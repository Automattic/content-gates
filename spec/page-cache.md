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

1. **Declare before output.** The plugin computes the declared set from content and from explicit declarations by customer code at the end of the `wp` action (core fires `send_headers` before `wp`, so that is the last hook where declarations made on `wp` are visible; see [decisions.md](decisions.md)), and sends it as a response header. Nothing is learned from the render.
2. **Undeclared reads fail closed.** A read for a segment that is not in the declared set returns `false` and raises `_doing_it_wrong()` under `WP_DEBUG`. A render can never depend on a segment the key does not cover.

The edge worker learns each URL's declared set from the response header and keeps it in edge KV store. Content-level changes will result in a cache purge, which refetches the response from origin and updates the edge KV store. Broader changes, such as adding or removing segment checks in template code, will rely on natural cache expiration.

### The declared set

For each request the plugin builds the initial declared set at the end of the `wp` action, after the main query is resolved:

| Request | Content contribution |
| --- | --- |
| Singular post or page | Every segment assigned to any Group in the post content, nested or not, read from the saved block attributes. Computed at save time and stored in post meta so no parse runs per request. Assignments to inactive segments are dropped. |
| Feed, oEmbed, embed | The union over the posts the feed or embed renders. |
| REST response with rendered content | The union over the posts whose content rendered, merged as each renders and sent in `rest_pre_echo_response` before the JSON is echoed. |
| Archive, search, home | Nothing from content. Teasers never read segments (decided). |

Reads that are not block assignments are added by the site through the declaration API below. The plugin sorts and de-duplicates the keys and sends:

```
X-WPVIP-Content-Gates-Declared: donor, subscriber
```

The header carries `Vary_Cache` group names: the segment keys on a single site, and `{blog_id}-{key}` on multisite (see [decisions.md](decisions.md)). Inactive segments are never included. A response with no gated content and no declarations sends the header with an empty value. A response that skipped the plugin's finalize callback (an early exit before the end of the `wp` action) sends no header. Such a response also lacks the `Vary` header that `Vary_Cache` sends at the same hook, so the worker caches it as unsegmented. A response with the `Vary` header but no declared header is treated as unknown and not stored.

### Declaration API

Two ways to declare, both usable only before the plugin's finalize callback, which runs at the end of the `wp` action (priority `PHP_INT_MAX`). The main query is resolved there and nothing has been output, so declarations belong on the `wp` action at the default priority or in the filter. A declaration at `template_redirect` or later returns `content_gates_too_late`.

```php
use function Automattic\VIP\ContentGates\declare_segment;

// Imperative. Call on `wp` or earlier. Accepts a post ID or key.
$result = declare_segment( 'donor' ); // true|WP_Error
```

```php
// Declarative. Runs when the plugin builds the set, with the main query available.
add_filter( 'content_gates_declared_segments', function ( array $segments, \WP_Query $query ): array {
	// Add keys. Keys from block assignments or earlier declarations cannot be removed.
	return $segments;
}, 10, 2 );
```

Errors: `content_gates_too_late`, `content_gates_invalid_segment`, `content_gates_inactive_segment`. Inactive segments are never declared, so a read of one fails closed and the segment costs no variants.

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

**Phase 1, client request, before lookup.** The worker reads the cookie, giving `{newsletter=1, subscriber=1}`, adds any crawler value it classifies for this request, and reads the KV record for the URL. Meter states are already cookie values and need no work here.

- No record: pass the full value through. This is today's full-cookie keying and is safe.
- Record `[subscriber]`: rewrite the header to the reader's values for those keys only, in `Vary_Cache` serialization.

```
GET /article-1/
Cookie: vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1
X-VIP-Go-Segmentation: vc-v1__subscriber_--_1
```

The cache key is `/article-1/` plus `vc-v1__subscriber_--_1`. A subscriber who is not on the newsletter shares the copy. A reader in none of the recorded segments produces an empty header and shares the anonymous copy.

**Phase 3, origin response, on a miss.** The worker reads `X-WPVIP-Content-Gates-Declared` and, in this order:

1. **Coverage check.** The check applies only to responses that carry `Vary: X-VIP-Go-Segmentation`. A response without that header is not segmented, so a missing declared header is not an error and the response is cached as usual. When the `Vary` header is present, every declared key must have been present in the narrowed lookup value. If any was narrowed away, or the declared header is missing, the worker sets `Cache-Control: no-store` so the cache does not store the response under a key that is too narrow. If the lookup used the full value because no record existed, the response is covered.
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

Edge KV is not durable. A record can be evicted at any time. That is safe: a missing record means the lookup uses the full cookie value, which is today's keying, and the next miss relearns the record from the declared header. The cost of an eviction is one extra miss per URL per edge location, not a leak. The frequency of eviction decides how much narrowing is lost in practice and is an open question for VIP.

### Variant bound

Stored copies per URL are bounded by the product of value counts over the declared set: `2^k` for `k` boolean segments, times four for each meter segment (`open`, `last`, `wall`, none). Never by the number of configured segments. A page that declares nothing has one copy.

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
3. What are KV read latency in phase 1, write visibility across edge locations, and the typical eviction rate? KV is known to be non-durable.
4. Can origin write to edge KV, so the record is pushed at save time?
5. Can a worker read and rewrite decrypted groups in encrypted mode?
6. What is the cap on stored variants per URL?

These questions do not block development but must be answered once a prototype is deployed to WPVIP.

## Anonymous metering

A meter grants an anonymous reader a limited number of articles per period and then shows the gate. The design keeps all meter state in the reader's cookies, keeps all meter configuration at origin, and gives the Edge Worker nothing to store. The worker advances the meter on cache hits as well as misses.

### Principle

1. **A meter is a segment.** A meter segment is a custom post type record like any other, with two extra fields: a limit (articles per period) and a period. Editors gate content on it exactly like a boolean segment, and it enters the declared set the same way. A Group gated on "subscriber, metered-articles" shows to subscribers and to anonymous readers with allowance left, through the normal OR semantics.
2. **Origin announces, the edge counts.** Every response whose declared set contains a meter segment carries a response header naming the meter and its settings. The header is stored with the cached copy, and the worker can read it on a hit or a miss. Origin never sees a count.
3. **State is computed after the view, for the next request.** In phase 4 the worker counts the view and writes the resulting state into `vip-go-seg` as the meter segment's value. Phase 1 has nothing to derive: the value is in the cookie like any other segment, so narrowing and lookup are unchanged.

### Configuration

The meter segment record holds:

| Field | Meaning | Example |
| --- | --- | --- |
| Limit | Articles a reader may open per period | `5` |
| Period | Length of the period, stored in seconds, entered in days | `2592000` (30 days) |

Nothing is configured at the edge. A site can have several meters, for example `metered-articles` and `metered-videos`, each with its own limit and period. Meter names are site-defined keys and follow the normal naming and multisite rules.

### The meter header

Origin sends, on every response whose declared set contains a meter segment:

```
X-WPVIP-Content-Gates-Meter: metered-articles; id=123; limit=5; period=2592000
```

`id` is the post ID that this view counts against, so two URLs for one post count once. If the declared set contains more than one meter segment, origin repeats the header once per meter. The header is omitted when no meter segment is declared, so the worker does no meter work on un-metered responses.

### Meter states

The segment value is one of three states. The worker chooses it from the remaining allowance after counting the current view.

| Remaining after this view | Value written | `is_reader_in_segment()` |
| --- | --- | --- |
| More than one | `open` | `true` |
| Exactly one | `last` | `true` |
| None | `wall` | `false` |
| No cookie yet | no value | `false` |

`get_reader_segment_value()` returns the state, so a template can show "one article left" on `last`. Three states cost three variants per metered URL regardless of the limit; the count itself never reaches the cache key.

### Cookies

Two cookies carry the meter:

- **`vip-go-seg`** carries only the state, as `metered-articles_--_open`, in `Vary_Cache` format. Origin and the cache key see this and nothing else.
- **`vip-go-meter`** is private to the worker. For each meter it holds the period start, the count, and a short list of hashed post IDs already counted. The worker signs the whole value with a secret it holds, so a forged or edited cookie fails verification and is treated as absent. Size is about four bytes per counted article plus a fixed header, so a meter of ten costs under 100 bytes.

### Request flow

Reader opens `/article-7/`, post ID 123, gated on "subscriber, metered-articles". Limit 5, and the reader has counted three articles this period, so the cookie carries `metered-articles_--_open`.

**Phase 1.** The worker narrows the segmentation header to the URL's declared set as usual. The meter state is already a cookie value, so it is handled like any other segment. Cache lookup proceeds with `metered-articles_--_open` in the key.

**Phase 4, on the hit or miss.** The worker reads the meter header from the response and, in this order:

1. Verifies `vip-go-meter`. A missing or invalid cookie starts a fresh record with count zero.
2. Resets the record if the period has elapsed: count zero, empty seen list, new period start.
3. Counts the view only if the state used for the lookup was `open` or `last` (content was delivered) and post 123 is not in the seen list. The count becomes four and 123 is added.
4. Computes the next state. Remaining is one, so the state is `last`.
5. Writes both cookies: the signed `vip-go-meter`, and `vip-go-seg` with `metered-articles_--_last` merged into the reader's other groups in `Vary_Cache` serialization.

The reader's next metered view is keyed on `last`, is counted as the fifth, and leaves them at `wall`. At `wall` the Group is withheld, views are not counted, and the state stays `wall` until the period resets.

**First visit.** A reader with no cookie has no meter value, so the Group is withheld and the response is the anonymous copy. The worker writes a fresh record with state `open`. The next metered view delivers content. This is the decided first-visit trade-off: the first free article needs one round trip, so a client that refuses cookies cannot obtain free articles by refusing them.

### Change propagation

| Event | What happens | Window |
| --- | --- | --- |
| Editor gates or un-gates a post on a meter | Post save purges the URL's variants. The next miss sends or omits the meter header. | One round trip |
| Admin changes a meter's limit or period | Cached copies keep the old header until purged. The spec already requires a global purge for segment configuration changes; this falls under that rule. | Until purge |
| Period elapses | The worker resets the record on the reader's next metered view. | Next view |
| Meter segment deactivated | It leaves every declared set, so origin stops sending the header and reads return `false`. Stale cookie values are ignored. | Global purge |

### Accepted limits

- **Deleting the cookie** returns the reader to the no-cookie state, which gets the gate. Two reloads after a delete produce a fresh allowance. No design without server-side identity can close this, and metering is a soft gate by nature. The hard gate is the subscriber segment.
- **Forging** fails the signature. **Copying** a cookie to another client can be caught by binding the signature to a client fingerprint such as JA3, if the worker can read one. Fingerprints are not reader identities and are never used to count.
- **Subscribers are counted too.** The worker does not know gating semantics, so a subscriber's views advance their meter. It has no effect while they are subscribed and is harmless after.

### Why not a server-side count

Edge KV is not durable, so it cannot hold the count. Origin cannot hold it because hits never reach origin. A client fingerprint such as JA3 is shared by every client with the same TLS configuration, so even a durable store keyed on it would count one reader's views against many others. The only identity stronger than a cookie is a login, and logged-in readers belong to the entitlement provider.

### Platform questions

1. Can the worker hold a signing secret, for example from an environment variable or compiled in at deploy?
2. Is JA3 or JA4 exposed to the worker as a request attribute, for cookie binding?
3. Does `Vary_Cache` at origin re-serialize groups it did not register, so the meter value survives a miss? The source suggests yes; it needs a regression test on the VIP test application.

## Crawler classification

Search and AI crawlers must receive the gated content together with paywall structured data, and they must never be able to carry that access into a later request. Crawler segments are maintained by VIP, not by customers. VIP must still be able to add or remove a segment and change the user agents in a segment through a release.

### Principle

1. **One file, two readers.** `config/crawlers.json` in this repository lists every crawler segment with its key, label, and user-agent patterns. The Edge Worker build in `edge/` compiles it in. The plugin ships the same file and reads it at load. There is no runtime configuration at the edge or in the Dashboard.
2. **Classification is per request and stateless.** The worker tests the user agent in phase 1 and injects a value for each matching segment into the narrowed segmentation header and into the cookie header it forwards to origin. It never writes a crawler value to the browser.
3. **Origin mirrors, never edits.** The plugin mirrors each configured crawler segment into a reserved segment record so the editor and the declared set work unchanged. The record is read-only in the admin.

### The configuration file

```json
{
	"version": 3,
	"segments": {
		"crawlers-search": {
			"label": "Search crawlers",
			"user_agents": [ "Googlebot", "bingbot", "DuckDuckBot" ]
		},
		"crawlers-ai": {
			"label": "AI crawlers",
			"user_agents": [ "GPTBot", "ClaudeBot", "PerplexityBot" ]
		}
	}
}
```

Keys follow the normal segment key rules. Patterns are case-insensitive substrings of the user agent; a segment may list any number. `version` increments on every change to the file. The format leaves room for verified-bot data later, for example an IP-range or reverse-DNS flag per segment, without a format change.

### Request flow

Googlebot requests `/article-1/`, gated on "subscriber, crawlers-search".

**Phase 1.** The worker first removes every crawler group from the groups it parsed from the incoming cookie, so a browser that sends `crawlers-search_--_1` in `vip-go-seg` gains nothing. Only the worker's own classification can set a crawler value. The user agent matches `crawlers-search`. The request has no cookie, so the reader's groups are `{crawlers-search=1}`. The KV record for the URL is `[crawlers-search, subscriber]`, so the narrowed header is `vc-v1__crawlers-search_--_1`. The worker also sends its configuration version to origin on a miss:

```
X-VIP-Go-Segmentation: vc-v1__crawlers-search_--_1
X-WPVIP-Content-Gates-Edge: 3
```

**Origin, on a miss.** `Vary_Cache` parses the forwarded cookie and `is_reader_in_segment( 'crawlers-search' )` is `true`. The Group renders in full, and the plugin emits the paywall structured data for the post. The response declares `crawlers-search, subscriber` and is stored under the crawler key. A human reader never carries a crawler value, so human keys are unaffected by crawler segments in the declared set.

**Phase 4.** The worker writes no cookie for a crawler. A later request from the same crawler is classified again from scratch.

### Structured data

On a response where any crawler segment is `true`, the plugin wraps each rendered gated Group in a marker class and emits JSON-LD on singular posts with `isAccessibleForFree: false` and one `hasPart` entry per Group with a `cssSelector` for that class. Posts with no gated Groups emit nothing. The marker and JSON-LD are emitted only for crawler variants, so human responses carry no hint of which parts are gated.

### Declarations

Crawler reads happen outside blocks, in the structured-data output. The plugin auto-declares every crawler segment on singular posts whose declared set is not empty, so themes never have to declare them. This costs no human variants: a human reader has no crawler value, so the narrowed key is the same with or without the crawler segments.

### Adding, removing, and changing segments

| Change | Edge | Origin | Cache |
| --- | --- | --- | --- |
| Change a segment's user agents | Worker release. A crawler may move from one segment to another. | None, except the displayed list. | No purge. Variants are keyed on the segment value, not the user agent. |
| Add a segment | Worker release injects the new value. | Plugin release mirrors a new record. Editors can assign it. | No global purge. A post save purges its URL when an editor assigns the segment. |
| Remove a segment | Worker release stops injecting it. | Plugin release marks the mirrored record inactive. It is never deleted, so existing assignments survive and are shown as inactive. It leaves every declared set. | Global purge, as for any segment deactivation. |

### Version skew

The worker and the plugin are released separately, so one side can be ahead of the other. Both skews are safe:

- **The worker knows a segment the plugin does not.** The injected value reaches origin, but the segment is in no declared set, so narrowing drops it and nothing varies on it.
- **The plugin knows a segment the worker does not.** Editors can assign it, but no request ever carries the value, so crawlers receive the anonymous view for that Group.

To make skew visible, the worker sends its configuration version in `X-WPVIP-Content-Gates-Edge` on every origin request. The plugin compares it with its own copy and shows an admin notice when they differ. Releases of the worker and the plugin should ship the same `version`.

### Accepted limits

- **Forged cookies gain nothing.** The worker strips crawler groups from the incoming cookie before classifying, and the cookie it forwards to origin contains only its own classification. Origin never sees a browser-supplied crawler value.
- **Spoofing.** Classification uses the user agent. A spoofed user agent receives the crawler variant. This is the accepted risk already recorded in [segment-admin.md](segment-admin.md). Verified-bot data at the edge would reduce it and is a platform question.
- **Unrecognized crawlers** receive the anonymous view, with no structured data.
- **Rich Results.** Google's test must accept the crawler variant's markup. That is release evidence, not a design assumption.

### Platform questions

1. Does the VIP edge expose verified-bot information (IP ranges or reverse DNS) to a worker?
2. Can a phase 2 worker add a header and rewrite the cookie header on the origin request? The design depends on both.

