<?php
/**
 * Create or update the deterministic local prototype fixture.
 *
 * Run with:
 * wp eval-file wp-content/plugins/content-gates/tests/fixtures/create-local-fixture.php
 *
 * @package ContentGates
 */

use ContentGates\Block_Visibility;
use ContentGates\Segment_Repository;

call_user_func(
	static function (): void {
		$fixture = get_option( 'content_gates_prototype_fixture', array() );

		$segment_id = isset( $fixture['segment_id'] ) ? absint( $fixture['segment_id'] ) : 0;
		if ( null === Segment_Repository::get( $segment_id ) ) {
			$existing   = get_posts(
				array(
					'post_type'      => Segment_Repository::POST_TYPE,
					'post_status'    => array( Segment_Repository::STATUS_ACTIVE, Segment_Repository::STATUS_INACTIVE ),
					'title'          => 'Prototype Subscribers',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$segment_id = isset( $existing[0] ) ? (int) $existing[0] : 0;
		}

		if ( 0 === $segment_id ) {
			$segment_id = Segment_Repository::create( 'Prototype Subscribers' );
			if ( is_wp_error( $segment_id ) ) {
				throw new RuntimeException( esc_html( $segment_id->get_error_message() ) );
			}
		} else {
			$updated = Segment_Repository::update( $segment_id, 'Prototype Subscribers', true );
			if ( is_wp_error( $updated ) ) {
				throw new RuntimeException( esc_html( $updated->get_error_message() ) );
			}
		}

		$public_marker    = 'CONTENT_GATES_PUBLIC_FIXTURE';
		$protected_marker = 'CONTENT_GATES_PROTECTED_FIXTURE';
		$content          = '<!-- wp:paragraph --><p>' . $public_marker . '</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>Subscribe through the prototype provider to read the member section.</p><!-- /wp:paragraph -->'
		. '<!-- wp:group {"' . Block_Visibility::ATTRIBUTE . '":' . $segment_id . '} --><div class="wp-block-group">'
		. '<!-- wp:heading --><h2 class="wp-block-heading">Members only</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>' . $protected_marker . '</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

		$post_id = isset( $fixture['post_id'] ) ? absint( $fixture['post_id'] ) : 0;
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$existing_post = get_page_by_path( 'content-gates-prototype', OBJECT, 'page' );
			$post_id       = $existing_post instanceof WP_Post ? (int) $existing_post->ID : 0;
		}

		$post_data = array(
			'post_title'   => 'Content Gates Prototype',
			'post_name'    => 'content-gates-prototype',
			'post_content' => $content,
			'post_excerpt' => 'Public prototype article with a server-withheld member section.',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		if ( 0 < $post_id ) {
			$post_data['ID'] = $post_id;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		$fixture = array(
			'segment_id'       => (int) $segment_id,
			'post_id'          => (int) $post_id,
			'public_marker'    => $public_marker,
			'protected_marker' => $protected_marker,
		);
		update_option( 'content_gates_prototype_fixture', $fixture, false );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( wp_json_encode( $fixture ) );
		}
	}
);
