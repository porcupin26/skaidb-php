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

`tlsClientCert` / `tlsClientKey` (PEM files; the key may be omitted when the
certificate file also holds it) make the driver present a client
certificate in the TLS handshake, which a server that verifies client
certificates needs. Either implies TLS. On its own the certificate only
opens the TLS session; the login is still the SCRAM user.

With `authMechanism: 'certificate'` the certificate IS the login (wire
mechanism EXTERNAL): the server maps its Common Name to a role and no
password is sent. The server needs `auth.x509_enabled`, and its client CA
must have signed the certificate.

```php
$db = new Connection(host: 'db1', tlsCa: '/etc/skaidb/ca.crt',
                     tlsClientCert: '/etc/app/app.crt', tlsClientKey: '/etc/app/app.key',
                     authMechanism: 'certificate', database: 'app');
```

Pass `user` only to assert the expected identity: a certificate mapped to a
different role fails the connect with `authentication denied: the username
does not match the client certificate's Common Name`. The server's
signature is not checked under this mechanism; TLS already authenticated
the server, so verify it (`tlsCa`), not `tlsInsecure`.

## Failure modes

- Wrong CA / untrusted certificate: `no reachable endpoint in <list>: ...`
  with OpenSSL's verification error in the message.
- Name mismatch: the same, mentioning the peer name.
- Plaintext against a `client_tls = required` server: the server closes the
  socket; the driver reports `connection closed by server` during the
  handshake.
