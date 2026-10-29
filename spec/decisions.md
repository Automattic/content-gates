# Miscellaneous

## Environment

- Minimum WordPress version: 7.0
- Minimum PHP version: 8.2

## Naming and namespace

- Plugin namespace: Use `Automattic\VIP\ContentGates`.
- Public function names: Functions live in the `Automattic\VIP\ContentGates` namespace, e.g., `Automattic\VIP\ContentGates\is_reader_in_segment()`. Callers import them with `use function`. This results in readable names and satisfies the prefix rule without a PHPCS exemption.
- Hook prefix (decided): `content_gates_*` (plural) for every action and filter.

## Segment model and identity

- Storage: Segments are custom post type records. No code-registered segments.
- Crawler segments: Maintained by VIP in `config/crawlers.json` in this repository, read by the Edge Worker build and by the plugin. Not customer-configurable. The plugin mirrors each one into a reserved, read-only segment record that appears in the editor; removal from the file marks the record inactive, never deletes it. Crawler values are injected per request and never written to the browser. Crawler segments are declared only when an editor assigns them to a Group, like any other segment (decided 2026-10-26, replacing auto-declaration). See the crawler section of [page-cache.md](page-cache.md).
- Type: A segment is boolean or a meter. A meter segment carries a limit (articles per period) and a period (entered in days, stored in seconds). These are the only per-segment settings. The Edge Worker writes meter state; see the metering section of [page-cache.md](page-cache.md).
- Identity: Segments are identified by an immutable key. The key is derived once at creation from the initial display name, is human-readable, matches `/^[A-Za-z0-9_-]+$/`, is limited to a maximum of 16 characters, and never changes when the display name changes. While post IDs are stable within a single WordPress instance, content can be migrated between instances which results in new post IDs. Keys are human-readable and stable. The key is the stored reference: block attributes, post meta, and the declared-set header carry keys (as cache group names), never post IDs. The PHP API accepts a key or a post ID and resolves a post ID to its key.
- Active vs. inactive: A post status of `publish` indicates an active segment, `draft` is inactive. New segments are active by default. Inactive segments do not appear in selection UI.
  - Inactive segments are never in the declared set. Block assignments to an inactive segment are dropped when the plugin computes the declared set, and `declare_segment()` returns `content_gates_inactive_segment`. Reads of an inactive segment therefore fail closed and return `false`, and the segment costs no cache variants. Assignment to an inactive segment is refused with `content_gates_inactive_segment`. Removal from an inactive segment is allowed so providers can clear stale cookies.
  - Unknown segment references return `false` or `null`, record nothing, and raise `_doing_it_wrong()` under `WP_DEBUG`. Only resolvable segments can be cache keys.
- Multisite: Supported. On a multisite install, a site-defined segment registers its `Vary_Cache` group as `{blog_id}-{key}`, for example `2-subscriber`, so one network-wide cookie cannot cross sites. Single-site installs use the bare key. The declared-set header carries group names, so the edge worker never needs to know about multisite. `Vary_Cache` accepts `[A-Za-z0-9_-]` in group names with no length limit, so the prefix fits. Meter segments are site-defined and prefixed like any other. Crawler segments are network-wide and unprefixed, because their values never persist in a browser cookie and so cannot cross sites.
- Object cached: All sites on WPVIP have persistent object cache. Segments should be cached there and invalidated whenever the segments are updated.

## Providers and REST

- API contract: Function signatures, errors, hooks, the timing window, and the versioning policy live in [api.md](api.md).
- Membership REST route: The plugin ships no membership assignment route. Providers that need HTTP assignment write their own authenticated endpoint that calls the PHP functions. The plugin cannot authenticate a reader who has no WordPress login; only the provider can.
- Segment listing route: An authenticated route for the editor to list active segments is allowed. It is not public. Anonymous callers may not list segment names or keys. The editor needs it; nothing else does.
- Post-redirect-get: The documented pattern for PHP providers: handle a POST on `init` after priority 10, assign, redirect on `vip_vary_cache_did_send_headers`, land on a GET that carries the cookie.

## Membership runtime

- Platform loading: `Vary_Cache` is opt-in on WPVIP. The plugin requires it from mu-plugins itself. Without it, outside a `local` environment, the plugin fails closed: reads return `false`, assignments error, gated Groups are withheld. A plaintext cookie adapter exists for `local` only and is never a production fallback.
- REST assignments: REST requests exit before `send_headers`, so the plugin triggers the `Vary_Cache` cookie write when it sends the declared header, in `rest_pre_echo_response` (see the timing corrections below). Providers' own routes need nothing extra.
- Declared-set filter: `content_gates_declared_segments` can add keys but never remove keys that came from block assignments or earlier declarations.

- The provider assigns membership on every origin request, for logged-in and anonymous readers alike. Login grants nothing.
- Provider writes (decided 2026-10-27, superseding "reassert on every request"): a provider sets membership once, when entitlement is established, and touches it again only to remove it. Ordinary page views never write, so every member view stays storable. Writes happen only on requests the edge forwards with the full cookie (POST, authenticated or logged-in, or no record yet); on a narrowed request the plugin refuses with `content_gates_narrowed_request`, since `Vary_Cache` would write the narrowed set back to the browser. Consequences accepted: the cookie lifetime is the only bound on a lapsed entitlement for a reader who never makes a full-cookie request; and a cookie that expires or is cleared is not restored by a page view, so an anonymous entitled reader needs a provider endpoint and a client nudge on gated pages (logged-in readers get it back on their next request if the provider acts there). The Newspack bridge therefore becomes event-driven (login, checkout, logout, cancellation) instead of evaluating rules on every request.
- Cookie lifetime: One day by default, filterable. Under the write rule above it is the lapse bound and the re-entitlement interval at once, so it is the provider's choice; a longer lifetime is a longer window for a lapsed or forged plaintext cookie.
- Removal of the last segment: `Vary_Cache` skips the cookie write when the serialized value is empty, so a reader removed from their last segment would keep the stale cookie and remain a member on the next request. Decided 2026-10-23 (superseding "wait for upstream"): the plugin expires the cookie itself on `vip_vary_cache_did_send_headers`, with the path and domain `Vary_Cache` used, and only after an actual removal in that request. The bundled copy of `Vary_Cache` is never edited. Updated 2026-10-28: upstream fixed the bug in [vip-go-mu-plugins #7454](https://github.com/Automattic/vip-go-mu-plugins/pull/7454) (on develop; not yet in the staging or production release). That `Vary_Cache` expires the cookie itself, with the same path and domain, so the plugin's expiry would be a second, identical `Set-Cookie`. The plugin now expires the cookie only when `Vary_Cache` lacks the fix. It detects the fix by the private `has_group_cookie()` that #7454 added, because there is no public signal. If upstream renames that method, the plugin sends the redundant expiry again, which is harmless. The bundled copy is re-copied from develop with #7454. Remove the fallback and the detection once production mu-plugins has #7454.
- Stepped values: Meter segments carry `open`, `last`, or `wall`, written only by the edge. `is_reader_in_segment()` on a meter segment is `true` for `open` and `last`. `get_reader_segment_value()` returns the stored value or `null`. Values match `/^[A-Za-z0-9_-]+$/`. Providers cannot assign a value to a meter segment. Metering, including the meter worker, is in the initial phase.
- Meter configuration: Lives on the segment record at origin and reaches the edge in a response header stored with each cached copy. Nothing is configured at the edge. Changing a limit or period requires the global purge already required for segment configuration changes.

## Timing corrections (2026-10-22, prototype)

- Core's `WP::main()` fires `send_headers` **before** the `wp` action. The spec placed declared-set finalization on `send_headers` and declarations on `wp`, which cannot both hold. Decided: the plugin finalizes at the end of `wp` (priority `PHP_INT_MAX`); declarations stay on `wp`, exactly as the spec's examples show. Membership writes still close at `send_headers`, where `Vary_Cache` writes the cookie. Both hooks run before any output.
- REST responses finalize in `rest_pre_echo_response` rather than `rest_pre_serve_request`, because `_embed`ded posts render after the latter; `Vary` is sent on every REST response the plugin finalizes, since `Vary_Cache` never sees `send_headers` there and a REST response with gated content and no `Vary` would be cached once for everyone.
- Meter headers are sent on singular pages only. Feeds and REST responses declare the meter (so the key covers the state) but are not article views and are not counted.

## Corrections (2026-10-23, prototype review)

Defects confirmed in the prototype and fixed. Each has a regression test.

- Membership writes are idempotent. `assign_reader_to_segment()` with the value the reader already holds, and `remove_reader_from_segment()` for a group the reader is not in, return `true` and write nothing. Before this, every reassert wrote `Set-Cookie`, and a response with `Set-Cookie` is never stored, so a provider that reasserts on every request made every entitled view a cache miss.
- Removing the last group expires the cookie (see "Membership runtime" above).
- Segment mutations need `manage_options` on every path. The `cg_segment` post type used `capability_type => post`, so an editor could rename a segment or flip its status through XML-RPC or `wp_update_post()`, and the purge fired only from the registry's own methods. The post type now maps its primitive capabilities to `manage_options` (deletion to `do_not_allow`), and status and meter changes are announced from `transition_post_status` and `updated_post_meta`, so any path that persists a change requests the purge. `Cache_Purge` collapses the events of one request into one purge.
- Phase 3 fails closed without its markers. The worker treated a missing lookup marker as a full lookup, which counts as covered. Had `resp.request` not carried the markers on the platform (an open question), every narrowed response would have been stored unchecked. Missing or malformed markers now mean `no-store` and no record write.
- Phase 4 lets origin's membership decision win. The worker built the final `vip-go-seg` from the request cookie and appended a second `Set-Cookie`, so on a metered miss where a provider assigned or removed a group the browser kept the worker's copy and origin's decision was lost. The base is now origin's `vip-go-seg` field on the response when present (an expiring one means no groups), else the request cookie; the worker writes exactly one `vip-go-seg` and leaves other cookies alone.

## Diagnostics (2026-10-23)

- Edge Workers expose no logs, so the worker reports each decision on the response: `X-WPVIP-Content-Gates-Edge-Coverage` from phase 3 (stored with the copy) and `X-WPVIP-Content-Gates-Edge-Meter` from phase 4 (per delivery). Origin echoes the worker's request markers in `X-WPVIP-Content-Gates-Edge-Received`. Comparing the echo with the coverage header localizes a failure to phase 1, the request snapshot, or the record.
- Always on, not gated. A request-gated header fails exactly when the request snapshot is the thing under test. A deployment-gated header makes the live evidence depend on configuration and invites the question of whether it was on. The values are low cardinality and reveal nothing the declared header does not. Revisit if a cost appears.
- These headers are the evidence format for the live validation scenarios; the edge test harness reads the same names so local and live runs read alike.
- Reduced 2026-10-26 to one worker header. What a reader needs is the declared set, the key the request resolved to, and whether origin answered sanely. The declared header gives the first; `X-WPVIP-Content-Gates-Key` (phase 3, stored with the copy) gives the second; a segmented response with no declared header is the third. Cache status comes from the platform and membership from the cookie, so the coverage reason, origin's marker echo, and the meter report were redundant and were removed. Headers are added back one at a time when a live question cannot be answered from these.

## Crawler assignment (2026-10-26)

- Crawler segments are no longer declared automatically. The prototype added every crawler segment to the declared set of any singular post with a non-empty declared set, so a post gated on `subscribers` alone declared `crawlers-ai` and `crawlers-search` too. Now a crawler segment is in the declared set only when an editor selects it on a Group, exactly like a reader segment. A post whose Groups name no crawler segment serves crawlers the anonymous copy and emits no paywall structured data.
- Why: the declared set is the editor's statement of who may read the post. An implicit entry that the editor never chose was surprising in the response header and in the admin, and it made "crawlers get the full article" a plugin default rather than an editorial decision. Editors who want crawlers to read a Group add the crawler segment next to the reader segment; the editor control already lists crawler segments with a note.
- Future: grouping segments, so an admin can declare that assigning `subscribers` also assigns the crawler segments, would restore the convenience under admin control. Recorded in [open-questions.md](open-questions.md).

## Worker scope (2026-10-23)

- The worker's `worker.json` has no `location`: any URL may serve gated content, and the platform's only pre-invocation condition is one path rule with no negation. Scoping by path would silently drop narrowing, metering, and crawler classification on every URL outside the rule.
- The "except" list is inside phase 1 instead: non-GET/HEAD, `Authorization`, admin and login paths, `/wp-content/` and `/wp-includes/`, static file extensions, and logged-in or no-cache session cookies. These responses can never be stored under a segmented key, so skipping the KV read and the rewrites costs nothing and saves one KV read per asset. The request is marked `bypass`; phase 3 never stores a bypassed response and phase 4 never meters one.
- The forwarded cookie is narrowed (2026-10-27). WPVIP derives the cache key's `X-VIP-Go-Segmentation` from the cookie the worker forwards and ignores the header the worker sets, observed live. So phase 1 narrows the `vip-go-seg` value it forwards to the reader's values for the recorded groups plus its crawler classification; the browser's cookie is untouched. Origin reads only declared groups, so a group missing from a narrowed cookie reads false and the coverage check refuses to store the response when the record was stale. The platform request to honor a worker-set header stands; the day it lands, the cookie rewrite becomes unnecessary.
- Phase 4 meters only 200 responses (2026-10-27). The canonical redirect from `?p=20` to the permalink carries the declared and meter headers like the page does, so the worker counted a view that delivered nothing; a reader at `last` would have reached `wall` on the redirect and met the gate on the article. A status check in phase 4 is simpler than teaching origin which responses are redirects.
- `on_failure` is `error`: a worker that fails after phase 1 narrowed the key must not let the response be stored unchecked. Whether the policy is per phase is a platform question.

## Caching

We assume that membership providers are external systems that require interaction over HTTP. However, since customer code calls our API to add or remove users from a segment, we expect them to implement their own caching.

## Editor UI

- Withheld Group: A Group whose reader is not entitled is suppressed entirely: no markup, no wrapper, and no extra line break or spacing in the surrounding output. The plugin never renders replacement markup. The spec's "excerpt and a prompt" use case is met by the toggle below, not by the plugin.
- Visibility toggle: The Group panel gains "Show to: readers in segment / readers not in segment". A subscribe prompt is a second Group set to "not in subscriber". A negated assignment puts the segment in the declared set exactly like a positive one. Editors place prompts without provider code. No AND/OR rule builder.
- Multiple segments per Group: A Group may list more than one segment, for example subscriber and crawler. A crawler must receive the same gated content as a subscriber.
- Multi-segment semantics: "In segment" with several segments means the reader is in any of them. "Not in segment" means the reader is in none of them. Every listed segment is in the declared set. OR is the only semantics that makes "subscriber and crawler" work.
- Who may assign: Any user with `edit_post` on the post may assign segments to a Group. Segment keys are validated on save; an unknown key is rejected, not silently cleared. On the same save the plugin computes the post's declared set from its assignments and stores it in post meta. Matches core block editing permissions.
- Preview: Previews obey membership. There is no capability-based bypass on the front end, including for administrators. A bypass would hide assignment mistakes from the people who need to see them. Debugging tools can allow preview through different logic gates.
- Debugging / View-as switcher: Users with `edit_posts` (the primitive capability, since archives have no post to check `edit_post` against) get an admin bar control on every front-end page that picks a segment set for that view. The switch applies via reload. Any response rendered under the switcher is `Cache-Control: no-store` and never reaches the edge. Editors must verify every reader view.
- Manual excerpts: A manual excerpt is public editorial text and the author's responsibility. The plugin never regenerates it.

