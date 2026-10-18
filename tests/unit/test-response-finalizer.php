<?php
/**
 * Response finalizer tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Segments;
use ContentGates\Rendering\Response_Finalizer;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Verifies finalization for buffered and REST output.
 */
final class Test_Response_Finalizer extends WP_UnitTestCase {

	/**
	 * REST output carries the exact frozen dependency signal.
	 */
	public function test_rest_response_carries_consulted_set(): void {
		$consulted = new Consulted_Segments();
		$consulted->record( 'cg_1_20' );
		$consulted->record( 'cg_1_10' );
		$finalizer = new Response_Finalizer( $consulted );
		$response  = new WP_REST_Response( array( 'ok' => true ) );

		$result = $finalizer->finalize_rest_response(
			$response,
			rest_get_server(),
			new WP_REST_Request( 'GET', '/wp/v2/posts' )
		);

		$this->assertSame( 'cg_1_10, cg_1_20', $result->get_headers()[ Response_Finalizer::HEADER_NAME ] );
	}

	/**
	 * REST output with no membership reads carries no variance signal.
	 */
	public function test_rest_response_omits_empty_signal(): void {
		$finalizer = new Response_Finalizer( new Consulted_Segments() );
		$response  = new WP_REST_Response( array( 'ok' => true ) );

		$result = $finalizer->finalize_rest_response(
			$response,
			rest_get_server(),
			new WP_REST_Request( 'GET', '/wp/v2/posts' )
		);

		$this->assertArrayNotHasKey( Response_Finalizer::HEADER_NAME, $result->get_headers() );
	}

	/**
	 * Buffered output is returned byte-for-byte and finalizes once.
	 */
	public function test_buffer_preserves_body_and_finalizes_once(): void {
		$consulted = new Consulted_Segments();
		$consulted->record( 'cg_1_10' );
		$finalizer = new Response_Finalizer( $consulted );
		$calls     = 0;

		add_action(
			'content_gate_finalize_variance',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->assertSame( '<main>body</main>', $finalizer->finalize_buffer( '<main>body</main>' ) );
		$this->assertSame( '<feed>body</feed>', $finalizer->finalize_buffer( '<feed>body</feed>' ) );
		$this->assertSame( 1, $calls );
	}
}
