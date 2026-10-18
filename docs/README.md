# Content Gates documentation

Content Gates is a WordPress plugin for cache-aware content gating on
WPVIP. It is a gating primitive. It needs an entitlement provider: the
plugin never decides who is entitled. A provider (Newspack, WooCommerce,
or any other system) puts a reader into a segment through the public
contract. The plugin then withholds gated Group blocks from readers outside
that segment on page, REST, and feed responses, and tells the edge cache
which segments each response depended on.

## Status

Prototype. Local verification only; not yet exercised on a WPVIP
application. Every statement about WPVIP edge behavior in these documents
is an assumption until it is tested on a VIP test application. See
[Known limitations](known-limitations.md) for the full list.

## Documents

| Document | Read it for |
| --- | --- |
| [Provider contract](provider-contract.md) | The versioned API providers and templates build against: the five public functions, error codes, timing rules, cookie mechanism, the REST endpoint, and the deprecation policy. |
| [Architecture](architecture.md) | Components, request flows, the consulted-set rule, response buffering, the segment model, the block gating decision table, the two cache adapters, the edge simulator, and the local demo. |
| [Known limitations](known-limitations.md) | What is unverified on WPVIP, what was cut on purpose, what stakeholders must confirm, and the accepted risks. |
| [Leak-surface matrix](leak-surface-matrix.md) | Each delivery surface, the mechanism that protects it, and the test that proves it (or "not yet tested"). |
| `../spec/` | Product specification and decisions. The specs win over these documents when they disagree. |
| `../tests/debugging/README.md` | How to run the local edge cache simulator. |

## Public hook reference

Hook names use the `content_gates_` prefix. The spec's `content_gate_*`
names were placeholders. All hooks are since plugin version 0.1.0 and
belong to contract version 1.

### Actions

#### `content_gates_loaded`

Fires once, at the end of `ContentGates\Plugin::boot()`, while the plugin
file is being loaded. That is before `init`. Providers hook here to
register code-defined segments and their assignment handlers.

| Argument | Type | Description |
| --- | --- | --- |
| `$plugin` | `ContentGates\Plugin` | The booted plugin instance. `segments()`, `membership()`, and `finalizer()` are available. |

Since 0.1.0.

```php
add_action( 'content_gates_loaded', function () {
	register_segment( 'newsletter' );
} );
```

#### `content_gates_finalize_variance`

Fires once the consulted set is final for this response. On front-end
requests this happens on `shutdown` (priority 0). On a REST request it
happens on `rest_post_dispatch` (priority `PHP_INT_MAX`). The arguments are
the values the response headers are built from, after the
`content_gates_consulted_segments` filter has run. Use it for debug output
or a custom edge signal. Do not use it to change membership: the request
is already too late for writes.

| Argument | Type | Description |
| --- | --- | --- |
| `$consulted` | `string[]` | Sorted, unique segment keys the render read. Empty when nothing was consulted. |
| `$variant_key` | `array<string, string>` | Key => the reader's value, or `'none'` when the reader is not in that segment. Same keys as `$consulted`, in the same order. |

Since 0.1.0.

```php
add_action( 'content_gates_finalize_variance', function ( array $consulted, array $variant_key ) {
	error_log( 'consulted: ' . implode( ',', $consulted ) );
}, 10, 2 );
```

#### `vip_vary_cache_did_send_headers`

This is WPVIP's `Vary_Cache` action. On WPVIP, `Vary_Cache` fires it
itself after it writes the cookie. The plugin's local adapter
(`Local_Cookie_Adapter`) fires the same action, with the same arguments,
so provider redirect code written for VIP works unchanged on a local site.
The local adapter fires it only when a cookie was actually written, and
`$sent_vary` is always `false` there because the local adapter never sends
a `Vary` header.

| Argument | Type | Description |
| --- | --- | --- |
| `$sent_vary` | `bool` | Whether a `Vary` header was sent. Always `false` from the local adapter. |
| `$sent_cookie` | `bool` | Whether a `Set-Cookie` header was sent. Always `true` from the local adapter. |

Since 0.1.0 (local adapter). On WPVIP the action belongs to `Vary_Cache`.

```php
// Redirect after a successful assignment (post-redirect-get).
add_action( 'vip_vary_cache_did_send_headers', function () { wp_safe_redirect( wp_get_referer() ); exit; } );
```

### Filters

#### `content_gates_consulted_segments`

The last chance to adjust the set of segments a response reports as
consulted. The returned value is cast to strings, de-duplicated, and
sorted before use. Adding a key is safe: the edge stores more variants
than needed. Removing a key is dangerous: the edge may then serve one
reader's page to another.

| Argument | Type | Description |
| --- | --- | --- |
| `$consulted` | `string[]` | Sorted segment keys read during render. |

Return `string[]`. Since 0.1.0.

```php
add_filter( 'content_gates_consulted_segments', function ( array $consulted ) {
	return array_merge( $consulted, array( 'region' ) ); // force a variant per region
} );
```

#### `content_gates_rest_membership_permission`

The provider authorization hook for `POST /content-gates/v1/membership`.
The plugin never decides entitlement itself: the default is `false`, so
without a provider every write is refused with `content_gates_forbidden`
(HTTP 403).

| Argument | Type | Description |
| --- | --- | --- |
| `$allowed` | `bool\|WP_Error` | Whether the request may change membership. Default `false`. |
| `$request` | `WP_REST_Request` | The request, with `segment`, `action` and `value` params already sanitized. Numeric `segment` strings are already integers here. |

Return `true` to allow. Return a `WP_Error` to refuse with your own code,
message and `status` (sent as-is). Any other value refuses with
`content_gates_forbidden`. Since 0.1.0.

```php
add_filter( 'content_gates_rest_membership_permission', function ( $allowed, WP_REST_Request $request ) {
	return my_provider_allows( $request->get_param( 'segment' ), $request->get_param( 'action' ) );
}, 10, 2 );
```

#### `content_gates_cookie_expiry`

Lifetime of the simulated segmentation cookie, in seconds. Only the local
adapter applies this filter. On WPVIP, `Vary_Cache` owns the cookie and
its lifetime is set with `Vary_Cache::set_cookie_expiry()`, which this
plugin does not call.

| Argument | Type | Description |
| --- | --- | --- |
| `$expiry` | `int` | Seconds until the cookie expires. Default `MONTH_IN_SECONDS`. |

Return `int`. Since 0.1.0.

```php
add_filter( 'content_gates_cookie_expiry', fn () => DAY_IN_SECONDS );
```

## Debug notices

Under `WP_DEBUG` the plugin raises `_doing_it_wrong()` when a read names
an unknown segment and when a Group block references a segment ID that
does not exist. It raises an `E_USER_WARNING` through `wp_trigger_error()`
when the serialized cookie exceeds 3072 bytes. Debug output never changes
behavior.

## REST API

The uncacheable membership endpoint is `content-gates/v1/membership`.
Its request and response shapes, headers, error codes, and an example for
an MCP-style caller are in the
[provider contract](provider-contract.md#the-rest-endpoint).
