<?php
/**
 * OpenTBS integration helpers for Resolate.
 *
 * @package Resolate
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Lightweight OpenTBS wrapper for Resolate.
 */
class Resolate_OpenTBS {



	/**
	 * WordprocessingML namespace used in DOCX documents.
	 */
	private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * ODF text namespace.
	 */
	private const ODF_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * ODF office namespace.
	 */
	private const ODF_OFFICE_NS = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

	/**
	 * ODF style namespace.
	 */
	private const ODF_STYLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

	/**
	 * ODF XSL-FO namespace.
	 */
	private const ODF_FO_NS = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

	/**
	 * XLink namespace used for hyperlinks in ODT documents.
	 */
	private const ODF_XLINK_NS = 'http://www.w3.org/1999/xlink';

	/**
	 * Ensure libraries are loaded.
	 *
	 * @return bool
	 */
	public static function load_libs() {
		$base = plugin_dir_path( __DIR__ ) . 'admin/vendor/tinybutstrong/';
		$tbs = $base . 'tinybutstrong/tbs_class.php';
		$otb = $base . 'opentbs/tbs_plugin_opentbs.php';
		if ( file_exists( $tbs ) && file_exists( $otb ) ) {
			require_once $tbs; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			require_once $otb; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			return class_exists( 'clsTinyButStrong' ) && defined( 'OPENTBS_PLUGIN' );
		}
		return false;
	}

	/**
	 * Render an ODT from template and data.
	 *
	 * @param string $template_path Absolute path to .odt template.
	 * @param array  $fields        Associative fields.
	 * @param string $dest_path     Output file path.
	 * @param array  $rich_values   Optional rich text values (unused for ODT).
	 * @return bool|WP_Error
	 */
	public static function render_odt( $template_path, $fields, $dest_path, $rich_values = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rich_result = self::apply_odt_rich_text( $dest_path, $rich_values );
		if ( is_wp_error( $rich_result ) ) {
			return $rich_result;
		}

		return $result;
	}

	/**
	 * Render a DOCX from template and data (same as ODT).
	 *
	 * @param string $template_path Template path.
	 * @param array  $fields        Fields map.
	 * @param string $dest_path     Output path.
	 * @param array  $rich_values   Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	public static function render_docx( $template_path, $fields, $dest_path, $rich_values = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rich_result = self::apply_docx_rich_text( $dest_path, $rich_values );
		if ( is_wp_error( $rich_result ) ) {
			return $rich_result;
		}
		return $result;
	}

	/**
	 * Render a template to disk using OpenTBS.
	 *
	 * @param string $template_path Absolute template path.
	 * @param array  $fields        Merge fields map.
	 * @param string $dest_path     Output destination.
	 * @return bool|WP_Error
	 */
	private static function render_template_to_file( $template_path, $fields, $dest_path ) {
		if ( ! self::load_libs() ) {
			return new WP_Error( 'resolate_opentbs_missing', __( 'OpenTBS no está disponible.', 'resolate' ) );
		}
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'resolate_template_missing', __( 'Plantilla no encontrada.', 'resolate' ) );
		}
		try {
			$tbs_engine = new clsTinyButStrong();
			$tbs_engine->Plugin( TBS_INSTALL, OPENTBS_PLUGIN );
			$tbs_engine->LoadTemplate( $template_path, OPENTBS_ALREADY_UTF8 );

            if ( ! is_array( $fields ) ) {
                $fields = array();
            }

            $tbs_engine->ResetVarRef( false );

            // First merge repeater blocks (arrays), then scalar fields.
            foreach ( $fields as $k => $v ) {
                if ( ! is_string( $k ) || '' === $k ) {
                    continue;
                }
                if ( is_array( $v ) ) {
                    // Merge repeatable blocks with the same key as the block name.
                    // TBS expects a sequential array of associative rows.
                    $tbs_engine->MergeBlock( $k, $v );
                }
            }

            foreach ( $fields as $k => $v ) {
                if ( ! is_string( $k ) || '' === $k ) {
                    continue;
                }
                if ( is_array( $v ) ) {
                    // Arrays are handled via MergeBlock; avoid printing "Array" by skipping MergeField.
                    continue;
                }
                $tbs_engine->SetVarRefItem( $k, $v );
                $tbs_engine->MergeField( $k, $v );
            }

			$tbs_engine->Show( OPENTBS_FILE, $dest_path );
			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_opentbs_error', $e->getMessage() );
		}
	}

	/**
	 * Post-process a DOCX file replacing HTML strings with formatted runs.
	 *
	 * @param string       $doc_path    Generated DOCX path.
	 * @param array<mixed> $rich_values Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	private static function apply_docx_rich_text( $doc_path, $rich_values ) {
			$lookup = self::prepare_rich_lookup( $rich_values );
		if ( empty( $lookup ) ) {
				return true;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'resolate_docx_zip_missing', __( 'ZipArchive no está disponible para aplicar formato enriquecido.', 'resolate' ) );
		}
			$zip = new ZipArchive();
		if ( true !== $zip->open( $doc_path ) ) {
			return new WP_Error( 'resolate_docx_zip_open', __( 'No se pudo abrir el DOCX generado para aplicar formato.', 'resolate' ) );
		}
			$targets     = array();
			$total_files = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		for ( $i = 0; $i < $total_files; $i++ ) {
				$name = $zip->getNameIndex( $i );
			if ( preg_match( '/^word\/(document|header[0-9]*|footer[0-9]*).xml$/', $name ) ) {
					$targets[] = $name;
			}
		}
			$changed = false;
		foreach ( $targets as $target ) {
				$xml = $zip->getFromName( $target );
			if ( false === $xml ) {
					continue;
			}
				$relationships = self::load_relationships_for_part( $zip, $target );
				$updated       = self::convert_docx_part_rich_text( $xml, $lookup, $relationships );
			if ( $updated !== $xml ) {
					$zip->addFromString( $target, $updated );
					$changed = true;
			}
			if ( is_array( $relationships ) && ! empty( $relationships['modified'] ) && ! empty( $relationships['path'] ) ) {
					$zip->addFromString( $relationships['path'], $relationships['doc']->saveXML() );
					$changed = true;
			}
		}
			$zip->close();
			return $changed;
	}

	/**
	 * Replace HTML fragments in a DOCX XML part with formatted runs.
	 *
	 * @param string                   $xml            Original XML part contents.
	 * @param array<string,string>     $lookup         Rich text lookup table.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 * @return string
	 */
	public static function convert_docx_part_rich_text( $xml, $lookup, &$relationships = null ) {
			$rich_lookup = self::prepare_rich_lookup( $lookup );
		if ( empty( $rich_lookup ) ) {
				return $xml;
		}
			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$dom->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			libxml_use_internal_errors( true );
			$loaded = $dom->loadXML( $xml );
			libxml_clear_errors();
		if ( ! $loaded ) {
			return $xml;
		}
			$xpath = new DOMXPath( $dom );
			$xpath->registerNamespace( 'w', self::WORD_NAMESPACE );
			$nodes    = $xpath->query( '//w:t' );
			$modified = false;
		if ( $nodes instanceof DOMNodeList ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}
				$value = html_entity_decode( $node->textContent, ENT_QUOTES | ENT_XML1, 'UTF-8' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '' === $value || ! isset( $rich_lookup[ $value ] ) ) {
					continue;
				}
				$run = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( ! $run instanceof DOMElement ) {
						continue;
				}
							$base_rpr = self::clone_run_properties( $run );
							$runs     = self::build_docx_runs_from_html( $dom, $value, $base_rpr, $relationships );
				if ( empty( $runs ) ) {
						continue;
				}
							$parent = $run->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( ! $parent ) {
					continue;
				}
				foreach ( $runs as $new_run ) {
					$parent->insertBefore( $new_run, $run );
				}
				$parent->removeChild( $run );
				$modified = true;
			}
		}
			return $modified ? $dom->saveXML() : $xml;
	}

	/**
	 * Prepare rich text values as a lookup table keyed by raw HTML.
	 *
	 * @param array<mixed> $values Potential rich text values.
	 * @return array<string,string>
	 */
	private static function prepare_rich_lookup( $values ) {
		$lookup = array();
		if ( ! is_array( $values ) ) {
			return $lookup;
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( false === strpos( $value, '<' ) || false === strpos( $value, '>' ) ) {
				continue;
			}
			$lookup[ $value ] = $value;
		}
		return $lookup;
	}

	/**
	 * Replace HTML fragments in the generated ODT archive with formatted markup.
	 *
	 * @param string       $odt_path    Generated ODT path.
	 * @param array<mixed> $rich_values Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	private static function apply_odt_rich_text( $odt_path, $rich_values ) {
		$lookup = self::prepare_rich_lookup( $rich_values );
		if ( empty( $lookup ) ) {
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'resolate_odt_zip_missing', __( 'ZipArchive no está disponible para aplicar formato enriquecido en ODT.', 'resolate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $odt_path ) ) {
			return new WP_Error( 'resolate_odt_open_failed', __( 'No se pudo abrir el archivo ODT para aplicar formato enriquecido.', 'resolate' ) );
		}

		$targets = array( 'content.xml', 'styles.xml' );
		foreach ( $targets as $target ) {
			$xml = $zip->getFromName( $target );
			if ( false === $xml ) {
				continue;
			}

			$updated = self::convert_odt_part_rich_text( $xml, $lookup );
			if ( is_wp_error( $updated ) ) {
				$zip->close();
				return $updated;
			}

			if ( $updated !== $xml ) {
				$zip->addFromString( $target, $updated );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Convert HTML placeholders inside an ODT XML part to styled markup.
	 *
	 * @param string               $xml    Original XML contents.
	 * @param array<string,string> $lookup Rich text lookup table.
	 * @return string|WP_Error
	 */
	private static function convert_odt_part_rich_text( $xml, $lookup ) {
		if ( empty( $lookup ) ) {
			return $xml;
		}

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $xml );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return $xml;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );

		$modified      = false;
		$style_require = array();
		$nodes         = $xpath->query( '//text()' );
		if ( $nodes instanceof DOMNodeList ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMText ) {
					continue;
				}
				$changed = self::replace_odt_text_node_html( $node, $lookup, $style_require );
				if ( $changed ) {
					$modified = true;
				}
			}
		}

		if ( $modified ) {
			if ( ! empty( $style_require ) ) {
				self::ensure_odt_styles( $doc, $style_require );
			}
			return $doc->saveXML();
		}

		return $xml;
	}

	/**
	 * Replace HTML fragments inside a DOMText node with formatted ODT nodes.
	 *
	 * @param DOMText              $text_node     Text node to inspect.
	 * @param array<string,string> $lookup        Rich text lookup table.
	 * @param array<string,bool>   $style_require Styles required so far.
	 * @return bool
	 */
	private static function replace_odt_text_node_html( DOMText $text_node, $lookup, array &$style_require ) {
		$value  = $text_node->wholeText; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc    = $text_node->ownerDocument;
		$parent = $text_node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $doc || ! $parent ) {
			return false;
		}

		$position = 0;
		$modified = false;

		while ( true ) {
			$match = self::find_next_html_match( $value, $lookup, $position );
			if ( false === $match ) {
				break;
			}

			list( $match_pos, $match_html ) = $match;
			if ( $match_pos > $position ) {
				$segment = substr( $value, $position, $match_pos - $position );
				if ( '' !== $segment ) {
					$parent->insertBefore( $doc->createTextNode( $segment ), $text_node );
				}
			}

			$nodes = self::build_odt_inline_nodes( $doc, $match_html, $style_require );
			foreach ( $nodes as $node ) {
				$parent->insertBefore( $node, $text_node );
			}

			$position = $match_pos + strlen( $match_html );
			$modified = true;
		}

		if ( $modified ) {
			$tail = substr( $value, $position );
			if ( '' !== $tail ) {
				$parent->insertBefore( $doc->createTextNode( $tail ), $text_node );
			}
			$parent->removeChild( $text_node );
		}

		return $modified;
	}

	/**
	 * Find the next HTML fragment occurrence within a text string.
	 *
	 * @param string               $text     Source text.
	 * @param array<string,string> $lookup   Lookup table.
	 * @param int                  $position Starting offset.
	 * @return array{int,string}|false
	 */
	private static function find_next_html_match( $text, $lookup, $position ) {
		$found_pos  = false;
		$found_html = '';
		foreach ( $lookup as $html => $raw ) {
			unset( $raw );
			$pos = strpos( $text, $html, $position );
			if ( false === $pos ) {
				continue;
			}
			if ( false === $found_pos || $pos < $found_pos || ( $pos === $found_pos && strlen( $html ) > strlen( $found_html ) ) ) {
				$found_pos  = $pos;
				$found_html = $html;
			}
		}

		if ( false === $found_pos ) {
			return false;
		}

		return array( $found_pos, $found_html );
	}

	/**
	 * Build ODT inline nodes for a HTML fragment.
	 *
	 * @param DOMDocument        $doc           Destination document.
	 * @param string             $html          HTML fragment.
	 * @param array<string,bool> $style_require Styles required so far.
	 * @return array<int,DOMNode>
	 */
	private static function build_odt_inline_nodes( DOMDocument $doc, $html, array &$style_require ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$tmp = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $tmp->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array( $doc->createTextNode( $html ) );
		}

		$container = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $container ) {
			return array( $doc->createTextNode( $html ) );
		}

		$list_state = array(
			'unordered' => 0,
			'ordered'   => array(),
		);

		$result = array();
		foreach ( $container->childNodes as $child ) {
			$converted = self::convert_html_node_to_odt( $doc, $child, array(), $style_require, $list_state );
			if ( ! empty( $converted ) ) {
				$result = array_merge( $result, $converted );
			}
		}

		self::trim_odt_inline_nodes( $result );
		return $result;
	}

	/**
	 * Convert an HTML node into ODT inline nodes.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMNode             $node          HTML node to convert.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @param array<string,mixed> $list_state   Current list state.
	 * @return array<int,DOMNode>
	 */
	private static function convert_html_node_to_odt( DOMDocument $doc, $node, $formatting, array &$style_require, array &$list_state ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$text = $node->nodeValue;
			if ( '' === $text ) {
				return array();
			}

			$text_node = $doc->createTextNode( $text );
			return self::wrap_nodes_with_formatting( $doc, array( $text_node ), $formatting, $style_require );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return array();
		}

		$tag = strtolower( $node->nodeName );
		switch ( $tag ) {
			case 'br':
				return array( $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ) );
			case 'strong':
			case 'b':
				$formatting['bold'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'em':
			case 'i':
				$formatting['italic'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'u':
				$formatting['underline'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'span':
				if ( $node->hasAttribute( 'style' ) ) {
					$style_attr = strtolower( $node->getAttribute( 'style' ) );
					if ( false !== strpos( $style_attr, 'font-weight:bold' ) || false !== strpos( $style_attr, 'font-weight:700' ) ) {
						$formatting['bold'] = true;
					}
					if ( false !== strpos( $style_attr, 'font-style:italic' ) ) {
						$formatting['italic'] = true;
					}
					if ( false !== strpos( $style_attr, 'text-decoration:underline' ) ) {
						$formatting['underline'] = true;
					}
				}
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'a':
				$href = trim( $node->getAttribute( 'href' ) );
				if ( '' !== $href ) {
					$formatting['link'] = $href;
				}
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'p':
			case 'div':
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				if ( empty( $children ) ) {
					return array();
				}
				$result = $children;
				$result[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
				return $result;
			case 'ul':
				$list_state['unordered']++;
				$items = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $items ) ) {
						$items[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$items = array_merge( $items, self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state ) );
				}
				$list_state['unordered'] = max( 0, $list_state['unordered'] - 1 );
				return $items;
			case 'ol':
				$list_state['ordered'][] = 1;
				$ordered = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $ordered ) ) {
						$ordered[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$ordered = array_merge( $ordered, self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state ) );
					$index                     = count( $list_state['ordered'] ) - 1;
					$list_state['ordered'][ $index ]++;
				}
				array_pop( $list_state['ordered'] );
				return $ordered;
			case 'li':
				$line = array();
				if ( ! empty( $list_state['ordered'] ) ) {
					$numbers = $list_state['ordered'];
					$numbers[ count( $numbers ) - 1 ]--;
					$prefix = implode( '.', $numbers ) . '. ';
					$line   = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $prefix ) ), $formatting, $style_require );
					$list_state['ordered'][ count( $list_state['ordered'] ) - 1 ]++;
				} elseif ( $list_state['unordered'] > 0 ) {
					$indent = str_repeat( '  ', max( 0, $list_state['unordered'] - 1 ) );
					$bullet = $indent . '• ';
					$line   = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $bullet ) ), $formatting, $style_require );
				}
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$line     = array_merge( $line, $children );
				return $line;
			default:
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
		}
	}

	/**
	 * Convert all child nodes for the provided HTML node.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMNode             $node          HTML node.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @param array<string,mixed> $list_state   Current list state.
	 * @return array<int,DOMNode>
	 */
	private static function collect_html_children_as_odt( DOMDocument $doc, DOMNode $node, $formatting, array &$style_require, array &$list_state ) {
		$result = array();
		foreach ( $node->childNodes as $child ) {
			$converted = self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state );
			if ( ! empty( $converted ) ) {
				$result = array_merge( $result, $converted );
			}
		}
		return $result;
	}

	/**
	 * Apply formatting wrappers to a list of nodes.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param array<int,DOMNode>  $nodes         Nodes to wrap.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @return array<int,DOMNode>
	 */
	private static function wrap_nodes_with_formatting( DOMDocument $doc, array $nodes, $formatting, array &$style_require ) {
		$result = $nodes;
		if ( empty( $result ) ) {
			return $result;
		}

		if ( ! empty( $formatting['bold'] ) ) {
			$style_require['bold'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'ResolateRichBold' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['italic'] ) ) {
			$style_require['italic'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'ResolateRichItalic' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['underline'] ) ) {
			$style_require['underline'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'ResolateRichUnderline' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['link'] ) ) {
			$href = (string) $formatting['link'];
			$link = $doc->createElementNS( self::ODF_TEXT_NS, 'text:a' );
			$link->setAttributeNS( self::ODF_XLINK_NS, 'xlink:href', $href );
			$link->setAttributeNS( self::ODF_XLINK_NS, 'xlink:type', 'simple' );
			$link->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'ResolateRichLink' );
			$style_require['link'] = true;
			foreach ( $result as $child ) {
				$link->appendChild( $child );
			}
			$result = array( $link );
		}

		return $result;
	}

	/**
	 * Trim trailing line-break elements from the generated node list.
	 *
	 * @param array<int,DOMNode> $nodes Node list reference.
	 * @return void
	 */
	private static function trim_odt_inline_nodes( array &$nodes ) {
		while ( ! empty( $nodes ) ) {
			$last = end( $nodes );
			if ( $last instanceof DOMElement && self::ODF_TEXT_NS === $last->namespaceURI && 'line-break' === $last->localName ) {
				array_pop( $nodes );
				continue;
			}
			if ( $last instanceof DOMText ) {

				$value   = $last->nodeValue;
				$trimmed = rtrim( $value, "\r\n" );
				if ( $trimmed !== $value ) {
					if ( '' === $trimmed ) {
						array_pop( $nodes );
						continue;
					}
					$last->nodeValue = $trimmed;
				}
			}
			break;
		}
	}

	/**
	 * Ensure automatic styles required for HTML conversion are present.
	 *
	 * @param DOMDocument        $doc           XML document.
	 * @param array<string,bool> $style_require Styles that must exist.
	 * @return void
	 */
	private static function ensure_odt_styles( DOMDocument $doc, array $style_require ) {
		if ( empty( $style_require ) ) {
			return;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'fo', self::ODF_FO_NS );

		$auto = $xpath->query( '/*/office:automatic-styles' )->item( 0 );
		if ( ! $auto instanceof DOMElement ) {
			$root = $doc->documentElement;
			if ( ! $root instanceof DOMElement ) {
				return;
			}
			$auto = $doc->createElementNS( self::ODF_OFFICE_NS, 'office:automatic-styles' );
			$root->insertBefore( $auto, $root->firstChild );
		}

		$styles = array(
			'bold'      => array(
				'name'   => 'ResolateRichBold',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:font-weight',
						'value' => 'bold',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-weight-asian',
						'value' => 'bold',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-weight-complex',
						'value' => 'bold',
					),
				),
			),
			'italic'    => array(
				'name'   => 'ResolateRichItalic',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:font-style',
						'value' => 'italic',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-style-asian',
						'value' => 'italic',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-style-complex',
						'value' => 'italic',
					),
				),
			),
			'underline' => array(
				'name'   => 'ResolateRichUnderline',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-style',
						'value' => 'solid',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-width',
						'value' => 'auto',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-color',
						'value' => 'font-color',
					),
				),
			),
			'link'      => array(
				'name'   => 'ResolateRichLink',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:color',
						'value' => '#0000FF',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-style',
						'value' => 'solid',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-width',
						'value' => 'auto',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-color',
						'value' => 'font-color',
					),
				),
			),
		);

		foreach ( $style_require as $key => $flag ) {
			if ( empty( $flag ) || ! isset( $styles[ $key ] ) ) {
				continue;
			}
			$info = $styles[ $key ];
			$exists = $xpath->query( './/style:style[@style:name="' . $info['name'] . '"]', $auto );
			if ( $exists instanceof DOMNodeList && $exists->length > 0 ) {
				continue;
			}
			$style = $doc->createElementNS( self::ODF_STYLE_NS, 'style:style' );
			$style->setAttributeNS( self::ODF_STYLE_NS, 'style:name', $info['name'] );
			$style->setAttributeNS( self::ODF_STYLE_NS, 'style:family', $info['family'] );
			$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:text-properties' );
			foreach ( $info['props'] as $prop ) {
				$props->setAttributeNS( $prop['ns'], $prop['name'], $prop['value'] );
			}
			$style->appendChild( $props );
			$auto->appendChild( $style );
		}
	}

	/**
	 * Build WordprocessingML runs that mimic the provided HTML fragment.
	 *
	 * @param DOMDocument              $doc      Base DOMDocument for namespace context.
	 * @param string                   $html     HTML fragment.
	 * @param DOMElement|null          $base_rpr Base run properties to clone.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 * @return array<int, DOMElement>
	 */
	private static function build_docx_runs_from_html( DOMDocument $doc, $html, $base_rpr = null, &$relationships = null ) {
			$html = trim( (string) $html );
		if ( '' === $html ) {
				return array();
		}
			$tmp = new DOMDocument();
			libxml_use_internal_errors( true );
			$wrapped = '<div>' . $html . '</div>';
			$loaded  = $tmp->loadHTML( '<?xml encoding="utf-8"?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
		if ( ! $loaded ) {
			return array();
		}
			$body = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
				return array();
		}
			$runs = array();
			self::append_html_nodes_to_runs( $doc, $runs, $body->childNodes, $base_rpr, array(), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			self::trim_trailing_break_runs( $runs );
			return $runs;
	}

	/**
	 * Recursively append HTML nodes as WordprocessingML runs.
	 *
	 * @param DOMDocument              $doc      Target DOMDocument.
	 * @param array<int, DOMElement>   $runs Accumulator of run nodes.
	 * @param DOMNodeList              $nodes    Nodes to append.
	 * @param DOMElement|null          $base_rpr Base run properties to reuse.
	 * @param array<string,bool>       $formatting Active formatting flags.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 */
	private static function append_html_nodes_to_runs( DOMDocument $doc, array &$runs, $nodes, $base_rpr, array $formatting, &$relationships = null ) {
		if ( ! $nodes instanceof DOMNodeList ) {
				return;
		}
		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$text = str_replace( array( "\r\n", "\r" ), "\n", $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$parts = explode( "\n", $text );
				foreach ( $parts as $index => $part ) {
					$part = (string) $part;
					if ( '' !== $part ) {
											$run = self::create_text_run( $doc, $part, $base_rpr, $formatting );
						if ( $run ) {
								$runs[] = $run;
						}
					}
					if ( $index < count( $parts ) - 1 ) {
							$runs[] = self::create_break_run( $doc, $base_rpr );
					}
				}
				continue;
			}
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			switch ( $tag ) {
				case 'strong':
				case 'b':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'bold', true ), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'em':
				case 'i':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'italic', true ), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'u':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'underline', true ), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'br':
						$runs[] = self::create_break_run( $doc, $base_rpr );
					break;
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'bold', true ), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$runs[] = self::create_break_run( $doc, $base_rpr, 2 );
					break;
				case 'p':
				case 'div':
				case 'section':
				case 'article':
				case 'blockquote':
				case 'address':
				case 'span':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::extract_span_formatting( $formatting, $node ), $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( 'span' !== $tag ) {
									$runs[] = self::create_break_run( $doc, $base_rpr );
					}
					break;
				case 'ul':
				case 'ol':
						self::append_list_runs( $doc, $runs, $node, $base_rpr, $formatting, 'ol' === $tag, $relationships );
					break;
				case 'li':
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting, $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'a':
						$href = trim( $node->getAttribute( 'href' ) );
					if ( '' === $href ) {
									self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting, $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
									break;
					}
						$link_runs = array();
						$link_formatting = self::with_format_flag( $formatting, 'hyperlink', true );
						$link_formatting = self::with_format_flag( $link_formatting, 'underline', true );
						self::append_html_nodes_to_runs( $doc, $link_runs, $node->childNodes, $base_rpr, $link_formatting, $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( empty( $link_runs ) ) {
										$run = self::create_text_run( $doc, $href, $base_rpr, $link_formatting );
						if ( $run ) {
							$link_runs[] = $run;
						}
					}
											$hyperlink = self::create_hyperlink_container( $doc, $link_runs, $relationships, $href );
					if ( $hyperlink ) {
						$runs[] = $hyperlink;
					} else {
						foreach ( $link_runs as $link_run ) {
												$runs[] = $link_run;
						}
					}
					break;
				default:
						self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting, $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
	}

	/**
	 * Append list items as runs (basic bullet/number rendering).
	 *
	 * @param DOMDocument              $doc       DOM document.
	 * @param array<int, DOMElement>   $runs Accumulator.
	 * @param DOMElement               $list      List element.
	 * @param DOMElement|null          $base_rpr  Base run properties.
	 * @param array<string,bool>       $formatting Formatting flags.
	 * @param bool                     $ordered   Whether list is ordered.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 */
	private static function append_list_runs( DOMDocument $doc, array &$runs, DOMElement $list, $base_rpr, array $formatting, $ordered, &$relationships = null ) {
			$index = 1;
		foreach ( $list->childNodes as $item ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! $item instanceof DOMElement || 'li' !== strtolower( $item->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
				$prefix = $ordered ? $index . '. ' : '• ';
				$prefix_run = self::create_text_run( $doc, $prefix, $base_rpr, $formatting );
			if ( $prefix_run ) {
					$runs[] = $prefix_run;
			}
				self::append_html_nodes_to_runs( $doc, $runs, $item->childNodes, $base_rpr, $formatting, $relationships ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$runs[] = self::create_break_run( $doc, $base_rpr );
				$index++;
		}
	}

	/**
	 * Merge formatting flags with additional span-based styles.
	 *
	 * @param array<string,bool> $formatting Current formatting flags.
	 * @param DOMElement         $node       Current span element.
	 * @return array<string,bool>
	 */
	private static function extract_span_formatting( array $formatting, DOMElement $node ) {
		$style = $node->getAttribute( 'style' );
		if ( $style ) {
			$styles = array_map( 'trim', explode( ';', strtolower( $style ) ) );
			foreach ( $styles as $rule ) {
				if ( '' === $rule ) {
					continue;
				}
				list( $prop, $val ) = array_map( 'trim', explode( ':', $rule ) + array( '', '' ) );
				switch ( $prop ) {
					case 'font-weight':
						if ( 'bold' === $val || '700' === $val ) {
									$formatting['bold'] = true;
						}
						break;
					case 'font-style':
						if ( 'italic' === $val ) {
							$formatting['italic'] = true;
						}
						break;
					case 'text-decoration':
						if ( false !== strpos( $val, 'underline' ) ) {
							$formatting['underline'] = true;
						}
						break;
				}
			}
		}
		return $formatting;
	}

	/**
	 * Toggle a formatting flag in a new formatting array.
	 *
	 * @param array<string,bool> $formatting Current formatting.
	 * @param string             $flag       Flag name.
	 * @param bool               $value      Flag value.
	 * @return array<string,bool>
	 */
	private static function with_format_flag( array $formatting, $flag, $value ) {
		$formatting[ $flag ] = $value;
		return $formatting;
	}

	/**
	 * Create a run containing text with the given formatting.
	 *
	 * @param DOMDocument        $doc        Target document.
	 * @param string             $text       Text content.
	 * @param DOMElement|null    $base_rpr   Base run properties to clone.
	 * @param array<string,bool> $formatting Formatting flags.
	 * @return DOMElement|null
	 */
	private static function create_text_run( DOMDocument $doc, $text, $base_rpr, array $formatting ) {
		if ( '' === $text ) {
				return null;
		}
			$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
				$run->appendChild( $base_rpr->cloneNode( true ) );
		}
			$rpr = self::get_or_create_run_properties( $doc, $run );
		if ( ! empty( $formatting['bold'] ) ) {
				$rpr->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:b' ) );
		}
		if ( ! empty( $formatting['italic'] ) ) {
				$rpr->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:i' ) );
		}
		if ( ! empty( $formatting['underline'] ) ) {
				$u = $doc->createElementNS( self::WORD_NAMESPACE, 'w:u' );
				$u->setAttribute( 'w:val', 'single' );
				$rpr->appendChild( $u );
		}
		if ( ! empty( $formatting['hyperlink'] ) ) {
				$style = $doc->createElementNS( self::WORD_NAMESPACE, 'w:rStyle' );
				$style->setAttribute( 'w:val', 'Hyperlink' );
				$rpr->appendChild( $style );
				$color = $doc->createElementNS( self::WORD_NAMESPACE, 'w:color' );
				$color->setAttribute( 'w:val', '0000FF' );
				$rpr->appendChild( $color );
		}
		if ( 0 === $rpr->childNodes->length ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$run->removeChild( $rpr );
		}
			$text_node = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		if ( preg_match( '/^\s|\s$/u', $text ) ) {
			$text_node->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
		}
			$text_node->appendChild( $doc->createTextNode( $text ) );
			$run->appendChild( $text_node );
			return $run;
	}

	/**
	 * Create a run representing a line break.
	 *
	 * @param DOMDocument     $doc      Target document.
	 * @param DOMElement|null $base_rpr Base run properties to clone.
	 * @param int             $count    Number of line breaks to append.
	 * @return DOMElement
	 */
	private static function create_break_run( DOMDocument $doc, $base_rpr, $count = 1 ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}
		$count = max( 1, (int) $count );
		for ( $i = 0; $i < $count; $i++ ) {
			$run->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:br' ) );
		}
		return $run;
	}

	/**
	 * Ensure a run has a run properties node to append formatting.
	 *
	 * @param DOMDocument $doc Document reference.
	 * @param DOMElement  $run Run element.
	 * @return DOMElement
	 */
	private static function get_or_create_run_properties( DOMDocument $doc, DOMElement $run ) {
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'rPr' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return $child;
			}
		}
		$rpr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:rPr' );
		$run->insertBefore( $rpr, $run->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $rpr;
	}

	/**
	 * Clone run properties from an existing run if available.
	 *
	 * @param DOMElement $run Run element to inspect.
	 * @return DOMElement|null
	 */
	private static function clone_run_properties( DOMElement $run ) {
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'rPr' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return $child->cloneNode( true );
			}
		}
		return null;
	}

	/**
	 * Remove trailing break runs from the generated run list.
	 *
	 * @param array<int, DOMElement> $runs Run collection.
	 */
	private static function trim_trailing_break_runs( array &$runs ) {
		while ( ! empty( $runs ) ) {
			$last = end( $runs );
			if ( self::run_is_break( $last ) ) {
				array_pop( $runs );
				continue;
			}
			break;
		}
	}

	/**
	 * Determine whether a run is a break run.
	 *
	 * @param DOMElement|null $run Run element.
	 * @return bool
	 */
	private static function run_is_break( $run ) {
		if ( ! $run instanceof DOMElement ) {
				return false;
		}
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'br' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}
		}
			return false;
	}

		/**
		 * Load the relationships XML for a given WordprocessingML part.
		 *
		 * @param ZipArchive $zip    Open archive instance.
		 * @param string     $target Target XML part path.
		 * @return array<string,mixed>|null
		 */
	private static function load_relationships_for_part( ZipArchive $zip, $target ) {
			$rel_path = self::get_relationship_part_path( $target );
		if ( '' === $rel_path ) {
				return null;
		}
			$rels_xml = $zip->getFromName( $rel_path );
			$doc      = new DOMDocument();
			$doc->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$doc->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$map      = array();
			$next_id  = 0;
			libxml_use_internal_errors( true );
		if ( false === $rels_xml || '' === trim( (string) $rels_xml ) || ! $doc->loadXML( $rels_xml ) ) {
				$doc->loadXML( '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships" />' );
		}
			libxml_clear_errors();
			$root = $doc->documentElement;
		if ( $root instanceof DOMElement ) {
				$relationships = $root->getElementsByTagNameNS( $root->namespaceURI, 'Relationship' );
			foreach ( $relationships as $relationship ) {
				if ( ! $relationship instanceof DOMElement ) {
					continue;
				}
					$id = $relationship->getAttribute( 'Id' );
				if ( preg_match( '/^rId(\d+)$/', $id, $matches ) ) {
						$next_id = max( $next_id, (int) $matches[1] );
				}
				if ( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' === $relationship->getAttribute( 'Type' ) ) {
						$target = $relationship->getAttribute( 'Target' );
					if ( '' !== $target ) {
							$map[ $target ] = $id;
					}
				}
			}
		}
			return array(
				'path'       => $rel_path,
				'doc'        => $doc,
				'next_index' => (int) $next_id,
				'map'        => $map,
				'modified'   => false,
			);
	}

		/**
		 * Determine the relationships part path for a given XML part.
		 *
		 * @param string $target Target XML part path.
		 * @return string
		 */
	private static function get_relationship_part_path( $target ) {
		if ( '' === $target ) {
				return '';
		}
			$dir  = dirname( $target );
			$file = basename( $target );
		if ( '.' === $dir ) {
				$dir = '';
		}
			$rel_dir = '' !== $dir ? $dir . '/_rels' : '_rels';
			return $rel_dir . '/' . $file . '.rels';
	}

		/**
		 * Create or reuse a hyperlink relationship and return its r:id value.
		 *
		 * @param array<string,mixed>|null $relationships Relationship context.
		 * @param string                   $target        Hyperlink URL.
		 * @return string
		 */
	private static function register_hyperlink_relationship( &$relationships, $target ) {
		if ( empty( $target ) || ! is_array( $relationships ) ) {
				return '';
		}
		if ( isset( $relationships['map'][ $target ] ) ) {
				return $relationships['map'][ $target ];
		}
		if ( empty( $relationships['doc'] ) || ! $relationships['doc'] instanceof DOMDocument ) {
				return '';
		}
			$doc  = $relationships['doc'];
			$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
				return '';
		}
			$relationships['next_index'] = (int) $relationships['next_index'] + 1;
			$r_id                        = 'rId' . $relationships['next_index'];
			$relationship                = $doc->createElementNS( $root->namespaceURI, 'Relationship' );
			$relationship->setAttribute( 'Id', $r_id );
			$relationship->setAttribute( 'Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' );
			$relationship->setAttribute( 'Target', $target );
			$relationship->setAttribute( 'TargetMode', 'External' );
			$root->appendChild( $relationship );
			$relationships['map'][ $target ] = $r_id;
			$relationships['modified']       = true;
			return $r_id;
	}

		/**
		 * Wrap runs inside a Word hyperlink container when possible.
		 *
		 * @param DOMDocument              $doc           Target DOM document.
		 * @param array<int, DOMElement>   $link_runs     Runs representing the link text.
		 * @param array<string,mixed>|null $relationships Relationship context.
		 * @param string                   $href          Hyperlink URL.
		 * @return DOMElement|null
		 */
	private static function create_hyperlink_container( DOMDocument $doc, array $link_runs, &$relationships, $href ) {
		if ( empty( $link_runs ) ) {
				return null;
		}
			$relationship_id = self::register_hyperlink_relationship( $relationships, $href );
		if ( '' === $relationship_id ) {
				return null;
		}
			$hyperlink = $doc->createElementNS( self::WORD_NAMESPACE, 'w:hyperlink' );
			$hyperlink->setAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $relationship_id );
			$hyperlink->setAttribute( 'w:history', '1' );
		foreach ( $link_runs as $run ) {
			if ( $run instanceof DOMElement ) {
					$hyperlink->appendChild( $run );
			}
		}
			return $hyperlink;
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
