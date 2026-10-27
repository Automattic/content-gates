<?php
/**
 * Excerpt guard tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

/**
 * Auto-excerpts never contain withheld content.
 */
final class Test_Excerpt_Guard extends Content_Gates_Test_Case {

	/**
	 * Without the guard, core's excerpt leaks gated text. This proves the guard is needed.
	 */
	public function test_core_excerpt_leaks_without_guard(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->become_reader( array() );
		$this->request_post( $post_id );

		$guard = $this->plugin()->excerpt_guard();
		remove_filter( 'get_the_excerpt', array( $guard, 'build' ), 9 );
		remove_filter( 'get_the_excerpt', array( $guard, 'restore' ), 11 );
		$leaky = get_the_excerpt( $post_id );
		add_filter( 'get_the_excerpt', array( $guard, 'build' ), 9, 2 );
		add_filter( 'get_the_excerpt', array( $guard, 'restore' ), 11, 2 );

		$this->assertStringContainsString( self::SECRET, $leaky, 'Core unwraps Groups when trimming excerpts.' );
	}

	/**
	 * On an archive nothing is declared, so a gated teaser withholds, and the
	 * plugin's own read raises no undeclared-read notice. The test library
	 * fails on any unexpected notice.
	 */
	public function test_archive_excerpt_withholds_quietly(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_page( home_url( '/' ) );

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'PUBLIC-INTRO', $excerpt );
		$this->assertStringNotContainsString( self::SECRET, $excerpt, 'Undeclared on an archive: withheld even for a member.' );
	}

	/**
	 * The guard removes withheld Groups from the excerpt.
	 */
	public function test_guard_strips_withheld_groups(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$excerpt = get_the_excerpt( $post_id );
		$this->assertStringContainsString( 'PUBLIC-INTRO', $excerpt );
		$this->assertStringNotContainsString( self::SECRET, $excerpt );

		$this->new_request();
		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringContainsString( self::SECRET, get_the_excerpt( $post_id ) );
	}

	/**
	 * A post that is nothing but gated Groups yields an empty excerpt for non-members.
	 */
	public function test_fully_gated_post_yields_empty_excerpt(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_post(
			array(
				'post_content' => $this->gated_group( array( 'subscriber' ), $this->paragraph( self::SECRET ) )
					. $this->gated_group( array( 'subscriber' ), $this->paragraph( 'ALSO-SECRET' ) ),
			)
		);

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertSame( '', get_the_excerpt( $post_id ) );
	}

	/**
	 * Manual excerpts are the author's responsibility and are untouched.
	 */
	public function test_manual_excerpt_is_untouched(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_post(
			array(
				'post_excerpt' => 'Hand-written summary.',
				'post_content' => $this->gated_group( array( 'subscriber' ), $this->paragraph( self::SECRET ) ),
			)
		);

		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertSame( 'Hand-written summary.', get_the_excerpt( $post_id ) );
	}

	/**
	 * Nested withheld Groups are removed while their allowed parent stays.
	 */
	public function test_nested_withheld_group_is_removed(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$inner   = $this->gated_group( array( 'donor' ), $this->paragraph( 'DONOR-ONLY' ) );
		$outer   = $this->gated_group( array( 'subscriber' ), $this->paragraph( 'SUB-TEXT' ) . $inner );
		$post_id = $this->create_post( array( 'post_content' => $this->paragraph( 'INTRO' ) . $outer ) );

		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$excerpt = get_the_excerpt( $post_id );
		$this->assertStringContainsString( 'INTRO', $excerpt );
		$this->assertStringContainsString( 'SUB-TEXT', $excerpt );
		$this->assertStringNotContainsString( 'DONOR-ONLY', $excerpt );
	}

	/**
	 * Feed content and summary paths both omit the gated Group for non-members.
	 */
	public function test_feed_paths_omit_gated_content(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->become_reader( array() );
		$this->request_page( get_feed_link() );
		$this->assertTrue( is_feed() );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Mirrors the feed loop.
		setup_postdata( $post );

		$full = get_the_content_feed( 'rss2' );
		$this->assertStringNotContainsString( self::SECRET, $full );
		$this->assertStringContainsString( 'PUBLIC-INTRO', $full );

		ob_start();
		the_excerpt_rss();
		$summary = (string) ob_get_clean();
		$this->assertStringNotContainsString( self::SECRET, $summary );

		wp_reset_postdata();
	}
}
