<?php
/**
 * Block editor assets.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Editor;

/**
 * Loads the editor script that adds the Segments control to Group blocks.
 *
 * The script is built from `src/` with `wp-scripts build` into `build/`.
 * When the build is missing the plugin still gates content; it only lacks
 * the editor control, and says so in an admin notice.
 *
 * @since 0.1.0
 */
final class Editor_Assets {

	public const HANDLE = 'content-gates-editor';

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
	}

	/**
	 * Enqueue the built script. Action callback for `enqueue_block_editor_assets`.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		$asset_file = CONTENT_GATES_PATH . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', CONTENT_GATES_FILE ),
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			is_string( $asset['version'] ?? null ) ? $asset['version'] : CONTENT_GATES_VERSION,
			true
		);
		wp_set_script_translations( self::HANDLE, 'content-gates' );
	}

	/**
	 * Tell developers when the editor script has not been built.
	 *
	 * @since 0.1.0
	 */
	public function missing_build_notice(): void {
		if ( is_readable( CONTENT_GATES_PATH . 'build/index.asset.php' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Content Gates: the editor script is not built, so Group blocks have no Segments control. Run `npm run build` in the plugin directory.', 'content-gates' )
		);
	}
}
