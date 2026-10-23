<?php
/**
 * Public PHP API.
 *
 * The contract is specified in spec/api.md and documented for integrators in
 * docs/api.md. Callers import these with `use function`.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates;

use WP_Error;

/**
 * Assign the reader to a segment.
 *
 * Call after `init` priority 10 and before `send_headers`. Providers call
 * this on every origin request; the cookie lifetime bounds how long a lapsed
 * reader keeps the entitled view.
 *
 * @since 0.1.0
 *
 * @param int|string  $segment Segment key or post ID.
 * @param string|null $value   Value; `1` for a boolean segment. Meter segments refuse values.
 * @return true|WP_Error `content_gates_too_late`, `content_gates_invalid_segment`,
 *                       `content_gates_inactive_segment`, `content_gates_invalid_value`,
 *                       or `content_gates_platform_unavailable`.
 */
function assign_reader_to_segment( int|string $segment, ?string $value = null ): true|WP_Error {
	return Plugin::instance()->membership()->assign( $segment, $value );
}

/**
 * Remove the reader from a segment. Allowed for inactive segments.
 *
 * Same window as `assign_reader_to_segment()`.
 *
 * @since 0.1.0
 *
 * @param int|string $segment Segment key or post ID.
 * @return true|WP_Error `content_gates_too_late`, `content_gates_invalid_segment`,
 *                       or `content_gates_platform_unavailable`.
 */
function remove_reader_from_segment( int|string $segment ): true|WP_Error {
	return Plugin::instance()->membership()->remove( $segment );
}

/**
 * Declare that this response may read a segment outside block assignments.
 *
 * Call on the `wp` action or earlier. Declaring is free at origin and costs
 * variants at the edge, so declare only on requests that will read.
 *
 * @since 0.1.0
 *
 * @param int|string $segment Segment key or post ID.
 * @return true|WP_Error `content_gates_too_late`, `content_gates_invalid_segment`,
 *                       or `content_gates_inactive_segment`.
 */
function declare_segment( int|string $segment ): true|WP_Error {
	return Plugin::instance()->declared()->declare( $segment );
}

/**
 * Whether the reader is in a segment.
 *
 * Use at `template_redirect` or later. A segment that is not declared for
 * this response reads as `false` and raises `_doing_it_wrong()` under
 * `WP_DEBUG`. Without `$value`, a boolean segment is true for any value and
 * a meter segment is true for `open` or `last`.
 *
 * @since 0.1.0
 *
 * @param int|string  $segment Segment key or post ID.
 * @param string|null $value   Exact value to test, or null for any.
 */
function is_reader_in_segment( int|string $segment, ?string $value = null ): bool {
	return Plugin::instance()->membership()->is_in( $segment, $value );
}

/**
 * The reader's value for a segment, or null.
 *
 * Returns `1` for a boolean segment the reader is in, and `open`, `last`, or
 * `wall` for a meter. Subject to the declared-set rule like every read.
 *
 * @since 0.1.0
 *
 * @param int|string $segment Segment key or post ID.
 */
function get_reader_segment_value( int|string $segment ): ?string {
	return Plugin::instance()->membership()->value( $segment );
}

/**
 * The declared set for this response, as sorted group names.
 *
 * Meaningful once the set is closed (after the `wp` action on pages).
 *
 * @since 0.1.0
 *
 * @return string[]
 */
function get_declared_segments(): array {
	return Plugin::instance()->declared()->groups();
}
