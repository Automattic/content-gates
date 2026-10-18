<?php
/**
 * Consulted_Set tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Set;
use WP_UnitTestCase;

/**
 * The consulted set is sorted, unique, and freezes once.
 */
final class Test_Consulted_Set extends WP_UnitTestCase {

	/**
	 * Keys come back sorted and without duplicates.
	 */
	public function test_keys_are_sorted_and_unique(): void {
		$set = new Consulted_Set();
		$set->record( 'subscriber' );
		$set->record( 'newsletter' );
		$set->record( 'subscriber' );

		$this->assertSame( array( 'newsletter', 'subscriber' ), $set->keys() );
		$this->assertFalse( $set->is_frozen() );
	}

	/**
	 * Freezing snapshots the set; later reads stay in the live set only.
	 */
	public function test_freeze_keeps_snapshot_while_live_set_grows(): void {
		$set = new Consulted_Set();
		$set->record( 'subscriber' );

		$this->assertSame( array( 'subscriber' ), $set->freeze() );
		$this->assertTrue( $set->is_frozen() );

		$set->record( 'newsletter' );

		$this->assertSame( array( 'subscriber' ), $set->keys(), 'The finalized set is what was frozen.' );
		$this->assertSame( array( 'newsletter', 'subscriber' ), $set->live_keys() );
		$this->assertSame( array( 'subscriber' ), $set->freeze(), 'A second freeze keeps the first snapshot.' );
	}

	/**
	 * Reset clears keys and unfreezes.
	 */
	public function test_reset_clears_and_unfreezes(): void {
		$set = new Consulted_Set();
		$set->record( 'subscriber' );
		$set->freeze();
		$set->reset();

		$this->assertSame( array(), $set->keys() );
		$this->assertFalse( $set->is_frozen() );
	}
}
