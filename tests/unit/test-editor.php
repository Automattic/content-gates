<?php
/**
 * Editor metadata and assignment contract tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Admin;
use ContentGates\Editor;
use WP_UnitTestCase;

/** Covers authorization and stable assignment metadata. */
final class Test_Editor extends WP_UnitTestCase {
	/** Only Groups gain the attribute; existing settings are preserved. */
	public function test_attribute_is_group_only(): void {
		$args = array( 'attributes' => array( 'other' => array( 'type' => 'string' ) ) );
		$this->assertSame( $args, Editor::attributes( $args, 'core/paragraph' ) );
		$group = Editor::attributes( $args, 'core/group' );
		$this->assertSame( array( 'type' => 'number' ), $group['attributes']['contentGateSegmentId'] );
		$this->assertSame( $args['attributes']['other'], $group['attributes']['other'] );
	}

	/** Segment names are private editor metadata; inactive references survive. */
	public function test_segment_lookup_requires_editor_permission(): void {
		$active   = self::factory()->post->create(
			array(
				'post_type'   => 'content_gate_segment',
				'post_title'  => 'Subscribers',
				'post_status' => 'publish',
			)
		);
		$inactive = self::factory()->post->create(
			array(
				'post_type'   => 'content_gate_segment',
				'post_title'  => 'Former members',
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'GET', '/content-gates/v1/segments' );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains(
			array(
				'id'     => $active,
				'name'   => 'Subscribers',
				'active' => true,
			),
			$response->get_data()
		);
		$this->assertContains(
			array(
				'id'     => $inactive,
				'name'   => 'Former members',
				'active' => false,
			),
			$response->get_data()
		);
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		wp_set_current_user( 0 );
	}

	/** No administrative fields are rendered for an unauthorized user. */
	public function test_admin_requires_management_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		ob_start();
		Admin::render();
		$this->assertSame( '', ob_get_clean() );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'content_gates_save_segment', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringNotContainsString( 'delete', $html );
		wp_set_current_user( 0 );
	}
}
