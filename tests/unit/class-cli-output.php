<?php
/**
 * Output recorded by the WP_CLI stand-in.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

/**
 * What the commands would have printed. The fake `WP_CLI` in
 * `fake-wp-cli.php` forwards here; tests read and reset it.
 */
final class CLI_Output {

	/**
	 * Lines in print order; success lines are prefixed `Success: `.
	 *
	 * @var string[]
	 */
	public static array $lines = array();

	/**
	 * The last format_items() call: format, rows, and columns.
	 *
	 * @var array{format: string, items: array<int, array<string, mixed>>, fields: string[]}|null
	 */
	public static ?array $formatted = null;

	/**
	 * Forget everything recorded.
	 */
	public static function reset(): void {
		self::$lines     = array();
		self::$formatted = null;
	}
}
