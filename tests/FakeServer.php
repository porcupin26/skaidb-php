<?php

declare(strict_types=1);

/**
 * An in-process skaidb server for the driver's tests: speaks the frame layer
 * and the SCRAM-SHA-256 handshake for real, and answers every request after
 * the handshake with whatever the test's handler scripts. No skaidb binary
 * is involved.
 *
 * The server runs in a forked child (the driver blocks in fread, so it cannot
 * share the test's process). The handler closure therefore runs in the child:
 * it may keep its own state across requests and connections, but it cannot
 * touch the test's variables. What the child saw comes back through
 * requests(), a log of every parsed post-handshake request.
 */

namespace SkaidbTests;

use Skaidb\Connection;
use Skaidb\Reader;

/** Server → client payload builders (PROTOCOL.md §3.2). */
final class F
{
    public static function str(string $s): string
    {
        return pack('V', strlen($s)) . $s;
    }

    /** @param array<int,string> $columns @param array<int,array<int,mixed>> $rows */
    private static function rowsBlock(array $columns, array $rows): string
    {
        $out = pack('V', count($columns));
        foreach ($columns as $c) {
            $out .= self::str($c);
        }
        $out .= pack('V', count($rows));
        foreach ($rows as $row) {
            $out .= pack('V', count($row));
            foreach ($row as $v) {
                $b = Connection::encodeValue($v);
                $out .= pack('V', strlen($b)) . $b;
            }
        }
        return $out;
    }

    public static function rows(array $columns, array $rows): string
    {
        return chr(0) . self::rowsBlock($columns, $rows);
    }

    public static function mutation(int $n): string
    {
        return chr(1) . pack('P', $n);
    }

    public static function ddl(): string
    {
        return chr(2);
    }

    public static function error(string $msg): string
    {
        return chr(3) . self::str($msg);
    }

    public static function prepared(int $id, int $nparams): string
    {
        return chr(4) . pack('V', $id) . pack('v', $nparams);
    }

    public static function header(array $columns): string
    {
        $out = chr(5) . pack('V', count($columns));
        foreach ($columns as $c) {
            $out .= self::str($c);
        }
        return $out;
    }

    public static function chunk(array $rows): string
    {
        $out = chr(6) . pack('V', count($rows));
        foreach ($rows as $row) {
            $out .= pack('V', count($row));
            foreach ($row as $v) {
                $b = Connection::encodeValue($v);
                $out .= pack('V', strlen($b)) . $b;
            }
        }
        return $out;
    }

    public static function end(): string
    {
        return chr(7);
    }

    /** @param array<int,array{0:array<int,string>,1:array<int,array<int,mixed>>}> $sets */
    public static function resultSets(array $sets): string
    {
        $out = chr(8) . pack('V', count($sets));
        foreach ($sets as [$columns, $rows]) {
            $out .= self::rowsBlock($columns, $rows);
        }
        return $out;
    }

    /** A whole frame: u32 BE length + payload. */
    public static function frame(string $payload): string
    {
        return pack('N', strlen($payload)) . $payload;
    }
}

final class FakeServer
{
    /** Return this from a handler to close the connection instead of answering. */
    public const DROP = "\0DROP\0";

    /** closeAll(): close every connection, as a node going away does. */
    public const CLOSE = 'close';

    /**
     * closeAll(): shut down only the SENDING side of every connection and
     * keep reading. The client reads end-of-file while its writes still
     * succeed — what a connection closed by a peer looks like over a real
     * network, where the reset for a write has not come back yet. Whatever
     * arrives afterwards is counted (strayBytes()) and discarded.
     */
    public const HALF = 'half';

    /**
     * closeAll(): close every connection with a reset (SO_LINGER 0) instead
     * of a clean end-of-file. Needs the sockets extension.
     */
    public const RESET = 'reset';

    public int $port = 0;

    private int $pid = 0;

    /** @var resource|null */
    private $listener = null;

    private string $log;

    /** How the next SIGUSR1 ends the connections: CLOSE, HALF or RESET. */
    private string $ctl;

    /** @var array<string,mixed>|null `ssl` context options: listen with TLS */
    private ?array $tls;

    /** Close a connection once this many bytes of one unfinished frame have arrived; 0 = never. */
    private int $cutAt;

    private string $password;

    /** @var callable(array):(string|array<int,string>|null) */
    private $handler;

    private bool $corruptServerSignature;

    /** @var (callable():bool)|null asked (in the child) at each accept: true closes the new connection unanswered */
    private $refuse;

    /** When set, every login is denied with this reason, whatever the proof. */
    private ?string $deny;

    private int $iterations = 1000;

    /** True inside the forked child, which must not tear the parent's state down. */
    private bool $child = false;

    /**
     * The process that made this server. Another fake server's child holds a
     * copy of this object too, and its exit must not stop this server.
     */
    private int $owner;

    /** Set by SIGUSR1; the select loop ends every client connection at its next safe point. */
    private bool $dropAll = false;

    /**
     * @param array{password?:string, handle?:callable, corruptServerSignature?:bool} $opts
     *   password — the one password every user has (default 'secret').
     *   handle   — fn(array $req): string|string[]|null, called for every
     *              post-handshake request; Hello is answered with Ddl unless
     *              the handler returns something. An empty list answers
     *              nothing and keeps the connection open (a stalled server).
     *              $req['conn'] numbers the connection it arrived on (1, 2,
     *              … in accept order), for state that is per connection, as
     *              a prepared statement's id is; requests() does not carry it.
     *   refuse   — fn(): bool, called at every accept; true closes the new
     *              connection before the handshake (a node at capacity).
     *   deny     — a reason: every login is answered with AuthOutcome
     *              Denied carrying it, whatever the proof.
     *   tls      — `ssl` stream-context options (local_cert, …): the server
     *              listens with TLS.
     *   cutAt    — bytes: a connection is closed once that much of a frame
     *              has arrived and the frame is still not complete, as a
     *              server closes one that sends a frame beyond its limit.
     *              The client is still writing then: its write fails.
     */
    public function __construct(array $opts = [])
    {
        $this->password = $opts['password'] ?? 'secret';
        $this->handler = $opts['handle'] ?? fn (array $req) => null;
        $this->refuse = $opts['refuse'] ?? null;
        $this->deny = $opts['deny'] ?? null;
        $this->tls = $opts['tls'] ?? null;
        $this->cutAt = $opts['cutAt'] ?? 0;
        $this->owner = getmypid();
        $this->corruptServerSignature = $opts['corruptServerSignature'] ?? false;
        $this->log = tempnam(sys_get_temp_dir(), 'skaidb-fake-');
        $this->ctl = tempnam(sys_get_temp_dir(), 'skaidb-fake-ctl-');
    }

    public function start(): self
    {
        $errno = 0;
        $errstr = '';
        // With TLS the listener is still plain TCP: each accepted
        // connection is upgraded in the child (see serve()), which keeps
        // its socket reachable for closeAll(RESET).
        $this->listener = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create($this->tls === null ? [] : ['ssl' => $this->tls])
        );
        if ($this->listener === false) {
            throw new \RuntimeException("fake server cannot listen: {$errstr}");
        }
        $name = stream_socket_get_name($this->listener, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('pcntl_fork failed');
        }
        if ($pid === 0) {
            // The child must never return into the test code: whatever
            // happens in serve(), it ends here.
            try {
                $this->serve();
            } catch (\Throwable $e) {
                fwrite(STDERR, 'fake server died: ' . $e->getMessage() . "\n");
            }
            exit(1);
        }
        $this->pid = $pid;
        fclose($this->listener);
        $this->listener = null;
        return $this;
    }

    /**
     * Ask the child to end every live client connection — closed, as a node
     * restart would (CLOSE), half-closed (HALF) or reset (RESET) — and wait
     * until it has: the child logs it, and the end-of-file or reset has had
     * time to reach the client.
     */
    public function closeAll(string $how = self::CLOSE): void
    {
        if ($this->pid <= 0) {
            return;
        }
        $done = fn () => count(array_filter($this->events(), fn ($r) => $r['op'] === 'dropped'));
        $before = $done();
        file_put_contents($this->ctl, $how);
        posix_kill($this->pid, SIGUSR1);
        $until = microtime(true) + 5.0;
        while ($done() === $before && microtime(true) < $until) {
            usleep(1_000);
        }
        usleep(50_000);
    }

    public function stop(): void
    {
        if ($this->child || getmypid() !== $this->owner) {
            return;
        }
        if ($this->pid > 0) {
            posix_kill($this->pid, SIGTERM);
            pcntl_waitpid($this->pid, $status);
            $this->pid = 0;
        }
        @unlink($this->log);
        @unlink($this->ctl);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Every parsed post-handshake request the child has seen, in order. Each
     * is logged BEFORE its reply is sent, so once a driver call returns its
     * request is here.
     *
     * @return array<int,array<string,mixed>>
     */
    public function requests(): array
    {
        return array_values(array_filter(
            $this->events(),
            fn ($r) => !in_array($r['op'], ['hangup', 'dropped', 'stray'], true)
        ));
    }

    /**
     * How many bytes arrived on connections after closeAll(HALF) half-closed
     * them: what a client wrote to a connection it could have known was
     * gone. The child notices within its select loop, so give it a moment.
     */
    public function strayBytes(): int
    {
        return array_sum(array_map(fn ($r) => $r['op'] === 'stray' ? $r['bytes'] : 0, $this->events()));
    }

    /**
     * How many connections the CLIENT closed (the child read end-of-file on
     * them). One the server dropped itself — DROP, closeAll() — is not
     * counted. The child notices within its select loop, so poll for it.
     */
    public function hangups(): int
    {
        return count(array_filter($this->events(), fn ($r) => $r['op'] === 'hangup'));
    }

    /** @return array<int,array<string,mixed>> everything the child logged, in order */
    private function events(): array
    {
        clearstatcache(true, $this->log);
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(fn (string $l) => unserialize(base64_decode($l)), $lines);
    }

    /** @return array<int,array<string,mixed>> only the Hello requests */
    public function hellos(): array
    {
        return array_values(array_filter($this->requests(), fn ($r) => $r['op'] === 8));
    }

    /** How many TCP connections the child accepted. */
    public function connections(): int
    {
        return count(array_filter($this->requests(), fn ($r) => $r['op'] === 'connect'));
    }

    // ---- child process --------------------------------------------------------

    private function serve(): never
    {
        $this->child = true;
        pcntl_async_signals(true);
        $conns = [];
        pcntl_signal(SIGTERM, function () {
            exit(0);
        });
        // The handler only raises a flag: closing sockets from inside an
        // async signal handler races the read loop below.
        pcntl_signal(SIGUSR1, function () {
            $this->dropAll = true;
        });
        stream_set_blocking($this->listener, false);
        $accepted = 0;
        while (true) {
            if (posix_getppid() === 1) {
                exit(0); // the test process is gone
            }
            if ($this->dropAll) {
                $this->dropAll = false;
                $how = (string) @file_get_contents($this->ctl);
                foreach ($conns as $id => $c) {
                    if ($how === self::HALF) {
                        stream_socket_shutdown($c['sock'], STREAM_SHUT_WR);
                        $conns[$id]['half'] = true;
                        continue;
                    }
                    if ($how === self::RESET) {
                        // SO_LINGER with a zero timeout: close() sends a
                        // reset instead of the orderly end-of-file.
                        socket_set_option($c['raw'], SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                    }
                    fclose($c['sock']);
                    unset($conns[$id]);
                }
                $this->record(['op' => 'dropped', 'how' => $how]);
            }
            $read = [$this->listener];
            foreach ($conns as $c) {
                $read[] = $c['sock'];
            }
            $write = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 0, 200_000);
            if ($n === false || $n === 0) {
                continue;
            }
            foreach ($read as $sock) {
                if ($sock === $this->listener) {
                    $client = @stream_socket_accept($this->listener, 0);
                    if ($client !== false && $this->refuse !== null && ($this->refuse)()) {
                        fclose($client);
                        continue;
                    }
                    if ($client !== false) {
                        // The socket under the stream, taken before TLS
                        // wraps it: a TLS stream cannot be imported later.
                        $raw = function_exists('socket_import_stream') ? socket_import_stream($client) : null;
                        if ($this->tls !== null
                            && @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                            fclose($client);
                            continue;
                        }
                        stream_set_blocking($client, false);
                        $conns[(int) $client] = ['sock' => $client, 'raw' => $raw, 'buf' => '', 'state' => 'start',
                            'user' => '', 'salted' => '', 'authMessage' => '', 'n' => ++$accepted, 'half' => false];
                        $this->record(['op' => 'connect']);
                    }
                    continue;
                }
                $id = (int) $sock;
                if (!isset($conns[$id]) || !is_resource($sock)) {
                    continue;
                }
                $data = @fread($sock, 65536);
                if ($data === false || ($data === '' && feof($sock))) {
                    fclose($sock);
                    unset($conns[$id]);
                    $this->record(['op' => 'hangup']);
                    continue;
                }
                if ($conns[$id]['half']) {
                    // Half-closed: nothing is answered any more. Count what
                    // still arrives and drop it.
                    if ($data !== '') {
                        $this->record(['op' => 'stray', 'bytes' => strlen($data)]);
                    }
                    continue;
                }
                $conns[$id]['buf'] .= $data;
                if ($this->cutAt > 0 && strlen($conns[$id]['buf']) >= $this->cutAt) {
                    fclose($sock);
                    unset($conns[$id]);
                    continue;
                }
                while (isset($conns[$id])) {
                    $buf = $conns[$id]['buf'];
                    if (strlen($buf) < 4) {
                        break;
                    }
                    $len = unpack('N', substr($buf, 0, 4))[1];
                    if (strlen($buf) < 4 + $len) {
                        break;
                    }
                    $payload = substr($buf, 4, $len);
                    $conns[$id]['buf'] = substr($buf, 4 + $len);
                    try {
                        $drop = $this->onFrame($conns[$id], $payload);
                    } catch (\Throwable $e) {
                        $this->send($sock, F::error('fake server: ' . $e->getMessage()));
                        $drop = false;
                    }
                    if ($drop) {
                        fclose($sock);
                        unset($conns[$id]);
                    }
                }
            }
        }
    }

    /** @param resource $sock */
    private function send($sock, string $payload): void
    {
        $frame = F::frame($payload);
        $off = 0;
        $len = strlen($frame);
        while ($off < $len) {
            $n = @fwrite($sock, substr($frame, $off));
            if ($n === false) {
                return;
            }
            if ($n === 0) {
                usleep(1000);
                continue;
            }
            $off += $n;
        }
    }

    private function record(array $req): void
    {
        file_put_contents($this->log, base64_encode(serialize($req)) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $conn (by reference) @return bool true to drop the connection */
    private function onFrame(array &$conn, string $payload): bool
    {
        $sock = $conn['sock'];
        $r = new Reader($payload);
        if ($conn['state'] === 'start') {
            if ($r->u8() !== 10) {
                throw new \RuntimeException('expected AuthStart');
            }
            $user = $r->text();
            $clientNonce = $r->text();
            $salt = random_bytes(16);
            $serverNonce = 's' . bin2hex(random_bytes(8));
            $conn['user'] = $user;
            $conn['authMessage'] = implode("\0", [$user, $clientNonce, $serverNonce, bin2hex($salt), (string) $this->iterations]);
            $conn['salted'] = hash_pbkdf2('sha256', $this->password, $salt, $this->iterations, 32, true);
            $this->send($sock, chr(11) . pack('V', strlen($salt)) . $salt . pack('V', $this->iterations) . F::str($serverNonce));
            $conn['state'] = 'finish';
            return false;
        }
        if ($conn['state'] === 'finish') {
            if ($r->u8() !== 12) {
                throw new \RuntimeException('expected AuthFinish');
            }
            $proof = $r->take(32);
            $clientKey = hash_hmac('sha256', 'Client Key', $conn['salted'], true);
            $storedKey = hash('sha256', $clientKey, true);
            $clientSig = hash_hmac('sha256', $conn['authMessage'], $storedKey, true);
            $recovered = $proof ^ $clientSig;
            if ($this->deny !== null) {
                $this->send($sock, chr(13) . chr(0) . F::str($this->deny));
                return true;
            }
            if (!hash_equals($storedKey, hash('sha256', $recovered, true))) {
                $this->send($sock, chr(13) . chr(0) . F::str('bad password'));
                return true;
            }
            $serverKey = hash_hmac('sha256', 'Server Key', $conn['salted'], true);
            $serverSig = hash_hmac('sha256', $conn['authMessage'], $serverKey, true);
            if ($this->corruptServerSignature) {
                $serverSig = str_repeat(chr(7), 32);
            }
            $this->send($sock, chr(13) . chr(1) . $serverSig);
            $conn['state'] = 'ready';
            return false;
        }
        $req = self::parseRequest($payload);
        $req['user'] = $conn['user'];
        $this->record($req);
        $out = ($this->handler)($req + ['conn' => $conn['n']]);
        if ($out === null) {
            $out = $req['op'] === 8 ? F::ddl() : F::error("fake server: unhandled op {$req['op']}");
        }
        if ($out === self::DROP) {
            return true;
        }
        foreach (is_array($out) ? $out : [$out] as $p) {
            $this->send($sock, $p);
        }
        return false;
    }

    /** Client → server payload parser (§3). @return array<string,mixed> */
    public static function parseRequest(string $payload): array
    {
        $r = new Reader($payload);
        $op = $r->u8();
        switch ($op) {
            case 1:
            case 5:
                $c = $r->u8();
                return ['op' => $op, 'consistency' => $c, 'sql' => $r->text()];
            case 2:
                return ['op' => $op, 'sql' => $r->text()];
            case 3:
                $c = $r->u8();
                $id = $r->u32();
                $n = $r->u16();
                $params = [];
                for ($i = 0; $i < $n; $i++) {
                    $params[] = Connection::decodeValue(new Reader($r->blob()));
                }
                return ['op' => $op, 'consistency' => $c, 'id' => $id, 'params' => $params];
            case 4:
                return ['op' => $op, 'id' => $r->u32()];
            case 7:
                $c = $r->u8();
                $id = $r->u32();
                $nrows = $r->u32();
                $rows = [];
                for ($i = 0; $i < $nrows; $i++) {
                    $n = $r->u16();
                    $row = [];
                    for ($j = 0; $j < $n; $j++) {
                        $row[] = Connection::decodeValue(new Reader($r->blob()));
                    }
                    $rows[] = $row;
                }
                return ['op' => $op, 'consistency' => $c, 'id' => $id, 'rows' => $rows];
            case 8:
                return ['op' => $op, 'name' => $r->text(), 'version' => $r->text()];
            case 9: // OP_QUERY_STREAM_PARAMS: OP_QUERY_STREAM, then OP_EXECUTE's parameter vector
                $c = $r->u8();
                $sql = $r->text();
                $n = $r->u16();
                $params = [];
                for ($i = 0; $i < $n; $i++) {
                    $params[] = Connection::decodeValue(new Reader($r->blob()));
                }
                return ['op' => $op, 'consistency' => $c, 'sql' => $sql, 'params' => $params];
            default:
                return ['op' => $op, 'raw' => $payload];
        }
    }
}
