<?php
/**
 * Settings > Content Gates.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Admin;

use Automattic\VIP\ContentGates\Segments\Crawler_Config;
use Automattic\VIP\ContentGates\Segments\Segment;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;

/**
 * The segment list editor: create, rename, activate, deactivate, reconfigure meters.
 *
 * Plain forms posted to `admin-post.php`, no JavaScript. There is no delete
 * action anywhere; deactivation replaces deletion. Crawler segments are
 * listed read-only.
 *
 * @since 0.1.0
 */
final class Segment_Admin {

	public const PAGE       = 'content-gates';
	public const ACTION     = 'content_gates_segment';
	public const CAPABILITY = 'manage_options';
	public const NONCE      = 'content_gates_segment_nonce';

	/**
	 * Constructor.
	 *
	 * @param Segment_Registry $segments Segment lookup.
	 * @param Crawler_Config   $crawlers Crawler configuration.
	 */
	public function __construct( private Segment_Registry $segments, private Crawler_Config $crawlers ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the settings page stylesheet on that page only. Action callback for `admin_enqueue_scripts`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'content-gates-admin',
			plugins_url( 'assets/admin.css', CONTENT_GATES_FILE ),
			array(),
			CONTENT_GATES_VERSION
		);
	}

	/**
	 * Add the Settings submenu. Action callback for `admin_menu`.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Content Gates', 'content-gates' ),
			__( 'Content Gates', 'content-gates' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the settings page.
	 *
	 * @since 0.1.0
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Handle a form submission. Action callback for `admin_post_content_gates_segment`.
	 *
	 * @since 0.1.0
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage segments.', 'content-gates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$reference = isset( $_POST['segment'] ) ? sanitize_text_field( wp_unslash( $_POST['segment'] ) ) : '';
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$type      = isset( $_POST['type'] ) && Segment::TYPE_METER === sanitize_key( wp_unslash( $_POST['type'] ) ) ? Segment::TYPE_METER : Segment::TYPE_BOOLEAN;
		$limit     = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
		$days      = isset( $_POST['period_days'] ) ? absint( $_POST['period_days'] ) : 0;

		$result = match ( $operation ) {
			'create'     => $this->segments->create( $name, $type, $limit, $days * DAY_IN_SECONDS ),
			'rename'     => $this->segments->rename( $reference, $name ),
			'activate'   => $this->segments->set_active( $reference, true ),
			'deactivate' => $this->segments->set_active( $reference, false ),
			'meter'      => $this->segments->update_meter( $reference, $limit, $days * DAY_IN_SECONDS ),
			default      => new \WP_Error( 'content_gates_invalid_operation', __( 'Unknown operation.', 'content-gates' ) ),
		};

		$args = is_wp_error( $result )
			? array(
				'cg_notice'  => 'error',
				'cg_message' => rawurlencode( $result->get_error_message() ),
			)
			: array( 'cg_notice' => $operation );

		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->segments->maybe_sync_crawlers();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Content Gates', 'content-gates' ) . '</h1>';
		$this->render_notice();

		echo '<p>' . esc_html__( 'A segment is a reader group your entitlement provider assigns readers to. Editors assign Group blocks to segments. Segments can be deactivated but never deleted, so existing assignments keep their meaning.', 'content-gates' ) . '</p>';

		$this->render_table();
		$this->render_create_form();
		echo '</div>';
	}

	/**
	 * Print the result notice from the query string.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result.
		$notice  = isset( $_GET['cg_notice'] ) ? sanitize_key( wp_unslash( $_GET['cg_notice'] ) ) : '';
		$message = isset( $_GET['cg_message'] ) ? sanitize_text_field( wp_unslash( $_GET['cg_message'] ) ) : '';
		// phpcs:enable

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'create'     => __( 'Segment created.', 'content-gates' ),
			'rename'     => __( 'Segment renamed. Its key did not change.', 'content-gates' ),
			'activate'   => __( 'Segment activated. A site-wide cache purge was requested.', 'content-gates' ),
			'deactivate' => __( 'Segment deactivated. Its assignments are kept and read as not-in-segment. A site-wide cache purge was requested.', 'content-gates' ),
			'meter'      => __( 'Meter updated. A site-wide cache purge was requested.', 'content-gates' ),
		);

		if ( 'error' === $notice ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Print the segment table.
	 */
	private function render_table(): void {
		$all = $this->segments->all();

		echo '<h2>' . esc_html__( 'Segments', 'content-gates' ) . '</h2>';
		echo '<table class="widefat striped" id="content-gates-segments">';
		echo '<thead><tr>';
		foreach ( array( __( 'Name', 'content-gates' ), __( 'Key', 'content-gates' ), __( 'Type', 'content-gates' ), __( 'Status', 'content-gates' ), __( 'Actions', 'content-gates' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $all ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No segments yet.', 'content-gates' ) . '</td></tr>';
		}

		foreach ( $all as $segment ) {
			$this->render_row( $segment );
		}

		echo '</tbody></table>';
	}

	/**
	 * Print one segment row.
	 *
	 * @param Segment $segment Segment.
	 */
	private function render_row( Segment $segment ): void {
		$row_id = 'content-gates-segment-' . $segment->key;
		echo '<tr id="' . esc_attr( $row_id ) . '" data-key="' . esc_attr( $segment->key ) . '" data-active="' . ( $segment->active ? '1' : '0' ) . '">';

		echo '<td>' . esc_html( $segment->name );
		if ( $segment->crawler ) {
			echo ' <span class="description">' . esc_html__( '(VIP-maintained)', 'content-gates' ) . '</span>';
		}
		echo '</td>';

		echo '<td><code>' . esc_html( $segment->key ) . '</code></td>';

		echo '<td>';
		if ( $segment->is_meter() ) {
			printf(
				/* translators: 1: article limit, 2: period in days */
				esc_html__( 'Meter: %1$d per %2$s days', 'content-gates' ),
				(int) $segment->limit,
				esc_html( (string) round( $segment->period / DAY_IN_SECONDS, 2 ) )
			);
		} else {
			echo esc_html( $segment->crawler ? __( 'Crawler', 'content-gates' ) : __( 'Boolean', 'content-gates' ) );
		}
		echo '</td>';

		echo '<td>' . esc_html( $segment->active ? __( 'Active', 'content-gates' ) : __( 'Inactive', 'content-gates' ) ) . '</td>';

		echo '<td><div class="content-gates-actions">';
		if ( $segment->crawler ) {
			echo '<span class="description">' . esc_html__( 'Managed in config/crawlers.json', 'content-gates' ) . '</span>';
		} else {
			$this->render_row_forms( $segment );
		}
		echo '</div></td>';

		echo '</tr>';
	}

	/**
	 * Print the per-row action forms.
	 *
	 * @param Segment $segment Segment.
	 */
	private function render_row_forms( Segment $segment ): void {
		$action_url = admin_url( 'admin-post.php' );

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		$this->render_hidden_fields( $segment->active ? 'deactivate' : 'activate', $segment->key );
		submit_button( $segment->active ? __( 'Deactivate', 'content-gates' ) : __( 'Activate', 'content-gates' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		$this->render_hidden_fields( 'rename', $segment->key );
		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="text" id="%1$s" name="name" value="%3$s" required>',
			esc_attr( 'content-gates-rename-' . $segment->key ),
			esc_html__( 'New name', 'content-gates' ),
			esc_attr( $segment->name )
		);
		submit_button( __( 'Rename', 'content-gates' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $segment->is_meter() ) {
			echo '<form method="post" action="' . esc_url( $action_url ) . '">';
			$this->render_hidden_fields( 'meter', $segment->key );
			printf(
				'<label>%1$s <input type="number" name="limit" min="1" value="%2$d"></label><label>%3$s <input type="number" name="period_days" min="1" value="%4$s"></label>',
				esc_html__( 'Limit', 'content-gates' ),
				(int) $segment->limit,
				esc_html__( 'Period (days)', 'content-gates' ),
				esc_attr( (string) max( 1, (int) round( $segment->period / DAY_IN_SECONDS ) ) )
			);
			submit_button( __( 'Update meter', 'content-gates' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Print the create form.
	 */
	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Add a segment', 'content-gates' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="content-gates-create">';
		$this->render_hidden_fields( 'create', '' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="content-gates-new-name">' . esc_html__( 'Name', 'content-gates' ) . '</label></th>';
		echo '<td><input type="text" id="content-gates-new-name" name="name" required class="regular-text"> <p class="description">' . esc_html__( 'The key is derived from the first name and never changes.', 'content-gates' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="content-gates-new-type">' . esc_html__( 'Type', 'content-gates' ) . '</label></th>';
		echo '<td><select id="content-gates-new-type" name="type">';
		echo '<option value="boolean">' . esc_html__( 'Boolean: the provider assigns readers', 'content-gates' ) . '</option>';
		echo '<option value="meter">' . esc_html__( 'Meter: anonymous readers get a limited number of articles per period', 'content-gates' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr class="content-gates-meter-only"><th scope="row"><label for="content-gates-new-limit">' . esc_html__( 'Meter limit', 'content-gates' ) . '</label></th>';
		echo '<td><input type="number" id="content-gates-new-limit" name="limit" min="1" value="5" class="small-text"> <p class="description">' . esc_html__( 'Articles per period.', 'content-gates' ) . '</p></td></tr>';

		echo '<tr class="content-gates-meter-only"><th scope="row"><label for="content-gates-new-period">' . esc_html__( 'Meter period (days)', 'content-gates' ) . '</label></th>';
		echo '<td><input type="number" id="content-gates-new-period" name="period_days" min="1" value="30" class="small-text"> <p class="description">' . esc_html__( 'Entered in days, stored in seconds.', 'content-gates' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add segment', 'content-gates' ) );
		echo '</form>';

		$version = $this->crawlers->version();
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: crawler configuration version */
				__( 'Crawler segments come from config/crawlers.json (version %d) and are mirrored here automatically.', 'content-gates' ),
				$version
			)
		) . '</p>';
	}

	/**
	 * Print the hidden fields every form carries.
	 *
	 * @param string $operation Operation name.
	 * @param string $reference Segment key, or empty.
	 */
	private function render_hidden_fields( string $operation, string $reference ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		if ( '' !== $reference ) {
			echo '<input type="hidden" name="segment" value="' . esc_attr( $reference ) . '">';
		}
		wp_nonce_field( self::ACTION, self::NONCE );
	}
}
