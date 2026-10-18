<?php
/**
 * Segment repository tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Segments\Segment_Repository;
use WP_Error;
use WP_UnitTestCase;

/**
 * Verifies segment storage and lifecycle safeguards.
 */
final class Test_Segment_Repository extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $repository;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $administrator_id;

	/**
	 * Register storage and deletion guards for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new Segment_Repository();
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_wp_error( $administrator_id ) ) {
			self::fail( $administrator_id->get_error_message() );
		}
		$this->administrator_id = $administrator_id;
		wp_set_current_user( $this->administrator_id );
		$this->repository->register_types();
		$this->repository->register_hooks();
	}

	/**
	 * Remove filters installed by the repository.
	 */
	public function tear_down(): void {
		remove_action( 'init', array( $this->repository, 'register_types' ), 5 );
		remove_filter( 'pre_trash_post', array( $this->repository, 'prevent_trash' ), 10 );
		remove_filter( 'pre_delete_post', array( $this->repository, 'prevent_delete' ), 10 );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * New segments are active custom post type records.
	 */
	public function test_create_makes_active_segment_record(): void {
		$segment_id = $this->repository->create( 'Subscribers' );

		$this->assertIsInt( $segment_id );
		$segment = $this->repository->find( $segment_id );
		$this->assertNotNull( $segment );
		$this->assertSame( Segment_Repository::POST_TYPE, $segment->post_type );
		$this->assertSame( Segment_Repository::STATUS_ACTIVE, $segment->post_status );
		$this->assertSame( 'Subscribers', $segment->post_title );
		$this->assertTrue( $this->repository->is_active( $segment_id ) );
	}

	/**
	 * Rename and status changes retain the stable ID and group key.
	 */
	public function test_lifecycle_retains_id_and_group_key(): void {
		$segment_id = $this->create_segment( 'Members' );
		$group_key  = $this->repository->group_key( $segment_id );

		$this->assertTrue( $this->repository->rename( $segment_id, 'Paid members' ) );
		$this->assertTrue( $this->repository->set_active( $segment_id, false ) );
		$this->assertFalse( $this->repository->is_active( $segment_id ) );
		$this->assertSame( $group_key, $this->repository->group_key( $segment_id ) );

		$inactive = $this->repository->find( $segment_id );
		$this->assertNotNull( $inactive );
		$this->assertSame( 'Paid members', $inactive->post_title );
		$this->assertSame( Segment_Repository::STATUS_INACTIVE, $inactive->post_status );

		$this->assertTrue( $this->repository->set_active( $segment_id, true ) );
		$this->assertTrue( $this->repository->is_active( $segment_id ) );
		$active = $this->repository->find( $segment_id );
		$this->assertNotNull( $active );
		$this->assertSame( $segment_id, $active->ID );
	}

	/**
	 * The group key contains the site and segment IDs.
	 */
	public function test_group_key_uses_site_and_segment_ids(): void {
		$segment_id = $this->create_segment( 'Newsletter' );

		$this->assertSame(
			sprintf( 'cg_%d_%d', get_current_blog_id(), $segment_id ),
			$this->repository->group_key( $segment_id )
		);
	}

	/**
	 * List methods return saved records and filter inactive segments.
	 */
	public function test_lists_include_expected_statuses_in_name_order(): void {
		$zulu_id  = $this->create_segment( 'Zulu' );
		$alpha_id = $this->create_segment( 'Alpha' );
		$this->assertTrue( $this->repository->set_active( $zulu_id, false ) );

		$this->assertSame( array( $alpha_id, $zulu_id ), wp_list_pluck( $this->repository->get_all(), 'ID' ) );
		$this->assertSame( array( $alpha_id ), wp_list_pluck( $this->repository->get_active(), 'ID' ) );
	}

	/**
	 * Invalid names and IDs return stable errors.
	 */
	public function test_invalid_changes_return_errors(): void {
		$empty_name = $this->repository->create( '   ' );
		$rename     = $this->repository->rename( 999999, 'Missing' );
		$status     = $this->repository->set_active( 999999, false );

		$this->assertInstanceOf( WP_Error::class, $empty_name );
		$this->assertSame( 'content_gates_empty_segment_name', $empty_name->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $rename );
		$this->assertSame( 'content_gates_invalid_segment', $rename->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $status );
		$this->assertSame( 'content_gates_invalid_segment', $status->get_error_code() );
	}

	/**
	 * Users without manage_options cannot change segment records.
	 */
	public function test_changes_require_manage_options(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		if ( is_wp_error( $editor_id ) ) {
			self::fail( $editor_id->get_error_message() );
		}
		wp_set_current_user( $editor_id );

		$result = $this->repository->create( 'Forbidden' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_forbidden', $result->get_error_code() );
	}

	/**
	 * Segment records cannot be trashed or permanently deleted.
	 */
	public function test_segment_cannot_be_trashed_or_deleted(): void {
		$segment_id = $this->create_segment( 'Permanent identity' );

		$this->assertFalse( wp_trash_post( $segment_id ) );
		$this->assertFalse( wp_delete_post( $segment_id, true ) );
		$this->assertNotNull( $this->repository->find( $segment_id ) );
	}

	/**
	 * The post type denies delete capabilities at the registration layer.
	 */
	public function test_post_type_caps_deny_deletion(): void {
		$post_type = get_post_type_object( Segment_Repository::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( Segment_Repository::ADMIN_CAPABILITY, $post_type->cap->create_posts );
		$this->assertSame( 'do_not_allow', $post_type->cap->delete_post );
		$this->assertSame( 'do_not_allow', $post_type->cap->delete_posts );
	}

	/**
	 * Create a segment and return its ID.
	 *
	 * @param string $name Segment name.
	 */
	private function create_segment( string $name ): int {
		$result = $this->repository->create( $name );
		$this->assertIsInt( $result );

		return $result;
	}
}
