<?php
/**
 * Crawler markup tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Rendering\Crawler_Markup;
use ContentGates\Segments\Segment_Registry;

require_once __DIR__ . '/class-gating-test-case.php';

/**
 * JSON-LD paywall markup appears only when a gated Group will render on
 * the crawler variant.
 */
final class Test_Crawler_Markup extends Gating_Test_Case {

	/**
	 * Expected JSON-LD string.
	 */
	private const JSON_LD = '{"@context":"https://schema.org","@type":"Article","isAccessibleForFree":false,"hasPart":{"@type":"WebPageElement","isAccessibleForFree":false,"cssSelector":".content-gates-paywall"}}';

	/**
	 * Capture `wp_head()` output.
	 */
	private function head(): string {
		ob_start();
		wp_head();

		return (string) ob_get_clean();
	}

	/**
	 * Create and visit a published post.
	 *
	 * @param string $content Post content.
	 */
	private function visit_post( string $content ): int {
		$post_id = $this->create_post( $content );
		$this->visit( $this->permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		$this->assertSame( $post_id, get_the_ID() );

		return $post_id;
	}

	/**
	 * The plugin hooks the markup on wp_head.
	 */
	public function test_plugin_hooks_markup(): void {
		$this->assert_hooked( 'wp_head', Crawler_Markup::class, 'print_markup', 10 );
	}

	/**
	 * The JSON is exactly the agreed structured data.
	 */
	public function test_json_ld_shape(): void {
		$this->assertSame( self::JSON_LD, Crawler_Markup::json_ld() );
		$this->assertSame( json_decode( self::JSON_LD, true ), Crawler_Markup::data() );
	}

	/**
	 * A denied crawler on a gated singular post gets the JSON-LD in wp_head.
	 * The reads match the render: the segment, then crawler after the denial.
	 */
	public function test_crawler_on_gated_post_gets_json_ld(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->visit_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );
		$head = $this->head();

		$this->assertStringContainsString( '<script type="application/ld+json">' . self::JSON_LD . '</script>', $head );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $segment->key() ), $this->consulted() );
	}

	/**
	 * A denied non-crawler on the same post gets no JSON-LD; the reads are
	 * the same as the render's.
	 */
	public function test_non_crawler_gets_no_json_ld(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->visit_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$head = $this->head();

		$this->assertStringNotContainsString( 'isAccessibleForFree', $head );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $segment->key() ), $this->consulted() );
	}

	/**
	 * An entitled reader's page gets no JSON-LD and does not consult crawler,
	 * so it does not vary on it.
	 */
	public function test_entitled_reader_does_not_consult_crawler(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->visit_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->set_reader( array( $segment->key() => '1' ) );
		$head = $this->head();

		$this->assertStringNotContainsString( 'isAccessibleForFree', $head );
		$this->assertSame( array( $segment->key() ), $this->consulted() );
	}

	/**
	 * A crawler who is also a member sees the Group unwrapped, so no JSON-LD
	 * may point at `.content-gates-paywall`.
	 */
	public function test_entitled_crawler_gets_no_json_ld(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->visit_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->set_reader(
			array(
				Segment_Registry::CRAWLER_KEY => '1',
				$segment->key()               => '1',
			)
		);
		$head = $this->head();

		$this->assertStringNotContainsString( 'isAccessibleForFree', $head );
		$this->assertSame( array( $segment->key() ), $this->consulted() );
	}

	/**
	 * Nested gates are walked the way the render walks them: a withheld
	 * outer Group hides its inner gate; an allowed outer Group exposes it.
	 */
	public function test_nested_gates_mirror_the_render(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );
		$this->visit_post(
			$this->gated_group(
				$outer->id(),
				$this->paragraph( 'OUTER-TEXT' ) . $this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) )
			)
		);

		// Not a crawler, in inner only: outer withheld, inner never consulted.
		$this->set_reader( array( $inner->key() => '1' ) );
		$this->assertStringNotContainsString( 'isAccessibleForFree', $this->head() );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $outer->key() ), $this->consulted() );

		// Crawler in outer only: outer allowed, inner denied, so JSON-LD.
		$this->set_reader(
			array(
				Segment_Registry::CRAWLER_KEY => '1',
				$outer->key()                 => '1',
			)
		);
		$this->assertStringContainsString( self::JSON_LD, $this->head() );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $inner->key(), $outer->key() ), $this->consulted() );

		// Crawler in both: nothing is wrapped, so no JSON-LD.
		$this->set_reader(
			array(
				Segment_Registry::CRAWLER_KEY => '1',
				$outer->key()                 => '1',
				$inner->key()                 => '1',
			)
		);
		$this->assertStringNotContainsString( 'isAccessibleForFree', $this->head() );
		$this->assertSame( array( $inner->key(), $outer->key() ), $this->consulted() );
	}

	/**
	 * An author-hidden gated Group never renders, so it never earns JSON-LD.
	 */
	public function test_hidden_gated_group_gets_no_json_ld(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->visit_post(
			$this->gated_group(
				$segment->id(),
				$this->paragraph( 'SECRET' ),
				array( 'metadata' => array( 'blockVisibility' => false ) )
			)
		);

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );

		$this->assertStringNotContainsString( 'isAccessibleForFree', $this->head() );
	}

	/**
	 * A crawler on an ungated post gets no JSON-LD and the crawler segment is not consulted.
	 */
	public function test_crawler_on_ungated_post_gets_nothing(): void {
		$this->visit_post( $this->plain_group( $this->paragraph( 'PLAIN' ) ) );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );
		$head = $this->head();

		$this->assertStringNotContainsString( 'isAccessibleForFree', $head );
		$this->assertSame( array(), $this->consulted() );
	}

	/**
	 * Non-singular views never carry the markup.
	 */
	public function test_archive_gets_nothing(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->create_post( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->visit( home_url( '/' ) );
		$this->assertFalse( is_singular() );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );
		$head = $this->head();

		$this->assertStringNotContainsString( 'isAccessibleForFree', $head );
		$this->assertSame( array(), $this->consulted() );
	}
}
