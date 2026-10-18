<?php
/**
 * Public provider and template contract.
 *
 * These five functions are the versioned contract providers and templates
 * build against (contract version 1, see CONTENT_GATES_CONTRACT_VERSION).
 * Their names are fixed by spec/content-gating-api.md and are deliberately
 * unprefixed. Each is a thin wrapper over the membership layer.
 *
 * Timing: assignment must happen before the `send_headers` hook. On a
 * cache HIT no PHP runs, so providers assigning during a cacheable GET
 * must use the POST-redirect-GET flow or the membership REST endpoint.
 *
 * @package ContentGates
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Public contract names are set by spec/content-gating-api.md.

declare( strict_types=1 );

use ContentGates\Membership\Membership;
use ContentGates\Plugin;

if ( ! function_exists( 'content_gates_membership' ) ) {
	/**
	 * The booted membership layer, or a WP_Error before boot.
	 *
	 * @since 0.1.0
	 *
	 * @return Membership|WP_Error Error code `content_gates_not_booted`.
	 */
	function content_gates_membership(): Membership|WP_Error {
		try {
			return Plugin::instance()->membership();
		} catch ( LogicException $e ) {
			return new WP_Error( 'content_gates_not_booted', 'Content Gates is not booted yet. Call this after the content_gates_loaded action.' );
		}
	}
}

if ( ! function_exists( 'register_segment' ) ) {
	/**
	 * Register a code-defined segment. Code segments have no post ID and
	 * are always active. Call on `content_gates_loaded` or `init` before
	 * priority 1.
	 *
	 * @since 0.1.0
	 * @contract 1
	 *
	 * @param string $key Segment key matching /^[A-Za-z0-9_-]+$/.
	 * @return true|WP_Error Codes: `content_gates_not_booted`,
	 *                       `content_gates_invalid_segment_key`,
	 *                       `content_gates_duplicate_segment_key`.
	 */
	function register_segment( string $key ): bool|WP_Error {
		try {
			return Plugin::instance()->segments()->register_key( $key );
		} catch ( LogicException $e ) {
			return new WP_Error( 'content_gates_not_booted', 'Content Gates is not booted yet. Call this after the content_gates_loaded action.' );
		}
	}
}

if ( ! function_exists( 'assign_reader_to_segment' ) ) {
	/**
	 * Put the current reader into a segment. Takes effect from the next
	 * request that carries the cookie.
	 *
	 * @since 0.1.0
	 * @contract 1
	 *
	 * @param int|string $segment Segment post ID or key.
	 * @param string     $value   Value to store. Default '1'.
	 * @return true|WP_Error Codes: `content_gates_not_booted`,
	 *                       `content_gates_too_late`,
	 *                       `content_gates_invalid_segment`,
	 *                       `content_gates_inactive_segment`,
	 *                       `content_gates_invalid_value`,
	 *                       `content_gates_invalid_segment_key`,
	 *                       `content_gates_not_registered`,
	 *                       `content_gates_cache_error`.
	 */
	function assign_reader_to_segment( int|string $segment, string $value = '1' ): bool|WP_Error {
		$membership = content_gates_membership();
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}

		return $membership->assign( $segment, $value );
	}
}

if ( ! function_exists( 'remove_reader_from_segment' ) ) {
	/**
	 * Take the current reader out of a segment.
	 *
	 * @since 0.1.0
	 * @contract 1
	 *
	 * @param int|string $segment Segment post ID or key.
	 * @return true|WP_Error Codes: `content_gates_not_booted`,
	 *                       `content_gates_too_late`,
	 *                       `content_gates_invalid_segment`,
	 *                       `content_gates_inactive_segment`,
	 *                       `content_gates_invalid_segment_key`,
	 *                       `content_gates_not_registered`,
	 *                       `content_gates_cache_error`.
	 */
	function remove_reader_from_segment( int|string $segment ): bool|WP_Error {
		$membership = content_gates_membership();
		if ( is_wp_error( $membership ) ) {
			return $membership;
		}

		return $membership->remove( $segment );
	}
}

if ( ! function_exists( 'is_reader_in_segment' ) ) {
	/**
	 * Whether the current reader is in a segment. Records the read so the
	 * response is keyed on this segment.
	 *
	 * Returns false for unknown segments (with `_doing_it_wrong()` under
	 * WP_DEBUG), inactive segments, and before boot.
	 *
	 * @since 0.1.0
	 * @contract 1
	 *
	 * @param int|string  $segment Segment post ID or key.
	 * @param string|null $value   When given, require this exact value.
	 */
	function is_reader_in_segment( int|string $segment, ?string $value = null ): bool {
		$membership = content_gates_membership();
		if ( is_wp_error( $membership ) ) {
			return false;
		}

		return $membership->is_in( $segment, $value );
	}
}

if ( ! function_exists( 'get_reader_segment_value' ) ) {
	/**
	 * The current reader's value for a segment, or null when not a member.
	 * Records the read.
	 *
	 * Returns null for unknown segments (with `_doing_it_wrong()` under
	 * WP_DEBUG), inactive segments, and before boot.
	 *
	 * @since 0.1.0
	 * @contract 1
	 *
	 * @param int|string $segment Segment post ID or key.
	 */
	function get_reader_segment_value( int|string $segment ): ?string {
		$membership = content_gates_membership();
		if ( is_wp_error( $membership ) ) {
			return null;
		}

		return $membership->value( $segment );
	}
}
