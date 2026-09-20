# Types

## Value mapping

Results decode from skaidb's typed wire values; parameters encode back to
them when a statement is prepared on the server (the normal path whenever
you pass values to `execute()` or `executeBatch()`).

| skaidb type | Result value | Accepted as a parameter |
|---|---|---|
| Null | `null` | `null` |
| Bool | `bool` | `bool` |
| Int (64-bit) | `int` | `int` |
| Float (64-bit) | `float` | `float` (NaN and ±Infinity are refused) |
| Decimal | `string`, exact (`'123.45'`, `'-0.005'`) | `new Skaidb\Decimal('123.45')` |
| String | `string` (UTF-8) | `string` |
| Bytes | `string` holding the raw bytes | `new Skaidb\Bytes($raw)` |
| Uuid | `string`, canonical lowercase `8-4-4-4-12` | `new Skaidb\Uuid('123e4567-e89b-12d3-a456-426614174000')` |
| Timestamp | `DateTimeImmutable` in UTC, millisecond precision, may predate 1970 | any `DateTimeInterface` (converted to the instant, microseconds truncated to milliseconds) |
| Array | `array` list | a list array (`array_is_list($v)` is true, including `[]`) |
| Document | `array`, associative, key order as sent by the server | any non-list array (keys become strings) |

Notes:

- PHP has one `string` type for text and binary data, so Decimal, Bytes and
  Uuid results all arrive as strings; the table above says which form. To
  send one of them **back** as the typed value, wrap it: a bare string binds
  as a String. The wrappers validate their input (`Decimal` accepts
  `[+-]digits[.digits]`, no exponent; `Uuid` accepts 32 hex digits with or
  without hyphens) and expose the normalized value as `->value` /
  `__toString()`.
- Decimal is exact across the whole 128-bit mantissa in pure PHP; no `gmp`
  or `bcmath` is used. A mantissa that does not fit 128 bits is refused.
- An empty PHP array is a list, so it binds as an empty Array. There is no
  way to bind an empty Document from an array; the server also returns an
  empty Document as `[]`.
- The server stores a Document with its keys sorted, so a document read back
  may not have the key order you inserted. The driver itself preserves the
  order in both directions.
- Timestamps are Unix milliseconds on the wire. A zoned `DateTime` binds as
  the same instant; results are always UTC.

## Client-side fallback

Statements the server will not prepare (DDL, `USE`, other session
statements) get their `?` parameters quoted into the SQL text instead:
strings single-quoted with `'` doubled, ints and floats as literals (an
integral float keeps its `.0`), bools as `TRUE`/`FALSE`, `null` as `NULL`,
a `DateTimeInterface` as its epoch milliseconds, a `Decimal` as its numeric
text, a `Bytes` as a hex string, a `Uuid` as a quoted string, any other
object with `__toString()` as a quoted string. Arrays and documents cannot
be rendered as SQL and throw; the message includes why the server would not
prepare the statement.
