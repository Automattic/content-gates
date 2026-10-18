<?php
/**
 * Membership REST controller tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Segments;
use ContentGates\Membership\Membership_Service;
use ContentGates\REST\Membership_Controller;
use ContentGates\Segments\Segment_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

require_once __DIR__ . '/class-rest-test-cache-adapter.php';

/**
 * Verifies the authorized, uncacheable REST assignment contract.
 */
final class Test_Membership_REST extends WP_UnitTestCase {

	/**
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Cache adapter test double.
	 *
	 * @var Rest_Test_Cache_Adapter
	 */
	private Rest_Test_Cache_Adapter $cache;

	/**
	 * Controller under test.
	 *
	 * @var Membership_Controller
	 */
	private Membership_Controller $controller;

	/**
	 * Create the REST controller and segment type.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->segments = new Segment_Repository();
		$this->segments->register_types();
		$this->cache      = new Rest_Test_Cache_Adapter();
		$service          = new Membership_Service( $this->segments, $this->cache, new Consulted_Segments() );
		$this->controller = new Membership_Controller( $service );
	}

	/**
	 * Administrators can assign membership and responses cannot be cached.
	 *
	 * @throws \RuntimeException When WordPress cannot create a fixture.
	 */
	public function test_admin_can_assign_membership(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( ! is_int( $user_id ) ) {
			throw new \RuntimeException( 'The administrator fixture could not be created.' );
		}
		wp_set_current_user( $user_id );
		$segment_id = $this->create_segment();
		$request    = new WP_REST_Request( 'POST', '/content-gates/v1/memberships/' . $segment_id );
		$request->set_param( 'id', $segment_id );
		$request->set_param( 'member', true );

		$this->assertTrue( $this->controller->check_permission( $request ) );
		$response = $this->controller->update_membership( $request );
		$this->assertNotWPError( $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'api_version' => '1.0',
				'segment_id'  => $segment_id,
				'member'      => true,
			),
			$response->get_data()
		);
		$this->assertTrue( $this->cache->member );
		$this->assertTrue( $this->cache->flushed );
	}

	/**
	 * Permission defaults to manage_options and can be supplied by a provider.
	 */
	public function test_permission_is_filterable(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/content-gates/v1/memberships/1' );
		$this->assertFalse( $this->controller->check_permission( $request ) );

		add_filter( 'content_gates_rest_membership_permission', '__return_true' );
		$this->assertTrue( $this->controller->check_permission( $request ) );
	}

	/**
	 * Invalid segment errors keep their public code and REST status.
	 */
	public function test_invalid_segment_has_not_found_status(): void {
		$request = new WP_REST_Request( 'POST', '/content-gates/v1/memberships/999999' );
		$request->set_param( 'id', 999999 );
		$request->set_param( 'member', true );

		$result = $this->controller->update_membership( $request );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Create an active segment directly.
	 *
	 * @throws \RuntimeException When WordPress cannot create the fixture.
	 */
	private function create_segment(): int {
		$segment_id = self::factory()->post->create(
			array(
				'post_type'   => Segment_Repository::POST_TYPE,
				'post_status' => Segment_Repository::STATUS_ACTIVE,
				'post_title'  => 'Subscribers',
			)
		);
		if ( ! is_int( $segment_id ) ) {
			throw new \RuntimeException( 'The segment fixture could not be created.' );
		}

		return $segment_id;
	}
}
