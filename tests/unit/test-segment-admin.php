<?php
/**
 * Segment admin page tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Admin\Segment_Admin;
use ContentGates\Plugin;
use ContentGates\Segments\Segment_Registry;
use WP_UnitTestCase;
use WPDieException;

/**
 * Covers authorization, nonce checks, the admin-post handler, and rendering.
 *
 * The handler ends with `wp_safe_redirect()` followed by `exit`. These tests
 * hook the `wp_redirect` filter to record the target URL and return false,
 * which makes `wp_redirect()` return false so the handler never reaches
 * `exit`. No header is sent.
 */
final class Test_Segment_Admin extends WP_UnitTestCase {

	/**
	 * Admin page under test.
	 *
	 * @var Segment_Admin
	 */
	private Segment_Admin $admin;

	/**
	 * Registry backing the page.
	 *
	 * @var Segment_Registry
	 */
	private Segment_Registry $registry;

	/**
	 * URL captured from the last redirect attempt.
	 *
	 * @var string|null
	 */
	private ?string $redirected_to = null;

	/**
	 * Build a fresh admin on the plugin's registry and capture redirects.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->registry = Plugin::instance()->segments();
		$this->registry->invalidate_cache();
		$this->admin         = new Segment_Admin( $this->registry );
		$this->redirected_to = null;

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
	}

	/**
	 * Clear request superglobals.
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();

		parent::tear_down();
	}

	/**
	 * Record the redirect target and cancel the redirect.
	 *
	 * @param string $location Redirect URL.
	 * @return false
	 */
	public function capture_redirect( string $location ): bool {
		$this->redirected_to = $location;

		return false;
	}

	/**
	 * Sign in an administrator and return the user ID.
	 */
	private function sign_in_admin(): int {
		return $this->sign_in( 'administrator' );
	}

	/**
	 * Sign in a new user with a role and return the user ID.
	 *
	 * @param string $role Role slug.
	 */
	private function sign_in( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $id );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Populate $_POST/$_REQUEST as a browser form submit would.
	 *
	 * @param array<string, string> $fields Form fields; the nonce is added unless `nonce` is false.
	 * @param bool                  $nonce  Whether to include a valid nonce.
	 */
	private function submit( array $fields, bool $nonce = true ): void {
		$fields['action'] = Segment_Admin::ACTION;
		if ( $nonce ) {
			$fields[ Segment_Admin::NONCE_NAME ] = wp_create_nonce( Segment_Admin::ACTION );
		}

		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	/**
	 * Query arguments from the captured redirect.
	 *
	 * @return array<string, string>
	 */
	private function redirect_args(): array {
		$this->assertNotNull( $this->redirected_to, 'Handler must redirect.' );
		$this->assertStringContainsString( 'options-general.php?page=content-gates', $this->redirected_to );

		$query = (string) wp_parse_url( $this->redirected_to, PHP_URL_QUERY );
		parse_str( $query, $args );

		$out = array();
		foreach ( $args as $key => $value ) {
			if ( is_string( $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The hooks are registered by boot().
	 */
	public function test_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Segment_Admin::ACTION ) );
	}

	/**
	 * The page is added under Settings for administrators.
	 */
	public function test_menu_is_added_under_settings(): void {
		$this->sign_in_admin();
		$this->admin->add_menu();

		$this->assertArrayHasKey( 'options-general.php', $GLOBALS['submenu'] );

		$found = false;
		foreach ( $GLOBALS['submenu']['options-general.php'] as $item ) {
			if ( Segment_Admin::PAGE_SLUG === $item[2] ) {
				$found = true;
				$this->assertSame( 'Content Gates', $item[0] );
				$this->assertSame( 'manage_options', $item[1] );
			}
		}
		$this->assertTrue( $found, 'Settings → Content Gates must exist.' );
	}

	/**
	 * A subscriber hitting the handler dies with 403 before any nonce check.
	 */
	public function test_handler_denies_subscriber(): void {
		$this->sign_in( 'subscriber' );
		$this->submit(
			array(
				'operation'    => 'create',
				'segment_name' => 'Should Not Exist',
			)
		);

		$this->expectException( WPDieException::class );
		$this->admin->handle_action();
	}

	/**
	 * Rendering also requires manage_options.
	 */
	public function test_render_denies_subscriber(): void {
		$this->sign_in( 'subscriber' );

		$this->expectException( WPDieException::class );
		ob_start();
		try {
			$this->admin->render_page();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * A missing nonce dies even for an administrator.
	 */
	public function test_handler_rejects_missing_nonce(): void {
		$this->sign_in_admin();
		$this->submit(
			array(
				'operation'    => 'create',
				'segment_name' => 'No Nonce',
			),
			false
		);

		$this->expectException( WPDieException::class );
		$this->admin->handle_action();
	}

	/**
	 * Creating inserts an active segment and redirects with `created`.
	 */
	public function test_handler_creates_segment(): void {
		$this->sign_in_admin();
		$this->submit(
			array(
				'operation'    => 'create',
				'segment_name' => 'Handler Created',
			)
		);

		$this->admin->handle_action();

		$this->assertSame( 'created', $this->redirect_args()[ Segment_Admin::NOTICE_ARG ] );
		$segment = $this->registry->find( 'handler-created' );
		$this->assertNotNull( $segment );
		$this->assertTrue( $segment->is_active() );
		$this->assertSame( 'Handler Created', $segment->name() );
	}

	/**
	 * Renaming keeps ID and key and redirects with `renamed`.
	 */
	public function test_handler_renames_segment(): void {
		$this->sign_in_admin();
		$id = $this->registry->create( 'Before' );
		$this->assertIsInt( $id );
		$this->submit(
			array(
				'operation'    => 'rename',
				'segment_id'   => (string) $id,
				'segment_name' => 'After',
			)
		);

		$this->admin->handle_action();

		$this->assertSame( 'renamed', $this->redirect_args()[ Segment_Admin::NOTICE_ARG ] );
		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->assertSame( 'After', $segment->name() );
		$this->assertSame( 'before', $segment->key() );
	}

	/**
	 * Deactivating then activating through the handler toggles the status.
	 */
	public function test_handler_toggles_activation(): void {
		$this->sign_in_admin();
		$id = $this->registry->create( 'Toggle Me' );
		$this->assertIsInt( $id );

		$this->submit(
			array(
				'operation'  => 'deactivate',
				'segment_id' => (string) $id,
			)
		);
		$this->admin->handle_action();
		$this->assertSame( 'deactivated', $this->redirect_args()[ Segment_Admin::NOTICE_ARG ] );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$this->redirected_to = null;
		$this->submit(
			array(
				'operation'  => 'activate',
				'segment_id' => (string) $id,
			)
		);
		$this->admin->handle_action();
		$this->assertSame( 'activated', $this->redirect_args()[ Segment_Admin::NOTICE_ARG ] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * Registry errors become `error` notices with the error code.
	 */
	public function test_handler_reports_errors(): void {
		$this->sign_in_admin();

		$this->submit(
			array(
				'operation'    => 'create',
				'segment_name' => '   ',
			)
		);
		$this->admin->handle_action();
		$args = $this->redirect_args();
		$this->assertSame( 'error', $args[ Segment_Admin::NOTICE_ARG ] );
		$this->assertSame( 'content_gates_invalid_segment_name', $args[ Segment_Admin::ERROR_ARG ] );

		$this->redirected_to = null;
		$this->submit(
			array(
				'operation'  => 'activate',
				'segment_id' => '999999',
			)
		);
		$this->admin->handle_action();
		$args = $this->redirect_args();
		$this->assertSame( 'error', $args[ Segment_Admin::NOTICE_ARG ] );
		$this->assertSame( 'content_gates_invalid_segment', $args[ Segment_Admin::ERROR_ARG ] );

		$this->redirected_to = null;
		$this->submit( array( 'operation' => 'delete' ) );
		$this->admin->handle_action();
		$args = $this->redirect_args();
		$this->assertSame( 'error', $args[ Segment_Admin::NOTICE_ARG ] );
		$this->assertSame( 'content_gates_invalid_operation', $args[ Segment_Admin::ERROR_ARG ] );
	}

	/**
	 * The page lists segments with controls, a nonce, and no delete control.
	 */
	public function test_render_page_lists_segments_without_delete(): void {
		$this->sign_in_admin();
		$active_id   = $this->registry->create( 'Rendered & "Active"' );
		$inactive_id = $this->registry->create( 'Rendered Inactive' );
		$this->assertIsInt( $active_id );
		$this->assertIsInt( $inactive_id );
		$this->registry->set_active( $inactive_id, false );
		$this->registry->register_key( 'code-rendered' );

		ob_start();
		$this->admin->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h1>Content Gates</h1>', $html );
		$this->assertStringContainsString( 'Rendered &amp; &quot;Active&quot;', $html, 'Names are escaped.' );
		$this->assertStringNotContainsString( 'Rendered & "Active"', $html );
		$this->assertStringContainsString( 'Rendered Inactive', $html );
		$active = $this->registry->find( $active_id );
		$this->assertNotNull( $active );
		$this->assertStringContainsString( '<code>' . $active->key() . '</code>', $html );
		$this->assertStringContainsString( '<td>' . $active_id . '</td>', $html );
		$this->assertStringContainsString( 'name="segment_id" value="' . $active_id . '"', $html );
		$this->assertStringContainsString( '<td>Active</td>', $html );
		$this->assertStringContainsString( '<td>Inactive</td>', $html );
		$this->assertStringContainsString( '>Deactivate</button>', $html );
		$this->assertStringContainsString( '>Activate</button>', $html );
		$this->assertStringContainsString( '>Rename</button>', $html );
		$this->assertStringContainsString( 'Add segment', $html );

		$this->assertStringContainsString( 'name="' . Segment_Admin::NONCE_NAME . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . Segment_Admin::ACTION . '"', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
		foreach ( array( 'create', 'rename', 'activate', 'deactivate' ) as $operation ) {
			$this->assertStringContainsString( 'name="operation" value="' . $operation . '"', $html );
		}

		$this->assertStringNotContainsStringIgnoringCase( 'delete', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'trash', $html );

		// Code-registered row: key shown, "code" as ID, no form controls.
		$this->assertStringContainsString( '<code>code-rendered</code>', $html );
		$this->assertStringContainsString( '<td>code</td>', $html );
		$this->assertStringContainsString( '<code>crawler</code>', $html );
		$this->assertMatchesRegularExpression(
			'#<tr><td>code</td><td>code-rendered</td><td><code>code-rendered</code></td><td>Active</td><td>&mdash;</td></tr>#',
			$html
		);
	}

	/**
	 * Notices render from the redirect query arguments.
	 */
	public function test_render_notice(): void {
		$this->sign_in_admin();

		$_GET = array( Segment_Admin::NOTICE_ARG => 'created' );
		ob_start();
		$this->admin->render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Segment created.', $html );

		$_GET = array(
			Segment_Admin::NOTICE_ARG => 'error',
			Segment_Admin::ERROR_ARG  => 'content_gates_invalid_segment_name<script>',
		);
		ob_start();
		$this->admin->render_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'content_gates_invalid_segment_namescript', $html, 'Error code is reduced to a key.' );
		$this->assertStringNotContainsString( '<script>', $html );

		$_GET = array( Segment_Admin::NOTICE_ARG => 'bogus' );
		ob_start();
		$this->admin->render_notice();
		$html = (string) ob_get_clean();
		$this->assertSame( '', $html );

		$_GET = array();
		ob_start();
		$this->admin->render_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
