<?php
/**
 * Editor assets tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Editor\Editor_Assets;
use ContentGates\Segments\Segment;
use WP_UnitTestCase;

require_once __DIR__ . '/class-array-segment-source.php';

/**
 * The editor script receives CPT segments only, with their active flags.
 */
final class Test_Editor_Assets extends WP_UnitTestCase {

	/**
	 * Assets under test.
	 *
	 * @var Editor_Assets
	 */
	private Editor_Assets $assets;

	/**
	 * Build on an in-memory source with CPT and code segments.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->assets = new Editor_Assets(
			new Array_Segment_Source(
				array(
					new Segment( 11, 'subscribers', 'Subscribers', true ),
					new Segment( 12, 'archived', 'Archived <b>&</b>', false ),
					new Segment( null, 'crawler', 'crawler', true ),
					new Segment( null, 'newsletter', 'Newsletter', true ),
				)
			)
		);
	}

	/**
	 * Drop the script between tests.
	 */
	public function tear_down(): void {
		wp_dequeue_script( Editor_Assets::HANDLE );
		wp_deregister_script( Editor_Assets::HANDLE );

		parent::tear_down();
	}

	/**
	 * The plugin hooks the assets on the editor enqueue action.
	 */
	public function test_plugin_hooks_enqueue(): void {
		$callbacks = $GLOBALS['wp_filter']['enqueue_block_editor_assets']->callbacks[10] ?? array();
		$found     = false;
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && ( $function[0] ?? null ) instanceof Editor_Assets && 'enqueue' === ( $function[1] ?? null ) ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'Editor_Assets::enqueue is not hooked to enqueue_block_editor_assets.' );
	}

	/**
	 * The data lists CPT segments with IDs and flags; code segments are excluded.
	 */
	public function test_editor_data_lists_cpt_segments_only(): void {
		$this->assertSame(
			array(
				'segments' => array(
					array(
						'id'     => 11,
						'name'   => 'Subscribers',
						'active' => true,
					),
					array(
						'id'     => 12,
						'name'   => 'Archived <b>&</b>',
						'active' => false,
					),
				),
			),
			$this->assets->editor_data()
		);
	}

	/**
	 * Enqueueing registers the script with its dependencies and inline data.
	 */
	public function test_enqueue_registers_script_and_inline_data(): void {
		$this->assets->enqueue();

		$this->assertTrue( wp_script_is( Editor_Assets::HANDLE, 'enqueued' ) );

		$script = wp_scripts()->registered[ Editor_Assets::HANDLE ] ?? null;
		$this->assertNotNull( $script );
		$this->assertStringEndsWith( '/assets/editor.js', (string) $script->src );
		$this->assertSame( CONTENT_GATES_VERSION, $script->ver );
		$this->assertSame(
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-blocks' ),
			$script->deps
		);
		$this->assertSame( 1, wp_scripts()->get_data( Editor_Assets::HANDLE, 'group' ), 'Loaded in the footer.' );

		$inline = wp_scripts()->get_inline_script_data( Editor_Assets::HANDLE, 'before' );
		$this->assertStringStartsWith( 'window.contentGatesEditor = {"segments":[', $inline );
		$this->assertStringContainsString( '"id":11,"name":"Subscribers","active":true', $inline );
		$this->assertStringContainsString( '"id":12,"name":"Archived \\u003Cb\\u003E\\u0026\\u003C/b\\u003E","active":false', $inline );
		$this->assertStringNotContainsString( 'newsletter', $inline );
		$this->assertStringNotContainsString( 'crawler', $inline );
		$this->assertStringNotContainsString( '</', $inline, 'Inline data cannot close a script element.' );
	}

	/**
	 * The script file exists and is plain JavaScript (no JSX).
	 */
	public function test_script_file_exists(): void {
		$path = CONTENT_GATES_PATH . 'assets/editor.js';

		$this->assertFileExists( $path );

		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local plugin file.
		$this->assertStringContainsString( "addFilter(\n\t\t'blocks.registerBlockType'", $source );
		$this->assertStringContainsString( "'editor.BlockEdit'", $source );
		$this->assertStringContainsString( 'contentGatesSegmentId', $source );
		$this->assertStringNotContainsString( 'blocks.getSaveContent.extraProps', $source );
	}
}
