# R6-B6 isolated restore drill — real-sandbox CI gate probe (owner directive D8). TEST TOOLING, never part of the drill.
#
# Runs INSIDE a unit built from the drill's own profile (dms_sandbox_properties) as the stand-in for the worst case:
# arbitrary native code as the sandbox identity. The gate adds only what the probe itself needs (python may execute,
# the probe file is bound read-only). Each probe prints one line: PROBE <name>=<outcome>, "blocked_<errno>" or
# "open_<detail>"; the gate decides which outcomes are required, per mode.
#
# argv: decoy_home_file decoy_shm_dir decoy_vartmp_file decoy_tcp_port abstract_name operator_pid operator_private_file
import ctypes, errno, mmap, os, socket, sys

decoy_home, decoy_shm, decoy_vartmp, port, abstract, hpid, private_file = sys.argv[1:8]
port = int(port); hpid = int(hpid)


def out(name, outcome):
    print(f"PROBE {name}={outcome}", flush=True)


def err(e):
    if isinstance(e, OSError) and e.errno == 0 and str(e.strerror).startswith("killed_"):
        return "blocked_" + str(e.strerror)
    return "blocked_" + errno.errorcode.get(e.errno, str(e.errno)) if isinstance(e, OSError) else "blocked_" + type(e).__name__


def attempt(name, fn):
    try:
        r = fn()
        out(name, "open" + ("_" + r if r else ""))
    except Exception as e:  # noqa: BLE001
        out(name, err(e))


def read(path):
    with open(path, "rb") as f:
        return f"read{len(f.read(64))}"


def write(path):
    with open(path, "wb") as f:
        f.write(b"x")
    return "written"


def unix_connect(path):
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(2)
    s.connect(path)
    return "connected"


def inet(fam, host):
    s = socket.socket(fam, socket.SOCK_STREAM)
    s.settimeout(2)
    s.connect((host, port))
    return "connected"


# Files and sockets that stand for production assets
attempt("read_home_secret", lambda: read(decoy_home))
attempt("read_shm_secret", lambda: read(os.path.join(decoy_shm, "secret.txt")))
attempt("read_vartmp_secret", lambda: read(decoy_vartmp))
attempt("read_etc_shadow", lambda: read("/etc/shadow"))
attempt("stat_system_pg_socket", lambda: str(os.stat("/var/run/postgresql/.s.PGSQL.5432").st_mode))
attempt("stat_run_postgresql", lambda: str(os.stat("/run/postgresql").st_mode))
attempt("stat_dbus_system_bus", lambda: str(os.stat("/run/dbus/system_bus_socket").st_mode))
attempt("unix_decoy_pg_socket", lambda: unix_connect(os.path.join(decoy_shm, f".s.PGSQL.{port}")))
attempt("unix_abstract_host", lambda: unix_connect("\0" + abstract))
attempt("list_home", lambda: str(len(os.listdir("/home"))))
attempt("list_root", lambda: str(len(os.listdir("/root"))))
# Network
attempt("inet_tcp_loopback_decoy", lambda: inet(socket.AF_INET, "127.0.0.1"))
attempt("inet6_tcp_loopback_decoy", lambda: inet(socket.AF_INET6, "::1"))
attempt("inet_udp_socket", lambda: socket.socket(socket.AF_INET, socket.SOCK_DGRAM) and "created")
attempt("netlink_socket", lambda: socket.socket(socket.AF_NETLINK, socket.SOCK_RAW, 0) and "created")
attempt("packet_socket", lambda: socket.socket(socket.AF_PACKET, socket.SOCK_RAW, 0) and "created")
attempt("metadata_service", lambda: (lambda s: (s.settimeout(2), s.connect(("169.254.169.254", 80)), "connected")[2])(socket.socket(socket.AF_INET, socket.SOCK_STREAM)))
# Filesystem write and execute (W^X)
attempt("write_root", lambda: write("/pwn"))
attempt("write_usr", lambda: write("/usr/pwn"))
attempt("write_etc", lambda: write("/etc/pwn"))
attempt("write_srv", lambda: write("/srv/pwn"))
attempt("write_tmp", lambda: write("/tmp/pwn"))
def exec_child(path):
    pid = os.fork()
    if pid == 0:
        try:
            os.execv(path, [path])
        except OSError as e:
            os._exit(100 + e.errno)
        os._exit(99)
    code = os.waitstatus_to_exitcode(os.waitpid(pid, 0)[1])
    if code >= 100:
        raise OSError(code - 100, "exec")
    return "exit" + str(code)


attempt("exec_usr_bin_id", lambda: exec_child("/usr/bin/id"))
attempt("exec_usr_bin_curl", lambda: exec_child("/usr/bin/curl"))


def exec_written():
    with open("/srv/x.sh", "wb") as f:
        f.write(b"#!/usr/bin/dash\necho hi\n")
    os.chmod("/srv/x.sh", 0o755)
    return exec_child("/srv/x.sh")


attempt("exec_file_written_to_srv", exec_written)


def exec_written_in(d):
    path = os.path.join(d, "x.sh")
    with open(path, "wb") as f:
        f.write(b"#!/usr/bin/dash\n:\n")
    os.chmod(path, 0o755)
    return exec_child(path)


attempt("exec_file_written_to_tmp", lambda: exec_written_in("/tmp"))
attempt("exec_file_written_to_var_tmp", lambda: exec_written_in("/var/tmp"))
attempt("exec_file_written_to_dev_shm", lambda: exec_written_in("/dev/shm"))


def mmap_exec_written():
    with open("/usr/lib/postgresql/16/lib/plpgsql.so", "rb") as src, open("/srv/x.so", "wb") as dst:
        dst.write(src.read())
    with open("/srv/x.so", "rb") as f:
        mmap.mmap(f.fileno(), 4096, prot=mmap.PROT_READ | mmap.PROT_EXEC, flags=mmap.MAP_PRIVATE)
    return "mapped"


attempt("mmap_exec_file_in_srv", mmap_exec_written)
attempt("mmap_anon_rwx", lambda: mmap.mmap(-1, 4096, prot=mmap.PROT_READ | mmap.PROT_WRITE | mmap.PROT_EXEC) and "mapped")
attempt("dlopen_file_in_srv", lambda: ctypes.CDLL("/srv/x.so") and "loaded")
# Privilege and kernel surfaces
attempt("unshare_user_ns", lambda: os.unshare(os.CLONE_NEWUSER) or "unshared")
attempt("unshare_mount_ns", lambda: os.unshare(os.CLONE_NEWNS) or "unshared")
libc = ctypes.CDLL(None, use_errno=True)


def in_child(fn):
    """Runs fn in a forked child: a seccomp kill (SIGSYS) must not end the probe."""
    pid = os.fork()
    if pid == 0:
        try:
            fn()
            os._exit(0)
        except OSError as e:
            os._exit(100 + (e.errno or 0))
        os._exit(99)
    st = os.waitpid(pid, 0)[1]
    if os.WIFSIGNALED(st):
        raise OSError(0, "killed_SIG" + str(os.WTERMSIG(st)))
    code = os.WEXITSTATUS(st)
    if code >= 100:
        raise OSError(code - 100, "child")
    return "done"


def in_child_ret(fn):
    """Like in_child, but returns fn()'s string (a seccomp kill is reported, the probe goes on)."""
    r, w = os.pipe()
    pid = os.fork()
    if pid == 0:
        os.close(r)
        try:
            out = fn() or "done"
            os.write(w, out.encode()[:60])
            os._exit(0)
        except OSError as e:
            os._exit(100 + (e.errno or 0))
        except Exception:
            os._exit(98)
    os.close(w)
    data = os.read(r, 64).decode()
    os.close(r)
    st = os.waitpid(pid, 0)[1]
    if os.WIFSIGNALED(st):
        raise OSError(0, "killed_SIG" + str(os.WTERMSIG(st)))
    code = os.WEXITSTATUS(st)
    if code >= 100:
        raise OSError(code - 100, "child")
    if code != 0:
        raise OSError(0, "killed_childerr" + str(code))
    return data


def sys_mount():
    if libc.mount(b"tmpfs", b"/srv", b"tmpfs", 0, None) != 0:
        raise OSError(ctypes.get_errno(), "mount")


attempt("mount_syscall", lambda: in_child(sys_mount))
attempt("setuid_root", lambda: in_child(lambda: os.setuid(0)))
attempt("ptrace_attach_parent", lambda: in_child(lambda: (_ for _ in ()).throw(OSError(ctypes.get_errno(), "ptrace")) if libc.ptrace(16, os.getppid(), None, None) != 0 else None))
attempt("write_proc_sys", lambda: write("/proc/sys/kernel/hostname"))
attempt("write_sys", lambda: write("/sys/kernel/mm/transparent_hugepage/enabled"))
attempt("write_cgroup", lambda: write("/sys/fs/cgroup/cgroup.procs"))
for field in ("CapEff", "NoNewPrivs", "Seccomp"):
    attempt("status_" + field.lower(), lambda f=field: next(l.split()[1] for l in open("/proc/self/status") if l.startswith(f + ":")))
attempt("read_proc_1_environ", lambda: read("/proc/1/environ"))
attempt("visible_pids", lambda: str(sum(1 for d in os.listdir("/proc") if d.isdigit())))
attempt("signal0_harness_pid", lambda: os.kill(hpid, 0) or "signalable")
attempt("read_harness_cmdline", lambda: read(f"/proc/{hpid}/cmdline"))
attempt("dev_entries", lambda: str(len(os.listdir("/dev"))))
attempt("uid", lambda: str(os.getuid()))

# --- Design review round 1 (DR-1, DR-2, DR-4, DR-12) --------------------------------------------------------------
import struct


def statfs_type(path):
    buf = ctypes.create_string_buffer(120)
    if libc.statfs(path.encode(), buf) != 0:
        raise OSError(ctypes.get_errno(), "statfs")
    return hex(struct.unpack_from("q", buf, 0)[0])


attempt("tmp_fs_type", lambda: statfs_type("/tmp"))
attempt("var_tmp_fs_type", lambda: statfs_type("/var/tmp"))


def io_uring_socket(domain, connect_port=None):
    """IORING_OP_SOCKET (45): creates a socket without the socket(2) syscall."""
    params = ctypes.create_string_buffer(120)
    fd = libc.syscall(425, 4, params)  # io_uring_setup
    if fd < 0:
        raise OSError(ctypes.get_errno(), "io_uring_setup")
    sq_entries, cq_entries = struct.unpack_from("II", params, 0)
    sq_off = struct.unpack_from("IIIIIIIIQ", params, 40)  # head tail mask entries flags dropped array resv user_addr
    cq_off = struct.unpack_from("IIIIIIIIQ", params, 80)  # head tail mask entries overflow cqes flags resv user_addr
    ring_sz = max(sq_off[6] + sq_entries * 4, cq_off[5] + cq_entries * 16)
    ring = mmap.mmap(fd, ring_sz, mmap.MAP_SHARED, mmap.PROT_READ | mmap.PROT_WRITE, offset=0)
    sqes = mmap.mmap(fd, sq_entries * 64, mmap.MAP_SHARED, mmap.PROT_READ | mmap.PROT_WRITE, offset=0x10000000)
    sqe = bytearray(64)
    struct.pack_into("BBHiQ", sqe, 0, 45, 0, 0, domain, socket.SOCK_STREAM)  # opcode, flags, ioprio, fd=domain, off=type
    struct.pack_into("Q", sqe, 32, 7)  # user_data
    sqes[0:64] = bytes(sqe)
    tail = struct.unpack_from("I", ring, sq_off[1])[0]
    mask = struct.unpack_from("I", ring, sq_off[2])[0]
    struct.pack_into("I", ring, sq_off[6] + (tail & mask) * 4, 0)
    struct.pack_into("I", ring, sq_off[1], tail + 1)
    if libc.syscall(426, fd, 1, 1, 1, None, 0) < 0:  # io_uring_enter(to_submit=1, min_complete=1, GETEVENTS)
        raise OSError(ctypes.get_errno(), "io_uring_enter")
    head = struct.unpack_from("I", ring, cq_off[0])[0]
    cmask = struct.unpack_from("I", ring, cq_off[2])[0]
    res = struct.unpack_from("i", ring, cq_off[5] + (head & cmask) * 16 + 8)[0]
    if res < 0:
        raise OSError(-res, "IORING_OP_SOCKET")
    if connect_port is None:
        return "socket_created"
    s = socket.socket(fileno=res)
    s.settimeout(2)
    s.connect(("127.0.0.1", connect_port))
    return "connected"


attempt("io_uring_inet_decoy", lambda: in_child_ret(lambda: io_uring_socket(socket.AF_INET, port)))
attempt("io_uring_vsock", lambda: in_child_ret(lambda: io_uring_socket(40)))


def memfd_exec():
    mfd = os.memfd_create("x")
    with open("/usr/bin/id", "rb") as f:
        os.write(mfd, f.read())
    return exec_child(f"/proc/self/fd/{mfd}")


attempt("memfd_exec_id", lambda: in_child_ret(memfd_exec))
attempt("add_key", lambda: in_child_ret(lambda: "added" if libc.syscall(248, b"user", b"dms", b"x", 1, -2) >= 0 else (_ for _ in ()).throw(OSError(ctypes.get_errno(), "add_key"))))
attempt("read_cgroup_root", lambda: str(len(os.listdir("/sys/fs/cgroup"))))
attempt("dev_pts_entries", lambda: str(len(os.listdir("/dev/pts"))))
attempt("write_tmp_32k", lambda: write("/tmp/fill") and (open("/tmp/fill", "ab").write(b"x" * 32768) and "written"))
attempt("stderr_target", lambda: "pipe" if os.readlink("/proc/self/fd/2").startswith("pipe:") else "file")
# --- Identity separation (only system mode with DynamicUser can satisfy these) ---------------------------------------
attempt("read_operator_private_file", lambda: read(private_file))
attempt("proc_operator_visible", lambda: str(os.stat(f"/proc/{hpid}").st_uid))
