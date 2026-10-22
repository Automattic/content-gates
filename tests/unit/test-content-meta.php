<?php
/**
 * Save-time declared set tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use WP_REST_Request;

/**
 * The post meta that feeds the declared set.
 */
final class Test_Content_Meta extends Content_Gates_Test_Case {

	/**
	 * Saving stores the sorted, unique keys of every Group, nested or not, either mode.
	 */
	public function test_save_stores_sorted_unique_keys(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->create_segment( 'Newsletter' );
		$inner   = $this->gated_group( array( 'donor' ), $this->paragraph( 'x' ) );
		$outer   = $this->gated_group( array( 'subscriber' ), $inner );
		$prompt  = $this->gated_group( array( 'newsletter', 'subscriber' ), $this->paragraph( 'join' ), 'not-in' );
		$post_id = $this->create_post( array( 'post_content' => $outer . $prompt ) );

		$this->assertSame( array( 'donor', 'newsletter', 'subscriber' ), Declared_Set::stored_keys( $post_id ) );
	}

	/**
	 * Removing every assignment deletes the meta.
	 */
	public function test_clearing_assignments_deletes_meta(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->assertSame( array( 'subscriber' ), Declared_Set::stored_keys( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $this->paragraph( 'public now' ),
			)
		);

		$this->assertSame( array(), Declared_Set::stored_keys( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, Declared_Set::META_KEY, true ) );
	}

	/**
	 * Unknown keys are not stored; inactive keys are kept.
	 */
	public function test_unknown_keys_are_dropped_and_inactive_kept(): void {
		$old = $this->create_segment( 'Old' );
		$this->plugin()->segments()->set_active( $old->id, false );
		$this->new_request();
		$post_id = $this->create_gated_post( array( 'old', 'ghost' ) );

		$this->assertSame( array( 'old' ), Declared_Set::stored_keys( $post_id ) );
	}

	/**
	 * A synced pattern's assignments count for the post that embeds it.
	 */
	public function test_synced_pattern_contributes_keys(): void {
		$this->create_segment( 'Subscriber' );
		$pattern_id = $this->create_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $this->gated_group( array( 'subscriber' ), $this->paragraph( 'reusable' ) ),
			)
		);
		$post_id    = $this->create_post( array( 'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->' ) );

		$this->assertSame( array( 'subscriber' ), Declared_Set::stored_keys( $post_id ) );
	}

	/**
	 * A REST save with an unknown key is refused with a 400.
	 */
	public function test_rest_save_rejects_unknown_key(): void {
		// dispatch() alone is not a served REST request, so the created post's
		// rendered content reads before the declared set is built.
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$this->create_segment( 'Subscriber' );
		wp_set_current_user( $this->create_user( 'editor' ) );
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params(
			array(
				'title'   => 'Gated',
				'status'  => 'draft',
				'content' => $this->gated_group( array( 'ghost' ), $this->paragraph( 'x' ) ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_invalid_segment', $response->get_data()['code'] );

		$request->set_body_params(
			array(
				'title'   => 'Gated',
				'status'  => 'draft',
				'content' => $this->gated_group( array( 'subscriber' ), $this->paragraph( 'x' ) ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'subscriber' ), Declared_Set::stored_keys( (int) $response->get_data()['id'] ) );
	}

	/**
	 * Segment posts themselves never get the meta.
	 */
	public function test_segment_posts_are_skipped(): void {
		$segment = $this->create_segment( 'Subscriber' );
		$this->assertSame( array(), Declared_Set::stored_keys( $segment->id ) );
	}
}
