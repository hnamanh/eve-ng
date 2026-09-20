#!/usr/bin/env bash
# =============================================================================
#  EVE-NG one-shot install for UBUNTU 26 (Resolute) — "Resolute Edition"
# -----------------------------------------------------------------------------
#  Target : a fresh Ubuntu 26.04 LTS amd64 VM with KVM (VT-x/AMD-V).
#           EVE-NG 2.0.x ported to modern PHP/Apache/MariaDB/QEMU/Guacamole.
#
#  Usage  : sudo ./install.sh                # do everything
#           sudo ./install.sh build          # only deps + /opt/unetlab + wrappers
#           sudo ./install.sh install        # only DB/services/config (code present)
#
#  What it does, end to end:
#    0. Checks host (root, amd64, ubuntu 26), installs dependencies from the
#       distro repos (PHP 8.x, Apache 2.4, MariaDB, QEMU 10, OVMF, Open vSwitch).
#    1. Clones your fork into /usr/src/eve-ng-public-dev and deploys the web UI
#       to /opt/unetlab/html with the standard layout + permissions.
#    2. Compiles the C wrappers (iol/qemu/dynamips) from source on this host.
#    3. Builds guacd from source (no distro package) and deploys Guacamole 1.x
#       on a standalone Tomcat 9 (distro Tomcat 10 is Jakarta-only), including
#       the MySQL JDBC auth provider as a proper $GUACAMOLE_HOME extension.
#    4. Creates the databases: eve_ng_db + guacdb with the OFFICIAL Guacamole
#       1.6.0 schema (the 1.x layout moved usernames to guacamole_entity),
#       users, and the admin account; sets MariaDB root password to 'eve-ng'.
#    5. Configures Apache (vhost on :80 with /html5/ proxy + websocket tunnel),
#       systemd services (guacd, tomcat9) and a non-interactive first-boot
#       network config so the web UI comes up immediately.
#
#  Result : EVE-NG at http://<this-host-ip>/   login admin / eve
# =============================================================================

set -Eeuo pipefail

# ----------------------------- configuration --------------------------------
REPO_URL="${REPO_URL:-https://github.com/hnamanh/eve-ng.git}"
SRC_DIR="/usr/src/eve-ng-public-dev"
LOG="/var/log/eve-install.log"
JOBS="$(nproc 2>/dev/null || echo 2)"

# Guacamole (no distro package on Ubuntu 26)
GUAC_VER="1.6.0"
TOMCAT9_URL="https://archive.apache.org/dist/tomcat/tomcat-9/v9.0.104/bin/apache-tomcat-9.0.104.tar.gz"

export DEBIAN_FRONTEND=noninteractive

# ----------------------------- helpers --------------------------------------
c_red=$'\033[0;31m'; c_grn=$'\033[0;32m'; c_yel=$'\033[1;33m'; c_blu=$'\033[0;34m'; c_rst=$'\033[0m'
log()  { echo -e "${c_blu}[$(date +%H:%M:%S)]${c_rst} $*" | tee -a "$LOG"; }
ok()   { echo -e "    ${c_grn}[OK]${c_rst} $*"   | tee -a "$LOG"; }
warn() { echo -e "    ${c_yel}[WARN]${c_rst} $*" | tee -a "$LOG"; }
fail() { echo -e "${c_red}[FAIL]${c_rst} $*"     | tee -a "$LOG"; exit 1; }

trap 'echo -e "\n${c_red}Aborted at line ${LINENO}. See ${LOG}${c_rst}"' ERR

# ----------------------------- phase 0: prereqs -----------------------------
phase_prereq() {
    log "Phase 0 — prerequisites"
    [[ $EUID -eq 0 ]] || fail "Run as root (sudo ./install.sh)"
    [[ "$(uname -m)" == "x86_64" ]] || warn "Not x86_64 ($(uname -m)); EVE-NG expects amd64."

    local ver; ver="$(. /etc/os-release && echo "${VERSION_ID%%.*}")"
    if [[ "$ver" != "26" ]]; then
        fail "This installer targets Ubuntu 26 (Resolute). Detected: ${VERSION_ID:-unknown}"
    fi

    log "Installing dependencies from distro repos..."
    apt-get update -qq >>"$LOG" 2>&1
    # PHP + web stack
    apt-get install -y -qq \
        php-cli php-mysql php-xml php-sqlite3 php-gd php-curl php-zip \
        apache2 libapache2-mod-php >/dev/null 2>&1 || fail "php/apache install failed"
    # Database (MariaDB replaces MySQL)
    apt-get install -y -qq mariadb-server >/dev/null 2>&1 || fail "mariadb install failed"
    # QEMU + firmware (distro QEMU 10.x; OVMF for nxos-style templates)
    apt-get install -y -qq qemu-system-x86 ovmf >/dev/null 2>&1 || fail "qemu/ovmf install failed"
    # Networking / lab support
    apt-get install -y -qq \
        bridge-utils openvswitch-switch cpulimit tcpdump telnet lvm2 \
        uml-utilities genisoimage xorriso lib32gcc-s1 lib32z1 libc6-i386 >/dev/null 2>&1 || true
    # Build tools (wrappers + guacd)
    apt-get install -y -qq \
        build-essential autoconf automake libtool pkg-config git unzip curl wget \
        libpango1.0-dev libcairo2-dev libjpeg-turbo8-dev libossp-uuid-dev libpng-dev \
        libssh2-1-dev libtelnet-dev libvncserver-dev libvorbis-dev libwebp-dev \
        libpulse-dev libsystemd-dev freerdp3-dev libssl-dev >/dev/null 2>&1 || fail "build deps failed"
    ok "dependencies installed (PHP $(php -r 'echo PHP_VERSION;'), QEMU $(qemu-system-x86_64 --version | head -1 | awk '{print $3}'))"

    # Your fork, where the build expects it.
    if [[ ! -d "$SRC_DIR/.git" ]]; then
        log "Cloning ${REPO_URL} -> ${SRC_DIR}"
        rm -rf "$SRC_DIR"
        git clone --depth 1 "$REPO_URL" "$SRC_DIR" >>"$LOG" 2>&1 \
            || fail "git clone failed (see $LOG)"
    else
        log "Using existing source at ${SRC_DIR} (rm -rf it to re-clone)"
    fi
    ok "source ready: $(cd "$SRC_DIR" && git rev-parse --short HEAD 2>/dev/null || echo '?')"

    # KVM check (labs need it; web UI works without)
    if grep -qE 'vmx|svm' /proc/cpuinfo 2>/dev/null; then
        modprobe kvm_intel 2>/dev/null || modprobe kvm_amd 2>/dev/null || true
        [[ -e /dev/kvm ]] && ok "KVM available" \
            || warn "CPU has virtualization but /dev/kvm missing — labs will not run"
    else
        warn "No Intel VT-x/AMD-V detected — QEMU nodes will fall back to TCG (slow)"
    fi
}

# ----------------------------- phase 1: deploy code -------------------------
phase_deploy() {
    log "Phase 1 — deploying EVE-NG from ${SRC_DIR}"

    # Standard layout + permissions (matches the original deb postinst)
    mkdir -p /opt/unetlab/data/Logs /opt/unetlab/labs /opt/unetlab/tmp \
             /opt/unetlab/scripts /opt/unetlab/wrappers /opt/unetlab/addons/iol/bin \
             /opt/unetlab/addons/dynamips /opt/unetlab/addons/qemu
    rm -rf /opt/unetlab/html
    cp -a "$SRC_DIR/html" /opt/unetlab/
    cp -a "$SRC_DIR/scripts" /opt/unetlab/ 2>/dev/null || true
    [[ -f "$SRC_DIR/schema/unetlab-001-create-schema.sql" ]] && \
        mkdir -p /opt/unetlab/schema && cp -a "$SRC_DIR/schema" /opt/unetlab/

    chown -R www-data:www-data /opt/unetlab/data /opt/unetlab/labs
    chown root:www-data /opt/unetlab/tmp; chmod 2775 /opt/unetlab/tmp
    ok "web UI deployed to /opt/unetlab/html"

    # QEMU shim: EVE code calls /opt/qemu/bin/* — point it at distro QEMU.
    mkdir -p /opt/qemu/bin /opt/qemu/share/qemu
    ln -sf "$(command -v qemu-system-x86_64)" /opt/qemu/bin/qemu-system-x86_64
    ln -sf "$(command -v qemu-system-i386)"   /opt/qemu/bin/qemu-system-i386 2>/dev/null || true
    ln -sf "$(command -v qemu-img)"           /opt/qemu/bin/qemu-img
    local ovf; ovf="$(find /usr/share/ovmf /usr/share/OVMF -name 'OVMF.fd' 2>/dev/null | head -1)"
    [[ -n "$ovf" ]] && cp -f "$ovf" /opt/qemu/share/qemu/OVMF.fd
    ok "QEMU shim ready (distro QEMU + OVMF firmware)"

    # Compile the C wrappers on this host (pure stdlib C — builds clean on 26)
    log "Compiling wrappers..."
    local W="$SRC_DIR/wrappers"
    gcc -Wall -O2 -o /opt/unetlab/wrappers/iol_wrapper \
        "$W"/include/ts.c "$W"/include/serial2udp.c "$W"/include/afsocket.c \
        "$W"/include/tap.c "$W"/include/cmd.c "$W"/include/functions.c "$W"/include/log.c \
        "$W"/iol_wrapper.c "$W"/iol_functions.c >>"$LOG" 2>&1 || fail "iol_wrapper build failed (see $LOG)"
    gcc -Wall -O2 -o /opt/unetlab/wrappers/qemu_wrapper \
        "$W"/include/ts.c "$W"/include/serial2udp.c "$W"/include/afsocket.c \
        "$W"/include/tap.c "$W"/include/cmd.c "$W"/include/functions.c "$W"/include/log.c \
        "$W"/qemu_wrapper.c "$W"/qemu_functions.c >>"$LOG" 2>&1 || fail "qemu_wrapper build failed (see $LOG)"
    gcc -Wall -O2 -o /opt/unetlab/wrappers/dynamips_wrapper \
        "$W"/include/ts.c "$W"/include/serial2udp.c "$W"/include/afsocket.c \
        "$W"/include/tap.c "$W"/include/cmd.c "$W"/include/functions.c "$W"/include/log.c \
        "$W"/dynamips_wrapper.c "$W"/dynamips_functions.c >>"$LOG" 2>&1 || fail "dynamips_wrapper build failed (see $LOG)"
    cp -a "$W/unl_profile" /opt/unetlab/wrappers/ 2>/dev/null || true
    [[ -f "$W/nsenter" ]] && cp -a "$W/nsenter" /opt/unetlab/wrappers/
    # IOU runtime lib (bundled, still needed by iol images)
    mkdir -p /opt/unetlab/addons/iol/lib
    [[ -f "$W/libcrypto.so.4" ]] && cp -a "$W/libcrypto.so.4" /opt/unetlab/addons/iol/lib/
    chmod 0755 /opt/unetlab/wrappers/*_wrapper /opt/unetlab/scripts/* 2>/dev/null || true
    ok "wrappers compiled (iol/qemu/dynamips + unl_wrapper)"

    # unl_wrapper is a PHP script — make sure it's in place and executable
    if [[ -f "$SRC_DIR/wrappers/unl_wrapper.php" ]]; then
        cp -a "$SRC_DIR/wrappers/unl_wrapper.php" /opt/unetlab/wrappers/unl_wrapper
        chmod 0755 /opt/unetlab/wrappers/unl_wrapper
    fi

    # sudoers for the unl user (wrappers run as root via sudo)
    cat > /etc/sudoers.d/unetlab <<'EOF'
www-data ALL=(root) NOPASSWD: /opt/unetlab/wrappers/*, /usr/bin/qemu-system-*
EOF
    chmod 0440 /etc/sudoers.d/unetlab

    # Build guacd from source (no distro package on Ubuntu 26)
    build_guacd
    # Deploy Guacamole web app + JDBC auth extension on standalone Tomcat 9
    deploy_guacamole
}

# ----------------------------- phase 2: database ----------------------------
phase_database() {
    log "Phase 2 — MariaDB setup"
    systemctl enable --now mariadb >>"$LOG" 2>&1 || service mariadb start >>"$LOG" 2>&1 || true
    sleep 3

    # Root password must be 'eve-ng' (EVE code + preinst scripts expect it)
    mysql -u root <<'SQL' >>"$LOG" 2>&1 || warn "could not set MariaDB root pw (may already be set)"
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('eve-ng');
FLUSH PRIVILEGES;
SQL

    # EVE database + user
    mysql -u root --password=eve-ng <<'SQL' >>"$LOG" 2>&1 || fail "eve_ng_db setup failed"
CREATE DATABASE IF NOT EXISTS eve_ng_db CHARACTER SET utf8mb4;
GRANT ALL ON eve_ng_db.* TO 'eve-ng'@'localhost' IDENTIFIED BY 'eve-ng';
FLUSH PRIVILEGES;
SQL

    # Guacamole database + user (VNC/SSH console)
    mysql -u root --password=eve-ng <<'SQL' >>"$LOG" 2>&1 || fail "guacdb setup failed"
CREATE DATABASE IF NOT EXISTS guacdb CHARACTER SET utf8mb4;
GRANT ALL ON guacdb.* TO 'guacuser'@'localhost' IDENTIFIED BY 'eve-ng';
FLUSH PRIVILEGES;
SQL

    # EVE schema (idempotent)
    mysql -u root --password=eve-ng eve_ng_db < /opt/unetlab/schema/unetlab-001-create-schema.sql >>"$LOG" 2>&1 \
        || warn "unetlab schema load reported an error (may already exist)"

    # Guacamole: fresh DB with the OFFICIAL 1.6.0 schema. The 1.x layout moved
    # usernames into guacamole_entity and switched permission tables to entity_id,
    # so the old repo schemas are NOT compatible — use the ones shipped with the
    # auth-jdbc package (downloaded in phase_deploy).
    local AJ="/usr/src/guacamole-auth-jdbc-${GUAC_VER}"
    mysql -u root --password=eve-ng -e "DROP DATABASE IF EXISTS guacdb; CREATE DATABASE guacdb CHARACTER SET utf8mb4;" >>"$LOG" 2>&1 \
        || fail "guacdb recreate failed"
    if [[ -f "$AJ/mysql/schema/001-create-schema.sql" ]]; then
        mysql -u root --password=eve-ng guacdb < "$AJ/mysql/schema/001-create-schema.sql" >>"$LOG" 2>&1 \
            || fail "guacamole ${GUAC_VER} schema load failed (see $LOG)"
        [[ -f "$AJ/mysql/schema/002-create-admin-user.sql" ]] && \
            mysql -u root --password=eve-ng guacdb < "$AJ/mysql/schema/002-create-admin-user.sql" >>"$LOG" 2>&1 || true
        ok "guacdb created with official Guacamole ${GUAC_VER} schema (admin: guacadmin/guacadmin)"
    else
        for f in /opt/unetlab/schema/guacamole-*.sql; do
            [[ -f "$f" ]] && mysql -u root --password=eve-ng guacdb < "$f" >>"$LOG" 2>&1 \
                || warn "guacamole schema load reported an error (may already exist)"
        done
    fi

    # Admin user (password: eve) — only if not present
    local n; n="$(mysql -u root --password=eve-ng -N -e "SELECT COUNT(*) FROM eve_ng_db.users WHERE username='admin';" 2>/dev/null || echo 0)"
    if [[ "$n" == "0" ]]; then
        mysql -u root --password=eve-ng eve_ng_db <<'SQL' >>"$LOG" 2>&1
INSERT INTO users VALUES ('admin',NULL,'root@localhost',-1,'UNetLab Administrator','85262adf74518bbb70c7cb94cd6159d91669e5a81edf1efebd543eadbda9fa2b',NULL,'','admin','',1);
SQL
        ok "admin user created (password: eve)"
    else
        ok "admin user already present"
    fi

    # Guacamole JDBC config (read by the web app at startup)
    mkdir -p /etc/guacamole
    cat > /etc/guacamole/guacamole.properties <<'EOF'
mysql-hostname: 127.0.0.1
mysql-port: 3306
mysql-database: guacdb
mysql-username: guacuser
mysql-password: eve-ng
mysql-driver-class: com.mysql.cj.jdbc.Driver
guacd-hostname: 127.0.0.1
guacd-port: 4822
EOF
    ok "databases ready (eve_ng_db + guacdb)"
}

# ----------------------------- phase 3: services ----------------------------
phase_services() {
    log "Phase 3 — Apache + systemd services"

    # PHP hardening for LXC/containers where /proc is restricted to own PID
    local confd; confd="$(ls -d /etc/php/*/apache2/conf.d 2>/dev/null | head -1)"
    [[ -n "$confd" ]] && echo 'pcre.jit=0' > "$confd/99-eve.ini"

    # Apache vhost (from the repo, already 2.4-clean) on port 80
    cp "$SRC_DIR/etc/apache.conf" /etc/apache2/sites-available/unetlab.conf
    sed -i "s/^ServerName .*/ServerName $(hostname)/" /etc/apache2/sites-available/unetlab.conf
    a2enmod rewrite proxy_html proxy_http proxy_wstunnel xml2enc >/dev/null 2>&1 || true
    a2dissite 000-default >/dev/null 2>&1 || true
    a2ensite unetlab >/dev/null 2>&1

    # Allow Apache to read /proc/meminfo etc. (Ubuntu hardening hides it)
    mkdir -p /etc/systemd/system/apache2.service.d
    cat > /etc/systemd/system/apache2.service.d/protect-proc.conf <<'EOF'
[Service]
ProtectProc=visible
EOF

    # guacd service (self-daemonizing -> Type=forking + pidfile)
    cat > /etc/systemd/system/guacd.service <<'EOF'
[Unit]
Description=Guacamole daemon
After=network.target

[Service]
Type=forking
PIDFile=/run/guacd.pid
ExecStart=/usr/local/sbin/guacd -L info -p /run/guacd.pid
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF

    # Tomcat 9 (Guacamole) service — distro Tomcat 10 is Jakarta-only, so we run
    # a standalone Tomcat 9 from /opt/tomcat9.
    local JAVA; JAVA="$(dirname "$(dirname "$(readlink -f "$(command -v java)")")")"
    cat > /etc/systemd/system/guac-tomcat.service <<EOF
[Unit]
Description=Tomcat 9 (Guacamole)
After=network.target guacd.service

[Service]
Type=forking
User=root
Environment=JAVA_HOME=${JAVA}
Environment=CATALINA_PID=/opt/tomcat9/temp/catalina.pid
ExecStart=/opt/tomcat9/bin/startup.sh
ExecStop=/opt/tomcat9/bin/shutdown.sh
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF

    # cpulimit daemon (EVE feature) — best effort
    if [[ -f "$SRC_DIR/etc/cpulimit.service" ]]; then
        cp "$SRC_DIR/etc/cpulimit.service" /etc/systemd/system/ 2>/dev/null || true
    fi

    systemctl daemon-reload >>"$LOG" 2>&1
    # Stop distro tomcat if present (port conflict with our Tomcat 9)
    command -v systemctl >/dev/null && systemctl disable --now tomcat10 >/dev/null 2>&1 || true
    systemctl enable --now guacd guac-tomcat apache2 mariadb >>"$LOG" 2>&1 || \
        warn "one of the services failed to start — check 'systemctl status guacd guac-tomcat apache2'"

    # Non-interactive first-boot config (replaces the interactive OVF wizard).
    # Skipped when a previous install/wizard already configured this host.
    if [[ ! -f /opt/ovf/.configured ]]; then
        mkdir -p /opt/ovf
        local hn="eve-ng"
        echo "$hn" > /etc/hostname; hostname "$hn" 2>/dev/null || true
        cat > /etc/network/interfaces <<'EOF'
auto lo
iface lo inet loopback

# Management bridge (EVE-NG) — DHCP from your host network.
auto pnet0
iface pnet0 inet dhcp
    bridge_ports eth0
    bridge_stp off
EOF
        touch /opt/ovf/.configured
        ifdown eth0 >>"$LOG" 2>&1 || true
        ifup pnet0   >>"$LOG" 2>&1 || warn "could not bring up pnet0 yet (a reboot will)"
    else
        log "First-boot network config skipped (/opt/ovf/.configured present)"
    fi

    ok "services configured and started"
}

# ----------------------------- guacd + tomcat builders ----------------------
build_guacd() {
    log "Building guacd ${GUAC_VER} from source..."
    local dir="/tmp/guacamole-server-${GUAC_VER}"
    if [[ ! -x /usr/local/sbin/guacd ]]; then
        rm -rf "$dir"
        curl -fsSL -o "/tmp/guacd-src.tar.gz" \
            "https://archive.apache.org/dist/guacamole/${GUAC_VER}/source/guacamole-server-${GUAC_VER}.tar.gz" >>"$LOG" 2>&1 \
            || fail "guacd source download failed (see $LOG)"
        tar xzf /tmp/guacd-src.tar.gz -C /tmp >>"$LOG" 2>&1 || fail "guacd extract failed"
        cd "$dir"
        # FreeRDP 3.x is experimental in guacd; EVE uses VNC/SSH so drop RDP.
        CFLAGS="-O2 -Wno-error" ./configure --prefix=/usr/local --without-rdp >>"$LOG" 2>&1 \
            || fail "guacd configure failed (see $LOG)"
        CFLAGS="-O2 -Wno-error" make -j"$JOBS" >>"$LOG" 2>&1 || fail "guacd build failed (see $LOG)"
        make install >>"$LOG" 2>&1 || fail "guacd install failed"
        ldconfig
    fi
    ok "guacd ready at /usr/local/sbin/guacd"
}

deploy_guacamole() {
    log "Deploying Guacamole ${GUAC_VER} on Tomcat 9..."
    local T="/opt/tomcat9"
    if [[ ! -x "$T/bin/catalina.sh" ]]; then
        rm -rf /tmp/tomcat9.tgz
        curl -fsSL -o /tmp/tomcat9.tgz "$TOMCAT9_URL" >>"$LOG" 2>&1 \
            || fail "Tomcat 9 download failed (see $LOG)"
        tar xzf /tmp/tomcat9.tgz -C /opt >>"$LOG" 2>&1
        mv "/opt/apache-tomcat-9.0.104" "$T"
    fi

    # MySQL JDBC auth provider. Guacamole 1.x loads providers as EXTENSIONS from
    # $GUACAMOLE_HOME/extensions/*.jar (guac-manifest.json inside) — NOT from
    # WEB-INF/lib and NOT via ServiceLoader. The Apache tarball ships the fat jar
    # with all deps nested inside, which is exactly what the extension loader wants.
    local AJ="/usr/src/guacamole-auth-jdbc-${GUAC_VER}"
    if [[ ! -f "$AJ/mysql/guacamole-auth-jdbc-mysql-${GUAC_VER}.jar" ]]; then
        rm -rf "$AJ"; mkdir -p "$AJ"
        curl -fsSL "https://archive.apache.org/dist/guacamole/${GUAC_VER}/binary/guacamole-auth-jdbc-${GUAC_VER}.tar.gz" \
            | tar xz -C "$AJ" --strip-components=1 >>"$LOG" 2>&1 \
            || fail "guacamole-auth-jdbc download failed (see $LOG)"
    fi
    mkdir -p /etc/guacamole/extensions
    cp -f "$AJ/mysql/guacamole-auth-jdbc-mysql-${GUAC_VER}.jar" /etc/guacamole/extensions/

    # MySQL JDBC driver for Guacamole's DB auth (webapp classpath)
    local lib="$T/webapps/guacamole/WEB-INF/lib"
    mkdir -p "$lib"
    if [[ ! -f "$lib/mysql-connector-j.jar" ]]; then
        curl -fsSL -o "$lib/mysql-connector-j.jar" \
            "https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/8.4.0/mysql-connector-j-8.4.0.jar" >>"$LOG" 2>&1 \
            || warn "MySQL connector download failed — Guacamole DB auth will not work"
    fi

    # Extract the WAR over the webapp (keeps WEB-INF/lib + classes we added)
    local war="/tmp/guacamole-${GUAC_VER}.war"
    if [[ ! -f "$war" ]]; then
        curl -fsSL -o "$war" \
            "https://archive.apache.org/dist/guacamole/${GUAC_VER}/binary/guacamole-${GUAC_VER}.war" >>"$LOG" 2>&1 \
            || fail "Guacamole WAR download failed (see $LOG)"
    fi
    rm -rf /tmp/guac-war && mkdir -p /tmp/guac-war
    (cd /tmp/guac-war && jar xf "$war" 2>/dev/null || python3 -c "import zipfile;zipfile.ZipFile('$war').extractall('.')") >>"$LOG" 2>&1
    # Copy app assets into the webapp without clobbering our WEB-INF additions
    local W="$T/webapps/guacamole"
    mkdir -p "$W/WEB-INF/classes"
    cp -a /tmp/guac-war/META-INF "$W/" 2>/dev/null || true
    for d in css js images fonts app layouts guacamole-common-js; do
        [[ -e "/tmp/guac-war/$d" ]] && cp -a "/tmp/guac-war/$d" "$W/"
    done
    for f in index.html *.js *.css templates.js; do
        [[ -e "/tmp/guac-war/$f" ]] && cp -a "/tmp/guac-war/$f" "$W/" 2>/dev/null || true
    done

    # JDBC properties (also written in phase_database; keep in sync)
    cat > /etc/guacamole/guacamole.properties <<'EOF'
mysql-hostname: 127.0.0.1
mysql-port: 3306
mysql-database: guacdb
mysql-username: guacuser
mysql-password: eve-ng
mysql-driver-class: com.mysql.cj.jdbc.Driver
guacd-hostname: 127.0.0.1
guacd-port: 4822
EOF
    ok "Guacamole deployed to ${T}/webapps/guacamole (+ JDBC auth extension)"
}

# ----------------------------- verify ---------------------------------------
verify() {
    log "Verification..."
    local ip; ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -n "$ip" ]] || ip="<this-host-ip>"

    # Wait for Apache to answer
    local i=0
    while (( i < 15 )); do
        if curl -fsS -o /dev/null "http://127.0.0.1/" 2>>"$LOG"; then break; fi
        sleep 2; ((i++))
    done

    # Login smoke test (admin/eve) — also creates the per-user Guacamole row
    local rc
    rc="$(curl -s -c /tmp/.evejar -X POST http://127.0.0.1/api/auth/login \
            -H 'Content-Type: application/json' \
            -d '{"username":"admin","password":"eve"}')"
    if echo "$rc" | grep -q '"code":200'; then
        ok "web UI responding and admin login works"
    else
        warn "login smoke test failed — check $LOG (response: ${rc:0:120})"
    fi

    # Guacamole token API through the Apache proxy (what EVE calls on login)
    local tok
    tok="$(curl -s -X POST http://127.0.0.1/html5/api/tokens \
            --data-urlencode 'username=admin' --data-urlencode 'password=unl')"
    if echo "$tok" | grep -q authToken; then
        ok "Guacamole token API works — VNC console ready"
    else
        warn "Guacamole token API not answering — check 'systemctl status guacd guac-tomcat' (response: ${tok:0:120})"
    fi

    echo
    echo -e "${c_grn}============================================================${c_rst}"
    echo -e "${c_grn}  EVE-NG Resolute Edition is installed on Ubuntu 26.${c_rst}"
    echo -e "  Web UI : http://${ip}/"
    echo -e "  Login  : admin / eve"
    echo -e "  Log    : ${LOG}"
    echo -e "============================================================${c_grn}"
}

# ----------------------------- main -----------------------------------------
main() {
    local mode="${1:-all}"
    mkdir -p "$(dirname "$LOG")"; : > "$LOG"
    log "EVE-NG Ubuntu 26 installer — mode='${mode}' jobs=${JOBS}"
    case "$mode" in
        build)   phase_prereq; phase_deploy ;;
        install) phase_database; phase_services; verify ;;
        all)     phase_prereq; phase_deploy; phase_database; phase_services; verify ;;
        *) fail "unknown mode '${mode}' (use: build | install | all)" ;;
    esac
}

main "$@"
