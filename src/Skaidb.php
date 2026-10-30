<?php

declare(strict_types=1);

/**
 * skaidb — PHP driver.
 *
 * A pure-PHP client for the skaidb binary wire protocol. The public API is
 * modelled on PDO/PDOStatement so it should feel immediately familiar:
 *
 *     use Skaidb\Connection;
 *
 *     $db = new Connection('localhost', 7000, 'skaidb', 'secret');
 *
 *     $db->exec('CREATE TABLE users (PRIMARY KEY (id))');
 *
 *     $stmt = $db->prepare('INSERT INTO users (id, name) VALUES (?, ?)');
 *     $stmt->execute([1, 'Ada']);
 *
 *     $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ?');
 *     $stmt->execute([1]);
 *     foreach ($stmt->fetchAll() as $row) {
 *         print_r($row);     // ['id' => 1, 'name' => 'Ada']
 *     }
 *
 * No Composer or PECL dependencies — only the bundled `hash` extension.
 * Targets PHP 8.1+.
 *
 * Placeholders are '?' (positional). With parameters, a statement is prepared
 * on the server and the values travel as typed wire values; statements the
 * server declines to prepare (DDL, session statements) fall back to safe
 * client-side quoting, so values like "O'Brien" are safe on every path.
 */

namespace Skaidb;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Thrown for every skaidb error: connection/transport failures, handshake
 * denials, and server-side statement errors. Modelled on PDOException.
 */
class SkaidbException extends Exception
{
}

/**
 * The server declines to prepare some statement kinds (DDL, session
 * statements). Not an error — the caller falls back to client-side text
 * binding.
 */
class Unpreparable extends SkaidbException
{
}

/**
 * Package identity. VERSION is the single source of truth for the driver
 * version: composer.json carries none (Composer takes it from the git tag),
 * the Hello frame reports this value to the server, and the release
 * workflow refuses a tag that does not equal it.
 */
final class Skaidb
{
    public const VERSION = '1.4.0';

    /** The client_name the driver reports in the server's `drivers` table. */
    public const CLIENT_NAME = 'php';
}

/**
 * Bind a parameter as a skaidb Bytes value. A PHP string binds as String;
 * wrap it to send the raw bytes with the Bytes tag instead.
 *
 *     $stmt->execute([1, new Bytes($blob)]);
 */
final class Bytes
{
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * Bind a parameter as a skaidb Decimal, from its exact decimal string form
 * ('123.45', '-0.005'; exponent notation is not accepted). Decimal results
 * come back as plain strings; wrap one to write it back unchanged.
 */
final class Decimal
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $value) !== 1) {
            throw new SkaidbException("not a decimal string: {$value}");
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * Bind a parameter as a skaidb Uuid, from its canonical 8-4-4-4-12 form
 * (hyphens optional, case-insensitive). Uuid results come back as canonical
 * lowercase strings; wrap one to write it back as a Uuid.
 */
final class Uuid
{
    /** canonical lowercase 8-4-4-4-12 */
    public readonly string $value;

    public function __construct(string $value)
    {
        $hex = strtolower(str_replace('-', '', trim($value)));
        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1) {
            throw new SkaidbException("not a uuid: {$value}");
        }
        $this->value = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** The 16 raw bytes (RFC 4122 byte order). */
    public function bytes(): string
    {
        return hex2bin(str_replace('-', '', $this->value));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * A connection to one skaidb node. Runs the SCRAM-SHA-256 handshake in the
 * constructor, then exposes a PDO-shaped query API.
 */
class Connection
{
    public const ONE = 0;
    public const QUORUM = 1;
    public const ALL = 2;

    /** Value type tags (§4 of PROTOCOL.md). */
    private const TAG_NULL = 0;
    private const TAG_BOOL = 1;
    private const TAG_INT = 2;
    private const TAG_FLOAT = 3;
    private const TAG_DECIMAL = 4;
    private const TAG_STRING = 5;
    private const TAG_BYTES = 6;
    private const TAG_UUID = 7;
    private const TAG_TIMESTAMP = 8;
    private const TAG_ARRAY = 9;
    private const TAG_DOCUMENT = 10;

    private const CONSISTENCY_BY_NAME = ['ONE' => 0, 'QUORUM' => 1, 'ALL' => 2];

    /** @var resource|null the TCP stream */
    private $sock;

    private int $consistency;

    private bool $closed = false;

    /** @var array<string,array{0:int,1:int}> */
    private array $preparedCache = [];

    /** Transport died; the next statement re-dials (see ensureLive). */
    private bool $broken = false;

    /**
     * A stream is in flight: the connection owes us RowsChunk frames up to
     * RowsEnd (PROTOCOL.md §3.4) and is therefore NOT sitting at a request
     * boundary. Guards every other statement, and fails isUsable() so a pool
     * cannot hand the connection out mid-stream.
     */
    private bool $streaming = false;

    /** Everything a redial needs, captured at construction. */
    private array $dialArgs = [];

    /**
     * The Pool that owns this connection (see Pool), or null outside one:
     * told each round trip's latency, each transport failure and each write,
     * and asked for the dial order when the connection re-dials.
     */
    private ?Pool $pool = null;

    /** @var array<int,string>|null endpoints to dial in exactly this order (pooled); null = the seeds, shuffled */
    private ?array $order = null;

    /** The endpoint ('host:port') the live socket reached. */
    private ?string $connected = null;

    private static int $nonceCounter = 0;

    /**
     * Open a connection and complete the SCRAM-SHA-256 handshake.
     *
     * @param string     $host        node hostname
     * @param int        $port        binary protocol port (default 7000)
     * @param string     $user        username ("anonymous" for auth-disabled servers)
     * @param string     $password    password (empty for anonymous)
     * @param int|string $consistency 'ONE'/'QUORUM'/'ALL' or 0/1/2 (default QUORUM)
     * @param float      $timeout     connect/read timeout in seconds
     * @param string|null $tlsClientCert PEM client certificate presented in the TLS handshake (implies TLS)
     * @param string|null $tlsClientKey  PEM private key for it, when not in the certificate file
     * @param string     $authMechanism 'scram' (username + password) or 'certificate'
     *                                  (the client certificate is the login; wire mechanism EXTERNAL)
     *
     * @throws SkaidbException on connect or auth failure
     */
    public function __construct(
        string $host = 'localhost',
        int $port = 7000,
        string $user = 'anonymous',
        string $password = '',
        $consistency = 'QUORUM',
        float $timeout = 10.0,
        ?string $database = null,
        bool $tls = false,
        ?string $tlsCa = null,
        bool $tlsInsecure = false,
        string $tlsServerName = 'skaidb',
        array $seeds = [],
        ?string $tlsClientCert = null,
        ?string $tlsClientKey = null,
        string $authMechanism = 'scram'
    ) {
        $this->consistency = self::resolveConsistency($consistency);
        $certAuth = self::resolveAuthMechanism($authMechanism);
        if ($tlsClientCert === '') {
            $tlsClientCert = null;
        }
        if ($tlsClientKey !== null && $tlsClientKey !== '' && $tlsClientCert === null) {
            throw new SkaidbException('tlsClientKey needs tlsClientCert');
        }
        if ($certAuth && $tlsClientCert === null) {
            throw new SkaidbException(
                "authMechanism 'certificate' needs a TLS client certificate (tlsClientCert)"
            );
        }
        // Retained so a reconnect repeats the original connect exactly.
        $this->dialArgs = compact(
            'host', 'port', 'user', 'password', 'timeout', 'database',
            'tls', 'tlsCa', 'tlsInsecure', 'tlsServerName', 'seeds',
            'tlsClientCert', 'tlsClientKey', 'certAuth'
        );
        $this->dial();
    }

    /**
     * A pool's connection: dials exactly $order (best first, no shuffle) and
     * reports its round trips, transport failures and writes to $pool.
     *
     * @internal
     * @param array<string,mixed> $args  named Connection constructor arguments
     * @param array<int,string>   $order endpoints to try, in order
     */
    public static function pooled(array $args, array $order, ?Pool $pool): self
    {
        $conn = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $conn->pool = $pool;
        $conn->order = $order;
        // The constructor proper, on the prepared instance: it validates the
        // arguments and dials — now in the pool's order.
        $conn->__construct(...$args);
        return $conn;
    }

    /**
     * Connect, authenticate and enter the session database. Used for the
     * first connect and for every reconnect, so a recovered connection is
     * indistinguishable from a fresh one.
     */
    private function dial(): void
    {
        extract($this->dialArgs);

        $errno = 0;
        $errstr = '';
        // A server with client_tls = required refuses plaintext outright, so
        // without TLS such a cluster is simply unreachable. Any of the TLS
        // knobs turns it on, a client certificate included.
        $tls = $tls || $tlsCa !== null || $tlsInsecure || $tlsClientCert !== null;
        $opts = ['socket' => ['tcp_nodelay' => true]];
        if ($tls) {
            // peer_name is SNI *and* the verified name; skaidb's certs carry
            // DNS:skaidb, which is usually NOT the address dialled.
            $opts['ssl'] = [
                'peer_name' => $tlsServerName,
                'verify_peer' => !$tlsInsecure,
                'verify_peer_name' => !$tlsInsecure,
                // Encrypt-without-authenticating is development only.
                'allow_self_signed' => $tlsInsecure,
            ];
            if ($tlsCa !== null && $tlsCa !== '') {
                $opts['ssl']['cafile'] = $tlsCa;
            }
            if ($tlsClientCert !== null) {
                // Presented during the TLS handshake. With the certificate
                // mechanism it is the login; otherwise it only satisfies a
                // listener that verifies client certificates.
                $opts['ssl']['local_cert'] = $tlsClientCert;
                if ($tlsClientKey !== null && $tlsClientKey !== '') {
                    $opts['ssl']['local_pk'] = $tlsClientKey;
                }
            }
        }
        $ctx = stream_context_create($opts);
        // Seeds: try each until one connects. skaidb is leaderless, so any
        // node serves — there is no primary to discover. Shuffled so many
        // clients spread instead of stampeding the first entry. A pooled
        // connection dials in the pool's order instead.
        if ($this->order !== null) {
            $endpoints = $this->order;
        } else {
            $endpoints = $seeds === [] ? ["{$host}:{$port}"] : $seeds;
            shuffle($endpoints);
        }
        $tried = [];
        $why = '';
        foreach ($endpoints as $ep) {
            $tried[] = $ep;
            $sock = @stream_socket_client(
                ($tls ? 'ssl://' : 'tcp://') . $ep,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            if ($sock === false) {
                $why = "{$errstr} ({$errno})";
                $this->pool?->failed($ep);
                continue;
            }
            $this->sock = $sock;
            // Read timeout for fread loops.
            stream_set_timeout($this->sock, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));

            try {
                if ($certAuth) {
                    // 'anonymous' is only the constructor's default, not a
                    // claim: send no username and let the certificate name it.
                    $this->handshakeCertificate($user === 'anonymous' ? '' : $user);
                } else {
                    $this->handshake($user, $password);
                }
            } catch (SkaidbException $e) {
                if ($this->order === null || !Pool::memberFault($e)) {
                    $this->close();
                    throw $e;
                }
                // Pooled: a member that dropped or refused the handshake (a
                // capacity refusal included) is a failed dial — try the next
                // on a clean slate.
                @fclose($this->sock);
                $this->sock = null;
                $this->broken = false;
                $why = $e->getMessage();
                $this->pool?->failed($ep);
                continue;
            }
            $this->connected = $ep;
            $this->pool?->moved($this);
            break;
        }
        if ($this->sock === null) {
            $list = implode(', ', $tried);
            throw new SkaidbException("no reachable endpoint in {$list}: {$why}");
        }

        $this->sendHello();
        // USE is per-connection session state, so it runs on every dial.
        if ($database !== null && $database !== '') {
            $this->exec('USE "' . str_replace('"', '""', $database) . '"');
        }
    }

    /**
     * Best-effort self-identification: fills the server's `drivers` table
     * client_name/client_version. An old server answers the unknown opcode
     * with an error frame, which is ignored — identity is telemetry, never
     * load-bearing.
     */
    private function sendHello(): void
    {
        try {
            $name = Skaidb::CLIENT_NAME;
            $ver = Skaidb::VERSION;
            $req = chr(8)
                . pack('V', strlen($name)) . $name
                . pack('V', strlen($ver)) . $ver;
            $this->writeFrame($req);
            $this->readFrame();
        } catch (SkaidbException $e) {
            // telemetry only
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Resolve a consistency level (name or 0/1/2) to its integer code.
     *
     * @param int|string $value
     */
    public static function resolveConsistency($value): int
    {
        if (is_int($value)) {
            if (!in_array($value, [0, 1, 2], true)) {
                throw new SkaidbException("invalid consistency {$value}");
            }
            return $value;
        }
        $key = strtoupper((string) $value);
        if (!isset(self::CONSISTENCY_BY_NAME[$key])) {
            throw new SkaidbException("invalid consistency {$value}");
        }
        return self::CONSISTENCY_BY_NAME[$key];
    }

    /**
     * 'scram'/'password' → false, 'certificate'/'external'/'x509' → true.
     */
    private static function resolveAuthMechanism(string $mechanism): bool
    {
        $m = strtolower($mechanism);
        if (in_array($m, ['scram', 'password'], true)) {
            return false;
        }
        if (in_array($m, ['certificate', 'external', 'x509'], true)) {
            return true;
        }
        throw new SkaidbException("unknown authMechanism {$mechanism} (use 'scram' or 'certificate')");
    }

    /** Set the default consistency level for subsequent queries. */
    public function setConsistency($consistency): void
    {
        $this->consistency = self::resolveConsistency($consistency);
    }

    /** Current default consistency code (0/1/2). */
    public function getConsistency(): int
    {
        return $this->consistency;
    }

    /**
     * Prepare a statement with '?' positional placeholders. Returns a
     * Statement you can `execute()` with parameters.
     */
    public function prepare(string $sql): Statement
    {
        return new Statement($this, $sql);
    }

    /**
     * Run SQL with no parameters and return a Statement holding the result.
     * Convenience for SELECTs without binds (mirrors PDO::query).
     */
    public function query(string $sql): Statement
    {
        $stmt = new Statement($this, $sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Execute a statement and return the number of affected rows (PDO::exec
     * semantics). For DDL or non-mutation statements this is 0.
     */
    public function exec(string $sql): int
    {
        $stmt = new Statement($this, $sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Yield a stream's events as they arrive, forever.
     *
     * A dependency-free helper over the stream's log: pages it with the
     * keyset cursor and yields each event (id, op, k, ts, doc). `id` is the
     * position — keep the last one and pass it as $after to resume exactly
     * where you stopped, across restarts.
     *
     * This polls; for push delivery subscribe to `$stream/<db>/<name>` with
     * any MQTT client instead. The events are identical.
     *
     *     foreach ($db->subscribe('big_orders') as $ev) { ... }
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function subscribe(string $stream, ?string $after = null, float $poll = 0.5): \Generator
    {
        $log = '_stream_' . $stream;
        $cur = $after;
        while (true) {
            if ($cur === null) {
                $st = $this->prepare("SELECT id, op, k, ts, doc FROM {$log} ORDER BY id LIMIT 500");
                $st->execute();
            } else {
                $st = $this->prepare(
                    "SELECT id, op, k, ts, doc FROM {$log} WHERE id > ? ORDER BY id LIMIT 500"
                );
                $st->execute([$cur]);
            }
            $rows = $st->fetchAll();
            foreach ($rows as $row) {
                $cur = $row['id'];
                yield $row;
            }
            if (count($rows) === 0) {
                usleep((int) ($poll * 1_000_000));
            }
        }
    }

    /**
     * False once closed, once a transport error broke the socket, and while a
     * stream is still in flight. Pool::acquire/release use this as the health
     * check, and a connection parked on a RowsChunk would answer the next
     * borrower's statement with a leftover stream frame.
     */
    public function isUsable(): bool
    {
        return !$this->closed && !$this->broken && !$this->streaming
            && is_resource($this->sock);
    }

    /**
     * The endpoint ('host:port', as configured or discovered) the live socket
     * reached — the member this connection's statements go to.
     */
    public function endpoint(): ?string
    {
        return $this->connected;
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            if (is_resource($this->sock)) {
                @fclose($this->sock);
            }
            $this->sock = null;
            // Closed while checked out instead of released: the pool stops
            // counting it against its member.
            $this->pool?->closedOut($this);
        }
    }

    // ---- internal: query execution (called by Statement) ------------------

    /**
     * Send one query frame and decode the response.
     *
     * @internal
     *
     * @return array{kind:string, columns:array<int,string>, rows:array<int,array<int,mixed>>, affected:int}
     */
    public function runQuery(string $sql, int $consistency): array
    {
        $this->assertNotStreaming();
        $this->ensureLive();
        if ($this->closed) {
            throw new SkaidbException('connection is closed');
        }
        $sqlBytes = $sql; // already a UTF-8 byte string
        // OP_QUERY=1, consistency u8, u32 LE sql_len, sql bytes.
        $req = pack('C', 1) . pack('C', $consistency)
            . pack('V', strlen($sqlBytes)) . $sqlBytes;
        return $this->roundtrip($req);
    }

    /**
     * Stream a result set: yields one associative-array row at a time while
     * holding a single chunk, instead of buffering the whole result. For
     * exports and large scans.
     *
     *     foreach ($db->stream('SELECT ...') as $row) { ... }
     *     foreach ($db->stream('SELECT id, v FROM t WHERE ts >= ? AND ts < ?', [$from, $to]) as $row) { ... }
     *
     * $params bind the statement's '?' placeholders in order — typed, as a
     * prepared Statement::execute() sends them (associative arrays are
     * re-indexed; there is no client-side quoting fallback on a stream).
     * They travel in OP_QUERY_STREAM_PARAMS (server >= 0.330), as the same
     * parameter vector OP_EXECUTE carries; an older server's refusal throws
     * "server does not support parameterised streaming (skaidb >= 0.330): …"
     * and leaves the connection usable. Without parameters the request is
     * the original OP_QUERY_STREAM, so a parameterless stream works against
     * every server. A consistency in the second position, stream($sql,
     * 'ONE') — the signature before $params existed — still means the
     * consistency.
     *
     * The connection is busy for the whole stream (PROTOCOL.md §3.4): any
     * other statement on it throws until the stream ends. Abandoning it —
     * break, return, an exception, or just letting the generator be collected
     * — runs the finally below, which drains the frames still in flight so the
     * connection stays usable; a drain that cannot complete marks the
     * connection broken instead, so it re-dials rather than answering the next
     * statement with a leftover frame.
     *
     * Once the generator has run to the end, getReturn() describes the
     * statement: ['kind' => 'rows'|'mutation'|'ddl', 'columns' => [...],
     * 'affected' => int]. That is how a stream with no rows still reports its
     * column names, and how a non-row statement reports its affected count.
     *
     * A CALL whose body EMITs result sets is answered with all of them in one
     * frame, streamed or not: the generator yields the rows fetch() would
     * return (the call's final result, the last set), getReturn() adds
     * 'result_sets' — every set, in order, as Statement::resultSets() returns
     * them — and the connection is free again before the first row arrives.
     *
     * @param array<int|string,mixed>|int|string|null $params values for the '?' placeholders, in order
     *                                                        (an int or string here, with no $consistency, is the consistency)
     * @param int|string|null $consistency 'ONE'/'QUORUM'/'ALL' or 0/1/2; default the connection's
     * @return \Generator<int,array<string,mixed>,mixed,array{kind:string,columns:array<int,string>,affected:int,result_sets?:array<int,array{columns:array<int,string>,rows:array<int,array<string,mixed>>}>}>
     */
    public function stream(string $sql, $params = [], $consistency = null): \Generator
    {
        if (!is_array($params)) {
            if ($params !== null) {
                if ($consistency !== null) {
                    $t = is_object($params) ? get_class($params) : gettype($params);
                    throw new SkaidbException("stream() takes its parameters as an array, got {$t}");
                }
                // stream($sql, 'ONE'): the signature before $params existed.
                $consistency = $params;
            }
            $params = [];
        }
        $params = array_values($params);
        $level = $consistency === null ? $this->consistency : self::resolveConsistency($consistency);
        $this->assertNotStreaming();
        $this->ensureLive();
        if ($this->closed) {
            throw new SkaidbException('connection is closed');
        }
        // OP_QUERY_STREAM=5: consistency u8, u32 LE sql_len, sql bytes. With
        // parameters, OP_QUERY_STREAM_PARAMS=9: the same, then the parameter
        // vector. Built before anything is sent, so a value that cannot be
        // bound throws with the connection still at a request boundary.
        $req = pack('C', $params === [] ? 5 : 9) . pack('C', $level) . pack('V', strlen($sql)) . $sql;
        if ($params !== []) {
            $req .= self::encodeParams($params);
        }
        $started = hrtime(true);
        try {
            $this->writeFrame($req);
        } catch (SkaidbException $e) {
            $this->observeFailure();
            throw $e;
        }
        // Busy from the request, not from the first row: the header read below
        // is already part of the stream's exchange.
        $this->streaming = true;
        $live = false; // true only once the header promises RowsChunk frames
        $released = false; // the exchange ended before the rows were yielded
        try {
            try {
                $frame = $this->readFrame();
            } catch (SkaidbException $e) {
                $this->observeFailure();
                throw $e;
            }
            $this->pool?->roundTrip($this, (hrtime(true) - $started) / 1e9);
            $r = new Reader($frame);
            // Before the tag is known, a short or empty frame makes even
            // `u8()` throw — and at that point we cannot say what the
            // reply left queued, so the socket is not reusable.
            try {
                $tag = $r->u8();
            } catch (\Throwable $e) {
                $this->broken = true;
                throw $e;
            }
            if ($tag === 3) {
                // One ordinary Error frame before any header: the statement
                // failed and the connection is at a request boundary.
                $msg = $r->text();
                if (str_contains($msg, 'unknown opcode')) {
                    $what = $params === [] ? 'streaming' : 'parameterised streaming (skaidb >= 0.330)';
                    throw new SkaidbException("server does not support {$what}: {$msg}");
                }
                throw new SkaidbException($msg);
            }
            $this->noteStatement($sql);
            if ($tag === 1) { // Mutation: not row-producing
                return ['kind' => 'mutation', 'columns' => [], 'affected' => $r->u64()];
            }
            if ($tag === 2) { // Ddl
                return ['kind' => 'ddl', 'columns' => [], 'affected' => 0];
            }
            if ($tag === 8) {
                // ResultSets: a CALL whose body EMITted is answered with
                // every set in ONE frame, streamed or not — the frame
                // Statement::execute() receives, decoded by the same code.
                // Nothing follows it, so the connection is handed back
                // before the first row reaches the caller (who may use it),
                // and the finally below must not release it a second time:
                // by then it may belong to a stream the caller started.
                $sets = self::assocSets(self::readResultSets($r)['result_sets']);
                $this->finishStream(false);
                $released = true;
                $last = $sets === [] ? ['columns' => [], 'rows' => []] : $sets[count($sets) - 1];
                foreach ($last['rows'] as $row) {
                    yield $row;
                }
                return ['kind' => 'rows', 'columns' => $last['columns'], 'affected' => 0, 'result_sets' => $sets];
            }
            if ($tag !== 5) {
                // We cannot know what else this reply left queued behind it.
                $this->broken = true;
                throw new SkaidbException("unexpected response tag {$tag} to stream request");
            }
            // The server has committed to chunks + RowsEnd, so from here
            // the socket carries frames this call owns: say so BEFORE
            // parsing the header. A Reader throw on a truncated column
            // list would otherwise leave `$live === false` and
            // `$broken === false`, so finishStream() returns without
            // draining, isUsable() stays true, and a pool hands out a
            // connection with the whole chunk sequence still queued — the
            // "unknown response tag 6" this ensure exists to prevent,
            // reachable through a three-line window.
            $live = true;
            $ncols = $r->u32();
            $columns = [];
            for ($i = 0; $i < $ncols; $i++) {
                $columns[] = $r->text();
            }
            while ($live) {
                $fr = new Reader($this->readFrame());
                $t = $fr->u8();
                if ($t === 6) {
                    $n = $fr->u32();
                    for ($i = 0; $i < $n; $i++) {
                        $ncells = $fr->u32();
                        $row = [];
                        for ($c = 0; $c < $ncells; $c++) {
                            $row[$columns[$c] ?? $c] = self::decodeValue(new Reader($fr->blob()));
                        }
                        yield $row;
                    }
                } elseif ($t === 7) {
                    $live = false;
                } elseif ($t === 3) {
                    $live = false;
                    throw new SkaidbException($fr->text());
                } else {
                    $live = false;
                    throw new SkaidbException("unexpected frame tag {$t} in stream");
                }
            }
            return ['kind' => 'rows', 'columns' => $columns, 'affected' => 0];
        } finally {
            if (!$released) {
                $this->finishStream($live);
            }
        }
    }

    /**
     * End a stream and hand the connection back at a request boundary.
     *
     * PHP runs a generator's finally when the generator is destroyed, so this
     * covers every abandon path, including one triggered by the garbage
     * collector. $live says whether the server still owes us frames.
     *
     * Draining mirrors the Rust driver's RowStream::drop: it blocks until the
     * server finishes, so abandoning a huge scan costs the rest of that scan —
     * close() the connection instead if that trade is wrong for the caller.
     * Anything that stops the drain leaves unread frames on the socket, and
     * then only $broken is truthful: ensureLive() re-dials before the next
     * statement and isUsable() keeps the connection out of the pool.
     */
    private function finishStream(bool $live): void
    {
        try {
            if (!$live) {
                return;
            }
            if ($this->closed) {
                return; // socket already gone; nothing can reuse it
            }
            if ($this->broken || !is_resource($this->sock)) {
                $this->broken = true;
                return;
            }
            while (true) {
                $t = (new Reader($this->readFrame()))->u8();
                if ($t === 7 || $t === 3) { // RowsEnd | Error: stream over
                    return;
                }
                if ($t !== 6) { // not a RowsChunk — we have lost the framing
                    $this->broken = true;
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->broken = true;
        } finally {
            $this->streaming = false;
        }
    }

    /**
     * Prepare $sql on the SERVER, returning [id, paramCount]. Cached per
     * connection, because a prepared id only means anything on the
     * connection that created it. Throws Unpreparable for statement kinds
     * the server declines (DDL, session statements).
     *
     * @internal
     * @return array{0:int,1:int}
     */
    public function prepareServer(string $sql): array
    {
        $this->assertNotStreaming();
        $this->ensureLive();
        if (isset($this->preparedCache[$sql])) {
            return $this->preparedCache[$sql];
        }
        if ($this->closed) {
            throw new SkaidbException('connection is closed');
        }
        $req = pack('C', 2) . pack('V', strlen($sql)) . $sql;
        $r = new Reader($this->exchange($req));
        $tag = $r->u8();
        if ($tag === 4) { // Prepared
            $id = $r->u32();
            $nparams = $r->u16();
            if (count($this->preparedCache) < 240) {
                $this->preparedCache[$sql] = [$id, $nparams];
            }
            return [$id, $nparams];
        }
        if ($tag === 3) {
            throw new Unpreparable($r->text());
        }
        throw new SkaidbException("unexpected prepare response tag {$tag}");
    }

    /**
     * Execute a prepared statement with TYPED parameters — the only way to
     * send an array or a document, neither of which has a SQL literal form.
     *
     * @internal
     * @param array<int,mixed> $params
     * @return array{kind:string, columns:array<int,string>, rows:array<int,array<int,mixed>>, affected:int}
     */
    public function execPrepared(int $id, array $params, int $consistency): array
    {
        $req = pack('C', 3) . pack('C', $consistency) . pack('V', $id) . self::encodeParams($params);
        return $this->roundtrip($req);
    }

    /**
     * Execute a prepared statement once per row in ONE round-trip. Rows
     * autocommit individually: a failure names the row and earlier rows
     * stay applied, so the statement must be idempotent.
     *
     * @internal
     * @param array<int,array<int,mixed>> $rows
     * @return array{kind:string, columns:array<int,string>, rows:array<int,array<int,mixed>>, affected:int}
     */
    public function execBatch(int $id, array $rows, int $consistency): array
    {
        $req = pack('C', 7) . pack('C', $consistency) . pack('V', $id)
            . pack('V', count($rows));
        foreach ($rows as $params) {
            $req .= self::encodeParams($params);
        }
        return $this->roundtrip($req);
    }

    /**
     * A parameter vector as OP_EXECUTE carries it — u16 LE count, then each
     * value (§4) behind its u32 LE length — and so OP_EXECUTE_BATCH per row
     * and OP_QUERY_STREAM_PARAMS.
     *
     * @param array<int,mixed> $params
     */
    private static function encodeParams(array $params): string
    {
        $n = count($params);
        if ($n > 0xFFFF) {
            throw new SkaidbException("too many parameters: {$n} (a request carries at most 65535)");
        }
        $out = pack('v', $n);
        foreach ($params as $p) {
            $v = self::encodeValue($p);
            $out .= pack('V', strlen($v)) . $v;
        }
        return $out;
    }

    /**
     * Encode a PHP value as a TYPED skaidb value (tag + payload), the
     * inverse of decodeValue. Lists become Array, associative arrays become
     * Document.
     *
     * @internal
     */
    public static function encodeValue($v): string
    {
        if ($v === null) {
            return pack('C', 0);
        }
        if (is_bool($v)) {
            return pack('C', 1) . pack('C', $v ? 1 : 0);
        }
        if (is_int($v)) {
            return pack('C', 2) . pack('P', $v);
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) {
                throw new SkaidbException('cannot bind NaN/Infinity');
            }
            return pack('C', 3) . pack('e', $v);
        }
        if (is_string($v)) {
            return pack('C', 5) . pack('V', strlen($v)) . $v;
        }
        if ($v instanceof \DateTimeInterface) {
            return pack('C', 8) . pack('P', self::toMillis($v));
        }
        if ($v instanceof Bytes) {
            return pack('C', 6) . pack('V', strlen($v->value)) . $v->value;
        }
        if ($v instanceof Uuid) {
            return pack('C', 7) . $v->bytes();
        }
        if ($v instanceof Decimal) {
            [$mantissa, $scale] = self::splitDecimal($v->value);
            return pack('C', 4) . self::i128FromString($mantissa) . pack('V', $scale);
        }
        if (is_array($v)) {
            // A list encodes as Array; anything else as Document.
            if (array_is_list($v)) {
                $out = pack('C', 9) . pack('V', count($v));
                foreach ($v as $item) {
                    $out .= self::encodeValue($item);
                }
                return $out;
            }
            $out = pack('C', 10) . pack('V', count($v));
            foreach ($v as $k => $item) {
                $ks = (string) $k;
                $out .= pack('V', strlen($ks)) . $ks . self::encodeValue($item);
            }
            return $out;
        }
        $t = is_object($v) ? get_class($v) : gettype($v);
        throw new SkaidbException("cannot bind value of type {$t}");
    }

    /**
     * @return array{kind:string, columns:array<int,string>, rows:array<int,array<int,mixed>>, affected:int}
     */
    private function roundtrip(string $req): array
    {
        $this->assertNotStreaming();
        if ($this->closed) {
            throw new SkaidbException('connection is closed');
        }
        $r = new Reader($this->exchange($req));
        $tag = $r->u8();
        if ($tag === 0) { // Rows
            $set = self::readRows($r);
            return ['kind' => 'rows', 'columns' => $set['columns'], 'rows' => $set['rows'], 'affected' => 0];
        }
        if ($tag === 8) { // ResultSets: a CALL whose body EMITted
            return self::readResultSets($r);
        }
        if ($tag === 1) { // Mutation
            $affected = $r->u64();
            return ['kind' => 'mutation', 'columns' => [], 'rows' => [], 'affected' => $affected];
        }
        if ($tag === 2) { // Ddl
            return ['kind' => 'ddl', 'columns' => [], 'rows' => [], 'affected' => 0];
        }
        if ($tag === 3) { // Error
            throw new SkaidbException($r->text());
        }
        throw new SkaidbException("unknown response tag {$tag}");
    }

    /**
     * A Rows body (PROTOCOL.md §3.2), also one set of ResultSets; $r sits
     * just past the tag.
     *
     * @return array{columns:array<int,string>, rows:array<int,array<int,mixed>>}
     */
    private static function readRows(Reader $r): array
    {
        $ncols = $r->u32();
        $columns = [];
        for ($i = 0; $i < $ncols; $i++) {
            $columns[] = $r->text();
        }
        $nrows = $r->u32();
        $rows = [];
        for ($i = 0; $i < $nrows; $i++) {
            $ncells = $r->u32();
            $row = [];
            for ($c = 0; $c < $ncells; $c++) {
                // Each cell is a length-prefixed blob holding one Value.
                $row[] = self::decodeValue(new Reader($r->blob()));
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * A ResultSets body (a CALL whose body EMITted), $r just past the tag.
     * The buffered path and a stream request both receive this frame, and
     * both decode it here: the last set (the call's final result) is the
     * result's own columns/rows; every set, in order, is under result_sets.
     *
     * @return array{kind:string, columns:array<int,string>, rows:array<int,array<int,mixed>>, affected:int,
     *               result_sets:array<int,array{columns:array<int,string>,rows:array<int,array<int,mixed>>}>}
     */
    private static function readResultSets(Reader $r): array
    {
        $sets = [];
        $nsets = $r->u32();
        for ($s = 0; $s < $nsets; $s++) {
            $sets[] = self::readRows($r);
        }
        $last = $sets === [] ? ['columns' => [], 'rows' => []] : $sets[count($sets) - 1];
        return ['kind' => 'rows', 'columns' => $last['columns'], 'rows' => $last['rows'],
                'affected' => 0, 'result_sets' => $sets];
    }

    /**
     * Every set of a ResultSets reply with its rows keyed by column name, as
     * Statement::resultSets() and a stream's getReturn() hand them out.
     *
     * @internal
     * @param array<int,array{columns:array<int,string>,rows:array<int,array<int,mixed>>}> $sets
     * @return array<int,array{columns:array<int,string>,rows:array<int,array<string,mixed>>}>
     */
    public static function assocSets(array $sets): array
    {
        $out = [];
        foreach ($sets as $set) {
            $rows = [];
            foreach ($set['rows'] as $row) {
                $assoc = [];
                foreach ($set['columns'] as $i => $name) {
                    $assoc[$name] = $row[$i] ?? null;
                }
                $rows[] = $assoc;
            }
            $out[] = ['columns' => $set['columns'], 'rows' => $rows];
        }
        return $out;
    }

    // ---- pool reporting ---------------------------------------------------

    /**
     * One request round trip: send $req, read the first response frame. A
     * pooled connection reports how long it took (the member's latency) or
     * that the transport failed (a member failure).
     */
    private function exchange(string $req): string
    {
        $started = hrtime(true);
        try {
            $this->writeFrame($req);
            $frame = $this->readFrame();
        } catch (SkaidbException $e) {
            $this->observeFailure();
            throw $e;
        }
        $this->pool?->roundTrip($this, (hrtime(true) - $started) / 1e9);
        return $frame;
    }

    /** writeFrame/readExact mark a transport failure broken; tell the pool. */
    private function observeFailure(): void
    {
        if ($this->broken && $this->pool !== null && $this->connected !== null) {
            $this->pool->failed($this->connected);
        }
    }

    /**
     * A statement succeeded: a pool with read-your-writes on remembers which
     * member took a write.
     *
     * @internal
     */
    public function noteStatement(string $sql): void
    {
        $this->pool?->statement($this, $sql);
    }

    // ---- framing ----------------------------------------------------------

    /**
     * Refuse to write a request while a stream is in flight.
     *
     * A flag rather than a lock held for the stream's duration: stream() is a
     * generator, so its "critical section" spans arbitrary caller code between
     * yields — there is nothing to hold a lock across, and holding one would
     * deadlock the very common `foreach (stream()) { ... }` body that touches
     * the same connection. Failing loudly is the honest alternative to
     * interleaving two conversations on one socket and corrupting both.
     */
    private function assertNotStreaming(): void
    {
        if ($this->streaming) {
            throw new SkaidbException(
                'connection is busy streaming a result set: finish or abandon the '
                . 'stream before running another statement on this connection '
                . '(use a second connection to run one in parallel)'
            );
        }
    }

    /**
     * Re-dial if the transport died since the last statement, BEFORE anything
     * is prepared on it.
     *
     * The prepared-statement cache MUST be cleared: an id is only valid on the
     * connection that created it, so carrying one across a reconnect would run
     * a different statement (or fail obscurely).
     */
    private function ensureLive(): void
    {
        if ($this->sock === null && !$this->broken) {
            throw new SkaidbException('connection is closed');
        }
        if (!$this->broken) {
            return;
        }
        $this->preparedCache = [];
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
        // Cleared BEFORE dialling: dial() issues USE, which runs a statement
        // and would otherwise re-enter this method forever.
        $this->broken = false;
        if ($this->pool !== null) {
            // Pooled: the pool's current order (usable members first, the
            // one this connection just lost last).
            $this->order = $this->pool->dialOrder($this->connected);
        }
        try {
            $this->dial();
        } catch (SkaidbException $e) {
            $this->broken = true;   // still down; the next statement retries
            throw $e;
        }
    }

    private function writeFrame(string $payload): void
    {
        // u32 BE length prefix, then payload.
        $frame = pack('N', strlen($payload)) . $payload;
        $total = strlen($frame);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->sock, substr($frame, $written));
            if ($n === false || $n === 0) {
                $this->broken = true;
                throw new SkaidbException('connection closed by server (write)');
            }
            $written += $n;
        }
    }

    private function readFrame(): string
    {
        $head = $this->readExact(4);
        $len = unpack('N', $head)[1]; // u32 BE
        if ($len === 0) {
            return '';
        }
        return $this->readExact($len);
    }

    /** Read exactly $n bytes; fread may return short, so loop. */
    private function readExact(int $n): string
    {
        if (!is_resource($this->sock)) {
            // Defence in depth, not a live path: finishStream() already
            // returns on `closed` and on a non-resource socket before it
            // reads anything, so an abandoned stream never arrives here
            // with a dead socket. Kept because `fread(null, …)` is a
            // TypeError rather than a SkaidbException, and one reachable
            // caller past this guard would turn a closed connection into
            // an error nobody catches.
            throw new SkaidbException('connection is closed');
        }
        $buf = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($this->sock, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : ['timed_out' => false];
                if (!empty($meta['timed_out'])) {
                    $this->broken = true;
                    throw new SkaidbException('connection timed out');
                }
                $this->broken = true;
                throw new SkaidbException('connection closed by server');
            }
            $buf .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $buf;
    }

    // ---- handshake (§2) ---------------------------------------------------

    private function handshake(string $user, string $password): void
    {
        self::$nonceCounter++;
        $clientNonce = 'php' . getmypid() . '.' . self::$nonceCounter . '.' . spl_object_id($this);

        // AuthStart: tag 10, str username, str client_nonce.
        $start = pack('C', 10) . self::encStr($user) . self::encStr($clientNonce);
        $this->writeFrame($start);

        // AuthChallenge: tag 11, blob salt, u32 LE iterations, str server_nonce.
        $r = new Reader($this->readFrame());
        if ($r->u8() !== 11) {
            throw new SkaidbException('bad handshake challenge');
        }
        $salt = $r->blob();
        $iterations = $r->u32();
        $serverNonce = $r->text();

        $authMessage = self::authMessage($user, $clientNonce, $serverNonce, $salt, $iterations);

        [$proof, $expectedServerSig] = self::scram($password, $salt, $iterations, $authMessage);

        // AuthFinish: tag 12, 32 raw proof bytes (NOT length-prefixed).
        $this->writeFrame(pack('C', 12) . $proof);

        // AuthOutcome: tag 13, u8 ok_flag, then 32-byte sig or str reason.
        $r = new Reader($this->readFrame());
        if ($r->u8() !== 13) {
            throw new SkaidbException('bad handshake outcome');
        }
        if ($r->u8() === 1) {
            $serverSig = $r->take(32);
            if ($password !== '' && !hash_equals($expectedServerSig, $serverSig)) {
                throw new SkaidbException('server signature mismatch (mutual auth failed)');
            }
        } else {
            throw new SkaidbException('authentication denied: ' . $r->text());
        }
    }

    /**
     * EXTERNAL (§2.4): the TLS client certificate is the credential; its
     * Common Name is the username, and $user is empty or must equal it. No
     * exchange follows AuthStart. The outcome's signature is 32 zero bytes
     * and is deliberately not checked: TLS already authenticated the server.
     */
    private function handshakeCertificate(string $user): void
    {
        $this->writeFrame(self::authStartExternal($user));
        $r = new Reader($this->readFrame());
        if ($r->u8() !== 13) {
            throw new SkaidbException('bad handshake outcome');
        }
        if ($r->u8() !== 1) {
            throw new SkaidbException('authentication denied: ' . $r->text());
        }
    }

    /**
     * AuthStart for EXTERNAL: tag 10, str username, an empty client nonce,
     * mechanism byte 2.
     *
     * @internal
     */
    public static function authStartExternal(string $user): string
    {
        return pack('C', 10) . self::encStr($user) . self::encStr('') . pack('C', 2);
    }

    /**
     * The SCRAM auth message (§2.1): the NUL-joined username, client nonce,
     * server nonce, lowercase-hex salt and decimal iteration count.
     *
     * @internal
     */
    public static function authMessage(
        string $user,
        string $clientNonce,
        string $serverNonce,
        string $salt,
        int $iterations
    ): string {
        return implode("\0", [$user, $clientNonce, $serverNonce, bin2hex($salt), (string) $iterations]);
    }

    /**
     * PBKDF2-HMAC-SHA-256 of the password, 32 raw bytes.
     *
     * @internal
     */
    public static function saltedPassword(string $password, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    }

    /**
     * Compute the SCRAM-SHA-256 client proof and expected server signature.
     * All crypto outputs are RAW bytes.
     *
     * @internal
     * @return array{0:string,1:string} [proof, expectedServerSig]
     */
    public static function scram(string $password, string $salt, int $iterations, string $authMessage): array
    {
        $salted = self::saltedPassword($password, $salt, $iterations);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);            // HMAC(key=salted, msg)
        $storedKey = hash('sha256', $clientKey, true);
        $clientSig = hash_hmac('sha256', $authMessage, $storedKey, true);
        $proof = $clientKey ^ $clientSig; // PHP XORs byte strings of equal length

        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
        $serverSig = hash_hmac('sha256', $authMessage, $serverKey, true);

        return [$proof, $serverSig];
    }

    /** Encode a str field: u32 LE length + UTF-8 bytes. */
    private static function encStr(string $s): string
    {
        return pack('V', strlen($s)) . $s;
    }

    // ---- value decoding (§4) ----------------------------------------------

    /**
     * Decode one typed value (tag + payload) at the reader's position.
     *
     * @internal
     * @return mixed
     */
    public static function decodeValue(Reader $r)
    {
        $tag = $r->u8();
        switch ($tag) {
            case self::TAG_NULL:
                return null;
            case self::TAG_BOOL:
                return $r->u8() !== 0;
            case self::TAG_INT:
                return $r->i64();
            case self::TAG_FLOAT:
                return $r->f64();
            case self::TAG_DECIMAL:
                $mantissa = self::i128ToString($r->take(16));
                $scale = $r->u32();
                return self::scaleDecimal($mantissa, $scale);
            case self::TAG_STRING:
                return $r->text();
            case self::TAG_BYTES:
                return $r->blob();
            case self::TAG_UUID:
                return self::uuidToString($r->take(16));
            case self::TAG_TIMESTAMP:
                $ms = $r->i64();
                return self::msToDateTime($ms);
            case self::TAG_ARRAY:
                $count = $r->u32();
                $out = [];
                for ($i = 0; $i < $count; $i++) {
                    $out[] = self::decodeValue($r);
                }
                return $out;
            case self::TAG_DOCUMENT:
                $count = $r->u32();
                $out = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = $r->text();
                    $out[$key] = self::decodeValue($r);
                }
                return $out;
        }
        throw new SkaidbException("unknown value tag {$tag}");
    }

    /**
     * Unix milliseconds for a DateTimeInterface, exactly: seconds * 1000 plus
     * the microseconds truncated to milliseconds (no float in between).
     */
    private static function toMillis(\DateTimeInterface $dt): int
    {
        return (int) $dt->format('U') * 1000 + intdiv((int) $dt->format('u'), 1000);
    }

    /**
     * Convert a 16-byte little-endian two's-complement integer to a decimal
     * string, exactly, with no bigint extension: schoolbook base-256 → base-10
     * on a digit string.
     */
    private static function i128ToString(string $bytes): string
    {
        $negative = (ord($bytes[15]) & 0x80) !== 0;
        if ($negative) {
            $bytes = self::negate128($bytes);
        }
        $dec = '0';
        for ($i = 15; $i >= 0; $i--) {
            $dec = self::decMulAdd($dec, 256, ord($bytes[$i]));
        }
        return ($negative && $dec !== '0' ? '-' : '') . $dec;
    }

    /**
     * Encode a decimal integer string as a 16-byte little-endian two's
     * complement i128. Throws when the magnitude does not fit.
     */
    private static function i128FromString(string $dec): string
    {
        $negative = $dec !== '' && $dec[0] === '-';
        $dec = ltrim(ltrim($dec, '+-'), '0');
        if ($dec === '') {
            return str_repeat("\0", 16);
        }
        $bytes = '';
        while ($dec !== '0') {
            [$dec, $rem] = self::decDivMod($dec, 256);
            $bytes .= chr($rem);
            if (strlen($bytes) > 16) {
                throw new SkaidbException('decimal mantissa does not fit 128 bits');
            }
        }
        $bytes = str_pad($bytes, 16, "\0");
        $top = ord($bytes[15]);
        if ($negative) {
            // Magnitudes up to 2^127 inclusive are representable as negatives.
            if ($top > 0x80 || ($top === 0x80 && rtrim(substr($bytes, 0, 15), "\0") !== '')) {
                throw new SkaidbException('decimal mantissa does not fit 128 bits');
            }
            return self::negate128($bytes);
        }
        if ($top >= 0x80) {
            throw new SkaidbException('decimal mantissa does not fit 128 bits');
        }
        return $bytes;
    }

    /** Two's-complement negation of a 16-byte LE integer. */
    private static function negate128(string $bytes): string
    {
        $bytes = ~$bytes;
        for ($i = 0; $i < 16; $i++) {
            $b = ord($bytes[$i]) + 1;
            $bytes[$i] = chr($b & 0xff);
            if ($b <= 0xff) {
                break;
            }
        }
        return $bytes;
    }

    /**
     * ('123.45') → ['12345', 2]; ('-7') → ['-7', 0]. The text was validated
     * by Decimal's constructor.
     *
     * @return array{0:string,1:int}
     */
    private static function splitDecimal(string $text): array
    {
        $neg = $text[0] === '-';
        $text = ltrim($text, '+-');
        $dot = strpos($text, '.');
        $scale = $dot === false ? 0 : strlen($text) - $dot - 1;
        $digits = ltrim(str_replace('.', '', $text), '0');
        if ($digits === '') {
            $digits = '0';
            $neg = false;
        }
        return [($neg ? '-' : '') . $digits, $scale];
    }

    /** Digit-string arithmetic: $dec * $mul + $add. */
    private static function decMulAdd(string $dec, int $mul, int $add): string
    {
        $out = '';
        $carry = $add;
        for ($i = strlen($dec) - 1; $i >= 0; $i--) {
            $v = (int) $dec[$i] * $mul + $carry;
            $out .= chr(48 + $v % 10);
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $out .= chr(48 + $carry % 10);
            $carry = intdiv($carry, 10);
        }
        $out = ltrim(strrev($out), '0');
        return $out === '' ? '0' : $out;
    }

    /**
     * Digit-string arithmetic: [$dec / $div, $dec % $div].
     *
     * @return array{0:string,1:int}
     */
    private static function decDivMod(string $dec, int $div): array
    {
        $out = '';
        $rem = 0;
        $n = strlen($dec);
        for ($i = 0; $i < $n; $i++) {
            $rem = $rem * 10 + (int) $dec[$i];
            $out .= chr(48 + intdiv($rem, $div));
            $rem %= $div;
        }
        $out = ltrim($out, '0');
        return [$out === '' ? '0' : $out, $rem];
    }

    /**
     * Apply a base-10 scale to an integer mantissa string, returning an exact
     * decimal string. value = mantissa / 10^scale.
     */
    private static function scaleDecimal(string $mantissa, int $scale): string
    {
        if ($scale === 0) {
            return $mantissa;
        }
        $neg = false;
        if ($mantissa !== '' && $mantissa[0] === '-') {
            $neg = true;
            $mantissa = substr($mantissa, 1);
        }
        $mantissa = ltrim($mantissa, '0');
        if ($mantissa === '') {
            $mantissa = '0';
        }
        if (strlen($mantissa) <= $scale) {
            $mantissa = str_repeat('0', $scale - strlen($mantissa) + 1) . $mantissa;
        }
        $point = strlen($mantissa) - $scale;
        $result = substr($mantissa, 0, $point) . '.' . substr($mantissa, $point);
        return ($neg ? '-' : '') . $result;
    }

    /** Format 16 raw UUID bytes as canonical lowercase 8-4-4-4-12. */
    private static function uuidToString(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** Unix milliseconds → DateTimeImmutable in UTC. */
    private static function msToDateTime(int $ms): DateTimeImmutable
    {
        $sec = intdiv($ms, 1000);
        $remMs = $ms - $sec * 1000; // keep sign consistent with $sec for negatives
        if ($remMs < 0) {
            $sec -= 1;
            $remMs += 1000;
        }
        $micros = $remMs * 1000;
        $str = sprintf('%d.%06d', $sec, $micros);
        $dt = DateTimeImmutable::createFromFormat('U.u', $str, new DateTimeZone('UTC'));
        if ($dt === false) {
            // Fallback for environments rejecting negative U: build from epoch.
            $dt = (new DateTimeImmutable('@0'))->modify("{$sec} seconds");
        }
        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    // ---- client-side parameter binding (§5) -------------------------------

    /**
     * Interpolate '?' placeholders in $sql with quoted $params.
     *
     * @internal
     *
     * @param array<int,mixed> $params
     */
    public static function bindParams(string $sql, array $params): string
    {
        // Re-index in case of associative input.
        $params = array_values($params);

        if (count($params) === 0) {
            if (strpos(self::stripStrings($sql), '?') !== false) {
                throw new SkaidbException('query has placeholders but no parameters given');
            }
            return $sql;
        }

        $out = '';
        $inStr = false;
        $i = 0;
        $n = strlen($sql);
        $used = 0;
        $total = count($params);
        while ($i < $n) {
            $ch = $sql[$i];
            if ($inStr) {
                $out .= $ch;
                if ($ch === "'") {
                    if ($i + 1 < $n && $sql[$i + 1] === "'") {
                        $out .= "'";
                        $i += 2;
                        continue;
                    }
                    $inStr = false;
                }
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inStr = true;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === '?') {
                if ($used >= $total) {
                    throw new SkaidbException('more placeholders than parameters');
                }
                $out .= self::quote($params[$used]);
                $used++;
                $i++;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        if ($used < $total) {
            throw new SkaidbException('more parameters than placeholders');
        }
        return $out;
    }

    /** SQL-quote a single bound value. */
    private static function quote($arg): string
    {
        if ($arg === null) {
            return 'NULL';
        }
        if (is_bool($arg)) {
            return $arg ? 'TRUE' : 'FALSE';
        }
        if (is_int($arg)) {
            return (string) $arg;
        }
        if (is_float($arg)) {
            if (is_nan($arg) || is_infinite($arg)) {
                throw new SkaidbException('cannot bind NaN/Infinity');
            }
            // Round-trip-safe float formatting. PHP's (string) cast honours the
            // `precision` ini (default 14) and can lose digits; json_encode uses
            // serialize_precision=-1 (shortest round-trippable form) by default.
            $s = json_encode($arg);
            if ($s === false) {
                throw new SkaidbException('cannot bind float value');
            }
            // json_encode(2.0) is "2": keep an integral float a Float literal.
            if (strpbrk($s, '.eE') === false) {
                $s .= '.0';
            }
            return $s;
        }
        if (is_string($arg)) {
            return "'" . str_replace("'", "''", $arg) . "'";
        }
        if ($arg instanceof \DateTimeInterface) {
            // Bind as unix milliseconds.
            return (string) self::toMillis($arg);
        }
        if ($arg instanceof Decimal) {
            return $arg->value; // a numeric literal; the server reads it as a number
        }
        if ($arg instanceof Bytes) {
            return "'" . bin2hex($arg->value) . "'"; // hex text: SQL has no bytes literal
        }
        if (is_object($arg) && method_exists($arg, '__toString')) {
            return "'" . str_replace("'", "''", (string) $arg) . "'";
        }
        $type = is_object($arg) ? get_class($arg) : gettype($arg);
        throw new SkaidbException("cannot bind value of type {$type}");
    }

    /** Return $sql with single-quoted literals blanked, for placeholder counting. */
    private static function stripStrings(string $sql): string
    {
        $out = '';
        $inStr = false;
        $i = 0;
        $n = strlen($sql);
        while ($i < $n) {
            $ch = $sql[$i];
            if ($inStr) {
                if ($ch === "'") {
                    if ($i + 1 < $n && $sql[$i + 1] === "'") {
                        $i += 2;
                        continue;
                    }
                    $inStr = false;
                }
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inStr = true;
                $i++;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }
}

/**
 * A prepared/executed statement, modelled on PDOStatement. Build it via
 * Connection::prepare(); call execute([...]) then fetch results.
 */
class Statement
{
    private Connection $conn;

    private string $sql;

    private int $consistency;

    /** @var array<int,string> */
    private array $columns = [];

    /** @var array<int,array<int,mixed>> */
    private array $rows = [];

    private int $affected = 0;

    private int $pos = 0;

    private bool $isRows = false;

    private string $kind = '';

    /** @var array<int,array{columns:array<int,string>,rows:array<int,array<int,mixed>>}>|null */
    private ?array $resultSets = null;

    public function __construct(Connection $conn, string $sql)
    {
        $this->conn = $conn;
        $this->sql = $sql;
        // Inherit the connection's default consistency; override per-statement.
        $this->consistency = $conn->getConsistency();
    }

    /** Override consistency for this statement ('ONE'/'QUORUM'/'ALL' or 0/1/2). */
    public function setConsistency($consistency): self
    {
        $this->consistency = Connection::resolveConsistency($consistency);
        return $this;
    }

    /**
     * Execute this statement once per row in ONE round-trip. Rows autocommit
     * individually: a failure names the row and earlier rows stay applied,
     * so the statement must be idempotent. Returns total affected rows.
     *
     * @param array<int,array<int,mixed>> $rows
     */
    public function executeBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        [$id, $n] = $this->conn->prepareServer($this->sql);
        foreach ($rows as $r) {
            if (count($r) !== $n) {
                throw new SkaidbException(
                    "batch row expects {$n} parameters, got " . count($r)
                );
            }
        }
        $res = $this->conn->execBatch(
            $id,
            array_map('array_values', $rows),
            $this->consistency
        );
        $this->conn->noteStatement($this->sql);
        return $res['affected'];
    }

    /**
     * Bind $params into the SQL and run it. Returns true on success (mirrors
     * PDOStatement::execute, which returns bool). Throws on error.
     *
     * With parameters the statement is prepared on the server (cached per
     * connection) and the values travel typed; if the server declines to
     * prepare it (DDL, session statements) the values are quoted client-side.
     *
     * @param array<int,mixed> $params positional values for '?' placeholders
     */
    public function execute(array $params = []): bool
    {
        $res = null;
        if ($params !== []) {
            // Server-side prepare so parameters travel as TYPED values;
            // arrays and documents have no SQL literal form.
            try {
                [$id, $n] = $this->conn->prepareServer($this->sql);
                if ($n !== count($params)) {
                    throw new SkaidbException(
                        "statement expects {$n} parameters, got " . count($params)
                    );
                }
                $res = $this->conn->execPrepared($id, array_values($params), $this->consistency);
            } catch (Unpreparable $e) {
                $unpreparable = $e; // fall through to text binding
            }
        }
        if ($res === null) {
            try {
                $bound = Connection::bindParams($this->sql, $params);
            } catch (SkaidbException $e) {
                if (isset($unpreparable)) {
                    // The text path cannot carry this value; the real reason
                    // is whatever made the server decline to prepare it
                    // (often a syntax error), so say so.
                    throw new SkaidbException(
                        $e->getMessage() . ' via client-side binding; the server declined to '
                        . 'prepare the statement: ' . $unpreparable->getMessage()
                    );
                }
                throw $e;
            }
            $res = $this->conn->runQuery($bound, $this->consistency);
        }
        $this->conn->noteStatement($this->sql);
        $this->pos = 0;
        $this->resultSets = $res['result_sets'] ?? null;
        $this->kind = $res['kind'];
        if ($res['kind'] === 'rows') {
            $this->isRows = true;
            $this->columns = $res['columns'];
            $this->rows = $res['rows'];
            $this->affected = 0;
        } else {
            $this->isRows = false;
            $this->columns = [];
            $this->rows = [];
            $this->affected = $res['affected'];
        }
        return true;
    }

    /**
     * Fetch the next row as an associative array (column name => value), or
     * null when exhausted.
     *
     * @return array<string,mixed>|null
     */
    public function fetch(): ?array
    {
        if ($this->pos >= count($this->rows)) {
            return null;
        }
        $row = $this->rows[$this->pos];
        $this->pos++;
        return $this->assoc($row);
    }

    /**
     * Fetch all remaining rows as associative arrays.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(): array
    {
        $out = [];
        $count = count($this->rows);
        for (; $this->pos < $count; $this->pos++) {
            $out[] = $this->assoc($this->rows[$this->pos]);
        }
        return $out;
    }

    /**
     * Fetch a single column from the next row (default the first column),
     * or false when exhausted (mirrors PDOStatement::fetchColumn).
     *
     * @return mixed
     */
    public function fetchColumn(int $column = 0)
    {
        if ($this->pos >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->pos];
        $this->pos++;
        return $row[$column] ?? null;
    }

    /**
     * For mutations: the number of affected rows. For SELECTs: the number of
     * rows in the result set (note: PDO leaves SELECT rowCount() driver-defined;
     * we return the row count for convenience).
     */
    public function rowCount(): int
    {
        return $this->isRows ? count($this->rows) : $this->affected;
    }

    /**
     * What the last execute() returned: 'rows' (a result set, possibly with
     * no rows), 'mutation' (an affected-row count, see rowCount()) or 'ddl'
     * (a bare acknowledgement). '' before the first execute().
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /** Number of columns in the result set. */
    public function columnCount(): int
    {
        return count($this->columns);
    }

    /** @return array<int,string> the column names */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Every result set of a CALL whose body EMITted, in emission order, each
     * as ['columns' => [...], 'rows' => [assoc rows]]. The last one is also
     * what fetch()/fetchAll() return. Null for every other statement.
     *
     * @return array<int,array{columns:array<int,string>,rows:array<int,array<string,mixed>>}>|null
     */
    public function resultSets(): ?array
    {
        if ($this->resultSets === null) {
            return null;
        }
        return Connection::assocSets($this->resultSets);
    }

    /**
     * Zip a positional row with column names into an associative array.
     *
     * @param array<int,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function assoc(array $row): array
    {
        $out = [];
        foreach ($this->columns as $i => $name) {
            $out[$name] = $row[$i] ?? null;
        }
        return $out;
    }
}

/**
 * A cursor over a byte string, decoding little-endian protocol fields.
 *
 * @internal
 */
class Reader
{
    private string $buf;

    private int $pos = 0;

    private int $len;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    public function take(int $n): string
    {
        $end = $this->pos + $n;
        if ($end > $this->len) {
            throw new SkaidbException('truncated server message');
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos = $end;
        return $s;
    }

    public function u8(): int
    {
        return ord($this->take(1));
    }

    public function u16(): int
    {
        return unpack('v', $this->take(2))[1]; // u16 LE
    }

    public function u32(): int
    {
        return unpack('V', $this->take(4))[1]; // u32 LE
    }

    /** Read an i64 LE, returning a PHP int (64-bit on 64-bit builds). */
    public function i64(): int
    {
        $bytes = $this->take(8);
        // 'P' = u64 LE. On 64-bit PHP this yields a signed int already when the
        // top bit is set (PHP ints are signed 64-bit), giving correct two's
        // complement values. unpack('q') would depend on machine endianness, so
        // we use 'P' explicitly to guarantee little-endian interpretation.
        return unpack('P', $bytes)[1];
    }

    /** Read a u64 LE. Returned as PHP int; values > PHP_INT_MAX wrap negative. */
    public function u64(): int
    {
        return unpack('P', $this->take(8))[1];
    }

    /** Read an f64 LE (IEEE-754). */
    public function f64(): float
    {
        // 'e' = double, little-endian (PHP 7.0.15+/7.1+). Guaranteed LE.
        return unpack('e', $this->take(8))[1];
    }

    /** Read a length-prefixed blob (u32 LE len + bytes). */
    public function blob(): string
    {
        return $this->take($this->u32());
    }

    /** Read a length-prefixed UTF-8 string. */
    public function text(): string
    {
        return $this->blob();
    }
}
/**
 * One cluster member as a Pool sees it.
 *
 * @internal
 */
final class PoolMember
{
    /** Its own name: the `local` row of its SHOW ENDPOINTS answer. */
    public ?string $canonical = null;

    /** 'ready' | 'resyncing' | 'unreachable' | 'unlisted' */
    public string $listed = 'ready';

    /** Round-trip average in seconds; null until measured. */
    public ?float $ewma = null;

    public int $samples = 0;

    /** When the last round trip was measured (Pool's monotonic seconds). */
    public float $lastSample = 0.0;

    /** Consecutive transport failures. */
    public int $errors = 0;

    /** Ejected until this time; null = admitted. */
    public ?float $ejectedUntil = null;

    public int $ejections = 0;

    /** When a probe last re-admitted it; null = never ejected and re-admitted. */
    public ?float $readmittedAt = null;

    /** Connections checked out to it. */
    public int $inUse = 0;

    /**
     * @param string $endpoint what the pool dials
     * @param bool   $seed     configured, so discovery never drops it
     */
    public function __construct(public readonly string $endpoint, public readonly bool $seed)
    {
    }
}

/**
 * A pool of skaidb connections, balanced across the cluster's members.
 *
 * `maxsize` bounds the connections kept IDLE, not the number checked out: a
 * burst creates extras and the surplus is closed on return. Every Connection
 * constructor argument passes through, so pooled connections inherit TLS,
 * credentials and the session database; `host`/`port`/`seeds` name the
 * members the pool starts from.
 *
 * Load balancing follows the rules every skaidb driver shares (PROTOCOL.md
 * §6.1; see docs/pooling.md):
 *
 * - `loadBalancing`: 'random' (default; each new connection dials the
 *   members in random order), 'round_robin' (checkouts rotate over the
 *   members) or 'least_latency' (of two members picked at random, the one
 *   with the lower latency average × checked-out connections).
 * - `idleOrder`: 'lifo' (default; the most recently returned idle connection
 *   first) or 'fifo' (the longest idle first).
 * - `discover`: read the members from SHOW ENDPOINTS (server >= 0.327) when
 *   a member is first reached and every `refreshInterval` seconds after (5 s
 *   while one is resyncing or unreachable): joiners are added, leavers
 *   dropped, and members that are not ready get no new connections.
 * - Health ejection: `ejectErrors` consecutive transport failures, or a
 *   latency average above `ejectLatencyFactor` × the other members' median
 *   and more than `ejectLatencyFloor` seconds above it, takes a member out
 *   for `ejectBackoff` seconds (doubling per repeat, up to `ejectBackoffMax`).
 *   The last usable member is never ejected.
 * - `readYourWrites`: seconds after a write during which checkouts with the
 *   same affinity key go to the member that took it. 0 (default) = off.
 *
 * PHP has no background threads, so the upkeep runs inside acquire(): the
 * first checkout after a member's backoff probes it (one member per
 * checkout, bounded by the connection timeout), and the first checkout after
 * the refresh interval re-reads SHOW ENDPOINTS on the connection it is about
 * to hand out.
 *
 * PHP is share-nothing per request, so this pool lives for one process — it
 * pays off in a worker or a long-running CLI job, not in a plain web request
 * that opens one connection and exits.
 *
 *     $pool = new Skaidb\Pool(['seeds' => ['db1:7000', 'db2:7000'], 'user' => 'app', 'password' => $pw], 8,
 *                             loadBalancing: 'least_latency', discover: true);
 *     $n = $pool->withConnection(fn ($c) => $c->exec('SELECT 1'));
 *     $pool->close();
 */
class Pool
{
    public const RANDOM = 'random';
    public const ROUND_ROBIN = 'round_robin';
    public const LEAST_LATENCY = 'least_latency';
    public const LIFO = 'lifo';
    public const FIFO = 'fifo';

    /**
     * Latency EWMA: each round trip moves a member's average 5% toward it,
     * and no sample counts for more than 10x the larger of the current
     * average and the ejection floor — one slow query does not eject a
     * healthy member, a member that stays slow is ejected within a few dozen
     * statements.
     */
    private const EWMA_WEIGHT = 0.05;

    private const EWMA_CLAMP = 10.0;

    /** Round trips a member (and at least one other) needs before its average can eject it. */
    private const EWMA_MIN_SAMPLES = 10;

    /**
     * least_latency: a member whose last round trip is older than this many
     * seconds scores 0, so a lightly loaded pool keeps re-measuring every
     * member instead of settling forever on whichever answered fastest once.
     */
    private const STALE_SAMPLE = 2.0;

    /**
     * Discovery refresh, in seconds, while a listed member is resyncing or
     * unreachable: often a matter of seconds (a peer's circuit breaker right
     * after it restarts), and a whole refresh interval would leave the load
     * on the others.
     */
    private const REFRESH_NOT_READY = 5.0;

    /** Leading keywords of statements that never write; everything else is a write. */
    private const READ_KEYWORDS = ['select', 'show', 'explain', 'describe', 'desc', 'values', 'with', 'use'];

    /** @var array<int, Connection> oldest first */
    private array $idle = [];

    private bool $closed = false;

    private int $maxsize;

    /** @var array<string, mixed> named Connection constructor arguments */
    private array $args;

    private string $policy;

    private bool $fifo;

    private bool $discover;

    private float $refreshInterval;

    private float $readYourWrites;

    private int $ejectErrors;

    private float $ejectFactor;

    private float $ejectFloor;

    private float $backoff;

    private float $backoffMax;

    /** @var array<string, PoolMember> by the endpoint the pool dials */
    private array $members = [];

    /**
     * Per connection the pool made: ep (the member it counts against), out
     * (checked out) and key (the affinity key it was checked out under).
     *
     * @var \WeakMap<Connection, \stdClass>
     */
    private \WeakMap $slots;

    private int $rr = -1;

    /** @var array<string, array{0:string,1:float}> affinity key => [member endpoint, until] */
    private array $affinity = [];

    private bool $discoveryOff = false;

    private float $nextRefresh = 0.0;

    /**
     * @param array<int|string, mixed> $connectionArgs     Connection constructor arguments, positional or named
     * @param int                      $maxsize            connections kept idle
     * @param string                   $loadBalancing      'random' | 'round_robin' | 'least_latency'
     * @param string                   $idleOrder          'lifo' | 'fifo'
     * @param bool                     $discover           read the members from SHOW ENDPOINTS
     * @param float                    $refreshInterval    seconds between discovery refreshes
     * @param float                    $readYourWrites     seconds a write pins its affinity key to its member; 0 = off
     * @param int                      $ejectErrors        consecutive transport failures that eject a member
     * @param float                    $ejectLatencyFactor × the other members' median latency that ejects; 0 = off
     * @param float                    $ejectLatencyFloor  seconds above the median a member must also be
     * @param float                    $ejectBackoff       seconds of the first ejection, doubling per repeat
     * @param float                    $ejectBackoffMax    the longest ejection, in seconds
     */
    public function __construct(
        array $connectionArgs = [],
        int $maxsize = 10,
        string $loadBalancing = self::RANDOM,
        string $idleOrder = self::LIFO,
        bool $discover = false,
        float $refreshInterval = 60.0,
        float $readYourWrites = 0.0,
        int $ejectErrors = 3,
        float $ejectLatencyFactor = 3.0,
        float $ejectLatencyFloor = 0.05,
        float $ejectBackoff = 5.0,
        float $ejectBackoffMax = 60.0
    ) {
        if ($maxsize < 1) {
            throw new SkaidbException('maxsize must be >= 1');
        }
        if (!in_array($loadBalancing, [self::RANDOM, self::ROUND_ROBIN, self::LEAST_LATENCY], true)) {
            throw new SkaidbException(
                "unknown loadBalancing '{$loadBalancing}' (use 'random', 'round_robin' or 'least_latency')"
            );
        }
        if (!in_array($idleOrder, [self::LIFO, self::FIFO], true)) {
            throw new SkaidbException("unknown idleOrder '{$idleOrder}' (use 'lifo' or 'fifo')");
        }
        if ($ejectErrors < 1) {
            throw new SkaidbException('ejectErrors must be >= 1');
        }
        $this->maxsize = $maxsize;
        $this->policy = $loadBalancing;
        $this->fifo = $idleOrder === self::FIFO;
        $this->discover = $discover;
        $this->refreshInterval = $refreshInterval;
        $this->readYourWrites = $readYourWrites;
        $this->ejectErrors = $ejectErrors;
        $this->ejectFactor = $ejectLatencyFactor;
        $this->ejectFloor = $ejectLatencyFloor;
        $this->backoff = $ejectBackoff;
        $this->backoffMax = $ejectBackoffMax;
        $this->args = self::named($connectionArgs);
        $this->slots = new \WeakMap();
        // The seeds, exactly as Connection would dial them.
        $seeds = $this->args['seeds'] ?? [];
        if (!is_array($seeds) || $seeds === []) {
            $seeds = [($this->args['host'] ?? 'localhost') . ':' . ($this->args['port'] ?? 7000)];
        }
        foreach ($seeds as $ep) {
            $ep = (string) $ep;
            $this->members[$ep] ??= new PoolMember($ep, true);
        }
    }

    // ---- checkout / checkin -----------------------------------------------

    /**
     * Check out a usable connection: an idle one to the member the policy
     * picks, or a new one dialed to it (falling back to the other members).
     * $affinity scopes read-your-writes — a user or session id; null is the
     * pool-wide key.
     */
    public function acquire(string|int|null $affinity = null): Connection
    {
        $key = $affinity === null ? '' : 'k:' . $affinity;
        while (true) {
            if ($this->closed) {
                throw new SkaidbException('pool is closed');
            }
            $this->probeDue();
            $target = $this->pick($key);
            $conn = $this->takeIdle($target);
            if ($conn === null) {
                $conn = $this->dial($this->order($target));
                $this->discoverThrough($conn);
            }
            if ($conn->isUsable() && $this->refreshDue()) {
                $this->nextRefresh = self::now() + $this->refreshInterval;
                $this->listThrough($conn);
            }
            // A connection the server closed while it sat idle still looks
            // fine locally (its next statement throws once and re-dials);
            // this drops the ones already known dead, then loops.
            if (!$conn->isUsable()) {
                $conn->close();
                continue;
            }
            $slot = $this->slots[$conn];
            $slot->out = true;
            $slot->key = $key;
            $m = $this->members[$slot->ep ?? ''] ?? null;
            if ($m !== null) {
                $m->inUse++;
            }
            return $conn;
        }
    }

    /**
     * Return a connection, closing it instead if it is broken or mid-stream,
     * its member is ejected, not ready or gone, or the pool is full or closed.
     */
    public function release(Connection $conn): void
    {
        $slot = $this->slots[$conn] ?? null;
        $m = $slot === null ? null : ($this->members[$slot->ep ?? ''] ?? null);
        if ($slot !== null && $slot->out) {
            $slot->out = false;
            if ($m !== null && $m->inUse > 0) {
                $m->inUse--;
            }
        }
        if (!$this->closed && $conn->isUsable() && $m !== null && self::usable($m)
            && count($this->idle) < $this->maxsize) {
            $this->idle[] = $conn;

            return;
        }
        $conn->close();
    }

    /**
     * Run $fn with a checked-out connection, returning it however $fn ends.
     *
     * @param callable(Connection):mixed $fn
     * @param string|int|null            $affinity read-your-writes key, as for acquire()
     *
     * @return mixed whatever $fn returns
     */
    public function withConnection(callable $fn, string|int|null $affinity = null)
    {
        $conn = $this->acquire($affinity);
        try {
            return $fn($conn);
        } finally {
            $this->release($conn);
        }
    }

    /**
     * The members as the pool sees them, for monitoring: endpoint (what it
     * dials), canonical (the member's own name, once discovered), state
     * ('ready', 'ejected', 'resyncing', 'unreachable', 'unlisted'),
     * latency_ms (the round-trip average; null until measured), in_use,
     * idle and seed.
     *
     * @return array<int, array{endpoint:string, canonical:?string, state:string, latency_ms:?float, in_use:int, idle:int, seed:bool}>
     */
    public function members(): array
    {
        $idle = [];
        foreach ($this->idle as $c) {
            $ep = $this->slots[$c]->ep ?? '';
            $idle[$ep] = ($idle[$ep] ?? 0) + 1;
        }
        $members = array_values($this->members);
        usort($members, fn (PoolMember $a, PoolMember $b) => strcmp($a->endpoint, $b->endpoint));
        return array_map(fn (PoolMember $m) => [
            'endpoint' => $m->endpoint,
            'canonical' => $m->canonical,
            'state' => $m->ejectedUntil !== null ? 'ejected' : $m->listed,
            'latency_ms' => $m->ewma === null ? null : $m->ewma * 1000.0,
            'in_use' => $m->inUse,
            'idle' => $idle[$m->endpoint] ?? 0,
            'seed' => $m->seed,
        ], $members);
    }

    /** Close the pool and every idle connection; checked-out ones close on release. */
    public function close(): void
    {
        $this->closed = true;
        $idle = $this->idle;
        $this->idle = [];
        foreach ($idle as $conn) {
            $conn->close();
        }
    }

    // ---- choosing a member ------------------------------------------------

    private static function usable(PoolMember $m): bool
    {
        return $m->ejectedUntil === null && $m->listed === 'ready';
    }

    /** The member this checkout should go to, or null for "any" (random, or nothing usable). */
    private function pick(string $key): ?PoolMember
    {
        if ($this->readYourWrites > 0 && isset($this->affinity[$key])) {
            [$ep, $until] = $this->affinity[$key];
            $m = $this->members[$ep] ?? null;
            if (self::now() >= $until || $m === null) {
                unset($this->affinity[$key]);
            } elseif (self::usable($m)) {
                return $m;
            }
        }
        $usable = array_values(array_filter($this->members, fn (PoolMember $m) => self::usable($m)));
        if ($usable === [] || $this->policy === self::RANDOM) {
            return null;
        }
        if ($this->policy === self::ROUND_ROBIN) {
            usort($usable, fn (PoolMember $a, PoolMember $b) => strcmp($a->endpoint, $b->endpoint));
            $this->rr = ($this->rr + 1) % count($usable);
            return $usable[$this->rr];
        }
        $n = count($usable);
        if ($n === 1) {
            return $usable[0];
        }
        // Two distinct members, in random order.
        $i = mt_rand(0, $n - 1);
        $j = mt_rand(0, $n - 2);
        if ($j >= $i) {
            $j++;
        }
        [$a, $b] = [$usable[$i], $usable[$j]];
        return self::score($a) <= self::score($b) ? $a : $b;
    }

    private static function score(PoolMember $m): float
    {
        // A member never measured, or not lately, scores 0: it is tried and
        // measured.
        if ($m->ewma === null || self::now() - $m->lastSample > self::STALE_SAMPLE) {
            return 0.0;
        }
        return $m->ewma * ($m->inUse + 1);
    }

    /**
     * Remove and return an idle connection to $target (to any usable member
     * when null), closing the idle connections found to belong to members
     * that are no longer usable.
     */
    private function takeIdle(?PoolMember $target): ?Connection
    {
        $n = count($this->idle);
        $found = null;
        $stale = [];
        for ($k = 0; $k < $n; $k++) {
            $i = $this->fifo ? $k : $n - 1 - $k;
            $m = $this->members[$this->slots[$this->idle[$i]]->ep ?? ''] ?? null;
            if ($m === null || !self::usable($m)) {
                $stale[] = $this->idle[$i];
                unset($this->idle[$i]);
                continue;
            }
            if ($target === null || $m === $target) {
                $found = $this->idle[$i];
                unset($this->idle[$i]);
                break;
            }
        }
        $this->idle = array_values($this->idle);
        foreach ($stale as $c) {
            $c->close();
        }
        return $found;
    }

    /**
     * Endpoints to dial, best first: $first, the other usable members in
     * random order, then the rest (resyncing/unreachable before ejected, the
     * soonest re-admission first) — a dial never has nowhere to go while any
     * member is configured. $exclude, when given, goes last.
     *
     * @return array<int, string>
     */
    private function order(?PoolMember $first = null, ?string $exclude = null): array
    {
        $usable = [];
        $rest = [];
        foreach ($this->members as $m) {
            if ($m === $first) {
                continue;
            }
            if (self::usable($m)) {
                $usable[] = $m;
            } else {
                $rest[] = $m;
            }
        }
        shuffle($usable);
        usort($rest, fn (PoolMember $a, PoolMember $b) => [$a->ejectedUntil !== null, $a->ejectedUntil ?? 0.0]
            <=> [$b->ejectedUntil !== null, $b->ejectedUntil ?? 0.0]);
        $eps = array_map(fn (PoolMember $m) => $m->endpoint, [...($first === null ? [] : [$first]), ...$usable, ...$rest]);
        $i = $exclude === null ? false : array_search($exclude, $eps, true);
        if ($i !== false && count($eps) > 1) {
            array_splice($eps, $i, 1);
            $eps[] = $exclude;
        }
        return $eps;
    }

    private function dial(array $order): Connection
    {
        $conn = Connection::pooled($this->args, $order, $this);
        $this->slots[$conn] = (object) ['ep' => $conn->endpoint(), 'out' => false, 'key' => ''];
        return $conn;
    }

    // ---- what connections report (called by Connection) -------------------

    /**
     * A pooled connection re-dialing: the pool's order, the member it lost
     * last.
     *
     * @internal
     * @return array<int, string>
     */
    public function dialOrder(?string $exclude): array
    {
        return $this->order(null, $exclude);
    }

    /**
     * A request on $conn took $elapsed seconds (send to first response frame).
     *
     * @internal
     */
    public function roundTrip(Connection $conn, float $elapsed): void
    {
        $m = $this->members[$conn->endpoint() ?? ''] ?? null;
        if ($m === null) {
            return;
        }
        $m->errors = 0;
        if ($m->ewma === null) {
            $m->ewma = $elapsed;
        } else {
            $cap = self::EWMA_CLAMP * max($m->ewma, $this->ejectFloor);
            $m->ewma += self::EWMA_WEIGHT * (min($elapsed, $cap) - $m->ewma);
        }
        $m->samples++;
        $m->lastSample = self::now();
        // Only a member still in rotation: round trips finishing on
        // connections checked out before an ejection must not eject it again.
        if (!self::usable($m) || $m->samples < self::EWMA_MIN_SAMPLES || $this->ejectFactor <= 0) {
            return;
        }
        $others = [];
        foreach ($this->members as $o) {
            if ($o !== $m && self::usable($o) && $o->ewma !== null && $o->samples >= self::EWMA_MIN_SAMPLES) {
                $others[] = $o->ewma;
            }
        }
        if ($others === []) {
            return;
        }
        sort($others);
        $median = $others[intdiv(count($others) - 1, 2)]; // the lower middle when even
        if ($m->ewma > $this->ejectFactor * $median && $m->ewma - $median > $this->ejectFloor) {
            $this->eject($m);
        }
    }

    /**
     * A transport failure on $endpoint: a failed dial, an I/O error or a
     * timeout mid-request, a capacity refusal.
     *
     * @internal
     */
    public function failed(string $endpoint): void
    {
        $m = $this->members[$endpoint] ?? null;
        if ($m === null) {
            return;
        }
        $m->errors++;
        if ($m->errors >= $this->ejectErrors && $m->ejectedUntil === null) {
            $this->eject($m);
        }
    }

    /**
     * $conn (re-)dialed: count it against the member it reached now.
     *
     * @internal
     */
    public function moved(Connection $conn): void
    {
        $slot = $this->slots[$conn] ?? null;
        $ep = $conn->endpoint();
        if ($slot === null || $slot->ep === $ep) {
            return;
        }
        if ($slot->out) {
            $old = $this->members[$slot->ep ?? ''] ?? null;
            if ($old !== null && $old->inUse > 0) {
                $old->inUse--;
            }
            $new = $this->members[$ep ?? ''] ?? null;
            if ($new !== null) {
                $new->inUse++;
            }
        }
        $slot->ep = $ep;
    }

    /**
     * $conn was closed: if it was checked out, it no longer counts.
     *
     * @internal
     */
    public function closedOut(Connection $conn): void
    {
        $slot = $this->slots[$conn] ?? null;
        if ($slot === null || !$slot->out) {
            return;
        }
        $slot->out = false;
        $m = $this->members[$slot->ep ?? ''] ?? null;
        if ($m !== null && $m->inUse > 0) {
            $m->inUse--;
        }
    }

    /**
     * $sql succeeded on $conn: with read-your-writes on, a write sends the
     * affinity key it was checked out under to that member for the window.
     *
     * @internal
     */
    public function statement(Connection $conn, string $sql): void
    {
        if ($this->readYourWrites <= 0 || !self::isWrite($sql)) {
            return;
        }
        $slot = $this->slots[$conn] ?? null;
        $ep = $conn->endpoint();
        if ($slot === null || !$slot->out || $ep === null) {
            return;
        }
        $now = self::now();
        if (count($this->affinity) >= 4096) {
            $this->affinity = array_filter($this->affinity, fn (array $a) => $a[1] > $now);
        }
        $this->affinity[$slot->key] = [$ep, $now + $this->readYourWrites];
    }

    // ---- ejection and re-admission ----------------------------------------

    /** Take $m out of rotation — unless it is the last usable member: a slow or flaky member beats none. */
    private function eject(PoolMember $m): void
    {
        $others = false;
        foreach ($this->members as $o) {
            if ($o !== $m && self::usable($o)) {
                $others = true;
                break;
            }
        }
        if (!$others) {
            return;
        }
        $now = self::now();
        if ($m->readmittedAt === null || $now - $m->readmittedAt > $this->backoffMax) {
            $m->ejections = 0; // healthy for a while: start the backoff over
        }
        $m->ejections++;
        $m->ejectedUntil = $now + $this->backoffFor($m);
        $keep = [];
        $drop = [];
        foreach ($this->idle as $c) {
            if (($this->slots[$c]->ep ?? null) === $m->endpoint) {
                $drop[] = $c;
            } else {
                $keep[] = $c;
            }
        }
        $this->idle = $keep;
        foreach ($drop as $c) {
            $c->close();
        }
    }

    private function backoffFor(PoolMember $m): float
    {
        return min($this->backoff * (2 ** max($m->ejections - 1, 0)), $this->backoffMax);
    }

    /**
     * Probe the member whose backoff ran out longest ago, if any. Inline —
     * PHP has no background thread — so a checkout runs at most one probe.
     */
    private function probeDue(): void
    {
        $now = self::now();
        $due = null;
        foreach ($this->members as $m) {
            if ($m->ejectedUntil !== null && $now >= $m->ejectedUntil
                && ($due === null || $m->ejectedUntil < $due->ejectedUntil)) {
                $due = $m;
            }
        }
        if ($due !== null) {
            $this->probe($due);
        }
    }

    /**
     * Dial $m alone and run one statement, bounded by the connection timeout:
     * success re-admits it with a fresh latency average (the probe connection
     * joins the idle set), failure ejects it again for twice as long.
     */
    private function probe(PoolMember $m): void
    {
        $conn = null;
        try {
            $conn = Connection::pooled($this->args, [$m->endpoint], $this);
            $conn->query('SHOW DATABASES');
            $ok = $conn->isUsable();
        } catch (SkaidbException $e) {
            $ok = false;
        }
        if (!$ok) {
            $m->ejections++;
            $m->ejectedUntil = self::now() + $this->backoffFor($m);
        } elseif (($this->members[$m->endpoint] ?? null) === $m) {
            $m->ejectedUntil = null;
            $m->errors = 0;
            $m->ewma = null;
            $m->samples = 0;
            $m->readmittedAt = self::now();
            if (count($this->idle) < $this->maxsize) {
                $this->slots[$conn] = (object) ['ep' => $m->endpoint, 'out' => false, 'key' => ''];
                $this->idle[] = $conn;

                return;
            }
        }
        $conn?->close();
    }

    // ---- discovery --------------------------------------------------------

    /**
     * Run discovery on a freshly dialed connection when the member it reached
     * has not named itself yet (once per member, so a seed given as a host
     * name is matched to the endpoint the server reports).
     */
    private function discoverThrough(Connection $conn): void
    {
        if (!$this->discover || $this->discoveryOff) {
            return;
        }
        $m = $this->members[$conn->endpoint() ?? ''] ?? null;
        if ($m !== null && $m->canonical === null) {
            $this->listThrough($conn);
        }
    }

    private function refreshDue(): bool
    {
        return $this->discover && !$this->discoveryOff && self::now() >= $this->nextRefresh;
    }

    private function listThrough(Connection $conn): void
    {
        try {
            $rows = $conn->query('SHOW ENDPOINTS')->fetchAll();
        } catch (SkaidbException $e) {
            if ($conn->isUsable()) {
                // A statement error on a live connection: a server that
                // predates SHOW ENDPOINTS. Keep the seeds, stop asking.
                $this->discoveryOff = true;
            }
            return;
        }
        if ($rows === []) {
            // A standalone server: nothing to discover. Keep the seeds, stop
            // asking.
            $this->discoveryOff = true;
            return;
        }
        $this->applyListing($conn->endpoint(), $rows);
        $interval = $this->refreshInterval;
        foreach ($this->members as $m) {
            if ($m->listed === 'resyncing' || $m->listed === 'unreachable') {
                $interval = min($interval, self::REFRESH_NOT_READY);
                break;
            }
        }
        $this->nextRefresh = self::now() + $interval;
    }

    /**
     * Fold a SHOW ENDPOINTS answer (endpoint, state, local) into the members.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyListing(?string $dialed, array $rows): void
    {
        $listed = [];
        foreach ($rows as $row) {
            $endpoint = (string) ($row['endpoint'] ?? '');
            if ($endpoint === '') {
                continue;
            }
            $listed[$endpoint] = (string) ($row['state'] ?? 'ready');
            if (!empty($row['local']) && isset($this->members[$dialed ?? ''])) {
                $this->members[$dialed]->canonical = $endpoint;
            }
        }
        // A member reported under the name of one already known (a seed
        // given as a host name) is that member, not a new one.
        $byName = [];
        foreach ($this->members as $m) {
            if ($m->canonical !== null && (!isset($byName[$m->canonical]) || $m->seed)) {
                $byName[$m->canonical] = $m;
            }
        }
        foreach ($this->members as $key => $m) {
            if ($m->canonical !== null && $byName[$m->canonical] !== $m) {
                unset($this->members[$key]); // a duplicate of a seed
            }
        }
        $seen = [];
        foreach ($listed as $endpoint => $state) {
            $endpoint = (string) $endpoint;
            $m = $byName[$endpoint] ?? null;
            if ($m === null) {
                $m = $this->members[$endpoint] ?? null;
                if ($m === null) {
                    $m = new PoolMember($endpoint, false);
                    $this->members[$endpoint] = $m;
                }
                $m->canonical = $endpoint;
            }
            $state = in_array($state, ['ready', 'resyncing', 'unreachable'], true) ? $state : 'ready';
            if ($state === 'ready' && $m->listed !== 'ready') {
                // Back in rotation: measured afresh, like a re-admission.
                $m->ewma = null;
                $m->samples = 0;
                $m->errors = 0;
            }
            $m->listed = $state;
            $seen[spl_object_id($m)] = true;
        }
        foreach ($this->members as $key => $m) {
            if (isset($seen[spl_object_id($m)]) || ($m->seed && $m->canonical === null)) {
                continue; // listed, or a seed not identified yet
            }
            if ($m->seed) {
                $m->listed = 'unlisted'; // configured, but no longer a member
            } else {
                unset($this->members[$key]);
            }
        }
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * Whether $sql writes, for read-your-writes: anything but a statement
     * whose first keyword (after whitespace, comments and opening
     * parentheses) is SELECT, SHOW, EXPLAIN, DESCRIBE, DESC, VALUES, WITH or
     * USE.
     *
     * @internal
     */
    public static function isWrite(string $sql): bool
    {
        $i = 0;
        $n = strlen($sql);
        while ($i < $n) {
            $two = substr($sql, $i, 2);
            if (str_contains(" \t\n\r\v\f(", $sql[$i])) {
                $i++;
            } elseif ($two === '--') {
                $j = strpos($sql, "\n", $i);
                $i = $j === false ? $n : $j + 1;
            } elseif ($two === '/*') {
                $j = strpos($sql, '*/', $i + 2);
                $i = $j === false ? $n : $j + 2;
            } else {
                break;
            }
        }
        preg_match('/[A-Za-z_]*/A', $sql, $word, 0, $i);
        return !in_array(strtolower($word[0] ?? ''), self::READ_KEYWORDS, true);
    }

    /**
     * Whether a failed dial says something about the member's health. A
     * refused login is the caller's problem, not the member's — except a
     * capacity refusal, which is the member being full.
     *
     * @internal
     */
    public static function memberFault(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'authentication denied')) {
            return str_contains($msg, 'too many connections:');
        }
        return !str_starts_with($msg, 'server signature mismatch');
    }

    /** Monotonic seconds. */
    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Connection constructor arguments with positional ones named, so the
     * pool can read the seeds and dial with its own endpoint order.
     *
     * @param array<int|string, mixed> $args
     * @return array<string, mixed>
     */
    private static function named(array $args): array
    {
        $names = [];
        foreach ((new \ReflectionMethod(Connection::class, '__construct'))->getParameters() as $p) {
            $names[] = $p->getName();
        }
        $out = [];
        foreach ($args as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            } elseif (isset($names[$k])) {
                $out[$names[$k]] = $v;
            }
        }
        return $out;
    }
}
