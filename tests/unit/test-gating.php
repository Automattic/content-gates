<?php
/**
 * Adversarial membership, rendering and delivery coverage.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Tests\Unit;

use ContentGates\Membership;
use ContentGates\Protection;
use ContentGates\Segments;
use WP_UnitTestCase;

require_once __DIR__ . '/class-fake-transport.php';

/** Verify complete bytes and side effects, not visual hiding. */
final class Test_Gating extends WP_UnitTestCase {
	/**
	 * Injected transport.
	 *
	 * @var Fake_Transport
	 */
	private Fake_Transport $transport;
	/**
	 * Request state.
	 *
	 * @var Membership
	 */
	private Membership $membership;
	/**
	 * Segment IDs.
	 *
	 * @var list<int>
	 */
	private array $ids = array();

	/** Build two real persistent definitions and an isolated request. */
	public function set_up(): void {
		parent::set_up();
		$this->ids = array();
		foreach ( array( 'Subscriber', 'Newsletter' ) as $title ) {
			$created = self::factory()->post->create(
				array(
					'post_type'   => 'content_gate_segment',
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
			$this->assertIsInt( $created );
			$this->ids[] = $created;
		}
		$this->transport  = new Fake_Transport();
		$this->membership = new Membership( $this->transport );
		Membership::reset( $this->membership );
		$this->membership->register();
		$this->membership->ready();
	}

	/** Restore request state after each test. */
	public function tear_down(): void {
		Membership::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Saved Group fixture.
	 *
	 * @param int    $id Segment ID.
	 * @param string $inner Inner saved content.
	 */
	private function group( int $id, string $inner ): string {
		return '<!-- wp:group {"contentGateSegmentId":' . $id . '} --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
	}

	/** Denying an outer gate never executes or consults its children. */
	public function test_nested_denial_short_circuits_dynamic_child(): void {
		$calls = 0;
		register_block_type(
			'content-gates/test-secret',
			array(
				'render_callback' => static function () use ( &$calls ): string {
					++$calls;
					return 'DYNAMIC_SECRET';
				},
			)
		);
		$inner   = $this->group( $this->ids[1], '<!-- wp:content-gates/test-secret /-->' );
		$content = $this->group( $this->ids[0], $inner );
		$this->assertSame( '', do_blocks( $content ) );
		$this->assertSame( 0, $calls );
		$this->assertSame( array( Segments::group( $this->ids[0] ) => 'none' ), $this->membership->finalize() );
		unregister_block_type( 'content-gates/test-secret' );
	}

	/** Nested allowance records only reached gates and preserves source. */
	public function test_entitled_outer_denied_inner(): void {
		$this->assertTrue( $this->membership->assign( $this->ids[0] ) );
		$content = $this->group( $this->ids[0], '<p>OUTER_PUBLIC</p>' . $this->group( $this->ids[1], '<p>INNER_SECRET</p>' ) );
		$id      = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->assertIsInt( $id );
		$render = do_blocks( $content );
		$this->assertStringContainsString( 'OUTER_PUBLIC', $render );
		$this->assertStringNotContainsString( 'INNER_SECRET', $render );
		$this->assertSame( $content, get_post_field( 'post_content', $id ) );
		$this->assertSame(
			array(
				Segments::group( $this->ids[0] ) => '1',
				Segments::group( $this->ids[1] ) => 'none',
			),
			$this->membership->finalize()
		);
	}

	/** Login is not entitlement, including for administrators. */
	public function test_login_does_not_grant_membership(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$this->assertSame( '', do_blocks( $this->group( $this->ids[0], '<p>SECRET</p>' ) ) );
	}

	/** Automatic excerpts must not strip comments and retain gated text. */
	public function test_excerpt_and_feed_withhold_bytes(): void {
		$id = self::factory()->post->create(
			array(
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>PUBLIC</p><!-- /wp:paragraph -->' . $this->group( $this->ids[0], '<p>RESTRICTED_SENTINEL</p>' ),
			)
		);
		$this->assertIsInt( $id );
		$this->assertStringNotContainsString( 'RESTRICTED_SENTINEL', get_the_excerpt( $id ) );
		$this->assertStringContainsString( 'PUBLIC', get_the_excerpt( $id ) );
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core delivery hooks, not new plugin hooks.
		$this->assertStringNotContainsString( 'RESTRICTED_SENTINEL', apply_filters( 'the_content_feed', apply_filters( 'the_content', $post->post_content ), 'rss2' ) );
	}

	/** An entirely gated post must not trigger core's raw excerpt fallback. */
	public function test_empty_protected_excerpt_stays_empty(): void {
		$id = self::factory()->post->create(
			array(
				'post_excerpt' => '',
				'post_content' => $this->group( $this->ids[0], '<p>ONLY_SECRET</p>' ),
			)
		);
		$this->assertIsInt( $id );
		$this->assertSame( '', get_the_excerpt( $id ) );
	}

	/** Public REST cannot expose raw or rendered restricted bytes. */
	public function test_rest_view_denied_and_authoring_raw_permissioned(): void {
		$id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $this->group( $this->ids[0], '<p>REST_SECRET</p>' ),
			)
		);
		$this->assertIsInt( $id );
		$response = rest_do_request( '/wp/v2/posts/' . $id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'REST_SECRET', (string) wp_json_encode( $response->get_data() ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', 'edit' );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$this->assertStringContainsString( 'REST_SECRET', (string) wp_json_encode( rest_do_request( $request )->get_data() ) );
	}

	/** Invalid, inactive and malformed assignments fail closed. */
	public function test_inactive_and_invalid_references(): void {
		$this->membership->assign( $this->ids[0] );
		wp_update_post(
			array(
				'ID'          => $this->ids[0],
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( $this->membership->contains( $this->ids[0] ) );
		$result = $this->membership->assign( $this->ids[0] );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );
		$this->assertSame( '', do_blocks( $this->group( 999999, '<p>INVALID_SECRET</p>' ) ) );
		$this->assertSame(
			'',
			Protection::block(
				null,
				array(
					'blockName' => 'core/group',
					'attrs'     => array( 'contentGateSegmentId' => 'oops' ),
				)
			)
		);
	}

	/** Definitions retain stable IDs and cannot be deleted through core. */
	public function test_deletion_and_rename(): void {
		$id    = $this->ids[0];
		$group = Segments::group( $id );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_title'  => 'Renamed',
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( wp_trash_post( $id ) );
		$this->assertFalse( wp_delete_post( $id, true ) );
		$this->assertSame( $group, Segments::group( $id ) );
		$this->assertNotNull( Segments::get( $id ) );
	}

	/** Mutation errors are versioned and enforce the lifecycle. */
	public function test_mutation_errors_and_revocation(): void {
		$this->assertWPError( $this->membership->assign( $this->ids[0], true, 2 ) );
		$this->assertWPError( $this->membership->assign( 999999 ) );
		$this->assertTrue( $this->membership->assign( $this->ids[0] ) );
		$this->assertTrue( $this->membership->assign( $this->ids[0], false ) );
		$this->assertFalse( $this->membership->contains( $this->ids[0] ) );
		$this->assertWPError( $this->membership->assign( $this->ids[0] ) );
		$this->membership->close();
		$result = $this->membership->assign( $this->ids[1] );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );
	}

	/** Reports cannot remove negative dependencies or accept late reads. */
	public function test_report_filter_cannot_remove_dependencies(): void {
		$this->membership->contains( $this->ids[0] );
		$remove = static function (): array {
			return array();
		};
		add_filter( 'content_gates_consulted_segments', $remove );
		$this->assertSame( array( Segments::group( $this->ids[0] ) => 'none' ), $this->membership->finalize() );
		remove_filter( 'content_gates_consulted_segments', $remove );
		$this->assertContains( 'invalid_consulted_filter', $this->membership->problems() );
		$this->assertFalse( $this->membership->contains( $this->ids[1] ) );
		$this->assertContains( 'late_read', $this->membership->problems() );
	}
}
