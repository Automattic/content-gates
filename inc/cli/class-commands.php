<?php
/**
 * WP-CLI commands.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\CLI;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Plugin;
use Automattic\VIP\ContentGates\Segments\Segment;
use WP_CLI;

/**
 * `wp content-gates ...`: manage segments and inspect posts from the shell.
 *
 * The same operations the Settings page offers, for scripts, seeding, and
 * agents working in a terminal.
 *
 * @since 0.1.0
 */
final class Commands {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register with WP-CLI.
	 *
	 * @since 0.1.0
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'content-gates', new self( $plugin ) );
	}

	/**
	 * List segments.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-gates list
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function list_segments( array $args, array $assoc_args ): void {
		unset( $args );

		$this->plugin->segments()->maybe_sync_crawlers();

		$items = array();
		foreach ( $this->plugin->segments()->all() as $segment ) {
			$row           = $segment->to_array();
			$row['status'] = $segment->active ? 'active' : 'inactive';
			$items[]       = $row;
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$items,
			array( 'id', 'key', 'name', 'type', 'status', 'limit', 'period', 'crawler', 'group' )
		);
	}

	/**
	 * Create a segment.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Display name. The key is derived from it once and never changes.
	 *
	 * [--type=<type>]
	 * : boolean or meter.
	 * ---
	 * default: boolean
	 * options:
	 *   - boolean
	 *   - meter
	 * ---
	 *
	 * [--limit=<n>]
	 * : Meter limit (articles per period).
	 *
	 * [--period-days=<n>]
	 * : Meter period in days.
	 *
	 * [--porcelain]
	 * : Print only the key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-gates create "Subscriber"
	 *     wp content-gates create "Metered articles" --type=meter --limit=5 --period-days=30
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$type   = Segment::TYPE_METER === ( $assoc_args['type'] ?? '' ) ? Segment::TYPE_METER : Segment::TYPE_BOOLEAN;
		$limit  = (int) ( $assoc_args['limit'] ?? 0 );
		$period = (int) ( $assoc_args['period-days'] ?? 0 ) * DAY_IN_SECONDS;

		$result = $this->plugin->segments()->create( $args[0], $type, $limit, $period );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $result->key );
			return;
		}

		WP_CLI::success( sprintf( 'Created segment "%s" with key %s (id %d).', $result->name, $result->key, $result->id ) );
	}

	/**
	 * Rename a segment. The key does not change.
	 *
	 * ## OPTIONS
	 *
	 * <segment>
	 * : Segment key or post ID.
	 *
	 * <name>
	 * : New display name.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function rename( array $args ): void {
		$result = $this->plugin->segments()->rename( $args[0], $args[1] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Renamed %s to "%s".', $result->key, $result->name ) );
	}

	/**
	 * Activate a segment.
	 *
	 * ## OPTIONS
	 *
	 * <segment>
	 * : Segment key or post ID.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function activate( array $args ): void {
		$result = $this->plugin->segments()->set_active( $args[0], true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Activated %s.', $result->key ) );
	}

	/**
	 * Deactivate a segment. Assignments are kept; reads of it return false.
	 *
	 * ## OPTIONS
	 *
	 * <segment>
	 * : Segment key or post ID.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function deactivate( array $args ): void {
		$result = $this->plugin->segments()->set_active( $args[0], false );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Deactivated %s.', $result->key ) );
	}

	/**
	 * Change a meter's limit and period.
	 *
	 * ## OPTIONS
	 *
	 * <segment>
	 * : Segment key or post ID.
	 *
	 * --limit=<n>
	 * : Articles per period.
	 *
	 * --period-days=<n>
	 * : Period in days.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function meter( array $args, array $assoc_args ): void {
		$result = $this->plugin->segments()->update_meter( $args[0], (int) ( $assoc_args['limit'] ?? 0 ), (int) ( $assoc_args['period-days'] ?? 0 ) * DAY_IN_SECONDS );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Meter %s: %d per %d seconds.', $result->key, $result->limit, $result->period ) );
	}

	/**
	 * Show the declared set stored for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID.
	 *
	 * [--recompute]
	 * : Recompute from the saved blocks and store it.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function declared( array $args, array $assoc_args ): void {
		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof \WP_Post ) {
			WP_CLI::error( 'Unknown post.' );
		}

		if ( isset( $assoc_args['recompute'] ) ) {
			$this->plugin->content_meta()->store( $post->ID, $post );
		}

		$keys = Declared_Set::stored_keys( $post->ID );
		WP_CLI::line( array() === $keys ? '(none)' : implode( ', ', $keys ) );
	}

	/**
	 * Mirror config/crawlers.json into segment records now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-gates sync-crawlers
	 *
	 * @subcommand sync-crawlers
	 */
	public function sync_crawlers(): void {
		$changed = $this->plugin->segments()->sync_crawlers();
		WP_CLI::success( array() === $changed ? 'Crawler segments already match the file.' : 'Changed: ' . implode( ', ', $changed ) );
	}
}
