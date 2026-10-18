<?php
/**
 * Explicit injected fake; never loaded by the plugin.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates\Tests\Unit;

use ContentGates\Cache\Transport;

/** Deterministic membership transport, not a cookie implementation. */
final class Fake_Transport implements Transport {
	/**
	 * Values.
	 *
	 * @var array<string,bool>
	 */
	public array $values = array();
	/** Available only in explicit injection. */
	public function available(): bool {
		return true; }
	/**
	 * Register a group.
	 *
	 * @param string $group Group name.
	 */
	public function register( string $group ): bool {
		return true; }
	/**
	 * Read a value.
	 *
	 * @param string $group Group name.
	 */
	public function contains( string $group ): bool {
		return $this->values[ $group ] ?? false; }
	/**
	 * Set a value.
	 *
	 * @param string $group Group name.
	 * @param bool   $member Value.
	 * @return true
	 */
	public function assign( string $group, bool $member ) {
		$this->values[ $group ] = $member;
		return true; }
	/** No cookie output in the fake. */
	public function send(): void {}
}
