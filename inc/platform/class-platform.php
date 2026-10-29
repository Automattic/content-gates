<?php
/**
 * Platform loader for WPVIP's Vary_Cache.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Platform;

use Automattic\VIP\Cache\Vary_Cache;
use ReflectionClass;

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
	 * Whether the loaded `Vary_Cache` expires an emptied group cookie itself.
	 *
	 * Null until `expires_empty_cookie()` first checks.
	 *
	 * @var bool|null
	 */
	private ?bool $expires_empty_cookie = null;

	/**
	 * Load `Vary_Cache`. Safe to call more than once.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		if ( class_exists( Vary_Cache::class, false ) ) {
			$this->available = true;
			return;
		}

		$platform_file = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/cache/class-vary-cache.php';
		if ( is_readable( $platform_file ) ) {
			require_once $platform_file;
			$this->available = class_exists( Vary_Cache::class, false );
			return;
		}

		if ( 'local' === wp_get_environment_type() ) {
			require_once __DIR__ . '/vendor/class-vary-cache.php';
			$this->available  = true;
			$this->local_copy = true;
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
	 * Whether `Vary_Cache` expires the group cookie itself when every group is empty.
	 *
	 * Older `Vary_Cache` skips the cookie write when every group is empty and
	 * leaves the old cookie in place. vip-go-mu-plugins #7454 changed
	 * `set_group_cookie()` to expire the active group cookie instead, when the
	 * request carried it, and added the private `has_group_cookie()` for that
	 * check. That method is the only sign of the change, so it is the signal.
	 * `ReflectionClass::hasMethod()` sees private methods. `method_exists()`
	 * would too, but static analysis folds it to the bundled copy's answer,
	 * and the class that runs depends on the platform.
	 *
	 * If upstream renames the method, this returns false and
	 * `Membership::expire_empty_cookie()` sends a second, identical expiry,
	 * which is redundant but harmless.
	 *
	 * Checked on first use, not in `load()`: only a removal of the reader's
	 * last group needs it, so other requests skip the reflection.
	 *
	 * @since 0.1.0
	 */
	public function expires_empty_cookie(): bool {
		if ( ! $this->available ) {
			return false;
		}

		$this->expires_empty_cookie ??= ( new ReflectionClass( Vary_Cache::class ) )->hasMethod( 'has_group_cookie' );

		return $this->expires_empty_cookie;
	}
}
