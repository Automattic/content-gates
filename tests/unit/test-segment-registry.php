<?php
/**
 * Segment registry tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Plugin;
use ContentGates\Segments\Segment;
use ContentGates\Segments\Segment_Registry;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers CPT lifecycle, key immutability, deletion guards, and lookups.
 */
final class Test_Segment_Registry extends WP_UnitTestCase {

	/**
	 * Registry under test (the booted plugin's instance).
	 *
	 * @var Segment_Registry
	 */
	private Segment_Registry $registry;

	/**
	 * Use the plugin's registry and drop its per-request cache; the test
	 * suite rolls the database back between tests without firing hooks.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->registry = Plugin::instance()->segments();
		$this->registry->invalidate_cache();
	}

	/**
	 * Create a segment and return its ID, failing loudly on error.
	 *
	 * @param string $name Segment name.
	 */
	private function create( string $name ): int {
		$id = $this->registry->create( $name );
		$this->assertIsInt( $id, 'create() must return a post ID.' );

		return $id;
	}

	/**
	 * Create a user with a role and return its ID.
	 *
	 * @param string $role Role slug.
	 */
	private function create_user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Read a post status fresh from WordPress.
	 *
	 * Marked impure so PHPStan does not carry an earlier asserted value
	 * forward across the `wp_update_post()` calls between assertions.
	 *
	 * @phpstan-impure
	 *
	 * @param int $id Post ID.
	 */
	private function status_of( int $id ): string {
		$status = get_post_status( $id );

		return false === $status ? '' : $status;
	}

	/**
	 * The post type is registered with the decided arguments.
	 */
	public function test_post_type_is_registered_and_hidden(): void {
		$type = get_post_type_object( Segment_Registry::POST_TYPE );

		$this->assertNotNull( $type );
		$this->assertSame( 'cg_segment', Segment_Registry::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_ui );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->can_export );
		$this->assertTrue( $type->map_meta_cap );
		$this->assertTrue( post_type_supports( Segment_Registry::POST_TYPE, 'title' ) );
	}

	/**
	 * Creation publishes the post and derives a regex-safe key.
	 */
	public function test_create_sets_publish_and_safe_key(): void {
		$id = $this->create( 'Premium Subscribers!' );

		$post = get_post( $id );
		$this->assertNotNull( $post );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Premium Subscribers!', $post->post_title );
		$this->assertMatchesRegularExpression( Segment_Registry::KEY_PATTERN, $post->post_name );
		$this->assertSame( 'premium-subscribers', $post->post_name );

		$segment = $this->registry->find( $id );
		$this->assertInstanceOf( Segment::class, $segment );
		$this->assertTrue( $segment->is_active() );
		$this->assertFalse( $segment->is_registered_in_code() );
		$this->assertSame( 'premium-subscribers', $segment->key() );
	}

	/**
	 * Keys are unique among CPT segments and fall back to `segment`.
	 */
	public function test_create_generates_unique_and_fallback_keys(): void {
		$first  = $this->create( 'Members' );
		$second = $this->create( 'Members' );
		$blank  = $this->create( '!!!' );

		$this->assertSame( 'members', get_post_field( 'post_name', $first ) );
		$this->assertSame( 'members-2', get_post_field( 'post_name', $second ) );
		$this->assertSame( 'segment', get_post_field( 'post_name', $blank ) );

		foreach ( array( $first, $second, $blank ) as $id ) {
			$this->assertMatchesRegularExpression( Segment_Registry::KEY_PATTERN, get_post_field( 'post_name', $id ) );
		}
	}

	/**
	 * A CPT key never collides with a code-registered key.
	 */
	public function test_create_avoids_code_registered_key(): void {
		$this->assertTrue( $this->registry->register_key( 'newsletter-code' ) );

		$id = $this->create( 'Newsletter Code' );

		$this->assertSame( 'newsletter-code-2', get_post_field( 'post_name', $id ) );
	}

	/**
	 * Empty names are rejected.
	 */
	public function test_create_rejects_empty_name(): void {
		$result = $this->registry->create( "  \t " );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_invalid_segment_name', $result->get_error_code() );
	}

	/**
	 * Rename keeps the ID and the key.
	 */
	public function test_rename_keeps_id_and_key(): void {
		$id = $this->create( 'Original Name' );

		$this->assertTrue( $this->registry->rename( $id, 'Completely Different' ) );

		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->assertSame( $id, $segment->id() );
		$this->assertSame( 'original-name', $segment->key() );
		$this->assertSame( 'Completely Different', $segment->name() );
		$this->assertSame( $segment, $this->registry->find( 'original-name' ) );
	}

	/**
	 * Rename validates its inputs.
	 */
	public function test_rename_rejects_unknown_id_and_empty_name(): void {
		$id = $this->create( 'Valid' );

		$unknown = $this->registry->rename( $id + 1000, 'Other' );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'content_gates_invalid_segment', $unknown->get_error_code() );

		$empty = $this->registry->rename( $id, '' );
		$this->assertInstanceOf( WP_Error::class, $empty );
		$this->assertSame( 'content_gates_invalid_segment_name', $empty->get_error_code() );
	}

	/**
	 * A direct post_name change through wp_update_post is discarded.
	 */
	public function test_key_is_immutable_through_wp_update_post(): void {
		$id = $this->create( 'Immutable' );

		wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => 'changed-key',
			)
		);

		$this->assertSame( 'immutable', get_post_field( 'post_name', $id ) );
		$this->assertNotNull( $this->registry->find( 'immutable' ) );
		$this->assertNull( $this->registry->find( 'changed-key' ) );
	}

	/**
	 * Deactivation sets draft and reports inactive; reactivation restores publish.
	 */
	public function test_set_active_toggles_status(): void {
		$id = $this->create( 'Toggle' );

		$this->assertTrue( $this->registry->set_active( $id, false ) );
		$this->assertSame( 'draft', $this->status_of( $id ) );

		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->assertFalse( $segment->is_active() );
		$this->assertSame( 'toggle', $segment->key() );
		$this->assertArrayNotHasKey( 'toggle', $this->registry->active() );
		$this->assertArrayHasKey( 'toggle', $this->registry->all() );

		$this->assertTrue( $this->registry->set_active( $id, true ) );
		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayHasKey( 'toggle', $this->registry->active() );
	}

	/**
	 * Unknown IDs are rejected by set_active().
	 */
	public function test_set_active_rejects_unknown_id(): void {
		$result = $this->registry->set_active( 999999, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_invalid_segment', $result->get_error_code() );
	}

	/**
	 * Any status outside publish/draft becomes draft, including trash.
	 */
	public function test_other_statuses_are_coerced_to_draft(): void {
		$id = $this->create( 'Coerced' );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'trash',
			)
		);
		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertSame( 'coerced', get_post_field( 'post_name', $id ), 'Trash must not leave a __trashed suffix on the key.' );
		$this->assertSame( '', get_post_meta( $id, '_wp_desired_post_slug', true ) );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'pending',
			)
		);
		$this->assertSame( 'draft', $this->status_of( $id ) );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			)
		);
		$this->assertSame( 'draft', $this->status_of( $id ) );

		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->assertFalse( $segment->is_active() );
	}

	/**
	 * Trashing is refused and the post stays published.
	 */
	public function test_trash_is_impossible(): void {
		$id = $this->create( 'Untrashable' );

		$this->assertFalse( wp_trash_post( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertNotNull( $this->registry->find( $id ) );
	}

	/**
	 * Deleting, forced or not, is refused and the post survives.
	 */
	public function test_delete_is_impossible(): void {
		$id = $this->create( 'Undeletable' );

		$forced = wp_delete_post( $id, true );
		$this->assertTrue( false === $forced || null === $forced, 'Forced delete must short-circuit.' );
		$this->assertNotNull( get_post( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$soft = wp_delete_post( $id, false );
		$this->assertTrue( false === $soft || null === $soft, 'Soft delete must short-circuit.' );
		$this->assertNotNull( get_post( $id ) );
		$this->assertNotNull( $this->registry->find( $id ) );
	}

	/**
	 * Even an administrator lacks delete_post on a segment, but keeps edit_post.
	 */
	public function test_administrator_cannot_delete_segment(): void {
		$id    = $this->create( 'Protected' );
		$admin = $this->create_user( 'administrator' );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'edit_post', $id ), 'Sanity: admins can still edit.' );
		$this->assertFalse( current_user_can( 'delete_post', $id ) );
		$this->assertContains( 'do_not_allow', map_meta_cap( 'delete_post', $admin, $id ) );
	}

	/**
	 * The guard is scoped to segments; ordinary posts are untouched.
	 */
	public function test_guards_do_not_affect_other_post_types(): void {
		$admin = $this->create_user( 'administrator' );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );

		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );
		$this->assertNotFalse( wp_trash_post( $post_id ) );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * Lookups resolve IDs and keys, and miss cleanly.
	 */
	public function test_find_by_id_and_key(): void {
		$id = $this->create( 'Findable' );

		$by_id  = $this->registry->find( $id );
		$by_key = $this->registry->find( 'findable' );

		$this->assertNotNull( $by_id );
		$this->assertNotNull( $by_key );
		$this->assertSame( $id, $by_key->id() );
		$this->assertSame( 'findable', $by_id->key() );

		$this->assertNull( $this->registry->find( $id + 1000 ) );
		$this->assertNull( $this->registry->find( 'no-such-key' ) );

		$other = self::factory()->post->create( array( 'post_name' => 'ordinary' ) );
		$this->assertIsInt( $other );
		$this->assertNull( $this->registry->find( $other ), 'Only cg_segment posts resolve.' );
		$this->assertNull( $this->registry->find( 'ordinary' ) );
	}

	/**
	 * Code-registered keys are active, ID-less, validated, and collision-checked.
	 */
	public function test_register_key(): void {
		$this->assertTrue( $this->registry->register_key( 'provider_code-1' ) );

		$segment = $this->registry->find( 'provider_code-1' );
		$this->assertNotNull( $segment );
		$this->assertNull( $segment->id() );
		$this->assertTrue( $segment->is_active() );
		$this->assertTrue( $segment->is_registered_in_code() );
		$this->assertArrayHasKey( 'provider_code-1', $this->registry->active() );

		$duplicate = $this->registry->register_key( 'provider_code-1' );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'content_gates_duplicate_segment_key', $duplicate->get_error_code() );

		foreach ( array( '', 'has space', 'bad.key', 'ünïcode', 'a/b' ) as $bad ) {
			$invalid = $this->registry->register_key( $bad );
			$this->assertInstanceOf( WP_Error::class, $invalid, "Key '{$bad}' must be rejected." );
			$this->assertSame( 'content_gates_invalid_segment_key', $invalid->get_error_code() );
		}
	}

	/**
	 * A code key cannot shadow an existing CPT key.
	 */
	public function test_register_key_rejects_cpt_collision(): void {
		$this->create( 'Cpt Owned' );

		$result = $this->registry->register_key( 'cpt-owned' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_duplicate_segment_key', $result->get_error_code() );
	}

	/**
	 * The reserved crawler segment exists from boot.
	 */
	public function test_crawler_segment_is_reserved(): void {
		$crawler = $this->registry->find( Segment_Registry::CRAWLER_KEY );

		$this->assertNotNull( $crawler );
		$this->assertTrue( $crawler->is_registered_in_code() );
		$this->assertTrue( $crawler->is_active() );
		$this->assertArrayHasKey( 'crawler', $this->registry->all() );
	}

	/**
	 * The full list merges CPT and code segments keyed by key; active() filters.
	 */
	public function test_all_and_active(): void {
		$active_id   = $this->create( 'Zeta Active' );
		$inactive_id = $this->create( 'Alpha Inactive' );
		$this->registry->set_active( $inactive_id, false );
		$this->registry->register_key( 'code-only' );

		$all = $this->registry->all();
		$this->assertArrayHasKey( 'zeta-active', $all );
		$this->assertArrayHasKey( 'alpha-inactive', $all );
		$this->assertArrayHasKey( 'code-only', $all );
		$this->assertSame( $active_id, $all['zeta-active']->id() );
		$this->assertSame( $inactive_id, $all['alpha-inactive']->id() );

		$cpt_keys = array_values(
			array_filter(
				array_keys( $all ),
				static fn ( string $key ): bool => ! $all[ $key ]->is_registered_in_code()
			)
		);
		$this->assertSame( array( 'alpha-inactive', 'zeta-active' ), $cpt_keys, 'CPT segments are ordered by title.' );

		$active = $this->registry->active();
		$this->assertArrayHasKey( 'zeta-active', $active );
		$this->assertArrayHasKey( 'code-only', $active );
		$this->assertArrayNotHasKey( 'alpha-inactive', $active );
	}

	/**
	 * Lookups are cached per request and invalidated on save.
	 */
	public function test_cache_invalidates_on_save(): void {
		$id     = $this->create( 'Cached' );
		$before = $this->registry->find( 'cached' );
		$this->assertNotNull( $before );
		$this->assertSame( 'Cached', $before->name() );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Cached Renamed',
			)
		);

		$segment = $this->registry->find( $id );
		$this->assertNotNull( $segment );
		$this->assertSame( 'Cached Renamed', $segment->name() );

		clean_post_cache( $id );
		$this->assertNotNull( $this->registry->find( 'cached' ) );
	}
}
