<?php
/**
 * Block stripper tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Rendering\Block_Stripper;
use ContentGates\Segments\Segment_Registry;

require_once __DIR__ . '/class-gating-test-case.php';

/**
 * `strip()` removes withheld Groups from serialized content.
 */
final class Test_Block_Stripper extends Gating_Test_Case {

	/**
	 * Stripper under test.
	 *
	 * @var Block_Stripper
	 */
	private Block_Stripper $stripper;

	/**
	 * Build a stripper on the plugin's membership.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->stripper = new Block_Stripper( new Block_Enforcer( $this->membership, $this->registry ) );
	}

	/**
	 * Block names at the top level of serialized content.
	 *
	 * @param string $content Serialized blocks.
	 * @return array<int, string|null>
	 */
	private function top_level_names( string $content ): array {
		$names = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ) ) {
				continue; // Whitespace between blocks.
			}
			$names[] = $block['blockName'];
		}

		return $names;
	}

	/**
	 * Content without the attribute is returned byte for byte, unparsed.
	 */
	public function test_content_without_attribute_is_unchanged(): void {
		$content = $this->plain_group( $this->paragraph( 'PLAIN' ) ) . "\n\n" . $this->paragraph( 'TEXT' );

		$this->assertSame( $content, $this->stripper->strip( $content ) );
		$this->assertSame( array(), $this->consulted() );
	}

	/**
	 * Denied Groups disappear; public blocks and entitled Groups stay.
	 */
	public function test_strip_removes_denied_groups_and_keeps_others(): void {
		$denied   = $this->create_segment( 'Denied' );
		$entitled = $this->create_segment( 'Entitled' );

		$content = $this->paragraph( 'PUBLIC' )
			. $this->gated_group( $denied->id(), $this->paragraph( 'SECRET' ) )
			. $this->gated_group( $entitled->id(), $this->paragraph( 'MEMBER-TEXT' ) )
			. $this->plain_group( $this->paragraph( 'PLAIN' ) );

		$this->set_reader( array( $entitled->key() => '1' ) );
		$stripped = $this->stripper->strip( $content );

		$this->assertStringNotContainsString( 'SECRET', $stripped );
		$this->assertStringContainsString( 'PUBLIC', $stripped );
		$this->assertStringContainsString( 'MEMBER-TEXT', $stripped );
		$this->assertStringContainsString( 'PLAIN', $stripped );
		$this->assertSame( array( 'core/paragraph', 'core/group', 'core/group' ), $this->top_level_names( $stripped ) );

		$blocks = parse_blocks( $stripped );
		$this->assertSame( $entitled->id(), $blocks[1]['attrs'][ Block_Enforcer::ATTRIBUTE ] ?? null, 'Kept Groups keep their assignment.' );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $denied->key(), $entitled->key() ), $this->consulted() );
	}

	/**
	 * A denied inner Group is removed from an allowed outer Group, and the
	 * outer Group still serializes correctly.
	 */
	public function test_strip_removes_nested_denied_group(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );

		$content = $this->gated_group(
			$outer->id(),
			$this->paragraph( 'FIRST' ) . $this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) ) . $this->paragraph( 'LAST' )
		);

		$this->set_reader( array( $outer->key() => '1' ) );
		$stripped = $this->stripper->strip( $content );

		$this->assertStringNotContainsString( 'INNER-SECRET', $stripped );
		$this->assertStringContainsString( 'FIRST', $stripped );
		$this->assertStringContainsString( 'LAST', $stripped );

		$blocks = parse_blocks( $stripped );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );
		$this->assertSame( array( 'core/paragraph', 'core/paragraph' ), array_column( $blocks[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 2, count( array_filter( $blocks[0]['innerContent'], 'is_null' ) ), 'One marker per kept inner block.' );
		$this->assertSame( $stripped, serialize_blocks( $blocks ), 'The output round-trips through the parser.' );

		$this->set_reader( array( $outer->key() => '1' ) );
		$this->assertStringContainsString( '>FIRST</p>', do_blocks( $stripped ) );
	}

	/**
	 * A denied outer Group takes its inner Group with it, unconsulted.
	 */
	public function test_strip_denied_outer_does_not_consult_inner(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );

		$content = $this->paragraph( 'PUBLIC' ) . $this->gated_group(
			$outer->id(),
			$this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) )
		);

		$stripped = $this->stripper->strip( $content );

		$this->assertStringNotContainsString( 'INNER-SECRET', $stripped );
		$this->assertStringNotContainsString( 'wp:group', $stripped );
		$this->assertStringContainsString( 'PUBLIC', $stripped );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $outer->key() ), $this->consulted() );
	}

	/**
	 * Invalid references are stripped too, with the usage notice.
	 */
	public function test_strip_removes_invalid_reference(): void {
		$this->setExpectedIncorrectUsage( Block_Enforcer::ATTRIBUTE );

		$stripped = $this->stripper->strip( $this->gated_group( 'abc', $this->paragraph( 'SECRET' ) ) . $this->paragraph( 'PUBLIC' ) );

		$this->assertStringNotContainsString( 'SECRET', $stripped );
		$this->assertStringContainsString( 'PUBLIC', $stripped );
	}

	/**
	 * The crawler variant keeps gated Groups (it receives the full content).
	 */
	public function test_strip_keeps_groups_for_crawler(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );

		$this->assertStringContainsString( 'SECRET', $this->stripper->strip( $content ) );
	}

	/**
	 * Freeform (classic) content and whitespace survive a strip.
	 */
	public function test_strip_keeps_freeform_content(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = "<p>Classic HTML</p>\n\n" . $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) . "\n\n" . $this->paragraph( 'PUBLIC' );

		$stripped = $this->stripper->strip( $content );

		$this->assertStringContainsString( '<p>Classic HTML</p>', $stripped );
		$this->assertStringContainsString( 'PUBLIC', $stripped );
		$this->assertStringNotContainsString( 'SECRET', $stripped );
	}
}
