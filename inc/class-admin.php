<?php
/**
 * Segment administration screen.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

/**
 * Provides Settings -> Content Gates.
 */
final class Admin {

	private const CAPABILITY = 'manage_options';

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_post_content_gates_create_segment', array( self::class, 'create_segment' ) );
		add_action( 'admin_post_content_gates_update_segment', array( self::class, 'update_segment' ) );
	}

	/**
	 * Register the settings submenu.
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'Content Gates', 'content-gates' ),
			__( 'Content Gates', 'content-gates' ),
			self::CAPABILITY,
			'content-gates',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render the segment list editor.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage content gates.', 'content-gates' ) );
		}

		$segments = Segment_Repository::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Gates', 'content-gates' ); ?></h1>
			<p><?php esc_html_e( 'Segments identify reader groups. Deactivate a segment to retire it without breaking saved content assignments.', 'content-gates' ); ?></p>

			<?php if ( isset( $_GET['content-gates-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Status-only redirect flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Segment saved.', 'content-gates' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Segments', 'content-gates' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'ID', 'content-gates' ); ?></th><th><?php esc_html_e( 'Name', 'content-gates' ); ?></th><th><?php esc_html_e( 'Active', 'content-gates' ); ?></th><th><?php esc_html_e( 'Action', 'content-gates' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $segments ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No segments yet.', 'content-gates' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $segments as $segment ) : ?>
					<?php $form_id = 'content-gates-segment-' . $segment->ID; ?>
					<tr>
						<td><?php echo esc_html( (string) $segment->ID ); ?><input form="<?php echo esc_attr( $form_id ); ?>" type="hidden" name="segment_id" value="<?php echo esc_attr( (string) $segment->ID ); ?>" /></td>
						<td><input form="<?php echo esc_attr( $form_id ); ?>" class="regular-text" required type="text" name="segment_name" value="<?php echo esc_attr( $segment->post_title ); ?>" /></td>
						<td><label><input form="<?php echo esc_attr( $form_id ); ?>" type="checkbox" name="segment_active" value="1" <?php checked( Segment_Repository::STATUS_ACTIVE, $segment->post_status ); ?> /> <?php esc_html_e( 'Active', 'content-gates' ); ?></label></td>
						<td>
							<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="content_gates_update_segment" />
								<?php wp_nonce_field( 'content_gates_update_segment_' . $segment->ID ); ?>
								<?php submit_button( __( 'Save', 'content-gates' ), 'secondary small', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add segment', 'content-gates' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="content_gates_create_segment" />
				<?php wp_nonce_field( 'content_gates_create_segment' ); ?>
				<label for="content-gates-segment-name"><?php esc_html_e( 'Name', 'content-gates' ); ?></label>
				<input id="content-gates-segment-name" class="regular-text" required type="text" name="segment_name" />
				<?php submit_button( __( 'Add segment', 'content-gates' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle segment creation.
	 */
	public static function create_segment(): void {
		self::authorize( 'content_gates_create_segment' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this request before input is read.
		$name   = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$result = Segment_Repository::create( $name );

		self::redirect_after_save( is_wp_error( $result ) ? (string) $result->get_error_code() : '' );
	}

	/**
	 * Handle segment updates.
	 */
	public static function update_segment(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The value selects the nonce action verified below.
		$segment_id = isset( $_POST['segment_id'] ) ? absint( $_POST['segment_id'] ) : 0;
		self::authorize( 'content_gates_update_segment_' . $segment_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this request before input is read.
		$name = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this request before input is read.
		$active = isset( $_POST['segment_active'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['segment_active'] ) );
		$result = Segment_Repository::update( $segment_id, $name, $active );

		self::redirect_after_save( is_wp_error( $result ) ? (string) $result->get_error_code() : '' );
	}

	/**
	 * Check capability and nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage content gates.', 'content-gates' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect to the list editor.
	 *
	 * @param string $error_code Optional error code.
	 */
	private static function redirect_after_save( string $error_code ): void {
		$args = array( 'page' => 'content-gates' );
		if ( '' === $error_code ) {
			$args['content-gates-updated'] = '1';
		} else {
			$args['content-gates-error'] = $error_code;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}
}
