<?php
/**
 * Shared test base.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Plugin;
use Automattic\VIP\ContentGates\Rendering\Block_Gate;
use Automattic\VIP\ContentGates\Segments\Segment;
use WP_UnitTestCase;

/**
 * Resets per-request plugin state and `Vary_Cache` between tests, and
 * offers helpers for building gated content and simulating readers.
 *
 * One PHP process runs every test, so what the plugin treats as a request
 * has to be reset by hand: the declared set, membership, the header record,
 * and `Vary_Cache`'s static state.
 */
abstract class Content_Gates_Test_Case extends WP_UnitTestCase {

	/**
	 * Secret marker that must never appear in a non-entitled response.
	 */
	public const SECRET = 'GATED-SECRET-7f3a';

	/**
	 * Start every test from a clean request.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->ignore_headers_sent_warnings();
		$this->new_request();
	}

	/**
	 * Leave no reader cookie behind.
	 */
	public function tear_down(): void {
		restore_error_handler();
		unset( $_COOKIE['vip-go-seg'] );
		$this->new_request();
		parent::tear_down();
	}

	/**
	 * Reset request state: plugin components, headers, `Vary_Cache`.
	 *
	 * Groups are registered again so writes work, as `init` 5 would have.
	 */
	protected function new_request(): void {
		Vary_Cache::unload();
		Vary_Cache::load();
		Headers::reset();
		$this->unhook_stale_instances();
		Plugin::instance()->reset_request_state();
		Plugin::instance()->membership()->register_groups();
	}

	/**
	 * Remove hooks that point at per-request component instances.
	 *
	 * `WP_UnitTestCase` snapshots `$wp_filter` in `set_up()` and restores it
	 * in `tear_down()`. The restore brings back callbacks bound to the
	 * instances that existed at snapshot time, which the plugin no longer
	 * holds, so a stale instance would run next to the fresh one. Removing
	 * every callback bound to those classes keeps one instance per request.
	 */
	private function unhook_stale_instances(): void {
		$classes = array(
			\Automattic\VIP\ContentGates\Membership\Declared_Set::class,
			\Automattic\VIP\ContentGates\Membership\Membership::class,
			\Automattic\VIP\ContentGates\Rendering\Block_Gate::class,
			\Automattic\VIP\ContentGates\Rendering\Excerpt_Guard::class,
			\Automattic\VIP\ContentGates\Rendering\Structured_Data::class,
		);

		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( ! is_array( $function ) || ! is_object( $function[0] ?? null ) ) {
						continue;
					}
					foreach ( $classes as $class ) {
						if ( $function[0] instanceof $class ) {
							remove_filter( (string) $hook_name, $function, (int) $priority );
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * The plugin instance.
	 */
	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Create an active boolean segment.
	 *
	 * @param string $name Display name; the key derives from it.
	 */
	protected function create_segment( string $name ): Segment {
		$segment = $this->plugin()->segments()->create( $name );
		$this->assertInstanceOf( Segment::class, $segment, 'Segment creation should succeed.' );
		$this->new_request();

		return $segment;
	}

	/**
	 * Create an active meter segment.
	 *
	 * @param string $name   Display name.
	 * @param int    $limit  Articles per period.
	 * @param int    $period Period in seconds.
	 */
	protected function create_meter( string $name, int $limit = 5, int $period = 2592000 ): Segment {
		$segment = $this->plugin()->segments()->create( $name, Segment::TYPE_METER, $limit, $period );
		$this->assertInstanceOf( Segment::class, $segment, 'Meter creation should succeed.' );
		$this->new_request();

		return $segment;
	}

	/**
	 * Give the current request a reader cookie and parse it, as `init` 10 does.
	 *
	 * @param array<string, string> $groups Group name => value.
	 */
	protected function become_reader( array $groups ): void {
		if ( array() === $groups ) {
			unset( $_COOKIE['vip-go-seg'] );
		} else {
			ksort( $groups );
			$pairs = array();
			foreach ( $groups as $group => $value ) {
				$pairs[] = $group . '_--_' . $value;
			}
			$_COOKIE['vip-go-seg'] = 'vc-v1__' . implode( '---__', $pairs );
		}

		Vary_Cache::parse_cookies();
	}

	/**
	 * Serialized Group block gated on segments.
	 *
	 * @param string[] $keys  Segment keys.
	 * @param string   $inner Inner block markup.
	 * @param string   $mode  `in` or `not-in`.
	 */
	protected function gated_group( array $keys, string $inner, string $mode = Block_Gate::MODE_IN ): string {
		$attrs = wp_json_encode(
			array(
				Block_Gate::ATTR_SEGMENTS => $keys,
				Block_Gate::ATTR_MODE     => $mode,
			)
		);

		return "<!-- wp:group {$attrs} -->\n<div class=\"wp-block-group\">{$inner}</div>\n<!-- /wp:group -->";
	}

	/**
	 * Serialized paragraph block.
	 *
	 * @param string $text Paragraph text.
	 */
	protected function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Create a published post whose content has one public paragraph and one gated Group.
	 *
	 * @param string[] $keys Segment keys for the Group.
	 * @param string   $mode `in` or `not-in`.
	 */
	protected function create_gated_post( array $keys, string $mode = Block_Gate::MODE_IN ): int {
		return $this->create_post(
			array(
				'post_content' => $this->paragraph( 'PUBLIC-INTRO' ) . "\n\n" . $this->gated_group( $keys, $this->paragraph( self::SECRET ), $mode ),
			)
		);
	}

	/**
	 * Create a published post and return its ID.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	protected function create_post( array $args ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_status'  => 'publish',
					// The factory fills in a manual excerpt by default, which would bypass excerpt generation.
					'post_excerpt' => '',
				),
				$args
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Create a user with a role and return the ID.
	 *
	 * @param string $role Role.
	 */
	protected function create_user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * A post's permalink as a string.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function permalink( int $post_id ): string {
		$link = get_permalink( $post_id );
		$this->assertIsString( $link );

		return $link;
	}

	/**
	 * Simulate a front-end request for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function request_post( int $post_id ): void {
		$this->request_page( $this->permalink( $post_id ) );
	}

	/**
	 * Simulate a front-end page request to a URL: main query, `send_headers`, `wp`.
	 *
	 * @param string $url URL.
	 */
	protected function request_page( string $url ): void {
		$this->go_to( $url );
	}

	/**
	 * Install an error handler that ignores "headers already sent" warnings.
	 *
	 * PHPUnit has already written to stdout, so `header()` and
	 * `setrawcookie()` inside `Vary_Cache` warn that headers were sent. Those
	 * calls are `Vary_Cache`'s responsibility and PHPUnit's output is the
	 * cause, so only that warning is swallowed; everything else goes to the
	 * handler that was already installed.
	 */
	private function ignore_headers_sent_warnings(): void {
		/**
		 * The handler that was installed before ours, so other errors still reach it.
		 *
		 * @var callable|null $previous
		 */
		$previous = null;
		$handler  = static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$previous ): bool {
			if ( str_contains( $errstr, 'Cannot modify header information' ) ) {
				return true;
			}
			if ( null !== $previous ) {
				return (bool) call_user_func( $previous, $errno, $errstr, $errfile, $errline );
			}
			return false;
		};
		$previous = set_error_handler( $handler ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test isolation.
	}

	/**
	 * Record the arguments of the next `vip_vary_cache_did_send_headers`.
	 *
	 * @return callable(): array<int, bool> The recorded pair, or an empty array before it fired.
	 */
	protected function capture_did_send_headers(): callable {
		$sent = array();
		add_action(
			'vip_vary_cache_did_send_headers',
			static function ( bool $vary, bool $cookie ) use ( &$sent ): void {
				$sent = array( $vary, $cookie );
			},
			10,
			2
		);

		return static function () use ( &$sent ): array {
			return $sent;
		};
	}

	/**
	 * Record every global purge request from now on.
	 *
	 * @return callable(): string[] The reasons recorded so far.
	 */
	protected function capture_purges(): callable {
		$reasons = array();
		add_action(
			'content_gates_purge_all',
			static function ( string $reason ) use ( &$reasons ): void {
				$reasons[] = $reason;
			}
		);

		return static function () use ( &$reasons ): array {
			return $reasons;
		};
	}

	/**
	 * Render a post's content the way `the_content()` does inside the loop.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function render_content( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Mirrors the loop.
		setup_postdata( $post );
		$content = (string) apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();

		return $content;
	}
}
