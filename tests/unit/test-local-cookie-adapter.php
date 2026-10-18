<?php
/**
 * Local_Cookie_Adapter tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Rendering\Headers;
use WP_UnitTestCase;

/**
 * The local adapter reproduces the Vary_Cache cookie contract.
 */
final class Test_Local_Cookie_Adapter extends WP_UnitTestCase {

	/**
	 * Saved request superglobals.
	 *
	 * @var array{cookie: mixed, header: mixed}
	 */
	private array $saved = array(
		'cookie' => null,
		'header' => null,
	);

	/**
	 * Save request state and clear recorded headers.
	 */
	public function set_up(): void {
		parent::set_up();
		Headers::reset();
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput -- Test setup saves and restores raw request state.
		$this->saved['cookie'] = $_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ] ?? null;
		$this->saved['header'] = $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] ?? null;
		unset( $_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ], $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] );
		// phpcs:enable
	}

	/**
	 * Restore request state.
	 */
	public function tear_down(): void {
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Test teardown restores raw request state.
		unset( $_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ], $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] );
		if ( null !== $this->saved['cookie'] ) {
			$_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ] = $this->saved['cookie'];
		}
		if ( null !== $this->saved['header'] ) {
			$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = $this->saved['header'];
		}
		// phpcs:enable
		Headers::reset();
		parent::tear_down();
	}

	/**
	 * Serialization sorts groups, drops empty values and prefixes the version.
	 */
	public function test_serialize_matches_vary_cache_format(): void {
		$groups = array(
			'subscriber' => '1',
			'newsletter' => '1',
			'donor'      => '',
		);

		$this->assertSame( 'vc-v1__newsletter_--_1---__subscriber_--_1', Local_Cookie_Adapter::serialize( $groups ) );
		$this->assertSame( '', Local_Cookie_Adapter::serialize( array( 'donor' => '' ) ) );
		$this->assertSame( '', Local_Cookie_Adapter::serialize( array() ) );
	}

	/**
	 * Parsing round-trips, tolerates a missing prefix and skips junk.
	 */
	public function test_parse_round_trips_and_skips_malformed_pairs(): void {
		$value = 'vc-v1__newsletter_--_1---__subscriber_--_yes';

		$this->assertSame(
			array(
				'newsletter' => '1',
				'subscriber' => 'yes',
			),
			Local_Cookie_Adapter::parse( $value )
		);
		$this->assertSame( array( 'a' => '1' ), Local_Cookie_Adapter::parse( 'a_--_1' ), 'Prefix is optional.' );
		$this->assertSame(
			array( 'ok' => '1' ),
			Local_Cookie_Adapter::parse( 'vc-v1__junk---__ok_--_1---__bad key_--_1---__bad_--_bad value' )
		);
	}

	/**
	 * Assign then flush records a cookie with the expected shape.
	 */
	public function test_flush_records_pending_cookie_and_fires_action(): void {
		$adapter = new Local_Cookie_Adapter();
		$adapter->register_group( 'subscriber' );
		$adapter->register_group( 'newsletter' );

		$this->assertTrue( $adapter->set_group( 'newsletter', '1' ) );
		$this->assertTrue( $adapter->set_group( 'subscriber', '1' ) );
		$this->assertNull( $adapter->pending_cookie(), 'Nothing is written before flush.' );

		$fired = array();
		add_action(
			'vip_vary_cache_did_send_headers',
			static function ( bool $sent_vary, bool $sent_cookie ) use ( &$fired ): void {
				$fired[] = array( $sent_vary, $sent_cookie );
			},
			10,
			2
		);

		add_filter( 'content_gates_cookie_expiry', static fn(): int => 600 );

		$before = time();
		$adapter->flush();

		$cookie = $adapter->pending_cookie();
		$this->assertNotNull( $cookie );
		$this->assertSame( 'vip-go-seg', $cookie['name'] );
		$this->assertSame( 'vc-v1__newsletter_--_1---__subscriber_--_1', $cookie['value'] );
		$this->assertGreaterThanOrEqual( $before + 600, $cookie['expires'] );
		$this->assertLessThanOrEqual( time() + 600, $cookie['expires'] );
		$this->assertSame( (string) constant( 'COOKIEPATH' ), $cookie['path'] );
		$this->assertSame( (string) constant( 'COOKIE_DOMAIN' ), $cookie['domain'] );

		$this->assertTrue( $adapter->did_send_headers() );
		$this->assertSame( array( array( false, true ) ), $fired, 'Action fires once with sent_vary=false.' );
		$this->assertSame( 'local', Headers::sent()['X-Content-Gates-Simulation'] ?? null );
		$this->assertArrayNotHasKey( 'Vary', Headers::sent() );

		$adapter->flush();
		$this->assertSame( 1, count( $fired ), 'flush() is idempotent.' );
	}

	/**
	 * Without changes, flush writes no cookie and fires no action.
	 */
	public function test_flush_without_changes_writes_nothing(): void {
		$adapter = new Local_Cookie_Adapter();
		$adapter->register_group( 'subscriber' );

		$fired = 0;
		add_action(
			'vip_vary_cache_did_send_headers',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$adapter->flush();

		$this->assertNull( $adapter->pending_cookie() );
		$this->assertSame( 0, $fired );
		$this->assertTrue( $adapter->did_send_headers() );
		$this->assertSame( 'local', Headers::sent()['X-Content-Gates-Simulation'] ?? null );
	}

	/**
	 * Removing a group drops it from the cookie; removing the last clears it.
	 */
	public function test_removal_drops_group_and_clears_cookie_when_empty(): void {
		$adapter = new Local_Cookie_Adapter();
		$adapter->register_group( 'subscriber' );
		$adapter->register_group( 'newsletter' );
		$adapter->set_group( 'subscriber', '1' );
		$adapter->set_group( 'newsletter', '1' );
		$adapter->set_group( 'newsletter', '' );

		$this->assertSame(
			array(
				'subscriber' => '1',
				'newsletter' => '',
			),
			$adapter->groups(),
			'Removed groups stay registered with an empty value.'
		);
		$this->assertSame( 'vc-v1__subscriber_--_1', Local_Cookie_Adapter::serialize( $adapter->groups() ) );

		$adapter->set_group( 'subscriber', '' );
		$adapter->flush();

		$cookie = $adapter->pending_cookie();
		$this->assertNotNull( $cookie );
		$this->assertSame( '', $cookie['value'] );
		$this->assertLessThan( time(), $cookie['expires'], 'An empty membership expires the cookie.' );
	}

	/**
	 * Every error code from set_group().
	 */
	public function test_set_group_error_codes(): void {
		$adapter = new Local_Cookie_Adapter();
		$adapter->register_group( 'subscriber' );

		$result = $adapter->set_group( 'bad key', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment_key', $result->get_error_code() );

		$result = $adapter->set_group( 'subscriber', 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_value', $result->get_error_code() );

		$result = $adapter->set_group( 'unregistered', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_not_registered', $result->get_error_code() );

		$adapter->flush();
		$result = $adapter->set_group( 'subscriber', '1' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );
	}

	/**
	 * The request header wins over the cookie.
	 */
	public function test_header_beats_cookie_when_parsing(): void {
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulating the incoming request.
		$_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ] = 'vc-v1__subscriber_--_1';
		$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = 'vc-v1__newsletter_--_1';
		// phpcs:enable

		$adapter = new Local_Cookie_Adapter();
		$adapter->parse_request();

		$this->assertSame( array( 'newsletter' => '1' ), $adapter->groups() );
	}

	/**
	 * The cookie is used when no header is present.
	 */
	public function test_cookie_is_parsed_without_header(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulating the incoming request.
		$_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ] = 'vc-v1__subscriber_--_1';

		$adapter = new Local_Cookie_Adapter();
		$adapter->parse_request();

		$this->assertSame( array( 'subscriber' => '1' ), $adapter->groups() );
	}

	/**
	 * Registering before parsing keeps the parsed values and the registered
	 * groups that the cookie does not mention.
	 */
	public function test_registration_before_parse_keeps_cookie_values(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Simulating the incoming request.
		$_COOKIE[ Local_Cookie_Adapter::COOKIE_NAME ] = 'vc-v1__subscriber_--_1';

		$adapter = new Local_Cookie_Adapter();
		$adapter->register_group( 'subscriber' );
		$adapter->register_group( 'newsletter' );
		$adapter->parse_request();

		$this->assertSame(
			array(
				'subscriber' => '1',
				'newsletter' => '',
			),
			$adapter->groups()
		);

		$adapter->register_group( 'subscriber' );
		$this->assertSame( '1', $adapter->groups()['subscriber'], 'Late re-registration keeps the parsed value.' );
	}

	/**
	 * Hooks and simulation flag.
	 */
	public function test_register_hooks_and_flags(): void {
		$adapter = new Local_Cookie_Adapter();
		$adapter->register();

		$this->assertSame( 10, has_action( 'init', array( $adapter, 'parse_request' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'send_headers', array( $adapter, 'flush' ) ) );
		$this->assertTrue( $adapter->is_local_simulation() );
		$this->assertFalse( $adapter->did_send_headers() );
	}
}
