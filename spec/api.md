# Content Gating API

This file is the public PHP contract for entitlement providers and template authors. [page-cache.md](page-cache.md) specifies how the declared set reaches the edge. [decisions.md](decisions.md) records the product decisions behind each rule. The placeholder names below become the contract at the first release.

## Namespace and versioning

- Every public function lives in `Automattic\VIP\ContentGates`. Callers import them with `use function`.
- Every hook is prefixed `content_gates_`.
- Deprecation policy: a public function or hook is deprecated with `_deprecated_function()` or `_deprecated_hook()` first, stays available for two minor releases, and is removed only with a version bump. `docs/` records each change.

## Segment references

`$segment` is `int|string`: a segment key or a segment post ID. The plugin resolves a post ID to its key; the key is the stored reference everywhere (block attributes, post meta, the declared-set header). Keys match `/^[A-Za-z0-9_-]+$/` and are at most 16 characters. See [decisions.md](decisions.md) for the identity rules.

An unknown reference returns `content_gates_invalid_segment` from writers. Readers return `false` or `null`, record nothing, and raise `_doing_it_wrong()` under `WP_DEBUG`.

## Functions

| Function | Returns | When it may run |
| --- | --- | --- |
| `assign_reader_to_segment( int\|string $segment, ?string $value = null )` | `true\|WP_Error` | After `init` priority 10 and before `send_headers` |
| `remove_reader_from_segment( int\|string $segment )` | `true\|WP_Error` | Same window. Allowed for inactive segments so providers can clear stale cookies |
| `declare_segment( int\|string $segment )` | `true\|WP_Error` | Before the plugin's finalize callback at the end of the `wp` action. Use the `wp` action |
| `is_reader_in_segment( int\|string $segment, ?string $value = null )` | `bool` | After the plugin's finalize callback (`template_redirect` and later) |
| `get_reader_segment_value( int\|string $segment )` | `?string` | After the plugin's finalize callback |
| `get_declared_segments()` | `string[]` | After the plugin's finalize callback. Group names, sorted |

Rules that apply to every function:

- A boolean segment stores the value `1`. A meter segment stores `open`, `last`, or `wall`, written by the edge. Values match `/^[A-Za-z0-9_-]+$/`. `is_reader_in_segment()` with a value checks for that exact value. Without a value it checks for any value on a boolean segment, and for `open` or `last` on a meter segment, so "in the meter" means "has allowance left".
- Meter values are written only by the Edge Worker. `assign_reader_to_segment()` on a meter segment returns `content_gates_invalid_value`. See the metering section of [page-cache.md](page-cache.md).
- A read of a segment that is not in the declared set returns `false` or `null` and raises `_doing_it_wrong()` under `WP_DEBUG`. This is the fail-closed rule from [page-cache.md](page-cache.md). It also applies to a read made before the declared set is final.
- Inactive segments are never declared. Reads of them return `false`. Assignment to them is refused.
- Writes are idempotent. Assigning a value the reader already holds, or removing a group the reader is not in, returns `true`, marks nothing dirty, and sends no `Set-Cookie`. Providers can therefore reassert membership on every request without making entitled responses unstorable. (Added 2026-10-23; see [decisions.md](decisions.md).)
- Removing the reader's last group expires the segmentation cookie (`vip-go-seg`, or `vip-go-auth` in encrypted mode) with the path and domain `Vary_Cache` used to set it. Upstream `Vary_Cache` skips the write when every group is empty, which would leave the reader a member on the next request. The plugin corrects this on `vip_vary_cache_did_send_headers` without editing `Vary_Cache`.
- Membership is parsed from the cookie by `Vary_Cache` at `init` priority 10. Nothing in this API works before that.
- All membership reads go through this API. A direct call to `Vary_Cache::is_user_in_group()`, `Vary_Cache::is_user_in_group_segment()`, or `Vary_Cache::get_groups()` outside the plugin is a defect, because it bypasses the declared-set check. A project PHPCS sniff flags those calls.

## Errors

| Code | Raised by | Meaning |
| --- | --- | --- |
| `content_gates_too_late` | assign, remove, declare | Called after the hook window closed |
| `content_gates_invalid_segment` | assign, remove, declare | The reference resolves to no segment |
| `content_gates_inactive_segment` | assign, declare | The segment is inactive |
| `content_gates_invalid_value` | assign | The value does not match the allowed pattern, or the segment is a meter, whose values only the edge writes |
| `content_gates_platform_unavailable` | assign, remove | `Vary_Cache` is not loaded outside a `local` environment |

## Hooks

| Hook | Type | Arguments | When |
| --- | --- | --- | --- |
| `content_gates_loaded` | action | `Plugin $plugin` | Once, after the plugin registered its hooks. Providers hook here |
| `content_gates_declared_segments` | filter | `string[] $segments, \WP_Query $query` | At `send_headers`, when the plugin builds the declared set. The filter can add keys but never remove them: keys that came from block assignments or earlier declarations are restored after the filter runs. Removing one would make its reads fail closed and withhold content with no visible cause |

Providers that follow the post-redirect-get pattern redirect on WPVIP's `vip_vary_cache_did_send_headers( $sent_vary, $sent_cookie )` action, which fires after `Vary_Cache` has written the cookie.

## Timing window

| Step | Hook | Who |
| --- | --- | --- |
| Segment registry loads; groups registered with `Vary_Cache` | `init`, before priority 10 | Plugin. Must stay before priority 10: `Vary_Cache::register_groups()` resets each group to an empty value, so registering after the cookie is parsed erases membership |
| Cookie parsed into membership | `init` priority 10 | `Vary_Cache` |
| Assign or remove membership | `init` after 10, up to `send_headers` | Provider |
| Write window closes | `send_headers` priority `PHP_INT_MAX - 1` | Plugin |
| `Vary` and cookie sent | `send_headers` priority `PHP_INT_MAX` | `Vary_Cache` |
| Declare segments read outside blocks | `wp` | Site code |
| Declared set built; filter runs; declared header sent | `wp` priority `PHP_INT_MAX` | Plugin |
| Reads | `template_redirect` and later | Blocks, templates, shortcodes |

Core fires `send_headers` before the `wp` action (`WP::main()`), so the plugin's finalize callback runs at the end of `wp`, not on `send_headers`. Both run before any output and before `template_redirect`. Membership writes therefore close at `send_headers` and declarations close at the end of `wp`. (Corrected 2026-10-22; see [decisions.md](decisions.md).)

REST responses build the declared set incrementally instead: a post's stored set is merged just before its content renders, and the header is sent in `rest_pre_echo_response`, the last filter before the JSON is echoed, so `_embed`ded posts are covered. When another handler serves the response itself (`rest_pre_serve_request` returns true) the set is closed there. Feeds, oEmbed, and embeds follow the page path.

REST requests exit before the `send_headers` action, so `Vary_Cache` never sends `Vary` or writes its cookie on them by itself. A provider route that assigns membership during a REST request would return 200 and set nothing, and a REST response that rendered gated content would carry no `Vary`. The plugin therefore calls `Vary_Cache::send_headers()` when it sends the declared header on a REST response. Provider routes need no extra step, but they must assign before the response is served.

## Platform loading

`Vary_Cache` is opt-in on WPVIP: nothing in the platform loads `cache/class-vary-cache.php` by itself. The plugin requires the file from the mu-plugins directory when it exists. When it does not exist and `WP_ENVIRONMENT_TYPE` is not `local`, the plugin fails closed: every read returns `false`, assignments return `content_gates_platform_unavailable`, and gated Groups are withheld for everyone. A plaintext cookie adapter runs only in a `local` environment, for development and tests, and is never a fallback in production.

## Response headers

| Header | Sent when | Value |
| --- | --- | --- |
| `X-WPVIP-Content-Gates-Declared` | Every response that passes the plugin's finalize callback | Sorted group names in the declared set, comma separated; empty when none |
| `X-WPVIP-Content-Gates-Meter` | The declared set of a singular page contains a meter segment; once per meter. Feeds and REST responses declare the meter but are not article views, so they send no meter header | `{group}; id={post_id}; limit={n}; period={seconds}` |
| `X-WPVIP-Content-Gates-Edge-Received` | With the declared header | The worker's request markers as origin received them: `lookup=…; record=…; key=…; crawler=…`. `-` is an absent header, `none` a present but empty one. Sanitized to `[A-Za-z0-9_:, -]` |

The first two are read by the Edge Worker and stored with the cached copy. [page-cache.md](page-cache.md) specifies how each is used.

### Diagnostic headers (added 2026-10-23)

Edge Workers expose no logs, so the worker and origin each report what they saw on the response itself. These headers are always sent, on every environment: gating them on a request header would fail exactly when the request snapshot is the thing under test, and gating on a deployment setting would make the live evidence depend on configuration. They reveal nothing the declared header does not already reveal.

| Header | Set by | Value |
| --- | --- | --- |
| `X-WPVIP-Content-Gates-Edge-Received` | origin | The markers origin saw on the request (table above) |
| `X-WPVIP-Content-Gates-Edge-Coverage` | worker, phase 3, before the store | `{reason}; lookup={marker}; record={names}` where reason is `covered`, `narrowed-too-far`, `undeclared`, `unsegmented`, or `no-marker`. Stored with the copy, so a hit shows the decision of the miss that created it |
| `X-WPVIP-Content-Gates-Edge-Meter` | worker, phase 4, per delivery on metered responses | `advanced; meters={n}; age={seconds}` or `skipped; reason={why}; age={seconds}`. `age` echoes the response's `Age`, so a hit is recognizable |

Reading them together: if origin's echo shows `lookup=full` but the worker's coverage header says `no-marker`, the origin-response snapshot did not carry the markers (open platform question 8). If both show `-`, phase 1 did not rewrite the request (question 1).

The worker sends one request header to origin, `X-WPVIP-Content-Gates-Edge`, carrying the version of `config/crawlers.json` it was built with. The plugin compares it with its own copy and shows an admin notice on a mismatch.

## Cache group names

Each segment is one `Vary_Cache` group. The group name is the key on a single site and `{blog_id}-{key}` on multisite. Meter segments are site-defined and follow the same rule. Crawler segments are VIP-maintained, network-wide, and never prefixed: their values are injected per request and never stored in a browser cookie, so they cannot cross sites. The declared-set header carries group names.

## Second cookie writers

`Vary_Cache` writes `vip-go-seg`. Any other writer, such as an Edge Worker, must reproduce the format exactly: the `vc-v1__` prefix, `_--_` between name and value, `---__` between groups, groups sorted by name, and the `[A-Za-z0-9_-]` character set. The edge writes meter states to the browser and injects crawler values into the origin-bound cookie header only; see the metering and crawler sections of [page-cache.md](page-cache.md).

## Debugging

The declared-set header is the debug signal for narrowing. `Vary_Cache` adds `X-VIP-Go-Segmentation-Debug` under `WP_DEBUG` with full membership. Comparing the two shows what the edge narrowed away.
