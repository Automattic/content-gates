<?php
/**
 * Public contract function tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Plugin;
use ContentGates\Segments\Segment_Registry;
use WP_UnitTestCase;

/**
 * The five public functions delegate to the booted singleton.
 *
 * These tests run against the plugin's own `Membership` and adapter, as a
 * provider's PHP would. They never flush the adapter and never fire
 * `send_headers`: either would mark the shared adapter "too late" for
 * every later test in the process.
 */
final class Test_Functions extends WP_UnitTestCase {

	/**
	 * The booted plugin's registry.
	 *
	 * @var Segment_Registry
	 */
	private Segment_Registry $registry;

	/**
	 * The booted plugin's membership layer.
	 *
	 * @var Membership
	 */
	private Membership $membership;

	/**
	 * The booted plugin's adapter.
	 *
	 * @var Local_Cookie_Adapter
	 */
	private Local_Cookie_Adapter $adapter;

	/**
	 * Active CPT segment created for the test.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Key of that segment.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Use the singleton, with a fresh CPT segment and a clean consulted set.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->registry   = Plugin::instance()->segments();
		$this->membership = Plugin::instance()->membership();
		$this->registry->invalidate_cache();
		$this->membership->consulted_set()->reset();

		$adapter = $this->membership->cache();
		$this->assertInstanceOf( Local_Cookie_Adapter::class, $adapter, 'Tests run without Vary_Cache.' );
		$this->adapter = $adapter;
		$this->assertFalse( $this->membership->is_too_late(), 'Another test flushed the shared adapter or fired send_headers.' );

		$id = $this->registry->create( 'Functions Segment' );
		$this->assertIsInt( $id );
		$this->id = $id;

		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->key = $segment->key();
	}

	/**
	 * Leave the singleton as found: no membership, no reads, no stale posts.
	 */
	public function tear_down(): void {
		remove_reader_from_segment( $this->id );
		$this->membership->consulted_set()->reset();
		$this->registry->invalidate_cache();

		parent::tear_down();
	}

	/**
	 * A key that no other test in the process could have registered.
	 */
	private function unique_key(): string {
		return 'tf_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * A code segment can be registered once.
	 */
	public function test_register_segment_once(): void {
		$key = $this->unique_key();

		$this->assertTrue( register_segment( $key ) );

		$segment = $this->registry->find( $key );
		$this->assertNotNull( $segment );
		$this->assertTrue( $segment->is_registered_in_code() );
		$this->assertTrue( $segment->is_active() );

		$result = register_segment( $key );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_duplicate_segment_key', $result->get_error_code() );

		$result = register_segment( $this->key );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_duplicate_segment_key', $result->get_error_code(), 'A code key may not shadow a CPT key.' );

		$result = register_segment( 'bad key' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment_key', $result->get_error_code() );
	}

	/**
	 * Assignment is visible in the same request before any cookie is written.
	 */
	public function test_assign_then_read_in_same_request(): void {
		$this->assertFalse( is_reader_in_segment( $this->id ) );
		$this->assertSame( array( $this->key ), $this->membership->consulted(), 'A false read is recorded.' );

		$this->assertTrue( assign_reader_to_segment( $this->id ) );

		$this->assertTrue( is_reader_in_segment( $this->id ) );
		$this->assertTrue( is_reader_in_segment( $this->key ) );
		$this->assertSame( '1', get_reader_segment_value( $this->key ) );
		$this->assertSame( '1', $this->adapter->groups()[ $this->key ] ?? null, 'The adapter holds the value in memory.' );
		$this->assertSame( array( $this->key ), $this->membership->consulted() );

		$this->assertNull( $this->adapter->pending_cookie(), 'No cookie is written until send_headers.' );
		$this->assertFalse( $this->adapter->did_send_headers() );
		$this->assertFalse( $this->membership->is_too_late() );
	}

	/**
	 * Values round-trip and exact-value checks work.
	 */
	public function test_assign_with_value(): void {
		$this->assertTrue( assign_reader_to_segment( $this->key, 'gold' ) );

		$this->assertTrue( is_reader_in_segment( $this->id, 'gold' ) );
		$this->assertFalse( is_reader_in_segment( $this->id, 'silver' ) );
		$this->assertSame( 'gold', get_reader_segment_value( $this->id ) );
	}

	/**
	 * Removal takes effect in the same request.
	 */
	public function test_remove_reader_from_segment(): void {
		$this->assertTrue( assign_reader_to_segment( $this->id ) );
		$this->assertTrue( remove_reader_from_segment( $this->id ) );

		$this->assertFalse( is_reader_in_segment( $this->id ) );
		$this->assertNull( get_reader_segment_value( $this->id ) );
		$this->assertSame( array(), $this->membership->membership() );
		$this->assertArrayHasKey( $this->key, $this->adapter->groups(), 'The group stays registered.' );
	}

	/**
	 * Unknown segments read false, record nothing, and are a caller bug.
	 */
	public function test_is_reader_in_unknown_segment(): void {
		$this->setExpectedIncorrectUsage( 'is_reader_in_segment' );

		$this->assertFalse( is_reader_in_segment( 'nope' ) );
		$this->assertSame( array(), $this->membership->consulted() );
	}

	/**
	 * Same for get_reader_segment_value().
	 */
	public function test_get_unknown_segment_value(): void {
		$this->setExpectedIncorrectUsage( 'get_reader_segment_value' );

		$this->assertNull( get_reader_segment_value( 999999 ) );
		$this->assertSame( array(), $this->membership->consulted() );
	}

	/**
	 * Write errors surface with the membership layer's codes.
	 */
	public function test_write_error_codes(): void {
		$result = assign_reader_to_segment( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );

		$result = remove_reader_from_segment( 999999 );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );

		$result = assign_reader_to_segment( $this->id, 'bad value' );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_invalid_value', $result->get_error_code() );

		$this->assertTrue( $this->registry->set_active( $this->id, false ) );
		$result = assign_reader_to_segment( $this->id );
		$this->assertWPError( $result );
		$this->assertSame( 'content_gates_inactive_segment', $result->get_error_code() );
		$this->assertFalse( is_reader_in_segment( $this->id ), 'Inactive segments fail closed.' );
		$this->assertSame( array( $this->key ), $this->membership->consulted(), 'The inactive read is still recorded.' );
	}

	/**
	 * The helper exposes the booted membership layer.
	 */
	public function test_content_gates_membership_returns_booted_layer(): void {
		$this->assertSame( $this->membership, content_gates_membership() );
	}
}
