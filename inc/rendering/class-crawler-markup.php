<?php
/**
 * Crawler markup.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use WP_Post;

/**
 * Emits paywall structured data on the crawler variant.
 *
 * On a singular post, a reader in the reserved `crawler` segment who is
 * denied a gated Group receives that Group in full, wrapped in
 * `.content-gates-paywall` (see `Block_Enforcer`), plus this JSON-LD in
 * `wp_head`, which tells search engines that the wrapped parts are not
 * free. See
 * https://developers.google.com/search/docs/appearance/structured-data/paywalled-content.
 *
 * `wp_head` runs before the content renders, so the render decisions are
 * not known yet. This class pre-evaluates them: it parses the post content
 * and runs every gated Group through `Block_Enforcer::decision()` the way
 * the render will, skipping the subtree of a withheld Group (its inner
 * gates are never consulted) and of an author-hidden Group (it never
 * renders, so it cannot carry the wrapper). The JSON-LD is printed only
 * when at least one decision is `crawler`, which is exactly when the page
 * contains a `.content-gates-paywall` element. Membership is fixed for the
 * request, so the render reaches the same decisions and records the same
 * reads; the consulted set is unchanged in effect. The enforcer's rule
 * stands: `crawler` is read only after a denial, so an entitled reader's
 * page, and a crawler who is also a member, never vary on `crawler` and
 * get no JSON-LD.
 *
 * @since 0.1.0
 */
final class Crawler_Markup {

	/**
	 * Constructor.
	 *
	 * @param Block_Enforcer $enforcer The decision maker.
	 */
	public function __construct( private Block_Enforcer $enforcer ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_markup' ), 10 );
	}

	/**
	 * Hook callback for `wp_head`.
	 *
	 * @since 0.1.0
	 */
	public function print_markup(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! str_contains( $post->post_content, Block_Enforcer::ATTRIBUTE ) ) {
			return;
		}

		if ( ! $this->has_crawler_decision( parse_blocks( $post->post_content ), $post->ID ) ) {
			return;
		}

		$json = self::json_ld();
		if ( '' === $json ) {
			return;
		}

		// The JSON is produced by wp_json_encode() with JSON_HEX_TAG and
		// JSON_HEX_AMP, so it cannot close the script element or inject markup.
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Whether any gated Group in these blocks will render on the crawler
	 * variant for this reader.
	 *
	 * Mirrors the render: a withheld Group's subtree is skipped, an allowed
	 * Group's inner blocks are walked, and a hidden Group is skipped.
	 *
	 * @param array<int|string, mixed> $blocks  Parsed blocks.
	 * @param int                      $post_id The post being rendered.
	 */
	private function has_crawler_decision( array $blocks, int $post_id ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( self::is_hidden( $block ) ) {
				continue;
			}

			if ( Block_Enforcer::is_gated( $block ) ) {
				$decision = $this->enforcer->decision( $block, $post_id );

				if ( Block_Enforcer::CRAWLER === $decision ) {
					return true;
				}

				if ( Block_Enforcer::WITHHOLD === $decision ) {
					continue;
				}
			}

			$inner = $block['innerBlocks'] ?? null;
			if ( is_array( $inner ) && $this->has_crawler_decision( $inner, $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the author hid a block (`metadata.blockVisibility` is `false`).
	 *
	 * Core's block visibility support renders such a block as `''`.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function is_hidden( array $block ): bool {
		$attrs    = $block['attrs'] ?? null;
		$metadata = is_array( $attrs ) ? ( $attrs['metadata'] ?? null ) : null;

		return is_array( $metadata ) && false === ( $metadata['blockVisibility'] ?? null );
	}

	/**
	 * The structured data as a JSON string, '' on encoding failure.
	 *
	 * @since 0.1.0
	 */
	public static function json_ld(): string {
		$json = wp_json_encode( self::data(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * The structured data as an array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		return array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Article',
			'isAccessibleForFree' => false,
			'hasPart'             => array(
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.' . Block_Enforcer::PAYWALL_CLASS,
			),
		);
	}
}
