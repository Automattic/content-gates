<?php
/**
 * Tests for the Group block editor integration.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Editor\Group_Block_Editor;
use ContentGates\Segments\Segment_Repository;
use WP_UnitTestCase;

/**
 * Verifies the Group block attribute and editor data.
 */
final class Test_Group_Block_Editor extends WP_UnitTestCase {

	/**
	 * Segment storage.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Editor integration under test.
	 *
	 * @var Group_Block_Editor
	 */
	private Group_Block_Editor $editor;

	/**
	 * Register the segment record types before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->segments = new Segment_Repository();
		$this->segments->register_types();
		$this->editor = new Group_Block_Editor( $this->segments );
	}

	/**
	 * The server recognizes the saved integer attribute on Group blocks only.
	 */
	public function test_registers_integer_attribute_for_group_blocks(): void {
		$args = $this->editor->register_group_attribute( array(), 'core/group' );

		$this->assertSame(
			array( 'type' => 'integer' ),
			$args['attributes'][ Group_Block_Editor::ATTRIBUTE ]
		);
		$this->assertSame( array(), $this->editor->register_group_attribute( array(), 'core/paragraph' ) );
	}

	/**
	 * The editor receives both active and inactive records for stable references.
	 */
	public function test_enqueues_script_with_active_and_inactive_segments(): void {
		$active_id   = self::factory()->post->create(
			array(
				'post_type'   => Segment_Repository::POST_TYPE,
				'post_status' => Segment_Repository::STATUS_ACTIVE,
				'post_title'  => 'Subscribers',
			)
		);
		$inactive_id = self::factory()->post->create(
			array(
				'post_type'   => Segment_Repository::POST_TYPE,
				'post_status' => Segment_Repository::STATUS_INACTIVE,
				'post_title'  => 'Former members',
			)
		);
		if ( is_wp_error( $active_id ) ) {
			$this->fail( $active_id->get_error_message() );
		}
		if ( is_wp_error( $inactive_id ) ) {
			$this->fail( $inactive_id->get_error_message() );
		}

		$this->editor->enqueue_assets();

		$scripts   = wp_scripts();
		$localized = $scripts->get_data( 'content-gates-editor', 'data' );

		$this->assertIsString( $localized );
		$this->assertStringContainsString( 'contentGatesEditor', $localized );
		$this->assertStringContainsString( 'Subscribers', $localized );
		$this->assertStringContainsString( 'Former members', $localized );
		$this->assertStringContainsString( '"id":' . $active_id . ',"name":"Subscribers","active":true', $localized );
		$this->assertStringContainsString( '"id":' . $inactive_id . ',"name":"Former members","active":false', $localized );
	}
}
