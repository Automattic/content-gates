<?php
/**
 * Paywall JSON-LD tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\ContentGates\Rendering\Block_Gate;
use Automattic\VIP\ContentGates\Rendering\Structured_Data;

/**
 * Crawler variants carry paywall structured data; human variants do not.
 */
final class Test_Structured_Data extends Content_Gates_Test_Case {

	/**
	 * What the wp_footer callback prints. Called directly: firing the whole
	 * `wp_footer` action trips a core deprecation under the test library.
	 */
	private function footer(): string {
		ob_start();
		$this->structured_data()->print_json_ld();

		return (string) ob_get_clean();
	}

	/**
	 * The plugin's Structured_Data instance, from its wp_footer hook.
	 */
	private function structured_data(): Structured_Data {
		$hook = $GLOBALS['wp_filter']['wp_footer'] ?? null;
		$this->assertInstanceOf( \WP_Hook::class, $hook );
		foreach ( $hook->callbacks[10] ?? array() as $callback ) {
			$function = $callback['function'] ?? null;
			if ( is_array( $function ) && $function[0] instanceof Structured_Data ) {
				return $function[0];
			}
		}
		$this->fail( 'Structured_Data is not hooked to wp_footer.' );
	}

	/**
	 * A crawler gets JSON-LD with one hasPart per rendered paywalled Group. A
	 * `not-in` Group renders for the crawler but is the free part, so it is
	 * neither marked nor listed.
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
					. $this->gated_group( array( 'crawlers-ai', 'crawlers-search' ), $this->paragraph( 'C' ) )
					. $this->gated_group( array( 'subscriber' ), $this->paragraph( 'D' ), Block_Gate::MODE_NOT_IN ),
			)
		);

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_post( $post_id );
		$content = $this->render_content( $post_id );
		$footer  = $this->footer();

		$this->assertStringContainsString( 'id="content-gates-paywall"', $footer );
		preg_match( '#<script type="application/ld\+json" id="content-gates-paywall">(.*?)</script>#s', $footer, $matches );
		$data = json_decode( $matches[1] ?? '', true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['isAccessibleForFree'] );
		$this->assertSame( 'Gated article', $data['headline'] );
		$this->assertCount( 2, $data['hasPart'], 'Groups A and C are paywalled and render for a search crawler; B does not render; D is free.' );
		$this->assertSame( '.content-gates-gated-1', $data['hasPart'][0]['cssSelector'] );
		$this->assertSame( '.content-gates-gated-2', $data['hasPart'][1]['cssSelector'] );
		$this->assertFalse( $data['hasPart'][0]['isAccessibleForFree'] );

		$this->assertStringContainsString( 'content-gates-gated-1', $content );
		$this->assertStringContainsString( 'content-gates-gated-2', $content );
		$this->assertStringNotContainsString( 'content-gates-gated-3', $content );
		$this->assertStringContainsString( 'D', $content, 'The not-in Group renders for the crawler.' );
	}

	/**
	 * A post whose only gated Group is in synced pattern is counted from the
	 * render, so the JSON-LD matches the marker the pattern's Group received.
	 */
	public function test_group_inside_synced_pattern_is_listed(): void {
		$this->create_segment( 'Subscriber' );
		$this->plugin()->segments()->sync_crawlers();
		$this->new_request();
		$pattern_id = $this->create_post(
			array(
				'post_type'    => 'wp_block',
				'post_content' => $this->gated_group( array( 'subscriber', 'crawlers-search' ), $this->paragraph( 'IN-PATTERN' ) ),
			)
		);
		$post_id    = $this->create_post(
			array(
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			)
		);

		$this->become_reader( array( 'crawlers-search' => '1' ) );
		$this->request_post( $post_id );
		$this->render_content( $post_id );
		$footer = $this->footer();

		$this->assertStringContainsString( '"cssSelector":".content-gates-gated-1"', $footer );
		$this->assertStringNotContainsString( 'content-gates-gated-2', $footer );
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
		$this->render_content( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->footer() );

		$this->new_request();
		$this->become_reader( array() );
		$this->request_post( $post_id );
		$this->render_content( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->footer() );
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
		$this->render_content( $post_id );
		$this->assertStringNotContainsString( 'content-gates-paywall', $this->footer() );
	}

	/**
	 * A browser-supplied crawler value gains nothing on an archive: archives
	 * declare nothing, so no crawler segment is readable there.
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
