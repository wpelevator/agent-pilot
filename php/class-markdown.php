<?php

namespace WPElevator\Agent_Pilot;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The DOM extension API uses camelCase properties.

/**
 * Converts editor content to Markdown.
 *
 * Accepts either parsed blocks, for content authored in the block editor, or raw
 * HTML, for content that only exists as rendered markup. Both paths share the same
 * inline conversion so emphasis, links and code read identically either way.
 */
class Markdown {

	/**
	 * Elements that introduce their own line in the output and therefore cannot be
	 * folded into the surrounding inline conversion.
	 */
	private const BLOCK_ELEMENTS = [
		'address',
		'article',
		'aside',
		'blockquote',
		'div',
		'figure',
		'footer',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'main',
		'nav',
		'ol',
		'p',
		'pre',
		'section',
		'ul',
	];

	/**
	 * @param array $blocks Parsed blocks, as returned by parse_blocks().
	 */
	public static function from_blocks( array $blocks ): string {
		$parts = [];

		foreach ( $blocks as $block ) {
			$markdown = trim( self::convert_block( $block ) );

			if ( '' !== $markdown ) {
				$parts[] = $markdown;
			}
		}

		return self::normalize_newlines( implode( "\n\n", $parts ) );
	}

	public static function from_html( string $html ): string {
		$container = self::load_container( $html );

		if ( ! $container ) {
			return self::to_plain_text( $html );
		}

		return self::normalize_newlines( trim( self::convert_nodes( $container->childNodes ) ) );
	}

	/**
	 * Convert markup that is known to carry no block-level elements, such as the
	 * contents of a single paragraph or heading.
	 */
	public static function from_inline_html( string $html ): string {
		$container = self::load_container( $html );

		if ( ! $container ) {
			return self::to_plain_text( $html );
		}

		return trim( self::convert_inline_node( $container ) );
	}

	/**
	 * Reduce markup to readable text, keeping the line breaks implied by block
	 * elements so that paragraphs do not run into each other.
	 */
	public static function to_plain_text( string $html ): string {
		$container = self::load_container( $html );

		if ( ! $container ) {
			return self::normalize_newlines( trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}

		return self::normalize_newlines( trim( self::convert_text_node( $container ) ) );
	}

	private static function convert_text_node( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return $node->nodeValue; // The DOM has already decoded any entities.
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$name = strtolower( $node->nodeName );

		if ( 'br' === $name ) {
			return "\n";
		}

		$text = '';

		foreach ( $node->childNodes as $child ) {
			$text .= self::convert_text_node( $child );
		}

		if ( 'li' === $name ) {
			return '' === trim( $text ) ? '' : trim( $text ) . "\n";
		}

		if ( in_array( $name, self::BLOCK_ELEMENTS, true ) ) {
			return '' === trim( $text ) ? '' : trim( $text ) . "\n\n";
		}

		return $text;
	}

	private static function convert_block( array $block ): string {
		switch ( $block['blockName'] ?? null ) {
			case 'core/paragraph':
				return self::from_inline_html( $block['innerHTML'] ) . "\n\n";

			case 'core/heading':
				$level = min( 6, max( 1, (int) ( $block['attrs']['level'] ?? 2 ) ) );
				return str_repeat( '#', $level ) . ' ' . self::from_inline_html( $block['innerHTML'] ) . "\n\n";

			case 'core/list':
				return self::convert_block_list( $block ) . "\n";

			case 'core/list-item':
				return '- ' . self::convert_block_list_item( $block ) . "\n";

			case 'core/code':
			case 'core/preformatted':
				// Trim newlines only, so that leading indentation survives.
				return self::fence( trim( html_entity_decode( wp_strip_all_tags( $block['innerHTML'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), "\n" ) );

			case 'core/quote':
				$content = '';

				foreach ( $block['innerBlocks'] ?? [] as $inner_block ) {
					$content .= self::convert_block( $inner_block );
				}

				return self::quote( $content, self::extract_element( $block['innerHTML'], 'cite' ) );

			case 'core/separator':
				return "---\n\n";

			case 'core/image':
				$image = self::extract_element( $block['innerHTML'], 'img' );
				return '' !== $image ? $image . "\n\n" : '';

			default:
				return '';
		}
	}

	private static function convert_block_list( array $block ): string {
		$ordered = ! empty( $block['attrs']['ordered'] );
		$markdown = '';
		$index = (int) ( $block['attrs']['start'] ?? 1 );

		foreach ( $block['innerBlocks'] ?? [] as $item ) {
			if ( 'core/list-item' !== $item['blockName'] ) {
				continue;
			}

			$markdown .= ( $ordered ? $index . '. ' : '- ' ) . self::convert_block_list_item( $item ) . "\n";

			++$index;
		}

		return $markdown;
	}

	private static function convert_block_list_item( array $item ): string {
		$markdown = self::from_inline_html( implode( '', array_filter( $item['innerContent'], 'is_string' ) ) );

		foreach ( $item['innerBlocks'] ?? [] as $nested_block ) {
			$markdown .= self::indent( self::convert_block( $nested_block ) );
		}

		return $markdown;
	}

	private static function convert_nodes( DOMNodeList $nodes ): string {
		$markdown = '';

		foreach ( $nodes as $node ) {
			$markdown .= self::convert_node( $node );
		}

		return $markdown;
	}

	private static function convert_node( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->nodeValue );

			return '' === $text ? '' : $text . "\n\n";
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$name = strtolower( $node->nodeName );

		switch ( $name ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return str_repeat( '#', (int) substr( $name, 1 ) ) . ' ' . trim( self::convert_inline_node( $node ) ) . "\n\n";

			case 'p':
				return trim( self::convert_inline_node( $node ) ) . "\n\n";

			case 'ul':
			case 'ol':
				return self::convert_html_list( $node ) . "\n";

			case 'pre':
				return self::fence( trim( $node->textContent, "\n" ) );

			case 'blockquote':
				$content = '';
				$citation = '';

				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType && 'cite' === strtolower( $child->nodeName ) ) {
						$citation = trim( self::convert_inline_node( $child ) );
					} else {
						$content .= self::convert_node( $child );
					}
				}

				return self::quote( $content, $citation );

			case 'hr':
				return "---\n\n";

			default:
				if ( self::has_block_children( $node ) ) {
					return self::convert_nodes( $node->childNodes );
				}

				$inline = trim( self::convert_inline_node( $node ) );

				return '' === $inline ? '' : $inline . "\n\n";
		}
	}

	private static function convert_html_list( DOMNode $node ): string {
		$ordered = 'ol' === strtolower( $node->nodeName );
		$start = $node->attributes->getNamedItem( 'start' );
		$index = $start ? (int) $start->nodeValue : 1;
		$markdown = '';

		foreach ( $node->childNodes as $item ) {
			if ( XML_ELEMENT_NODE !== $item->nodeType || 'li' !== strtolower( $item->nodeName ) ) {
				continue;
			}

			$markdown .= ( $ordered ? $index . '. ' : '- ' ) . self::convert_html_list_item( $item ) . "\n";

			++$index;
		}

		return $markdown;
	}

	private static function convert_html_list_item( DOMNode $item ): string {
		$inline = '';
		$nested = '';

		foreach ( $item->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), [ 'ul', 'ol' ], true ) ) {
				$nested .= self::indent( self::convert_html_list( $child ) );
			} else {
				$inline .= self::convert_inline_node( $child );
			}
		}

		return trim( $inline ) . $nested;
	}

	private static function convert_inline_node( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return $node->nodeValue;
		}

		$content = '';

		foreach ( $node->childNodes as $child ) {
			$content .= self::convert_inline_node( $child );
		}

		switch ( strtolower( $node->nodeName ) ) {
			case 'strong':
			case 'b':
				return '**' . $content . '**';

			case 'em':
			case 'i':
				return '*' . $content . '*';

			case 'code':
				return '`' . $content . '`';

			case 'a':
				$href = $node->attributes->getNamedItem( 'href' );
				return $href ? sprintf( '[%s](%s)', $content, $href->nodeValue ) : $content;

			case 'img':
				$src = $node->attributes->getNamedItem( 'src' );
				$alt = $node->attributes->getNamedItem( 'alt' );
				return $src ? sprintf( '![%s](%s)', $alt ? $alt->nodeValue : '', $src->nodeValue ) : '';

			case 'br':
				return "\n";

			default:
				return $content;
		}
	}

	private static function extract_element( string $html, string $element_name ): string {
		$container = self::load_container( $html );

		if ( ! $container ) {
			return '';
		}

		$element = $container->getElementsByTagName( $element_name )->item( 0 );

		return $element ? trim( self::convert_inline_node( $element ) ) : '';
	}

	private static function has_block_children( DOMNode $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), self::BLOCK_ELEMENTS, true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function load_container( string $html ): ?DOMElement {
		if ( ! class_exists( DOMDocument::class ) ) {
			return null;
		}

		$document = new DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );

		$document->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		$container = $document->getElementsByTagName( 'div' )->item( 0 );

		return $container instanceof DOMElement ? $container : null;
	}

	private static function fence( string $content ): string {
		$fence = false !== strpos( $content, '```' ) ? '````' : '```';

		return sprintf( "%s\n%s\n%s\n\n", $fence, $content, $fence );
	}

	private static function quote( string $content, string $citation ): string {
		$content = trim( $content );

		if ( '' !== $citation ) {
			$content .= "\n\n— " . $citation;
		}

		return '> ' . str_replace( "\n", "\n> ", $content ) . "\n\n";
	}

	private static function indent( string $markdown ): string {
		return "\n  " . str_replace( "\n", "\n  ", rtrim( $markdown ) );
	}

	private static function normalize_newlines( string $value ): string {
		return str_replace( [ "\r\n", "\r" ], "\n", $value );
	}
}
