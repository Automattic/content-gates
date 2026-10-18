# Known limitations

An honest list. Each item says what is true of the code as written on
2026-10-18 and what would close it. Nothing here is hidden behind a
passing test suite.

## Not verified on WPVIP

None of the following has run on a VIP application. The PHPUnit suite
uses the plugin's own local cookie adapter and a fake `Vary_Cache`.

| Item | What the code does | What is unverified |
| --- | --- | --- |
| Broad `Vary` header | The local adapter never sends `Vary: X-VIP-Go-Segmentation`. The `Vary_Cache` adapter does nothing about it. | On VIP, `Vary_Cache` still sends that header whenever any group has a value. The response then carries both the broad header and `X-Content-Gates-Vary`. Whether the VIP edge can be made to prefer the narrow signal, or whether `Vary_Cache` needs an upstream filter to suppress its header, is open. Until then, VIP keys on the full cookie, which is the status quo the project exists to fix. |
| Edge honoring `X-Content-Gates-Vary` | Origin sends it after render. The edge simulator keys on it. | No VIP edge component reads this header today. The spec's two candidate edge designs are both undecided. |
| Hit rate | Not measured. | The spec requires gated URLs to stay within 5 percentage points of baseline on the same URLs. No workload, baseline, or result exists. |
| Purge | Not implemented. | Segment deactivation, reactivation, rename, or Group reassignment purges nothing. Stale entitled variants could stay in the edge until TTL. The edge simulator has a manual purge endpoint; nothing calls it. |
| Encrypted cookie mode | Not supported by the local adapter. The `Vary_Cache` adapter forwards calls, so on VIP encryption is whatever `Vary_Cache` does. | Behavior under `vip-go-auth` encryption is untested. The edge simulator ignores `X-VIP-Go-Auth`. |
| Registration before `send_headers` on every path | `init` 1 registration and the `send_headers` `PHP_INT_MAX - 1` too-late mark are unit tested. Page, REST, feed, embed, and listing paths have Playwright assertions of the signal header against the local adapter. | The spec calls reliable registration and assignment before `send_headers` on REST, admin-ajax, cron, and feeds "the unresolved engineering-validation question". Admin-ajax and cron are not exercised, and nothing has run against the real `Vary_Cache`. |
| Buffering cost | Full-response buffering from `template_redirect` to `shutdown`. | Time-to-first-byte and peak memory are not measured. |
| Crawler structured data | JSON-LD with `isAccessibleForFree` and `hasPart`/`cssSelector` is emitted and unit tested for shape. | Not submitted to Google's Rich Results Test. |
| `core/latest-posts` full-post mode | On WordPress 7.1.3 the block renders post content through `do_blocks()`, so the enforcer applies. | Verified on 7.1.3 only; behavior on the 6.6 floor not verified, since older versions may have rendered raw `post_content` on this path. |

## Deliberate scope cuts

| Cut | Reason and status |
| --- | --- |
| Anonymous metering and the edge worker | Out of scope for this run. No `anon`/`m1`/`m2`/`m3`/`wall` sequence, no worker, no `values` list on `register_segment()`. The cookie format and the consulted-set rule leave room for it: `assign_reader_to_segment()` already accepts a value and `is_reader_in_segment()` an exact-value check. |
| Newspack `register_rule()` bridge | Not shipped. `newspack-workspace` was not available in this run, so the bridge could not be written or tested against the real hooks. The test provider is the only adapter. The spec lists the bridge as the one required first-party adapter; it is deferred, not dropped. |
| Encrypted cookie mode | See above. |
| Purge tooling | See above. |
| Fragment caches | No dependency-recording API exists. The plugin itself caches no gated HTML (see the [leak-surface matrix](leak-surface-matrix.md)). Themes and plugins that cache fragments of gated content hide the reads that produced them, and the plugin cannot detect this. |
| Surfaces beyond page, REST, and feeds | Triage status (details in the [leak-surface matrix](leak-surface-matrix.md)): **oEmbed** and the **embed template** have a Playwright assertion of zero gated bytes (`leak-surfaces.spec.ts`). **AMP** is not applicable: no AMP plugin is installed or integrated. **Head metadata** (Open Graph, meta description) comes from SEO plugins that may read raw content; not covered, and the tests site has no SEO plugin. **Sitemaps** list URLs and dates only by core's documented behavior; not covered by a test. **Listing teasers**: `core/post-excerpt` reads `get_the_excerpt()` and is covered through the guard; `core/latest-posts` with "display post content" renders through `do_blocks()` on WordPress 7.1.3, so the enforcer applies there; verified on 7.1.3 only, behavior on the 6.6 floor not verified. **Search as an oracle**: searching for gated text still matches the post, so a reader can learn whether a term exists in gated content without seeing it. |
| Raw content in themes | Themes that call `get_the_content()` and echo it, or run `wp_trim_words()` over `post_content`, bypass block rendering and leak. Only `the_content` (which runs `do_blocks()`) and `get_the_excerpt()` are protected. |
| Template parts, widgets, shortcodes | No visual control. Developers gate them with `is_reader_in_segment()`. A sitewide read (header, footer) adds that segment to every response's consulted set and doubles the variant count on every URL. |
| Block types other than `core/group` | Editors wrap content in a Group. The attribute exists only on `core/group`. |
| Static analysis guardrails | The spec asks for a sniff that flags direct `Vary_Cache` reads and late `assign_reader_to_segment()` calls. Neither exists. |

## Behaviors stakeholders should confirm

These are choices the prototype made where the spec left room. Each
could be reversed; some would change the contract.

- **Archives and search vary on the union of every listed post's gates.**
  A listing page runs the excerpt guard for each post, so its consulted
  set is the union of all gated segments on that page. After the e2e
  suite, the home page reported nine keys, which allows up to 2^9 stored
  copies of one URL. This is correct keying, but it is the cardinality
  risk the API spec flags, and archives and search will hit it first.
  Options to confirm: cap the number of gated posts per listing, exclude
  gated posts from teasers, or accept the variant count after measuring
  real co-occurrence.
- **Unprefixed public function names.** `register_segment`,
  `assign_reader_to_segment`, `remove_reader_from_segment`,
  `is_reader_in_segment`, `get_reader_segment_value` are global functions
  without the `content_gates_` prefix, as the spec's placeholder names
  had them. A one-line PHPCS exclusion in `inc/functions.php` allows
  this. The spec says the names can change before the first release and
  not after. Confirm them now.
- **Every denied page varies on `crawler`.** The crawler read happens
  after any denial, so every response that withheld a Group is keyed on
  `crawler` as well as the gate's segment. That doubles the variant count
  on gated URLs (at most 2 to the power of 2 per gate instead of 2). The
  alternative, a separate crawler path at the edge, was not designed.
- **Inactive segments fail closed.** Deactivating a segment withholds its
  Groups from everyone, including readers whose cookie still carries the
  value, and refuses assignment and removal. The read is still recorded,
  so the response varies on a segment nobody can be in. Reactivation
  restores access without any change to content or cookies.
- **Inactive segments are still registered as cache groups** at `init`
  1, so their cookie values are preserved across the inactive period.
- **Early-exit paths.** `finalize()` runs on `shutdown`, which PHP fires
  after `exit`. A `wp_die()` or `wp_redirect(); exit;` after
  `template_redirect` is therefore still finalized and its buffered body
  released. A redirect before `template_redirect` has no buffer and
  normally no reads, so no header. None of this is tested. Themes that
  close all output buffers or call `flush()` cause the signal header to be
  skipped silently; the plugin logs nothing when that happens. On VIP the
  broad `Vary_Cache` header would still protect such a response; on a
  local site with the simulator the copy would be stored for everyone.
- **Admin-ajax is not finalized.** The finalizer skips buffering when
  `wp_doing_ajax()`. An admin-ajax handler that renders gated content for
  an anonymous reader gets no signal header. The block enforcer still
  withholds, so no bytes leak; only the variance signal is missing.
- **Preview exception scope.** The only bypass of withholding is
  `is_preview()` together with `current_user_can( 'edit_post', $post_id )`
  for the post being rendered. The post ID comes from `get_the_ID()` or
  the parent block's `postId` context; with no post ID the exception does
  not apply. Logged-in administrators who are not previewing are withheld
  like anyone else.
- **Local adapter variances from `Vary_Cache`.** Listed in the
  `Local_Cookie_Adapter` class docblock: never sends the broad `Vary`
  header; sends `X-Content-Gates-Simulation: local`; expires the cookie
  when every group is removed (real `Vary_Cache` leaves it); a late
  `register_group()` keeps an already-parsed value (real `Vary_Cache`
  resets it). Also: no encryption, and the `content_gates_cookie_expiry`
  filter exists only locally.
- **REST `GET /membership` is public** and lists every segment, active
  and inactive, with its ID, key, and name, plus the reader's raw cookie
  state. Confirm that exposing segment names and keys to anonymous callers
  is acceptable.
- **Response signal format.** `X-Content-Gates-Vary` (plural) with
  comma-and-space separated sorted keys, plus `X-Content-Gates-Key` under
  `WP_DEBUG`. The spec's draft used the singular name. The final name and
  shape depend on the edge design.
- **Rename does not purge.** The spec says renaming a segment invalidates
  dependent copies; the prototype keeps the key immutable so a rename
  changes nothing at the edge. Confirm that this is the intended
  interpretation.

## Accepted risks carried from the spec

From `spec/segment-admin.md`, restated so release documentation carries
them:

- **Cookie forgery.** Membership lives in a client cookie. On VIP,
  `vip-go-auth` uses AES-128-CBC with a static per-site key and IV and no
  MAC, and plaintext `vip-go-seg` may be accepted alongside it. Locally
  there is no encryption at all. A reader who writes `subscriber_--_1`
  into the cookie receives the subscriber variant. The same applies to
  `crawler_--_1`, which yields the full-content crawler variant. Accepted
  for this slice; this is not a tamper-proof entitlement system.
- **Crawler UA spoofing.** Crawler classification (when it exists at the
  edge) will be user-agent based. A spoofed crawler user agent receives
  the crawler variant with full content. An unrecognized crawler receives
  the stripped anonymous view. This qualifies the zero-leak claim.
- **First metered visit.** When metering exists, a first-time reader sees
  the gate one request earlier than in Newspack today, because granting
  metered access needs a round trip. Not applicable yet: metering is not
  implemented.
- **Configuration invalidation.** Segment and provider-rule changes need
  a purge across every affected URL and variant. No purge exists in this
  prototype.
