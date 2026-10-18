<?php
/**
 * Public Content Gates membership functions.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use ContentGates\Membership\Membership_Service;
use WP_Error;

/**
 * Check whether the current reader is in a segment.
 *
 * Every call records an active segment as a dependency of the current
 * response, including calls that return false.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 */
function content_gates_is_reader_in_segment( int $segment_id ): bool {
	return Membership_Service::instance()->is_reader_in_segment( $segment_id );
}

/**
 * Assign the current reader to an active segment.
 *
 * Contract version: 1.0.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 * @return true|WP_Error
 */
function content_gates_assign_reader_to_segment( int $segment_id ) {
	return Membership_Service::instance()->assign_reader_to_segment( $segment_id );
}

/**
 * Remove the current reader from a segment.
 *
 * Contract version: 1.0. Removal is allowed for inactive segments.
 *
 * @since 0.1.0
 *
 * @param int $segment_id Segment post ID.
 * @return true|WP_Error
 */
function content_gates_remove_reader_from_segment( int $segment_id ) {
	return Membership_Service::instance()->remove_reader_from_segment( $segment_id );
}
