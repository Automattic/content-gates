<?php
/**
 * Consulted segment finalization tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Segments;
use WP_UnitTestCase;

/**
 * Verifies exact, deterministic response dependencies.
 */
final class Test_Consulted_Segments extends WP_UnitTestCase {

	/**
	 * Finalization sorts, de-duplicates, filters, and fires once.
	 */
	public function test_finalize_freezes_exact_filtered_set(): void {
		$consulted = new Consulted_Segments();
		$calls     = 0;
		$reported  = array();

		$consulted->record( 'cg_1_20' );
		$consulted->record( 'cg_1_10' );
		$consulted->record( 'cg_1_20' );

		add_filter(
			'content_gate_consulted_segments',
			static function ( array $segments ): array {
				$segments[] = 'cg_1_30';
				$segments[] = 'invalid key';
				return $segments;
			}
		);
		add_action(
			'content_gate_finalize_variance',
			static function ( array $segments ) use ( &$calls, &$reported ): void {
				++$calls;
				$reported = $segments;
			}
		);

		$expected = array( 'cg_1_10', 'cg_1_20', 'cg_1_30' );
		$this->assertSame( $expected, $consulted->finalize() );
		$consulted->record( 'cg_1_40' );
		$this->assertSame( $expected, $consulted->finalize() );
		$this->assertSame( $expected, $reported );
		$this->assertSame( 1, $calls );
	}

	/**
	 * An unread response finalizes to an empty dependency set.
	 */
	public function test_empty_set_remains_empty(): void {
		$consulted = new Consulted_Segments();

		$this->assertSame( array(), $consulted->finalize() );
	}
}
