<?php
/**
 * Base test case for rendering tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Plugin;
use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Segments\Segment;
use ContentGates\Segments\Segment_Registry;
use WP_UnitTestCase;

/**
 * Runs the booted plugin against simulated readers.
 *
 * Readers are simulated the way the VIP edge presents them: through the
 * `X-VIP-Go-Segmentation` request header, parsed by the plugin's own
 * `Local_Cookie_Adapter`. Every test starts with an empty membership and
 * an empty consulted set.
 */
abstract class Gating_Test_Case extends WP_UnitTestCase {

	/**
	 * The plugin's registry.
	 *
	 * @var Segment_Registry
	 */
	protected Segment_Registry $registry;

	/**
	 * The plugin's membership layer.
	 *
	 * @var Membership
	 */
	protected Membership $membership;

	/**
	 * The plugin's adapter.
	 *
	 * @var Local_Cookie_Adapter
	 */
	protected Local_Cookie_Adapter $adapter;

	/**
	 * Header value present before the test, restored afterwards.
	 *
	 * @var string|null
	 */
	private ?string $saved_header = null;

	/**
	 * Point at the booted plugin and start from an anonymous reader.
	 */
	public function set_up(): void {
		parent::set_up();

		$plugin = Plugin::instance();

		$this->registry = $plugin->segments();
		$this->registry->invalidate_cache();
		$this->membership = $plugin->membership();

		$adapter = $this->membership->cache();
		$this->assertInstanceOf( Local_Cookie_Adapter::class, $adapter, 'Rendering tests simulate readers through the local adapter.' );
		$this->adapter = $adapter;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup saves raw request state.
		$header             = $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] ?? null;
		$this->saved_header = is_string( $header ) ? $header : null;

		$this->set_reader( array() );
	}

	/**
	 * Leave the shared adapter empty and restore request state.
	 */
	public function tear_down(): void {
		$this->set_reader( array() );

		unset( $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] );
		if ( null !== $this->saved_header ) {
			$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = $this->saved_header;
		}

		parent::tear_down();
	}

	/**
	 * Make the current request come from a reader with these memberships.
	 *
	 * Every group the adapter or registry knows is listed in the header
	 * with an empty value unless given, so earlier state is cleared. The
	 * consulted set is reset.
	 *
	 * @param array<string, string> $values Segment key => value.
	 */
	protected function set_reader( array $values ): void {
		$keys = array_unique(
			array_merge(
				array_keys( $this->adapter->groups() ),
				array_keys( $this->registry->all() ),
				array_keys( $values )
			)
		);

		$parts = array();
		foreach ( $keys as $key ) {
			$parts[] = $key . '_--_' . ( $values[ $key ] ?? '' );
		}

		$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = 'vc-v1__' . implode( '---__', $parts );
		$this->adapter->parse_request();
		$this->membership->consulted_set()->reset();
	}

	/**
	 * Create an active CPT segment.
	 *
	 * @param string $name Segment name.
	 */
	protected function create_segment( string $name ): Segment {
		$id = $this->registry->create( $name );
		$this->assertIsInt( $id, 'create() must return a post ID.' );

		$segment = $this->registry->find( $id );
		$this->assertInstanceOf( Segment::class, $segment );

		return $segment;
	}

	/**
	 * Create a published post without a manual excerpt and return its ID.
	 *
	 * The factory fills `post_excerpt` by default; an empty excerpt is what
	 * sends core down the auto-excerpt path.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Extra post args.
	 */
	protected function create_post( string $content, array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_content' => $content,
					'post_excerpt' => '',
				),
				$args
			)
		);
		$this->assertIsInt( $post_id );

		return $post_id;
	}

	/**
	 * Create a user with a role and return its ID.
	 *
	 * @param string $role Role slug.
	 */
	protected function create_user( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user_id );

		return $user_id;
	}

	/**
	 * Permalink of a post, asserted to exist.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function permalink( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		$this->assertIsString( $permalink );

		return $permalink;
	}

	/**
	 * Visit a front-end URL without marking the shared membership too late.
	 *
	 * `go_to()` runs `WP::main()`, which fires `send_headers`. The plugin's
	 * callbacks on that action flip the booted membership and adapter to
	 * "too late" for the rest of the PHP process, which would break every
	 * later test that assigns membership. They are detached for the call
	 * and re-attached afterwards.
	 *
	 * @param string $url URL to visit.
	 */
	protected function visit( string $url ): void {
		$mark  = array( $this->membership, 'mark_headers_sent' );
		$flush = array( $this->adapter, 'flush' );

		remove_action( 'send_headers', $mark, PHP_INT_MAX - 1 );
		remove_action( 'send_headers', $flush, PHP_INT_MAX );

		try {
			$this->go_to( $url );
		} finally {
			add_action( 'send_headers', $mark, PHP_INT_MAX - 1 );
			add_action( 'send_headers', $flush, PHP_INT_MAX );
		}

		$this->assertFalse( $this->membership->is_too_late(), 'Visiting a URL must not mark the shared membership too late.' );
	}

	/**
	 * Serialized Group block with a segment assignment.
	 *
	 * @param mixed                $segment_id Attribute value.
	 * @param string               $inner      Serialized inner blocks.
	 * @param array<string, mixed> $attrs      Extra attributes.
	 */
	protected function gated_group( mixed $segment_id, string $inner, array $attrs = array() ): string {
		$attrs = array_merge( array( Block_Enforcer::ATTRIBUTE => $segment_id ), $attrs );

		return '<!-- wp:group ' . wp_json_encode( $attrs ) . ' --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
	}

	/**
	 * Serialized Group block without a segment.
	 *
	 * @param string $inner Serialized inner blocks.
	 */
	protected function plain_group( string $inner ): string {
		return '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
	}

	/**
	 * Serialized paragraph block.
	 *
	 * @param string $text Paragraph text.
	 */
	protected function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Keys consulted so far, sorted.
	 *
	 * @return string[]
	 */
	protected function consulted(): array {
		return $this->membership->consulted();
	}

	/**
	 * Assert that an instance of a class is hooked at a priority.
	 *
	 * @param string $hook     Hook name.
	 * @param string $class_name Class name of the callback object.
	 * @param string $method     Method name.
	 * @param int    $priority   Priority.
	 */
	protected function assert_hooked( string $hook, string $class_name, string $method, int $priority ): void {
		$callbacks = $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? array();
		$this->assertIsArray( $callbacks );

		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			if ( ! is_array( $function ) ) {
				continue;
			}

			$object       = $function[0] ?? null;
			$found_method = $function[1] ?? null;
			if ( $object instanceof $class_name && $found_method === $method ) {
				$this->addToAssertionCount( 1 );

				return;
			}
		}

		$this->fail( sprintf( '%s::%s is not hooked to %s at priority %d.', $class_name, $method, $hook, $priority ) );
	}
}
