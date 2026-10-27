<?php
/**
 * Segment storage and lookup.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Segments;

use WP_Error;
use WP_Post;

/**
 * Stores segments as `cg_segment` posts and resolves references to them.
 *
 * Rules enforced here, from spec/decisions.md:
 *
 * - `publish` is active, `draft` is inactive. Any other status is coerced.
 * - The key is `post_name`, derived once from the initial name, and never
 *   changes afterwards, whatever path updates the post.
 * - Segments are never trashed or deleted. Trash and delete are refused for
 *   every user, through capabilities and through the pre-trash and pre-delete
 *   short circuits, so REST and `wp_delete_post()` are covered as well.
 * - Every other mutation needs `manage_options`. The post type maps its
 *   primitive capabilities there, so generic post capabilities never grant
 *   access on any WordPress path.
 * - Activation, deactivation, and meter changes are announced from the
 *   status transition and meta update hooks, so a change made through any
 *   path, not only this class, requests the global purge.
 * - Crawler segments from `config/crawlers.json` are mirrored into reserved,
 *   read-only records. Removal from the file deactivates the record.
 * - The group name is the key on a single site and `{blog_id}-{key}` on
 *   multisite. Crawler segments are network-wide and never prefixed.
 *
 * Segments are read once per request from the persistent object cache.
 *
 * @since 0.1.0
 */
final class Segment_Registry {

	public const POST_TYPE   = 'cg_segment';
	public const CACHE_GROUP = 'content_gates';

	public const META_TYPE    = '_content_gates_type';
	public const META_LIMIT   = '_content_gates_limit';
	public const META_PERIOD  = '_content_gates_period';
	public const META_CRAWLER = '_content_gates_crawler';

	public const OPTION_CRAWLERS_VERSION = 'content_gates_crawlers_version';

	/**
	 * Per-request memo of every segment, keyed by key.
	 *
	 * @var array<string, Segment>|null
	 */
	private ?array $segments = null;

	/**
	 * Constructor.
	 *
	 * @param Crawler_Config $crawlers Crawler configuration.
	 */
	public function __construct( private Crawler_Config $crawlers ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ), 0 );
		add_action( 'init', array( $this, 'maybe_sync_crawlers' ), 1 );
		add_filter( 'wp_insert_post_data', array( $this, 'protect_post_data' ), 10, 3 );
		add_filter( 'pre_trash_post', array( $this, 'refuse_trash_or_delete' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'refuse_trash_or_delete' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'announce_status_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'announce_meter_change' ), 10, 3 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'flush' ) );
		add_action( 'clean_post_cache', array( $this, 'flush_for_post' ), 10, 2 );
	}

	/**
	 * Register the segment post type.
	 *
	 * @since 0.1.0
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Segments', 'content-gates' ),
					'singular_name' => __( 'Segment', 'content-gates' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_menu'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'cg_segment',
				'map_meta_cap'        => true,
				'capabilities'        => self::capabilities(),
			)
		);
	}

	/**
	 * Primitive capabilities of the post type.
	 *
	 * Segment configuration is administrative state, so every mutation needs
	 * `manage_options` and deletion is impossible for everyone. With
	 * `map_meta_cap`, core maps `edit_post`, `publish_post`, and
	 * `delete_post` on a segment to these primitives, so XML-RPC, wp-admin,
	 * REST, and any other path that checks post capabilities refuses an
	 * editor. Generic post capabilities are never enough.
	 *
	 * @return array<string, string>
	 */
	private static function capabilities(): array {
		return array(
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'publish_posts'          => 'manage_options',
			'create_posts'           => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'do_not_allow',
			'delete_others_posts'    => 'do_not_allow',
			'delete_published_posts' => 'do_not_allow',
			'delete_private_posts'   => 'do_not_allow',
		);
	}

	/**
	 * Every segment, active and inactive, keyed by key.
	 *
	 * The order is the one every list shows: by type (`boolean`, `crawler`,
	 * `meter`), then by key. See `Segment::sort_type()`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Segment>
	 */
	public function all(): array {
		if ( null !== $this->segments ) {
			return $this->segments;
		}

		$cache_key = 'segments:' . get_current_blog_id();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$this->segments = $this->hydrate( $cached );
			return $this->segments;
		}

		$rows  = array();
		$paged = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => array( 'publish', 'draft' ),
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$rows[] = $this->row_from_post( $post );
			}
			++$paged;
			$page_size = count( $posts );
		} while ( 100 === $page_size );

		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP );
		$this->segments = $this->hydrate( $rows );

		return $this->segments;
	}

	/**
	 * Order two segments by type, then by key.
	 *
	 * @since 0.1.0
	 *
	 * @param Segment $a First.
	 * @param Segment $b Second.
	 * @return int Negative when `$a` sorts first.
	 */
	public static function compare( Segment $a, Segment $b ): int {
		return array( $a->sort_type(), $a->key ) <=> array( $b->sort_type(), $b->key );
	}

	/**
	 * Active segments only, keyed by key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Segment>
	 */
	public function active(): array {
		return array_filter( $this->all(), static fn( Segment $segment ): bool => $segment->active );
	}

	/**
	 * Resolve a key or post ID to a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @return Segment|null Null when nothing matches.
	 */
	public function resolve( int|string $reference ): ?Segment {
		$all = $this->all();

		if ( is_int( $reference ) || 1 === preg_match( '/^[1-9][0-9]*$/', $reference ) ) {
			$id = (int) $reference;
			foreach ( $all as $segment ) {
				if ( $segment->id === $id ) {
					return $segment;
				}
			}
			return null;
		}

		return $all[ $reference ] ?? null;
	}

	/**
	 * Resolve a `Vary_Cache` group name back to its segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Group name.
	 */
	public function resolve_group( string $group ): ?Segment {
		foreach ( $this->all() as $segment ) {
			if ( $segment->group_name === $group ) {
				return $segment;
			}
		}

		return null;
	}

	/**
	 * Create a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   Display name. The key is derived from it once.
	 * @param string $type   `boolean` or `meter`.
	 * @param int    $limit  Meter limit; ignored for boolean segments.
	 * @param int    $period Meter period in seconds; ignored for boolean segments.
	 * @return Segment|WP_Error
	 */
	public function create( string $name, string $type = Segment::TYPE_BOOLEAN, int $limit = 0, int $period = 0 ): Segment|WP_Error {
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_invalid_name', __( 'A segment needs a name.', 'content-gates' ) );
		}
		if ( ! in_array( $type, array( Segment::TYPE_BOOLEAN, Segment::TYPE_METER ), true ) ) {
			return new WP_Error( 'content_gates_invalid_type', __( 'Segment type must be boolean or meter.', 'content-gates' ) );
		}
		if ( Segment::TYPE_METER === $type && ( $limit < 1 || $period < 1 ) ) {
			return new WP_Error( 'content_gates_invalid_meter', __( 'A meter needs a limit of at least 1 and a period of at least 1 second.', 'content-gates' ) );
		}

		$key = $this->generate_key( $name );

		return $this->insert( $name, $key, true, $type, $limit, $period, false );
	}

	/**
	 * Rename a segment. The key does not change.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @param string     $name      New display name.
	 * @return Segment|WP_Error
	 */
	public function rename( int|string $reference, string $name ): Segment|WP_Error {
		$segment = $this->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}
		if ( $segment->crawler ) {
			return new WP_Error( 'content_gates_read_only_segment', __( 'Crawler segments are maintained by VIP and cannot be edited.', 'content-gates' ) );
		}
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'content_gates_invalid_name', __( 'A segment needs a name.', 'content-gates' ) );
		}

		$result = wp_update_post(
			array(
				'ID'         => $segment->id,
				'post_title' => $name,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->flush();
		$updated = $this->resolve( $segment->id );

		return $updated ?? new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
	}

	/**
	 * Activate or deactivate a segment.
	 *
	 * The status transition fires `content_gates_segment_changed`, which
	 * triggers a global purge: a site-wide configuration change invalidates
	 * every stored variant that declared the segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @param bool       $active    True to activate.
	 * @return Segment|WP_Error
	 */
	public function set_active( int|string $reference, bool $active ): Segment|WP_Error {
		$segment = $this->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}
		if ( $segment->crawler ) {
			return new WP_Error( 'content_gates_read_only_segment', __( 'Crawler segments are maintained by VIP and cannot be edited.', 'content-gates' ) );
		}
		if ( $segment->active === $active ) {
			return $segment;
		}

		$result = wp_update_post(
			array(
				'ID'          => $segment->id,
				'post_status' => $active ? 'publish' : 'draft',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->flush();
		$updated = $this->resolve( $segment->id );
		if ( null === $updated ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}

		return $updated;
	}

	/**
	 * Change a meter's limit or period.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $reference Segment key or post ID.
	 * @param int        $limit     Articles per period.
	 * @param int        $period    Period in seconds.
	 * @return Segment|WP_Error
	 */
	public function update_meter( int|string $reference, int $limit, int $period ): Segment|WP_Error {
		$segment = $this->resolve( $reference );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}
		if ( ! $segment->is_meter() ) {
			return new WP_Error( 'content_gates_invalid_type', __( 'Only meter segments have a limit and period.', 'content-gates' ) );
		}
		if ( $limit < 1 || $period < 1 ) {
			return new WP_Error( 'content_gates_invalid_meter', __( 'A meter needs a limit of at least 1 and a period of at least 1 second.', 'content-gates' ) );
		}
		if ( $segment->limit === $limit && $segment->period === $period ) {
			return $segment;
		}

		update_post_meta( $segment->id, self::META_LIMIT, $limit );
		update_post_meta( $segment->id, self::META_PERIOD, $period );
		$this->flush();

		$updated = $this->resolve( $segment->id );
		if ( null === $updated ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}

		return $updated;
	}

	/**
	 * Mirror `config/crawlers.json` into segment records when its version changed.
	 *
	 * Runs on admin and WP-CLI requests only, so a front-end request never
	 * writes posts. Tests call `sync_crawlers()` directly.
	 *
	 * @since 0.1.0
	 */
	public function maybe_sync_crawlers(): void {
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( (int) get_option( self::OPTION_CRAWLERS_VERSION, -1 ) === $this->crawlers->version() ) {
			return;
		}

		$this->sync_crawlers();
	}

	/**
	 * Create, relabel, or deactivate crawler segment records to match the file.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Keys that were created, relabeled, reactivated, or deactivated.
	 */
	public function sync_crawlers(): array {
		$changed    = array();
		$configured = $this->crawlers->segments();
		$existing   = $this->all();

		foreach ( $configured as $key => $config ) {
			$segment = $existing[ $key ] ?? null;
			if ( null === $segment ) {
				$result = $this->insert( $config['label'], $key, true, Segment::TYPE_BOOLEAN, 0, 0, true );
				if ( ! is_wp_error( $result ) ) {
					$changed[] = $key;
				}
				continue;
			}
			if ( ! $segment->crawler ) {
				// A customer segment already owns this key. Leave it alone and let the admin notice explain.
				continue;
			}
			if ( $segment->name !== $config['label'] || ! $segment->active ) {
				wp_update_post(
					array(
						'ID'          => $segment->id,
						'post_title'  => $config['label'],
						'post_status' => 'publish',
					)
				);
				$changed[] = $key;
			}
		}

		foreach ( $existing as $key => $segment ) {
			if ( $segment->crawler && $segment->active && ! isset( $configured[ $key ] ) ) {
				wp_update_post(
					array(
						'ID'          => $segment->id,
						'post_status' => 'draft',
					)
				);
				$changed[] = $key;
			}
		}

		update_option( self::OPTION_CRAWLERS_VERSION, $this->crawlers->version(), false );
		$this->flush();

		if ( array() !== $changed ) {
			/**
			 * Fires after the crawler segment records were synchronized with config/crawlers.json.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $changed Keys that changed.
			 */
			do_action( 'content_gates_crawlers_synced', $changed );
		}

		return $changed;
	}

	/**
	 * Keys of the active crawler segments.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function crawler_keys(): array {
		$keys = array();
		foreach ( $this->active() as $segment ) {
			if ( $segment->crawler ) {
				$keys[] = $segment->key;
			}
		}

		return $keys;
	}

	/**
	 * Group name for a key on this site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     Segment key.
	 * @param bool   $crawler Whether the segment is a crawler segment.
	 */
	public function group_name_for( string $key, bool $crawler ): string {
		if ( $crawler || ! is_multisite() ) {
			return $key;
		}

		return get_current_blog_id() . '-' . $key;
	}

	/**
	 * Keep keys immutable and statuses within publish/draft.
	 *
	 * Filter callback for `wp_insert_post_data`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data    Slashed post data about to be written.
	 * @param array<string, mixed> $postarr Slashed post data as passed in.
	 * @param array<string, mixed> $unsanitized_postarr Unsanitized post data.
	 * @return array<string, mixed>
	 */
	public function protect_post_data( array $data, array $postarr, array $unsanitized_postarr ): array {
		unset( $unsanitized_postarr );

		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$status = (string) ( $data['post_status'] ?? '' );
		if ( 'publish' !== $status ) {
			$data['post_status'] = 'draft';
		}

		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id > 0 ) {
			$stored = get_post_field( 'post_name', $id, 'raw' );
			if ( '' !== $stored ) {
				$data['post_name'] = $stored;
			}
		}

		return $data;
	}

	/**
	 * Refuse to trash or delete a segment.
	 *
	 * Filter callback for `pre_trash_post` and `pre_delete_post`. Returning
	 * `false` makes `wp_trash_post()` and `wp_delete_post()` return false.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $check Short-circuit value.
	 * @param WP_Post   $post  Post being trashed or deleted.
	 * @return bool|null
	 */
	public function refuse_trash_or_delete( ?bool $check, WP_Post $post ): ?bool {
		if ( self::POST_TYPE === $post->post_type ) {
			return false;
		}

		return $check;
	}

	/**
	 * Announce an activation or deactivation, whatever path saved it.
	 *
	 * Action callback for `transition_post_status`. Hooking the transition
	 * rather than `set_active()` means a status change through
	 * `wp_update_post()`, XML-RPC, or any other supported path fires the
	 * same configuration-change event and purge. Creation (`new` to
	 * `publish` or `draft`) is announced by `create()` as `created`.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function announce_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		if ( in_array( $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		$this->flush();
		$segment = $this->resolve( $post->ID );
		if ( null === $segment ) {
			return;
		}

		$this->announce_change( $segment, 'publish' === $new_status ? 'activated' : 'deactivated' );
	}

	/**
	 * Announce a meter limit or period change, whatever path wrote it.
	 *
	 * Action callback for `updated_post_meta`. `update_meter()` writes two
	 * meta values; `Cache_Purge` collapses the two events into one purge.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $meta_id  Meta row ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	public function announce_meter_change( int $meta_id, int $post_id, string $meta_key ): void {
		unset( $meta_id );

		if ( ! in_array( $meta_key, array( self::META_LIMIT, self::META_PERIOD ), true ) ) {
			return;
		}
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$this->flush();
		$segment = $this->resolve( $post_id );
		if ( null === $segment ) {
			return;
		}

		$this->announce_change( $segment, 'reconfigured' );
	}

	/**
	 * Drop the object-cache entry and the request memo.
	 *
	 * @since 0.1.0
	 */
	public function flush(): void {
		$this->segments = null;
		wp_cache_delete( 'segments:' . get_current_blog_id(), self::CACHE_GROUP );
	}

	/**
	 * Flush when a segment post's cache is cleaned.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function flush_for_post( int $post_id, WP_Post $post ): void {
		unset( $post_id );

		if ( self::POST_TYPE === $post->post_type ) {
			$this->flush();
		}
	}

	/**
	 * Derive a unique key from a display name.
	 *
	 * `sanitize_title()` first, then anything outside `[A-Za-z0-9_-]` becomes
	 * `-`, trimmed, cut to 16 characters, then made unique with a numeric
	 * suffix. Falls back to `segment`.
	 *
	 * @param string $name Display name.
	 */
	private function generate_key( string $name ): string {
		$base = (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', sanitize_title( $name ) );
		$base = trim( $base, '-' );
		if ( '' === $base ) {
			$base = 'segment';
		}
		$base = substr( $base, 0, Segment::KEY_MAX_LENGTH );
		$base = rtrim( $base, '-' );

		$taken = array_keys( $this->all() );
		foreach ( array_keys( $this->crawlers->segments() ) as $crawler_key ) {
			$taken[] = $crawler_key;
		}

		$key = $base;
		$n   = 2;
		while ( in_array( $key, $taken, true ) ) {
			$suffix = '-' . $n;
			$key    = substr( $base, 0, Segment::KEY_MAX_LENGTH - strlen( $suffix ) ) . $suffix;
			++$n;
		}

		return $key;
	}

	/**
	 * Insert a segment post.
	 *
	 * @param string $name    Display name.
	 * @param string $key     Key.
	 * @param bool   $active  Whether to publish.
	 * @param string $type    Segment type.
	 * @param int    $limit   Meter limit.
	 * @param int    $period  Meter period.
	 * @param bool   $crawler Whether this mirrors a crawler segment.
	 * @return Segment|WP_Error
	 */
	private function insert( string $name, string $key, bool $active, string $type, int $limit, int $period, bool $crawler ): Segment|WP_Error {
		$meta = array(
			self::META_TYPE => $type,
		);
		if ( Segment::TYPE_METER === $type ) {
			$meta[ self::META_LIMIT ]  = $limit;
			$meta[ self::META_PERIOD ] = $period;
		}
		if ( $crawler ) {
			$meta[ self::META_CRAWLER ] = '1';
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $name,
				'post_name'   => $key,
				'post_status' => $active ? 'publish' : 'draft',
				'meta_input'  => $meta,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->flush();
		$segment = $this->resolve( (int) $id );
		if ( null === $segment ) {
			return new WP_Error( 'content_gates_invalid_segment', __( 'Unknown segment.', 'content-gates' ) );
		}

		if ( ! $crawler ) {
			$this->announce_change( $segment, 'created' );
		}

		return $segment;
	}

	/**
	 * Fire the configuration-change action.
	 *
	 * @param Segment $segment Segment that changed.
	 * @param string  $change  One of created, activated, deactivated, reconfigured.
	 */
	private function announce_change( Segment $segment, string $change ): void {
		/**
		 * Fires after a segment's configuration changed.
		 *
		 * Site-wide changes (activation, deactivation, meter limit or period)
		 * need a purge across every URL and variant; an ordinary content-edit
		 * purge does not cover them. The plugin's default listener requests a
		 * global purge through `content_gates_purge_all`.
		 *
		 * @since 0.1.0
		 *
		 * @param Segment $segment The segment.
		 * @param string  $change  One of `created`, `activated`, `deactivated`, `reconfigured`.
		 */
		do_action( 'content_gates_segment_changed', $segment, $change );
	}

	/**
	 * Cacheable row for a post.
	 *
	 * @param WP_Post $post Segment post.
	 * @return array<string, mixed>
	 */
	private function row_from_post( WP_Post $post ): array {
		$type = get_post_meta( $post->ID, self::META_TYPE, true );

		return array(
			'id'      => $post->ID,
			'key'     => $post->post_name,
			'name'    => $post->post_title,
			'active'  => 'publish' === $post->post_status,
			'type'    => Segment::TYPE_METER === $type ? Segment::TYPE_METER : Segment::TYPE_BOOLEAN,
			'limit'   => (int) get_post_meta( $post->ID, self::META_LIMIT, true ),
			'period'  => (int) get_post_meta( $post->ID, self::META_PERIOD, true ),
			'crawler' => '1' === get_post_meta( $post->ID, self::META_CRAWLER, true ),
		);
	}

	/**
	 * Build segment objects from cached rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Cached rows.
	 * @return array<string, Segment>
	 */
	private function hydrate( array $rows ): array {
		$segments = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['key'];
			if ( '' === $key ) {
				continue;
			}
			$crawler          = (bool) $row['crawler'];
			$segments[ $key ] = new Segment(
				(int) $row['id'],
				$key,
				(string) $row['name'],
				(bool) $row['active'],
				(string) $row['type'],
				(int) $row['limit'],
				(int) $row['period'],
				$crawler,
				$this->group_name_for( $key, $crawler )
			);
		}
		uasort( $segments, array( self::class, 'compare' ) );

		return $segments;
	}
}
