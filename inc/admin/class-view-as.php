<?php
/**
 * Admin-bar "View as" switcher.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Admin;

use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Membership\Membership;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Admin_Bar;

/**
 * Lets users who can edit posts view the front end as a reader with a chosen membership.
 *
 * The choice lives in a cookie that only logged-in users with `edit_posts`
 * can act on. It replaces membership for the request in memory; it never
 * touches the reader cookie. Every response rendered under the switcher is
 * `Cache-Control: no-store`, so it never reaches the edge. Reads stay
 * subject to the declared set, so an editor sees exactly what a reader with
 * that membership would see, including fail-closed reads.
 *
 * @since 0.1.0
 */
final class View_As {

	public const COOKIE = 'content_gates_view_as';
	public const ACTION = 'content_gates_view_as';
	public const NONCE  = 'content_gates_view_as_nonce';
	public const HEADER = 'X-WPVIP-Content-Gates-View-As';

	/**
	 * Parsed selection for this request, group => value, or null.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $selection = null;

	/**
	 * Constructor.
	 *
	 * @param Membership       $membership Membership.
	 * @param Segment_Registry $segments   Segment lookup.
	 */
	public function __construct( private Membership $membership, private Segment_Registry $segments ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'apply_cookie' ), 11 );
		add_action( 'send_headers', array( $this, 'send_no_store' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 200 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the switcher stylesheet when the control will show. Action callback for `wp_enqueue_scripts`.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_style(
			'content-gates-view-as',
			plugins_url( 'assets/view-as.css', CONTENT_GATES_FILE ),
			array( 'admin-bar' ),
			CONTENT_GATES_VERSION
		);
	}

	/**
	 * Point at a new membership instance. For tests.
	 *
	 * @since 0.1.0
	 *
	 * @param Membership $membership Membership.
	 */
	public function rebind( Membership $membership ): void {
		$this->membership = $membership;
		$this->selection  = null;
	}

	/**
	 * Apply the cookie selection for an authorized user. Action callback for `init` 11.
	 *
	 * @since 0.1.0
	 */
	public function apply_cookie(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Logged-in editors only; such requests bypass the page cache and the response is no-store.
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$this->apply( $raw );
	}

	/**
	 * Apply a serialized selection (`key=value,key=value`).
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw Serialized selection.
	 */
	public function apply( string $raw ): void {
		$selection = array();
		foreach ( explode( ',', $raw ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			[ $key, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '1' );
			$segment         = $this->segments->resolve( $key );
			if ( null === $segment || ! $segment->active ) {
				continue;
			}
			$value = $segment->is_meter()
				? ( in_array( $value, array( Membership::METER_OPEN, Membership::METER_LAST, Membership::METER_WALL ), true ) ? $value : '' )
				: '1';
			if ( '' !== $value ) {
				$selection[ $segment->group_name ] = $value;
			}
		}

		ksort( $selection, SORT_STRING );
		$this->selection = $selection;
		$this->membership->override( $selection );
	}

	/**
	 * Mark the response uncacheable while the switcher is active. Action callback for `send_headers`.
	 *
	 * @since 0.1.0
	 */
	public function send_no_store(): void {
		if ( null === $this->selection ) {
			return;
		}

		Headers::send( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		Headers::send( self::HEADER, $this->summary() );
	}

	/**
	 * Add the admin bar control on the front end. Action callback for `admin_bar_menu`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function add_node( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'content-gates-view-as',
				'title' => esc_html__( 'View as', 'content-gates' ) . ': ' . esc_html( null === $this->selection ? __( 'yourself', 'content-gates' ) : $this->summary() ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'content-gates-view-as-form',
				'parent' => 'content-gates-view-as',
				'title'  => $this->form_html(),
				'meta'   => array( 'html' => '' ),
			)
		);
	}

	/**
	 * Store or clear the selection. Action callback for `admin_post_content_gates_view_as`.
	 *
	 * @since 0.1.0
	 */
	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to use the View As switcher.', 'content-gates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$pairs = array();
		if ( empty( $_POST['reset'] ) ) {
			$boolean = isset( $_POST['segments'] ) && is_array( $_POST['segments'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['segments'] ) ) : array();
			foreach ( $boolean as $key ) {
				$pairs[] = $key . '=1';
			}
			$meters = isset( $_POST['meters'] ) && is_array( $_POST['meters'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['meters'] ) ) : array();
			foreach ( $meters as $key => $state ) {
				if ( '' !== $state ) {
					$pairs[] = sanitize_text_field( (string) $key ) . '=' . $state;
				}
			}
		}

		$value  = implode( ',', $pairs );
		$path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';
		$expiry = '' === $value ? time() - HOUR_IN_SECONDS : time() + DAY_IN_SECONDS;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Debug cookie for logged-in editors; those requests bypass the page cache and every response under it is no-store.
		setcookie( self::COOKIE, $value, $expiry, $path, $domain, is_ssl(), true );

		$back = wp_get_referer();
		wp_safe_redirect( is_string( $back ) && '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	/**
	 * Current selection as a short string.
	 *
	 * @since 0.1.0
	 */
	public function summary(): string {
		if ( null === $this->selection || array() === $this->selection ) {
			return __( 'anonymous', 'content-gates' );
		}

		$parts = array();
		foreach ( $this->selection as $group => $value ) {
			$segment = $this->segments->resolve_group( $group );
			$label   = null === $segment ? $group : $segment->key;
			$parts[] = '1' === $value ? $label : $label . '=' . $value;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Build the admin-bar form.
	 */
	private function form_html(): string {
		$selected = $this->selection ?? array();

		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="content-gates-view-as">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$html .= wp_nonce_field( self::ACTION, self::NONCE, true, false );

		foreach ( $this->segments->active() as $segment ) {
			if ( $segment->is_meter() ) {
				$current = $selected[ $segment->group_name ] ?? '';
				$html   .= '<label>' . esc_html( $segment->name ) . ' <select name="meters[' . esc_attr( $segment->key ) . ']">';
				foreach ( array(
					''     => __( 'no cookie', 'content-gates' ),
					'open' => 'open',
					'last' => 'last',
					'wall' => 'wall',
				) as $state => $label ) {
					$html .= '<option value="' . esc_attr( $state ) . '"' . selected( $current, $state, false ) . '>' . esc_html( $label ) . '</option>';
				}
				$html .= '</select></label>';
				continue;
			}

			$html .= '<label><input type="checkbox" name="segments[]" value="' . esc_attr( $segment->key ) . '"' . checked( isset( $selected[ $segment->group_name ] ), true, false ) . '> ' . esc_html( $segment->name );
			if ( $segment->crawler ) {
				$html .= ' <em>' . esc_html__( '(crawler)', 'content-gates' ) . '</em>';
			}
			$html .= '</label>';
		}

		$html .= '<div class="content-gates-view-as-actions"><button type="submit">' . esc_html__( 'View as this reader', 'content-gates' ) . '</button>';
		$html .= '<button type="submit" name="reset" value="1" class="content-gates-view-as-reset">' . esc_html__( 'Reset', 'content-gates' ) . '</button></div>';
		$html .= '</form>';

		return $html;
	}
}
