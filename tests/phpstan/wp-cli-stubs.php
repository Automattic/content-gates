<?php
/**
 * Minimal WP-CLI stubs for PHPStan. Not loaded at runtime.
 *
 * The published php-stubs/wp-cli-stubs package conflicts with the WordPress 7
 * stubs this project pins, so the few symbols the plugin uses are declared here.
 *
 * phpcs:ignoreFile
 */

namespace {
	class WP_CLI {
		/**
		 * @param string          $name
		 * @param callable|object $callable
		 * @param array<mixed>    $args
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {
			return true;
		}

		public static function line( string $message = '' ): void {}

		public static function success( string $message ): void {}

		/**
		 * @return never
		 */
		public static function error( string $message, bool|int $exit = true ) {
			exit( 1 );
		}

		public static function warning( string $message ): void {}

		/**
		 * @param string              $command
		 * @param array<string, mixed> $options
		 * @return mixed
		 */
		public static function runcommand( $command, $options = array() ) {
			return null;
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                           $format
	 * @param array<int, array<string, mixed>> $items
	 * @param string[]|string                  $fields
	 */
	function format_items( $format, $items, $fields ): void {}
}
