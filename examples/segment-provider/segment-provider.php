<?php
/**
 * Plugin Name: Content Gates Example Provider
 * Description: A toy membership provider for Content Gates. Any reader can put themselves into, or take themselves out of, any reader segment on one page. No authentication. Local demonstrations only.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Text Domain: content-gates
 *
 * What a provider is: Content Gates decides what a reader may see from the
 * segments the reader is in. It never decides who is in a segment. That is
 * the provider's job: a subscription system, a CRM, a login, or, here, a
 * form the reader fills in. This plugin is the smallest complete provider:
 *
 * 1. On `init` priority 20, inside the plugin's write window (after
 *    `Vary_Cache` parsed the cookie at 10, before `send_headers`), it reads
 *    the submitted form and calls `assign_reader_to_segment()` or
 *    `remove_reader_from_segment()` for every reader segment.
 * 2. It redirects once the cookie is written, on
 *    `vip_vary_cache_did_send_headers`, so the reader lands on a GET that
 *    already carries the membership. This is the post-redirect-get pattern
 *    the spec documents for PHP providers.
 * 3. The page itself is `Cache-Control: no-store`: it shows the reader's
 *    own membership, so it must never be stored.
 * 4. It declares every segment it reads on `wp`, because reads of
 *    undeclared segments return `false` by design.
 *
 * Meter segments are listed but cannot be chosen: only the Edge Worker
 * writes meter values. Crawler segments are not listed: only the Edge
 * Worker classifies crawlers, and it strips crawler values a browser sends.
 *
 * Not for production. Anyone can grant themselves any membership. The
 * plugin lives under examples/ and is loaded only by the wp-env configs; it
 * is not part of the content-gates plugin that a site deploys.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Examples\Segment_Provider;

use Automattic\VIP\ContentGates\Plugin;
use Automattic\VIP\ContentGates\Segments\Segment;

use function Automattic\VIP\ContentGates\assign_reader_to_segment;
use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\is_reader_in_segment;
use function Automattic\VIP\ContentGates\remove_reader_from_segment;

/** Public query var that selects the provider page. */
const QUERY_VAR = 'content_gates_provider';

/** Path of the page; `/?content_gates_provider=1` also works. */
const PATH = 'segments';

/** Form field: the keys the reader wants to be in. */
const FIELD_SEGMENTS = 'content_gates_segments';

/** Form field: present on a submission. */
const FIELD_ACTION = 'content_gates_provider_action';

/** Nonce action. The nonce is CSRF protection, not authentication. */
const NONCE_ACTION = 'content_gates_provider_save';

/** Query arg carrying the result code after the redirect. */
const RESULT_PARAM = 'content_gates_result';

if ( ! function_exists( 'Automattic\\VIP\\ContentGates\\assign_reader_to_segment' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Content Gates Example Provider needs the Content Gates plugin.', 'content-gates' ) . '</p></div>';
		}
	);
	return;
}

add_action( 'init', __NAMESPACE__ . '\\register_page', 5 );
add_action( 'init', __NAMESPACE__ . '\\handle_submission', 20 );
add_action( 'wp', __NAMESPACE__ . '\\declare_listed_segments', 10 );
add_action( 'template_redirect', __NAMESPACE__ . '\\render_page' );

/**
 * Register the page's query var and answer on its path.
 *
 * No rewrite rule: a rule needs a flush, which WPVIP's standards forbid at
 * runtime. The `request` filter sees every parsed request, so matching the
 * path there makes `/segments/` work from the first request in a fresh
 * container. The query-var form, `/?content_gates_provider=1`, works too.
 */
function register_page(): void {
	add_filter(
		'query_vars',
		static function ( array $vars ): array {
			$vars[] = QUERY_VAR;
			return $vars;
		}
	);
	add_filter(
		'request',
		static function ( array $vars ): array {
			if ( is_page_request() ) {
				return array( QUERY_VAR => '1' );
			}
			return $vars;
		}
	);
}

/**
 * The segments a reader can choose on the page: active, not a crawler.
 *
 * Meter segments are included so the page can show them, but `is_choosable()`
 * says no for them.
 *
 * @return Segment[]
 */
function listed_segments(): array {
	$listed = array();
	foreach ( Plugin::instance()->segments()->active() as $segment ) {
		if ( ! $segment->crawler ) {
			$listed[] = $segment;
		}
	}
	return $listed;
}

/**
 * Whether the reader may put themselves into a segment.
 *
 * @param Segment $segment Segment.
 */
function is_choosable( Segment $segment ): bool {
	return ! $segment->is_meter();
}

/**
 * Whether this request is for the provider page.
 *
 * At `init` the main query is not parsed yet, so the check reads the
 * request target directly.
 */
function is_page_request(): bool {
	if ( '' !== (string) get_query_var( QUERY_VAR, '' ) ) {
		return true;
	}
	$target = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path   = (string) wp_parse_url( $target, PHP_URL_PATH );
	$query  = (string) wp_parse_url( $target, PHP_URL_QUERY );
	if ( '' !== $query && str_contains( '&' . $query, '&' . QUERY_VAR . '=' ) ) {
		return true;
	}
	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	return rtrim( $path, '/' ) === rtrim( $home_path, '/' ) . '/' . PATH;
}

/**
 * Apply a submitted form: assign the checked segments, remove the rest.
 *
 * Priority 20 on `init`: inside the plugin's write window.
 */
function handle_submission(): void {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! is_page_request() ) {
		return;
	}
	if ( ! isset( $_POST[ FIELD_ACTION ] ) ) {
		return;
	}
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( false === wp_verify_nonce( $nonce, NONCE_ACTION ) ) {
		schedule_redirect( 'bad_nonce' );
		return;
	}

	$wanted = array();
	if ( isset( $_POST[ FIELD_SEGMENTS ] ) && is_array( $_POST[ FIELD_SEGMENTS ] ) ) {
		$wanted = array_map( 'sanitize_key', (array) wp_unslash( $_POST[ FIELD_SEGMENTS ] ) );
	}

	$code = 'ok';
	foreach ( listed_segments() as $segment ) {
		if ( ! is_choosable( $segment ) ) {
			continue;
		}
		$result = in_array( $segment->key, $wanted, true )
			? assign_reader_to_segment( $segment->key )
			: remove_reader_from_segment( $segment->key );
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
		}
	}

	schedule_redirect( $code );
}

/**
 * Redirect to the page once the cookie is written.
 *
 * `vip_vary_cache_did_send_headers` fires only when `Vary_Cache` sent
 * something, so the end of `send_headers` is the fallback for a submission
 * that changed nothing. `redirect_once()` makes the second call a no-op.
 *
 * @param string $code `ok` or an error code.
 */
function schedule_redirect( string $code ): void {
	$redirect = static function () use ( $code ): void {
		redirect_once( $code );
	};
	add_action( 'vip_vary_cache_did_send_headers', $redirect );
	add_action( 'send_headers', $redirect, PHP_INT_MAX );
}

/**
 * Send the redirect. Runs once per request.
 *
 * @param string $code Result code for the query string.
 */
function redirect_once( string $code ): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_safe_redirect( add_query_arg( RESULT_PARAM, $code, page_url() ), 303 );
	exit;
}

/**
 * The page's URL. The query-var form works without a rewrite flush.
 */
function page_url(): string {
	return home_url( '/' . PATH . '/' );
}

/**
 * Declare every listed segment so the page can read membership.
 *
 * `wp` priority 10, before the plugin finalizes the declared set at the end
 * of `wp`. The page is `no-store`, so the declaration costs no variants.
 */
function declare_listed_segments(): void {
	if ( '' === (string) get_query_var( QUERY_VAR, '' ) ) {
		return;
	}
	foreach ( listed_segments() as $segment ) {
		declare_segment( $segment->key );
	}
}

/**
 * Print the page and stop. `template_redirect` callback.
 */
function render_page(): void {
	if ( '' === (string) get_query_var( QUERY_VAR, '' ) ) {
		return;
	}
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the code is a word from this plugin's own redirect.
	$result = isset( $_GET[ RESULT_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ RESULT_PARAM ] ) ) : '';

	$segments = listed_segments();
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php esc_html_e( 'Your segments', 'content-gates' ); ?></title>
	<style>
		body { font: 16px/1.5 system-ui, sans-serif; margin: 2rem auto; max-width: 36rem; padding: 0 1rem; color: #1d2327; }
		fieldset { border: 1px solid #c3c4c7; border-radius: 4px; padding: 1rem; }
		label { display: block; padding: .25rem 0; }
		.note { color: #646970; font-size: .9em; margin-left: 1.6em; }
		.result { border-left: 4px solid #00a32a; background: #f0f6fc; padding: .5rem 1rem; }
		.result.error { border-color: #d63638; }
		button { font: inherit; padding: .5rem 1rem; margin-top: 1rem; }
	</style>
</head>
<body>
	<h1><?php esc_html_e( 'Your segments', 'content-gates' ); ?></h1>
	<p><?php esc_html_e( 'This example provider lets any reader choose their own segments. Tick a segment to join it; untick it to leave. The choice is stored in the segmentation cookie that Content Gates and the page cache read.', 'content-gates' ); ?></p>
	<?php if ( '' !== $result ) : ?>
		<p class="result <?php echo 'ok' === $result ? '' : 'error'; ?>">
			<?php
			if ( 'ok' === $result ) {
				esc_html_e( 'Saved. Your membership is now in the cookie this browser sends.', 'content-gates' );
			} else {
				/* translators: %s: error code */
				printf( esc_html__( 'Not saved: %s', 'content-gates' ), '<code>' . esc_html( $result ) . '</code>' );
			}
			?>
		</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( page_url() ); ?>">
		<?php wp_nonce_field( NONCE_ACTION ); ?>
		<input type="hidden" name="<?php echo esc_attr( FIELD_ACTION ); ?>" value="save">
		<fieldset>
			<legend><?php esc_html_e( 'Segments', 'content-gates' ); ?></legend>
			<?php if ( array() === $segments ) : ?>
				<p><?php esc_html_e( 'No active segments. Create one under Settings → Segments.', 'content-gates' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $segments as $segment ) : ?>
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( FIELD_SEGMENTS ); ?>[]"
						value="<?php echo esc_attr( $segment->key ); ?>"
						<?php checked( is_reader_in_segment( $segment->key ) ); ?>
						<?php disabled( ! is_choosable( $segment ) ); ?>
					>
					<?php echo esc_html( $segment->name ); ?>
					<code><?php echo esc_html( $segment->key ); ?></code>
				</label>
				<?php if ( $segment->is_meter() ) : ?>
					<p class="note"><?php esc_html_e( 'Meter segment: only the Edge Worker writes its value. Shown as the cookie reports it.', 'content-gates' ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
		</fieldset>
		<button type="submit"><?php esc_html_e( 'Save my segments', 'content-gates' ); ?></button>
	</form>
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to the site', 'content-gates' ); ?></a></p>
</body>
</html>
	<?php
	exit;
}
