# Spec: Content Gating API

Status: draft for investigation. This document describes the API surface and the contract between WordPress and the VIP edge cache. It does not prescribe an implementation.

## API summary

**The situation.** A publisher sorts readers into groups: subscriber, newsletter member, someone on their second free article this month, a search crawler. Parts of a page are only shown to some groups. To stay fast, VIP stores finished copies of each page at the edge and serves them without running WordPress.

**The problem today.** VIP's existing tool (`Vary_Cache`) keeps one copy of a page per distinct combination of groups a reader is in. A reader in groups A, B, and C gets a copy made for "A, B, and C" even if the page only cares about A. Two subscribers who differ in an unrelated group get two copies of the same page. With many groups, copies multiply and most are duplicates.

**What the new API does.** It wraps `Vary_Cache` and adds one idea: store a page only per group it actually looked at. If a page only asks "is this reader a subscriber?", all subscribers share one copy and all non-subscribers share another. A page that asks nothing has one copy for everyone.

**The API has three jobs.**

1. Put a reader into a group, or take them out. Called by an entitlement provider (Newspack, WooCommerce, a third party) or by our own crawler detection. This is the public, versioned contract providers build against.

   ```php
   register_segment( 'subscriber' );
   register_segment( 'newsletter' );

   assign_reader_to_segment( 'subscriber' );
   remove_reader_from_segment( 'newsletter' );
   ```

2. Ask whether the reader is in a group. Called by templates, blocks, shortcodes, widgets. As a side effect, the API notes that this page looked at that group.

   ```php
   if ( is_reader_in_segment( 'subscriber' ) ) {
       the_content();
   } else {
       the_excerpt();
   }
   ```

3. Report which groups the page looked at. Done by the API itself after the page finishes rendering. The list travels with the response so the edge stores the page under only those groups.

   ```php
   add_action( 'content_gate_finalize_variance', function ( array $consulted ) {
       // $consulted is e.g. [ 'subscriber', 'newsletter' ]. Hook point for the edge signal and debug output.
   } );

   add_filter( 'content_gate_consulted_segments', function ( array $consulted ) {
       return $consulted;                              // last chance to adjust the reported set
   } );
   ```

**The one rule.** Every membership question must go through the API. Code that checks membership another way (for example calling `Vary_Cache` directly) is invisible to the report, and the edge may hand one reader a page meant for someone else.

**The catch.** The report can only be written after rendering, because that is the only time we know which groups were checked. `Vary_Cache` sends its cache instructions before rendering starts. Closing that gap needs changes in how WordPress sends the response and in what the edge does with it. This document says what those changes must achieve, not how to build them.

**Terms.** A **segment** is a named group readers can be gated against (one `Vary_Cache` group). In this spec a reader is either in a segment or not. The cookie records a fixed token (`1`) for "in", and the API treats "not in" as a distinct state called `none`. Segments with several possible values, such as metering steps, are described under Future work and are not the focus here. **Membership** is all values a reader holds, carried in the cookie. The **consulted set** is the segment names one response read while rendering. The **variant key** is membership restricted to the consulted set, including `none` entries, and is what the cache should key on.

## Use cases

### A provider puts a reader into a segment

Newspack, WooCommerce, or a third party decides a reader is entitled and calls `assign_reader_to_segment()` from PHP before the `send_headers` hook. The wrapper writes through to `Vary_Cache::set_group_for_user()`, which sets the `vip-go-seg` cookie (or `vip-go-auth` when encrypted) at `send_headers`:

```
vc-v1__<group>_--_<value>---__<group>_--_<value>
```

Names and values allow only `A-Z a-z 0-9 _ -`. Groups are sorted by name. Empty values are dropped, which is how removal works.

Because a cache HIT never runs PHP, assignment during a cacheable GET only takes effect on a request that reached origin. The reference flow is the POST-redirect-GET pattern in VIP's README: handle the POST on `init`, assign, then redirect from `vip_vary_cache_did_send_headers`.

A provider running in the browser calls an uncacheable endpoint that performs the same assignment and returns the cookie before any redirect.

### The edge puts a reader into a segment

Crawler classification runs in a VIP Edge Worker, because a cache HIT never reaches origin. The worker writes the same cookie, in the same format, as a second writer alongside `Vary_Cache`. Anonymous metering will use the same mechanism later (see Future work).

### A template author gates content

Theme, block, shortcode, and widget code calls `is_reader_in_segment()` and branches on the answer. Gated content is omitted from the HTML for readers who are not entitled. Every call records the segment in the consulted set, whether or not the reader was in it and whether or not anything was shown.

### A reader requests a page

1. The CDN turns the cookie into the `X-VIP-Go-Segmentation` request header (or decrypted `X-VIP-Go-Auth`) and looks for a stored copy.
2. On a MISS, origin parses the cookie at `init`, renders the page, and records the consulted set as reads happen.
3. After rendering, the wrapper freezes the consulted set and sends it with the response. Two plausible shapes:
   - A custom header: `X-Content-Gate-Vary: subscriber, newsletter`.
   - Standard `Vary` over per-segment request headers the Edge Worker splits out of the cookie: `Vary: X-Content-Gate-Seg-subscriber, X-Content-Gate-Seg-newsletter`.
4. The edge stores the copy keyed on the URL plus the reader's values for only those segments.
5. Later readers whose values match on those segments get that copy, whatever else they belong to.

Worked example with segments `subscriber`, `newsletter`, and `donor`. On `/article-1`, subscribers see the full article. Non-subscribers see an excerpt, and the template then checks `newsletter` to decide between a "read the rest in our newsletter" teaser and a signup form.

| Reader | Membership | `/article-1` consults | Key |
| --- | --- | --- | --- |
| A | subscriber | subscriber | `{subscriber=1}` |
| B | subscriber, newsletter | subscriber | `{subscriber=1}` (shares A's copy) |
| C | newsletter, donor | subscriber, newsletter | `{subscriber=none, newsletter=1}` |
| D | newsletter | subscriber, newsletter | `{subscriber=none, newsletter=1}` (shares C's copy) |
| E | donor, on `/about` | nothing | `{}` (one copy for everyone) |

Reader C consults `newsletter` and reader A does not, because that check only runs for non-subscribers. The consulted set for one URL is not fixed. That is safe as long as each stored copy is keyed on the set its own render reported.

On the wire, reader B's cold request and origin's response look like:

```
GET /article-1
Cookie: vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1
```

```
HTTP/1.1 200 OK
Cache-Control: max-age=300
X-Content-Gate-Vary: subscriber
```

### Non-HTML responses

Archives and search (each post in the loop may consult segments), RSS and Atom feeds, oEmbed, REST responses with rendered content, and anything reading membership from a global hook like `wp_head` all go through the same flow. Each output path needs the finalization step to fire.

### An engineer debugs or tests variance

With `WP_DEBUG` on, origin emits a header with the consulted set and variant key next to `Vary_Cache`'s existing `X-VIP-Go-Segmentation-Debug`, which carries full membership. Comparing the two shows exactly what was narrowed.

### A site operator changes a segment

Renaming or removing a segment invalidates every stored copy that depended on it and strands existing cookies. Segment IDs are stable identifiers, not labels. Changing which content a segment gates purges dependent copies. Changing a reader's membership purges nothing: the reader simply projects to a different key.

## Finish line + verification

### End result

Gated content never leaves origin for a reader who is not entitled. Pages with gates are still served from the edge, keyed on only the segments each page consulted. Pages without gates have one copy for everyone. Providers assign membership through a documented, versioned contract. Template code has one function to ask about membership. The edge cache hit rate on gated URLs stays close to today's.

### Verification

Each item below is meant to become an acceptance test or a guardrail. The adversarial cache-correctness suite covers the first group.

| Property | How it is verified |
| --- | --- |
| Zero gated bytes in a non-entitled render | For every configured segment, fetch each gated URL as a reader outside the segment with JavaScript off. Assert the gated markup is absent from the response body. |
| Reported equals consulted | Unit test: after rendering a fixture page, the finalized set equals exactly the segments the fixture read. Not the registry, not the reader's membership. |
| Every read counts | Unit test: a read that returns `false` still records the segment. |
| Nested gates key on their own set | Integration test: render `/article-1` as a non-subscriber and as a subscriber. Assert the two responses report different sets and are stored under different keys. Assert a subscriber is never served the non-subscriber copy. |
| Readers who differ only in unconsulted segments share a copy | Integration test: readers A and B above produce one stored copy. |
| Empty set means one copy | Integration test: a page with no gates reports an empty set and carries no segmentation `Vary` header. |
| No gated HTML in fragment caches | Code review guardrail: gated content is never stored in the object cache, transients, or a block render cache in this slice. |
| No unreported reads | Static analysis guardrail (see Standards). Debug-mode log of any direct `Vary_Cache` read. |
| Signal present on every output path | Integration test per path: singular, archive, search, feed, oEmbed, REST. |
| Edge honors the signal | Test on a VIP test application: the stored variant count per URL is at most 2 to the power of the number of consulted segments, not of all configured segments. |
| Hit rate | Edge cache hit rate on gated URLs within 5 percentage points of today's on the same URLs. |
| Cookie size | Guardrail: the wrapper warns when the serialized cookie approaches 4 KB. |
| Provider contract timing | Unit test: assignment after `send_headers` returns a `WP_Error` with the wrapper's own "too late" code. |

## Decisions

### Preferences

- **Wrap `Vary_Cache`, do not replace it.** Membership, cookie format, and cookie writes stay with `Vary_Cache`. The wrapper adds dependency tracking and the post-render signal. If `Vary_Cache` needs a small upstream change (a filter on whether it sends its `Vary` header, or a hook after render), prefer that to forking.
- **Key on the consulted set, never on full membership.** The response signal names segments the render consulted. The edge derives the key from those names plus the reader's values. A response that consulted nothing must not carry `Vary: X-VIP-Go-Segmentation`, which `Vary_Cache` sends whenever any group has a value. Suppressing or bypassing that header is part of this work.
- **The signal is computed after render.** `send_headers` fires before `template_redirect`, and PHP cannot add headers once output starts. So the response body has to be held until render completes, or the signal has to travel another way. Full-response buffering is the expected cost. HTTP trailers are treated as unavailable unless proven. Predicting the set before `send_headers` is acceptable only as a superset hint, never as the sole source.
- **One read API.** All membership reads go through the wrapper. Direct `Vary_Cache::is_user_in_group*()` calls are a defect, not a style issue, because `Vary_Cache` exposes no hook on reads.
- **Segment registry loads early.** Every segment that might be read is registered with `Vary_Cache::register_group()` before `send_headers` on every request path, including REST, admin-ajax, cron, and feeds.
- **Segment IDs are stable.** They appear in cookies and cache keys.
- **The provider contract is versioned separately** from the cookie's `vc-v1`. Changing cookie format, the meaning of `none`, or the response signal bumps the version.
- **Anonymous and cookie-identified readers only.** Logged-in WordPress users bypass the VIP page cache and are out of scope.
- **Edge direction stays open.** Two candidates, to be decided by what the VIP CDN supports:
  - Standard `Vary` over per-segment headers that an Edge Worker splits from the cookie. Uses HTTP's own per-response Vary matching, needs no per-URL edge state, and handles nested gates for free. Depends on the CDN honoring `Vary` on custom names and on its cap for variants per URL.
  - An edge-held per-URL manifest plus one derived header the cache varies on. Simpler header, but needs per-URL state that only grows, and each copy must still be keyed on its own reported set rather than the manifest at lookup time.

### No-gos

- Keying on the whole segmentation cookie for pages that consult a subset. This is the status quo and the thing being fixed.
- Origin-side prediction alone, keeping the existing `Vary` header. It still keys on the full cookie.
- Path-scoped cookies carrying one page's segments. Cookie count explodes and path matching is fragile.
- Reading membership from anywhere but the wrapper.
- Storing rendered gated HTML in the object cache, transients, or a block render cache. Fragment caching of gated content is out of scope for this slice (see Future work).
- Keying a stored copy on a set learned from a different render of the same URL.
- Assuming HTTP trailers work end to end.
- Rendering gated content and hiding it client-side. The whole point is that it never leaves origin.

### Not yet decided

1. Does the VIP CDN honor `Vary` on custom header names set by an Edge Worker, and is there a cap on variants per URL? Decides the edge direction.
2. Can an Edge Worker keep per-URL state, and with what consistency? Manifest direction only.
3. Can `Vary_Cache`'s `Vary: X-VIP-Go-Segmentation` be suppressed without forking the class?
4. What does full-response buffering cost on origin for a representative page?
5. Which non-HTML surfaces render gated content today on Newspack sites?
6. How does the Edge Worker stay in sync with the `Vary_Cache` cookie format, especially in encrypted mode where it would need the site key?
7. Can `register_group()` and `set_group_for_user()` be called reliably before `send_headers` on every request path?
8. How many segments co-occur on one page in practice? This decides whether the per-segment `Vary` direction's variant count is acceptable.

## Future work

### Stepped segments (metering)

Anonymous metering (`anon`, `m1`, `m2`, `m3`, `wall`) is part of the wider project but not the focus of this spec. This section records how it can fit so the design here does not close the door on it.

**What a stepped segment is.** A segment whose value is one of a known list instead of a single "in" token. A reader on their second free article holds `meter=m2`. `Vary_Cache` already stores groups as name and value pairs, so the cookie format needs no change.

**What the API would add.**

```php
register_segment( 'meter', [ 'values' => [ 'anon', 'm1', 'm2', 'm3', 'wall' ] ] );

assign_reader_to_segment( 'meter', 'm2' );

$step = get_reader_segment_value( 'meter' );       // 'm2', or null for none
is_reader_in_segment( 'meter', 'wall' );           // optional exact-value check
```

**What stays the same.** Every read records the segment name in the consulted set, exactly as for boolean segments. The response signal still names segments, not values. The edge still derives the key from the named segments plus the reader's values, which it already has from the cookie. So neither the signal format nor the edge logic changes shape. Only the number of possible values per segment grows.

**What changes.**

- Variants per URL become the product of each consulted segment's value count (including `none`) rather than 2 to the power of the segment count. `subscriber` (2 states) and `meter` (6 states) give at most 12 copies of one URL, fewer in practice since metering only runs for non-subscribers.
- The value is written by an Edge Worker on cache HITs, because a HIT never reaches origin and the count must still advance. That makes the worker a cookie writer that must reproduce the `Vary_Cache` format exactly, including sort order and the version prefix, and in encrypted mode it would need the site key.
- The registry gains a `values` list so the edge can emit `none` and validate values.

**Left out of this spec.** The metering algorithm, the Edge Worker, the `anon` through `wall` sequence, and how metering interacts with crawler classification. Those belong to the metering work.

### Cached fragments

This slice never stores rendered gated HTML in the object cache, transients, or a block render cache. If that changes, a fragment produced by a render that read `subscriber` would be reused by a later request that reads nothing, and the dependency would be lost. Supporting it would need the fragment cache to store the consulted set alongside the HTML and replay it into the current response, which means an explicit "declare a dependency without reading" call on the API. That call is left out until there is a concrete need for it. The filter on the finalized set remains the only manual adjustment point for now.

## Standards

### Static analysis

- PHPCS with the WordPress and WordPress-VIP-Minimum rulesets, as on any VIP mu-plugin.
- A project sniff that flags direct calls to `Vary_Cache::is_user_in_group()`, `Vary_Cache::is_user_in_group_segment()`, and `Vary_Cache::get_groups()` outside the wrapper. This is the guardrail for the one-read-API rule.
- A sniff or lint check that flags `assign_reader_to_segment()` and `register_segment()` calls hooked later than `init`.

### Code standards

- WordPress PHP coding standards. Public functions are documented with `@since` and a contract version.
- Public function names are the contract. The placeholder names in this document (`register_segment`, `assign_reader_to_segment`, and so on) can change before the first release but not after.
- Errors are returned as `WP_Error` with wrapper-specific codes, so callers can distinguish "too late in the request" from "invalid segment" from "not registered."
- Debug output is gated on `WP_DEBUG` and never changes behavior.
- No em dashes in comments or docs.

### Coverage requirements

- Unit tests for every public function in the wrapper, including each `WP_Error` path and the `init` timing rule.
- Integration tests on a VIP test application for every output path listed under Use cases, asserting the response signal and the stored variant key.
- The adversarial cache-correctness suite from Finish line runs against every configured segment on the test site and is a merge gate.
- A worked-example test for the Newspack `register_rule()` bridge, since it is the one shipped adapter.

### Quality metrics

- Zero gated bytes in any non-entitled render across all configured segments.
- Edge cache hit rate on gated URLs within 5 percentage points of today's on the same URLs.
- Stored variants per URL bounded by 2 to the power of the number of consulted segments, never by the number of configured segments.
- Serialized cookie size under 4 KB with headroom, with a warning emitted before the limit.
- Origin time-to-first-byte and peak memory on buffered responses measured and recorded before and after, with an agreed ceiling.

## References

- VIP Cache Personalization API docs: https://docs.wpvip.com/caching/page-cache/customize-behavior/cache-personalization-api/
- `cache/README.md`: https://github.com/Automattic/vip-go-mu-plugins/blob/develop/cache/README.md
- `cache/class-vary-cache.php`: https://github.com/Automattic/vip-go-mu-plugins/blob/develop/cache/class-vary-cache.php
- RFC 9111 (HTTP Caching), section 4.1, on matching requests against stored responses with differing Vary fields.
