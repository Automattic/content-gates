<?php
/**
 * Segment registry.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Segments;

use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Stores and resolves segments.
 *
 * Two kinds of segment exist:
 *
 * - CPT segments are `cg_segment` posts managed at Settings → Content Gates.
 *   The post ID is the stable identifier; `post_name` is the cache group
 *   key, generated once at creation and never changed. `publish` means
 *   active, `draft` means inactive. They can never be trashed or deleted.
 * - Code-registered segments come from `register_key()`. They have no ID,
 *   are always active, and live only in memory for the current request.
 *
 * The registry validates inputs only. Authorization (`manage_options`) is
 * the caller's job; see `ContentGates\Admin\Segment_Admin`.
 */
final class Segment_Registry implements Segment_Source {

	/**
	 * Post type slug for CPT segments.
	 */
	public const POST_TYPE = 'cg_segment';

	/**
	 * Pattern every segment key must match.
	 */
	public const KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

	/**
	 * Key of the reserved crawler segment, assigned at the edge.
	 */
	public const CRAWLER_KEY = 'crawler';

	/**
	 * Fallback key when a name yields no usable characters.
	 */
	private const FALLBACK_KEY = 'segment';

	/**
	 * Page size for the CPT query. The VIP standard rejects unbounded
	 * queries, so `load()` pages until a short page is returned.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Code-registered segments keyed by key.
	 *
	 * @var array<string, Segment>
	 */
	private array $code_segments = array();

	/**
	 * Per-request cache of CPT segments keyed by key. Null means not loaded.
	 *
	 * @var array<string, Segment>|null
	 */
	private ?array $cpt_segments = null;

	/**
	 * Per-request index of CPT segments by post ID.
	 *
	 * @var array<int, Segment>
	 */
	private array $cpt_by_id = array();

	/**
	 * Constructor. Registers the reserved `crawler` segment.
	 */
	public function __construct() {
		$this->code_segments[ self::CRAWLER_KEY ] = new Segment( null, self::CRAWLER_KEY, self::CRAWLER_KEY, true );
	}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ), 0 );
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 2 );
		add_filter( 'pre_trash_post', array( $this, 'prevent_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'prevent_delete' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'invalidate_cache' ), 10, 0 );
		add_action( 'clean_post_cache', array( $this, 'invalidate_cache_for_post' ), 10, 2 );
	}

	/**
	 * Register the `cg_segment` post type.
	 *
	 * The type is hidden from every UI and from REST; the only management
	 * surface is the Content Gates settings page.
	 *
	 * @since 0.1.0
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Segments', 'content-gates' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
				'can_export'      => false,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Register a code-defined segment.
	 *
	 * Code segments have no post ID and are always active. They may not
	 * collide with another code key or with a CPT key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Segment key matching KEY_PATTERN.
	 * @return true|WP_Error `content_gates_invalid_segment_key` or
	 *                       `content_gates_duplicate_segment_key`.
	 */
	public function register_key( string $key ): bool|WP_Error {
		if ( ! self::is_valid_key( $key ) ) {
			return new WP_Error(
				'content_gates_invalid_segment_key',
				__( 'Segment keys may contain only letters, digits, hyphens, and underscores.', 'content-gates' ),
				array( 'key' => $key )
			);
		}

		if ( null !== $this->find( $key ) ) {
			return new WP_Error(
				'content_gates_duplicate_segment_key',
				__( 'A segment with this key already exists.', 'content-gates' ),
				array( 'key' => $key )
			);
		}

		$this->code_segments[ $key ] = new Segment( null, $key, $key, true );

		return true;
	}

	/**
	 * Find a segment by post ID (int) or key (string).
	 *
	 * Code-registered segments win over CPT segments with the same key.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $ref Segment reference.
	 * @return Segment|null Null when nothing matches.
	 */
	public function find( int|string $ref ): ?Segment {
		if ( is_int( $ref ) ) {
			$this->load();

			return $this->cpt_by_id[ $ref ] ?? null;
		}

		if ( isset( $this->code_segments[ $ref ] ) ) {
			return $this->code_segments[ $ref ];
		}

		$this->load();

		return $this->cpt_segments[ $ref ] ?? null;
	}

	/**
	 * All segments, active and inactive, keyed by key.
	 *
	 * CPT segments come first in title order, then code segments. A code
	 * segment replaces a CPT segment with the same key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Segment>
	 */
	public function all(): array {
		$this->load();

		return array_merge( (array) $this->cpt_segments, $this->code_segments );
	}

	/**
	 * Active segments only, keyed by key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Segment>
	 */
	public function active(): array {
		return array_filter(
			$this->all(),
			static fn ( Segment $segment ): bool => $segment->is_active()
		);
	}

	/**
	 * Create an active CPT segment.
	 *
	 * The key is derived from the name once and never changes afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Display name.
	 * @return int|WP_Error Post ID, or `content_gates_invalid_segment_name`
	 *                      / the `wp_insert_post` error.
	 */
	public function create( string $name ): int|WP_Error {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return self::invalid_name_error();
		}

		$result = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $name,
				'post_name'   => $this->generate_key( $name ),
				'post_status' => 'publish',
			),
			true
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$this->invalidate_cache();

		return (int) $result;
	}

	/**
	 * Rename a CPT segment. ID and key stay the same.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id   Post ID.
	 * @param string $name New display name.
	 * @return true|WP_Error `content_gates_invalid_segment`,
	 *                       `content_gates_invalid_segment_name`, or the
	 *                       `wp_update_post` error.
	 */
	public function rename( int $id, string $name ): bool|WP_Error {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return self::invalid_name_error();
		}

		if ( null === $this->find( $id ) ) {
			return self::invalid_segment_error( $id );
		}

		return $this->update(
			array(
				'ID'         => $id,
				'post_title' => $name,
			)
		);
	}

	/**
	 * Activate (`publish`) or deactivate (`draft`) a CPT segment.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $id     Post ID.
	 * @param bool $active True to activate.
	 * @return true|WP_Error `content_gates_invalid_segment` or the
	 *                       `wp_update_post` error.
	 */
	public function set_active( int $id, bool $active ): bool|WP_Error {
		if ( null === $this->find( $id ) ) {
			return self::invalid_segment_error( $id );
		}

		return $this->update(
			array(
				'ID'          => $id,
				'post_status' => $active ? 'publish' : 'draft',
			)
		);
	}

	/**
	 * Keep keys immutable and statuses within publish/draft.
	 *
	 * Runs on `wp_insert_post_data` for every post; acts only on
	 * `cg_segment`. On updates the stored `post_name` wins over whatever the
	 * caller passed, so renaming never changes the cache group. Any status
	 * other than `publish` or `draft` (including `trash`) becomes `draft`.
	 *
	 * Core renames a post to `<name>__trashed` in the database before this
	 * filter runs when `trash` is written directly, and records the original
	 * name in `_wp_desired_post_slug`; that original is restored here.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data    Sanitized post data about to be written.
	 * @param array<string, mixed> $postarr Raw post data, including `ID` on updates.
	 * @return array<string, mixed>
	 */
	public function filter_insert_post_data( array $data, array $postarr ): array {
		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		if ( ! in_array( $data['post_status'] ?? '', array( 'publish', 'draft' ), true ) ) {
			$data['post_status'] = 'draft';
		}

		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id <= 0 ) {
			return $data;
		}

		$original = get_post( $id );
		if ( ! $original instanceof WP_Post || self::POST_TYPE !== $original->post_type ) {
			return $data;
		}

		$key = $original->post_name;
		if ( str_ends_with( $key, '__trashed' ) ) {
			$desired = get_post_meta( $id, '_wp_desired_post_slug', true );
			$key     = is_string( $desired ) && '' !== $desired
				? $desired
				: substr( $key, 0, -strlen( '__trashed' ) );
			delete_post_meta( $id, '_wp_desired_post_slug' );
		}

		$data['post_name'] = $key;

		return $data;
	}

	/**
	 * Refuse to trash a segment.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed   $trash Short-circuit value; null means proceed.
	 * @param WP_Post $post  Post being trashed.
	 * @return mixed False for segments, the incoming value otherwise.
	 */
	public function prevent_trash( mixed $trash, WP_Post $post ): mixed {
		return self::POST_TYPE === $post->post_type ? false : $trash;
	}

	/**
	 * Refuse to delete a segment, including forced deletion.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed   $delete Short-circuit value; null means proceed.
	 * @param WP_Post $post   Post being deleted.
	 * @return mixed False for segments, the incoming value otherwise.
	 */
	public function prevent_delete( mixed $delete, WP_Post $post ): mixed {
		return self::POST_TYPE === $post->post_type ? false : $delete;
	}

	/**
	 * Deny `delete_post` on segments for every user.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]     $caps    Primitive capabilities required.
	 * @param string       $cap     Capability being checked.
	 * @param int          $user_id User ID.
	 * @param array<mixed> $args    Additional arguments; `$args[0]` is the post ID.
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' !== $cap || ! isset( $args[0] ) || ! is_numeric( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Drop the per-request CPT cache.
	 *
	 * @since 0.1.0
	 */
	public function invalidate_cache(): void {
		$this->cpt_segments = null;
		$this->cpt_by_id    = array();
	}

	/**
	 * Drop the cache when a segment post's cache is cleaned.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public function invalidate_cache_for_post( int $post_id, ?WP_Post $post = null ): void {
		unset( $post_id );
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			$this->invalidate_cache();
		}
	}

	/**
	 * Whether a key matches KEY_PATTERN.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_valid_key( string $key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Load CPT segments into the per-request cache.
	 */
	private function load(): void {
		if ( null !== $this->cpt_segments ) {
			return;
		}

		$segments = array();
		$by_id    = array();
		$page     = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => array( 'publish', 'draft' ),
					'posts_per_page'         => self::PAGE_SIZE,
					'paged'                  => $page,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$posts = is_array( $query->posts ) ? $query->posts : array();
			$count = count( $posts );

			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$segment = self::from_post( $post );
				if ( null === $segment ) {
					continue;
				}

				$segments[ $segment->key() ] = $segment;
				$by_id[ $post->ID ]          = $segment;
			}

			++$page;
		} while ( self::PAGE_SIZE === $count );

		$this->cpt_segments = $segments;
		$this->cpt_by_id    = $by_id;
	}

	/**
	 * Build a Segment from a post. Null for unusable rows.
	 *
	 * @param WP_Post $post Segment post.
	 */
	private static function from_post( WP_Post $post ): ?Segment {
		if ( ! self::is_valid_key( $post->post_name ) ) {
			return null;
		}

		return new Segment( $post->ID, $post->post_name, $post->post_title, 'publish' === $post->post_status );
	}

	/**
	 * Derive a unique key from a display name.
	 *
	 * @param string $name Display name.
	 */
	private function generate_key( string $name ): string {
		$base = preg_replace( '/[^A-Za-z0-9_-]/', '-', sanitize_title( $name ) );
		$base = is_string( $base ) ? trim( $base, '-' ) : '';
		if ( '' === $base ) {
			$base = self::FALLBACK_KEY;
		}

		$key    = $base;
		$suffix = 2;
		while ( null !== $this->find( $key ) ) {
			$key = $base . '-' . $suffix;
			++$suffix;
		}

		return $key;
	}

	/**
	 * Update a segment post and refresh the cache.
	 *
	 * @param array<string, mixed> $postarr Fields for `wp_update_post`, including `ID`.
	 * @return true|WP_Error
	 */
	private function update( array $postarr ): bool|WP_Error {
		$result = wp_update_post( $postarr, true );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$this->invalidate_cache();

		return true;
	}

	/**
	 * Error for an empty or unusable name.
	 */
	private static function invalid_name_error(): WP_Error {
		return new WP_Error(
			'content_gates_invalid_segment_name',
			__( 'Segment name is required.', 'content-gates' )
		);
	}

	/**
	 * Error for an unknown CPT segment ID.
	 *
	 * @param int $id Post ID.
	 */
	private static function invalid_segment_error( int $id ): WP_Error {
		return new WP_Error(
			'content_gates_invalid_segment',
			__( 'Unknown segment.', 'content-gates' ),
			array( 'id' => $id )
		);
	}
}
