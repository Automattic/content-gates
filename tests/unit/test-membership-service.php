<?php
/**
 * Membership service tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Segments;
use ContentGates\Membership\Membership_Service;
use ContentGates\Segments\Segment_Repository;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/class-test-cache-adapter.php';
require_once dirname( __DIR__, 2 ) . '/inc/functions.php';

/**
 * Verifies membership, errors, and response dependency tracking.
 */
final class Test_Membership_Service extends WP_UnitTestCase {

	/**
	 * Segment repository.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $segments;

	/**
	 * Cache adapter test double.
	 *
	 * @var Test_Cache_Adapter
	 */
	private Test_Cache_Adapter $cache;

	/**
	 * Consulted segment state.
	 *
	 * @var Consulted_Segments
	 */
	private Consulted_Segments $consulted;

	/**
	 * Service under test.
	 *
	 * @var Membership_Service
	 */
	private Membership_Service $service;

	/**
	 * Register segment types and create a fresh service.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->segments = new Segment_Repository();
		$this->segments->register_types();
		$this->cache     = new Test_Cache_Adapter();
		$this->consulted = new Consulted_Segments();
		$this->service   = new Membership_Service( $this->segments, $this->cache, $this->consulted );
	}

	/**
	 * A false active membership read still records the stable group key.
	 */
	public function test_false_membership_read_is_recorded(): void {
		$segment_id = $this->create_segment( true );

		$this->assertFalse( $this->service->is_reader_in_segment( $segment_id ) );
		$this->assertSame( array( $this->segments->group_key( $segment_id ) ), $this->consulted->finalize() );
	}

	/**
	 * An inactive segment denies access and still records its stable key.
	 */
	public function test_inactive_segment_read_is_false(): void {
		$segment_id = $this->create_segment( false );
		$this->cache->members[ $this->segments->group_key( $segment_id ) ] = true;

		$this->assertFalse( $this->service->is_reader_in_segment( $segment_id ) );
		$this->assertSame( array( $this->segments->group_key( $segment_id ) ), $this->consulted->finalize() );
	}

	/**
	 * Assignment registers and updates the stable cache group.
	 */
	public function test_assigns_active_segment(): void {
		$segment_id = $this->create_segment( true );
		$group_key  = $this->segments->group_key( $segment_id );

		$this->assertTrue( $this->service->assign_reader_to_segment( $segment_id ) );
		$this->assertSame( array( $group_key ), $this->cache->registered );
		$this->assertSame( array( array( $group_key, true ) ), $this->cache->changes );
	}

	/**
	 * Public namespaced functions delegate to the configured service.
	 */
	public function test_public_functions_delegate_to_membership_service(): void {
		$segment_id = $this->create_segment( true );
		$group_key  = $this->segments->group_key( $segment_id );

		$this->cache->members[ $group_key ] = true;
		Membership_Service::set_instance( $this->service );

		$this->assertTrue( \ContentGates\content_gates_is_reader_in_segment( $segment_id ) );
		$this->assertTrue( \ContentGates\content_gates_assign_reader_to_segment( $segment_id ) );
		$this->assertTrue( \ContentGates\content_gates_remove_reader_from_segment( $segment_id ) );
		$this->assertSame(
			array(
				array( $group_key, true ),
				array( $group_key, false ),
			),
			$this->cache->changes
		);
	}

	/**
	 * Assignment fails for inactive segments, but removal remains possible.
	 */
	public function test_inactive_segment_rejects_assignment_and_allows_removal(): void {
		$segment_id = $this->create_segment( false );
		$result     = $this->service->assign_reader_to_segment( $segment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );
		$this->assertTrue( $this->service->remove_reader_from_segment( $segment_id ) );
		$this->assertSame( array( array( $this->segments->group_key( $segment_id ), false ) ), $this->cache->changes );
	}

	/**
	 * Unknown segments fail without touching the cache provider.
	 */
	public function test_unknown_segment_returns_error(): void {
		$result = $this->service->assign_reader_to_segment( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );
		$this->assertSame( array(), $this->cache->registered );
	}

	/**
	 * Provider timing errors use the wrapper-specific public code.
	 */
	public function test_maps_provider_late_error(): void {
		$segment_id             = $this->create_segment( true );
		$this->cache->set_error = new WP_Error( 'did_send_headers', 'Too late.' );
		$result                 = $this->service->assign_reader_to_segment( $segment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_assignment_too_late', $result->get_error_code() );
	}

	/**
	 * The wrapper rejects calls after the WordPress send_headers action.
	 */
	public function test_rejects_assignment_after_send_headers(): void {
		global $wp_actions;

		$segment_id                 = $this->create_segment( true );
		$previous                   = $wp_actions['send_headers'] ?? null;
		$wp_actions['send_headers'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the hook timing boundary.

		$result = $this->service->assign_reader_to_segment( $segment_id );

		if ( null === $previous ) {
			unset( $wp_actions['send_headers'] );
		} else {
			$wp_actions['send_headers'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test state.
		}

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_assignment_too_late', $result->get_error_code() );
		$this->assertSame( array(), $this->cache->registered );
	}

	/**
	 * The wrapper rejects mutation after response bytes made headers immutable.
	 */
	public function test_rejects_assignment_after_output_started(): void {
		$segment_id = $this->create_segment( true );
		$service    = new Membership_Service(
			$this->segments,
			$this->cache,
			$this->consulted,
			static fn (): bool => true
		);

		$result = $service->assign_reader_to_segment( $segment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_assignment_too_late', $result->get_error_code() );
		$this->assertSame( array(), $this->cache->registered );
	}

	/**
	 * The wrapper rejects calls before cache cookies can be parsed.
	 */
	public function test_rejects_assignment_before_init(): void {
		global $wp_actions;

		$segment_id         = $this->create_segment( true );
		$previous           = $wp_actions['init'] ?? null;
		$wp_actions['init'] = 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the hook timing boundary.
		$result             = $this->service->assign_reader_to_segment( $segment_id );
		$wp_actions['init'] = $previous ?? 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test state.

		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_assignment_too_early', $result->get_error_code() );
		$this->assertSame( array(), $this->cache->registered );
	}

	/**
	 * Create a segment without exercising admin permissions.
	 *
	 * @param bool $active Whether the segment is active.
	 * @throws \RuntimeException When WordPress cannot create the fixture.
	 */
	private function create_segment( bool $active ): int {
		$segment_id = self::factory()->post->create(
			array(
				'post_type'   => Segment_Repository::POST_TYPE,
				'post_status' => $active ? Segment_Repository::STATUS_ACTIVE : Segment_Repository::STATUS_INACTIVE,
				'post_title'  => 'Readers',
			)
		);
		if ( ! is_int( $segment_id ) ) {
			throw new \RuntimeException( 'The segment fixture could not be created.' );
		}

		return $segment_id;
	}
}
