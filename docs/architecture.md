# Architecture

How the prototype is put together and how a request moves through it.
Everything here describes the code in `inc/`, `assets/`, and
`tests/debugging/` as written. Claims about the WPVIP edge are
assumptions until tested on a VIP application.

## Components

`ContentGates\Plugin::boot()` (`inc/class-plugin.php`) constructs every
component in one place and calls its `register()` method. Nothing runs at
construction time. The boot order is the order below.

| Component | File | Hooks | Job |
| --- | --- | --- | --- |
| `Segments\Segment_Registry` | `inc/segments/class-segment-registry.php` | `init` 0, `wp_insert_post_data`, `pre_trash_post`, `pre_delete_post`, `map_meta_cap`, `save_post_cg_segment`, `clean_post_cache` | Stores CPT segments, holds code segments, resolves references, blocks deletion. |
| `Admin\Segment_Admin` | `inc/admin/class-segment-admin.php` | `admin_menu`, `admin_post_content_gates_segment` | Settings > Content Gates: list, create, rename, activate, deactivate. Plain forms, no JavaScript, no delete control. |
| `Membership\Local_Cookie_Adapter` or `Membership\Vary_Cache_Adapter` | `inc/membership/` | Local: `init` 10, `send_headers` `PHP_INT_MAX`. VIP: `send_headers` `PHP_INT_MAX - 1`. | The only door to the segmentation cookie. Chosen at boot by `class_exists( Vary_Cache )`. |
| `Membership\Consulted_Set` | `inc/membership/class-consulted-set.php` | none | Records every segment key a response read. Freezable. |
| `Membership\Membership` | `inc/membership/class-membership.php` | `init` 1, `send_headers` `PHP_INT_MAX - 1` | The one read and write API. Validates, records reads, marks the request too late. |
| `Rendering\Response_Finalizer` | `inc/rendering/class-response-finalizer.php` | `template_redirect` `PHP_INT_MIN`, `shutdown` 0, `rest_post_dispatch` `PHP_INT_MAX` | Buffers the body, computes the consulted set after render, sends the signal headers. |
| `Rendering\Headers` | `inc/rendering/class-headers.php` | none | `header()` wrapper that records what it sent so tests can read it. |
| `Rendering\Block_Enforcer` | `inc/rendering/class-block-enforcer.php` | `register_block_type_args`, `pre_render_block` 10, `render_block` 10 | Registers `contentGatesSegmentId` on `core/group` and decides allow, withhold, or crawler per Group. |
| `Rendering\Block_Stripper` | `inc/rendering/class-block-stripper.php` | none | Removes withheld Groups from serialized block content, for paths that never render the Group. |
| `Rendering\Excerpt_Guard` | `inc/rendering/class-excerpt-guard.php` | `get_the_excerpt` 9 | Builds auto-excerpts from stripped content. |
| `Rendering\Crawler_Markup` | `inc/rendering/class-crawler-markup.php` | `wp_head` 10 | JSON-LD paywall markup on the crawler variant. |
| `Editor\Editor_Assets` + `assets/editor.js` | `inc/editor/class-editor-assets.php` | `enqueue_block_editor_assets` | The Segment dropdown in the Group block inspector. Plain script, no build. |
| `REST\Membership_Controller` | `inc/rest/class-membership-controller.php` | `rest_api_init`, `rest_request_after_callbacks` | `content-gates/v1/membership`. |
| Public functions | `inc/functions.php` | none | The five contract functions plus `content_gates_membership()`. |

The plugin fires `content_gates_loaded` at the end of `boot()`.

## Request flows

### Cold page request (edge MISS)

```mermaid
sequenceDiagram
    participant R as Reader
    participant E as Edge (VIP or edge-sim)
    participant O as Origin (WordPress + plugin)
    R->>E: GET /article-1  Cookie: vip-go-seg=vc-v1__newsletter_--_1
    E->>E: no stored copy matches
    E->>O: GET /article-1  X-VIP-Go-Segmentation: vc-v1__newsletter_--_1
    Note over O: init 0: register cg_segment CPT
    Note over O: init 1: Membership registers every key as a cache group
    Note over O: init 10: adapter parses the header/cookie
    Note over O: send_headers MAX-1: writes now return too_late
    Note over O: send_headers MAX: adapter writes cookie if changed
    Note over O: template_redirect MIN: ob_start()
    Note over O: render: each gated Group calls is_in(); every read is recorded
    Note over O: shutdown 0: freeze set, filter, action, headers, flush body
    O-->>E: 200  X-Content-Gates-Vary: subscriber  body without the Group
    E->>E: store under /article-1 + {subscriber=none}
    E-->>R: 200
```

Step by step on origin:

1. `init` 0: the registry registers the `cg_segment` post type.
2. `init` 1: `Membership::register_groups()` registers every known key
   (CPT segments active and inactive, plus code segments) with the
   adapter. Inactive segments are registered too, so a stale cookie value
   for them is parsed and kept, and so a read of them can be recorded.
3. `init` 10: the adapter parses `X-VIP-Go-Segmentation` (or the cookie).
4. Providers that use the post-redirect-get flow assign on `init` 11+.
5. `send_headers` `PHP_INT_MAX - 1`: `Membership::mark_headers_sent()`.
   From here `assign()` and `remove()` return `content_gates_too_late`.
6. `send_headers` `PHP_INT_MAX`: the adapter writes the cookie when a
   value changed (local), or `Vary_Cache` does (VIP).
7. `template_redirect` `PHP_INT_MIN`: `Response_Finalizer::begin()` opens
   an output buffer.
8. Rendering. `Block_Enforcer` runs on `pre_render_block` for every Group
   with `contentGatesSegmentId`, calls `Membership::is_in()`, and returns
   `''` for a withheld Group. Each read lands in the `Consulted_Set`.
9. `shutdown` 0: `Response_Finalizer::finalize()` freezes the set, applies
   `content_gates_consulted_segments`, fires
   `content_gates_finalize_variance`, sends `X-Content-Gates-Vary` (and
   `X-Content-Gates-Key` under `WP_DEBUG`), checks cookie size, and
   flushes the buffer.

### Cache HIT

No PHP runs. The edge compares the reader's values for the stored copy's
consulted segments only. In the example above, a reader with
`subscriber=none` and any value for `newsletter` gets the stored copy.
Nothing the provider does at origin can change membership on a HIT. The
edge simulator reproduces this matching; whether the VIP edge can be made
to do it is unverified.

### REST assignment

```text
POST /wp-json/content-gates/v1/membership  {"segment":"subscriber"}
  rest_api_init: route registered
  permission_callback: apply_filters( content_gates_rest_membership_permission, false, $request )
  callback: Membership::assign(); adapter->flush()  -> Set-Cookie on this response
  rest_request_after_callbacks: Cache-Control: no-store..., Vary: Cookie
  rest_post_dispatch MAX: Response_Finalizer::finalize_rest()  (no reads, so no X-Content-Gates-Vary)
```

`send_headers` never fires on a REST request, so the controller flushes
the adapter itself. After the flush the request is too late for another
write. The finalizer still runs on `rest_post_dispatch` and would attach
`X-Content-Gates-Vary` to any REST response whose handler consulted a
segment, for example `GET /wp/v2/posts/<id>` whose `content.rendered` ran
the block enforcer. The finalizer marks the request finalized only when
WordPress is serving the REST request (`wp_is_serving_rest_request()`),
so an internal `rest_do_request()` during a page render does not steal the
page's own signal.

### Feed

Feeds go through `template_redirect`, which core fires before it calls
`do_feed()`, so the finalizer's buffer covers them. Full-content feeds
(`get_the_content_feed()`) run `the_content`, which runs `do_blocks()`,
so the block enforcer covers them. Summary feeds (`the_excerpt_rss()`)
read `get_the_excerpt()`, so the excerpt guard covers them. Both are
asserted at the function level by PHPUnit
(`Test_Excerpt_Guard::test_feed_content_omits_denied_group` and
`test_feed_excerpt_omits_denied_group`), and the Playwright spec
`leak-surfaces.spec.ts` asserts zero gated bytes and the
`X-Content-Gates-Vary` header on `/feed/` and `/feed/atom/`.

### Admin and admin-ajax

The finalizer does not buffer when `is_admin()` or `wp_doing_ajax()` is
true at boot. Admin requests are logged in and bypass the VIP page cache.
Admin-ajax responses that render gated content are therefore not
finalized; see the known limitations.

## The consulted-set rule

Every membership question goes through `Membership::is_in()` or
`Membership::value()`. Each call records the segment key when the
reference resolves, whether the answer is yes or no, and whether or not
the segment is active. The set is per request and per render: a gate
nested inside a denied Group is never reached, so its key is not recorded
(PHPUnit `Test_Block_Enforcer::test_denied_outer_consults_only_outer`).
Two renders of one URL can report different sets; that is safe as long as
the edge keys each stored copy on the set that copy reported.

Reads that record nothing: unknown references (a caller bug, flagged
under `WP_DEBUG`), the REST `GET /membership` body (raw adapter state
through `Membership::membership()`), and the test provider's footer box.

After `freeze()`, further reads still land in the live set
(`Consulted_Set::live_keys()`) but `keys()` keeps returning the frozen
snapshot. The reported set must be what the response was actually keyed
on.

## Response signal headers

| Header | When | Format |
| --- | --- | --- |
| `X-Content-Gates-Vary` | Consulted set is non-empty. | Sorted keys, comma and space separated: `newsletter, subscriber`. |
| `X-Content-Gates-Key` | `WP_DEBUG` only. | `newsletter=1, subscriber=none`. |
| `X-Content-Gates-Simulation` | Local adapter only, every response it handles. | `local`. |

An empty consulted set sends no `X-Content-Gates-Vary` header: one copy
for every reader. The local adapter never sends `Vary:
X-VIP-Go-Segmentation`. On WPVIP, `Vary_Cache` still sends that broad
header on its own whenever any group has a value; this plugin does not
suppress it, so on VIP both headers would be present until the edge
prefers the narrow one. That is an unverified platform question.

The spec's draft name was `X-Content-Gate-Vary` (singular). The plugin
sends the plural. The edge simulator accepts both.

## Buffering and why

The consulted set is only known after templates and blocks have run. PHP
cannot add a header once body output has started. So on front-end
requests the finalizer opens an output buffer at `template_redirect`
(priority `PHP_INT_MIN`, before anything else on that hook) and releases
it at `shutdown` after sending the headers. The whole body is held in
memory for the duration of the render. The finalizer remembers its own
buffer level and flushes only down to that level, so buffers opened by
other code above it are left alone.

Costs and failure modes: time-to-first-byte and peak memory on origin
requests rise by the size of the page; a theme or plugin that closes all
buffers (`ob_end_flush()` loops, `ob_get_clean()` on the wrong level)
or calls `flush()` sends output early, after which `Headers::send()`
skips the header silently (it still records it for tests). The spec
accepts full-response buffering as the expected cost. The costs have not
been measured in this prototype.

## Segment model

| Property | Value |
| --- | --- |
| Post type | `cg_segment`: `public => false`, `show_ui => false`, `show_in_rest => false`, `supports => [ 'title' ]`, `rewrite => false`, `query_var => false`, `can_export => false`, `map_meta_cap => true`, `capability_type => 'post'`. |
| Stable ID | The post ID. Block attributes and `int` references use it. |
| Key | `post_name`, generated once at creation: `sanitize_title( name )`, then anything outside `[A-Za-z0-9_-]` becomes `-`, trimmed of `-`, fallback `segment`, made unique with `-2`, `-3`, ... against both CPT and code keys. |
| Immutable key | `wp_insert_post_data` restores the stored `post_name` on every update, so a rename through any path keeps the key. It also undoes core's `__trashed` suffix. |
| Active | `post_status = publish`. New segments start active. |
| Inactive | `post_status = draft`. |
| Other statuses | Coerced to `draft` by `wp_insert_post_data`, including `trash` written directly. |
| Trash | `pre_trash_post` returns `false`: `wp_trash_post()` fails. |
| Delete | `pre_delete_post` returns `false`: `wp_delete_post( $id, true )` fails. `map_meta_cap` turns `delete_post` into `do_not_allow` for every user, administrators included. |
| Registry cache | CPT segments are loaded once per request (paged `WP_Query`, 100 per page) and invalidated on `save_post_cg_segment` and `clean_post_cache`. |

Deactivation fails closed: `is_reader_in_segment()` returns `false` for
everyone (the read is recorded), `assign_reader_to_segment()` and
`remove_reader_from_segment()` return `content_gates_inactive_segment`,
and gated Groups are withheld from everyone until reactivation. Content
assignments are preserved and the editor shows them as "(inactive)".

Code segments (`register_segment()`) have no post ID, are always active,
and live in memory for one request. The plugin registers `crawler`
itself.

## Block gating decision table

`Block_Enforcer::decision()` is the one place the rule lives.
`Block_Stripper` reuses it for excerpts. The attribute is
`contentGatesSegmentId` (integer) on `core/group`, registered on the
server with `register_block_type_args` and in the editor with
`blocks.registerBlockType`. Absent or `null` means "No segment".

| Attribute value | Segment state | Reader | Decision | Read recorded |
| --- | --- | --- | --- | --- |
| absent or `null` | n/a | any | allow (not a gate) | no |
| not a positive integer | n/a | any | withhold, `_doing_it_wrong()` under `WP_DEBUG` | no |
| ID of no segment | n/a | any | withhold, `_doing_it_wrong()` | no |
| ID that resolves to a code segment | n/a | any | withhold (code segments have no ID; this cannot normally happen) | no |
| valid ID | active | in the segment | allow | `segment` |
| valid ID | active | previewing (`is_preview()`) and `current_user_can( 'edit_post', $post_id )` | allow | `segment` |
| valid ID | active | not in the segment, in `crawler` | crawler: render in full, wrapped in `<div class="content-gates-paywall">` | `segment`, `crawler` |
| valid ID | active | not in the segment, not a crawler | withhold | `segment`, `crawler` |
| valid ID | inactive | any (including a crawler) | withhold | `segment`, `crawler` |

Withhold means `pre_render_block` returns `''`: core skips the Group, its
wrapper, its inner blocks, and their dynamic render callbacks (PHPUnit
`Test_Block_Enforcer::test_dynamic_child_callback_does_not_run_when_denied`).
The `crawler` read happens only after a denial, so pages without a denied
gate do not vary on `crawler`. When access is allowed, core's own
`metadata.blockVisibility` setting still applies; the plugin never forces
a block visible. Rendering never writes to the saved block attributes.

The `render_block` filter recomputes the decision rather than remembering
it. Membership is fixed for the request, so the answer is the same; this
closes the path where a `WP_Block` is rendered without `pre_render_block`
and applies the crawler wrapper when another filter pre-rendered the
Group.

## Excerpt guard and why core leaks

Core's `wp_trim_excerpt()` builds an auto-excerpt from the content through
`excerpt_remove_blocks()`. That function treats `core/group` as a wrapper:
it renders the Group's inner blocks one by one and drops the wrapper
itself. The Group, and with it `contentGatesSegmentId`, is never rendered,
so `pre_render_block` never fires for it and the gated text lands in the
excerpt. PHPUnit `Test_Excerpt_Guard::test_core_excerpt_leaks_without_guard`
proves the leak exists in core and is the reason the guard exists.

`Excerpt_Guard` hooks `get_the_excerpt` at priority 9, one step before
core's `wp_trim_excerpt` at 10. When the incoming text is empty (no manual
excerpt) and the post content mentions the attribute, it clones the post,
replaces `post_content` with `Block_Stripper::strip()` output, and returns
`wp_trim_excerpt( '', $clone )`. Core's own callback then receives
non-empty text and returns it unchanged. Manual excerpts are never
touched. Posts without the attribute are not parsed.

`Block_Stripper::strip()` parses the content, drops every Group whose
decision is withhold (the whole subtree, so inner gates are not
consulted), keeps `innerContent` markers in step, and re-serializes with
`serialize_blocks()`. Groups on the crawler variant are kept, unwrapped.

This covers archives, search, summary feeds, REST `excerpt.rendered`, and
the embed template, all of which read `get_the_excerpt()`. It does not
cover themes that call `get_the_content()` or `wp_trim_words()` on raw
content directly.

## The two cache adapters

Both implement `Membership\Cache_Adapter`: `register_group()`,
`set_group()` (empty value removes), `groups()`, `did_send_headers()`,
`flush()`, `is_local_simulation()`.

**`Vary_Cache_Adapter`** is used when `\Automattic\VIP\Cache\Vary_Cache`
exists. It forwards `register_group()`, `set_group_for_user()`, and
`get_groups()`, maps `Vary_Cache` error codes to this plugin's codes
(`did_send_headers` to `content_gates_too_late`,
`invalid_vary_group_name` to `content_gates_invalid_segment_key`,
`invalid_vary_group_segment` to `content_gates_invalid_value`,
`invalid_vary_group_notregistered` to `content_gates_not_registered`,
anything else to `content_gates_cache_error`), and flips
`did_send_headers()` at `send_headers` `PHP_INT_MAX - 1` or on `flush()`,
which calls `Vary_Cache::send_headers()`. `Vary_Cache` keeps parsing,
cookie writes, and its own `Vary` header. PHPUnit covers the adapter
against a fake `Vary_Cache` (`tests/unit/class-fake-vary-cache.php`); it
has not run against the real class.

**`Local_Cookie_Adapter`** is used otherwise (wp-env, CI). It reproduces
the cookie name, format, parse timing, and write timing, prefers the
`X-VIP-Go-Segmentation` header over the cookie, records the would-be
cookie in `pending_cookie()` even when `headers_sent()` prevents the real
write, and fires `vip_vary_cache_did_send_headers`. Its documented
variances from `Vary_Cache` are listed in its class docblock and in the
[provider contract](provider-contract.md#cookie-mechanism).

## The edge simulator

`tests/debugging/edge-sim.mjs` is a dependency-free Node 20+ proxy that
sits in front of a WordPress origin and behaves like the parts of the VIP
page cache that matter here: bypass rules, the cookie-to-header
translation, and per-stored-copy matching on `X-Content-Gates-Vary` (RFC
9111 section 4.1). It stores a copy only for a GET with status 200, no
`Set-Cookie`, and a cacheable `Cache-Control`. It adds `X-Edge-Sim:
HIT|MISS|PASS` and `X-Edge-Sim-Key` to responses and exposes
`/__edge-sim/stats`, `purge`, and `reset`. It is a test double with a
documented list of variances from WPVIP; read
`tests/debugging/README.md` before drawing platform conclusions.

Demo, with the dev site running on `:8888`:

```bash
npm run env start
npm run edge-sim -- --origin http://localhost:8888 --port 8890 --verbose
curl -sI http://127.0.0.1:8890/?p=1 | grep -i 'x-edge-sim\|x-content-gates'
curl -sI -H 'Cookie: vip-go-seg=vc-v1__subscribers_--_1' http://127.0.0.1:8890/?p=1 | grep -i x-edge-sim
curl -s http://127.0.0.1:8890/__edge-sim/stats
```

Two readers who differ only in a segment the page did not consult share
one stored copy; the stats endpoint shows one entry per distinct
consulted-set value combination.

## The test-only provider and the footer demo

`tests/e2e/fixtures/mu-plugins/content-gates-test-provider.php` is mapped
into both wp-env sites as an mu-plugin (`mappings` in `.wp-env.json` and
`.wp-env.tests.json`). It stands in for a real provider:

- grants every `POST /content-gates/v1/membership`
  (`add_filter( 'content_gates_rest_membership_permission', '__return_true' )`),
- registers the `newsletter` code segment on `content_gates_loaded`,
- handles a form POST with `content_gates_test_assign=<ref>` or
  `content_gates_test_remove=<ref>` on `init` 20, calls the public
  functions, and redirects back with `?content_gates_test=<ok|code>` from
  `vip_vary_cache_did_send_headers` (the VIP README flow),
- prints a fixed "Content Gates test provider (never deploy)" box in
  `wp_footer` with a Join/Leave button per active segment when
  `wp_get_environment_type()` is `local`. Both wp-env configs set
  `WP_ENVIRONMENT_TYPE` to `local`.

It has no nonce, so `curl` and Playwright can post to it. It grants
membership to anyone who asks. It must never be deployed. It stays out of
PHPUnit by returning early when `tests_add_filter()` exists.

To see the demo: start the dev site (`npm run env start`, `:8888`),
create a segment at Settings > Content Gates, wrap a paragraph in a Group
and pick the segment, publish, then view the post logged out. The footer
box shows current membership and Join/Leave buttons. Put the edge
simulator in front (`:8890` as above) to watch HIT/MISS and the variant
key change as you join and leave.
