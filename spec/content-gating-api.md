# Spec: Content Gating API

Status: draft for investigation. This document describes the API surface and the contract between WordPress and the VIP edge cache. It does not prescribe an implementation.

Prototype note: the local implementation maps a segment post ID to `cg-<ID>`, implements boolean membership contract version `1`, and emits provisional `X-Content-Gates-Vary` and debug headers after buffered rendering. It uses WPVIP `Vary_Cache` when available and a local-only compatibility adapter otherwise. These choices enable origin-level verification; they do not settle or claim VIP edge support for the signal.

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

**The catch.** The report can only be written after rendering, because that is the only time we know which groups were checked. `Vary_Cache` sends its cache instructions before rendering starts. Closing that gap needs changes in how WordPress sends the response and in what the edge does with it. This document says what those changes must achieve, not how to build them. This and the other hard problems are listed under Risks & Unknowns.

**Terms.** A **segment** is a named group readers can be gated against (one `Vary_Cache` group). In this spec a reader is either in a segment or not. The cookie records a fixed token (`1`) for "in", and the API treats "not in" as a distinct state called `none`. Segments with several possible values, such as metering steps, are described under Future work and are not the focus here. **Membership** is all values a reader holds, carried in the cookie. The **consulted set** is the segment names one response read while rendering. The **variant key** is membership restricted to the consulted set, including `none` entries, and is what the cache should key on.

## Use cases

### Reader use cases

**An anonymous reader opens a gated article.** They see the headline, the excerpt, and a prompt to subscribe. The rest of the article is not in the page at all: not hidden, not in the page source, not visible with JavaScript off. The page arrives from the edge as fast as any other page, because every anonymous reader shares the same copy.

**A reader subscribes.** The publisher's entitlement provider puts them in the `subscriber` segment and sets the cookie. From the next page load, every gated article shows in full. The first article they open after subscribing may be a little slower, since no cached copy for subscribers exists yet. After that, all subscribers share it.

**A reader belongs to many groups.** Someone who is a subscriber, a newsletter member, and a donor gets the same copy of an article as a plain subscriber. Being in extra groups never makes their pages slower or produces a separate copy, unless a page actually looks at one of those groups.

**A subscriber lets their subscription lapse.** The provider removes them from the segment on their next request that reaches origin. From then on they see the anonymous view. Nothing has to be purged from the cache, and no cached copy is tied to a person, only to a combination of segment values.

### Publisher use cases

**An editor creates a segment and gates content with it.** Using the existing gate-builder UI, they add "Subscribers", which registers a `subscriber` segment with a stable ID. In the block editor they mark a block, or the whole post below a point, as visible to Subscribers only. On publish, non-subscribers get a render with that content omitted. The editor can confirm it by viewing the page logged out and viewing source.

**A publisher connects an entitlement provider.** Newspack ships with a bridge from its `register_rule()` to the assignment contract. A publisher on another system (WooCommerce memberships, a third-party paywall) writes a small adapter against the same documented, versioned contract and does not need to touch the cache layer.

**A publisher checks that paid content does not leak.** They, or an automated test, fetch each gated URL as an anonymous reader and search the response for gated text. The expected result is zero matches for every segment. This is the headline acceptance test for the project.

**A publisher renames or removes a segment.** Renaming or removing a segment invalidates every stored copy that depended on it and strands existing cookies. Segment IDs are stable identifiers, not labels. Changing which content a segment gates purges dependent copies. Changing a reader's membership purges nothing: the reader simply projects to a different key.

### Technical use cases

#### A provider puts a reader into a segment

Newspack, WooCommerce, or a third party decides a reader is entitled and calls `assign_reader_to_segment()` from PHP before the `send_headers` hook. The wrapper writes through to `Vary_Cache::set_group_for_user()`, which sets the `vip-go-seg` cookie (or `vip-go-auth` when encrypted) at `send_headers`:

```
vc-v1__<group>_--_<value>---__<group>_--_<value>
```

Names and values allow only `A-Z a-z 0-9 _ -`. Groups are sorted by name. Empty values are dropped, which is how removal works.

Because a cache HIT never runs PHP, assignment during a cacheable GET only takes effect on a request that reached origin. The reference flow is the POST-redirect-GET pattern in VIP's README: handle the POST on `init`, assign, then redirect from `vip_vary_cache_did_send_headers`.

A provider running in the browser calls an uncacheable endpoint that performs the same assignment and returns the cookie before any redirect.

#### The edge puts a reader into a segment

Crawler classification runs in a VIP Edge Worker, because a cache HIT never reaches origin. The worker writes the same cookie, in the same format, as a second writer alongside `Vary_Cache`. Anonymous metering will use the same mechanism later (see Future work).

#### A template author gates content

Theme, block, shortcode, and widget code calls `is_reader_in_segment()` and branches on the answer. Gated content is omitted from the HTML for readers who are not entitled. Every call records the segment in the consulted set, whether or not the reader was in it and whether or not anything was shown.

#### A request flows through the edge and origin

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

#### Non-HTML responses

Archives and search (each post in the loop may consult segments), RSS and Atom feeds, oEmbed, REST responses with rendered content, and anything reading membership from a global hook like `wp_head` all go through the same flow. Each output path needs the finalization step to fire.

#### An engineer debugs or tests variance

With `WP_DEBUG` on, origin emits a header with the consulted set and variant key next to `Vary_Cache`'s existing `X-VIP-Go-Segmentation-Debug`, which carries full membership. Comparing the two shows exactly what was narrowed.

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
- Storing rendered gated HTML in the object cache, transients, or a block render cache. Fragment caching of gated content is out of scope for this slice (see Risks & Unknowns).
- Keying a stored copy on a set learned from a different render of the same URL.
- Assuming HTTP trailers work end to end.
- Rendering gated content and hiding it client-side. The whole point is that it never leaves origin.

## Risks & Unknowns

Each item is a problem that has to be solved, or a fact that has to be established, before the API above can be built with confidence. The last column says what would settle it.

| Risk or unknown | Why it is hard | What settles it |
| --- | --- | --- |
| **The signal comes after render.** The consulted set is only known once templates and blocks have run, but `Vary_Cache` sends its cache instructions at `send_headers`, before `template_redirect`, and PHP cannot add a header once body output has started. | The practical fix is to hold the whole response body until render finishes. That costs memory and time-to-first-byte on origin requests, can collide with other output buffering on the site, and breaks if a theme calls `flush()` or exits early (redirects, `wp_die()`, 404 handling). HTTP trailers would avoid buffering but are not reliably supported by PHP SAPIs or CDNs. | Measure buffering cost on a representative page. Enumerate early-exit paths and define what the signal is on each. Confirm trailers are not an option. |
| **`Vary_Cache` sends the broad `Vary` header on its own.** It emits `Vary: X-VIP-Go-Segmentation` whenever any group has a value, and it loads every group from the cookie whether or not it was registered. | That header is exactly the full-cookie keying we want to escape. Suppressing it means unhooking `send_headers` and re-implementing cookie writes, or asking the edge to ignore it, or getting a small upstream filter. | Decide whether an upstream change to `Vary_Cache` is possible. If not, prove the edge can prefer the new signal over the old header. |
| **Edge capabilities are unverified.** Both candidate directions depend on what the VIP CDN and Edge Workers can do. | Direction A needs `Vary` honored on custom header names and a known cap on variants per URL. Direction B needs per-URL state at the edge with acceptable consistency. Neither has been checked. | Ask VIP, then build a minimal test on a VIP test application for whichever direction looks viable. |
| **Unreported reads poison the cache.** Any code that branches on membership without going through the wrapper produces an under-reported set, and the edge then serves one reader's page to another. | `Vary_Cache` has no hook on reads, so the wrapper cannot observe direct calls. Newspack's own `Content_Gate` checks, third-party plugins, and theme code all have to be routed through the wrapper. A sniff catches first-party code but not arbitrary plugins. | Static analysis for first-party code, a debug-mode log of direct `Vary_Cache` reads, and a bridge so Newspack's restriction checks go through the wrapper. Accept and document the residual risk from third-party code. |
| **Fragment caches hide reads.** A fragment rendered once and stored in the object cache, a transient, or a block render cache was produced by a render that read `subscriber`. A later request reuses it without reading anything, so the dependency is lost. | The wrapper only sees reads that happen during the current request. Themes and plugins already cache fragments today, and nothing stops one from caching gated markup. The fix would be a way to record a dependency without a read, which this slice deliberately leaves out of the API. | Audit the test site and Newspack for fragment caching around gated content. Keep gated HTML out of fragment caches in this slice. If that proves impractical, add an explicit dependency call and have fragment caches store and replay the consulted set. |
| **Per-render consulted sets are subtle.** A gate nested inside another gate only runs for readers who pass the outer one, so two renders of one URL report different sets. | Safe only if each stored copy is keyed on the set its own response reported. An edge design that keys on a per-URL manifest learned from other renders breaks this. Easy to get wrong and hard to notice without a targeted test. | The nested-gate integration test under Finish line. Work the case through on paper for whichever edge direction is chosen. |
| **Every output path needs the finalization step.** Feeds, oEmbed, REST responses with rendered content, archives, and search each have their own output lifecycle. | REST sends JSON through its own serving hooks, feeds use their own templates, and some paths may not pass through `template_redirect` at all. Missing one path means gated content keyed on the wrong set there. | Inventory which non-HTML surfaces render gated content today on Newspack sites. Add an integration test per path. |
| **The registry must load before `send_headers` on every path.** Each segment that might be read has to be registered with `Vary_Cache::register_group()` first. | REST, admin-ajax, cron, and feeds reach `send_headers` on different hooks and at different times. Lazy registration on first read is too late. | Confirm `register_group()` and `set_group_for_user()` can be called reliably before `send_headers` on each path. This is the unresolved engineering-validation question for the whole approach. |
| **Reads from global hooks fragment every page.** A membership check in `wp_head`, `wp_footer`, or a sitewide widget adds that segment to every response. | Correct, but it means a site-wide "subscriber" check in the header doubles the variant count on every URL, including pages with no gated content. The win from narrow keying disappears. | Document the cost. Audit the test site for global reads. Consider whether a global read should warn in debug mode. |
| **Two writers of one cookie.** The Edge Worker (crawler now, metering later) writes the same cookie `Vary_Cache` writes. | The worker must reproduce the exact format: version prefix, sorted group order, allowed characters. In encrypted mode it would also need the site key and the signed expiry. Format drift silently splits the cache. | Share one serializer or one conformance test between PHP and the worker. Decide whether encrypted mode is supported at all for edge-written segments. |
| **Cookie size.** Full membership still travels in one cookie, shared with every other cookie on the domain. | Around 20 bytes per boolean segment means 100 segments is about 2 KB against a 4 KB limit, before encryption overhead. | Set a recommended maximum in the provider contract and warn before the limit. Get a real number for segments per reader. |
| **Real cardinality is unknown.** No one has measured how many segments co-occur on one page in practice. | This decides whether direction A's variant count per URL is acceptable, and whether the hit-rate target is achievable. | Ask Newspack engineering for real usage. Measure on the test site. |
| **Migration from today's keying.** Enabling narrow keying while copies keyed on the full cookie still exist at the edge. | Old copies are keyed on a different header, so they will not be matched by the new scheme, but they occupy the cache and may be served until they expire if the old `Vary` header is still present on some paths. | Purge on cutover. Confirm no path still emits the old header afterwards. |
| **Assignment only takes effect at origin.** A provider assigning membership during a cacheable GET sees nothing happen on a HIT. | Providers used to running on every request will be surprised. The redirect flow on `vip_vary_cache_did_send_headers` is the only reliable pattern. | State this plainly in the provider contract with the worked Newspack bridge as the example. |

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
