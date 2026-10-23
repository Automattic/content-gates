<?php
/**
 * Platform loader for WPVIP's Vary_Cache.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Platform;

use Automattic\VIP\Cache\Vary_Cache;

/**
 * Loads `Vary_Cache` and reports whether the cache personalization API is usable.
 *
 * `Vary_Cache` is opt-in on WPVIP: nothing loads `cache/class-vary-cache.php`
 * by itself. This class requires it from the mu-plugins directory. When the
 * platform copy is missing and the environment is `local`, a verbatim upstream
 * copy bundled with the plugin is loaded instead so wp-env and PHPUnit behave
 * like the platform. Anywhere else the plugin fails closed: reads return
 * `false`, assignments return `content_gates_platform_unavailable`, and gated
 * Groups are withheld for everyone.
 *
 * @since 0.1.0
 */
final class Platform {

	/**
	 * Whether `Vary_Cache` is loaded.
	 *
	 * @var bool
	 */
	private bool $available = false;

	/**
	 * Whether the bundled local copy was used.
	 *
	 * @var bool
	 */
	private bool $local_copy = false;

	/**
	 * Load `Vary_Cache`. Safe to call more than once.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		if ( class_exists( Vary_Cache::class, false ) ) {
			$this->available = true;
			$this->configure();
			return;
		}

		$platform_file = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cache/class-vary-cache.php';
		if ( is_readable( $platform_file ) ) {
			require_once $platform_file;
			$this->available = class_exists( Vary_Cache::class, false );
			$this->configure();
			return;
		}

		if ( 'local' === wp_get_environment_type() ) {
			require_once __DIR__ . '/vendor/class-vary-cache.php';
			$this->available  = true;
			$this->local_copy = true;
			$this->configure();
		}
	}

	/**
	 * Whether the cache personalization API can be used on this request.
	 *
	 * @since 0.1.0
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Whether the bundled upstream copy is in use (local environments only).
	 *
	 * @since 0.1.0
	 */
	public function is_local_copy(): bool {
		return $this->local_copy;
	}

	/**
	 * Apply plugin defaults to `Vary_Cache`.
	 *
	 * Providers re-assert membership on every origin request, so the cookie
	 * lifetime bounds how long a lapsed reader keeps the entitled view.
	 */
	private function configure(): void {
		/**
		 * Filters the segmentation cookie lifetime in seconds.
		 *
		 * `Vary_Cache` refuses values under one hour.
		 *
		 * @since 0.1.0
		 *
		 * @param int $lifetime Lifetime in seconds. Default one day.
		 */
		$lifetime = (int) apply_filters( 'content_gates_cookie_lifetime', DAY_IN_SECONDS );
		Vary_Cache::set_cookie_expiry( max( HOUR_IN_SECONDS, $lifetime ) );
	}
}
