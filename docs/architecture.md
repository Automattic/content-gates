# Architecture

How the prototype is put together and how a request moves through it. The
audience is engineers and technical product managers who are not WPVIP
cache experts, so each section starts with the why.

## The problem in one paragraph

WPVIP's page cache stores one copy of a URL per distinct value of the
`X-VIP-Go-Segmentation` request header, which the edge derives from the
`vip-go-seg` cookie. If the cookie carries every segment a reader is in,
every URL gets up to 2^N copies for N segments, including pages that read
no segment at all. This plugin makes origin say, before any output, exactly
which segments a URL may read (**the declared set**), and an Edge Worker
narrows the header to the reader's values for those segments only. A page
that reads nothing has one copy. A page gated on `subscriber` has two,
whatever else the reader belongs to.

## Components

`Plugin::boot()` (`inc/class-plugin.php`) constructs every component in one
place and calls its `register()` method. The boot order is the order below.

| Component | File | Hooks | Job |
| --- | --- | --- | --- |
| `Platform\Platform` | `inc/platform/class-platform.php` | none | Loads `Vary_Cache` from mu-plugins, or the bundled upstream copy in `local`. Sets the cookie lifetime. |
| `Segments\Segment_Registry` | `inc/segments/class-segment-registry.php` | `init` 0 and 1, `wp_insert_post_data`, `pre_trash_post`, `pre_delete_post`, `transition_post_status`, `updated_post_meta`, cache flushes | Stores `cg_segment` posts (every mutation needs `manage_options`), resolves keys and IDs, blocks deletion, announces status and meter changes from any path, mirrors `config/crawlers.json`. |
| `Membership\Declared_Set` | `inc/membership/class-declared-set.php` | `wp` `PHP_INT_MAX`, `the_content` 1, `rest_pre_serve_request`, `rest_pre_echo_response` | Builds the declared set and sends the declared and meter headers. |
| `Membership\Membership` | `inc/membership/class-membership.php` | `init` 5, `send_headers` `PHP_INT_MAX - 1`, `content_gates_rest_headers_sent`, `vip_vary_cache_did_send_headers` | The one door to `Vary_Cache`: registers groups, writes idempotently, expires the cookie after a final removal, and reads subject to the declared set. |
| `Rendering\Block_Gate` | `inc/rendering/class-block-gate.php` | `register_block_type_args`, `pre_render_block`, `render_block_data`, `render_block_core/group` | Registers the Group attributes, withholds Groups, numbers and marks gated Groups on crawler variants. |
| `Rendering\Content_Meta` | `inc/rendering/class-content-meta.php` | `save_post`, `rest_pre_insert_{type}` | Computes a post's declared set at save time into `_content_gates_declared`; rejects unknown keys on REST saves. |
| `Rendering\Excerpt_Guard` | `inc/rendering/class-excerpt-guard.php` | `get_the_excerpt` 9 and 11 | Builds auto-excerpts from content with withheld Groups removed. |
| `Rendering\Structured_Data` | `inc/rendering/class-structured-data.php` | `wp_footer` | Paywall JSON-LD on crawler variants of gated singular posts, from the count of Groups the render marked. |
| `Cache_Purge` | `inc/class-cache-purge.php` | `content_gates_segment_changed`, `content_gates_crawlers_synced`, `content_gates_purge_all` | Requests one site-wide purge per request on configuration changes. |
| `REST\Segments_Controller` | `inc/rest/class-segments-controller.php` | `rest_api_init` | `GET content-gates/v1/segments` for the editor, `edit_posts` only. |
| `Admin\Segment_Admin` | `inc/admin/class-segment-admin.php` | `admin_menu`, `admin_post_*` | Settings > Content Gates. |
| `Admin\Edge_Notice` | `inc/admin/class-edge-notice.php` | `init`, `admin_notices` | Version skew between the plugin and the Edge Worker. |
| `Admin\View_As` | `inc/admin/class-view-as.php` | `init` 11, `send_headers` 1, `admin_bar_menu`, `admin_post_*` | Editors preview a reader's membership; responses are `no-store`. |
| `Editor\Editor_Assets` | `inc/editor/class-editor-assets.php` | `enqueue_block_editor_assets` | Loads the built Segments control. |
| `Providers\Newspack_Bridge` | `inc/providers/class-newspack-bridge.php` | `init` 20 | Maps Newspack rule evaluations to assignments. |
| `CLI\Commands` | `inc/cli/class-commands.php` | WP-CLI | `wp content-gates ...`. |
| Public functions | `inc/functions.php` | none | The six contract functions. |

## The declared set

The declared set is the list of segments a response may read. It is built
**before any output**, so the render can never depend on a segment the cache
key does not cover. It comes from two sources:

1. **Content.** When a post is saved, `Content_Meta` walks its blocks and
   stores the keys of every Group assignment, nested or not, in either
   show-to mode, in post meta. No block parse runs per request. Synced
   patterns contribute the referenced post's stored set.
2. **Declarations.** Site code declares reads made outside blocks (template
   parts, shortcodes, body classes) with `declare_segment()` on the `wp`
   action or through the `content_gates_declared_segments` filter.

Per request type:

| Request | Content contribution |
| --- | --- |
| Singular post or page (including `/embed/`) | The queried post's stored set. If the result is non-empty, the active crawler segments are added so the paywall markup can read them at no cost to human variants. |
| Feed | The union over the posts in the main query. |
| Archive, search, home | Nothing. Teasers never read segments. |
| REST | The union over the posts whose content rendered, merged as each renders. |

A read of a segment outside the set returns `false` and logs under
`WP_DEBUG`. Inactive segments leave the set when it is built, so their
assignments withhold content for everyone until reactivation, and they cost
no variants. The filter can add keys but never remove block or earlier
declarations: removing one would withhold content with no visible cause.

### Why `wp` and not `send_headers`

The spec places finalization on `send_headers` and declarations on `wp`.
Core's `WP::main()` fires `send_headers` **before** `wp`, so a declaration
on `wp` would be too late for a `send_headers` finalizer. Finalizing at the
end of `wp` keeps the public contract the spec's examples show. Both hooks
run before output and before `template_redirect`. The corrected timing is
recorded in `spec/decisions.md`.

## Request flows

### Page request, cold (edge MISS)

```
Reader                         Edge Worker                        Origin (WordPress + plugin)
  |  GET /article  Cookie: vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1
  |----------------------------->|
  |                              |  KV record for /article: [crawlers-ai, crawlers-search, subscriber]
  |                              |  narrow header to vc-v1__subscriber_--_1; forward cookie, edge version
  |                              |---------------------------------->|
  |                              |                                   | init 0: cg_segment post type
  |                              |                                   | init 5: every segment registered as a group
  |                              |                                   | init 10: Vary_Cache parses the cookie
  |                              |                                   | init 20: provider assigns/removes (optional)
  |                              |                                   | send_headers: writes close; Vary + cookie sent
  |                              |                                   | wp MAX: declared set from post meta; header sent
  |                              |                                   | template: pre_render_block withholds Groups the reader lacks
  |                              |<----------------------------------|
  |                              |  200  Vary: X-VIP-Go-Segmentation
  |                              |       X-WPVIP-Content-Gates-Declared: crawlers-ai, crawlers-search, subscriber
  |                              |  coverage check ok -> store under /article + vc-v1__subscriber_--_1; KV record replaced
  |<-----------------------------|
```

### Page request, warm (edge HIT)

No PHP runs. The worker narrows the header the same way and the cache
returns the stored copy for that key. A newsletter-only reader produces the
empty key and shares the anonymous copy. For metered URLs the worker still
runs phase 4 on the hit: it counts the view and rewrites the meter cookies.

### REST request

```
GET /wp-json/wp/v2/posts/123
  init 5/10/20 as above
  dispatch: prepare_item_for_response
    the_content priority 1 (Declared_Set): merge post 123's stored set
    the_content priority 9 (do_blocks): pre_render_block withholds Groups
    excerpt: Excerpt_Guard strips withheld Groups before trimming
  rest_pre_echo_response MAX: filter runs, declared header sent,
    content_gates_rest_headers_sent -> Vary_Cache::send_headers() (Vary + cookie)
  echo JSON
```

`rest_pre_echo_response` rather than `rest_pre_serve_request` because
`_embed`ded posts render after the latter. If another handler serves the
response itself (`rest_pre_serve_request` returns true) the set is closed
there instead.

### Membership change (provider)

```
POST /article  content_gates_dev_assign=subscriber      (any form; the fixture is an example)
  init 20: provider calls assign_reader_to_segment( 'subscriber' )   -> Vary_Cache::set_group_for_user
  send_headers MAX: Vary_Cache writes Set-Cookie: vip-go-seg=vc-v1__subscriber_--_1
  vip_vary_cache_did_send_headers: provider redirects (303) to the same URL
GET /article  Cookie: vip-go-seg=...   -> entitled variant
```

The redirect is the post-redirect-get pattern WPVIP documents; a POST
response is never cached, and the following GET carries the cookie.

## Enforcement

`Block_Gate::should_withhold()` is the one place the rule lives:

| Mode | Withhold when |
| --- | --- |
| `in` | The reader is in none of the listed segments |
| `not-in` | The reader is in any of them |

Each listed key is read through `Membership::is_in()`, so unknown, inactive,
and undeclared keys read as not-in-segment and can never open a gate. A
withheld Group returns `''` from `pre_render_block`, which core applies to
nested blocks too, so the wrapper, the inner blocks, and their render
callbacks are all skipped. When access is allowed the block renders
normally and core's own visibility settings still apply.

Surfaces that bypass block rendering need their own decision:

- **Excerpts.** Core's `excerpt_remove_blocks()` unwraps `core/group` and
  renders the inner blocks directly, so `pre_render_block` never sees the
  Group's attributes. `Excerpt_Guard` regenerates the excerpt from content
  with withheld Groups removed and discards what core produced.
- **Raw content paths** (`get_post_field( 'post_content' )`, `content.raw`
  in REST edit context) are outside the plugin; see
  [leak-surfaces.md](leak-surfaces.md).

## Segment model

| Property | Value |
| --- | --- |
| Post type | `cg_segment`, not public, not in REST, no UI, `supports => title` |
| Key | `post_name`, derived once from the first name (`sanitize_title`, `[A-Za-z0-9_-]` only, 16 characters, unique), restored on every update |
| Active | `publish`. Inactive: `draft`. Other statuses are coerced to `draft` |
| Type | `boolean` or `meter` (`_content_gates_type`); meters carry `_content_gates_limit` and `_content_gates_period` (seconds) |
| Crawler | `_content_gates_crawler = 1`; read-only, mirrored from `config/crawlers.json`; removal from the file deactivates |
| Group name | The key on a single site; `{blog_id}-{key}` on multisite; crawler keys are never prefixed |
| Deletion | Refused: `pre_trash_post`, `pre_delete_post`, and `delete_post` maps to `do_not_allow` |
| Cache | One object-cache entry per site, invalidated on any segment save |

## Crawler variants

The Edge Worker classifies the user agent and injects `crawlers-search=1`
or `crawlers-ai=1` into the cookie header it forwards to origin, after
stripping any crawler value the browser sent. Origin treats the value like
any other membership. A crawler segment is in the declared set only when an
editor selected it on a Group (no auto-declaration since 2026-10-26), and
`Block_Gate::is_crawler()` reads it through the declared set. On a crawler
response to such a post every rendered `in` Group gets the classes
`content-gates-gated content-gates-gated-{n}` (numbered in document order,
per post) and `wp_footer` prints JSON-LD with `isAccessibleForFree: false`
and one `hasPart` per marked Group with the matching `cssSelector`. A
`not-in` Group is the free part the crawler sees, so it is neither marked
nor listed. Human responses carry neither.

## Metering

A meter is a segment with a limit and a period. Origin's only jobs are to
treat its value like any other (`open` and `last` are in, `wall` is out) and
to announce the meter on singular pages with the `X-WPVIP-Content-Gates-Meter`
header. The Edge Worker counts views and writes the state, on hits as well
as misses. See [edge-worker.md](edge-worker.md).
