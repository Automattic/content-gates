<?php
/**
 * Response_Finalizer tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Set;
use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Plugin;
use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Rendering\Headers;
use ContentGates\Rendering\Response_Finalizer;
use ContentGates\Segments\Segment;
use WP_REST_Response;
use WP_UnitTestCase;

require_once __DIR__ . '/class-array-segment-source.php';

/**
 * The finalizer reports exactly the consulted set, after render.
 */
final class Test_Response_Finalizer extends WP_UnitTestCase {

	/**
	 * Membership under test.
	 *
	 * @var Membership
	 */
	private Membership $membership;

	/**
	 * Finalizer under test.
	 *
	 * @var Response_Finalizer
	 */
	private Response_Finalizer $finalizer;

	/**
	 * Build a fresh membership layer and finalizer.
	 *
	 * PHPUnit already wrote to stdout, so `headers_sent()` is true for the
	 * whole process. The simulation makes `Headers::send()` behave as on a
	 * web request; tests that need "output already started" opt in.
	 */
	public function set_up(): void {
		parent::set_up();
		Headers::reset();
		Headers::simulate_output_started( false );

		$source = new Array_Segment_Source(
			array(
				new Segment( 11, 'subscriber', 'Subscribers', true ),
				new Segment( null, 'newsletter', 'Newsletter', true ),
			)
		);

		$this->membership = new Membership( new Local_Cookie_Adapter(), $source, new Consulted_Set() );
		$this->membership->register_groups();
		$this->finalizer = new Response_Finalizer( $this->membership );
	}

	/**
	 * Clear recorded headers and leave the booted plugin as found.
	 */
	public function tear_down(): void {
		Headers::reset();
		Plugin::instance()->membership()->consulted_set()->reset();
		Plugin::instance()->segments()->invalidate_cache();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting WordPress's own REST server global, as core's REST tests do.
		parent::tear_down();
	}

	/**
	 * Capture E_USER_WARNING messages raised by a callback.
	 *
	 * @param callable $callback Code under test.
	 * @return string[] Warning messages, in order.
	 */
	private function capture_warnings( callable $callback ): array {
		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the warning under test.
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				unset( $errno );
				$warnings[] = $errstr;
				return true;
			},
			E_USER_WARNING
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
		}

		return $warnings;
	}

	/**
	 * Skip when WP_DEBUG is off: `wp_trigger_error()` is silent then.
	 */
	private function require_debug(): void {
		if ( ! defined( 'WP_DEBUG' ) || true !== constant( 'WP_DEBUG' ) ) {
			$this->markTestSkipped( 'WP_DEBUG is off; the warning under test is debug-only.' );
		}
	}

	/**
	 * An empty consulted set sends neither header, on the page or REST path.
	 */
	public function test_empty_set_sends_no_headers(): void {
		$response = new WP_REST_Response( array( 'ok' => true ) );
		$this->finalizer->finalize_rest( $response );
		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, $response->get_headers() );
		$this->assertArrayNotHasKey( Response_Finalizer::KEY_HEADER, $response->get_headers() );

		$this->finalizer->finalize();

		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, Headers::sent() );
		$this->assertArrayNotHasKey( Response_Finalizer::KEY_HEADER, Headers::sent() );
		$this->assertTrue( $this->finalizer->is_finalized() );
		$this->assertTrue( $this->membership->consulted_set()->is_frozen() );
	}

	/**
	 * Consulted keys are reported sorted, with the debug key header.
	 */
	public function test_consulted_set_is_reported_sorted(): void {
		$this->membership->assign( 'subscriber' );
		$this->membership->is_in( 'subscriber' );
		$this->membership->is_in( 'newsletter' );

		$this->finalizer->finalize();

		$sent = Headers::sent();
		$this->assertSame( 'newsletter, subscriber', $sent[ Response_Finalizer::VARY_HEADER ] ?? null );

		if ( defined( 'WP_DEBUG' ) && true === constant( 'WP_DEBUG' ) ) {
			$this->assertSame( 'newsletter=none, subscriber=1', $sent[ Response_Finalizer::KEY_HEADER ] ?? null );
		} else {
			$this->assertArrayNotHasKey( Response_Finalizer::KEY_HEADER, $sent );
		}
	}

	/**
	 * The filter can change the reported set; the action sees the result.
	 */
	public function test_filter_and_action(): void {
		$this->membership->is_in( 'subscriber' );

		add_filter(
			'content_gates_consulted_segments',
			static function ( array $consulted ): array {
				$consulted[] = 'newsletter';
				return $consulted;
			}
		);

		$received = array();
		add_action(
			'content_gates_finalize_variance',
			static function ( array $consulted, array $variant_key, bool $sent ) use ( &$received ): void {
				$received[] = array( $consulted, $variant_key, $sent );
			},
			10,
			3
		);

		$this->finalizer->finalize();
		$this->finalizer->finalize();

		$this->assertCount( 1, $received, 'finalize() is idempotent.' );
		$this->assertSame( array( 'newsletter', 'subscriber' ), $received[0][0] );
		$this->assertSame(
			array(
				'newsletter' => 'none',
				'subscriber' => 'none',
			),
			$received[0][1]
		);
		$this->assertTrue( $received[0][2], 'The header was sent.' );
		$this->assertSame( 'newsletter, subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null );
	}

	/**
	 * Keys from the filter that cannot travel in a header are dropped with
	 * a warning; valid ones are kept.
	 */
	public function test_filter_output_is_validated(): void {
		$this->require_debug();
		$this->membership->is_in( 'subscriber' );

		add_filter(
			'content_gates_consulted_segments',
			static function ( array $consulted ): array {
				return array_merge( $consulted, array( 'newsletter', 'bad key', "evil\r\nX-Injected: 1", '' ) );
			}
		);

		$received = null;
		add_action(
			'content_gates_finalize_variance',
			static function ( array $consulted ) use ( &$received ): void {
				$received = $consulted;
			}
		);

		$warnings = $this->capture_warnings( fn() => $this->finalizer->finalize() );

		$this->assertSame( 'newsletter, subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null );
		$this->assertSame( array( 'newsletter', 'subscriber' ), $received, 'The action sees the validated set.' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'content_gates_consulted_segments', $warnings[0] );
		$this->assertStringContainsString( '"bad key"', $warnings[0] );
		$this->assertStringNotContainsString( "\r\n", $warnings[0], 'Control characters are escaped in the warning.' );
	}

	/**
	 * When output already started the header cannot go out: warn, and tell
	 * the action.
	 */
	public function test_warns_when_output_already_started(): void {
		$this->require_debug();
		$this->membership->is_in( 'subscriber' );
		Headers::simulate_output_started( true );

		$received = null;
		add_action(
			'content_gates_finalize_variance',
			static function ( array $consulted, array $variant_key, bool $sent ) use ( &$received ): void {
				unset( $consulted, $variant_key );
				$received = $sent;
			},
			10,
			3
		);

		$warnings = $this->capture_warnings( fn() => $this->finalizer->finalize() );

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'could not send X-Content-Gates-Vary', $warnings[0] );
		$this->assertStringContainsString( '(consulted: subscriber)', $warnings[0] );
		$this->assertFalse( $received, 'The action receives $sent === false.' );
		$this->assertSame( 'subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null, 'The attempt is still recorded.' );
	}

	/**
	 * Nothing consulted and output started: nothing to send, so no warning.
	 */
	public function test_no_warning_when_nothing_consulted(): void {
		Headers::simulate_output_started( true );

		$warnings = $this->capture_warnings( fn() => $this->finalizer->finalize() );

		$this->assertSame( array(), $warnings );
	}

	/**
	 * Reads after finalize do not change what was reported.
	 */
	public function test_reads_after_finalize_are_not_reported(): void {
		$this->membership->is_in( 'subscriber' );
		$this->finalizer->finalize();
		$this->membership->is_in( 'newsletter' );

		$this->assertSame( 'subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null );
		$this->assertSame( array( 'subscriber' ), $this->membership->consulted() );
	}

	/**
	 * The buffered body is released unchanged.
	 */
	public function test_buffered_body_is_echoed_unchanged(): void {
		$this->membership->is_in( 'subscriber' );

		ob_start();
		$outer = ob_get_level();
		$this->finalizer->begin();
		$this->assertSame( $outer + 1, ob_get_level() );

		echo '<p>gated <b>body</b></p>';

		$this->finalizer->finalize();
		$this->assertSame( $outer, ob_get_level(), 'Only our buffer is closed.' );
		$body = ob_get_clean();

		$this->assertSame( '<p>gated <b>body</b></p>', $body );
		$this->assertSame( 'subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null );
	}

	/**
	 * Calling finalize() without begin() works (REST, early exit).
	 */
	public function test_finalize_without_buffer(): void {
		$level = ob_get_level();
		$this->finalizer->finalize();

		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * REST responses get the headers on the response object.
	 */
	public function test_rest_response_gets_headers(): void {
		$this->membership->assign( 'newsletter' );
		$this->membership->is_in( 'newsletter' );
		$this->membership->is_in( 'subscriber' );

		$response = new WP_REST_Response( array( 'ok' => true ) );
		$result   = $this->finalizer->finalize_rest( $response );

		$this->assertSame( $response, $result );
		$headers = $response->get_headers();
		$this->assertSame( 'newsletter, subscriber', $headers[ Response_Finalizer::VARY_HEADER ] ?? null );
		if ( defined( 'WP_DEBUG' ) && true === constant( 'WP_DEBUG' ) ) {
			$this->assertSame( 'newsletter=1, subscriber=none', $headers[ Response_Finalizer::KEY_HEADER ] ?? null );
		}
		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, Headers::sent(), 'REST headers go on the response, not header().' );
		$this->assertFalse( $this->finalizer->is_finalized(), 'An internal REST call does not finalize the page.' );
	}

	/**
	 * An internal REST dispatch neither freezes the set nor fires the hooks:
	 * the page that made the call owns the signal.
	 */
	public function test_internal_rest_dispatch_does_not_freeze_or_announce(): void {
		$this->membership->is_in( 'subscriber' );

		$filtered = 0;
		add_filter(
			'content_gates_consulted_segments',
			static function ( array $consulted ) use ( &$filtered ): array {
				++$filtered;
				return $consulted;
			}
		);
		$fired = 0;
		add_action(
			'content_gates_finalize_variance',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->finalizer->finalize_rest( new WP_REST_Response() );
		$this->membership->is_in( 'newsletter' );

		$this->assertSame( 0, $filtered );
		$this->assertSame( 0, $fired );
		$this->assertFalse( $this->membership->consulted_set()->is_frozen() );
		$this->assertSame( array( 'newsletter', 'subscriber' ), $this->membership->consulted(), 'Later reads still count.' );

		$this->assertSame( array(), $this->finalizer->finalize_rest_echo( array() ), 'Not serving REST: the echo hook is a no-op.' );
		$this->assertFalse( $this->finalizer->is_finalized() );
	}

	/**
	 * When WordPress is serving the REST request, the echo hook finalizes
	 * with everything read since dispatch, and shutdown is then a no-op.
	 */
	public function test_rest_echo_finalizes_when_serving_rest(): void {
		$finalizer = new Response_Finalizer( $this->membership, static fn(): bool => true );

		$this->membership->is_in( 'subscriber' );

		$fired = 0;
		add_action(
			'content_gates_finalize_variance',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$response = new WP_REST_Response();
		$finalizer->finalize_rest( $response );
		$this->assertSame( 'subscriber', $response->get_headers()[ Response_Finalizer::VARY_HEADER ] ?? null );
		$this->assertFalse( $finalizer->is_finalized(), 'Dispatch is too early: embeds render later.' );
		$this->assertSame( 0, $fired );

		$this->membership->is_in( 'newsletter' );

		$data = array( 'id' => 1 );
		$this->assertSame( $data, $finalizer->finalize_rest_echo( $data ) );
		$finalizer->finalize_rest_echo( $data );
		$finalizer->finalize();

		$this->assertTrue( $finalizer->is_finalized() );
		$this->assertSame( 1, $fired, 'The echo hook fires the action once; shutdown finalize() is a no-op.' );
		$this->assertTrue( $this->membership->consulted_set()->is_frozen() );
		$this->assertSame( 'newsletter, subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null, 'The late read is reported.' );
	}

	/**
	 * Reads made while core renders `_embed` sub-requests reach the signal.
	 *
	 * Core order in `WP_REST_Server::serve_request()`: `rest_post_dispatch`,
	 * send the response object's headers, `response_to_data()` (which
	 * dispatches every embeddable link and renders its content), then
	 * `rest_pre_echo_response`. A signal frozen at dispatch misses the
	 * embedded Group.
	 */
	public function test_rest_embed_reads_are_reported(): void {
		$plugin     = Plugin::instance();
		$membership = $plugin->membership();
		$membership->consulted_set()->reset();
		$plugin->segments()->invalidate_cache();

		$id = $plugin->segments()->create( 'Embed Segment' );
		$this->assertIsInt( $id );
		$segment = $plugin->segments()->find( $id );
		$this->assertNotNull( $segment );
		$key = $segment->key();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Public lead.</p><!-- /wp:paragraph -->'
					. '<!-- wp:group ' . wp_json_encode( array( Block_Enforcer::ATTRIBUTE => $id ) ) . ' --><div class="wp-block-group">'
					. '<!-- wp:paragraph --><p>EMBEDDED_GATED_TEXT</p><!-- /wp:paragraph -->'
					. '</div><!-- /wp:group -->',
			)
		);
		$this->assertIsInt( $post_id );

		$finalizer = new Response_Finalizer( $membership, static fn(): bool => true );

		$response = new WP_REST_Response( array( 'ok' => true ) );
		// context=view: core would default embeds to context=embed, which omits `content`.
		$response->add_link( 'self', add_query_arg( 'context', 'view', rest_url( 'wp/v2/posts/' . $post_id ) ), array( 'embeddable' => true ) );

		$finalizer->finalize_rest( $response );
		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, $response->get_headers(), 'Nothing consulted at dispatch.' );
		$this->assertSame( array(), $membership->consulted() );

		$data = rest_get_server()->response_to_data( $response, array( 'self' ) );

		$this->assertIsArray( $data );
		$rendered = $data['_embedded']['self'][0]['content']['rendered'] ?? null;
		$this->assertIsString( $rendered, 'The embedded post rendered its content. Got: ' . (string) wp_json_encode( $data ) );
		$this->assertStringContainsString( 'Public lead.', $rendered );
		$this->assertStringNotContainsString( 'EMBEDDED_GATED_TEXT', $rendered, 'The anonymous reader does not get the Group.' );
		$this->assertContains( $key, $membership->consulted(), 'The embedded render recorded its read.' );

		$finalizer->finalize_rest_echo( $data );

		$this->assertTrue( $finalizer->is_finalized() );
		$this->assertStringContainsString( $key, Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? '', 'The sent signal includes the embedded read.' );
	}

	/**
	 * Non-response values pass through untouched.
	 */
	public function test_rest_ignores_non_responses(): void {
		$error = new \WP_Error( 'x', 'y' );

		$this->assertSame( $error, $this->finalizer->finalize_rest( $error ) );
		$this->assertNull( $this->finalizer->finalize_rest( null ) );
	}

	/**
	 * Hooks are registered at the required priorities.
	 */
	public function test_register_hooks(): void {
		$this->finalizer->register();

		$this->assertSame( PHP_INT_MIN, has_action( 'template_redirect', array( $this->finalizer, 'begin' ) ) );
		$this->assertSame( 0, has_action( 'shutdown', array( $this->finalizer, 'finalize' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_post_dispatch', array( $this->finalizer, 'finalize_rest' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( $this->finalizer, 'finalize_rest_echo' ) ) );
	}
}
