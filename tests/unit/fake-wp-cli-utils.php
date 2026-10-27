<?php
/**
 * WP_CLI\Utils stand-in: records formatted items instead of printing a table.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace WP_CLI\Utils;

use Automattic\VIP\ContentGates\Tests\Unit\CLI_Output;

if ( function_exists( __NAMESPACE__ . '\\format_items' ) ) {
	return;
}

/**
 * Record the table a command would print.
 *
 * @param string                           $format Output format.
 * @param array<int, array<string, mixed>> $items  Rows.
 * @param string[]                         $fields Columns.
 */
function format_items( string $format, array $items, array $fields ): void {
	CLI_Output::$formatted = array(
		'format' => $format,
		'items'  => $items,
		'fields' => $fields,
	);
}
