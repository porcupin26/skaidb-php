# Changelog

All notable changes to the skaidb PHP driver. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-20

First release as a standalone package (`github.com/porcupin26/skaidb-php`),
carrying its full history over from the skaidb monorepo. This is the first
version of the driver that was executed against a real skaidb node; the live
end-to-end test in `tests/live/live.php` is what found the fixes below.

### Added
- `Skaidb\Skaidb::VERSION` (`1.0.0`) as the single source of truth for the
  version; `composer.json` carries none (Composer takes it from the git
  tag). The Hello frame the driver sends after authentication reports
  `client_name` `php` and `client_version` = that constant, and CI asserts
  the two agree.
- `Skaidb\Bytes`, `Skaidb\Decimal` and `Skaidb\Uuid` bind wrappers, so a
  parameter can travel as a typed Bytes, Decimal or Uuid value (a plain
  PHP string binds as String; SQL has no literal for these types).
- Exact Decimal encoding and decoding across the full 128-bit mantissa in
  pure PHP; `gmp`/`bcmath` are no longer consulted or suggested.
- `Statement::resultSets()` exposes every result set of a `CALL` whose
  body `EMIT`s (the last set is what `fetch()` returns).
- `Connection::stream()` accepts a consistency name (`'ONE'`) as well as a
  code, like every other consistency argument.
- A dependency-free test suite (`php tests/run.php`) with an in-process
  fake server that speaks the frame layer and SCRAM-SHA-256 for real:
  framing, the handshake (good/bad password, mutual auth, anonymous), the
  value codec for every type, prepare/execute with typed parameters,
  batches, streaming (chunks, mid-stream errors, the abandon/drain rule),
  multiple result sets, reconnect, the pool, `subscribe()`, and the
  Hello-version-equals-package-version check.
- A live end-to-end test (`tests/live/live.php`) against a real node,
  skipped unless `SKAIDB_HOST` is set.
- CI on PHP 8.1, 8.2, 8.3 and 8.4, `composer validate --strict`, a Composer
  VCS install of the package as a consumer, and a publish workflow that
  gates the tag on `Skaidb::VERSION`, builds the source zip and attaches it
  to a GitHub Release.
- Documentation: README plus `docs/` (getting started, API reference,
  types, TLS, streaming, pooling) and runnable `examples/`.

### Fixed
- A `DateTimeInterface` parameter was converted to milliseconds through a
  float (`(float) format('U.u') * 1000`, rounded), which could shift a
  timestamp by a millisecond or into the next second near `.9995`. The
  conversion is now integer arithmetic on seconds and microseconds.
- An integral float bound through the client-side fallback (`2.0`) was
  rendered as `2` and stored as an Int. It now renders as `2.0`.
- Binding an array or document to a statement the server would not prepare
  (for example one with a syntax error) failed with `cannot bind value of
  type array`, hiding the server's reason. The exception now carries the
  prepare error too.
- Decimal values whose mantissa exceeded 64 bits were approximated when
  neither `gmp` nor `bcmath` was loaded; they are exact now.

### Changed
- Package identity: repository and homepage are
  `https://github.com/porcupin26/skaidb-php`; license `SSPL-1.0`; PHP 8.1
  or newer (the driver already used `array_is_list`).
- The example moved from `example.php` to `examples/basic.php`.

[1.0.0]: https://github.com/porcupin26/skaidb-php/releases/tag/v1.0.0
