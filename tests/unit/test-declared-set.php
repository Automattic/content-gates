<?php
/**
 * Declared set tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Membership\Declared_Set;
use WP_Error;
use WP_Query;

use function Automattic\VIP\ContentGates\declare_segment;
use function Automattic\VIP\ContentGates\get_declared_segments;

/**
 * What a response declares, and the headers it sends.
 */
final class Test_Declared_Set extends Content_Gates_Test_Case {

	/**
	 * A singular post declares its stored assignments plus the crawler segments.
	 */
	public function test_singular_declares_stored_keys_and_crawlers(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->request_post( $post_id );

		$this->assertSame( array( 'crawlers-ai', 'crawlers-search', 'subscriber' ), get_declared_segments() );
		$this->assertSame( 'crawlers-ai, crawlers-search, subscriber', Headers::last( Declared_Set::HEADER_DECLARED ) );
		$this->assertTrue( $this->plugin()->declared()->is_closed() );
	}

	/**
	 * A singular post with nothing gated declares nothing, not even crawlers.
	 */
	public function test_ungated_singular_declares_nothing(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_post( array( 'post_content' => $this->paragraph( 'hello' ) ) );

		$this->request_post( $post_id );

		$this->assertSame( array(), get_declared_segments() );
		$this->assertSame( '', Headers::last( Declared_Set::HEADER_DECLARED ), 'The header is sent with an empty value.' );
	}

	/**
	 * Archives declare nothing from content.
	 */
	public function test_archive_declares_nothing(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_gated_post( array( 'subscriber' ) );

		$this->request_page( home_url( '/' ) );

		$this->assertSame( array(), get_declared_segments() );
		$this->assertSame( '', Headers::last( Declared_Set::HEADER_DECLARED ) );
	}

	/**
	 * Feeds declare the union over the posts they render.
	 */
	public function test_feed_declares_union(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->create_gated_post( array( 'subscriber' ) );
		$this->create_gated_post( array( 'donor' ) );

		$this->request_page( get_feed_link() );

		$this->assertTrue( is_feed() );
		$this->assertSame( array( 'donor', 'subscriber' ), get_declared_segments() );
	}

	/**
	 * Inactive segments are never declared.
	 */
	public function test_inactive_assignments_are_dropped(): void {
		$this->create_segment( 'Subscriber' );
		$old     = $this->create_segment( 'Old' );
		$post_id = $this->create_gated_post( array( 'subscriber', 'old' ) );
		$this->plugin()->segments()->set_active( $old->id, false );
		$this->new_request();

		$this->assertSame( array( 'old', 'subscriber' ), Declared_Set::stored_keys( $post_id ), 'Meta keeps the assignment.' );
		$this->request_post( $post_id );
		$this->assertSame( array( 'subscriber' ), get_declared_segments() );
	}

	/**
	 * Declaring works on the wp action and is refused later.
	 */
	public function test_declare_segment_window(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$inactive = $this->create_segment( 'Old' );
		$this->plugin()->segments()->set_active( $inactive->id, false );
		$this->new_request();

		$results = array();
		add_action(
			'wp',
			static function () use ( &$results ): void {
				$results['ok']       = declare_segment( 'donor' );
				$results['unknown']  = declare_segment( 'nope' );
				$results['inactive'] = declare_segment( 'old' );
			}
		);
		$this->request_page( home_url( '/' ) );

		$this->assertTrue( $results['ok'] );
		$this->assertInstanceOf( WP_Error::class, $results['unknown'] );
		$this->assertSame( 'content_gates_invalid_segment', $results['unknown']->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $results['inactive'] );
		$this->assertSame( 'content_gates_inactive_segment', $results['inactive']->get_error_code() );
		$this->assertSame( array( 'donor' ), get_declared_segments() );

		$late = declare_segment( 'subscriber' );
		$this->assertInstanceOf( WP_Error::class, $late );
		$this->assertSame( 'content_gates_too_late', $late->get_error_code() );
	}

	/**
	 * The filter can add keys but never remove block or earlier declarations.
	 */
	public function test_filter_adds_but_cannot_remove(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Donor' );
		$this->create_segment( 'Newsletter' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		add_action(
			'wp',
			static function (): void {
				declare_segment( 'donor' );
			}
		);
		$seen = null;
		add_filter(
			'content_gates_declared_segments',
			static function ( array $segments, WP_Query $query ) use ( &$seen ): array {
				$seen             = $segments;
				$this_is_singular = $query->is_singular();
				return $this_is_singular ? array( 'newsletter' ) : $segments;
			},
			10,
			2
		);
		$this->request_post( $post_id );

		$this->assertSame( array( 'donor', 'subscriber' ), $seen, 'The filter sees block and explicit declarations.' );
		$this->assertSame( array( 'donor', 'newsletter', 'subscriber' ), get_declared_segments() );
	}

	/**
	 * Unknown keys returned by the filter are dropped with a notice.
	 */
	public function test_filter_unknown_key_is_dropped(): void {
		$this->setExpectedIncorrectUsage( 'content_gates_declared_segments' );
		$this->create_segment( 'Subscriber' );
		add_filter( 'content_gates_declared_segments', static fn( array $segments ): array => array_merge( $segments, array( 'nope', 'subscriber' ) ) );

		$this->request_page( home_url( '/' ) );

		$this->assertSame( array( 'subscriber' ), get_declared_segments() );
	}

	/**
	 * Meter headers are sent on singular pages that declare a meter.
	 */
	public function test_meter_header_on_singular(): void {
		$this->create_segment( 'Subscriber' );
		$this->create_meter( 'Metered articles', 5, 2592000 );
		$post_id = $this->create_gated_post( array( 'subscriber', 'metered-articles' ) );

		$this->request_post( $post_id );

		$this->assertSame(
			array( "metered-articles; id={$post_id}; limit=5; period=2592000" ),
			Headers::values( Declared_Set::HEADER_METER )
		);
	}

	/**
	 * Feeds declare the meter but send no meter header: a feed is not an article view.
	 */
	public function test_no_meter_header_on_feed(): void {
		$this->create_meter( 'Metered articles', 5, 2592000 );
		$this->create_gated_post( array( 'metered-articles' ) );

		$this->request_page( get_feed_link() );

		$this->assertSame( array( 'metered-articles' ), get_declared_segments() );
		$this->assertSame( array(), Headers::values( Declared_Set::HEADER_METER ) );
	}

	/**
	 * The declared action fires once the set is final.
	 */
	public function test_declared_action(): void {
		$this->create_segment( 'Subscriber' );
		$post_id = $this->create_gated_post( array( 'subscriber' ) );
		$fired   = array();
		add_action(
			'content_gates_declared',
			static function ( array $keys ) use ( &$fired ): void {
				$fired[] = $keys;
			}
		);

		$this->request_post( $post_id );

		$this->assertSame( array( array( 'subscriber' ) ), $fired );
	}
}
