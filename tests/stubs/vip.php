<?php
/** Static-analysis signatures only; never loaded at runtime. */
namespace Automattic\VIP\Cache;
class Vary_Cache {
	public static function register_group( string $group ): bool {}
	public static function is_user_in_group_segment( string $group, string $value ): bool {}
	/** @return bool|\WP_Error */
	public static function set_group_for_user( string $group, string $value ) {}
	/** @return array<string,string> */
	public static function get_groups(): array {}
	public static function parse_cookies(): void {}
	public static function unload(): void {}
	public static function load(): void {}
	public static function send_headers(): void {}
}
