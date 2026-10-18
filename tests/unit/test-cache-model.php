<?php
/**
 * Per-response predicate simulation, not a deployed edge cache.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Models each stored response's own dependency predicate. */
final class Test_Cache_Model extends TestCase {
	/**
	 * Lookup fails on ambiguous matching bodies instead of choosing one.
	 *
	 * @param list<array{key:array<string,string>,body:string}> $entries Responses.
	 * @param array<string,string>                              $reader Current values.
	 * @return string|null
	 */
	private function lookup( array $entries, array $reader ): ?string {
		$matches = array();
		foreach ( $entries as $entry ) {
			$match = true;
			foreach ( $entry['key'] as $name => $value ) {
				if ( ( $reader[ $name ] ?? 'none' ) !== $value ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				$matches[ $entry['body'] ] = true;
			}
		}
		return 1 === count( $matches ) ? (string) array_key_first( $matches ) : null;
	}

	/** Negative predicates and conditional sets remain distinct in either order. */
	public function test_conditional_sets_and_irrelevant_memberships(): void {
		$entries = array(
			array(
				'key'  => array( 'subscriber' => '1' ),
				'body' => 'full',
			),
			array(
				'key'  => array(
					'subscriber' => 'none',
					'newsletter' => '1',
				),
				'body' => 'newsletter',
			),
			array(
				'key'  => array(
					'subscriber' => 'none',
					'newsletter' => 'none',
				),
				'body' => 'signup',
			),
		);
		foreach ( array( $entries, array_reverse( $entries ) ) as $order ) {
			$this->assertSame( 'full', $this->lookup( $order, array( 'subscriber' => '1' ) ) );
			$this->assertSame(
				'full',
				$this->lookup(
					$order,
					array(
						'subscriber' => '1',
						'newsletter' => '1',
						'donor'      => '1',
					)
				)
			);
			$this->assertSame(
				'newsletter',
				$this->lookup(
					$order,
					array(
						'newsletter' => '1',
						'donor'      => '1',
					)
				)
			);
			$this->assertSame( 'signup', $this->lookup( $order, array( 'donor' => '1' ) ) );
		}
	}

	/** No-read pages match everyone; stale/conflicting predicates force a miss. */
	public function test_empty_sets_ambiguity_and_invalidation(): void {
		$entries = array(
			array(
				'key'  => array(),
				'body' => 'public',
			),
		);
		$this->assertSame( 'public', $this->lookup( $entries, array( 'subscriber' => '1' ) ) );
		$entries[] = array(
			'key'  => array( 'subscriber' => '1' ),
			'body' => 'stale',
		);
		$this->assertNull( $this->lookup( $entries, array( 'subscriber' => '1' ) ) );
		$this->assertNull( $this->lookup( array(), array( 'subscriber' => '1' ) ) );
	}
}
