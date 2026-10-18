<?php
/**
 * Excerpt guard tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Rendering\Excerpt_Guard;
use ContentGates\Segments\Segment;
use WP_REST_Request;

require_once __DIR__ . '/class-gating-test-case.php';

/**
 * Auto-generated excerpts never contain withheld Group text.
 */
final class Test_Excerpt_Guard extends Gating_Test_Case {

	/**
	 * Create a post whose content is a gated Group plus a public paragraph.
	 *
	 * @param Segment              $segment Segment to gate on.
	 * @param array<string, mixed> $args    Extra post args.
	 */
	private function create_gated_post( Segment $segment, array $args = array() ): int {
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET words that must never leak into an excerpt' ) )
			. $this->paragraph( 'PUBLIC words that may appear' );

		return $this->create_post( $content, $args );
	}

	/**
	 * The plugin hooks the guard before core's trimmer.
	 */
	public function test_plugin_hooks_guard_at_priority_nine(): void {
		$this->assert_hooked( 'get_the_excerpt', Excerpt_Guard::class, 'filter_excerpt', 9 );
		$this->assertSame( 10, has_filter( 'get_the_excerpt', 'wp_trim_excerpt' ) );
	}

	/**
	 * Core on its own leaks: the Group wrapper is dropped and its paragraphs
	 * rendered. This documents why the guard exists.
	 */
	public function test_core_excerpt_leaks_without_guard(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$callbacks = $GLOBALS['wp_filter']['get_the_excerpt']->callbacks[9] ?? array();
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && ( $function[0] ?? null ) instanceof Excerpt_Guard ) {
				remove_filter( 'get_the_excerpt', $function, 9 );
			}
		}

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'SECRET', $excerpt, 'If this fails, core stopped leaking Group text and the guard may be redundant.' );
	}

	/**
	 * A non-member's excerpt has the public text and none of the secret.
	 */
	public function test_excerpt_omits_denied_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'PUBLIC words that may appear', $excerpt );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
		$this->assertContains( $segment->key(), $this->consulted(), 'The excerpt path consults the segment.' );
	}

	/**
	 * A member's excerpt includes the Group text.
	 */
	public function test_excerpt_includes_group_for_member(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$this->set_reader( array( $segment->key() => '1' ) );
		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'SECRET words', $excerpt );
		$this->assertStringContainsString( 'PUBLIC words', $excerpt );
	}

	/**
	 * The excerpt is trimmed once: the standard "more" marker appears once.
	 */
	public function test_excerpt_is_trimmed_once(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$long    = implode( ' ', array_fill( 0, 80, 'word' ) );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) . $this->paragraph( $long );
		$post_id = $this->create_post( $content );

		$excerpt = get_the_excerpt( $post_id );

		$this->assertSame( 1, substr_count( $excerpt, '[&hellip;]' ) );
		$this->assertSame( 55, substr_count( $excerpt, 'word' ) );
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
	}

	/**
	 * A manual excerpt is returned as written.
	 */
	public function test_manual_excerpt_is_untouched(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment, array( 'post_excerpt' => 'Hand-written summary.' ) );

		$this->assertSame( 'Hand-written summary.', get_the_excerpt( $post_id ) );
		$this->assertSame( array(), $this->consulted(), 'A manual excerpt consults nothing.' );
	}

	/**
	 * Posts without gated Groups take core's path unchanged.
	 */
	public function test_ungated_post_is_not_parsed(): void {
		$post_id = $this->create_post( $this->paragraph( 'Plain post text.' ) );

		$this->assertStringContainsString( 'Plain post text.', get_the_excerpt( $post_id ) );
		$this->assertSame( array(), $this->consulted() );
	}

	/**
	 * Feed summaries go through `the_excerpt_rss()` and are guarded too.
	 */
	public function test_feed_excerpt_omits_denied_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$this->visit( $this->permalink( $post_id ) );
		$this->assertSame( $post_id, get_the_ID() );

		ob_start();
		the_excerpt_rss();
		$rss = (string) ob_get_clean();

		$this->assertStringContainsString( 'PUBLIC words', $rss );
		$this->assertStringNotContainsString( 'SECRET', $rss );
	}

	/**
	 * Full-content feeds render blocks, so the enforcer covers them.
	 */
	public function test_feed_content_omits_denied_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$this->visit( $this->permalink( $post_id ) );

		$feed = get_the_content_feed( 'rss2' );

		$this->assertStringContainsString( 'PUBLIC words', $feed );
		$this->assertStringNotContainsString( 'SECRET', $feed );
	}

	/**
	 * REST `excerpt.rendered` and `content.rendered` carry no gated bytes.
	 */
	public function test_rest_response_omits_denied_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_gated_post( $segment );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'PUBLIC words', $data['excerpt']['rendered'] );
		$this->assertStringNotContainsString( 'SECRET', $data['excerpt']['rendered'] );
		$this->assertStringContainsString( 'PUBLIC words', $data['content']['rendered'] );
		$this->assertStringNotContainsString( 'SECRET', $data['content']['rendered'] );

		$this->set_reader( array( $segment->key() => '1' ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'SECRET', $data['content']['rendered'] );
		$this->assertStringContainsString( 'SECRET', $data['excerpt']['rendered'] );
	}
}
