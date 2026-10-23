<?php
/**
 * Plugin Name: Content Gates Dev Provider
 * Description: Local-only entitlement provider, demo content seeder, and edge-simulator purge bridge. NEVER deploy.
 *
 * Mapped into both wp-env sites as an mu-plugin (`mappings` in .wp-env.json
 * and .wp-env.tests.json). It stands in for a real provider so humans and
 * agents can exercise membership without one:
 *
 * 1. POST-redirect-GET: a form POST with `content_gates_dev_assign=<key>` or
 *    `content_gates_dev_remove=<key>` to any front-end URL assigns or removes
 *    membership on `init` 20 and redirects back once the cookie is written,
 *    the flow WPVIP documents for `Vary_Cache`.
 * 2. REST: `POST /wp-json/content-gates-dev/v1/membership` with JSON
 *    `{"segment":"subscriber","action":"assign"|"remove"}` does the same on
 *    the REST path, where `send_headers` never fires.
 * 3. Demo box: `?content_gates_demo=1` prints a Join/Leave box in the footer
 *    and marks that response `no-store`, because the box shows raw membership
 *    outside the declared set.
 * 4. `wp content-gates-dev seed` creates demo segments and posts.
 * 5. Purge bridge: the plugin's `content_gates_purge_all` action and post
 *    saves are forwarded to the local edge simulator's purge endpoint, the
 *    way WPVIP purges URLs on save.
 *
 * Safety: the file is inert unless `WP_ENVIRONMENT_TYPE` is `local`, and it
 * stays out of PHPUnit so the suite sees the plugin's defaults.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Dev;

use Automattic\VIP\ContentGates\Segments\Segment;
use WP_Error;
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

const ASSIGN_FIELD = 'content_gates_dev_assign';
const REMOVE_FIELD = 'content_gates_dev_remove';
const RESULT_PARAM = 'content_gates_dev';
const DEMO_PARAM   = 'content_gates_demo';

/**
 * Base URL of the local edge simulator as seen from inside the container.
 */
function edge_sim_url(): string {
	if ( defined( 'CONTENT_GATES_DEV_EDGE_URL' ) ) {
		return (string) constant( 'CONTENT_GATES_DEV_EDGE_URL' );
	}

	return 'http://host.docker.internal:8890';
}

add_action( 'init', __NAMESPACE__ . '\\handle_form_post', 20 );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_route' );
add_action( 'send_headers', __NAMESPACE__ . '\\demo_no_store', 1 );
add_action( 'wp_footer', __NAMESPACE__ . '\\render_demo_box' );
add_action( 'content_gates_purge_all', __NAMESPACE__ . '\\purge_everything' );
add_action( 'save_post', __NAMESPACE__ . '\\purge_post_urls', 20, 2 );

/**
 * Handle a membership form POST and arrange the redirect.
 *
 * Priority 20: after `Vary_Cache` parsed the cookie at 10 and before
 * `send_headers`, inside the plugin's write window.
 */
function handle_form_post(): void {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Local-only fixture by design: no nonce, so curl and Playwright can POST without fetching a token first.
	$assign = isset( $_POST[ ASSIGN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ ASSIGN_FIELD ] ) ) : null;
	$remove = isset( $_POST[ REMOVE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ REMOVE_FIELD ] ) ) : null;
	// phpcs:enable

	if ( null !== $assign ) {
		$result = assign_reader_to_segment( $assign );
	} elseif ( null !== $remove ) {
		$result = remove_reader_from_segment( $remove );
	} else {
		return;
	}

	$code     = is_wp_error( $result ) ? (string) $result->get_error_code() : 'ok';
	$redirect = static function () use ( $code ): void {
		redirect_back( $code );
	};

	// Redirect the way VIP's README does: once the cookie is written. The
	// action fires only when something was sent, so the end of send_headers
	// is the fallback for an error result that wrote nothing.
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

	$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$url = remove_query_arg( RESULT_PARAM, $url );

	wp_safe_redirect( add_query_arg( RESULT_PARAM, $code, $url ), 303 );
	exit;
}

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
 * Print the Join/Leave box.
 */
function render_demo_box(): void {
	if ( ! is_demo_request() ) {
		return;
	}

	$plugin = \Automattic\VIP\ContentGates\Plugin::instance();
	$groups = class_exists( '\Automattic\VIP\Cache\Vary_Cache', false ) ? \Automattic\VIP\Cache\Vary_Cache::get_groups() : array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a redirect result.
	$result = isset( $_GET[ RESULT_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ RESULT_PARAM ] ) ) : '';

	echo '<div id="content-gates-demo" style="position:fixed;bottom:0;right:0;max-width:22em;padding:1em;background:#222;color:#eee;font:14px/1.5 sans-serif;z-index:9999">';
	echo '<strong>Content Gates demo provider</strong>';
	if ( '' !== $result ) {
		echo '<p data-result="' . esc_attr( $result ) . '">Last action: ' . esc_html( $result ) . '</p>';
	}
	echo '<p>Declared: <code>' . esc_html( implode( ', ', $plugin->declared()->groups() ) ) . '</code></p>';
	foreach ( $plugin->segments()->active() as $segment ) {
		$value = $groups[ $segment->group_name ] ?? '';
		$in    = '' !== trim( (string) $value );
		echo '<form method="post" style="margin:0 0 .25em">';
		echo '<span>' . esc_html( $segment->name ) . ' <code>' . esc_html( $segment->key ) . '</code>' . ( $in ? ' = <code>' . esc_html( (string) $value ) . '</code>' : '' ) . '</span> ';
		if ( $segment->is_meter() ) {
			echo '<em>(edge-written)</em>';
		} elseif ( $in ) {
			echo '<button name="' . esc_attr( REMOVE_FIELD ) . '" value="' . esc_attr( $segment->key ) . '">Leave</button>';
		} else {
			echo '<button name="' . esc_attr( ASSIGN_FIELD ) . '" value="' . esc_attr( $segment->key ) . '">Join</button>';
		}
		echo '</form>';
	}
	echo '</div>';
}

/**
 * Forward a global purge to the edge simulator.
 *
 * @param string $reason Why the purge was requested.
 */
function purge_everything( string $reason ): void {
	call_edge_sim( '/__edge/purge', array( 'reason' => $reason ) );
}

/**
 * Forward the URL purge WPVIP performs on save: the post, the home page, and the feed.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post.
 */
function purge_post_urls( int $post_id, \WP_Post $post ): void {
	if ( 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}

	foreach ( array( get_permalink( $post_id ), home_url( '/' ), get_feed_link() ) as $url ) {
		if ( is_string( $url ) ) {
			call_edge_sim( '/__edge/purge', array( 'url' => wp_make_link_relative( $url ) ) );
		}
	}
}

/**
 * Fire-and-forget POST to the simulator. Errors are ignored: the simulator may not be running.
 *
 * @param string               $path Endpoint path.
 * @param array<string, mixed> $body JSON body.
 */
function call_edge_sim( string $path, array $body ): void {
	wp_remote_post(
		edge_sim_url() . $path,
		array(
			'timeout'  => 1,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $body ),
		)
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/content-gates-dev/class-cli.php';
	\WP_CLI::add_command( 'content-gates-dev', new CLI() );
}
