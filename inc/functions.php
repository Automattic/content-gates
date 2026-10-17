<?php
/**
 * Public Content Gates API.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

use ContentGates\Membership;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Public contract specified by the product API.

/**
 * Check whether the current reader belongs to a segment.
 *
 * Every call records the segment in the request's consulted set, including a
 * denied or invalid lookup.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 * @return bool Whether the current reader is in the active segment.
 */
function is_reader_in_segment( int $segment_id ): bool {
	return Membership::is_reader_in_segment( $segment_id );
}

/**
 * Assign the current reader to a segment.
 *
 * Contract version 1. Assignment must run before `send_headers`. The cookie
 * affects later requests; callers should use a POST-redirect-GET flow.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 * @return true|WP_Error True on success, or a Content Gates error.
 */
function assign_reader_to_segment( int $segment_id ) {
	return Membership::assign_reader_to_segment( $segment_id );
}

/**
 * Remove the current reader from a segment.
 *
 * Contract version 1. Removal must run before `send_headers`.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 * @return true|WP_Error True on success, or a Content Gates error.
 */
function remove_reader_from_segment( int $segment_id ) {
	return Membership::remove_reader_from_segment( $segment_id );
}

/**
 * Return segment IDs consulted by the current response.
 *
 * @since 0.1.0
 *
 * @return int[] Consulted segment post IDs.
 */
function content_gates_get_consulted_segments(): array {
	return Membership::get_consulted_segment_ids();
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
