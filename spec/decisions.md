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
- Crawler segments: Maintained by VIP in `config/crawlers.json` in this repository, read by the Edge Worker build and by the plugin. Not customer-configurable. The plugin mirrors each one into a reserved, read-only segment record that appears in the editor; removal from the file marks the record inactive, never deletes it. Crawler values are injected per request and never written to the browser. The plugin auto-declares crawler segments on singular posts with a non-empty declared set. See the crawler section of [page-cache.md](page-cache.md).
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

- The provider assigns membership on every origin request, for logged-in and anonymous readers alike. Login grants nothing.
- Cookie lifetime: One day by default, filterable. Providers re-assert membership on every origin request. The maximum time a lapsed subscriber keeps the entitled view is the cookie lifetime. A short TTL needs no refresh endpoint and no client script.
- Removal of the last segment: No workaround. `Vary_Cache` skips the cookie write when the serialized value is empty, so a reader removed from their last segment keeps the stale cookie until it expires. The wrapper documents this gap and the plugin files the upstream bug. Wait for the upstream fix rather than hand-write the cookie. The one-day TTL bounds the exposure.
- Stepped values: Meter segments carry `open`, `last`, or `wall`, written only by the edge. `is_reader_in_segment()` on a meter segment is `true` for `open` and `last`. `get_reader_segment_value()` returns the stored value or `null`. Values match `/^[A-Za-z0-9_-]+$/`. Providers cannot assign a value to a meter segment. Metering, including the meter worker, is in the initial phase.
- Meter configuration: Lives on the segment record at origin and reaches the edge in a response header stored with each cached copy. Nothing is configured at the edge. Changing a limit or period requires the global purge already required for segment configuration changes.

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

