<?php
/**
 * Paywall JSON-LD tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

/**
 * Crawler variants carry paywall structured data; human variants do not.
 */
final class Test_Structured_Data extends Content_Gates_Test_Case {

	/**
	 * Render wp_head and return it.
	 */
	private function head(): string {
		ob_start();
		do_action( 'wp_head' );

		return (string) ob_get_clean();
	}

	/**
	 * A crawler gets JSON-LD with one hasPart per rendered gated Group.
	 */
	public function test_crawler_gets_json_ld(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_post(
			array(
				'post_title'   => 'Gated article',
				'post_content' => $this->gated_group( array( 'subscriber', 'crawlers-search' ), $this->paragraph( 'A' ) )
					. $this->gated_group( array( 'subscriber' ), $this->paragraph( 'B' ) )
					. $this->gated_group( array( 'crawlers-ai', 'crawlers-search' ), $this->paragraph( 'C' ) ),
			)
		);

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_post( $post_id );
		$head = $this->head();

		$this->assertStringContainsString( 'id="content-gates-paywall"', $head );
		preg_match( '#<script type="application/ld\+json" id="content-gates-paywall">(.*?)</script>#s', $head, $matches );
		$data = json_decode( $matches[1] ?? '', true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['isAccessibleForFree'] );
		$this->assertSame( 'Gated article', $data['headline'] );
		$this->assertCount( 2, $data['hasPart'], 'Groups A and C render for a search crawler; B does not.' );
		$this->assertSame( '.content-gates-gated-1', $data['hasPart'][0]['cssSelector'] );
		$this->assertSame( '.content-gates-gated-2', $data['hasPart'][1]['cssSelector'] );
		$this->assertFalse( $data['hasPart'][0]['isAccessibleForFree'] );

		$content = $this->render_content( $post_id );
		$this->assertStringContainsString( 'content-gates-gated-1', $content );
		$this->assertStringContainsString( 'content-gates-gated-2', $content );
		$this->assertStringNotContainsString( 'content-gates-gated-3', $content );
	}

	/**
	 * Human readers, members or not, get no JSON-LD.
	 */
	public function test_humans_get_no_json_ld(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_gated_post( array( 'subscriber' ) );

		$this->become_reader( array( 'subscriber' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->head() );

		$this->new_request();
		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->head() );
	}

	/**
	 * A post with no gated Groups emits nothing even for crawlers.
	 */
	public function test_ungated_post_emits_nothing(): void {
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$post_id = $this->create_post( array( 'post_content' => $this->paragraph( 'free' ) ) );

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_post( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->head() );
	}

	/**
	 * A browser-supplied crawler value gains nothing on an archive: crawler
	 * segments are declared only on gated singular posts.
	 */
	public function test_crawler_value_is_not_read_on_archives(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$this->create_gated_post( array( 'subscriber' ) );

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_page( home_url( '/' ) );
		$this->assertFalse( $this->plugin()->gate()->is_crawler() );
	}
}
