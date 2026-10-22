# Automattic\VIP Page Cache

This plugin controls the behavior of the page cache on WordPress VIP by generating content intended for specific segments. Consult the documentation for how the page cache works:

- https://docs.wpvip.com/caching/page-cache/
- https://docs.wpvip.com/caching/page-cache/customize-behavior/cache-personalization-api/
- https://docs.wpvip.com/caching/page-cache/customize-behavior/vary-http-response-header/

Our goal is to use cookies to identify users and the segments they belong to, external systems to map users to segments, and response headers to vary the cache based on those segments. This allows us to serve personalized content while still benefiting from page caching.

## Cache Personalization API vs. consulted-set narrowing

There is a gap between the implementation of the Cache Personalization API and this plugin's desire for consulted-set narrowing. The gap is resolved using edge workers.

If this gap cannot be completely closed, changes to the Cache Personalization API and VIP's page cache logic are possible as a last resort. Raise it as a blocker.

### The initial gap

The two approaches differ in what the edge uses as the cache key.

| | Cache Personalization API today | Consulted-set narrowing |
| --- | --- | --- |
| Cookie | `vip-go-seg`, every group the reader is in | Same cookie |
| Request header at edge | `X-VIP-Go-Segmentation` (full membership) | Same header |
| Response signal | `Vary: X-VIP-Go-Segmentation`, sent by `Vary_Cache` at `send_headers` on every response once any group is registered, whether or not the reader has a value | Response header naming only segments consulted on the rendered page |
| Cache key | URL + whole header value | URL + reader's values for the consulted segments only |
| Copies of a URL | Up to 2^N for N segments, on every URL, including pages that read nothing | Up to 2^K for the K consulted segments; one copy for pages that read nothing |

With segments `subscriber`, `newsletter`, and `donor`, the documented API can store eight copies of `/article-1` although the page reads only `subscriber`, and eight copies of `/about` although it reads nothing. Every added segment doubles the variant space on every URL. Narrowing stores two copies of `/article-1` and one of `/about`, and readers who differ only in segments the page did not read share a copy.

## Edge worker narrowing design

Consulted-set narrowing becomes declared-set narrowing. It is implemented with a WPVIP [Edge Worker](https://docs.wpvip.com/infrastructure/edge-servers/edge-workers/) that rewrites the request-side segmentation value before cache lookup, keyed on a **declared set** that origin computes before any output. There is no output buffering and no post-render signal. `Vary_Cache`, the cookie, and the cache's keying logic stay as they are.

### Principle

The cache keys on URL plus the full value of the `X-VIP-Go-Segmentation` request header. The worker presents a narrowed value for that header: only the reader's values for the segments this URL may read. The reader's browser cookie is never narrowed. Origin keeps sending `Vary: X-VIP-Go-Segmentation`, so nothing in `Vary_Cache` needs to change.

The origin must know the segments a response will read before it sends headers. That is the declared set:

1. **Declare before `send_headers`.** The plugin computes the declared set from content and from explicit declarations by customer code, and sends it as a response header. Nothing is learned from the render.
2. **Undeclared reads fail closed.** A read for a segment that is not in the declared set returns `false` and raises `_doing_it_wrong()` under `WP_DEBUG`. A render can never depend on a segment the key does not cover.

The edge worker learns each URL's declared set from the response header and keeps it in edge KV store. Content-level changes will result in a cache purge, which refetches the response from origin and updates the edge KV store. Broader changes, such as adding or removing segment checks in template code, will rely on natural cache expiration.

### The declared set

For each request the plugin builds the initial declared set at `send_headers`, after the main query is resolved:

| Request | Content contribution |
| --- | --- |
| Singular post or page | Every segment assigned to any Group in the post content, nested or not, read from the saved block attributes. Computed at save time and stored in post meta so no parse runs per request. |
| Feed, oEmbed, embed | The union over the posts the feed or embed renders. |
| REST response with rendered content | The union over the posts in the response, computed in `rest_pre_serve_request` before the JSON is sent. |
| Archive, search, home | Nothing from content. Teasers never read segments (decided). |

Reads that are not block assignments are added by the site through the declaration API below. The plugin sorts and de-duplicates the keys and sends:

```
X-WPVIP-Content-Gates-Declared: donor, subscriber
```

A response with no gated content and no declarations sends the header with an empty value. A response that skipped the plugin's `send_headers` callback (an early exit before `send_headers`) sends no header; the worker treats that as unknown.

### Declaration API

Two ways to declare, both usable only before the plugin's `send_headers` callback. `send_headers` fires before `template_redirect`, so declarations belong on the `wp` action (the main query is resolved there) or in the filter. A declaration at `template_redirect` or later returns `content_gates_too_late`.

```php
use function Automattic\VIP\ContentGates\declare_segment;

// Imperative. Call on `wp` or earlier. Accepts a post ID or key.
$result = declare_segment( 'donor' ); // true|WP_Error
```

```php
// Declarative. Runs when the plugin builds the set, with the main query available.
add_filter( 'content_gates_declared_segments', function ( array $segments, \WP_Query $query ): array {
	// Return the full list; add or remove keys.
	return $segments;
}, 10, 2 );
```

Errors: `content_gates_too_late`, `content_gates_invalid_segment`, `content_gates_inactive_segment` (declaring an inactive segment is allowed and recorded; reads of it still return `false`).

Declaring is free at origin and costs variants at the edge: every declared segment doubles the possible copies of that URL. Declare on the requests that will read, not site-wide, unless the read really happens site-wide.

### Reads outside blocks: customer examples

**A subscriber-only item in the site header, on every page.** The header template calls the read on every request, so every URL genuinely has two variants. Declare site-wide. This is the one case where a site-wide declaration is correct, and the cost (two copies of every URL) is the real cost of the feature.

```php
// functions.php
use function Automattic\VIP\ContentGates\is_reader_in_segment;

add_filter( 'content_gates_declared_segments', function ( array $segments ): array {
	$segments[] = 'subscriber';
	return $segments;
} );

// header.php
if ( is_reader_in_segment( 'subscriber' ) ) {
	get_template_part( 'template-parts/nav', 'subscriber' );
} else {
	get_template_part( 'template-parts/nav', 'public' );
}
```

**A different single-post template for donors.** The read happens only on singular posts, so declare only there. `template_include` runs after `send_headers`, which is fine for the read; only the declaration has to be early.

```php
// functions.php
use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\is_reader_in_segment;

add_action( 'wp', function (): void {
	if ( is_singular( 'post' ) ) {
		declare_segment( 'donor' );
	}
} );

add_filter( 'template_include', function ( string $template ): string {
	if ( is_singular( 'post' ) && is_reader_in_segment( 'donor' ) ) {
		return get_theme_file_path( 'single-donor.php' );
	}
	return $template;
} );
```

Posts get up to two copies at the edge. The home page and archives are unaffected.

**Switching the whole theme.** The `stylesheet` and `template` filters run at `setup_theme`, before `init`, and membership is parsed from the cookie at `init` priority 10. Contract version 1 cannot vary the active theme by segment. Vary the template, the template parts, or the body class instead:

```php
add_action( 'wp', function (): void {
	if ( is_singular() ) {
		declare_segment( 'subscriber' );
	}
} );

add_filter( 'body_class', function ( array $classes ): array {
	if ( is_singular() && is_reader_in_segment( 'subscriber' ) ) {
		$classes[] = 'is-subscriber';
	}
	return $classes;
} );
```

**A members-only shortcode.** The plugin cannot see a shortcode's segment statically, so the shortcode's owner declares it when the content contains it.

```php
add_filter( 'content_gates_declared_segments', function ( array $segments, \WP_Query $query ): array {
	$post = $query->get_queried_object();
	if ( $post instanceof \WP_Post && has_shortcode( $post->post_content, 'members_only' ) ) {
		$segments[] = 'subscriber';
	}
	return $segments;
}, 10, 2 );

add_shortcode( 'members_only', function ( array $atts, string $content = '' ): string {
	return is_reader_in_segment( 'subscriber' ) ? do_shortcode( $content ) : '';
} );
```

**A welcome box for subscribers on the home page only.**

```php
add_action( 'wp', function (): void {
	if ( is_front_page() ) {
		declare_segment( 'subscriber' );
	}
} );
```

**Forgetting to declare.** A theme that reads `subscriber` in a template part on the front page without declaring it gets `false` on every request. Subscribers see the public version of that part. Under `WP_DEBUG` the read logs:

```
is_reader_in_segment(): Segment "subscriber" was read but not declared for this request. Declare it on the `wp` action or through the content_gates_declared_segments filter.
```

Nothing leaks and no cached copy is wrong; the fix is one declaration.

### Request flow

Segments `subscriber`, `newsletter`, and `donor`. The reader is in `subscriber` and `newsletter`. `/article-1/` has one Group gated on `subscriber` and declares nothing else.

**Phase 1, client request, before lookup.** The worker reads the cookie, giving `{newsletter=1, subscriber=1}`, adds any edge-written values (`crawler`, `meter`), and reads the KV record for the URL.

- No record: pass the full value through. This is today's full-cookie keying and is safe.
- Record `[subscriber]`: rewrite the header to the reader's values for those keys only, in `Vary_Cache` serialization.

```
GET /article-1/
Cookie: vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1
X-VIP-Go-Segmentation: vc-v1__subscriber_--_1
```

The cache key is `/article-1/` plus `vc-v1__subscriber_--_1`. A subscriber who is not on the newsletter shares the copy. A reader in none of the recorded segments produces an empty header and shares the anonymous copy.

**Phase 3, origin response, on a miss.** The worker reads `X-Content-Gates-Declared` and, in this order:

1. **Coverage check.** Every declared key must have been present in the narrowed lookup value. If any was narrowed away, or the header is missing, the worker sets `Cache-Control: no-store` so the cache does not store the response under a key that is too narrow. If the lookup used the full value because no record existed, the response is covered.
2. **Record update.** The worker replaces the KV record with the declared set. Replacement, not merge, so the record shrinks when an editor removes a segment and grows when one is added.

Both steps use headers only. The worker never inspects the body.

### Why a render never outruns the key

A subscriber's render of `/article-1/` reads only `subscriber`. A non-subscriber's render goes on to read `newsletter` to choose a teaser. Under a learned-from-render design the record could be `[subscriber]` while a non-subscriber render depended on `newsletter`, and a copy would be stored under a key that is too narrow. Here `newsletter` is in the declared set because it is assigned to a Group in the content, so every render of the URL is keyed on both.

### Change propagation

| Event at origin | What the edge does | Window |
| --- | --- | --- |
| Editor adds a segment to a Group and saves | Post save purges every stored variant of the URL (confirmed by VIP, 2026-10-21). The next miss declares a wider set; the worker widens the record and does not store that one response. | One origin round trip per edge location |
| Editor removes a segment from a Group | The next miss declares a narrower set; the record shrinks. Old copies were purged on save. | One origin round trip |
| Segment deactivated or renamed in the admin | Global invalidation purges all URLs. Records are rebuilt on the next miss per URL. | One origin round trip per URL |
| Theme adds a read without declaring it | The read returns `false`. Nothing is stored wrongly. | Until the theme declares it |
| KV record stale at one edge location | The coverage check catches a too-narrow record. A too-wide record costs variants only. | One origin round trip at that location |

No edge-side TTL is needed for correctness or responsiveness. If VIP's KV requires one, it must be long and is a storage detail.

### Variant bound

Stored copies per URL are bounded by the product of value counts over the declared set: `2^k` for `k` boolean segments, times the step count for `meter`. Never by the number of configured segments. A page that declares nothing has one copy.

### Encrypted mode

In `vip-go-auth` mode the worker must read the decrypted groups and rewrite `X-VIP-Go-Auth` instead. Whether a worker can do this is an open VIP question. Until answered, the design is specified for plaintext mode.

### Alternatives considered

- **Post-render consulted set with output buffering.** Keys on exactly what each render read, so nested gates cost fewer variants. Rejected: full-response buffering on every output path, a fail-safe path for early flushes, and a finalization step per surface (page, REST, feed, oEmbed), for a small variant saving.
- **Consulted set as a body trailer.** Avoids PHP buffering but needs the worker to hold the body before storage, or to purge a just-stored copy, and does not fit JSON. Rejected.
- **Edge-side gating.** Origin renders everything with markers and the worker strips unentitled sections on every delivery. One copy per URL and no variants, but the worker becomes the enforcement point, gated bytes sit in the edge cache, and REST and feeds need their own rewriting. Kept as the fallback if the hit-rate target proves unreachable with variants.
- **Per-segment request headers with standard `Vary`.** VIP honors a fixed list of Vary names. Rejected.
- **Narrowed key in the URL.** Gives up purge-all-variants, since variants would be distinct URLs. Rejected.

### Platform questions this design depends on

1. Can a phase 1 worker rewrite `X-VIP-Go-Segmentation` (or the cookie the edge derives it from), and does the cache then key on the rewritten value?
2. Can a phase 3 worker add `Cache-Control: no-store` to an origin response so the cache does not store it?
3. What are KV read latency in phase 1 and write visibility across edge locations?
4. Can origin write to edge KV, so the record is pushed at save time?
5. Can a worker read and rewrite decrypted groups in encrypted mode?
6. What is the cap on stored variants per URL?

These questions do not block development but must be answered once a prototype is deployed to WPVIP.
