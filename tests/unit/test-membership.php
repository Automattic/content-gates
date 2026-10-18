<?php
/**
 * Membership tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Set;
use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Segments\Segment;
use WP_UnitTestCase;

require_once __DIR__ . '/class-array-segment-source.php';

/**
 * Membership reads only through the adapter and records every read.
 */
final class Test_Membership extends WP_UnitTestCase {

	/**
	 * Segment source under test.
	 *
	 * @var Array_Segment_Source
	 */
	private Array_Segment_Source $source;

	/**
	 * Adapter under test.
	 *
	 * @var Local_Cookie_Adapter
	 */
	private Local_Cookie_Adapter $adapter;

	/**
	 * Consulted set under test.
	 *
	 * @var Consulted_Set
	 */
	private Consulted_Set $consulted;

	/**
	 * Membership under test.
	 *
	 * @var Membership
	 */
	private Membership $membership;

	/**
	 * Build a fresh membership layer with three segments.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->source = new Array_Segment_Source(
			array(
				new Segment( 11, 'subscriber', 'Subscribers', true ),
				new Segment( null, 'newsletter', 'Newsletter', true ),
				new Segment( 12, 'archived', 'Archived', false ),
			)
		);

		$this->adapter    = new Local_Cookie_Adapter();
		$this->consulted  = new Consulted_Set();
		$this->membership = new Membership( $this->adapter, $this->source, $this->consulted );
		$this->membership->register_groups();
	}

	/**
	 * The register() call hooks init 1 and send_headers PHP_INT_MAX - 1.
	 */
	public function test_register_hooks_at_required_priorities(): void {
		$this->membership->register();

		$this->assertSame( 1, has_action( 'init', array( $this->membership, 'register_groups' ) ) );
		$this->assertSame( PHP_INT_MAX - 1, has_action( 'send_headers', array( $this->membership, 'mark_headers_sent' ) ) );
	}

	/**
	 * The init 1 callback registers every known segment, inactive ones included.
	 */
	public function test_register_groups_registers_all_segments(): void {
		$this->assertSame(
			array(
				'subscriber' => '',
				'newsletter' => '',
				'archived'   => '',
			),
			$this->adapter->groups()
		);
	}

	/**
	 * Assign by key and by ID, then read back.
	 */
	public function test_assign_and_read(): void {
		$this->assertTrue( $this->membership->assign( 'newsletter' ) );
		$this->assertTrue( $this->membership->assign( 11, 'gold' ) );

		$this->assertTrue( $this->membership->is_in( 'newsletter' ) );
		$this->assertTrue( $this->membership->is_in( 11 ) );
		$this->assertTrue( $this->membership->is_in( 'subscriber', 'gold' ) );
		$this->assertFalse( $this->membership->is_in( 'subscriber', 'silver' ) );
		$this->assertSame( 'gold', $this->membership->value( 'subscriber' ) );
		$this->assertSame( '1', $this->membership->value( 'newsletter' ) );
		$this->assertSame(
			array(
				'subscriber' => 'gold',
				'newsletter' => '1',
			),
			$this->membership->membership()
		);
	}

	/**
	 * Removal drops the group from membership but keeps it registered.
	 */
	public function test_remove_drops_group(): void {
		$this->membership->assign( 'subscriber' );
		$this->assertTrue( $this->membership->remove( 'subscriber' ) );

		$this->assertSame( array(), $this->membership->membership() );
		$this->assertFalse( $this->membership->is_in( 'subscriber' ) );
		$this->assertNull( $this->membership->value( 'subscriber' ) );
		$this->assertArrayHasKey( 'subscriber', $this->adapter->groups() );
	}

	/**
	 * After our send_headers callback, writes fail with content_gates_too_late.
	 */
	public function test_assign_after_send_headers_is_too_late(): void {
		$this->assertFalse( $this->membership->is_too_late() );

		$this->membership->mark_headers_sent();

		$this->assertTrue( $this->membership->is_too_late() );

		$result = $this->membership->assign( 'subscriber' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );

		$result = $this->membership->remove( 'subscriber' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );

		$this->assertFalse( $this->membership->is_in( 'subscriber' ), 'Reads still work after send_headers.' );
	}

	/**
	 * An explicit adapter flush (REST path) also makes later writes too late.
	 */
	public function test_assign_after_flush_is_too_late(): void {
		$this->membership->assign( 'subscriber' );
		$this->adapter->flush();

		$result = $this->membership->assign( 'newsletter' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );
	}

	/**
	 * Unknown references are rejected on write.
	 */
	public function test_assign_unknown_segment(): void {
		$result = $this->membership->assign( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );

		$result = $this->membership->remove( 999 );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );
	}

	/**
	 * Inactive segments reject writes.
	 */
	public function test_assign_inactive_segment(): void {
		$result = $this->membership->assign( 'archived' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );

		$result = $this->membership->remove( 12 );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );
	}

	/**
	 * Values outside the cookie alphabet are rejected before the adapter.
	 */
	public function test_assign_invalid_value(): void {
		foreach ( array( '', 'a b', 'a_--_b', 'ümlaut' ) as $value ) {
			$result = $this->membership->assign( 'subscriber', $value );
			$this->assertWPError( $result );
			$this->assertSame( 'content_gates_invalid_value', $result->get_error_code(), "Value: {$value}" );
		}
	}

	/**
	 * A segment whose key the adapter cannot store surfaces the adapter code.
	 */
	public function test_assign_surfaces_adapter_invalid_key(): void {
		$this->source->add( new Segment( 13, 'bad key', 'Bad', true ) );

		$result = $this->membership->assign( 13 );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment_key', $result->get_error_code() );
	}

	/**
	 * Error order: too late beats unknown, unknown beats inactive, inactive
	 * beats invalid value.
	 */
	public function test_error_precedence(): void {
		$result = $this->membership->assign( 'archived', 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );

		$result = $this->membership->assign( 'nope', 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );

		$this->membership->mark_headers_sent();
		$result = $this->membership->assign( 'nope', 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_too_late', $result->get_error_code() );
	}

	/**
	 * A segment created after init 1 is registered on first assignment.
	 */
	public function test_late_segment_is_registered_on_assign(): void {
		$this->source->add( new Segment( 14, 'late', 'Late', true ) );
		$this->assertArrayNotHasKey( 'late', $this->adapter->groups() );

		$this->assertTrue( $this->membership->assign( 'late' ) );
		$this->assertSame( '1', $this->adapter->groups()['late'] );
	}

	/**
	 * Reads that return false are still recorded.
	 */
	public function test_false_read_is_recorded(): void {
		$this->assertFalse( $this->membership->is_in( 'subscriber' ) );
		$this->assertNull( $this->membership->value( 'newsletter' ) );

		$this->assertSame( array( 'newsletter', 'subscriber' ), $this->membership->consulted() );
	}

	/**
	 * Inactive segments fail closed and are still recorded.
	 */
	public function test_inactive_read_fails_closed_and_records(): void {
		$this->adapter->set_group( 'archived', '1' );

		$this->assertFalse( $this->membership->is_in( 'archived' ) );
		$this->assertFalse( $this->membership->is_in( 12, '1' ) );
		$this->assertNull( $this->membership->value( 'archived' ) );
		$this->assertSame( array( 'archived' ), $this->membership->consulted() );
	}

	/**
	 * Unknown references record nothing and are a caller bug under WP_DEBUG.
	 */
	public function test_unknown_ref_is_in_records_nothing(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );

		$this->assertFalse( $this->membership->is_in( 'nope' ) );
		$this->assertSame( array(), $this->membership->consulted() );
	}

	/**
	 * Same rule for value().
	 */
	public function test_unknown_ref_value_records_nothing(): void {
		$this->setExpectedIncorrectUsage( 'get_reader_segment_value' );

		$this->assertNull( $this->membership->value( 404 ) );
		$this->assertSame( array(), $this->membership->consulted() );
	}

	/**
	 * The variant key covers only consulted keys, with 'none' for absent.
	 */
	public function test_variant_key_includes_none(): void {
		$this->membership->assign( 'subscriber' );
		$this->membership->assign( 'newsletter' );

		$this->membership->is_in( 'subscriber' );
		$this->membership->is_in( 'archived' );

		$this->assertSame(
			array(
				'archived'   => 'none',
				'subscriber' => '1',
			),
			$this->membership->variant_key()
		);
		$this->assertSame(
			array(
				'newsletter' => '1',
				'donor'      => 'none',
			),
			$this->membership->variant_key( array( 'newsletter', 'donor' ) ),
			'An explicit key list projects onto that list.'
		);
		$this->assertSame( array(), ( new Membership( new Local_Cookie_Adapter(), $this->source, new Consulted_Set() ) )->variant_key() );
	}

	/**
	 * Membership never bypasses the adapter: a value the adapter holds that
	 * matches no segment is invisible.
	 */
	public function test_membership_reads_only_through_adapter(): void {
		$this->adapter->register_group( 'stray' );
		$this->adapter->set_group( 'stray', '1' );

		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );
		$this->assertFalse( $this->membership->is_in( 'stray' ) );
		$this->assertSame( array( 'stray' => '1' ), $this->membership->membership(), 'membership() reports raw adapter state.' );
	}

	/**
	 * A cookie above the threshold triggers a warning under WP_DEBUG.
	 */
	public function test_check_cookie_size_warns_when_large(): void {
		if ( ! defined( 'WP_DEBUG' ) || true !== constant( 'WP_DEBUG' ) ) {
			$this->markTestSkipped( 'WP_DEBUG is off; the cookie size warning is debug-only.' );
		}

		$this->membership->assign( 'subscriber', str_repeat( 'a', Membership::COOKIE_WARN_BYTES ) );

		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the warning under test.
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = array( $errno, $errstr );
				return true;
			},
			E_USER_WARNING
		);

		try {
			$this->membership->check_cookie_size();
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $warnings );
		$this->assertSame( E_USER_WARNING, $warnings[0][0] );
		$this->assertStringContainsString( 'segmentation cookie', $warnings[0][1] );
	}

	/**
	 * Small cookies do not warn.
	 */
	public function test_check_cookie_size_is_quiet_when_small(): void {
		$this->membership->assign( 'subscriber' );

		$warnings = 0;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the warning under test.
			static function () use ( &$warnings ): bool {
				++$warnings;
				return true;
			},
			E_USER_WARNING
		);

		try {
			$this->membership->check_cookie_size();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 0, $warnings );
	}
}
