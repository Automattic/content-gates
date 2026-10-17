<?php
/**
 * Tests for the segment-gating prototype.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership;
use ContentGates\Segment_Repository;
use WP_UnitTestCase;

/**
 * Covers stable segments, membership, and server-side withholding.
 */
final class Test_Segment_Gating extends WP_UnitTestCase {

	/**
	 * Active segment fixture ID.
	 *
	 * @var int
	 */
	private int $segment_id;

	/**
	 * Create one active segment and reset request membership.
	 */
	public function set_up(): void {
		parent::set_up();

		unset( $_COOKIE['vip-go-seg'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Reset the local adapter fixture.
		Membership::reset_request_state();
		Membership::load_local_adapter();

		$result = Segment_Repository::create( 'Subscribers' );
		$this->assertIsInt( $result );
		$this->segment_id = $result;
		Membership::register_segments();
	}

	/**
	 * Remove the fixture segment and dynamic test block.
	 */
	public function tear_down(): void {
		unset( $_COOKIE['vip-go-seg'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Reset the local adapter fixture.
		unregister_block_type( 'content-gates/test-dynamic' );
		parent::tear_down();
	}

	/**
	 * Segment records retain their ID and cannot be deleted.
	 */
	public function test_segment_can_be_deactivated_but_not_deleted(): void {
		$result = Segment_Repository::update( $this->segment_id, 'Members', false );

		$this->assertSame( $this->segment_id, $result );
		$this->assertFalse( Segment_Repository::is_active( $this->segment_id ) );
		$this->assertFalse( wp_trash_post( $this->segment_id ) );
		$this->assertFalse( wp_delete_post( $this->segment_id, true ) );
		$this->assertNotNull( Segment_Repository::get( $this->segment_id ) );

		$result = Segment_Repository::update( $this->segment_id, 'Members', true );

		$this->assertSame( $this->segment_id, $result );
		$this->assertTrue( Segment_Repository::is_active( $this->segment_id ) );
	}

	/**
	 * A denied Group and its dynamic children never render.
	 */
	public function test_denied_group_is_withheld_before_children_render(): void {
		$renders = 0;
		register_block_type(
			'content-gates/test-dynamic',
			array(
				'render_callback' => static function () use ( &$renders ): string {
					++$renders;
					return '<p>PROTECTED_DYNAMIC_MARKER</p>';
				},
			)
		);

		$content  = '<!-- wp:group {"contentGateSegmentId":' . $this->segment_id . '} --><div class="wp-block-group"><!-- wp:content-gates/test-dynamic /--></div><!-- /wp:group -->';
		$rendered = do_blocks( $content );

		$this->assertStringNotContainsString( 'PROTECTED_DYNAMIC_MARKER', $rendered );
		$this->assertSame( 0, $renders );
		$this->assertSame( array( $this->segment_id ), Membership::get_consulted_segment_ids() );
	}

	/**
	 * Assignment grants the entitled view on the next request state.
	 */
	public function test_assigned_reader_receives_restricted_group(): void {
		$this->set_membership_cookie();

		$content  = '<!-- wp:group {"contentGateSegmentId":' . $this->segment_id . '} --><div class="wp-block-group"><!-- wp:paragraph --><p>PROTECTED_MARKER</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$rendered = do_blocks( $content );

		$this->assertStringContainsString( 'PROTECTED_MARKER', $rendered );
	}

	/**
	 * Deactivation preserves assignment but fails closed at render time.
	 */
	public function test_inactive_segment_assignment_fails_closed(): void {
		$this->set_membership_cookie();
		$this->assertSame( $this->segment_id, Segment_Repository::update( $this->segment_id, 'Subscribers', false ) );

		$content  = '<!-- wp:group {"contentGateSegmentId":' . $this->segment_id . '} --><div class="wp-block-group"><!-- wp:paragraph --><p>PROTECTED_MARKER</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$rendered = do_blocks( $content );

		$this->assertStringNotContainsString( 'PROTECTED_MARKER', $rendered );
		$this->assertFalse( Membership::is_reader_in_segment( $this->segment_id ) );
	}

	/**
	 * Load the local adapter from a next-request membership cookie.
	 */
	private function set_membership_cookie(): void {
		$_COOKIE['vip-go-seg'] = 'vc-v1__cg-' . $this->segment_id . '_--_1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulate the VIP request cookie.
		Membership::reset_request_state();
		Membership::load_local_adapter();
		Membership::register_segments();
	}

	/**
	 * Invalid references never become unrestricted content.
	 */
	public function test_invalid_segment_reference_fails_closed(): void {
		$content  = '<!-- wp:group {"contentGateSegmentId":999999} --><div class="wp-block-group"><!-- wp:paragraph --><p>PROTECTED_MARKER</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$rendered = do_blocks( $content );

		$this->assertStringNotContainsString( 'PROTECTED_MARKER', $rendered );
		$this->assertContains( 999999, Membership::get_consulted_segment_ids() );
	}

	/**
	 * Generated excerpts use the same denied Group decision.
	 */
	public function test_generated_excerpt_does_not_leak_denied_group(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Gated article',
				'post_content' => '<!-- wp:paragraph --><p>Public introduction.</p><!-- /wp:paragraph --><!-- wp:group {"contentGateSegmentId":' . $this->segment_id . '} --><div class="wp-block-group"><!-- wp:paragraph --><p>PROTECTED_EXCERPT_MARKER</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			)
		);

		$this->assertIsInt( $post_id );
		$excerpt = get_the_excerpt( (int) $post_id );

		$this->assertStringContainsString( 'Public introduction.', $excerpt );
		$this->assertStringNotContainsString( 'PROTECTED_EXCERPT_MARKER', $excerpt );
	}
}
