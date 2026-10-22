<?php
/**
 * Paywall structured data for crawler variants.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Rendering;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use WP_Post;

/**
 * Emits Google's paywall JSON-LD on singular posts served to a crawler.
 *
 * Only crawler variants carry the markup and the marker classes, so human
 * responses give no hint of which parts are gated. The JSON-LD lists one
 * `hasPart` per gated Group that renders for the crawler, with a
 * `cssSelector` for the class `Block_Gate` adds to that Group.
 *
 * `wp_head` runs before the content, so the Groups are counted here by
 * walking the saved blocks with the same decision the render will make.
 *
 * @since 0.1.0
 */
final class Structured_Data {

	/**
	 * Constructor.
	 *
	 * @param Block_Gate $gate Gating decision.
	 */
	public function __construct( private Block_Gate $gate ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_json_ld' ) );
	}

	/**
	 * Print the JSON-LD when this is a crawler variant of a gated singular post.
	 *
	 * @since 0.1.0
	 */
	public function print_json_ld(): void {
		if ( ! is_singular() || ! $this->gate->is_crawler() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || array() === Declared_Set::stored_keys( $post->ID ) ) {
			return;
		}

		$count = $this->count_rendered_groups( parse_blocks( $post->post_content ) );
		if ( 0 === $count ) {
			return;
		}

		$parts = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$parts[] = array(
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.' . Block_Gate::MARKER_CLASS . '-' . $i,
			);
		}

		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Article',
			'headline'            => get_the_title( $post ),
			'mainEntityOfPage'    => get_permalink( $post ),
			'isAccessibleForFree' => false,
			'hasPart'             => $parts,
		);

		printf(
			"<script type=\"application/ld+json\" id=\"content-gates-paywall\">%s</script>\n",
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built from escaped scalars; wp_json_encode escapes `</`.
		);
	}

	/**
	 * Count gated Groups that will render, in the order the render numbers them.
	 *
	 * Mirrors `Block_Gate`: a withheld Group's subtree is skipped, an
	 * author-hidden Group is skipped because it never renders, and a Group
	 * inside a rendered Group is walked.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 */
	private function count_rendered_groups( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || Block_Gate::is_author_hidden( $block ) ) {
				continue;
			}

			$keys = Block_Gate::segments_of( $block );
			if ( array() !== $keys ) {
				if ( $this->gate->should_withhold( $keys, Block_Gate::mode_of( $block ) ) ) {
					continue;
				}
				++$count;
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$count += $this->count_rendered_groups( $block['innerBlocks'] );
			}
		}

		return $count;
	}
}
