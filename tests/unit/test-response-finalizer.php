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
	 */
	public function set_up(): void {
		parent::set_up();
		Headers::reset();

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
	 * Clear recorded headers.
	 */
	public function tear_down(): void {
		Headers::reset();
		parent::tear_down();
	}

	/**
	 * An empty consulted set sends no Vary header.
	 */
	public function test_empty_set_sends_no_vary_header(): void {
		$this->finalizer->finalize();

		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, Headers::sent() );
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
			static function ( array $consulted, array $variant_key ) use ( &$received ): void {
				$received[] = array( $consulted, $variant_key );
			},
			10,
			2
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
		$this->assertSame( 'newsletter, subscriber', Headers::sent()[ Response_Finalizer::VARY_HEADER ] ?? null );
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
	 * When WordPress is serving the REST request, the REST path finalizes.
	 */
	public function test_rest_response_finalizes_when_serving_rest(): void {
		$finalizer = new Response_Finalizer( $this->membership, static fn(): bool => true );

		$this->membership->is_in( 'subscriber' );

		$fired = 0;
		add_action(
			'content_gates_finalize_variance',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$finalizer->finalize_rest( new WP_REST_Response() );
		$finalizer->finalize();

		$this->assertTrue( $finalizer->is_finalized() );
		$this->assertSame( 1, $fired, 'shutdown finalize() is a no-op after the REST path.' );
		$this->assertTrue( $this->membership->consulted_set()->is_frozen() );
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
	}
}
