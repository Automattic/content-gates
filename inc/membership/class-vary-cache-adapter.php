<?php
/**
 * Vary_Cache adapter.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Membership;

use Automattic\VIP\Cache\Vary_Cache;
use WP_Error;

/**
 * Delegates membership storage to WPVIP's `Vary_Cache`.
 *
 * `Vary_Cache` owns parsing (`init` 10), the cookie write and the `Vary`
 * header (`send_headers` PHP_INT_MAX). This adapter only forwards calls
 * and translates `WP_Error` codes:
 *
 * | Vary_Cache code                     | Content Gates code                  |
 * |-------------------------------------|-------------------------------------|
 * | `did_send_headers`                  | `content_gates_too_late`            |
 * | `invalid_vary_group_name`           | `content_gates_invalid_segment_key` |
 * | `invalid_vary_group_segment`        | `content_gates_invalid_value`       |
 * | `invalid_vary_group_notregistered`  | `content_gates_not_registered`      |
 * | anything else                       | `content_gates_cache_error`         |
 *
 * `did_send_headers()` flips at `send_headers` priority PHP_INT_MAX - 1
 * (one step before `Vary_Cache`) or when `flush()` runs, so callers see
 * "too late" at the same moment `Vary_Cache` would start rejecting writes.
 *
 * @since 0.1.0
 */
final class Vary_Cache_Adapter implements Cache_Adapter {

	/**
	 * Whether `send_headers` ran or `flush()` was called.
	 *
	 * @var bool
	 */
	private bool $did_send_headers = false;

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'send_headers', array( $this, 'mark_sent' ), PHP_INT_MAX - 1 );
	}

	/**
	 * Register a group with `Vary_Cache`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 */
	public function register_group( string $group ): void {
		Vary_Cache::register_group( $group );
	}

	/**
	 * Set or remove ('') the reader's value for a group.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 * @param string $value Value, or '' to remove.
	 * @return true|WP_Error
	 */
	public function set_group( string $group, string $value ): bool|WP_Error {
		$result = Vary_Cache::set_group_for_user( $group, $value );

		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		$code = match ( (string) $result->get_error_code() ) {
			'did_send_headers'                 => 'content_gates_too_late',
			'invalid_vary_group_name'          => 'content_gates_invalid_segment_key',
			'invalid_vary_group_segment'       => 'content_gates_invalid_value',
			'invalid_vary_group_notregistered' => 'content_gates_not_registered',
			default                            => 'content_gates_cache_error',
		};

		return new WP_Error( $code, $result->get_error_message(), array( 'vary_cache_code' => $result->get_error_code() ) );
	}

	/**
	 * Registered groups with values.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		$groups = array();
		foreach ( Vary_Cache::get_groups() as $group => $value ) {
			$groups[ (string) $group ] = (string) $value;
		}

		return $groups;
	}

	/**
	 * Whether the cookie write already happened.
	 *
	 * @since 0.1.0
	 */
	public function did_send_headers(): bool {
		return $this->did_send_headers;
	}

	/**
	 * Ask `Vary_Cache` to write its headers now. Idempotent on its side.
	 *
	 * @since 0.1.0
	 */
	public function flush(): void {
		$this->did_send_headers = true;
		Vary_Cache::send_headers();
	}

	/**
	 * Not a simulation.
	 *
	 * @since 0.1.0
	 */
	public function is_local_simulation(): bool {
		return false;
	}

	/**
	 * Hook callback: record that `send_headers` reached our priority.
	 *
	 * @since 0.1.0
	 */
	public function mark_sent(): void {
		$this->did_send_headers = true;
	}
}
