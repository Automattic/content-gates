<?php
/**
 * A WP_CLI stand-in that records output instead of printing or exiting.
 *
 * PHPUnit runs without WP-CLI. The plugin's command class calls these
 * static methods only, so the fake lets tests drive the commands and read
 * what they would have printed through `CLI_Output`. Loaded by
 * Test_Commands; PHPStan analyzes the real signatures from
 * tests/phpstan/wp-cli-stubs.php instead.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

use Automattic\VIP\ContentGates\Tests\Unit\CLI_Output;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Stands in for the WP_CLI class itself.

if ( class_exists( 'WP_CLI', false ) ) {
	return;
}

/**
 * Recording WP_CLI.
 */
final class WP_CLI {

	/**
	 * Record a success line.
	 *
	 * @param string $message Message.
	 */
	public static function success( string $message ): void {
		CLI_Output::$lines[] = 'Success: ' . $message;
	}

	/**
	 * Record a plain line.
	 *
	 * @param string $message Message.
	 */
	public static function line( string $message = '' ): void {
		CLI_Output::$lines[] = $message;
	}

	/**
	 * Record a log line.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		CLI_Output::$lines[] = $message;
	}

	/**
	 * The real error() prints and exits; the fake throws so a test can expect it.
	 *
	 * @param string $message Message.
	 * @throws RuntimeException Always.
	 */
	public static function error( string $message ): void {
		throw new RuntimeException( $message );
	}

	/**
	 * Commands register themselves here; nothing to do without a runner.
	 *
	 * @param string $name    Command name.
	 * @param mixed  $command Command object.
	 */
	public static function add_command( string $name, mixed $command ): void {
		unset( $name, $command );
	}
}
