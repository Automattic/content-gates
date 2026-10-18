<?php
/**
 * Block stripper.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Rendering;

/**
 * Removes withheld Groups from raw block content.
 *
 * `pre_render_block` protects every path that renders blocks. Some paths do
 * not: core's `excerpt_remove_blocks()` treats `core/group` as a wrapper
 * and renders its inner blocks one by one, so the Group (and its segment
 * attribute) is never rendered and the gate never fires. Those paths need
 * the same decision applied to the serialized content before it is used.
 *
 * `strip()` parses the content, drops every Group whose decision is
 * `withhold` (the whole subtree, so inner gates are not consulted), keeps
 * everything else, and re-serializes. The decision comes from
 * `Block_Enforcer::decision()`, so the rule lives in one place.
 *
 * @since 0.1.0
 *
 * @phpstan-type Parsed_Block array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, array<string, mixed>>, innerHTML: string, innerContent: array<int, string|null>}
 */
final class Block_Stripper {

	/**
	 * Constructor.
	 *
	 * @param Block_Enforcer $enforcer The decision maker.
	 */
	public function __construct( private Block_Enforcer $enforcer ) {}

	/**
	 * Return the content without withheld Groups.
	 *
	 * Content that mentions no segment attribute is returned untouched
	 * without parsing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Serialized block content.
	 */
	public function strip( string $content ): string {
		if ( ! str_contains( $content, Block_Enforcer::ATTRIBUTE ) ) {
			return $content;
		}

		return serialize_blocks( $this->filter_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Filter a list of parsed blocks recursively.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, Parsed_Block>
	 */
	private function filter_blocks( array $blocks ): array {
		$kept = array();

		foreach ( $blocks as $block ) {
			if ( Block_Enforcer::WITHHOLD === $this->enforcer->decision( $block ) ) {
				continue;
			}

			$kept[] = $this->filter_inner( $block );
		}

		return $kept;
	}

	/**
	 * Filter the inner blocks of one block, keeping `innerContent` in step.
	 *
	 * `innerContent` holds HTML fragments and `null` markers, one marker
	 * per inner block in order. Dropping an inner block means dropping its
	 * marker too, or `serialize_blocks()` would pair markers with the wrong
	 * blocks. The result is normalized to the parser's block shape.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return Parsed_Block
	 */
	private function filter_inner( array $block ): array {
		$inner_blocks  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : array();
		$inner_content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();

		$kept_blocks  = array();
		$kept_content = array();
		$index        = 0;

		foreach ( $inner_content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$kept_content[] = $chunk;
				continue;
			}

			$child = $inner_blocks[ $index ] ?? null;
			++$index;

			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( Block_Enforcer::WITHHOLD === $this->enforcer->decision( $child ) ) {
				continue;
			}

			$kept_blocks[]  = $this->filter_inner( $child );
			$kept_content[] = null;
		}

		$name  = $block['blockName'] ?? null;
		$attrs = $block['attrs'] ?? null;
		$html  = $block['innerHTML'] ?? null;

		return array(
			'blockName'    => is_string( $name ) ? $name : null,
			'attrs'        => is_array( $attrs ) ? $attrs : array(),
			'innerBlocks'  => $kept_blocks,
			'innerHTML'    => is_string( $html ) ? $html : '',
			'innerContent' => $kept_content,
		);
	}
}
