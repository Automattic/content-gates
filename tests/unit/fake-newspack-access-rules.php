<?php
/**
 * Minimal stand-in for Newspack\Access_Rules.
 *
 * Mirrors the three static methods the bridge calls, including the real
 * class's behavior of evaluating an unregistered rule as true.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Newspack;

/**
 * Fake access rules registry.
 */
final class Access_Rules {

	/**
	 * Registered rules by ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $rules = array();

	/**
	 * Forget every rule.
	 */
	public static function reset(): void {
		self::$rules = array();
	}

	/**
	 * Register a rule.
	 *
	 * @param array<string, mixed> $config Rule configuration with `id` and `callback`.
	 */
	public static function register_rule( array $config ): void {
		self::$rules[ (string) $config['id'] ] = $config;
	}

	/**
	 * Fetch a rule.
	 *
	 * @param string $slug Rule ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_rule( string $slug ): ?array {
		return self::$rules[ $slug ] ?? null;
	}

	/**
	 * Evaluate a rule. Unknown rules evaluate to true, as in Newspack.
	 *
	 * @param string $slug    Rule ID.
	 * @param mixed  $args    Configured value.
	 * @param int    $user_id User ID.
	 */
	public static function evaluate_rule( string $slug, mixed $args, int $user_id ): bool {
		$rule = self::$rules[ $slug ] ?? null;
		if ( null === $rule || ! is_callable( $rule['callback'] ?? null ) ) {
			return true;
		}

		return (bool) call_user_func( $rule['callback'], $user_id, $args );
	}
}
