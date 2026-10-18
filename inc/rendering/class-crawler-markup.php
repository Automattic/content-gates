<?php
/**
 * Crawler markup.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

use ContentGates\Membership\Membership;
use ContentGates\Segments\Segment_Registry;
use WP_Post;

/**
 * Emits paywall structured data on the crawler variant.
 *
 * On a singular post whose content contains a gated Group, a reader in the
 * reserved `crawler` segment receives the Groups in full (see
 * `Block_Enforcer`) plus this JSON-LD in `wp_head`, which tells search
 * engines that the `.content-gates-paywall` parts are not free. See
 * https://developers.google.com/search/docs/appearance/structured-data/paywalled-content.
 *
 * The `crawler` read is made only when the content has a gated Group, so
 * ungated pages do not vary on the crawler segment.
 *
 * @since 0.1.0
 */
final class Crawler_Markup {

	/**
	 * Constructor.
	 *
	 * @param Membership $membership Membership layer.
	 */
	public function __construct( private Membership $membership ) {}

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

		if ( ! $this->membership->is_in( Segment_Registry::CRAWLER_KEY ) ) {
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
