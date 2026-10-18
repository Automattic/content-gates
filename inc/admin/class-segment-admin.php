<?php
/**
 * Segment settings page.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Admin;

use ContentGates\Segments\Segment_Repository;
use WP_Error;

/**
 * Provides the Settings > Content Gates segment list editor.
 */
final class Segment_Admin {

	/**
	 * Settings page slug.
	 */
	public const PAGE_SLUG = 'content-gates';

	/**
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Create the settings controller.
	 *
	 * @param Segment_Repository $segments Segment repository.
	 */
	public function __construct( Segment_Repository $segments ) {
		$this->segments = $segments;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_content_gates_create_segment', array( $this, 'handle_create' ) );
		add_action( 'admin_post_content_gates_rename_segment', array( $this, 'handle_rename' ) );
		add_action( 'admin_post_content_gates_set_segment_status', array( $this, 'handle_set_status' ) );
	}

	/**
	 * Register the settings submenu page.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Content Gates', 'content-gates' ),
			__( 'Content Gates', 'content-gates' ),
			Segment_Repository::ADMIN_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the segment list editor.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Segment_Repository::ADMIN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You cannot manage content gate segments.', 'content-gates' ) );
		}

		$segments = $this->segments->get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Gates', 'content-gates' ); ?></h1>
			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Add segment', 'content-gates' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="content_gates_create_segment" />
				<?php wp_nonce_field( 'content_gates_create_segment' ); ?>
				<label for="content-gates-new-segment-name" class="screen-reader-text"><?php esc_html_e( 'Segment name', 'content-gates' ); ?></label>
				<input id="content-gates-new-segment-name" name="segment_name" type="text" required />
				<?php submit_button( __( 'Add segment', 'content-gates' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Segments', 'content-gates' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'content-gates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'content-gates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'content-gates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'content-gates' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $segments ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No segments exist.', 'content-gates' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $segments as $segment ) : ?>
						<tr>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="content_gates_rename_segment" />
									<input type="hidden" name="segment_id" value="<?php echo esc_attr( (string) $segment->ID ); ?>" />
									<?php wp_nonce_field( 'content_gates_rename_segment' ); ?>
									<label for="content-gates-segment-<?php echo esc_attr( (string) $segment->ID ); ?>" class="screen-reader-text"><?php esc_html_e( 'Segment name', 'content-gates' ); ?></label>
									<input id="content-gates-segment-<?php echo esc_attr( (string) $segment->ID ); ?>" name="segment_name" type="text" value="<?php echo esc_attr( $segment->post_title ); ?>" required />
									<?php submit_button( __( 'Save name', 'content-gates' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
							<td><?php echo esc_html( (string) $segment->ID ); ?></td>
							<td><?php echo esc_html( $this->segments->is_active( $segment->ID ) ? __( 'Active', 'content-gates' ) : __( 'Inactive', 'content-gates' ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="content_gates_set_segment_status" />
									<input type="hidden" name="segment_id" value="<?php echo esc_attr( (string) $segment->ID ); ?>" />
									<input type="hidden" name="active" value="<?php echo $this->segments->is_active( $segment->ID ) ? '0' : '1'; ?>" />
									<?php wp_nonce_field( 'content_gates_set_segment_status' ); ?>
									<?php submit_button( $this->segments->is_active( $segment->ID ) ? __( 'Deactivate', 'content-gates' ) : __( 'Activate', 'content-gates' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle segment creation.
	 */
	public function handle_create(): void {
		$this->authorize();
		check_admin_referer( 'content_gates_create_segment' );

		$name   = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$result = $this->segments->create( $name );
		$this->redirect_after_action( $result, 'created' );
	}

	/**
	 * Handle segment rename.
	 */
	public function handle_rename(): void {
		$this->authorize();
		check_admin_referer( 'content_gates_rename_segment' );

		$segment_id = isset( $_POST['segment_id'] ) ? absint( $_POST['segment_id'] ) : 0;

		$name   = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
		$result = $this->segments->rename( $segment_id, $name );
		$this->redirect_after_action( $result, 'updated' );
	}

	/**
	 * Handle segment activation and deactivation.
	 */
	public function handle_set_status(): void {
		$this->authorize();
		check_admin_referer( 'content_gates_set_segment_status' );

		$segment_id = isset( $_POST['segment_id'] ) ? absint( $_POST['segment_id'] ) : 0;

		$active = isset( $_POST['active'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['active'] ) );
		$result = $this->segments->set_active( $segment_id, $active );
		$this->redirect_after_action( $result, $active ? 'activated' : 'deactivated' );
	}

	/**
	 * Verify the current user and request nonce.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Segment_Repository::ADMIN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You cannot manage content gate segments.', 'content-gates' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect to the list after an action.
	 *
	 * @param bool|int|WP_Error $result  Action result.
	 * @param string            $success Success notice code.
	 */
	private function redirect_after_action( bool|int|WP_Error $result, string $success ): never {
		$notice = is_wp_error( $result ) ? $result->get_error_code() : $success;
		$url    = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'cg_notice'        => $notice,
				'cg_notice_result' => is_wp_error( $result ) ? 'error' : 'success',
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a result notice from an allowlist of action codes.
	 */
	private function render_notice(): void {
		$code = isset( $_GET['cg_notice'] ) ? sanitize_key( wp_unslash( $_GET['cg_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only code is checked against an allowlist.
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'created'                          => __( 'Segment created.', 'content-gates' ),
			'updated'                          => __( 'Segment name saved.', 'content-gates' ),
			'activated'                        => __( 'Segment activated.', 'content-gates' ),
			'deactivated'                      => __( 'Segment deactivated.', 'content-gates' ),
			'content_gates_empty_segment_name' => __( 'Enter a segment name.', 'content-gates' ),
			'content_gates_invalid_segment'    => __( 'The segment does not exist.', 'content-gates' ),
			'content_gates_forbidden'          => __( 'You cannot manage content gate segments.', 'content-gates' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$type = isset( $_GET['cg_notice_result'] ) && 'error' === sanitize_key( wp_unslash( $_GET['cg_notice_result'] ) ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only class uses two fixed values.
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $messages[ $code ] )
		);
	}
}
