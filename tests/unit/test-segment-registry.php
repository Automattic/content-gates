<?php
/**
 * Segment registry tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Segments\Segment;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;
use WP_Error;

/**
 * Segment records: keys, statuses, immutability, no deletion, crawler mirror.
 */
final class Test_Segment_Registry extends Content_Gates_Test_Case {

	/**
	 * The key derives from the name once and is stored in post_name.
	 */
	public function test_create_derives_key_from_name(): void {
		$segment = $this->create_segment( 'Premium Subscriber!' );

		$this->assertSame( 'premium-subscrib', $segment->key, 'Key is sanitized and cut to 16 characters.' );
		$this->assertSame( 'Premium Subscriber!', $segment->name );
		$this->assertTrue( $segment->active );
		$this->assertSame( Segment::TYPE_BOOLEAN, $segment->type );
		$this->assertSame( $segment->key, get_post_field( 'post_name', $segment->id ) );
		$this->assertSame( Segment_Registry::POST_TYPE, get_post_type( $segment->id ) );
	}

	/**
	 * Duplicate names get distinct keys within the length limit.
	 */
	public function test_duplicate_names_get_unique_keys(): void {
		$first  = $this->create_segment( 'Donor' );
		$second = $this->create_segment( 'Donor' );
		$third  = $this->create_segment( 'A very long segment name indeed' );
		$fourth = $this->create_segment( 'A very long segment name indeed' );

		$this->assertSame( 'donor', $first->key );
		$this->assertSame( 'donor-2', $second->key );
		$this->assertSame( 'a-very-long-segm', $third->key );
		$this->assertSame( 'a-very-long-se-2', $fourth->key );
		$this->assertLessThanOrEqual( Segment::KEY_MAX_LENGTH, strlen( $fourth->key ) );
	}

	/**
	 * Meters need a limit and a period.
	 */
	public function test_meter_requires_limit_and_period(): void {
		$result = $this->plugin()->segments()->create( 'Metered', Segment::TYPE_METER, 0, 0 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_gates_invalid_meter', $result->get_error_code() );

		$meter = $this->create_meter( 'Metered articles', 5, 2592000 );
		$this->assertTrue( $meter->is_meter() );
		$this->assertSame( 5, $meter->limit );
		$this->assertSame( 2592000, $meter->period );
	}

	/**
	 * Renaming changes the name only.
	 */
	public function test_rename_keeps_key(): void {
		$segment = $this->create_segment( 'Subscriber' );
		$renamed = $this->plugin()->segments()->rename( $segment->key, 'Members' );

		$this->assertInstanceOf( Segment::class, $renamed );
		$this->assertSame( 'subscriber', $renamed->key );
		$this->assertSame( 'Members', $renamed->name );
		$this->assertSame( $segment->id, $renamed->id );
	}

	/**
	 * A direct post update cannot change the key or move the status outside publish/draft.
	 */
	public function test_post_name_is_immutable_and_status_is_coerced(): void {
		$segment = $this->create_segment( 'Subscriber' );

		wp_update_post(
			array(
				'ID'          => $segment->id,
				'post_name'   => 'something-else',
				'post_status' => 'pending',
			)
		);

		$this->assertSame( 'subscriber', get_post_field( 'post_name', $segment->id ) );
		$this->assertSame( 'draft', get_post_status( $segment->id ), 'Unknown statuses become draft (inactive).' );
	}

	/**
	 * Deactivation keeps the record and the key; reactivation restores it.
	 */
	public function test_deactivate_and_reactivate(): void {
		$segment  = $this->create_segment( 'Subscriber' );
		$inactive = $this->plugin()->segments()->set_active( $segment->id, false );
		$this->assertInstanceOf( Segment::class, $inactive );
		$this->assertFalse( $inactive->active );
		$this->assertSame( 'subscriber', $inactive->key );
		$this->assertArrayNotHasKey( 'subscriber', $this->plugin()->segments()->active() );
		$this->assertArrayHasKey( 'subscriber', $this->plugin()->segments()->all() );

		$active = $this->plugin()->segments()->set_active( 'subscriber', true );
		$this->assertInstanceOf( Segment::class, $active );
		$this->assertTrue( $active->active );
	}

	/**
	 * A status change fires the configuration-change action that requests a global purge.
	 */
	public function test_status_change_requests_global_purge(): void {
		$segment = $this->create_segment( 'Subscriber' );
		$reasons = array();
		add_action(
			'content_gates_purge_all',
			static function ( string $reason ) use ( &$reasons ): void {
				$reasons[] = $reason;
			}
		);

		$this->plugin()->segments()->set_active( $segment->id, false );
		$this->assertCount( 1, $reasons );
		$this->assertStringContainsString( 'deactivated', $reasons[0] );

		$this->plugin()->segments()->set_active( $segment->id, false );
		$this->assertCount( 1, $reasons, 'No change, no purge.' );
	}

	/**
	 * Segments can never be trashed or deleted, by anyone.
	 */
	public function test_segments_cannot_be_trashed_or_deleted(): void {
		$segment = $this->create_segment( 'Subscriber' );
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$this->assertFalse( wp_trash_post( $segment->id ) );
		$this->assertSame( 'publish', get_post_status( $segment->id ) );

		$this->assertFalse( wp_delete_post( $segment->id, true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $segment->id ) );

		$this->assertFalse( current_user_can( 'delete_post', $segment->id ), 'Administrators cannot delete segments.' );
	}

	/**
	 * References resolve by key and by ID; unknown references resolve to null.
	 */
	public function test_resolve_by_key_and_id(): void {
		$segment = $this->create_segment( 'Subscriber' );

		$this->assertSame( $segment->id, $this->plugin()->segments()->resolve( 'subscriber' )?->id );
		$this->assertSame( 'subscriber', $this->plugin()->segments()->resolve( $segment->id )?->key );
		$this->assertSame( 'subscriber', $this->plugin()->segments()->resolve( (string) $segment->id )?->key );
		$this->assertNull( $this->plugin()->segments()->resolve( 'nope' ) );
		$this->assertNull( $this->plugin()->segments()->resolve( 999999 ) );
	}

	/**
	 * Crawler segments are mirrored from config/crawlers.json as read-only, active records.
	 */
	public function test_crawler_sync_mirrors_config(): void {
		$changed = $this->plugin()->segments()->sync_crawlers();
		$this->assertContains( 'crawlers-search', $changed );
		$this->assertContains( 'crawlers-ai', $changed );

		$this->new_request();
		$search = $this->plugin()->segments()->resolve( 'crawlers-search' );
		$this->assertInstanceOf( Segment::class, $search );
		$this->assertTrue( $search->crawler );
		$this->assertTrue( $search->active );
		$this->assertSame( 'Search crawlers', $search->name );
		$this->assertSame( 'crawlers-search', $search->group_name, 'Crawler groups are never prefixed.' );

		$this->assertSame( array(), $this->plugin()->segments()->sync_crawlers(), 'A second sync changes nothing.' );

		$error = $this->plugin()->segments()->rename( 'crawlers-search', 'Renamed' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'content_gates_read_only_segment', $error->get_error_code() );

		$error = $this->plugin()->segments()->set_active( 'crawlers-search', false );
		$this->assertInstanceOf( WP_Error::class, $error );
	}

	/**
	 * A crawler segment removed from the file is deactivated, never deleted.
	 */
	public function test_crawler_removed_from_config_is_deactivated(): void {
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();

		$file = get_temp_dir() . 'content-gates-crawlers-' . wp_generate_password( 8, false ) . '.json';
		file_put_contents( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test fixture.
			$file,
			(string) wp_json_encode(
				array(
					'version'  => 99,
					'segments' => array(
						'crawlers-search' => array(
							'label'       => 'Search crawlers',
							'user_agents' => array( 'Googlebot' ),
						),
					),
				)
			)
		);

		$registry = new Segment_Registry( new \Automattic\VIP\ContentGates\Segments\Crawler_Config( $file ) );
		$changed  = $registry->sync_crawlers();
		unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Test fixture.

		$this->assertSame( array( 'crawlers-ai' ), $changed );
		$ai = $registry->resolve( 'crawlers-ai' );
		$this->assertInstanceOf( Segment::class, $ai );
		$this->assertFalse( $ai->active );
		$this->assertTrue( $ai->crawler );
	}

	/**
	 * The registry serves from the object cache and invalidates on change.
	 */
	public function test_registry_uses_object_cache(): void {
		$this->create_segment( 'Subscriber' );
		$this->assertIsArray( wp_cache_get( 'segments:' . get_current_blog_id(), Segment_Registry::CACHE_GROUP ) );

		$this->create_segment( 'Donor' );
		$this->assertCount( 2, $this->plugin()->segments()->all() );
	}
}
