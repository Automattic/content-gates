# Public PHP API

Version 0.1.0. The contract is specified in `spec/api.md`; this page is the
integrator's view.

Every function lives in `Automattic\VIP\ContentGates`. Import them:

```php
use function Automattic\VIP\ContentGates\assign_reader_to_segment;
use function Automattic\VIP\ContentGates\remove_reader_from_segment;
use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\is_reader_in_segment;
use function Automattic\VIP\ContentGates\get_reader_segment_value;
use function Automattic\VIP\ContentGates\get_declared_segments;
```

`$segment` is a segment key (`subscriber`) or the segment record's post ID.
Keys are the stable reference; the plugin resolves IDs to keys.

## Functions

| Function | Returns | Window |
| --- | --- | --- |
| `assign_reader_to_segment( $segment, ?string $value = null )` | `true\|WP_Error` | After `init` priority 10, before `send_headers` |
| `remove_reader_from_segment( $segment )` | `true\|WP_Error` | Same. Allowed for inactive segments, so stale cookies can be cleared |
| `declare_segment( $segment )` | `true\|WP_Error` | On the `wp` action or earlier (see Timing) |
| `is_reader_in_segment( $segment, ?string $value = null )` | `bool` | `template_redirect` and later |
| `get_reader_segment_value( $segment )` | `?string` | `template_redirect` and later |
| `get_declared_segments()` | `string[]` | After the declared set is final. Sorted group names |

Rules:

- A boolean segment stores `1`. A meter segment stores `open`, `last`, or
  `wall`, written only by the Edge Worker; `assign_reader_to_segment()` on
  a meter returns `content_gates_invalid_value`.
- `is_reader_in_segment()` with a value checks for that exact value. Without
  one it is true for any value on a boolean segment and for `open` or `last`
  on a meter, so "in the meter" means "has allowance left".
- **Reads fail closed.** A segment that is not in this response's declared
  set reads as `false` or `null`, whatever the cookie says, and raises
  `_doing_it_wrong()` under `WP_DEBUG`. So does a read made before the set
  is final. The reason is in [architecture.md](architecture.md#the-declared-set).
- Inactive segments are never declared, so they read as `false`. Assignment
  to one is refused.
- **Writes are idempotent.** Assigning a value the reader already holds, or
  removing a group they are not in, returns `true` and writes nothing: no
  dirty flag, no `Set-Cookie`.
- **Write once, remove explicitly, never on a page view.** Set membership
  from a POST, a logged-in request, or your own endpoint; the edge forwards
  those with the full cookie. On a request the edge narrowed, writers
  return `content_gates_narrowed_request` and change nothing.
- **Removing the last group expires the cookie.** The response expires
  `vip-go-seg` (or `vip-go-auth` in encrypted mode) once, with the path and
  domain `Vary_Cache` used to set it. `Vary_Cache` does this itself since
  vip-go-mu-plugins #7454. An older `Vary_Cache` skips its write when every
  group is empty, which would leave the reader a member on the next
  request, so with one the plugin expires the cookie on
  `vip_vary_cache_did_send_headers`.
- Unknown references return `false` or `null` from readers with a
  `_doing_it_wrong()` notice, and `content_gates_invalid_segment` from writers.

## Errors

| Code | Raised by | Meaning |
| --- | --- | --- |
| `content_gates_too_late` | assign, remove, declare | The hook window closed |
| `content_gates_invalid_segment` | assign, remove, declare | The reference resolves to no segment |
| `content_gates_inactive_segment` | assign, declare | The segment is inactive |
| `content_gates_invalid_value` | assign | Bad characters, or a meter segment |
| `content_gates_platform_unavailable` | assign, remove | `Vary_Cache` is not loaded outside a `local` environment |
| `content_gates_narrowed_request` | assign, remove | The edge narrowed this request's cookie. Write from a POST, a logged-in request, or your own endpoint instead |

## Timing

The timing table is in the spec, [spec/api.md](../spec/api.md#timing-window),
and is not repeated here. The two facts integrators trip on:

- Core fires `send_headers` **before** the `wp` action (`WP::main()`), so
  membership writes close at `send_headers` and the declared set closes at
  the end of `wp`. Both run before output and before the template loads.
- REST requests never reach `send_headers` or `wp`. There the declared set
  grows while the response is built (each post's stored set is merged just
  before its content renders) and the header, `Vary`, and any pending
  cookie are sent in `rest_pre_echo_response`, the last filter before the
  JSON is echoed. A provider route that assigns membership needs nothing
  extra; it must assign before the response is served.

## Examples

Worked examples (a site-wide header item, a per-template read, a
shortcode, a theme switch, and what forgetting to declare looks like) are in
[spec/page-cache.md](../spec/page-cache.md#reads-outside-blocks-customer-examples).
They were copied here once and drifted; the spec is the one copy.

## Platform loading

`Vary_Cache` is opt-in on WPVIP. The plugin requires
`cache/class-vary-cache.php` from the mu-plugins directory. When it is
missing and `WP_ENVIRONMENT_TYPE` is `local`, a verbatim upstream copy
bundled in `inc/platform/vendor/` is loaded so wp-env and PHPUnit behave
like the platform. Anywhere else the plugin fails closed.

## Rules for callers

All membership reads go through this API. A direct call to
`Vary_Cache::is_user_in_group()`, `Vary_Cache::is_user_in_group_segment()`,
or `Vary_Cache::get_groups()` outside the plugin bypasses the declared-set
check and is a defect. (The spec asks for a PHPCS sniff that flags those
calls; it is not written yet.)
