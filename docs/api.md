# Public PHP API

Version 0.1.0. The contract is specified in `spec/api.md`; this page is the
integrator's view with worked examples.

Every function lives in `Automattic\VIP\ContentGates`. Import them:

```php
use function Automattic\VIP\ContentGates\assign_reader_to_segment;
use function Automattic\VIP\ContentGates\remove_reader_from_segment;
use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\is_reader_in_segment;
use function Automattic\VIP\ContentGates\get_reader_segment_value;
use function Automattic\VIP\ContentGates\get_declared_segments;
```

`$segment` is a segment key (`subscriber`) or the segment record's post ID.
Keys are the stable reference; the plugin resolves IDs to keys.

## Functions

| Function | Returns | Window |
| --- | --- | --- |
| `assign_reader_to_segment( $segment, ?string $value = null )` | `true\|WP_Error` | After `init` priority 10, before `send_headers` |
| `remove_reader_from_segment( $segment )` | `true\|WP_Error` | Same. Allowed for inactive segments, so stale cookies can be cleared |
| `declare_segment( $segment )` | `true\|WP_Error` | On the `wp` action or earlier (see Timing) |
| `is_reader_in_segment( $segment, ?string $value = null )` | `bool` | `template_redirect` and later |
| `get_reader_segment_value( $segment )` | `?string` | `template_redirect` and later |
| `get_declared_segments()` | `string[]` | After the declared set is final. Sorted group names |

Rules:

- A boolean segment stores `1`. A meter segment stores `open`, `last`, or
  `wall`, written only by the Edge Worker; `assign_reader_to_segment()` on
  a meter returns `content_gates_invalid_value`.
- `is_reader_in_segment()` with a value checks for that exact value. Without
  one it is true for any value on a boolean segment and for `open` or `last`
  on a meter, so "in the meter" means "has allowance left".
- **Reads fail closed.** A segment that is not in this response's declared
  set reads as `false` or `null`, whatever the cookie says, and raises
  `_doing_it_wrong()` under `WP_DEBUG`. So does a read made before the set
  is final. The reason is in [architecture.md](architecture.md#the-declared-set).
- Inactive segments are never declared, so they read as `false`. Assignment
  to one is refused.
- Unknown references return `false` or `null` from readers with a
  `_doing_it_wrong()` notice, and `content_gates_invalid_segment` from writers.

## Errors

| Code | Raised by | Meaning |
| --- | --- | --- |
| `content_gates_too_late` | assign, remove, declare | The hook window closed |
| `content_gates_invalid_segment` | assign, remove, declare | The reference resolves to no segment |
| `content_gates_inactive_segment` | assign, declare | The segment is inactive |
| `content_gates_invalid_value` | assign | Bad characters, or a meter segment |
| `content_gates_platform_unavailable` | assign, remove | `Vary_Cache` is not loaded outside a `local` environment |

## Timing

The main query is resolved, headers go out, and templates load in this
order on a page request. Everything the plugin does happens before the first
byte of output.

| Step | Hook | Who |
| --- | --- | --- |
| Segment post type registered; crawler records synced on admin requests | `init` 0, 1 | Plugin |
| Every segment registered as a `Vary_Cache` group | `init` 5 | Plugin. Must stay before 10: `register_groups()` resets values |
| Cookie parsed into membership | `init` 10 | `Vary_Cache` |
| Providers assign or remove membership | `init` 11 and later, up to `send_headers` | Provider |
| Write window closes; `Vary` and `Set-Cookie` sent | `send_headers` `PHP_INT_MAX - 1`, then `PHP_INT_MAX` | Plugin, then `Vary_Cache` |
| Site code declares segments it reads outside blocks | `wp` (default priority) | Theme or plugin |
| Declared set built; filter runs; declared header sent | `wp` `PHP_INT_MAX` | Plugin |
| Reads | `template_redirect` and later | Blocks, templates, shortcodes |

Core fires `send_headers` **before** the `wp` action (`WP::main()`), so the
plugin finalizes the declared set at the end of `wp`, not on `send_headers`.
Both run before output and before the template loads, and declarations on
`wp` are exactly as the spec's examples show. Note the asymmetry: membership
writes close at `send_headers`, declarations close at the end of `wp`.

REST requests never reach `send_headers` or `wp`. There the declared set
grows while the response is built (each post's stored set is merged just
before its content renders) and the header, `Vary`, and any pending cookie
are sent in `rest_pre_echo_response`, the last filter before the JSON is
echoed. A provider route that assigns membership needs nothing extra; it
must assign before the response is served.

## Examples

**A subscriber-only item in the site header, on every page.** Every URL
really has two variants, so declare site-wide.

```php
add_filter( 'content_gates_declared_segments', function ( array $segments ): array {
	$segments[] = 'subscriber';
	return $segments;
} );

// header.php
if ( is_reader_in_segment( 'subscriber' ) ) {
	get_template_part( 'template-parts/nav', 'subscriber' );
}
```

**A different single-post template for donors.** Declare only where the read
happens.

```php
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

**A members-only shortcode.** The shortcode's owner declares when the content
contains it.

```php
add_filter( 'content_gates_declared_segments', function ( array $segments, WP_Query $query ): array {
	$post = $query->get_queried_object();
	if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'members_only' ) ) {
		$segments[] = 'subscriber';
	}
	return $segments;
}, 10, 2 );

add_shortcode( 'members_only', function ( array $atts, string $content = '' ): string {
	return is_reader_in_segment( 'subscriber' ) ? do_shortcode( $content ) : '';
} );
```

**Forgetting to declare.** The read returns `false` for everyone and, under
`WP_DEBUG`, logs:

```
is_reader_in_segment(): Segment "subscriber" was read but not declared for this request. Declare it on the `wp` action or through the content_gates_declared_segments filter.
```

Nothing leaks and no cached copy is wrong. The fix is one declaration.

## Platform loading

`Vary_Cache` is opt-in on WPVIP. The plugin requires
`cache/class-vary-cache.php` from the mu-plugins directory. When it is
missing and `WP_ENVIRONMENT_TYPE` is `local`, a verbatim upstream copy
bundled in `inc/platform/vendor/` is loaded so wp-env and PHPUnit behave
like the platform. Anywhere else the plugin fails closed.

## Rules for callers

All membership reads go through this API. A direct call to
`Vary_Cache::is_user_in_group()`, `Vary_Cache::is_user_in_group_segment()`,
or `Vary_Cache::get_groups()` outside the plugin bypasses the declared-set
check and is a defect. (The spec asks for a PHPCS sniff that flags those
calls; it is not written yet.)
