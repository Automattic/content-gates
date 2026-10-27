<?php
/**
 * Paywall structured data for crawler variants.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Rendering;

use WP_Post;

/**
 * Emits Google's paywall JSON-LD on singular posts served to a crawler.
 *
 * Only crawler variants carry the markup and the marker classes, so human
 * responses give no hint of which parts are gated. The JSON-LD lists one
 * `hasPart` per paywalled Group that rendered for the crawler, with a
 * `cssSelector` for the class `Block_Gate` added to that Group.
 *
 * It prints on `wp_footer`, after the content, so the count is the one the
 * render produced. Google reads JSON-LD anywhere in the document.
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
		add_action( 'wp_footer', array( $this, 'print_json_ld' ) );
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
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$count = $this->gate->rendered_count( $post->ID );
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
}
