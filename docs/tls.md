# TLS

The binary protocol can run inside TLS. The TLS handshake happens right
after the TCP connect; SCRAM authentication and every request then ride
inside the TLS session. The server side (listener certificate, `client_tls`
policy, the cluster CA) is covered in the server docs at
<https://skaidb.org/docs/>.

## Three modes

```php
use Skaidb\Connection;

// 1. Verify against the system trust store (a publicly trusted or
//    OS-installed CA). SNI and the expected name are tlsServerName.
$db = new Connection(host: 'db1.example.com', tls: true, tlsServerName: 'db1.example.com', ...);

// 2. Verify against a specific CA — the cluster's own CA certificate.
//    This is the usual production shape.
$db = new Connection(host: 'db1', tlsCa: '/etc/skaidb/skai-ca.crt', ...);

// 3. No verification at all (self-signed dev server). INSECURE: any peer
//    can impersonate the server.
$db = new Connection(host: 'db1', tlsInsecure: true, ...);
```

Setting any of `tls: true`, `tlsCa: ...` or `tlsInsecure: true` enables TLS.
`tlsInsecure` wins over `tlsCa`. Without any of them the connection is
plaintext, even if the server would accept TLS; a server with `client_tls =
required` then refuses the connection.

## The server name

`tlsServerName` (default `skaidb`) is sent as SNI and is the name the server
certificate must match under modes 1 and 2. skaidb's generated certificates
carry `skaidb` as a subject alternative name, which is why the default is
not the host you dialled: the same certificate is valid on every node
whatever its address. If your certificates carry real hostnames, pass the
hostname.

Verification uses PHP's stream SSL context (`verify_peer`,
`verify_peer_name`, `peer_name`, `cafile`), so the negotiated protocol and
ciphers follow your PHP build's OpenSSL policy.

## Pools and seeds

The TLS arguments pass through `Pool` and apply to every seed:

```php
$pool = new Skaidb\Pool(['user' => 'app', 'password' => $pw, 'database' => 'app',
                         'seeds' => ['db1:7000', 'db2:7000'], 'tlsCa' => '/etc/skaidb/skai-ca.crt'], 8);
```

## Client certificates

The driver does not present a client certificate; identity is the SCRAM
user. A server configured to require client certificates on the binary port
will reject this driver's handshake.

## Failure modes

- Wrong CA / untrusted certificate: `no reachable endpoint in <list>: ...`
  with OpenSSL's verification error in the message.
- Name mismatch: the same, mentioning the peer name.
- Plaintext against a `client_tls = required` server: the server closes the
  socket; the driver reports `connection closed by server` during the
  handshake.
