<?php
/**
 * Segment settings page.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Admin;

use ContentGates\Segments\Segment;
use ContentGates\Segments\Segment_Registry;
use WP_Error;

/**
 * Settings → Content Gates: list, create, rename, activate, deactivate.
 *
 * Plain HTML forms posted to `admin-post.php`; no JavaScript. Every
 * mutation requires `manage_options` and the `content_gates_segment` nonce.
 * There is deliberately no delete or trash control: segments are retired
 * by deactivation so IDs and content references survive.
 */
final class Segment_Admin {

	/**
	 * Settings page slug.
	 */
	public const PAGE_SLUG = 'content-gates';

	/**
	 * `admin-post.php` action and nonce action.
	 */
	public const ACTION = 'content_gates_segment';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = '_content_gates_nonce';

	/**
	 * Query argument carrying the result of the last operation.
	 */
	public const NOTICE_ARG = 'content_gates_notice';

	/**
	 * Query argument carrying the error code when the notice is `error`.
	 */
	public const ERROR_ARG = 'content_gates_error';

	/**
	 * Operations the handler accepts.
	 */
	private const OPERATIONS = array( 'create', 'rename', 'activate', 'deactivate' );

	/**
	 * Constructor.
	 *
	 * @param Segment_Registry $registry Segment registry.
	 */
	public function __construct( private Segment_Registry $registry ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Register the Settings → Content Gates page.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Content Gates', 'content-gates' ),
			__( 'Content Gates', 'content-gates' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * URL of the settings page, with optional query arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $args Extra query arguments.
	 */
	public function page_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Handle a create/rename/activate/deactivate POST and redirect back.
	 *
	 * Dies with 403 without `manage_options`; dies via `check_admin_referer`
	 * on a bad nonce. Otherwise redirects to the settings page with
	 * `content_gates_notice` (and `content_gates_error` on failure).
	 *
	 * @since 0.1.0
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage segments.', 'content-gates' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION, self::NONCE_NAME );

		$result = $this->run_operation(
			self::post_string( 'operation' ),
			self::post_string( 'segment_name' ),
			self::post_int( 'segment_id' )
		);

		$args = is_string( $result )
			? array( self::NOTICE_ARG => $result )
			: array(
				self::NOTICE_ARG => 'error',
				self::ERROR_ARG  => (string) $result->get_error_code(),
			);

		if ( wp_safe_redirect( $this->page_url( $args ) ) ) {
			exit;
		}
	}

	/**
	 * Run one operation against the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $operation One of create, rename, activate, deactivate.
	 * @param string $name      Segment name for create and rename.
	 * @param int    $id        Segment post ID for rename, activate, deactivate.
	 * @return string|WP_Error Notice code on success.
	 */
	public function run_operation( string $operation, string $name, int $id ): string|WP_Error {
		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new WP_Error( 'content_gates_invalid_operation', __( 'Unknown operation.', 'content-gates' ) );
		}

		switch ( $operation ) {
			case 'create':
				$result = $this->registry->create( $name );
				$notice = 'created';
				break;
			case 'rename':
				$result = $this->registry->rename( $id, $name );
				$notice = 'renamed';
				break;
			case 'activate':
				$result = $this->registry->set_active( $id, true );
				$notice = 'activated';
				break;
			default:
				$result = $this->registry->set_active( $id, false );
				$notice = 'deactivated';
				break;
		}

		return $result instanceof WP_Error ? $result : $notice;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 0.1.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage segments.', 'content-gates' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Content Gates', 'content-gates' ) . '</h1>';

		$this->render_notice();

		echo '<p>' . esc_html__( 'Segments group readers for content gating. A segment can be deactivated and reactivated; its ID and content assignments always stay valid.', 'content-gates' ) . '</p>';

		$this->render_table();
		$this->render_create_form();

		echo '</div>';
	}

	/**
	 * Render the notice for the last operation, when present.
	 *
	 * @since 0.1.0
	 */
	public function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags set by our own redirect; nothing changes state.
		$notice = isset( $_GET[ self::NOTICE_ARG ] ) && is_string( $_GET[ self::NOTICE_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) )
			: '';
		$error  = isset( $_GET[ self::ERROR_ARG ] ) && is_string( $_GET[ self::ERROR_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::ERROR_ARG ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'created'     => __( 'Segment created.', 'content-gates' ),
			'renamed'     => __( 'Segment renamed.', 'content-gates' ),
			'activated'   => __( 'Segment activated.', 'content-gates' ),
			'deactivated' => __( 'Segment deactivated.', 'content-gates' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
			return;
		}

		if ( 'error' === $notice ) {
			$message = '' === $error
				? __( 'The segment change failed.', 'content-gates' )
				/* translators: %s: error code. */
				: sprintf( __( 'The segment change failed (%s).', 'content-gates' ), $error );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Render the segment table.
	 *
	 * @since 0.1.0
	 */
	public function render_table(): void {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'content-gates' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'content-gates' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Key', 'content-gates' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'content-gates' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'content-gates' ) . '</th>';
		echo '</tr></thead><tbody>';

		$segments = $this->registry->all();
		if ( array() === $segments ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No segments yet.', 'content-gates' ) . '</td></tr>';
		}

		foreach ( $segments as $segment ) {
			$this->render_row( $segment );
		}

		echo '</tbody></table>';
	}

	/**
	 * Render one table row.
	 *
	 * @param Segment $segment Segment to render.
	 */
	private function render_row( Segment $segment ): void {
		$id     = $segment->id();
		$status = $segment->is_active()
			? __( 'Active', 'content-gates' )
			: __( 'Inactive', 'content-gates' );

		echo '<tr>';

		if ( null === $id ) {
			echo '<td>' . esc_html__( 'code', 'content-gates' ) . '</td>';
			echo '<td>' . esc_html( $segment->name() ) . '</td>';
			echo '<td><code>' . esc_html( $segment->key() ) . '</code></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>&mdash;</td>';
			echo '</tr>';
			return;
		}

		echo '<td>' . esc_html( (string) $id ) . '</td>';

		echo '<td>';
		$this->open_form( 'rename', $id );
		echo '<label class="screen-reader-text" for="content-gates-name-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Segment name', 'content-gates' ) . '</label>';
		echo '<input type="text" class="regular-text" id="content-gates-name-' . esc_attr( (string) $id ) . '" name="segment_name" value="' . esc_attr( $segment->name() ) . '" required> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Rename', 'content-gates' ) . '</button>';
		echo '</form>';
		echo '</td>';

		echo '<td><code>' . esc_html( $segment->key() ) . '</code></td>';
		echo '<td>' . esc_html( $status ) . '</td>';

		echo '<td>';
		if ( $segment->is_active() ) {
			$this->open_form( 'deactivate', $id );
			echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'content-gates' ) . '</button>';
		} else {
			$this->open_form( 'activate', $id );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Activate', 'content-gates' ) . '</button>';
		}
		echo '</form>';
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Render the "Add segment" form.
	 *
	 * @since 0.1.0
	 */
	public function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Add segment', 'content-gates' ) . '</h2>';
		$this->open_form( 'create', null );
		echo '<label for="content-gates-new-name">' . esc_html__( 'Name', 'content-gates' ) . '</label> ';
		echo '<input type="text" class="regular-text" id="content-gates-new-name" name="segment_name" value="" required> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add segment', 'content-gates' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Open a form posted to admin-post.php with action, operation, ID, and nonce.
	 *
	 * The caller closes it with `</form>`.
	 *
	 * @param string   $operation Operation name.
	 * @param int|null $id        Segment post ID, or null for create.
	 */
	private function open_form( string $operation, ?int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		if ( null !== $id ) {
			echo '<input type="hidden" name="segment_id" value="' . esc_attr( (string) $id ) . '">';
		}
		wp_nonce_field( self::ACTION, self::NONCE_NAME );
	}

	/**
	 * Read a sanitized string field from the POST body.
	 *
	 * Nonce verification happens in `handle_action()` before this is called.
	 *
	 * @param string $field Field name.
	 */
	private static function post_string( string $field ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle_action().
		if ( ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read a non-negative integer field from the POST body.
	 *
	 * @param string $field Field name.
	 */
	private static function post_int( string $field ): int {
		return absint( self::post_string( $field ) );
	}
}
