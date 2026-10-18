<?php
/**
 * Cache adapter contract.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use WP_Error;

/**
 * The only door between the plugin and the segmentation cookie.
 *
 * `Membership` reads and writes group values exclusively through this
 * interface. On WPVIP the implementation delegates to `Vary_Cache`; locally
 * it reproduces the same cookie format so the edge simulation matches
 * production byte for byte.
 *
 * Implementations return `WP_Error` with these codes from `set_group()`:
 *
 * - `content_gates_too_late`: headers were already sent for this request.
 * - `content_gates_invalid_segment_key`: the group name has characters
 *   outside `[A-Za-z0-9_-]`.
 * - `content_gates_invalid_value`: the value has characters outside
 *   `[A-Za-z0-9_-]`.
 * - `content_gates_not_registered`: the group was never registered.
 *
 * @since 0.1.0
 */
interface Cache_Adapter {

	/**
	 * Add hooks. Called once from the plugin bootstrap.
	 *
	 * @since 0.1.0
	 */
	public function register(): void;

	/**
	 * Declare a group the response may vary on.
	 *
	 * Must run before the cookie is parsed at `init` priority 10 so parsed
	 * values are not clobbered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 */
	public function register_group( string $group ): void;

	/**
	 * Set the reader's value for a group. An empty value removes the reader
	 * from the group.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 * @param string $value Value, or '' to remove.
	 * @return true|WP_Error See the interface docblock for codes. The
	 *                       native type is bool|WP_Error because the
	 *                       PHP 8.1 floor has no standalone true type.
	 */
	public function set_group( string $group, string $value ): bool|WP_Error;

	/**
	 * All registered groups with the reader's values.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Group => value; '' when the reader is not in the group.
	 */
	public function groups(): array;

	/**
	 * Whether the cookie write already happened for this request.
	 *
	 * @since 0.1.0
	 */
	public function did_send_headers(): bool;

	/**
	 * Write cookies now. Used by REST responses, where `send_headers` never
	 * fires. No-op when the write already happened.
	 *
	 * @since 0.1.0
	 */
	public function flush(): void;

	/**
	 * Whether this adapter simulates the edge locally instead of talking to
	 * `Vary_Cache`.
	 *
	 * @since 0.1.0
	 */
	public function is_local_simulation(): bool;
}
