<?php
/**
 * Keeps gated content out of generated excerpts.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Rendering;

use Automattic\VIP\ContentGates\Membership\Declared_Set;
use WP_Post;

/**
 * Builds auto-excerpts from content with withheld Groups removed.
 *
 * @phpstan-type Parsed_Block array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int|string, array<string, mixed>>, innerHTML: string, innerContent: array<int, string|null>}
 *
 * Core's `wp_trim_excerpt()` runs `excerpt_remove_blocks()`, which treats
 * `core/group` as a wrapper: it renders the Group's inner blocks one by one
 * and drops the wrapper, so `pre_render_block` never sees the Group's
 * attributes and gated text lands in the excerpt. Archives, search, summary
 * feeds, REST `excerpt.rendered`, and the embed template all read
 * `get_the_excerpt()`, so this guard covers them in one place.
 *
 * It hooks `get_the_excerpt` twice, around core's `wp_trim_excerpt` at 10.
 * At 9 it computes the excerpt from a stripped clone of the post and
 * remembers it. At 11 it returns the remembered value, so whatever core
 * produced at 10 is discarded. The second hook matters when a post is
 * nothing but gated Groups: the stripped excerpt is `''`, which core treats
 * as "no excerpt yet" and would regenerate from the original content.
 *
 * Manual excerpts are public editorial text and are never touched.
 *
 * @since 0.1.0
 */
final class Excerpt_Guard {

	/**
	 * Remembered excerpts keyed by post ID, stacked for nested calls.
	 *
	 * @var array<int, string[]>
	 */
	private array $remembered = array();

	/**
	 * Constructor.
	 *
	 * @param Block_Gate   $gate     Gating decision.
	 * @param Declared_Set $declared Declared set.
	 */
	public function __construct( private Block_Gate $gate, private Declared_Set $declared ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'get_the_excerpt', array( $this, 'build' ), 9, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'restore' ), 11, 2 );
	}

	/**
	 * Compute a safe auto-excerpt before core does. Filter callback for `get_the_excerpt` at 9.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $excerpt Manual excerpt, or `''`.
	 * @param WP_Post $post    Post.
	 */
	public function build( string $excerpt, WP_Post $post ): string {
		if ( '' !== $excerpt || array() === Declared_Set::stored_keys( $post->ID ) ) {
			return $excerpt;
		}

		$this->declared->merge_post( $post->ID );

		$clone               = clone $post;
		$clone->post_content = $this->strip( $post->post_content );

		remove_filter( 'get_the_excerpt', array( $this, 'build' ), 9 );
		$safe = wp_trim_excerpt( '', $clone );
		add_filter( 'get_the_excerpt', array( $this, 'build' ), 9, 2 );

		$this->remembered[ $post->ID ][] = $safe;

		return $safe;
	}

	/**
	 * Return the remembered excerpt, discarding core's. Filter callback for `get_the_excerpt` at 11.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $excerpt Excerpt as produced so far.
	 * @param WP_Post $post    Post.
	 */
	public function restore( string $excerpt, WP_Post $post ): string {
		if ( ! isset( $this->remembered[ $post->ID ] ) || array() === $this->remembered[ $post->ID ] ) {
			return $excerpt;
		}

		$safe = array_pop( $this->remembered[ $post->ID ] );
		if ( array() === $this->remembered[ $post->ID ] ) {
			unset( $this->remembered[ $post->ID ] );
		}

		return $safe;
	}

	/**
	 * Serialized content with every withheld Group removed, subtree included.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Block content.
	 */
	public function strip( string $content ): string {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}

		return serialize_blocks( $this->strip_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Treat loosely typed inner blocks as parsed blocks.
	 *
	 * Core types `innerBlocks` as plain arrays, but the parser produces the
	 * same shape at every level. Elements that lack the parser's keys are
	 * dropped instead of trusted.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Inner blocks.
	 * @return array<int, Parsed_Block>
	 */
	private function as_blocks( array $blocks ): array {
		$typed = array();
		foreach ( $blocks as $block ) {
			if ( ! array_key_exists( 'blockName', $block ) || ! isset( $block['attrs'], $block['innerBlocks'], $block['innerHTML'], $block['innerContent'] ) ) {
				continue;
			}
			if ( ( null !== $block['blockName'] && ! is_string( $block['blockName'] ) ) || ! is_array( $block['attrs'] ) || ! is_array( $block['innerBlocks'] ) || ! is_string( $block['innerHTML'] ) || ! is_array( $block['innerContent'] ) ) {
				continue;
			}
			$typed[] = array(
				'blockName'    => $block['blockName'],
				'attrs'        => $block['attrs'],
				'innerBlocks'  => $block['innerBlocks'],
				'innerHTML'    => $block['innerHTML'],
				'innerContent' => $block['innerContent'],
			);
		}

		return $typed;
	}

	/**
	 * Recursively drop withheld Groups, keeping `innerContent` markers in step.
	 *
	 * @param array<int|string, Parsed_Block> $blocks Parsed blocks.
	 * @return array<int, Parsed_Block>
	 */
	private function strip_blocks( array $blocks ): array {
		$kept = array();
		foreach ( $blocks as $block ) {
			$keys = Block_Gate::segments_of( $block );
			if ( array() !== $keys && $this->gate->should_withhold( $keys, Block_Gate::mode_of( $block ) ) ) {
				continue;
			}

			if ( array() !== $block['innerBlocks'] ) {
				$block = $this->strip_inner( $block );
			}

			$kept[] = $block;
		}

		return $kept;
	}

	/**
	 * Strip a block's inner blocks and rebuild `innerContent` to match.
	 *
	 * @param array<string, mixed> $block Parsed block with inner blocks.
	 * @return Parsed_Block
	 *
	 * @phpstan-param Parsed_Block $block
	 */
	private function strip_inner( array $block ): array {
		$inner       = $block['innerBlocks'];
		$content     = $block['innerContent'];
		$new_inner   = array();
		$new_content = array();
		$index       = 0;

		foreach ( $content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$new_content[] = $chunk;
				continue;
			}

			$child = $inner[ $index ] ?? null;
			++$index;
			if ( ! is_array( $child ) ) {
				continue;
			}

			$stripped = $this->strip_blocks( $this->as_blocks( array( $child ) ) );
			if ( array() === $stripped ) {
				continue;
			}

			$new_inner[]   = $stripped[0];
			$new_content[] = null;
		}

		$block['innerBlocks']  = $new_inner;
		$block['innerContent'] = $new_content;

		return $block;
	}
}
