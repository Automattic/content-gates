<?php
/**
 * REST surface tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Membership\Declared_Set;
use WP_REST_Request;

use function Automattic\VIP\ContentGates\assign_reader_to_segment;

/**
 * REST responses withhold gated content, declare what they read, and send cookies.
 */
final class Test_REST extends Content_Gates_Test_Case {

	/**
	 * Serve a REST request the way WordPress does and capture the JSON.
	 *
	 * @param string $route Route.
	 * @param string $method HTTP method.
	 * @return array<string, mixed>
	 */
	private function serve( string $route, string $method = 'GET' ): array {
		$_SERVER['REQUEST_METHOD']               = $method;
		$GLOBALS['wp']->query_vars['rest_route'] = $route; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Mirrors core's REST dispatch.

		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		$server = rest_get_server();
		ob_start();
		$server->serve_request( $route );
		$json = (string) ob_get_clean();
		// The WordPress test library serves through Spy_REST_Server, which buffers the body itself.
		if ( '' === $json && property_exists( $server, 'sent_body' ) ) {
			$json = (string) $server->sent_body;
		}
		remove_filter( 'wp_is_rest_endpoint', '__return_true' );

		$data = json_decode( $json, true );
		$this->assertIsArray( $data, 'REST output must be JSON: ' . $json );

		return $data;
	}

	/**
	 * Anonymous REST readers get no gated bytes, and the response declares the post's set.
	 */
	public function test_anonymous_post_response_withholds_and_declares(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->become_reader( array() );

		$data = $this->serve( "/wp/v2/posts/{$post_id}" );

		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $data ) );
		$this->assertStringContainsString( 'PUBLIC-INTRO', $data['content']['rendered'] );
		$this->assertStringNotContainsString( self::SECRET, $data['excerpt']['rendered'] );
		$this->assertSame( 'subscriber', Headers::last( Declared_Set::HEADER_DECLARED ) );
		$this->assertTrue( $this->plugin()->declared()->is_closed() );
	}

	/**
	 * A member's REST response includes the Group.
	 */
	public function test_member_post_response_includes_group(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->become_reader( array( 'subscriber' => '1' ) );

		$data = $this->serve( "/wp/v2/posts/{$post_id}" );

		$this->assertStringContainsString( self::SECRET, $data['content']['rendered'] );
	}

	/**
	 * A collection declares the union of the posts it rendered.
	 */
	public function test_collection_declares_union(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->create_gated_post( array( 'subscriber' ) );
		$this->create_gated_post( array( 'donor' ) );
		$this->become_reader( array() );

		$data = $this->serve( '/wp/v2/posts' );

		$this->assertCount( 2, $data );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $data ) );
		$this->assertSame( 'donor, subscriber', Headers::last( Declared_Set::HEADER_DECLARED ) );
	}

	/**
	 * A response that renders no gated post declares nothing.
	 */
	public function test_ungated_response_declares_empty(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_post( array( 'post_content' => $this->paragraph( 'free' ) ) );

		$this->serve( '/wp/v2/posts' );

		$this->assertSame( '', Headers::last( Declared_Set::HEADER_DECLARED ) );
	}

	/**
	 * A provider route that assigns during a REST request gets its cookie written.
	 */
	public function test_rest_assignment_sends_cookie_headers(): void {
		$this->create_segment( 'Subscriber' );
		// Register on the live server directly: rest_api_init already ran earlier in the process.
		rest_get_server()->register_route(
			'content-gates-test/v1',
			'/content-gates-test/v1/join',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function (): array {
						return array( 'result' => assign_reader_to_segment( 'subscriber' ) );
					},
				),
			)
		);
		$sent = $this->capture_did_send_headers();

		$data = $this->serve( '/content-gates-test/v1/join', 'POST' );

		$this->assertTrue( $data['result'] );
		$this->assertSame( array( true, true ), $sent(), 'Vary and the cookie were sent before the JSON.' );
	}

	/**
	 * When another handler serves the response itself, the set is closed and the header sent there.
	 */
	public function test_served_elsewhere_still_closes_and_sends_header(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$this->become_reader( array() );
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served ): bool {
				unset( $served );
				echo (string) wp_json_encode( array( 'served' => 'elsewhere' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body of a test handler.
				return true;
			},
			5
		);

		$data = $this->serve( "/wp/v2/posts/{$post_id}" );

		$this->assertSame( 'elsewhere', $data['served'] );
		$this->assertTrue( $this->plugin()->declared()->is_closed() );
		$this->assertSame( 'subscriber', Headers::last( Declared_Set::HEADER_DECLARED ) );
	}

	/**
	 * The segments route needs edit_posts.
	 */
	public function test_segments_route_requires_authentication(): void {
		$this->create_segment( 'Subscriber' );
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/content-gates/v1/segments' ) );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( $this->create_user( 'contributor' ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/content-gates/v1/segments' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'subscriber', $response->get_data()[0]['key'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * Segment posts are not exposed through REST.
	 */
	public function test_segment_post_type_is_not_in_rest(): void {
		$segment = $this->create_segment( 'Subscriber' );
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/types/cg_segment' ) );
		$this->assertContains( $response->get_status(), array( 404, 403 ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $segment->id ), 'The record exists; only REST exposure is tested here.' );
	}
}
