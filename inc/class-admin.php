<?php
/**
 * Segment administration.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

/** Settings list editor; membership is never granted here. */
final class Admin {
	/** Register administration hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_content_gates_save_segment', array( self::class, 'save' ) );
	}

	/** Register the Settings submenu. */
	public static function menu(): void {
		add_options_page( __( 'Content Gates', 'content-gates' ), __( 'Content Gates', 'content-gates' ), 'manage_options', 'content-gates', array( self::class, 'render' ) );
	}

	/** Validate and persist one segment without changing its identity. */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage segments.', 'content-gates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'content_gates_save_segment' );
		$id     = isset( $_POST['segment_id'] ) && is_string( $_POST['segment_id'] ) ? absint( $_POST['segment_id'] ) : 0;
		$name   = isset( $_POST['segment_name'] ) && is_string( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$status = isset( $_POST['segment_status'] ) && 'publish' === $_POST['segment_status'] ? 'publish' : 'draft';
		if ( '' === $name || ( $id && null === Segments::get( $id ) ) ) {
			wp_die( esc_html__( 'Enter a name and a valid segment.', 'content-gates' ), '', array( 'response' => 400 ) );
		}
		$data = array(
			'post_type'   => 'content_gate_segment',
			'post_title'  => $name,
			'post_status' => $status,
		);
		if ( $id ) {
			$data['ID'] = $id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=content-gates&saved=1' ) );
		exit;
	}

	/** Render the accessible list and creation form. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Gates', 'content-gates' ); ?></h1>
			<p><?php esc_html_e( 'Define reader segments for Group blocks. Your entitlement provider decides who belongs to each segment. Deactivate segments instead of deleting them.', 'content-gates' ); ?></p>
			<?php foreach ( Segments::all() as $segment ) : ?>
				<?php self::form( $segment ); ?>
			<?php endforeach; ?>
			<h2><?php esc_html_e( 'Add segment', 'content-gates' ); ?></h2>
			<?php self::form( null ); ?>
		</div>
		<?php
	}

	/**
	 * Render one independent editing form.
	 *
	 * @param \WP_Post|null $segment Existing segment or new record.
	 */
	private static function form( ?\WP_Post $segment ): void {
		$id     = $segment ? $segment->ID : 0;
		$status = $segment ? $segment->post_status : 'publish';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'content_gates_save_segment' ); ?>
			<input type="hidden" name="action" value="content_gates_save_segment" />
			<input type="hidden" name="segment_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<fieldset>
				<legend><?php echo $id ? esc_html( sprintf( /* translators: %d: Stable segment ID. */ __( 'Segment #%d', 'content-gates' ), $id ) ) : esc_html__( 'New segment', 'content-gates' ); ?></legend>
				<p>
					<label for="segment-name-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Name', 'content-gates' ); ?></label>
					<input required class="regular-text" id="segment-name-<?php echo esc_attr( (string) $id ); ?>" name="segment_name" value="<?php echo esc_attr( $segment ? $segment->post_title : '' ); ?>" />
					<label for="segment-status-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Status', 'content-gates' ); ?></label>
					<select id="segment-status-<?php echo esc_attr( (string) $id ); ?>" name="segment_status">
						<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'Active', 'content-gates' ); ?></option>
						<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Inactive', 'content-gates' ); ?></option>
					</select>
					<?php submit_button( $id ? __( 'Save segment', 'content-gates' ) : __( 'Add segment', 'content-gates' ), 'secondary', 'submit', false ); ?>
				</p>
			</fieldset>
		</form>
		<?php
	}
}
