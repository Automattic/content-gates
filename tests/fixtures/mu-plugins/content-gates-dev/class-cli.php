<?php
/**
 * `wp content-gates-dev seed`: demo segments and posts for the local environment.
 *
 * Loaded by content-gates-dev.php under WP-CLI only.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Dev;

use Automattic\VIP\ContentGates\Segments\Segment;
use WP_Error;

/**
 * `wp content-gates-dev`: seed demo data.
 */
final class CLI {

	/**
	 * Create the demo segments and posts. Idempotent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-gates-dev seed
	 */
	public function seed(): void {
		$plugin   = \Automattic\VIP\ContentGates\Plugin::instance();
		$registry = $plugin->segments();

		\WP_CLI::runcommand( 'rewrite structure /%postname%/ --quiet' );

		$registry->sync_crawlers();
		$this->ensure_segment( 'Subscriber' );
		$this->ensure_segment( 'Newsletter' );
		$this->ensure_segment( 'Donor' );
		$this->ensure_segment( 'Metered articles', Segment::TYPE_METER, 3, 30 * DAY_IN_SECONDS );
		$registry->flush();

		$posts = array(
			'gated-article'     => array(
				'title'   => 'Gated article',
				'content' => $this->paragraph( 'This introduction is public. The rest of the article is for subscribers.' )
					. $this->group( array( 'subscriber', 'crawlers-search', 'crawlers-ai' ), 'in', $this->paragraph( 'SUBSCRIBER-ONLY: the full article body. If you can read this sentence without being a subscriber, the gate failed.' ) )
					. $this->group( array( 'subscriber' ), 'not-in', $this->paragraph( 'Not a subscriber yet? Subscribe to keep reading. (This prompt is a Group shown to readers NOT in the subscriber segment.)' ) ),
			),
			'metered-article'   => array(
				'title'   => 'Metered article',
				'content' => $this->paragraph( 'Anonymous readers may open three metered articles per 30 days; subscribers always see everything.' )
					. $this->group( array( 'subscriber', 'metered-articles', 'crawlers-search' ), 'in', $this->paragraph( 'METERED-BODY: you are reading this because you are a subscriber or your meter is open.' ) )
					. $this->group( array( 'subscriber', 'metered-articles' ), 'not-in', $this->paragraph( 'METERED-WALL: your free articles for this period are used up.' ) ),
			),
			'metered-article-2' => array(
				'title'   => 'Metered article 2',
				'content' => $this->group( array( 'subscriber', 'metered-articles' ), 'in', $this->paragraph( 'METERED-BODY-2: a second metered article, for watching the meter count down.' ) ),
			),
			'metered-article-3' => array(
				'title'   => 'Metered article 3',
				'content' => $this->group( array( 'subscriber', 'metered-articles' ), 'in', $this->paragraph( 'METERED-BODY-3: a third metered article, the last free one.' ) ),
			),
			'nested-gates'      => array(
				'title'   => 'Nested gates',
				'content' => $this->paragraph( 'Public intro.' )
					. $this->group(
						array( 'subscriber' ),
						'in',
						$this->paragraph( 'SUBSCRIBER-LAYER: visible to subscribers.' )
						. $this->group( array( 'donor' ), 'in', $this->paragraph( 'DONOR-LAYER: visible only to readers who are subscribers AND donors (inner Group inside outer Group).' ) )
					),
			),
			'newsletter-perk'   => array(
				'title'   => 'Newsletter perk',
				'content' => $this->paragraph( 'A perk for newsletter readers or donors.' )
					. $this->group( array( 'newsletter', 'donor' ), 'in', $this->paragraph( 'PERK-BODY: shown to readers in newsletter OR donor.' ) ),
			),
			'public-post'       => array(
				'title'   => 'Public post',
				'content' => $this->paragraph( 'Nothing is gated here. This URL should have exactly one cached copy.' ),
			),
		);

		foreach ( $posts as $slug => $post ) {
			$existing = get_page_by_path( $slug, OBJECT, 'post' );
			$args     = array(
				'post_title'   => $post['title'],
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_content' => $post['content'],
			);
			if ( $existing instanceof \WP_Post ) {
				$args['ID'] = $existing->ID;
				wp_update_post( $args );
				\WP_CLI::line( "Updated post {$slug} (#{$existing->ID})." );
			} else {
				$id = wp_insert_post( $args );
				\WP_CLI::line( "Created post {$slug} (#{$id})." );
			}
		}

		\WP_CLI::success( 'Seeded. Open ?content_gates_demo=1 on any page to join or leave segments.' );
	}

	/**
	 * Create a segment unless its key exists.
	 *
	 * @param string $name   Name.
	 * @param string $type   Type.
	 * @param int    $limit  Meter limit.
	 * @param int    $period Meter period in seconds.
	 */
	private function ensure_segment( string $name, string $type = Segment::TYPE_BOOLEAN, int $limit = 0, int $period = 0 ): void {
		$registry = \Automattic\VIP\ContentGates\Plugin::instance()->segments();
		$key      = sanitize_title( $name );
		if ( null !== $registry->resolve( $key ) ) {
			\WP_CLI::line( "Segment {$key} exists." );
			return;
		}

		$result = $registry->create( $name, $type, $limit, $period );
		if ( $result instanceof WP_Error ) {
			\WP_CLI::warning( $result->get_error_message() );
			return;
		}

		\WP_CLI::line( "Created segment {$result->key}." );
	}

	/**
	 * Serialized paragraph block.
	 *
	 * @param string $text Text.
	 */
	private function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";
	}

	/**
	 * Serialized gated Group block.
	 *
	 * @param string[] $keys  Segment keys.
	 * @param string   $mode  `in` or `not-in`.
	 * @param string   $inner Inner blocks.
	 */
	private function group( array $keys, string $mode, string $inner ): string {
		$attrs = wp_json_encode(
			array(
				'contentGatesSegments' => $keys,
				'contentGatesMode'     => $mode,
			)
		);

		return "<!-- wp:group {$attrs} -->\n<div class=\"wp-block-group\">{$inner}</div>\n<!-- /wp:group -->\n\n";
	}
}
