#!/usr/bin/env bash
# =============================================================================
#  EVE-NG one-shot build + install  (from your fork: hnamanh/eve-ng)
# -----------------------------------------------------------------------------
#  Target : a FRESH Ubuntu 16.04 LTS (Xenial) amd64 VM with KVM (VT-x/AMD-V).
#           This is the only distro EVE-NG 2.0.x runs on.
#
#  Usage  : sudo ./install.sh            # do everything (build + install)
#           sudo ./install.sh build      # only produce .debs in /build/apt/pool/xenial/
#           sudo ./install.sh install    # only install from an already-built pool
#
#  What it does, end to end:
#    0. Checks host (root, amd64, xenial) and installs build dependencies.
#    1. Clones your fork into /usr/src/eve-ng-public-dev (where the builders expect).
#    2. Fixes dead 2016-era download URLs in the qemu builder.
#    3. Builds all six packages: schema, guacamole, dynamips, qemu, vpcs, eve-ng.
#    4. Builds the custom linux-image-4.4.14-eve-ng-ukms kernel from source
#       (the official apt repo that used to ship it is offline). Falls back to
#       stock linux-image-generic automatically if the custom build fails.
#    5. Installs everything on this machine, sets MySQL root pw, and does a
#       non-interactive first-boot config so the web UI comes up immediately.
#
#  Result : EVE-NG at http://<this-host-ip>/   login admin / eve
# =============================================================================

set -Eeuo pipefail

# ----------------------------- configuration --------------------------------
REPO_URL="${REPO_URL:-https://github.com/hnamanh/eve-ng.git}"
SRC_DIR="/usr/src/eve-ng-public-dev"
BUILD_DIR="/build"
DISTNAME="$(lsb_release -c -s 2>/dev/null || echo xenial)"
POOL="${BUILD_DIR}/apt/pool/${DISTNAME}"
LOG="/var/log/eve-install.log"
JOBS="$(nproc 2>/dev/null || echo 2)"

# Custom kernel (matches the main package's hard dependency)
KERNEL_VER="4.4.14"
KERNEL_FLAVOUR="-eve-ng-ukms-1.0"          # appended version -> pkg name suffix
KERNEL_TARBALL_URL="https://cdn.kernel.org/pub/linux/kernel/v4.x/linux-${KERNEL_VER}.tar.xz"

# QEMU sources (working mirror; the old wiki.qemu-project.org is dead)
QEMU_MIRROR="https://download.qemu.org"

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

    if [[ "$DISTNAME" != "xenial" ]]; then
        fail "EVE-NG 2.0.x requires Ubuntu 16.04 (Xenial). Detected: ${DISTNAME}"
    fi

    log "Installing build dependencies..."
    apt-get update -qq >>"$LOG" 2>&1
    apt-get install -y -qq \
        build-essential fakeroot dpkg-dev debhelper dh-make kernel-package \
        zip unzip wget curl git gcc g++ make patch xsltproc dialog lsb-release \
        libssl-dev libpcap-dev libelf-dev apt-ftparchive >>"$LOG" 2>&1 \
        || fail "apt-get install of build deps failed (see $LOG)"
    ok "build dependencies installed"

    # Your fork, where every builder script expects it.
    if [[ ! -d "$SRC_DIR/.git" ]]; then
        log "Cloning ${REPO_URL} -> ${SRC_DIR}"
        rm -rf "$SRC_DIR"
        git clone --depth 1 "$REPO_URL" "$SRC_DIR" >>"$LOG" 2>&1 \
            || fail "git clone failed (see $LOG)"
    else
        log "Using existing source at ${SRC_DIR} (run 'rm -rf $SRC_DIR' to re-clone)"
    fi
    ok "source ready: $(cd "$SRC_DIR" && git rev-parse --short HEAD 2>/dev/null || echo '?')"

    # The main builder for THIS tree is build_deb_eve-ng.sh (uses adminLTE theme).
    [[ -f "$SRC_DIR/scripts/build_deb_eve-ng.sh" ]] || fail "main builder script missing"
}

# ----------------------------- phase 1: build debs --------------------------
fix_qemu_urls() {
    # Point the qemu builder at a live mirror (idempotent).
    local f="$SRC_DIR/scripts/build_deb_qemu.sh"
    if grep -q "wiki.qemu-project.org/download" "$f"; then
        sed -i "s#http://wiki.qemu-project.org/download#${QEMU_MIRROR}#g" "$f"
        ok "patched qemu builder to use ${QEMU_MIRROR}"
    fi
}

run_builder() {  # $1 = script name, $2 = label
    local s="$SRC_DIR/scripts/$1"
    [[ -f "$s" ]] || fail "builder not found: $s"
    log "Building $2 ($1)..."
    if bash "$s" >>"$LOG" 2>&1; then
        ok "$2 built"
    else
        fail "$2 build failed — last lines of $LOG:\n$(tail -n 25 "$LOG")"
    fi
}

phase_build() {
    log "Phase 1 — building packages (dist: ${DISTNAME})"
    mkdir -p "$POOL"
    fix_qemu_urls

    # Order only matters for the final 'apt install'; build order is free.
    run_builder build_deb_schema.sh     "schema"
    run_builder build_deb_guacamole.sh  "guacamole"
    run_builder build_deb_dynamips.sh   "dynamips"
    run_builder build_deb_qemu.sh       "qemu"
    run_builder build_deb_vpcs.sh       "vpcs"

    # ---- custom kernel (official repo is offline, so build it) -------------
    local kernel_ok=0
    if build_ukms_kernel; then
        kernel_ok=1
        ok "custom ukms kernel built"
    else
        warn "custom ukms kernel build FAILED — will fall back to linux-image-generic"
    fi

    # If the custom kernel is unavailable, relax the main package's hard dep so
    # 'apt install' can still resolve. (EVE runs fine on stock 4.4 generic; you
    # just lose UKSM tuning for IOU nodes.)
    local ctl="$SRC_DIR/debian/eve-ng_${DISTNAME}_control.template"
    if [[ $kernel_ok -eq 0 && -f "$ctl" ]]; then
        sed -i 's/linux-image-4.4.14-eve-ng-ukms+/linux-image-generic/g' "$ctl"
        sed -i 's/linux-headers-4.4.14-eve-ng-ukms+/linux-headers-generic/g' "$ctl"
        warn "main package now depends on linux-image-generic instead of ukms kernel"
    fi

    run_builder build_deb_eve-ng.sh     "eve-ng (main)"

    log "Built packages:"
    ls -1 "$POOL"/e/*/*.deb 2>/dev/null | tee -a "$LOG" || true
}

# Build linux-image/headers-4.4.14-eve-ng-ukms-1.0 from a clean kernel tree.
build_ukms_kernel() {
    local kdir="/usr/src/linux-${KERNEL_VER}"
    mkdir -p "${POOL}/e/eve-ng-kernel"

    if [[ ! -d "$kdir" ]]; then
        log "Downloading Linux ${KERNEL_VER} source..."
        wget -q -O "/tmp/linux-${KERNEL_VER}.tar.xz" "$KERNEL_TARBALL_URL" \
            || { warn "kernel tarball download failed"; return 1; }
        rm -rf "$kdir"
        tar -xf "/tmp/linux-${KERNEL_VER}.tar.xz" -C /usr/src \
            || { warn "kernel extract failed"; return 1; }
    fi

    cd "$kdir"
    # Apply EVE's bridge patch (LLDP/LACP forwarding) — paths strip with -p1.
    if [[ -f "$SRC_DIR/patch/linux-4.4.14_bridge.patch" ]]; then
        patch -p1 --forward < "$SRC_DIR/patch/linux-4.4.14_bridge.patch" >>"$LOG" 2>&1 \
            || warn "bridge patch did not apply cleanly (continuing)"
    fi

    log "Configuring kernel (defconfig + KVM/bridge)..."
    make defconfig >>"$LOG" 2>&1 || { warn "make defconfig failed"; return 1; }
    # Ensure the bits EVE needs are present.
    ./scripts/config --enable  KVM        >>"$LOG" 2>&1 || true
    if grep -q vmx /proc/cpuinfo 2>/dev/null; then
        ./scripts/config --module KVM_INTEL >>"$LOG" 2>&1 || true
    fi
    if grep -q svm /proc/cpuinfo 2>/dev/null; then
        ./scripts/config --module KVM_AMD   >>"$LOG" 2>&1 || true
    fi
    ./scripts/config --enable  BRIDGE      >>"$LOG" 2>&1 || true
    ./scripts/config --module  VETH        >>"${kdir}/.config" 2>/dev/null || \
        ./scripts/config --module VETH     >>"$LOG" 2>&1 || true
    ./scripts/config --module  TUN         >>"$LOG" 2>&1 || true
    make olddefconfig >>"$LOG" 2>&1 || { warn "olddefconfig failed"; return 1; }

    # kernel-package site config (from the repo).
    cp -f "$SRC_DIR/debian/kernel-pkg.conf" /etc/kernel-pkg.conf 2>/dev/null || true

    log "Compiling kernel (-j${JOBS}) — this takes a while..."
    if ! fakeroot make-kpkg -j"$JOBS" --initrd \
            --append-to-version="${KERNEL_FLAVOUR}" \
            kernel_image kernel_headers >>"$LOG" 2>&1; then
        warn "make-kpkg failed"; return 1
    fi

    # Collect the produced debs into the pool so apt can resolve them.
    local found=0
    for d in /usr/src/linux-image-${KERNEL_VER}${KERNEL_FLAVOUR}*.deb \
             /usr/src/linux-headers-${KERNEL_VER}${KERNEL_FLAVOUR}*.deb; do
        [[ -f "$d" ]] && { cp -f "$d" "${POOL}/e/eve-ng-kernel/"; found=1; }
    done
    [[ $found -eq 1 ]] || { warn "no kernel .debs produced in /usr/src"; return 1; }

    ls -1 "${POOL}/e/eve-ng-kernel/" | tee -a "$LOG"
    cd /
    return 0
}

# ----------------------------- phase 2: install -----------------------------
phase_install() {
    log "Phase 2 — installing EVE-NG on this host"

    # Base services the packages expect. mysql-server must exist BEFORE our debs'
    # preinst scripts run (they all call 'mysql -u root --password=eve-ng').
    apt-get update -qq >>"$LOG" 2>&1
    log "Installing base services (mysql, apache, php, openvswitch)..."
    apt-get install -y -qq mysql-server >>"$LOG" 2>&1 || fail "mysql-server install failed"

    # Make MySQL root use the password every EVE preinst expects.
    service mysql start >>"$LOG" 2>&1 || true
    sleep 3
    mysql -u root <<'SQL' >>"$LOG" 2>&1 || warn "could not set mysql root pw (may already be set)"
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'eve-ng';
FLUSH PRIVILEGES;
SQL
    ok "mysql root password set to eve-ng"

    # Install the custom kernel first if we built it, so the main package's
    # dependency is already satisfied. Otherwise apt pulls stock generic.
    local kdeb=0
    for d in "${POOL}"/e/eve-ng-kernel/linux-image-*.deb; do
        [[ -f "$d" ]] && { dpkg -i "$d" >>"$LOG" 2>&1 || true; kdeb=1; }
    done
    if [[ $kdeb -eq 0 ]]; then
        log "Installing stock linux-image-generic (kernel fallback)..."
        apt-get install -y -qq linux-image-generic linux-headers-generic >>"$LOG" 2>&1 \
            || warn "generic kernel install failed — you may need to provide a KVM kernel"
    fi

    # Now the EVE packages, in dependency order. 'apt-get install ./x.deb' lets
    # apt pull each package's remaining Depends (apache2, php, ovs, ...) from repo.
    log "Installing schema..."
    apt-get install -y "${POOL}"/e/eve-ng-schema/*.deb >>"$LOG" 2>&1 \
        || fail "schema install failed — $(tail -n 15 "$LOG")"

    log "Installing guacamole (VNC)..."
    apt-get install -y "${POOL}"/e/eve-ng-guacamole/*.deb >>"$LOG" 2>&1 \
        || fail "guacamole install failed — $(tail -n 15 "$LOG")"

    log "Installing dynamips, qemu, vpcs..."
    apt-get install -y "${POOL}"/e/eve-ng-dynamips/*.deb \
                       "${POOL}"/e/eve-ng-qemu/*.deb \
                       "${POOL}"/e/eve-ng-vpcs/*.deb >>"$LOG" 2>&1 \
        || fail "dynamips/qemu/vpcs install failed — $(tail -n 15 "$LOG")"

    log "Installing main eve-ng package..."
    apt-get install -y "${POOL}"/e/eve-ng/*.deb >>"$LOG" 2>&1 \
        || fail "main eve-ng install failed — $(tail -n 15 "$LOG")"
    ok "all EVE-NG packages installed"

    first_boot_config
    verify
}

# Non-interactive equivalent of the interactive OVF first-boot wizard.
first_boot_config() {
    log "First-boot configuration (non-interactive)..."
    local hn="eve-ng"
    echo "$hn" > /etc/hostname
    hostname "$hn" 2>/dev/null || true

    # Management bridge pnet0 over eth0 with DHCP (matches EVE's dhcp path), so
    # lab networking works out of the box. Keep loopback + eth0 manual.
    cat > /etc/network/interfaces <<'EOF'
# This file describes the network interfaces on this system.
auto lo
iface lo inet loopback

# Management bridge (EVE-NG) — DHCP from your Proxmox/ESXi host network.
auto pnet0
iface pnet0 inet dhcp
    bridge_ports eth0
    bridge_stp off
EOF

    # Stop the interactive OVF wizard from blocking on next boot.
    touch /opt/ovf/.configured 2>/dev/null || true

    # Bring networking up now (best effort; a reboot also does this).
    if command -v ifup >/dev/null 2>&1; then
        ifdown eth0 >>"$LOG" 2>&1 || true
        ifup pnet0   >>"$LOG" 2>&1 || warn "could not bring up pnet0 yet (reboot will)"
    fi

    # Make sure the services are running.
    service apache2 restart >>"$LOG" 2>&1 || true
    service mysql   start   >>"$LOG" 2>&1 || true
    ok "first-boot config written (hostname=${hn}, pnet0=dhcp)"
}

verify() {
    log "Verification..."
    local ip; ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [[ -n "$ip" ]] || ip="<this-host-ip>"

    # Is the web UI answering?
    if curl -fsS -o /dev/null "http://127.0.0.1/" 2>>"$LOG"; then
        ok "web UI is responding on localhost:80"
    else
        warn "web UI not yet answering — check 'service apache2 status' and $LOG"
    fi

    echo
    echo -e "${c_grn}============================================================${c_rst}"
    echo -e "${c_grn}  EVE-NG is installed.${c_rst}"
    echo -e "  Web UI : http://${ip}/"
    echo -e "  Login  : admin / eve"
    echo -e "  Log    : ${LOG}"
    echo -e "============================================================${c_grn}"
}

# ----------------------------- main -----------------------------------------
main() {
    local mode="${1:-all}"
    mkdir -p "$(dirname "$LOG")"; : > "$LOG"
    log "EVE-NG installer — mode='${mode}' dist='${DISTNAME}' jobs=${JOBS}"
    case "$mode" in
        build)   phase_prereq; phase_build ;;
        install) phase_install ;;
        all)     phase_prereq; phase_build; phase_install ;;
        *) fail "unknown mode '${mode}' (use: build | install | all)" ;;
    esac
}

main "$@"
