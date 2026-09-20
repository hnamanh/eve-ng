#!/usr/bin/env python3
"""PNETLab telnet and VNC WebSocket mux.

The browser-facing contracts are deliberately unchanged:

* ``/telnet/?token=...&cols=...&rows=...&term=...`` is handled by telnetlib3.
  Binary frames carry terminal bytes and text frames carry resize JSON.
* ``/vnc/?token=...`` is a transparent binary WebSocket-to-TCP relay.

The two lanes share only the strictly validated token-file resolver and the
bounded bidirectional pump.  Shell, HTTP, and Guacamole have separate
processes and are intentionally not part of this service.
"""

import asyncio
import ipaddress
import json
import os
import re
import stat
import sys
from urllib.parse import parse_qs, urlparse

import telnetlib3
import websockets
from telnetlib3 import NAWS

try:
    import pwd
except ImportError:  # pragma: no cover - only relevant to non-POSIX test hosts
    pwd = None


TOKEN_DIR = os.environ.get("PNET_TOKEN_DIR", "/dev/shm/pnet-tokens")
LISTEN_HOST = os.environ.get(
    "PNET_CONSOLE_MUX_HOST",
    os.environ.get("PNET_TELNET_BRIDGE_HOST", "127.0.0.1"),
)
VNC_LISTEN_HOST = os.environ.get("PNET_VNC_BRIDGE_HOST", LISTEN_HOST)
TELNET_PORT = int(os.environ.get("PNET_TELNET_BRIDGE_PORT", "8022"))
VNC_PORT = int(os.environ.get("PNET_VNC_BRIDGE_PORT", "6080"))
# Readable aliases for operators/tests that refer to the original telnet
# listener names; the mux still exposes both explicit lane ports below.
LISTEN_PORT = TELNET_PORT
VNC_LISTEN_PORT = VNC_PORT
MAX_WS_MESSAGE_SIZE = int(
    os.environ.get("PNET_CONSOLE_MUX_MAX_MESSAGE_SIZE", str(4 * 1024 * 1024))
)
BACKEND_READ_SIZE = 4096
TOKEN_RE = re.compile(r"^[a-f0-9]{32}$")
PORT_RE = re.compile(r"^[0-9]+$")


def _www_data_uid():
    """Return www-data's uid, or None when the appliance account is absent."""
    if pwd is None:
        return None
    try:
        return pwd.getpwnam("www-data").pw_uid
    except KeyError:
        return None


def _read_token_file(path, token):
    """Read one token file without following a symlink.

    The service runs on Linux, where O_NOFOLLOW makes the open itself race-safe.
    The lstat fallback exists only to keep the module importable/testable on the
    Windows development host; the appliance path always has O_NOFOLLOW.
    """
    flags = os.O_RDONLY
    cloexec = getattr(os, "O_CLOEXEC", 0)
    nofollow = getattr(os, "O_NOFOLLOW", None)
    if nofollow is None:
        try:
            if stat.S_ISLNK(os.lstat(path).st_mode):
                return None
        except OSError:
            return None
    else:
        flags |= nofollow
    flags |= cloexec

    try:
        fd = os.open(path, flags)
    except OSError:
        return None

    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != _www_data_uid():
            return None
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = None
            contents = stream.read(4097)
    except (OSError, UnicodeError):
        return None
    finally:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                pass

    if len(contents) > 4096:
        return None
    lines = contents.splitlines()
    if len(lines) != 1:
        return None
    return lines[0].strip()


def resolve_token(token):
    """Resolve exactly one trusted ``TOKEN_DIR/<token>`` file.

    Console tokens are deliberately non-consuming in this slice; the janitor
    remains responsible for expiry.  The shell sentinel is not a valid target
    for either lane and port zero is rejected.
    """
    if not isinstance(token, str) or TOKEN_RE.fullmatch(token) is None:
        return None

    path = os.path.join(TOKEN_DIR, token)
    line = _read_token_file(path, token)
    if line is None:
        return None

    token_in_file, separator, target = line.partition(":")
    if separator != ":" or token_in_file.strip() != token:
        return None

    host, separator, port_text = target.strip().rpartition(":")
    if separator != ":":
        return None
    host = host.strip()
    port_text = port_text.strip()
    if host == "__pnetshell__" or PORT_RE.fullmatch(port_text) is None:
        return None
    try:
        ipaddress.ip_address(host)
        port = int(port_text, 10)
    except ValueError:
        return None
    if not 1 <= port <= 65535:
        return None
    return host, port


def request_path(ws, path=None):
    """Get the request path across supported websockets API generations."""
    if path:
        return path
    request = getattr(ws, "request", None)
    if request is not None:
        return getattr(request, "path", "")
    return getattr(ws, "path", "")


def apply_control(text, writer, size):
    """Apply the existing resize control contract through negotiated NAWS."""
    try:
        event = json.loads(text)
    except ValueError:
        return
    if event.get("type") != "resize":
        return
    size["cols"] = max(0, min(int(event.get("cols", 80)), 65535))
    size["rows"] = max(0, min(int(event.get("rows", 24)), 65535))
    try:
        if writer.local_option.get(NAWS):
            writer._send_naws()
    except Exception:
        pass


async def _safe_close(ws, code=None, reason=None):
    try:
        if code is None:
            await ws.close()
        else:
            await ws.close(code=code, reason=reason or "")
    except Exception:
        pass


async def _close_writer(writer):
    try:
        writer.close()
    except Exception:
        return
    wait_closed = getattr(writer, "wait_closed", None)
    if wait_closed is not None:
        try:
            await wait_closed()
        except Exception:
            pass


async def bounded_bidirectional_pump(
    ws, reader, close_backend, on_ws_message
):
    """Relay one WebSocket and one stream with bounded reads and backpressure.

    There is no unbounded intermediary queue: the TCP read is capped at
    BACKEND_READ_SIZE and ``ws.send``/the backend drain are awaited before the
    next message is read.  Whichever direction finishes first closes the other
    direction and the backend.
    """

    async def backend_to_ws():
        try:
            while True:
                data = await reader.read(BACKEND_READ_SIZE)
                if not data:
                    break
                await ws.send(bytes(data))
        except Exception:
            pass

    async def ws_to_backend():
        try:
            async for message in ws:
                if not await on_ws_message(message):
                    break
        except Exception:
            pass

    tasks = [
        asyncio.create_task(backend_to_ws()),
        asyncio.create_task(ws_to_backend()),
    ]
    try:
        await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
    finally:
        for task in tasks:
            if not task.done():
                task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await close_backend()
        await _safe_close(ws)


async def _telnet_connection(ws, *args):
    path = args[0] if args else None
    query = parse_qs(urlparse(request_path(ws, path)).query)
    token = (query.get("token") or [None])[0]
    target = resolve_token(token)
    if not target:
        await _safe_close(ws, code=4401, reason="invalid token")
        return

    try:
        cols = int((query.get("cols") or ["80"])[0] or 80)
        rows = int((query.get("rows") or ["24"])[0] or 24)
    except (TypeError, ValueError):
        await _safe_close(ws, code=4400, reason="invalid terminal size")
        return
    term = (query.get("term") or ["xterm-256color"])[0]
    host, port = target

    try:
        reader, writer = await telnetlib3.open_connection(
            host,
            port,
            encoding=False,
            term=term,
            cols=cols,
            rows=rows,
            connect_minwait=0.05,
            connect_maxwait=1.0,
        )
    except (OSError, asyncio.TimeoutError) as exc:
        await _safe_close(ws, code=4502, reason=f"connect failed: {exc}")
        return

    size = {"cols": cols, "rows": rows}
    try:
        writer.set_ext_send_callback(NAWS, lambda: (size["rows"], size["cols"]))
    except Exception:
        pass

    async def on_message(message):
        if isinstance(message, str):
            apply_control(message, writer, size)
            return True
        writer.write(message)
        await writer.drain()
        return True

    await bounded_bidirectional_pump(
        ws,
        reader,
        lambda: _close_writer(writer),
        on_message,
    )


async def _vnc_connection(ws, *args):
    path = args[0] if args else None
    query = parse_qs(urlparse(request_path(ws, path)).query)
    token = (query.get("token") or [None])[0]
    target = resolve_token(token)
    if not target:
        await _safe_close(ws, code=4401, reason="invalid token")
        return

    host, port = target
    try:
        reader, writer = await asyncio.open_connection(host, port)
    except (OSError, asyncio.TimeoutError) as exc:
        await _safe_close(ws, code=4502, reason=f"connect failed: {exc}")
        return

    async def on_message(message):
        if isinstance(message, str):
            await _safe_close(ws, code=1003, reason="VNC requires binary data")
            return False
        writer.write(message)
        await writer.drain()
        return True

    await bounded_bidirectional_pump(
        ws,
        reader,
        lambda: _close_writer(writer),
        on_message,
    )


async def handle_telnet(ws, *args):
    """Connection boundary for the telnet lane; one failure cannot kill serve()."""
    try:
        await _telnet_connection(ws, *args)
    except asyncio.CancelledError:
        raise
    except Exception as exc:
        print(f"telnet connection failed: {exc}", file=sys.stderr, flush=True)
        await _safe_close(ws)


async def handle_vnc(ws, *args):
    """Connection boundary for the VNC lane; backend errors stay per-session."""
    try:
        await _vnc_connection(ws, *args)
    except asyncio.CancelledError:
        raise
    except Exception as exc:
        print(f"vnc connection failed: {exc}", file=sys.stderr, flush=True)
        await _safe_close(ws)


async def _serve(handler, host, port):
    return await websockets.serve(
        handler,
        host,
        port,
        ping_interval=30,
        max_size=MAX_WS_MESSAGE_SIZE,
        compression=None,
    )


async def main():
    """Bind both lanes before declaring the mux ready."""
    telnet_server = await _serve(handle_telnet, LISTEN_HOST, TELNET_PORT)
    try:
        vnc_server = await _serve(handle_vnc, VNC_LISTEN_HOST, VNC_PORT)
    except Exception:
        telnet_server.close()
        await telnet_server.wait_closed()
        raise

    try:
        print(
            f"console mux ready telnet={LISTEN_HOST}:{TELNET_PORT} "
            f"vnc={VNC_LISTEN_HOST}:{VNC_PORT} tokens={TOKEN_DIR}",
            file=sys.stderr,
            flush=True,
        )
        await asyncio.Future()
    finally:
        vnc_server.close()
        telnet_server.close()
        await asyncio.gather(vnc_server.wait_closed(), telnet_server.wait_closed())


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
