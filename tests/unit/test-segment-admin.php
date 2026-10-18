<?php
/**
 * Segment settings page tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Admin\Segment_Admin;
use ContentGates\Segments\Segment_Repository;
use WP_UnitTestCase;

/**
 * Verifies the segment settings UI surface.
 */
final class Test_Segment_Admin extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Segment_Repository
	 */
	private Segment_Repository $repository;

	/**
	 * Admin controller under test.
	 *
	 * @var Segment_Admin
	 */
	private Segment_Admin $admin;

	/**
	 * Prepare an administrator and registered storage.
	 */
	public function set_up(): void {
		parent::set_up();

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_wp_error( $administrator_id ) ) {
			self::fail( $administrator_id->get_error_message() );
		}
		wp_set_current_user( $administrator_id );
		$this->repository = new Segment_Repository();
		$this->repository->register_types();
		$this->admin = new Segment_Admin( $this->repository );
	}

	/**
	 * Reset the current user after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The controller registers the menu and mutation handlers.
	 */
	public function test_registers_admin_hooks(): void {
		$this->admin->register_hooks();

		$this->assertSame( 10, has_action( 'admin_menu', array( $this->admin, 'register_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_content_gates_create_segment', array( $this->admin, 'handle_create' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_content_gates_rename_segment', array( $this->admin, 'handle_rename' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_content_gates_set_segment_status', array( $this->admin, 'handle_set_status' ) ) );

		remove_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		remove_action( 'admin_post_content_gates_create_segment', array( $this->admin, 'handle_create' ) );
		remove_action( 'admin_post_content_gates_rename_segment', array( $this->admin, 'handle_rename' ) );
		remove_action( 'admin_post_content_gates_set_segment_status', array( $this->admin, 'handle_set_status' ) );
	}

	/**
	 * The page lists active and inactive records without delete controls.
	 */
	public function test_page_renders_list_editor_without_delete_action(): void {
		$active_id   = $this->create_segment( 'Active readers' );
		$inactive_id = $this->create_segment( 'Past readers' );
		$this->assertTrue( $this->repository->set_active( $inactive_id, false ) );

		ob_start();
		$this->admin->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Content Gates</h1>', $output );
		$this->assertStringContainsString( 'Active readers', $output );
		$this->assertStringContainsString( 'Past readers', $output );
		$this->assertStringContainsString( 'Active', $output );
		$this->assertStringContainsString( 'Inactive', $output );
		$this->assertStringContainsString( 'content_gates_rename_segment', $output );
		$this->assertStringContainsString( 'content_gates_set_segment_status', $output );
		$this->assertStringNotContainsString( 'content_gates_delete', $output );
		$this->assertStringContainsString( 'value="' . $active_id . '"', $output );
	}

	/**
	 * Registering the menu creates the expected settings submenu page.
	 */
	public function test_register_menu_adds_content_gates_settings_page(): void {
		global $submenu;

		$this->admin->register_menu();
		$settings_items = isset( $submenu['options-general.php'] ) ? $submenu['options-general.php'] : array();
		$matches        = array_filter(
			$settings_items,
			static function ( array $item ): bool {
				return Segment_Admin::PAGE_SLUG === $item[2];
			}
		);

		$this->assertCount( 1, $matches );
		$item = reset( $matches );
		$this->assertIsArray( $item );
		$this->assertSame( Segment_Repository::ADMIN_CAPABILITY, $item[1] );
		$this->assertSame( 'Content Gates', $item[0] );
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
