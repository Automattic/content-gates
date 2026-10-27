<?php
/**
 * Plugin Name: Content Gates Dev Provider
 * Description: Local-only entitlement provider and demo content seeder. NEVER deploy.
 *
 * Mapped into both wp-env sites as an mu-plugin (`mappings` in .wp-env.json
 * and .wp-env.tests.json). It stands in for a real provider so humans and
 * agents can exercise membership without one:
 *
 * 1. REST: `POST /wp-json/content-gates-dev/v1/membership` with JSON
 *    `{"segment":"subscriber","action":"assign"|"remove"}` assigns or
 *    removes membership on the REST path, where `send_headers` never fires.
 *    The form path (post-redirect-get) lives in the example provider,
 *    `examples/segment-provider/`, at `/segments/`.
 * 2. Demo box: `?content_gates_demo=1` prints the declared set and the raw
 *    membership in the footer, with a link to `/segments/`, and marks that
 *    response `no-store` because raw membership is outside the declared set.
 *    The box reads `Vary_Cache::get_groups()` directly, which spec/api.md
 *    calls a defect in provider code; it is a local diagnostic, not a
 *    provider, and nothing should copy it.
 * 3. `wp content-gates-dev seed` creates demo segments and posts.
 *
 * Safety: the file is inert unless `WP_ENVIRONMENT_TYPE` is `local`, and it
 * stays out of PHPUnit so the suite sees the plugin's defaults.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Dev;

use WP_REST_Request;
use WP_REST_Response;

use function Automattic\VIP\ContentGates\assign_reader_to_segment;
use function Automattic\VIP\ContentGates\remove_reader_from_segment;

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

// The WordPress test library also loads mu-plugins. PHPUnit must see the
// plugin's defaults, so stay out of it. tests_add_filter() exists only there.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

const DEMO_PARAM = 'content_gates_demo';

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_route' );
add_action( 'send_headers', __NAMESPACE__ . '\\demo_no_store', 1 );
add_action( 'wp_footer', __NAMESPACE__ . '\\render_demo_box' );

/**
 * Register the local-only membership route.
 */
function register_rest_route(): void {
	\register_rest_route(
		'content-gates-dev/v1',
		'/membership',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'args'                => array(
				'segment' => array(
					'type'     => 'string',
					'required' => true,
				),
				'action'  => array(
					'type'    => 'string',
					'enum'    => array( 'assign', 'remove' ),
					'default' => 'assign',
				),
			),
			'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
				$segment = (string) $request->get_param( 'segment' );
				$result  = 'remove' === $request->get_param( 'action' )
					? remove_reader_from_segment( $segment )
					: assign_reader_to_segment( $segment );

				$response = new WP_REST_Response(
					array(
						'result'  => is_wp_error( $result ) ? $result->get_error_code() : 'ok',
						'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
					),
					is_wp_error( $result ) ? 400 : 200
				);
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			},
		)
	);
}

/**
 * Whether this request shows the demo box.
 */
function is_demo_request(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag on a local-only fixture.
	return isset( $_GET[ DEMO_PARAM ] );
}

/**
 * The demo box shows raw membership, so its response must never be cached.
 */
function demo_no_store(): void {
	if ( is_demo_request() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	}
}

/**
 * Print the declared set and the raw membership, with a link to the example provider.
 */
function render_demo_box(): void {
	if ( ! is_demo_request() ) {
		return;
	}

	$plugin = \Automattic\VIP\ContentGates\Plugin::instance();
	$groups = class_exists( '\Automattic\VIP\Cache\Vary_Cache', false ) ? \Automattic\VIP\Cache\Vary_Cache::get_groups() : array();

	echo '<div id="content-gates-demo" style="position:fixed;bottom:0;right:0;max-width:22em;padding:1em;background:#222;color:#eee;font:14px/1.5 sans-serif;z-index:9999">';
	echo '<strong>Content Gates demo</strong>';
	echo '<p>Declared: <code>' . esc_html( implode( ', ', $plugin->declared()->groups() ) ) . '</code></p>';
	echo '<ul style="margin:0;padding-left:1.2em">';
	foreach ( $plugin->segments()->active() as $segment ) {
		$value = trim( (string) ( $groups[ $segment->group_name ] ?? '' ) );
		echo '<li><code>' . esc_html( $segment->key ) . '</code> ' . ( '' === $value ? '<em>not a member</em>' : '= <code>' . esc_html( $value ) . '</code>' ) . '</li>';
	}
	echo '</ul>';
	echo '<p><a href="' . esc_url( home_url( '/segments/' ) ) . '" style="color:#9cf">Change membership</a> (example provider)</p>';
	echo '</div>';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/content-gates-dev/class-cli.php';
	\WP_CLI::add_command( 'content-gates-dev', new CLI() );
}
