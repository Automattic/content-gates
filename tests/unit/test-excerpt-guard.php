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
	 * The plugin hooks the guard on both sides of core's trimmer.
	 */
	public function test_plugin_hooks_guard_around_core_trimmer(): void {
		$this->assert_hooked( 'get_the_excerpt', Excerpt_Guard::class, 'filter_excerpt', 9 );
		$this->assertSame( 10, has_filter( 'get_the_excerpt', 'wp_trim_excerpt' ) );
		$this->assert_hooked( 'get_the_excerpt', Excerpt_Guard::class, 'restore_excerpt', 11 );
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

	/**
	 * A post that is nothing but a gated Group has an empty excerpt for a
	 * non-member. Core at priority 10 regenerates from the original post
	 * when it is handed '', and that regeneration must be discarded.
	 */
	public function test_only_gated_content_yields_empty_excerpt_for_non_member(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET words only' ) ) );

		$this->assertSame( '', get_the_excerpt( $post_id ) );
		$this->assertContains( $segment->key(), $this->consulted() );

		$this->visit( $this->permalink( $post_id ) );
		$this->assertSame( $post_id, get_the_ID() );

		ob_start();
		the_excerpt_rss();
		$rss = (string) ob_get_clean();

		$this->assertSame( '', $rss );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( '', $data['excerpt']['rendered'] );
		$this->assertStringNotContainsString( 'SECRET', $data['content']['rendered'] );

		$this->set_reader( array( $segment->key() => '1' ) );
		$this->assertStringContainsString( 'SECRET words only', get_the_excerpt( $post_id ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertStringContainsString( 'SECRET words only', $data['excerpt']['rendered'] );
	}

	/**
	 * Without the priority 11 callback, core regenerates the excerpt from the
	 * original post when it receives '' and the gated text comes back. This
	 * documents why the guard owns both sides of core's trimmer.
	 */
	public function test_core_regenerates_from_original_without_restore_callback(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$post_id = $this->create_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET words only' ) ) );

		$removed = array();
		$hook    = $GLOBALS['wp_filter']['get_the_excerpt'];
		$this->assertInstanceOf( \WP_Hook::class, $hook );
		foreach ( $hook->callbacks[11] ?? array() as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && ( $function[0] ?? null ) instanceof Excerpt_Guard ) {
				remove_filter( 'get_the_excerpt', $function, 11 );
				$removed[] = $function;
			}
		}
		$this->assertCount( 1, $removed, 'The guard must be hooked at priority 11.' );

		try {
			$leaked = get_the_excerpt( $post_id );
		} finally {
			foreach ( $removed as $function ) {
				add_filter( 'get_the_excerpt', $function, 11, 2 );
			}
		}

		$this->assertStringContainsString( 'SECRET', $leaked, 'If this fails, core stopped regenerating from the original post and the priority 11 callback may be redundant.' );
		$this->assertSame( '', get_the_excerpt( $post_id ), 'With the callback restored, the excerpt is empty again.' );
	}

	/**
	 * Two gated Groups and nothing else: each reader gets exactly the
	 * Groups they are entitled to, and a non-member gets ''.
	 */
	public function test_two_gated_groups_and_nothing_else(): void {
		$alpha   = $this->create_segment( 'Alpha' );
		$beta    = $this->create_segment( 'Beta' );
		$post_id = $this->create_post(
			$this->gated_group( $alpha->id(), $this->paragraph( 'ALPHA-SECRET' ) )
			. $this->gated_group( $beta->id(), $this->paragraph( 'BETA-SECRET' ) )
		);

		$this->assertSame( '', get_the_excerpt( $post_id ) );
		$this->assertContains( $alpha->key(), $this->consulted() );
		$this->assertContains( $beta->key(), $this->consulted() );

		$this->set_reader( array( $alpha->key() => '1' ) );
		$excerpt = get_the_excerpt( $post_id );
		$this->assertStringContainsString( 'ALPHA-SECRET', $excerpt );
		$this->assertStringNotContainsString( 'BETA-SECRET', $excerpt );

		$this->set_reader(
			array(
				$alpha->key() => '1',
				$beta->key()  => '1',
			)
		);
		$excerpt = get_the_excerpt( $post_id );
		$this->assertStringContainsString( 'ALPHA-SECRET', $excerpt );
		$this->assertStringContainsString( 'BETA-SECRET', $excerpt );
	}

	/**
	 * A nested `get_the_excerpt()` call for another post during the
	 * computation does not confuse the pairing of priorities 9 and 11.
	 */
	public function test_nested_excerpt_calls_keep_their_own_values(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$outer   = $this->create_post( $this->gated_group( $segment->id(), $this->paragraph( 'OUTER-SECRET' ) ) );
		$inner   = $this->create_post( $this->gated_group( $segment->id(), $this->paragraph( 'INNER-SECRET' ) ) . $this->paragraph( 'INNER-PUBLIC' ) );

		$nested = null;
		$busy   = false;
		$spy    = static function ( string $text ) use ( $inner, &$nested, &$busy ): string {
			// The nested call fires this filter again; the flag stops re-entry.
			if ( null === $nested && ! $busy ) {
				$busy   = true;
				$nested = get_the_excerpt( $inner );
			}

			return $text;
		};
		add_filter( 'wp_trim_excerpt', $spy );

		try {
			$excerpt = get_the_excerpt( $outer );
		} finally {
			remove_filter( 'wp_trim_excerpt', $spy );
		}

		$this->assertSame( '', $excerpt );
		$this->assertIsString( $nested );
		$this->assertStringContainsString( 'INNER-PUBLIC', $nested );
		$this->assertStringNotContainsString( 'INNER-SECRET', $nested );
		$this->assertStringNotContainsString( 'OUTER-SECRET', $nested );
	}
}
