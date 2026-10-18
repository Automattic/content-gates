<?php
/**
 * Block enforcer tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Plugin;
use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Segments\Segment_Registry;
use WP_Block_Type_Registry;

require_once __DIR__ . '/class-gating-test-case.php';

/**
 * Gated Groups are withheld at `pre_render_block` for non-members.
 */
final class Test_Block_Enforcer extends Gating_Test_Case {

	/**
	 * Enforcer built on the plugin's membership and registry, for direct calls.
	 *
	 * @var Block_Enforcer
	 */
	private Block_Enforcer $enforcer;

	/**
	 * Build a direct enforcer. Hooks come from the booted plugin.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->enforcer = new Block_Enforcer( $this->membership, $this->registry );
	}

	/**
	 * Remove the test block if a test registered one.
	 */
	public function tear_down(): void {
		$blocks = WP_Block_Type_Registry::get_instance();
		if ( $blocks->is_registered( 'content-gates-test/counter' ) ) {
			$blocks->unregister( 'content-gates-test/counter' );
		}

		parent::tear_down();
	}

	/**
	 * The plugin wires the enforcer to the three filters.
	 */
	public function test_plugin_hooks_enforcer(): void {
		$this->assert_hooked( 'register_block_type_args', Block_Enforcer::class, 'filter_block_type_args', 10 );
		$this->assert_hooked( 'pre_render_block', Block_Enforcer::class, 'filter_pre_render_block', 10 );
		$this->assert_hooked( 'render_block', Block_Enforcer::class, 'filter_render_block', 10 );
	}

	/**
	 * The attribute is registered on core/group server-side.
	 */
	public function test_attribute_is_registered_on_core_group(): void {
		$group = WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' );

		$this->assertNotNull( $group );
		$this->assertIsArray( $group->attributes );
		$this->assertSame( array( 'type' => 'integer' ), $group->attributes[ Block_Enforcer::ATTRIBUTE ] );

		$paragraph = WP_Block_Type_Registry::get_instance()->get_registered( 'core/paragraph' );
		$this->assertNotNull( $paragraph );
		$this->assertIsArray( $paragraph->attributes );
		$this->assertArrayNotHasKey( Block_Enforcer::ATTRIBUTE, $paragraph->attributes );
	}

	/**
	 * A non-member gets nothing: no text, no wrapper. The segment and the
	 * crawler segment are consulted.
	 */
	public function test_denied_reader_gets_nothing(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) );

		$output = do_blocks( $content );

		$this->assertSame( '', $output );
		$this->assertStringNotContainsString( 'SECRET', $output );
		$this->assertStringNotContainsString( 'wp-block-group', $output );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $segment->key() ), $this->consulted() );
	}

	/**
	 * A member gets the Group. Only the segment is consulted.
	 */
	public function test_entitled_reader_gets_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) );

		$this->set_reader( array( $segment->key() => '1' ) );
		$output = do_blocks( $content );

		$this->assertStringContainsString( '>SECRET</p>', $output );
		$this->assertStringContainsString( 'class="wp-block-group"', $output );
		$this->assertStringNotContainsString( Block_Enforcer::PAYWALL_CLASS, $output );
		$this->assertSame( array( $segment->key() ), $this->consulted() );
	}

	/**
	 * Public content around a gated Group is untouched.
	 */
	public function test_public_blocks_render_around_denied_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->paragraph( 'BEFORE' ) . $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) . $this->paragraph( 'AFTER' );

		$output = do_blocks( $content );

		$this->assertStringContainsString( '>BEFORE</p>', $output );
		$this->assertStringContainsString( '>AFTER</p>', $output );
		$this->assertStringNotContainsString( 'SECRET', $output );
		$this->assertStringNotContainsString( 'wp-block-group', $output );
	}

	/**
	 * A dynamic block inside a denied Group never runs its callback.
	 */
	public function test_dynamic_child_callback_does_not_run_when_denied(): void {
		$calls = 0;
		register_block_type(
			'content-gates-test/counter',
			array(
				'render_callback' => static function () use ( &$calls ): string {
					++$calls;

					return '>DYNAMIC</p>';
				},
			)
		);

		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), '<!-- wp:content-gates-test/counter /-->' );

		$this->assertSame( '', do_blocks( $content ) );
		$this->assertSame( 0, $calls, 'A denied Group must short-circuit its dynamic children.' );

		$this->set_reader( array( $segment->key() => '1' ) );
		$this->assertStringContainsString( '>DYNAMIC</p>', do_blocks( $content ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A permitted outer Group lets an inner Group enforce its own segment.
	 */
	public function test_allowed_outer_denied_inner_withholds_only_inner(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );

		$content = $this->gated_group(
			$outer->id(),
			$this->paragraph( 'OUTER-TEXT' ) . $this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) )
		);

		$this->set_reader( array( $outer->key() => '1' ) );
		$output = do_blocks( $content );

		$this->assertStringContainsString( '>OUTER-TEXT</p>', $output );
		$this->assertStringNotContainsString( 'INNER-SECRET', $output );
		$this->assertSame( 1, substr_count( $output, 'class="wp-block-group"' ), 'Only the outer wrapper renders.' );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $inner->key(), $outer->key() ), $this->consulted() );
	}

	/**
	 * A denied outer Group never consults the inner segment.
	 */
	public function test_denied_outer_consults_only_outer(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );

		$content = $this->gated_group(
			$outer->id(),
			$this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) )
		);

		$this->set_reader( array( $inner->key() => '1' ) );
		$output = do_blocks( $content );

		$this->assertSame( '', $output );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $outer->key() ), $this->consulted() );
		$this->assertNotContains( $inner->key(), $this->consulted() );
	}

	/**
	 * Invalid references withhold and are a caller bug under WP_DEBUG.
	 */
	public function test_invalid_references_withhold_with_incorrect_usage(): void {
		$this->setExpectedIncorrectUsage( Block_Enforcer::ATTRIBUTE );

		$unknown = 999999;
		$this->assertNull( $this->registry->find( $unknown ) );

		foreach ( array( 0, -5, 'abc', '12', $unknown ) as $segment_id ) {
			$output = do_blocks( $this->gated_group( $segment_id, $this->paragraph( 'SECRET' ) ) );

			$this->assertSame( '', $output, 'Reference: ' . wp_json_encode( $segment_id ) );
		}

		$this->assertSame( array(), $this->consulted(), 'Invalid references record no read.' );
	}

	/**
	 * A code-registered key cannot be assigned to a Group, and an invalid
	 * reference is withheld from crawlers too.
	 */
	public function test_invalid_reference_withholds_from_crawler(): void {
		$this->setExpectedIncorrectUsage( Block_Enforcer::ATTRIBUTE );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );

		$this->assertTrue( $this->enforcer->should_withhold( Segment_Registry::CRAWLER_KEY, null ) );
		$this->assertSame( '', do_blocks( $this->gated_group( Segment_Registry::CRAWLER_KEY, $this->paragraph( 'SECRET' ) ) ) );
		$this->assertSame( '', do_blocks( $this->gated_group( 999999, $this->paragraph( 'SECRET' ) ) ) );
		$this->assertSame( array(), $this->consulted(), 'Invalid references consult nothing, not even crawler.' );
	}

	/**
	 * A deactivated segment fails closed even for a reader holding its value.
	 */
	public function test_inactive_segment_withholds(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$this->assertTrue( $this->registry->set_active( $segment->id() ?? 0, false ) );

		$this->set_reader( array( $segment->key() => '1' ) );
		$output = do_blocks( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->assertSame( '', $output );
		$this->assertContains( $segment->key(), $this->consulted(), 'Inactive reads are still recorded.' );

		$this->assertTrue( $this->registry->set_active( $segment->id() ?? 0, true ) );
		$this->set_reader( array( $segment->key() => '1' ) );
		$this->assertStringContainsString( 'SECRET', do_blocks( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) ) );
	}

	/**
	 * Rendering for different readers never changes the saved post.
	 */
	public function test_rendering_does_not_change_saved_content(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) . $this->paragraph( 'PUBLIC' );
		$post_id = $this->create_post( $content );

		$saved = get_post( $post_id );
		$this->assertNotNull( $saved );
		$this->assertSame( $content, $saved->post_content );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercising core's content pipeline on purpose.
		$denied = apply_filters( 'the_content', $saved->post_content );
		$this->set_reader( array( $segment->key() => '1' ) );
		$entitled = apply_filters( 'the_content', $saved->post_content );
		// phpcs:enable

		$this->assertStringNotContainsString( 'SECRET', $denied );
		$this->assertStringContainsString( 'SECRET', $entitled );

		clean_post_cache( $post_id );
		$fresh = get_post( $post_id );
		$this->assertNotNull( $fresh );
		$this->assertSame( $content, $fresh->post_content );
		$this->assertSame( $content, $saved->post_content );
	}

	/**
	 * Membership never forces a Group visible that its author hid.
	 */
	public function test_author_visibility_is_respected_for_entitled_reader(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group(
			$segment->id(),
			$this->paragraph( 'SECRET' ),
			array( 'metadata' => array( 'blockVisibility' => false ) )
		);

		$this->set_reader( array( $segment->key() => '1' ) );

		$this->assertSame( '', do_blocks( $content ) );
	}

	/**
	 * Previewing editors see the Group; login alone never grants access.
	 */
	public function test_preview_exception_requires_preview_and_edit_capability(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) );
		$post_id = $this->create_post( $content );
		$editor  = $this->create_user( 'editor' );
		$reader  = $this->create_user( 'subscriber' );

		// Logged in, not previewing: withheld.
		wp_set_current_user( $editor );
		$this->visit( $this->permalink( $post_id ) );
		$this->assertSame( $post_id, get_the_ID() );
		$this->assertFalse( is_preview() );
		$this->assertSame( '', do_blocks( $content ) );

		// Previewing with edit capability: shown.
		$this->visit( add_query_arg( 'preview', 'true', $this->permalink( $post_id ) ) );
		$this->assertTrue( is_preview() );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertStringContainsString( 'SECRET', do_blocks( $content ) );
		$this->assertContains( $segment->key(), $this->consulted(), 'The preview path still records the read.' );

		// Previewing without edit capability: withheld.
		wp_set_current_user( $reader );
		$this->assertTrue( is_preview() );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
		$this->assertSame( '', do_blocks( $content ) );
	}

	/**
	 * The preview exception needs a post to check; without one it does not apply.
	 */
	public function test_preview_exception_without_post_withholds(): void {
		$segment = $this->create_segment( 'Subscribers' );
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$this->assertTrue( $this->enforcer->should_withhold( $segment->id(), null ) );
	}

	/**
	 * A crawler gets the Group in full, wrapped in the paywall selector.
	 */
	public function test_crawler_gets_wrapped_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->paragraph( 'PUBLIC' ) . $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );
		$output = do_blocks( $content );

		$this->assertStringContainsString( '>PUBLIC</p>', $output );
		$this->assertSame( 1, preg_match( '#<div class="content-gates-paywall">\s*<div class="wp-block-group">\s*<p[^>]*>SECRET</p>\s*</div>\s*</div>#', $output ), $output );
		$this->assertSame( array( Segment_Registry::CRAWLER_KEY, $segment->key() ), $this->consulted() );
		$this->assertSame( Block_Enforcer::CRAWLER, $this->enforcer->decision( parse_blocks( $this->gated_group( $segment->id(), '' ) )[0] ) );
	}

	/**
	 * A crawler who is also a member is treated as a member: no wrapper.
	 */
	public function test_entitled_crawler_is_not_wrapped(): void {
		$segment = $this->create_segment( 'Subscribers' );

		$this->set_reader(
			array(
				Segment_Registry::CRAWLER_KEY => '1',
				$segment->key()               => '1',
			)
		);
		$output = do_blocks( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) );

		$this->assertStringContainsString( 'SECRET', $output );
		$this->assertStringNotContainsString( Block_Enforcer::PAYWALL_CLASS, $output );
		$this->assertSame( array( $segment->key() ), $this->consulted() );
	}

	/**
	 * Nested gates on the crawler variant: only the denied Group is wrapped.
	 */
	public function test_crawler_wraps_only_denied_nested_group(): void {
		$outer = $this->create_segment( 'Outer' );
		$inner = $this->create_segment( 'Inner' );

		$content = $this->gated_group(
			$outer->id(),
			$this->paragraph( 'OUTER-TEXT' ) . $this->gated_group( $inner->id(), $this->paragraph( 'INNER-SECRET' ) )
		);

		$this->set_reader(
			array(
				Segment_Registry::CRAWLER_KEY => '1',
				$outer->key()                 => '1',
			)
		);
		$output = do_blocks( $content );

		$this->assertSame( 1, substr_count( $output, Block_Enforcer::PAYWALL_CLASS ) );
		$this->assertSame( 2, substr_count( $output, 'class="wp-block-group"' ) );
		$this->assertStringStartsWith( '<div class="wp-block-group">', trim( $output ), 'The entitled outer Group is not wrapped.' );
		$this->assertSame( 1, preg_match( '#<div class="content-gates-paywall">\s*<div class="wp-block-group">\s*<p[^>]*>INNER-SECRET</p>#', $output ) );
	}

	/**
	 * A hidden Group on the crawler variant stays hidden: nothing to wrap.
	 */
	public function test_crawler_does_not_wrap_author_hidden_group(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$content = $this->gated_group(
			$segment->id(),
			$this->paragraph( 'SECRET' ),
			array( 'metadata' => array( 'blockVisibility' => false ) )
		);

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );

		$this->assertSame( '', do_blocks( $content ) );
	}

	/**
	 * Groups without the attribute, and other blocks, are never touched or consulted.
	 */
	public function test_ungated_blocks_are_untouched(): void {
		$this->create_segment( 'Subscribers' );
		$content = $this->plain_group( $this->paragraph( 'PLAIN' ) ) . $this->paragraph( 'TEXT' );

		$output = do_blocks( $content );

		$this->assertStringContainsString( '>PLAIN</p>', $output );
		$this->assertStringContainsString( '>TEXT</p>', $output );
		$this->assertSame( array(), $this->consulted() );
		$this->assertSame( Block_Enforcer::ALLOW, $this->enforcer->decision( parse_blocks( $content )[0] ) );
	}

	/**
	 * An explicit null attribute means "No segment".
	 */
	public function test_null_attribute_means_no_segment(): void {
		$content = $this->gated_group( null, $this->paragraph( 'TEXT' ) );

		$this->assertStringContainsString( '>TEXT</p>', do_blocks( $content ) );
		$this->assertSame( array(), $this->consulted() );
	}

	/**
	 * `pre_render_block` returns the incoming value for everything it allows.
	 */
	public function test_pre_render_block_passes_through_incoming_value(): void {
		$segment = $this->create_segment( 'Subscribers' );
		$block   = parse_blocks( $this->gated_group( $segment->id(), $this->paragraph( 'SECRET' ) ) )[0];

		$this->assertSame( '', $this->enforcer->filter_pre_render_block( null, $block, null ) );
		$this->assertSame( '', $this->enforcer->filter_pre_render_block( '<p>other</p>', $block, null ), 'Withholding beats an earlier pre-render.' );

		$this->set_reader( array( $segment->key() => '1' ) );
		$this->assertNull( $this->enforcer->filter_pre_render_block( null, $block, null ) );
		$this->assertSame( '<p>other</p>', $this->enforcer->filter_pre_render_block( '<p>other</p>', $block, null ) );

		$this->set_reader( array( Segment_Registry::CRAWLER_KEY => '1' ) );
		$this->assertNull( $this->enforcer->filter_pre_render_block( null, $block, null ) );
		$this->assertSame(
			'<div class="content-gates-paywall"><p>other</p></div>',
			$this->enforcer->filter_pre_render_block( '<p>other</p>', $block, null ),
			'An earlier pre-render skips render_block, so the wrapper is applied here.'
		);
	}

	/**
	 * The booted plugin exposes the same registry the enforcer reads.
	 */
	public function test_uses_plugin_registry(): void {
		$this->assertSame( Plugin::instance()->segments(), $this->registry );
	}
}
