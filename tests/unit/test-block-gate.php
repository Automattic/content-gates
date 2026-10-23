<?php
/**
 * Group block enforcement tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Rendering\Block_Gate;

/**
 * Withholding, OR semantics, nesting, dynamic children, crawler markers.
 */
final class Test_Block_Gate extends Content_Gates_Test_Case {

	/**
	 * The attributes are registered on the server for core/group.
	 */
	public function test_attributes_are_registered(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' );
		$this->assertNotNull( $type );
		$this->assertIsArray( $type->attributes );
		$this->assertArrayHasKey( Block_Gate::ATTR_SEGMENTS, $type->attributes );
		$this->assertArrayHasKey( Block_Gate::ATTR_MODE, $type->attributes );
		$this->assertSame( array(), $type->attributes[ Block_Gate::ATTR_SEGMENTS ]['default'] );
	}

	/**
	 * A non-member gets zero gated bytes; a member gets the Group.
	 */
	public function test_in_mode_withholds_for_non_members(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$anonymous = $this->render_content( $post_id );
		$this->assertStringContainsString( 'PUBLIC-INTRO', $anonymous );
		$this->assertStringNotContainsString( self::SECRET, $anonymous );
		$this->assertStringNotContainsString( 'wp-block-group', $anonymous, 'No wrapper markup is left behind.' );

		$this->new_request();
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$member = $this->render_content( $post_id );
		$this->assertStringContainsString( self::SECRET, $member );
	}

	/**
	 * Several segments are OR'ed: any membership shows the Group.
	 */
	public function test_or_semantics(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$post_id = $this->create_gated_post( array( 'subscriber', 'donor' ) );

		$this->become_reader( array( 'donor' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringContainsString( self::SECRET, $this->render_content( $post_id ) );
	}

	/**
	 * The not-in mode shows the Group only to readers in none of the segments.
	 */
	public function test_not_in_mode(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$post_id = $this->create_gated_post( array( 'subscriber', 'donor' ), Block_Gate::MODE_NOT_IN );

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertStringContainsString( self::SECRET, $this->render_content( $post_id ), 'Anonymous readers see the prompt.' );

		$this->new_request();
		$this->become_reader( array( 'donor' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( self::SECRET, $this->render_content( $post_id ), 'Members do not.' );
	}

	/**
	 * A nested gated Group inside an allowed Group enforces its own check, and its
	 * segment is declared even for readers the outer Group denies.
	 */
	public function test_nested_groups(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$inner   = $this->gated_group( array( 'donor' ), $this->paragraph( 'DONOR-ONLY' ) );
		$outer   = $this->gated_group( array( 'subscriber' ), $this->paragraph( self::SECRET ) . $inner );
		$post_id = $this->create_post( array( 'post_content' => $outer ) );

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertSame( array( 'donor', 'subscriber' ), \Automattic\VIP\ContentGates\get_declared_segments(), 'Inner segments are declared even when the outer Group is denied.' );
		$this->assertSame( '', trim( $this->render_content( $post_id ) ) );

		$this->new_request();
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$content = $this->render_content( $post_id );
		$this->assertStringContainsString( self::SECRET, $content );
		$this->assertStringNotContainsString( 'DONOR-ONLY', $content );

		$this->new_request();
		$this->become_reader(
			array(
				'donor'      => '1',
				'subscriber' => '1',
			)
		);
		$this->request_post( $post_id );
		$this->assertStringContainsString( 'DONOR-ONLY', $this->render_content( $post_id ) );
	}

	/**
	 * A denied Group's dynamic child render callback never runs.
	 */
	public function test_dynamic_child_callback_does_not_run_when_denied(): void {
		$this->create_segment( 'Subscriber' );
		$calls = 0;
		register_block_type(
			'content-gates-test/dynamic',
			array(
				'render_callback' => static function () use ( &$calls ): string {
					++$calls;
					return '<p>' . self::SECRET . '</p>';
				},
			)
		);
		$post_id = $this->create_post(
			array( 'post_content' => $this->gated_group( array( 'subscriber' ), '<!-- wp:content-gates-test/dynamic /-->' ) )
		);

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( self::SECRET, $this->render_content( $post_id ) );
		$this->assertSame( 0, $calls );

		$this->new_request();
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringContainsString( self::SECRET, $this->render_content( $post_id ) );
		$this->assertSame( 1, $calls );

		unregister_block_type( 'content-gates-test/dynamic' );
	}

	/**
	 * An unknown key is never treated as unrestricted.
	 */
	public function test_unknown_key_withholds(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$post_id = $this->create_post(
			array( 'post_content' => $this->gated_group( array( 'ghost' ), $this->paragraph( self::SECRET ) ) )
		);

		$this->request_post( $post_id );
		$this->assertStringNotContainsString( self::SECRET, $this->render_content( $post_id ) );
	}

	/**
	 * An inactive segment withholds for everyone, cookie or not.
	 */
	public function test_inactive_segment_withholds_for_everyone(): void {
		$segment = $this->create_segment( 'Old' );
		$post_id = $this->create_gated_post( array( 'old' ) );
		$this->plugin()->segments()->set_active( $segment->id, false );
		$this->new_request();

		$this->become_reader( array( 'old' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( self::SECRET, $this->render_content( $post_id ) );
	}

	/**
	 * Core visibility still applies to an entitled reader.
	 */
	public function test_author_hidden_group_stays_hidden_for_members(): void {
		$this->create_segment( 'Subscriber' );
		$attrs   = wp_json_encode(
			array(
				Block_Gate::ATTR_SEGMENTS => array( 'subscriber' ),
				'metadata'                => array( 'blockVisibility' => false ),
			)
		);
		$post_id = $this->create_post(
			array( 'post_content' => "<!-- wp:group {$attrs} -->\n<div class=\"wp-block-group\">" . $this->paragraph( self::SECRET ) . "</div>\n<!-- /wp:group -->" )
		);

		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( self::SECRET, $this->render_content( $post_id ) );
	}

	/**
	 * Rendering never changes the saved content.
	 */
	public function test_rendering_does_not_change_saved_content(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$before  = get_post_field( 'post_content', $post_id );

		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$this->render_content( $post_id );

		$this->assertSame( $before, get_post_field( 'post_content', $post_id ) );
		$this->assertStringNotContainsString( Block_Gate::ATTR_INDEX, (string) $before );
	}

	/**
	 * Crawler variants get the content plus numbered marker classes; humans get no markers.
	 */
	public function test_crawler_variant_marks_gated_groups(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_post(
			array(
				'post_content' => $this->gated_group( array( 'subscriber', 'crawlers-search' ), $this->paragraph( 'FIRST' ) )
					. $this->gated_group( array( 'subscriber' ), $this->paragraph( 'SECOND' ) ),
			)
		);

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_post( $post_id );
		$this->assertTrue( $this->plugin()->gate()->is_crawler() );
		$crawler = $this->render_content( $post_id );
		$this->assertStringContainsString( 'FIRST', $crawler );
		$this->assertStringNotContainsString( 'SECOND', $crawler, 'The crawler is not a subscriber.' );
		$this->assertStringContainsString( 'content-gates-gated content-gates-gated-1', $crawler );
		$this->assertStringNotContainsString( 'content-gates-gated-2', $crawler );

		$this->new_request();
		$this->become_reader(
			array(
				'crawlers-search' => '1',
				'subscriber'      => '1',
			)
		);
		$this->request_post( $post_id );
		$both = $this->render_content( $post_id );
		$this->assertStringContainsString( 'content-gates-gated-1', $both );
		$this->assertStringContainsString( 'content-gates-gated-2', $both );

		$this->new_request();
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$human = $this->render_content( $post_id );
		$this->assertStringContainsString( 'SECOND', $human );
		$this->assertStringNotContainsString( 'content-gates-gated', $human, 'Human responses carry no hint of gating.' );
	}
}
