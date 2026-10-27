<?php
/**
 * WP-CLI command tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\CLI\Commands;
use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Segments\Segment;
use RuntimeException;

/**
 * `wp content-gates ...` drives the registry and reports what it did.
 */
final class Test_Commands extends Content_Gates_Test_Case {

	/**
	 * Load the recording WP_CLI and start each test with empty output.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/class-cli-output.php';
		require_once __DIR__ . '/fake-wp-cli.php';
		require_once __DIR__ . '/fake-wp-cli-utils.php';
		CLI_Output::reset();
	}

	/**
	 * The command object under test.
	 */
	private function commands(): Commands {
		return new Commands( $this->plugin() );
	}

	/**
	 * The create command derives the key, prints it with --porcelain, and takes meter settings in days.
	 */
	public function test_create_boolean_and_meter(): void {
		$this->commands()->create( array( 'VIP members' ), array( 'porcelain' => '1' ) );
		$this->assertSame( array( 'vip-members' ), CLI_Output::$lines );

		CLI_Output::reset();
		$this->commands()->create(
			array( 'Metered articles' ),
			array(
				'type'        => 'meter',
				'limit'       => '5',
				'period-days' => '30',
			)
		);
		$this->assertStringContainsString( 'Created segment "Metered articles" with key metered-articles', CLI_Output::$lines[0] );

		$this->new_request();
		$meter = $this->plugin()->segments()->resolve( 'metered-articles' );
		$this->assertInstanceOf( Segment::class, $meter );
		$this->assertTrue( $meter->is_meter() );
		$this->assertSame( 5, $meter->limit );
		$this->assertSame( 30 * DAY_IN_SECONDS, $meter->period );
	}

	/**
	 * Rename, deactivate, activate, and meter each report the result in the unit they take.
	 */
	public function test_rename_status_and_meter(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_meter( 'Metered articles', 5, 30 * DAY_IN_SECONDS );

		$this->commands()->rename( array( 'subscriber', 'Paying readers' ) );
		$this->commands()->deactivate( array( 'subscriber' ) );
		$this->commands()->activate( array( 'subscriber' ) );
		$this->commands()->meter(
			array( 'metered-articles' ),
			array(
				'limit'       => '3',
				'period-days' => '7',
			)
		);

		$this->assertSame(
			array(
				'Success: Renamed subscriber to "Paying readers".',
				'Success: Deactivated subscriber.',
				'Success: Activated subscriber.',
				'Success: Meter metered-articles: 3 per 7 days.',
			),
			CLI_Output::$lines
		);
		$this->new_request();
		$meter = $this->plugin()->segments()->resolve( 'metered-articles' );
		$this->assertInstanceOf( Segment::class, $meter );
		$this->assertSame( 7 * DAY_IN_SECONDS, $meter->period );
	}

	/**
	 * A registry error becomes WP_CLI::error(), which the fake throws.
	 */
	public function test_errors_surface(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown segment.' );
		$this->commands()->rename( array( 'ghost', 'Anything' ) );
	}

	/**
	 * The declared command prints the stored set and can recompute it from the blocks.
	 */
	public function test_declared_prints_and_recomputes(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->commands()->declared( array( (string) $post_id ), array() );
		$this->assertSame( array( 'subscriber' ), CLI_Output::$lines );

		delete_post_meta( $post_id, Declared_Set::META_KEY );
		CLI_Output::reset();
		$this->commands()->declared( array( (string) $post_id ), array() );
		$this->assertSame( array( '(none)' ), CLI_Output::$lines );

		CLI_Output::reset();
		$this->commands()->declared( array( (string) $post_id ), array( 'recompute' => '1' ) );
		$this->assertSame( array( 'subscriber' ), CLI_Output::$lines );
	}

	/**
	 * The list command hands every segment, active or not, to the formatter in registry order.
	 */
	public function test_list_formats_every_segment(): void {
		$old = $this->create_segment( 'Old' );
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->set_active( $old->id, false );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();

		$this->commands()->list_segments( array(), array( 'format' => 'json' ) );

		$formatted = CLI_Output::$formatted;
		$this->assertIsArray( $formatted );
		$this->assertSame( 'json', $formatted['format'] );
		$this->assertSame( array( 'old', 'subscriber', 'crawlers-ai', 'crawlers-search' ), array_column( $formatted['items'], 'key' ) );
		$this->assertSame( array( 'inactive', 'active', 'active', 'active' ), array_column( $formatted['items'], 'status' ) );
	}

	/**
	 * The sync-crawlers command reports what changed, in file order, then that nothing did.
	 */
	public function test_sync_crawlers_reports_changes(): void {
		$this->commands()->sync_crawlers();
		$this->commands()->sync_crawlers();

		$this->assertSame(
			array(
				'Success: Changed: crawlers-search, crawlers-ai',
				'Success: Crawler segments already match the file.',
			),
			CLI_Output::$lines
		);
	}
}
