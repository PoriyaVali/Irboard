"""Run isolated Workerman/Adapterman processes; never bootstrap Laravel or its DB."""
from pathlib import Path
import json
import os
import signal
import socket
import subprocess
import sys
import time
import tempfile

TEMP = tempfile.TemporaryDirectory(prefix='irboard-webman-test-')
ROOT = Path(TEMP.name)
APP = Path(os.environ.get('IRBOARD_WEBMAN_TEST_APP', str(Path(__file__).resolve().parents[1]))).resolve()
PHP = os.environ.get('PHP_BINARY', 'php')
BODY = b'x' * (512 * 1024)


def wait_for(predicate, timeout=5):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        result = predicate()
        if result:
            return result
        time.sleep(0.05)
    raise AssertionError('timeout waiting for condition')


def children(pid):
    try:
        return [int(p) for p in Path(f'/proc/{pid}/task/{pid}/children').read_text().split()]
    except FileNotFoundError:
        return []


def start(source, case, max_requests):
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1', 0))
        port = reserve.getsockname()[1]
    php = r'''<?php
require __AUTOLOAD__;
use Workerman\Worker;
use Workerman\Timer;
Adapterman\Adapterman::init();
define('MAX_MEMORY_MB', 128);
define('MAX_REQUEST', __MAX_REQUESTS__);
function run() { return str_repeat('x', 512 * 1024); }
$http = new Worker('http://127.0.0.1:__PORT__');
$http->count = 1;
$http->name = 'isolated-lifecycle-test';
// Reproduce closed connections retained by application/protocol references.
$http->onConnect = static function ($conn) { $GLOBALS['retained'][] = $conn; };
$source = file_get_contents(__SOURCE__);
$begin = strpos($source, '$http->onMessage = ');
$end = strpos($source, 'Worker::$pidFile = ', $begin);
if ($begin === false || $end === false) throw new RuntimeException('callback boundaries missing');
eval('use Workerman\\Worker; use Workerman\\Timer;' . substr($source, $begin, $end - $begin));
Worker::$pidFile = __PIDFILE__;
Worker::$logFile = __LOGFILE__;
Worker::runAll();
'''
    for key, value in {
        '__AUTOLOAD__': json.dumps(str(APP / 'vendor/autoload.php')),
        '__MAX_REQUESTS__': str(max_requests), '__PORT__': str(port),
        '__SOURCE__': json.dumps(str(source)),
        '__PIDFILE__': json.dumps(str(ROOT / (case + '.pid'))),
        '__LOGFILE__': json.dumps(str(ROOT / (case + '.worker.log'))),
    }.items():
        php = php.replace(key, value)
    path = ROOT / (case + '.php')
    path.write_text(php)
    log = (ROOT / (case + '.stdout.log')).open('wb')
    # Adapterman requires its native HTTP/session functions disabled, as in production.
    php_args = [PHP, '-c', os.environ.get('PHP_INI', str(APP / 'cli-php.ini'))]
    proc = subprocess.Popen(php_args + [str(path), 'start'],
                            cwd=ROOT, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
    try:
        wait_for(lambda: children(proc.pid))
    except BaseException as exc:
        cleanup(proc, log)
        details = (ROOT / (case + '.stdout.log')).read_text(errors='replace')
        raise AssertionError(f'Fixture startup failed: {exc}\n{details}') from exc
    return proc, port, log


def connect(port):
    end = time.monotonic() + 5
    while True:
        try:
            conn = socket.create_connection(('127.0.0.1', port), timeout=2)
            conn.settimeout(4)
            return conn
        except OSError:
            if time.monotonic() > end:
                raise
            time.sleep(0.05)


def send(conn):
    conn.sendall(b'GET /test HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n')


def receive(conn):
    data = b''
    while b'\r\n\r\n' not in data:
        block = conn.recv(16384)
        assert block, 'connection closed before headers'
        data += block
    head, body = data.split(b'\r\n\r\n', 1)
    assert head.startswith(b'HTTP/1.1 200 '), head
    length = next(int(line.split(b':', 1)[1]) for line in head.split(b'\r\n') if line.lower().startswith(b'content-length:'))
    while len(body) < length:
        block = conn.recv(min(65536, length - len(body)))
        assert block, f'truncated response {len(body)}/{length}'
        body += block
    assert body == BODY, f'wrong response length {len(body)}'


def cleanup(proc, log):
    if proc.poll() is None:
        os.kill(proc.pid, signal.SIGINT)
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            # Isolated test process group only; no production PID can be here.
            os.killpg(proc.pid, signal.SIGKILL)
            proc.wait()
    log.close()


def graceful(source, label):
    case = label + '-graceful'
    proc, port, log = start(source, case, 100)
    conn = None
    try:
        # Leave a fully closed but retained object before the active connection.
        with connect(port) as closed:
            send(closed)
            receive(closed)
        time.sleep(0.1)
        conn = connect(port)
        send(conn)
        # A completed TCP handshake alone does not mean the child accepted it.
        # Peek at response bytes to confirm acceptance without consuming the body.
        assert conn.recv(1, socket.MSG_PEEK), 'fixture did not begin responding'
        os.kill(proc.pid, signal.SIGQUIT)
        time.sleep(0.3)
        assert proc.poll() is None, 'worker exited with active connection'
        receive(conn)
        # Keepalive must still work during draining, with its full response intact.
        send(conn)
        receive(conn)
        assert proc.poll() is None, 'active keepalive was dropped'
        conn.close()
        conn = None
        try:
            proc.wait(timeout=2)
            stopped = True
        except subprocess.TimeoutExpired:
            stopped = False
        assert stopped and proc.returncode == 0, 'graceful stop did not drain and exit cleanly'
        print('PASS: graceful stop retains active keepalive, drains 512KiB responses, exits after last socket closes', flush=True)
    finally:
        if conn is not None:
            conn.close()
        cleanup(proc, log)


def recycle(source, label):
    case = label + '-recycle'
    proc, port, log = start(source, case, 2)
    try:
        initial = children(proc.pid)
        for _ in range(3):
            with connect(port) as conn:
                send(conn)
                time.sleep(0.1)
                receive(conn)
        wait_for(lambda: children(proc.pid) and children(proc.pid) != initial)
        with connect(port) as conn:
            send(conn)
            receive(conn)
        log.flush()
        text = (ROOT / (case + '.stdout.log')).read_text(errors='replace')
        fatal = 'undefined method Workerman\\Worker::stopSelf()' in text
        assert not fatal and 'Error:' not in text and 'exit with status' not in text, text
        print('PASS: request-limit recycle exits normally, preserves full 512KiB response, replacement serves requests', flush=True)
    finally:
        cleanup(proc, log)


if __name__ == '__main__':
    try:
        source = APP / 'webman.php'
        assert (APP / 'vendor/autoload.php').is_file(), 'Run composer install first'
        graceful(source, 'webman')
        recycle(source, 'webman')
        print('All WebMan lifecycle tests PASS; Laravel/DB not bootstrapped.', flush=True)
    finally:
        TEMP.cleanup()
