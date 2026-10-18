<?php
/**
 * Editor assets.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Editor;

use ContentGates\Segments\Segment_Source;

/**
 * Loads the block editor integration and hands it the segment list.
 *
 * `assets/editor.js` adds the `contentGatesSegmentId` attribute to
 * `core/group` on the client and a Segment dropdown in the block
 * inspector. The script reads `window.contentGatesEditor.segments`, an
 * array of `{ id, name, active }` for CPT segments only: code-registered
 * segments have no ID and cannot be assigned to content.
 *
 * @since 0.1.0
 */
final class Editor_Assets {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'content-gates-editor';

	/**
	 * Constructor.
	 *
	 * @param Segment_Source $segments Segment lookup.
	 */
	public function __construct( private Segment_Source $segments ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Hook callback for `enqueue_block_editor_assets`.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/editor.js', CONTENT_GATES_FILE ),
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-blocks' ),
			CONTENT_GATES_VERSION,
			true
		);

		wp_add_inline_script( self::HANDLE, 'window.contentGatesEditor = ' . $this->editor_json() . ';', 'before' );
	}

	/**
	 * Data handed to the editor script.
	 *
	 * @since 0.1.0
	 *
	 * @return array{segments: array<int, array{id: int, name: string, active: bool}>}
	 */
	public function editor_data(): array {
		$segments = array();

		foreach ( $this->segments->all() as $segment ) {
			$id = $segment->id();
			if ( null === $id ) {
				continue;
			}

			$segments[] = array(
				'id'     => $id,
				'name'   => $segment->name(),
				'active' => $segment->is_active(),
			);
		}

		return array( 'segments' => $segments );
	}

	/**
	 * The editor data as JSON.
	 */
	private function editor_json(): string {
		$json = wp_json_encode( $this->editor_data(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );

		return is_string( $json ) ? $json : '{"segments":[]}';
	}
}
