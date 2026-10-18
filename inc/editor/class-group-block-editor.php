<?php
/**
 * Group block editor integration.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Editor;

use ContentGates\Segments\Segment_Repository;
use WP_Post;

/**
 * Adds segment assignment controls to the core Group block.
 */
final class Group_Block_Editor {

	/**
	 * Block attribute used for the stable segment post ID.
	 */
	public const ATTRIBUTE = 'contentGateSegmentId';

	/**
	 * Segment storage.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Create the editor integration.
	 *
	 * @param Segment_Repository $segments Segment storage.
	 */
	public function __construct( Segment_Repository $segments ) {
		$this->segments = $segments;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'register_block_type_args', array( $this, 'register_group_attribute' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the segment ID attribute on the server.
	 *
	 * @param array<string, mixed> $args       Block type arguments.
	 * @param string               $block_type Block type name.
	 * @return array<string, mixed>
	 */
	public function register_group_attribute( array $args, string $block_type ): array {
		if ( 'core/group' !== $block_type ) {
			return $args;
		}

		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}

		$args['attributes'][ self::ATTRIBUTE ] = array(
			'type' => 'integer',
		);

		return $args;
	}

	/**
	 * Load the editor control and its segment records.
	 */
	public function enqueue_assets(): void {
		$handle = 'content-gates-editor';

		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/editor.js', CONTENT_GATES_FILE ),
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' ),
			CONTENT_GATES_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'contentGatesEditor',
			array(
				'segments' => $this->get_editor_segments(),
			)
		);
	}

	/**
	 * Return segment data needed by the block control.
	 *
	 * Active records are normal choices. Inactive records remain available so
	 * the editor can identify an existing assignment without offering a new one.
	 *
	 * @return list<array{id: int, name: string, active: bool}>
	 */
	private function get_editor_segments(): array {
		$records  = $this->segments->get_all();
		$segments = array();

		foreach ( $records as $record ) {
			if ( ! $record instanceof WP_Post ) {
				continue;
			}

			$segments[] = array(
				'id'     => $record->ID,
				'name'   => get_the_title( $record ),
				'active' => $this->segments->is_active( $record->ID ),
			);
		}

		return $segments;
	}
}
