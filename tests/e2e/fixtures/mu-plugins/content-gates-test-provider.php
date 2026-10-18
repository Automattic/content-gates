<?php
/**
 * Plugin Name: Content Gates Test Provider
 * Description: Test-only entitlement provider. Grants membership to anyone who asks. NEVER deploy.
 *
 * Mapped into the wp-env dev and tests sites as an mu-plugin (see the
 * `mappings` entry in .wp-env.json and .wp-env.tests.json). It stands in
 * for a real provider (Newspack, WooCommerce, ...) so the plugin's two
 * assignment paths can be exercised without one:
 *
 * 1. REST: it grants every `POST /content-gates/v1/membership`.
 * 2. PHP + POST-redirect-GET: a form POST with `content_gates_test_assign=<ref>`
 *    or `content_gates_test_remove=<ref>` is handled on `init`, the public
 *    functions are called, and the request is redirected back to the same
 *    URL once the cookie is written, with `?content_gates_test=<code>`
 *    (`ok` or the WP_Error code). This is the flow from VIP's cache README.
 *
 * On local sites it also prints a small demo box in the footer with a
 * Join/Leave form per active segment.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Provider;

const SEGMENT_KEY  = 'newsletter';
const ASSIGN_FIELD = 'content_gates_test_assign';
const REMOVE_FIELD = 'content_gates_test_remove';
const RESULT_PARAM = 'content_gates_test';

// The WordPress test library also loads mu-plugins. PHPUnit must see the
// plugin's defaults (no provider, no extra segments), so stay out of it.
// tests_add_filter() exists only when the test library's includes/functions.php
// is loaded. WP_TESTS_DOMAIN is not a usable signal, because wp-env defines
// it in every site's wp-config.php.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

add_action(
	'content_gates_loaded',
	static function (): void {
		// A duplicate on a later boot is fine; ignore the WP_Error.
		register_segment( SEGMENT_KEY );
	}
);

add_filter( 'content_gates_rest_membership_permission', '__return_true' );

/*
 * Priority 20: after the plugin registers groups (init 1) and after the
 * adapter parses the incoming cookie (init 10, same as Vary_Cache).
 * Writing before the parse would let the parse clobber a removal.
 */
add_action( 'init', __NAMESPACE__ . '\\handle_post', 20 );
add_action( 'wp_footer', __NAMESPACE__ . '\\render_demo_box' );

/**
 * Handle a membership form POST and arrange the redirect.
 */
function handle_post(): void {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Test-only fixture: no nonce, so curl and Playwright can POST without fetching a token first. Never deploy.
	if ( isset( $_POST[ ASSIGN_FIELD ] ) ) {
		$result = assign_reader_to_segment( sanitize_ref( sanitize_text_field( wp_unslash( $_POST[ ASSIGN_FIELD ] ) ) ) );
	} elseif ( isset( $_POST[ REMOVE_FIELD ] ) ) {
		$result = remove_reader_from_segment( sanitize_ref( sanitize_text_field( wp_unslash( $_POST[ REMOVE_FIELD ] ) ) ) );
	} else {
		return;
	}
	// phpcs:enable

	$code     = is_wp_error( $result ) ? (string) $result->get_error_code() : 'ok';
	$redirect = static function () use ( $code ): void {
		redirect_back( $code );
	};

	// Redirect the way VIP's README does: from the "did send headers"
	// action, which fires once the cookie is written. The adapter fires
	// that action only when a cookie was really sent, so the same redirect
	// is also attached to the very end of the headers hook. Both are
	// attached now, during init, later than the plugin attached its own
	// callbacks, so at equal priority they run after the adapter's flush.
	add_action( 'vip_vary_cache_did_send_headers', $redirect );
	add_action( 'send_headers', $redirect, PHP_INT_MAX );
}

/**
 * Redirect to the current URL with the result code. Runs once.
 *
 * @param string $code `ok` or a WP_Error code.
 */
function redirect_back( string $code ): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_safe_redirect( add_query_arg( RESULT_PARAM, rawurlencode( $code ) ) );
	exit;
}

/**
 * Turn a sanitized POST field into a segment reference.
 *
 * @param string $raw Sanitized field value.
 * @return int|string Post ID when numeric, else the key.
 */
function sanitize_ref( string $raw ): int|string {
	return is_numeric( $raw ) ? (int) $raw : $raw;
}

/**
 * Print a Join/Leave form per active segment on local sites.
 */
function render_demo_box(): void {
	if ( 'local' !== wp_get_environment_type() ) {
		return;
	}

	$membership = content_gates_membership();
	if ( is_wp_error( $membership ) ) {
		return;
	}

	// membership() is raw adapter state: this box must not record reads.
	$current = $membership->membership();
	$action  = remove_query_arg( RESULT_PARAM );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of the redirect's result code only.
	$result = isset( $_GET[ RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ RESULT_PARAM ] ) ) : '';

	echo '<div style="position:fixed;right:1rem;bottom:1rem;z-index:9999;max-width:20rem;padding:.75rem 1rem;font:13px/1.4 system-ui,sans-serif;color:#111;background:#fff8dc;border:1px solid #c9a227;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.2)">';
	echo '<strong>Content Gates test provider</strong> (never deploy)';

	if ( '' !== $result ) {
		echo '<p style="margin:.5rem 0">Last action: <code>' . esc_html( $result ) . '</code></p>';
	}

	echo '<p style="margin:.5rem 0">Membership: ';
	if ( array() === $current ) {
		echo '<em>none</em>';
	} else {
		$parts = array();
		foreach ( $current as $key => $value ) {
			$parts[] = esc_html( $key . '=' . $value );
		}
		echo '<code>' . implode( ', ', $parts ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
	}
	echo '</p>';

	foreach ( \ContentGates\Plugin::instance()->segments()->active() as $segment ) {
		$ref   = $segment->id() ?? $segment->key();
		$is_in = isset( $current[ $segment->key() ] );
		$field = $is_in ? REMOVE_FIELD : ASSIGN_FIELD;
		$label = ( $is_in ? 'Leave ' : 'Join ' ) . $segment->name();

		echo '<form method="post" action="' . esc_url( $action ) . '" style="display:inline-block;margin:0 .25rem .25rem 0">';
		echo '<button type="submit" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $ref ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	echo '</div>';
}
