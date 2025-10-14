<?php
/**
 * OpenTBS integration helpers for Resolate.
 *
 * @package Resolate
 */

/**
 * Lightweight OpenTBS wrapper for Resolate.
 */
class Resolate_OpenTBS {

	/**
	 * WordprocessingML namespace used in DOCX documents.
	 */
	private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

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
		return self::render_template_to_file( $template_path, $fields, $dest_path );
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

			foreach ( $fields as $k => $v ) {
				if ( ! is_string( $k ) || '' === $k ) {
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
			$updated = self::convert_docx_part_rich_text( $xml, $lookup );
			if ( $updated !== $xml ) {
				$zip->addFromString( $target, $updated );
				$changed = true;
			}
		}
		$zip->close();
		return $changed;
	}

	/**
	 * Replace HTML fragments in a DOCX XML part with formatted runs.
	 *
	 * @param string               $xml    Original XML part contents.
	 * @param array<string,string> $lookup Rich text lookup table.
	 * @return string
	 */
	public static function convert_docx_part_rich_text( $xml, $lookup ) {
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
				$runs     = self::build_docx_runs_from_html( $dom, $value, $base_rpr );
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
	 * Build WordprocessingML runs that mimic the provided HTML fragment.
	 *
	 * @param DOMDocument     $doc      Base DOMDocument for namespace context.
	 * @param string          $html     HTML fragment.
	 * @param DOMElement|null $base_rpr Base run properties to clone.
	 * @return array<int, DOMElement>
	 */
	private static function build_docx_runs_from_html( DOMDocument $doc, $html, $base_rpr = null ) {
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
		self::append_html_nodes_to_runs( $doc, $runs, $body->childNodes, $base_rpr, array() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		self::trim_trailing_break_runs( $runs );
		return $runs;
	}

	/**
	 * Recursively append HTML nodes as WordprocessingML runs.
	 *
	 * @param DOMDocument            $doc      Target DOMDocument.
	 * @param array<int, DOMElement> $runs Accumulator of run nodes.
	 * @param DOMNodeList            $nodes    Nodes to append.
	 * @param DOMElement|null        $base_rpr Base run properties to reuse.
	 * @param array<string,bool>     $formatting Active formatting flags.
	 */
	private static function append_html_nodes_to_runs( DOMDocument $doc, array &$runs, $nodes, $base_rpr, array $formatting ) {
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
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'bold', true ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'em':
				case 'i':
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'italic', true ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'u':
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::with_format_flag( $formatting, 'underline', true ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'br':
					$runs[] = self::create_break_run( $doc, $base_rpr );
					break;
				case 'p':
				case 'div':
				case 'section':
				case 'article':
				case 'blockquote':
				case 'address':
				case 'span':
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, self::extract_span_formatting( $formatting, $node ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( 'span' !== $tag ) {
						$runs[] = self::create_break_run( $doc, $base_rpr );
					}
					break;
				case 'ul':
				case 'ol':
					self::append_list_runs( $doc, $runs, $node, $base_rpr, $formatting, 'ol' === $tag );
					break;
				case 'li':
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'a':
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				default:
					self::append_html_nodes_to_runs( $doc, $runs, $node->childNodes, $base_rpr, $formatting ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
	}

	/**
	 * Append list items as runs (basic bullet/number rendering).
	 *
	 * @param DOMDocument            $doc       DOM document.
	 * @param array<int, DOMElement> $runs Accumulator.
	 * @param DOMElement             $list      List element.
	 * @param DOMElement|null        $base_rpr  Base run properties.
	 * @param array<string,bool>     $formatting Formatting flags.
	 * @param bool                   $ordered   Whether list is ordered.
	 */
	private static function append_list_runs( DOMDocument $doc, array &$runs, DOMElement $list, $base_rpr, array $formatting, $ordered ) {
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
			self::append_html_nodes_to_runs( $doc, $runs, $item->childNodes, $base_rpr, $formatting ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
	 * @return DOMElement
	 */
	private static function create_break_run( DOMDocument $doc, $base_rpr ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}
		$run->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:br' ) );
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
}
