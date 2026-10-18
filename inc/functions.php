<?php
/**
 * Public provider contract version 1.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

/**
 * Check a current-reader segment and record the response dependency.
 *
 * @param int $segment_id Site-local segment ID.
 * @return bool
 */
function content_gates_is_reader_in_segment( int $segment_id ): bool {
	return \ContentGates\Membership::instance()->contains( $segment_id );
}

/**
 * Assign or revoke a current-reader membership before headers.
 *
 * @param int  $segment_id Site-local segment ID.
 * @param bool $member Grant or revoke.
 * @param int  $version Provider contract version, not cookie version.
 * @return true|WP_Error
 */
function content_gates_assign_reader( int $segment_id, bool $member = true, int $version = 1 ) {
	return \ContentGates\Membership::instance()->assign( $segment_id, $member, $version );
}
