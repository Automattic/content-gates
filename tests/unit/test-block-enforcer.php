<?php
/**
 * Tests for server-side block access enforcement.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Editor\Group_Block_Editor;
use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Segments\Segment_Repository;
use WP_UnitTestCase;

/**
 * Verifies that denied content does not render.
 */
final class Test_Block_Enforcer extends WP_UnitTestCase {

	/**
	 * Segment storage.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Register segment types before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->segments = new Segment_Repository();
		$this->segments->register_types();
	}

	/**
	 * Unassigned blocks continue through the normal rendering pipeline.
	 */
	public function test_unassigned_group_is_unchanged(): void {
		$enforcer = new Block_Enforcer( $this->segments, static fn (): bool => false );

		$this->assertNull(
			$enforcer->enforce_group_access(
				null,
				array(
					'blockName' => 'core/group',
					'attrs'     => array(),
				)
			)
		);
	}

	/**
	 * Malformed and missing segment references fail closed.
	 */
	public function test_invalid_references_are_withheld_without_membership_read(): void {
		$reads    = 0;
		$enforcer = new Block_Enforcer(
			$this->segments,
			static function () use ( &$reads ): bool {
				++$reads;
				return true;
			}
		);

		foreach ( array( '12', 0, -1, 999999 ) as $segment_id ) {
			$this->assertSame( '', $enforcer->enforce_group_access( null, $this->group( $segment_id ) ) );
		}

		$this->assertSame( 0, $reads );
	}

	/**
	 * Inactive records are consulted for variance and always withheld.
	 */
	public function test_inactive_reference_records_membership_read_and_fails_closed(): void {
		$reads       = array();
		$inactive_id = $this->create_segment( Segment_Repository::STATUS_INACTIVE );
		$enforcer    = new Block_Enforcer(
			$this->segments,
			static function ( int $read_id ) use ( &$reads ): bool {
				$reads[] = $read_id;
				return true;
			}
		);

		$this->assertSame( '', $enforcer->enforce_group_access( null, $this->group( $inactive_id ) ) );
		$this->assertSame( array( $inactive_id ), $reads );
	}

	/**
	 * Active records use the membership wrapper for both outcomes.
	 */
	public function test_active_segment_uses_membership_result(): void {
		$segment_id = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$reads      = array();
		$denied     = new Block_Enforcer(
			$this->segments,
			static function ( int $read_id ) use ( &$reads ): bool {
				$reads[] = $read_id;
				return false;
			}
		);

		$this->assertSame( '', $denied->enforce_group_access( null, $this->group( $segment_id ) ) );
		$this->assertSame( array( $segment_id ), $reads );

		$allowed = new Block_Enforcer( $this->segments, static fn (): bool => true );
		$this->assertNull( $allowed->enforce_group_access( null, $this->group( $segment_id ) ) );
	}

	/**
	 * A denied outer Group stops dynamic child callbacks.
	 */
	public function test_denied_group_short_circuits_dynamic_children(): void {
		$segment_id = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$renders    = 0;
		$enforcer   = new Block_Enforcer( $this->segments, static fn (): bool => false );

		register_block_type(
			'content-gates/test-dynamic',
			array(
				'render_callback' => static function () use ( &$renders ): string {
					++$renders;
					return 'restricted dynamic marker';
				},
			)
		);
		add_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10, 2 );

		$output = render_block( parse_blocks( $this->group_markup( $segment_id ) )[0] );

		remove_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10 );
		unregister_block_type( 'content-gates/test-dynamic' );

		$this->assertSame( '', $output );
		$this->assertSame( 0, $renders );
	}

	/**
	 * An entitled reader receives the Group and its dynamic children.
	 */
	public function test_allowed_group_renders_dynamic_children(): void {
		$segment_id = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$renders    = 0;
		$enforcer   = new Block_Enforcer( $this->segments, static fn (): bool => true );
		$assignment = \ContentGates\content_gates_assign_reader_to_segment( $segment_id );

		$this->assertNotWPError( $assignment );

		register_block_type(
			'content-gates/test-dynamic',
			array(
				'render_callback' => static function () use ( &$renders ): string {
					++$renders;
					return 'entitled dynamic marker';
				},
			)
		);
		add_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10, 2 );

		$output = render_block( parse_blocks( $this->group_markup( $segment_id ) )[0] );

		remove_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10 );
		unregister_block_type( 'content-gates/test-dynamic' );

		$this->assertStringContainsString( 'entitled dynamic marker', $output );
		$this->assertSame( 1, $renders );
	}

	/**
	 * An allowed outer Group does not bypass a denied nested Group.
	 */
	public function test_allowed_outer_group_enforces_denied_inner_group(): void {
		$outer_id   = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$inner_id   = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$reads      = array();
		$enforcer   = new Block_Enforcer(
			$this->segments,
			static function ( int $segment_id ) use ( $outer_id, &$reads ): bool {
				$reads[] = $segment_id;
				return $outer_id === $segment_id;
			}
		);
		$assignment = \ContentGates\content_gates_assign_reader_to_segment( $outer_id );
		$this->assertNotWPError( $assignment );

		$markup = sprintf(
			'<!-- wp:group {"%1$s":%2$d} --><div class="wp-block-group"><!-- wp:paragraph --><p>outer allowed marker</p><!-- /wp:paragraph --><!-- wp:group {"%1$s":%3$d} --><div class="wp-block-group"><!-- wp:paragraph --><p>nested restricted marker</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->',
			Group_Block_Editor::ATTRIBUTE,
			$outer_id,
			$inner_id
		);
		add_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10, 2 );

		$output = render_block( parse_blocks( $markup )[0] );

		remove_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10 );
		$this->assertStringContainsString( 'outer allowed marker', $output );
		$this->assertStringNotContainsString( 'nested restricted marker', $output );
		$this->assertSame( array( $outer_id, $inner_id ), $reads );
	}

	/**
	 * Automatic excerpts render the Group wrapper before extracting text.
	 */
	public function test_automatic_excerpt_omits_denied_group_text(): void {
		$segment_id = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$enforcer   = new Block_Enforcer( $this->segments, static fn (): bool => false );
		$post_id    = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Public marker</p><!-- /wp:paragraph -->' .
					$this->group_markup( $segment_id ),
			)
		);
		if ( is_wp_error( $post_id ) ) {
			$this->fail( $post_id->get_error_message() );
		}

		add_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10, 2 );
		add_filter( 'excerpt_allowed_wrapper_blocks', array( $enforcer, 'exclude_groups_from_core_excerpts' ) );
		add_filter( 'get_the_excerpt', array( $enforcer, 'protect_automatic_excerpt' ), 99, 2 );

		$excerpt = get_the_excerpt( $post_id );

		remove_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10 );
		remove_filter( 'excerpt_allowed_wrapper_blocks', array( $enforcer, 'exclude_groups_from_core_excerpts' ) );
		remove_filter( 'get_the_excerpt', array( $enforcer, 'protect_automatic_excerpt' ), 99 );

		$this->assertStringContainsString( 'Public marker', $excerpt );
		$this->assertStringNotContainsString( 'restricted dynamic marker', $excerpt );
	}

	/**
	 * Automatic excerpts include restricted text for an entitled reader.
	 */
	public function test_automatic_excerpt_includes_allowed_group_text(): void {
		$segment_id = $this->create_segment( Segment_Repository::STATUS_ACTIVE );
		$enforcer   = new Block_Enforcer( $this->segments, static fn (): bool => true );
		$assignment = \ContentGates\content_gates_assign_reader_to_segment( $segment_id );
		$post_id    = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Public marker</p><!-- /wp:paragraph -->' .
					$this->group_markup( $segment_id ),
			)
		);
		if ( is_wp_error( $post_id ) ) {
			$this->fail( $post_id->get_error_message() );
		}
		$this->assertNotWPError( $assignment );

		add_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10, 2 );
		add_filter( 'excerpt_allowed_wrapper_blocks', array( $enforcer, 'exclude_groups_from_core_excerpts' ) );
		add_filter( 'get_the_excerpt', array( $enforcer, 'protect_automatic_excerpt' ), 99, 2 );

		$excerpt = get_the_excerpt( $post_id );

		remove_filter( 'pre_render_block', array( $enforcer, 'enforce_group_access' ), 10 );
		remove_filter( 'excerpt_allowed_wrapper_blocks', array( $enforcer, 'exclude_groups_from_core_excerpts' ) );
		remove_filter( 'get_the_excerpt', array( $enforcer, 'protect_automatic_excerpt' ), 99 );

		$this->assertStringContainsString( 'restricted dynamic marker', $excerpt );
	}

	/**
	 * Create one segment record without invoking administrator-facing methods.
	 *
	 * @param string $status Segment status.
	 */
	private function create_segment( string $status ): int {
		$segment_id = self::factory()->post->create(
			array(
				'post_type'   => Segment_Repository::POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Test segment',
			)
		);

		if ( is_wp_error( $segment_id ) ) {
			$this->fail( $segment_id->get_error_message() );
		}

		return $segment_id;
	}

	/**
	 * Build parsed Group data for a segment value.
	 *
	 * @param mixed $segment_id Segment attribute value.
	 * @return array<string,mixed>
	 */
	private function group( mixed $segment_id ): array {
		return array(
			'blockName' => 'core/group',
			'attrs'     => array(
				Group_Block_Editor::ATTRIBUTE => $segment_id,
			),
		);
	}

	/**
	 * Build a Group containing a dynamic block.
	 *
	 * @param int $segment_id Segment post ID.
	 */
	private function group_markup( int $segment_id ): string {
		return sprintf(
			'<!-- wp:group {"%s":%d} --><div class="wp-block-group"><!-- wp:content-gates/test-dynamic /--><p>restricted dynamic marker</p></div><!-- /wp:group -->',
			Group_Block_Editor::ATTRIBUTE,
			$segment_id
		);
	}
}
