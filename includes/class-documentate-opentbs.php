<?php
/**
 * OpenTBS integration helpers for Documentate.
 *
 * @package Documentate
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

use Documentate\OpenTBS\OpenTBS_HTML_Parser;

/**
 * Lightweight OpenTBS wrapper for Documentate.
 *
 * Uses OpenTBS_HTML_Parser for shared HTML parsing utilities.
 */
class Documentate_OpenTBS {
	/**
	 * Locale to be used for date formatting in OpenTBS.
	 *
	 * @var string
	 */
	public static $tbs_locale = 'es_ES';

	/**
	 * Number of times the ODT archive was opened during the last content pass.
	 *
	 * Exposed so tests can assert the single-archive-pass optimisation. @internal
	 *
	 * @var int
	 */
	private static $odt_archive_open_count = 0;

	/**
	 * WordprocessingML namespace used in DOCX documents.
	 */
	private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * ODF text namespace.
	 */
	private const ODF_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * ODF table namespace.
	 */
	private const ODF_TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

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
	 * Dublin Core namespace for document metadata.
	 */
	private const DC_NS = 'http://purl.org/dc/elements/1.1/';

	/**
	 * ODF meta namespace for document metadata.
	 */
	private const ODF_META_NS = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';

	/**
	 * OOXML Core Properties namespace for DOCX metadata.
	 */
	private const CP_NS = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

	/**
	 * Table border style for generated documents.
	 * Format: "width style color" (e.g., "0.5pt solid #000000").
	 */
	public const TABLE_BORDER = '0.5pt solid #000000';

	/**
	 * Table cell padding for generated documents.
	 * Uses ODF fo:padding format (e.g., "0.049cm" is similar to Word default).
	 */
	public const TABLE_CELL_PADDING = '0.049cm';

	/**
	 * Create a DOMDocument configured for XML parsing.
	 *
	 * @param string $xml XML content to load.
	 * @return DOMDocument|false DOMDocument on success, false on failure.
	 */
	private static function create_xml_document( $xml ) {
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = false;

		libxml_use_internal_errors( true );
		// LIBXML_NONET blocks network access during parsing (no external DTDs/entities
		// are fetched). LIBXML_NOENT is intentionally NOT set, so entities are never expanded.
		$loaded = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();

		return $loaded ? $dom : false;
	}

	/**
	 * Create a DOMDocument from HTML content with UTF-8 encoding.
	 *
	 * @param string $html HTML content to load.
	 * @return DOMDocument|false DOMDocument on success, false on failure.
	 */
	private static function create_html_document( $html ) {
		$tmp = new DOMDocument();

		libxml_use_internal_errors( true );
		// Convert to HTML entities to preserve UTF-8 encoding, then wrap for parsing.
		$encoded = @mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		$wrapped = '<html><body><div>' . $encoded . '</div></body></html>';
		// LIBXML_NONET blocks network access during parsing; entities are never expanded.
		$loaded = $tmp->loadHTML( $wrapped, LIBXML_NONET );
		libxml_clear_errors();

		return $loaded ? $tmp : false;
	}

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
	 * @param array  $metadata      Optional document metadata (title, subject, author, keywords).
	 * @return bool|WP_Error
	 */
	public static function render_odt( $template_path, $fields, $dest_path, $rich_values = array(), $metadata = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Apply the content-part transforms (rich text, paragraph splitting and
		// leftover-HTML stripping) in a single archive pass instead of reopening
		// the ODT once per step.
		$content_result = self::post_process_odt_content( $dest_path, $rich_values );
		if ( is_wp_error( $content_result ) ) {
			return $content_result;
		}

		$meta_result = self::apply_odt_metadata( $dest_path, $metadata );
		if ( is_wp_error( $meta_result ) ) {
			return $meta_result;
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
	 * @param array  $metadata      Optional document metadata (title, subject, author, keywords).
	 * @return bool|WP_Error
	 */
	public static function render_docx( $template_path, $fields, $dest_path, $rich_values = array(), $metadata = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rich_result = self::apply_docx_rich_text( $dest_path, $rich_values );
		if ( is_wp_error( $rich_result ) ) {
			return $rich_result;
		}

		// Determine targets for DOCX HTML cleaning.
		$targets = array();
		$zip = new ZipArchive();
		if ( true === $zip->open( $dest_path ) ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( preg_match( '/^word\/(document|header[0-9]*|footer[0-9]*)\.xml$/', $name ) ) {
					$targets[] = $name;
				}
			}
			$zip->close();
			self::strip_unprocessed_html_from_archive( $dest_path, $targets );
		}

		$meta_result = self::apply_docx_metadata( $dest_path, $metadata );
		if ( is_wp_error( $meta_result ) ) {
			return $meta_result;
		}

		return $result;
	}

	/**
	 * Final safety net: open the archive and strip HTML tags from text nodes
	 * to ensure raw HTML code like &lt;p&gt; does not appear in the generated document.
	 *
	 * @param string   $archive_path Path to the ZIP/ODT/DOCX file.
	 * @param string[] $targets      Array of XML files inside the archive to process.
	 * @return true|WP_Error
	 */
	private static function strip_unprocessed_html_from_archive( $archive_path, $targets ) {
		if ( empty( $targets ) || ! class_exists( 'ZipArchive' ) ) {
			return true;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error(
				'documentate_zip_open_failed',
				__(
					'Could not open the archive to sanitize HTML.',
					'documentate',
				)
			);
		}

		foreach ( $targets as $target ) {
			$xml = $zip->getFromName( $target );
			if ( false === $xml ) {
				continue;
			}

			$updated = self::strip_unprocessed_html_from_xml( $xml );
			if ( $updated !== $xml ) {
				$zip->addFromString( $target, $updated );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Strip leftover encoded HTML tags from a single XML part string.
	 *
	 * Removes encoded HTML tags (e.g. &lt;p&gt;) and encoded conditional comments
	 * so raw HTML never appears in the generated document.
	 *
	 * @param string $xml XML part contents.
	 * @return string The XML with any encoded HTML tags removed.
	 */
	private static function strip_unprocessed_html_from_xml( $xml ) {
		$updated = preg_replace(
			'/&lt;\/?(?:p|div|span|a|ul|ol|li|table|thead|tbody|tfoot|tr|th|td|h[1-6]|strong|b|em|i|u|br|blockquote|pre)[^&]*&gt;/i',
			'',
			$xml,
		);
		$updated = preg_replace( '/&lt;!--\[.*?\]--&gt;/i', '', (string) $updated );

		return null === $updated ? $xml : (string) $updated;
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
			return new WP_Error( 'documentate_opentbs_missing', __( 'OpenTBS is not available.', 'documentate' ) );
		}
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'documentate_template_missing', __( 'Template not found.', 'documentate' ) );
		}
		// Set locale for TBS date formatting (month/day names in local language).
		// LC_TIME is process wide, so the finally below puts it back on every
		// exit: the two pre-processing errors return early, and a leaked locale
		// would silently reformat every date the rest of the request prints.
		$old_locale = self::push_locale();

		try {
			$tbs_engine = new clsTinyButStrong();
			$tbs_engine->Plugin( TBS_INSTALL, OPENTBS_PLUGIN );
			$tbs_engine->LoadTemplate( $template_path, OPENTBS_ALREADY_UTF8 );

			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			// Pre-process visibility blocks [onshow;block=begin;bloc=FIELD]...[onshow;block=end].
			$tbs_engine->Source = self::process_visibility_blocks( $tbs_engine->Source, $fields );
			if ( null === $tbs_engine->Source ) {
				return new \WP_Error(
					'documentate_regex_error',
					__(
						'Template pre-processing failed (visibility blocks).',
						'documentate',
					)
				);
			}

			// Collapse fragmented XML spans so TBS can match placeholders split across tags.
			$tbs_engine->Source = self::normalize_template_placeholders( $tbs_engine->Source, $template_path );
			if ( null === $tbs_engine->Source ) {
				return new \WP_Error(
					'documentate_regex_error',
					__(
						'Template pre-processing failed (placeholder normalization).',
						'documentate',
					)
				);
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
			return new WP_Error( 'documentate_opentbs_error', $e->getMessage() );
		} finally {
			self::pop_locale( $old_locale );
		}
	}

	/**
	 * Switch LC_TIME to the locale TBS formats dates in.
	 *
	 * TBS renders `frm='mmmm (locale)'` through strftime(), which reads the
	 * process-wide locale. Every caller must hand the returned value back to
	 * pop_locale() so the rest of the request sees the locale it started with.
	 *
	 * @return string|false Locale in force before the switch, false when it could not be read.
	 */
	public static function push_locale() {
		$old_locale = setlocale( LC_TIME, 0 );
		$tbs_locale = self::$tbs_locale;
		setlocale( LC_TIME, $tbs_locale . '.UTF-8', $tbs_locale . '.utf8', $tbs_locale, substr( $tbs_locale, 0, 2 ), 0 );

		return $old_locale;
	}

	/**
	 * Put back the LC_TIME locale that push_locale() replaced.
	 *
	 * @param string|false $old_locale Value returned by push_locale().
	 * @return void
	 */
	public static function pop_locale( $old_locale ) {
		if ( $old_locale ) {
			setlocale( LC_TIME, $old_locale );
		}
	}

	/**
	 * Pre-process visibility blocks in template content.
	 *
	 * Evaluates [onshow;block=begin;bloc=FIELD]...[onshow;block=end] markers
	 * and removes blocks where the referenced field is empty.
	 *
	 * The markers carry no format of their own, so the HTML layouts the native
	 * PDF renderer merges reuse this pass as it stands.
	 *
	 * @param string              $content Template XML content.
	 * @param array<string,mixed> $fields  Merge fields data.
	 * @return string|null Processed content, null when the regular expression failed.
	 */
	public static function process_visibility_blocks( $content, $fields ) {
		// Pattern to match [onshow;block=begin;bloc=FIELD_NAME]...[onshow;block=end]
		// The bloc= value may be quoted or unquoted.
		$pattern = '/\[onshow;block=begin;bloc=[\'"]?([^\];\'"]+)[\'"]?\](.*?)\[onshow;block=end\]/s';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $fields ) {
				$field_name = trim( $matches[1] );
				$block_content = $matches[2];

				// Check if the referenced field has data.
				$has_data = false;
				if ( isset( $fields[ $field_name ] ) ) {
					$value = $fields[ $field_name ];
					if ( is_array( $value ) ) {
						$has_data = ! empty( $value );
					} else {
						$has_data = '' !== trim( (string) $value );
					}
				}

				// Return content if has data, empty string otherwise.
				return $has_data ? $block_content : '';
			},
			$content,
		);
	}

	/**
	 * Collapse fragmented XML spans in template source so TBS can match placeholders.
	 *
	 * ODT/DOCX editors often split placeholder text across multiple XML tags
	 * (e.g. [lugar;ope=utf8,upper] becomes [</span><span>lugar</span><span>;ope=...]).
	 * This method rejoins those fragments so OpenTBS can find the placeholder pattern.
	 *
	 * @param string $source        Raw XML template content.
	 * @param string $template_path Template file path (used to detect odt vs docx).
	 * @return string Normalized XML content.
	 */
	public static function normalize_template_placeholders( $source, $template_path ) {
		// Match TBS placeholders [name;params] that may be fragmented across
		// formatting spans in ODT/DOCX. Strips XML tags from within brackets.
		// Uses possessive quantifiers on inner character classes to prevent
		// catastrophic backtracking on large content.xml files.
		return preg_replace_callback(
			'/\[(?:[^<\[\]]*+|<[^>]*+>)*\]/',
			function ( $match ) {
				$placeholder = $match[0];
				// Strip all XML tags within the placeholder.
				$clean = preg_replace( '#<[^>]+>#', '', $placeholder );
				// Remove XML formatting whitespace (newlines, tabs) between tags.
				return preg_replace( '/[\r\n\t]+/', '', $clean );
			},
			$source,
		);
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
		// Debug logging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'DOCUMENTATE apply_docx_rich_text: rich_values_count=' . count( $rich_values ) . ', lookup_count=' . count( $lookup ),
			);
			foreach ( $lookup as $key => $val ) {
				error_log( 'DOCUMENTATE lookup[' . strlen( $key ) . ' chars]: ' . substr( $key, 0, 100 ) . '...' );
			}
		}
		if ( empty( $lookup ) ) {
			return true;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'documentate_docx_zip_missing',
				__(
					'ZipArchive is not available for rich text formatting.',
					'documentate',
				)
			);
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $doc_path ) ) {
			return new WP_Error(
				'documentate_docx_zip_open',
				__(
					'Could not open the generated DOCX for formatting.',
					'documentate',
				)
			);
		}
		$targets = array();
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
			$updated = self::convert_docx_part_rich_text( $xml, $lookup, $relationships );
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
		// Normalize line endings to match ODT processing (handles HTML with newlines between tags).
		$rich_lookup = self::normalize_lookup_line_endings( $rich_lookup );

		$dom = self::create_xml_document( $xml );
		if ( ! $dom ) {
			return $xml;
		}
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::WORD_NAMESPACE );

		// Process paragraphs instead of individual w:t nodes to handle HTML split across runs.
		$paragraphs = $xpath->query( '//w:p' );
		$modified = false;

		if ( $paragraphs instanceof DOMNodeList ) {
			// Convert to array to avoid issues with DOM modification during iteration.
			$para_array = array();
			foreach ( $paragraphs as $para ) {
				$para_array[] = $para;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DOCUMENTATE convert_docx_part: checking ' . count( $para_array ) . ' paragraphs' );
			}

			foreach ( $para_array as $paragraph ) {
				if ( ! $paragraph instanceof DOMElement ) {
					continue;
				}

				// Collect all w:t nodes and their text within this paragraph.
				$t_nodes = $xpath->query( './/w:t', $paragraph );
				$para_map = self::build_paragraph_text_map( $t_nodes );

				if ( empty( $para_map['text'] ) ) {
					continue;
				}

				// Decode HTML entities to match raw HTML.
				$coalesced = html_entity_decode( $para_map['text'], ENT_QUOTES | ENT_XML1, 'UTF-8' );

				// Normalize for matching (removes orphaned indentation spaces left by TBS).
				$coalesced = self::normalize_for_html_matching( $coalesced );

				// Debug: log paragraph text if it contains HTML-like content.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && false !== strpos( $coalesced, '<' ) ) {
					$escaped = htmlspecialchars( substr( $coalesced, 0, 300 ), ENT_QUOTES, 'UTF-8' );
					error_log(
						'DOCUMENTATE paragraph text ['
						. strlen( $coalesced )
						. ' chars, '
						. count( $para_map['nodes'] )
						. ' nodes]: '
						. $escaped,
					);
				}

				// Find HTML match in coalesced paragraph text.
				$match = self::find_next_html_match( $coalesced, $rich_lookup, 0 );
				if ( false === $match ) {
					continue;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DOCUMENTATE convert_docx_part: MATCH FOUND at pos ' . $match[0] . ' in paragraph, converting HTML' );
				}

				list($match_pos, $match_key, $match_raw) = $match;
				$match_end = $match_pos + strlen( $match_key );

				// Find the base run properties from the first affected run.
				$base_rpr = null;
				foreach ( $para_map['nodes'] as $node_info ) {
					if ( $node_info['end'] > $match_pos ) {
						$run = $node_info['node']->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $run instanceof DOMElement ) {
							$base_rpr = self::clone_run_properties( $run );
						}
						break;
					}
				}

				// Build prefix text (before the HTML match).
				$prefix = substr( $coalesced, 0, $match_pos );

				// Build suffix text (after the HTML match).
				$suffix = substr( $coalesced, $match_end );

				// Collect runs that need to be removed (those containing the matched HTML).
				$runs_to_remove = array();
				foreach ( $para_map['nodes'] as $node_info ) {
					// Check if this node overlaps with the match range.
					if ( $node_info['end'] > $match_pos && $node_info['start'] < $match_end ) {
						$run = $node_info['node']->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $run instanceof DOMElement && ! in_array( $run, $runs_to_remove, true ) ) {
							$runs_to_remove[] = $run;
						}
					}
				}

				if ( empty( $runs_to_remove ) ) {
					continue;
				}

				// Get insertion reference point (first run to remove).
				$insert_before = $runs_to_remove[0];

				// Build prefix run if needed.
				if ( '' !== $prefix ) {
					$prefix_run = self::build_docx_text_run( $dom, $prefix, $base_rpr );
					$paragraph->insertBefore( $prefix_run, $insert_before );
				}

				// Convert the matched HTML.
				$conversion = self::build_docx_nodes_from_html( $dom, $match_raw, $base_rpr, $relationships );

				if ( ! empty( $conversion['block'] ) && ! empty( $conversion['nodes'] ) ) {
					$container = $paragraph->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $container instanceof DOMNode ) {
						$replacement_nodes = self::build_docx_block_replacement_nodes(
							$dom,
							$paragraph,
							$conversion['nodes'],
							$prefix,
							$suffix,
							$base_rpr,
						);

						foreach ( $replacement_nodes as $node_to_insert ) {
							if ( $node_to_insert instanceof DOMElement ) {
								$container->insertBefore( $node_to_insert, $paragraph );
							}
						}

						$container->removeChild( $paragraph );
						$modified = true;
						continue;
					}
				}

				// Insert converted inline runs.
				$inline_runs = ! empty( $conversion['nodes'] ) ? $conversion['nodes'] : array();

				foreach ( $inline_runs as $new_run ) {
					if ( $new_run instanceof DOMElement ) {
						$paragraph->insertBefore( $new_run, $insert_before );
					}
				}

				// Build suffix run if needed.
				if ( '' !== $suffix ) {
					$suffix_run = self::build_docx_text_run( $dom, $suffix, $base_rpr );
					$paragraph->insertBefore( $suffix_run, $insert_before );
				}

				// Remove the original runs that contained the HTML.
				foreach ( $runs_to_remove as $run ) {
					if ( $run->parentNode === $paragraph ) {
						$paragraph->removeChild( $run );
					}
				}

				$modified = true;
			}
		}
		return $modified ? $dom->saveXML() : $xml;
	}

	/**
	 * Build a DOCX text run element.
	 *
	 * @param DOMDocument     $doc      Document.
	 * @param string          $text     Text content.
	 * @param DOMElement|null $base_rpr Base run properties to clone.
	 * @return DOMElement
	 */
	private static function build_docx_text_run( DOMDocument $doc, $text, $base_rpr ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}
		$t = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		$t->setAttribute( 'xml:space', 'preserve' );
		$t->appendChild( $doc->createTextNode( $text ) );
		$run->appendChild( $t );
		return $run;
	}

	/**
	 * Build a map of text content from w:t nodes within a paragraph.
	 *
	 * This coalesces text from multiple w:t nodes into a single string while
	 * tracking the position of each node within that string.
	 *
	 * @param DOMNodeList|false $t_nodes List of w:t nodes.
	 * @return array{text:string,nodes:array<int,array{node:DOMElement,start:int,end:int}>}
	 */
	private static function build_paragraph_text_map( $t_nodes ) {
		$result = array(
			'text' => '',
			'nodes' => array(),
		);

		if ( ! $t_nodes instanceof DOMNodeList ) {
			return $result;
		}

		$position = 0;
		foreach ( $t_nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$text = $node->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$len = strlen( $text );
			$start = $position;
			$end = $position + $len;

			$result['text'] .= $text;
			$result['nodes'][] = array(
				'node' => $node,
				'start' => $start,
				'end' => $end,
			);

			$position = $end;
		}

		return $result;
	}

	/**
	 * Prepare rich text values as a lookup table keyed by raw HTML.
	 *
	 * Delegates to OpenTBS_HTML_Parser for implementation.
	 *
	 * @param array<mixed> $values Potential rich text values.
	 * @return array<string,string>
	 */
	private static function prepare_rich_lookup( $values ) {
		return OpenTBS_HTML_Parser::prepare_rich_lookup( $values );
	}

	/**
	 * Apply all ODT content-part transforms in a single archive pass.
	 *
	 * Opens the generated ODT once and applies, in order, rich-text formatting,
	 * paragraph splitting and leftover-HTML stripping to content.xml and
	 * styles.xml, then writes the changed parts back. This replaces the previous
	 * three separate open/modify/save cycles (rich text, paragraph splitting and
	 * HTML stripping) with one, preserving the exact same transform order.
	 *
	 * @param string       $odt_path    Generated ODT path.
	 * @param array<mixed> $rich_values Rich text values detected during merge.
	 * @return true|WP_Error
	 */
	public static function post_process_odt_content( $odt_path, $rich_values ) {
		self::$odt_archive_open_count = 0;

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'documentate_odt_zip_missing',
				__(
					'ZipArchive is not available for ODT post-processing.',
					'documentate',
				)
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $odt_path ) ) {
			return new WP_Error(
				'documentate_odt_open_failed',
				__(
					'Could not open the ODT file for post-processing.',
					'documentate',
				)
			);
		}
		++self::$odt_archive_open_count;

		$lookup = self::prepare_rich_lookup( $rich_values );

		foreach ( array( 'content.xml', 'styles.xml' ) as $part ) {
			$xml = $zip->getFromName( $part );
			if ( false === $xml ) {
				continue;
			}
			$original = $xml;

			// 1) Convert rich-text HTML fragments to native ODF markup.
			if ( ! empty( $lookup ) ) {
				$converted = self::convert_odt_part_rich_text( $xml, $lookup );
				if ( is_wp_error( $converted ) ) {
					$zip->close();
					return $converted;
				}
				$xml = $converted;
			}

			// 2) Split double-line-break sequences into real paragraphs (content.xml only).
			if ( 'content.xml' === $part ) {
				$xml = self::split_odt_content_paragraphs( $xml );
			}

			// 3) Strip any leftover encoded HTML tags.
			$xml = self::strip_unprocessed_html_from_xml( $xml );

			if ( $xml !== $original ) {
				$zip->addFromString( $part, $xml );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Number of ODT archive opens performed during the last content pass.
	 *
	 * Test/observability helper for the single-archive-pass optimisation.
	 *
	 * @return int
	 */
	public static function get_odt_archive_open_count() {
		return self::$odt_archive_open_count;
	}

	/**
	 * Apply document metadata to an ODT file's meta.xml.
	 *
	 * Updates the ODT's internal meta.xml file with document properties
	 * like title, subject, creator, and keywords.
	 *
	 * @param string $odt_path Path to the ODT file.
	 * @param array  $metadata Associative array with keys: title, subject, author, keywords.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function apply_odt_metadata( $odt_path, $metadata ) {
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return true;
		}

		// Check if any metadata value is non-empty.
		$has_values = false;
		foreach ( $metadata as $value ) {
			if ( ! empty( $value ) ) {
				$has_values = true;
				break;
			}
		}
		if ( ! $has_values ) {
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'documentate_odt_zip_missing', __( 'ZipArchive is not available for metadata.', 'documentate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $odt_path ) ) {
			return new WP_Error( 'documentate_odt_open_failed', __( 'Could not open the ODT file for metadata.', 'documentate' ) );
		}

		$xml = $zip->getFromName( 'meta.xml' );
		if ( false === $xml ) {
			$zip->close();
			return new WP_Error( 'documentate_meta_missing', __( 'meta.xml not found in ODT.', 'documentate' ) );
		}

		$dom = self::create_xml_document( $xml );
		if ( ! $dom ) {
			$zip->close();
			return new WP_Error( 'documentate_meta_parse', __( 'Could not parse meta.xml.', 'documentate' ) );
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'dc', self::DC_NS );
		$xpath->registerNamespace( 'meta', self::ODF_META_NS );

		// Find office:meta element.
		$office_meta_list = $xpath->query( '//office:meta' );
		if ( 0 === $office_meta_list->length ) {
			$zip->close();
			return new WP_Error( 'documentate_meta_element', __( 'office:meta element not found.', 'documentate' ) );
		}
		$office_meta = $office_meta_list->item( 0 );

		// Helper to set or update an element.
		$set_element = function ( $ns_uri, $local_name, $value ) use ( $dom, $xpath, $office_meta ) {
			if ( empty( $value ) ) {
				return;
			}
			$prefix = 'dc' === substr( $local_name, 0, 2 ) || in_array( $local_name, array( 'title', 'subject', 'creator' ), true )
				? 'dc'
				: 'meta';
			$query = ".//{$prefix}:{$local_name}";
			$nodes = $xpath->query( $query, $office_meta );

			if ( $nodes->length > 0 ) {
				// Update existing element.
				$nodes->item( 0 )->textContent = $value;
			} else {
				// Create new element.
				$new_el = $dom->createElementNS( $ns_uri, "{$prefix}:{$local_name}" );
				$new_el->appendChild( $dom->createTextNode( $value ) );
				$office_meta->appendChild( $new_el );
			}
		};

		// Set title.
		if ( ! empty( $metadata['title'] ) ) {
			$set_element( self::DC_NS, 'title', $metadata['title'] );
		}

		// Set subject.
		if ( ! empty( $metadata['subject'] ) ) {
			$set_element( self::DC_NS, 'subject', $metadata['subject'] );
		}

		// Set creator (author) - both initial-creator (LibreOffice author) and dc:creator.
		if ( ! empty( $metadata['author'] ) ) {
			$set_element( self::ODF_META_NS, 'initial-creator', $metadata['author'] );
			$set_element( self::DC_NS, 'creator', $metadata['author'] );
		}

		// Set keywords - remove existing and add new ones.
		if ( ! empty( $metadata['keywords'] ) ) {
			// Remove existing keyword elements.
			$existing_keywords = $xpath->query( './/meta:keyword', $office_meta );
			foreach ( $existing_keywords as $kw ) {
				$office_meta->removeChild( $kw );
			}

			// Add new keyword elements.
			$keywords_array = array_map( 'trim', explode( ',', $metadata['keywords'] ) );
			$keywords_array = array_filter( $keywords_array );
			foreach ( $keywords_array as $keyword ) {
				$kw_el = $dom->createElementNS( self::ODF_META_NS, 'meta:keyword' );
				$kw_el->appendChild( $dom->createTextNode( $keyword ) );
				$office_meta->appendChild( $kw_el );
			}
		}

		// Save updated meta.xml.
		$updated_xml = $dom->saveXML();
		$zip->addFromString( 'meta.xml', $updated_xml );
		$zip->close();

		return true;
	}

	/**
	 * Apply document metadata to a DOCX file's docProps/core.xml.
	 *
	 * Updates the DOCX's internal core.xml file with document properties
	 * like title, subject, creator, and keywords.
	 *
	 * @param string $docx_path Path to the DOCX file.
	 * @param array  $metadata  Associative array with keys: title, subject, author, keywords.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function apply_docx_metadata( $docx_path, $metadata ) {
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return true;
		}

		// Check if any metadata value is non-empty.
		$has_values = false;
		foreach ( $metadata as $value ) {
			if ( ! empty( $value ) ) {
				$has_values = true;
				break;
			}
		}
		if ( ! $has_values ) {
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'documentate_docx_zip_missing', __( 'ZipArchive is not available for metadata.', 'documentate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx_path ) ) {
			return new WP_Error( 'documentate_docx_open_failed', __( 'Could not open the DOCX file for metadata.', 'documentate' ) );
		}

		$xml = $zip->getFromName( 'docProps/core.xml' );
		if ( false === $xml ) {
			$zip->close();
			return new WP_Error( 'documentate_core_missing', __( 'docProps/core.xml not found in DOCX.', 'documentate' ) );
		}

		$dom = self::create_xml_document( $xml );
		if ( ! $dom ) {
			$zip->close();
			return new WP_Error( 'documentate_core_parse', __( 'Could not parse core.xml.', 'documentate' ) );
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'cp', self::CP_NS );
		$xpath->registerNamespace( 'dc', self::DC_NS );

		// Find cp:coreProperties element.
		$core_props_list = $xpath->query( '//cp:coreProperties' );
		if ( 0 === $core_props_list->length ) {
			$zip->close();
			return new WP_Error( 'documentate_core_element', __( 'cp:coreProperties element not found.', 'documentate' ) );
		}
		$core_props = $core_props_list->item( 0 );

		// Helper to set or update an element.
		$set_element = static function ( $ns_uri, $prefix, $local_name, $value ) use ( $dom, $xpath, $core_props ) {
			if ( empty( $value ) ) {
				return;
			}
			$query = ".//{$prefix}:{$local_name}";
			$nodes = $xpath->query( $query, $core_props );

			if ( $nodes->length > 0 ) {
				// Update existing element.
				$nodes->item( 0 )->textContent = $value;
			} else {
				// Create new element.
				$new_el = $dom->createElementNS( $ns_uri, "{$prefix}:{$local_name}" );
				$new_el->appendChild( $dom->createTextNode( $value ) );
				$core_props->appendChild( $new_el );
			}
		};

		// Set title.
		if ( ! empty( $metadata['title'] ) ) {
			$set_element( self::DC_NS, 'dc', 'title', $metadata['title'] );
		}

		// Set subject.
		if ( ! empty( $metadata['subject'] ) ) {
			$set_element( self::DC_NS, 'dc', 'subject', $metadata['subject'] );
		}

		// Set creator (author).
		if ( ! empty( $metadata['author'] ) ) {
			$set_element( self::DC_NS, 'dc', 'creator', $metadata['author'] );
		}

		// Set keywords (DOCX uses cp:keywords as comma-separated string).
		if ( ! empty( $metadata['keywords'] ) ) {
			$set_element( self::CP_NS, 'cp', 'keywords', $metadata['keywords'] );
		}

		// Save updated core.xml.
		$updated_xml = $dom->saveXML();
		$zip->addFromString( 'docProps/core.xml', $updated_xml );
		$zip->close();

		return true;
	}

	/**
	 * Convert HTML placeholders inside an ODT XML part to styled markup.
	 *
	 * CHANGE: Expose rich text conversion for direct usage in tests and callers.
	 *
	 * @param string               $xml    Original XML contents.
	 * @param array<string,string> $lookup Rich text lookup table.
	 * @return string|WP_Error
	 */
	public static function convert_odt_part_rich_text( $xml, $lookup ) {
		$lookup = self::prepare_rich_lookup( $lookup ); // CHANGE: Normalize raw lookup values defensively.
		if ( empty( $lookup ) ) {
			return $xml;
		}

		$lookup = self::normalize_lookup_line_endings( $lookup );

		$doc = self::create_xml_document( $xml );
		if ( ! $doc ) {
			return $xml;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );

		$modified = false;
		$style_require = array();

		// Process paragraph by paragraph to handle HTML split by text:line-break elements.
		$paragraphs = $xpath->query( '//text:p' );
		if ( $paragraphs instanceof DOMNodeList ) {
			// Convert to array to avoid issues with DOM modification during iteration.
			$para_array = array();
			foreach ( $paragraphs as $para ) {
				$para_array[] = $para;
			}

			foreach ( $para_array as $paragraph ) {
				if ( ! $paragraph instanceof DOMElement ) {
					continue;
				}

				// Collect all text nodes in the paragraph.
				$text_nodes = array();
				$coalesced = '';
				foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $child instanceof DOMText ) {
						$text_nodes[] = $child;
						$coalesced .= $child->wholeText; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
				}

				if ( empty( $coalesced ) ) {
					continue;
				}

				// Decode HTML entities and normalize newlines.
				$coalesced = self::normalize_text_newlines( $coalesced );
				$coalesced = html_entity_decode( $coalesced, ENT_QUOTES | ENT_XML1, 'UTF-8' );

				// Normalize for matching (removes orphaned indentation spaces left by TBS).
				$coalesced = self::normalize_for_html_matching( $coalesced );

				// Check if there's an HTML match in the coalesced text.
				$match = self::find_next_html_match( $coalesced, $lookup, 0 );
				if ( false === $match ) {
					// No match found; try individual text nodes for backward compatibility.
					foreach ( $text_nodes as $node ) {
						$changed = self::replace_odt_text_node_html( $node, $lookup, $style_require );
						if ( $changed ) {
							$modified = true;
						}
					}
					continue;
				}

				// Found a match; process the entire paragraph's text content.
				$changed = self::replace_odt_paragraph_html( $paragraph, $coalesced, $text_nodes, $lookup, $style_require, $doc );
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
	 * Replace HTML fragments in a paragraph by processing coalesced text from all text nodes.
	 *
	 * This handles HTML that was split across multiple DOMText nodes by text:line-break elements.
	 *
	 * @param DOMElement           $paragraph     The paragraph element.
	 * @param string               $coalesced     Coalesced and decoded text content.
	 * @param array<int,DOMText>   $text_nodes    Array of text nodes in the paragraph.
	 * @param array<string,string> $lookup        Rich text lookup table.
	 * @param array<string,bool>   $style_require Styles required so far.
	 * @param DOMDocument          $doc           The document.
	 * @return bool
	 */
	private static function replace_odt_paragraph_html(
		DOMElement $paragraph,
		$coalesced,
		array $text_nodes,
		array $lookup,
		array &$style_require,
		DOMDocument $doc,
	) {
		$position = 0;
		$modified = false;
		$nodes_to_insert = array();

		while ( true ) {
			$match = self::find_next_html_match( $coalesced, $lookup, $position );
			if ( false === $match ) {
				break;
			}

			list($match_pos, $match_key, $match_raw) = $match;

			// Add text before match.
			if ( $match_pos > $position ) {
				$segment = substr( $coalesced, $position, $match_pos - $position );
				if ( '' !== $segment ) {
					$nodes_to_insert[] = $doc->createTextNode( $segment );
				}
			}

			// Convert HTML to ODT nodes.
			$html_nodes = self::build_odt_inline_nodes( $doc, $match_raw, $style_require );
			foreach ( $html_nodes as $node ) {
				$nodes_to_insert[] = $node;
			}

			$position = $match_pos + strlen( $match_key );
			$modified = true;
		}

		if ( ! $modified ) {
			return false;
		}

		// Add remaining text after last match.
		$tail = substr( $coalesced, $position );
		if ( '' !== $tail ) {
			$nodes_to_insert[] = $doc->createTextNode( $tail );
		}

		if ( self::contains_odt_block_nodes( $nodes_to_insert ) ) {
			return self::replace_odt_paragraph_with_blocks( $paragraph, $nodes_to_insert );
		}

		// Remove all existing text nodes and line-break elements.
		$children_to_remove = array();
		foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMText ) {
				$children_to_remove[] = $child;
			} elseif ( $child instanceof DOMElement && 'line-break' === $child->localName ) {
				$children_to_remove[] = $child;
			}
		}
		foreach ( $children_to_remove as $child ) {
			$paragraph->removeChild( $child );
		}

		// Insert new nodes. Block-level elements need to be inserted after the paragraph.
		$parent = $paragraph->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$next_sibling = $paragraph->nextSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		foreach ( $nodes_to_insert as $node ) {
			$is_table =
				$node instanceof DOMElement && self::ODF_TABLE_NS === $node->namespaceURI && 'table' === $node->localName;
			$is_paragraph = $node instanceof DOMElement && self::ODF_TEXT_NS === $node->namespaceURI && 'p' === $node->localName;

			if ( $is_table || $is_paragraph ) {
				// Tables and paragraphs must be siblings, not children.
				if ( $parent instanceof DOMNode ) {
					$parent->insertBefore( $node, $next_sibling );
				}
			} else {
				$paragraph->appendChild( $node );
			}
		}

		return true;
	}

	/**
	 * Determine whether the provided ODT node list contains block-level nodes.
	 *
	 * @param array<int,DOMNode> $nodes Nodes to inspect.
	 * @return bool
	 */
	private static function contains_odt_block_nodes( array $nodes ) {
		foreach ( $nodes as $node ) {
			if ( self::is_odt_block_node( $node ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether an ODT node must live outside the current paragraph.
	 *
	 * @param DOMNode $node Candidate node.
	 * @return bool
	 */
	private static function is_odt_block_node( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}

		$is_table = self::ODF_TABLE_NS === $node->namespaceURI && 'table' === $node->localName;
		$is_paragraph = self::ODF_TEXT_NS === $node->namespaceURI && 'p' === $node->localName;

		return $is_table || $is_paragraph;
	}

	/**
	 * Replace an ODT paragraph with a sequence that may contain block nodes.
	 *
	 * Prefix and suffix inline content are merged into the first and last generated
	 * paragraphs when possible so inline placeholders keep their surrounding text.
	 *
	 * @param DOMElement         $paragraph Source paragraph being replaced.
	 * @param array<int,DOMNode> $nodes     Replacement nodes.
	 * @return bool
	 */
	private static function replace_odt_paragraph_with_blocks( DOMElement $paragraph, array $nodes ) {
		$parent = $paragraph->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $parent instanceof DOMNode ) {
			return false;
		}

		$replacement_nodes = array();
		$inline_buffer = array();

		foreach ( $nodes as $node ) {
			if ( ! self::is_odt_block_node( $node ) ) {
				$inline_buffer[] = $node;
				continue;
			}

			if ( $node instanceof DOMElement && self::ODF_TEXT_NS === $node->namespaceURI && 'p' === $node->localName ) {
				self::inherit_odt_paragraph_style( $paragraph, $node );
				if ( ! empty( $inline_buffer ) ) {
					self::prepend_odt_inline_nodes( $node, $inline_buffer );
					$inline_buffer = array();
				}
				$replacement_nodes[] = $node;
				continue;
			}

			if ( ! empty( $inline_buffer ) ) {
				$replacement_nodes[] = self::create_odt_paragraph_from_inline_nodes( $paragraph, $inline_buffer );
				$inline_buffer = array();
			}

			$replacement_nodes[] = $node;
		}

		if ( ! empty( $inline_buffer ) ) {
			$last_node = ! empty( $replacement_nodes ) ? end( $replacement_nodes ) : null;
			if (
				$last_node instanceof DOMElement
				&& self::ODF_TEXT_NS === $last_node->namespaceURI
				&& 'p' === $last_node->localName
			) {
				self::append_odt_inline_nodes( $last_node, $inline_buffer );
			} else {
				$replacement_nodes[] = self::create_odt_paragraph_from_inline_nodes( $paragraph, $inline_buffer );
			}
		}

		foreach ( $replacement_nodes as $replacement_node ) {
			$parent->insertBefore( $replacement_node, $paragraph );
		}

		$parent->removeChild( $paragraph );
		return true;
	}

	/**
	 * Create an ODT paragraph shell inheriting the source paragraph styling.
	 *
	 * @param DOMElement         $source_paragraph Source paragraph.
	 * @param array<int,DOMNode> $nodes            Inline nodes to append.
	 * @return DOMElement
	 */
	private static function create_odt_paragraph_from_inline_nodes( DOMElement $source_paragraph, array $nodes ) {
		$paragraph = $source_paragraph->cloneNode( false );
		foreach ( $nodes as $node ) {
			$paragraph->appendChild( $node );
		}
		return $paragraph;
	}

	/**
	 * Apply the source paragraph style to a generated ODT paragraph when needed.
	 *
	 * @param DOMElement $source_paragraph Template paragraph.
	 * @param DOMElement $target_paragraph Generated paragraph.
	 */
	private static function inherit_odt_paragraph_style( DOMElement $source_paragraph, DOMElement $target_paragraph ) {
		if ( $target_paragraph->hasAttributeNS( self::ODF_TEXT_NS, 'style-name' ) ) {
			return;
		}

		$style_name = $source_paragraph->getAttributeNS( self::ODF_TEXT_NS, 'style-name' );
		if ( '' !== $style_name ) {
			$target_paragraph->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', $style_name );
		}
	}

	/**
	 * Prepend inline nodes to an ODT paragraph.
	 *
	 * @param DOMElement         $paragraph Paragraph to update.
	 * @param array<int,DOMNode> $nodes     Nodes to prepend.
	 */
	private static function prepend_odt_inline_nodes( DOMElement $paragraph, array $nodes ) {
		$reference = $paragraph->firstChild; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $nodes as $node ) {
			if ( $reference instanceof DOMNode ) {
				$paragraph->insertBefore( $node, $reference );
				continue;
			}

			$paragraph->appendChild( $node );
		}
	}

	/**
	 * Append inline nodes to an ODT paragraph.
	 *
	 * @param DOMElement         $paragraph Paragraph to update.
	 * @param array<int,DOMNode> $nodes     Nodes to append.
	 */
	private static function append_odt_inline_nodes( DOMElement $paragraph, array $nodes ) {
		foreach ( $nodes as $node ) {
			$paragraph->appendChild( $node );
		}
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
		$value = self::normalize_text_newlines( $text_node->wholeText ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// Decode HTML entities so we can match raw HTML fragments like <table> inside text nodes that contain &lt;table&gt;.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		// Normalize for matching (removes orphaned indentation spaces left by TBS).
		$value = self::normalize_for_html_matching( $value );
		$doc = $text_node->ownerDocument;
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

			list($match_pos, $match_key, $match_raw) = $match;
			if ( $match_pos > $position ) {
				$segment = substr( $value, $position, $match_pos - $position );
				if ( '' !== $segment ) {
					$parent->insertBefore( $doc->createTextNode( $segment ), $text_node );
				}
			}

			// Use raw HTML for parsing, not the possibly-encoded key.
			$nodes = self::build_odt_inline_nodes( $doc, $match_raw, $style_require );
			foreach ( $nodes as $node ) {
				// Check if node is a block-level element that must be a sibling of paragraphs.
				$is_table =
					$node instanceof DOMElement && self::ODF_TABLE_NS === $node->namespaceURI && 'table' === $node->localName;
				$is_paragraph =
					$node instanceof DOMElement && self::ODF_TEXT_NS === $node->namespaceURI && 'p' === $node->localName;

				if ( $is_table || $is_paragraph ) {
					// Tables and paragraphs must be siblings of the containing paragraph, not children.
					$target_parent = $parent;
					$reference = $text_node;

					if (
						$parent instanceof DOMElement
						&& self::ODF_TEXT_NS === $parent->namespaceURI
						&& 'p' === $parent->localName
						&& $parent->parentNode
					) {
						$target_parent = $parent->parentNode;
						$reference = $parent->nextSibling;
					}

					if ( $target_parent instanceof DOMNode ) {
						$target_parent->insertBefore( $node, $reference );
					} else {
						$parent->insertBefore( $node, $text_node );
					}
				} else {
					$parent->insertBefore( $node, $text_node );
				}
			}

			// Use key length for position calculation (matches what's in source text).
			$position = $match_pos + strlen( $match_key );
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
	 * @return array{int,string,string}|false Position, matched key for length, raw HTML for parsing.
	 */
	private static function find_next_html_match( $text, $lookup, $position ) {
		return OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, $position );
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

		$tmp = self::create_html_document( $html );
		if ( ! $tmp ) {
			return array( $doc->createTextNode( $html ) );
		}

		$container = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $container ) {
			return array( $doc->createTextNode( $html ) );
		}

		$list_state = array(
			'unordered' => 0,
			'ordered' => array(),
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
	private static function convert_html_node_to_odt(
		DOMDocument $doc,
		$node,
		$formatting,
		array &$style_require,
		array &$list_state,
	) {
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
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$formatting['bold'] = true;
				$spacing = array(
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
				);
				$heading_nodes = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$tail_spacing = array(
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
				);
				return array_merge( $spacing, $heading_nodes, $tail_spacing );
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
				$alignment = self::extract_text_alignment( $node );
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$is_spacing_paragraph = self::is_nbsp_only_paragraph( $node );

				if ( empty( $children ) && ! $is_spacing_paragraph ) {
					return array();
				}

				// Always create a real ODT paragraph for proper spacing control.
				$paragraph = $doc->createElementNS( self::ODF_TEXT_NS, 'text:p' );

				// Apply alignment style if specified.
				if ( null !== $alignment && 'left' !== $alignment ) {
					$style_name = 'DocumentateAlign' . ucfirst( $alignment );
					$paragraph->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', $style_name );
					$style_require[ 'align_' . $alignment ] = true;
				}

				if ( ! empty( $children ) ) {
					self::trim_odt_inline_nodes( $children );
					foreach ( $children as $child_node ) {
						$paragraph->appendChild( $child_node );
					}
				} elseif ( $is_spacing_paragraph ) {
					// For spacing paragraphs, add a non-breaking space.
					$paragraph->appendChild( $doc->createTextNode( "\xC2\xA0" ) );
				}

				return array( $paragraph );
			case 'table':
				return self::convert_table_node_to_odt( $doc, $node, $formatting, $style_require );
			case 'ul':
				$list_state['unordered']++;
				$prev_type = $list_state['current_type'] ?? null;
				$list_state['current_type'] = 'ul';
				$items = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $items ) ) {
						$items[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$items = array_merge(
						$items,
						self::convert_html_node_to_odt(
							$doc,
							$child,
							$formatting,
							$style_require,
							$list_state,
						)
					);
				}
				$list_state['unordered'] = max( 0, $list_state['unordered'] - 1 );
				$list_state['current_type'] = $prev_type;
				return $items;
			case 'ol':
				$list_state['ordered'][] = 1;
				$prev_type = $list_state['current_type'] ?? null;
				$list_state['current_type'] = 'ol';
				$ordered = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $ordered ) ) {
						$ordered[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$ordered = array_merge(
						$ordered,
						self::convert_html_node_to_odt(
							$doc,
							$child,
							$formatting,
							$style_require,
							$list_state,
						)
					);
				}
				array_pop( $list_state['ordered'] );
				$list_state['current_type'] = $prev_type;
				return $ordered;
			case 'li':
				$line = array();
				$current_type = $list_state['current_type'] ?? '';
				if ( 'ol' === $current_type && ! empty( $list_state['ordered'] ) ) {
					$index = count( $list_state['ordered'] ) - 1;
					$number = $list_state['ordered'][ $index ];
					$prefix = $number . '. ';
					$line = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $prefix ) ), $formatting, $style_require );
					$list_state['ordered'][ $index ]++;
				} elseif ( 'ul' === $current_type || $list_state['unordered'] > 0 ) {
					$indent = str_repeat( '  ', max( 0, $list_state['unordered'] - 1 ) );
					$bullet = $indent . '• ';
					$line = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $bullet ) ), $formatting, $style_require );
				}
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$line = array_merge( $line, $children );
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
	private static function collect_html_children_as_odt(
		DOMDocument $doc,
		DOMNode $node,
		$formatting,
		array &$style_require,
		array &$list_state,
	) {
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
	 * Convert an HTML table node to ODT table elements.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMNode             $node          Table HTML node.
	 * @param array<string,mixed> $formatting    Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @return array<int,DOMNode>
	 */
	private static function convert_table_node_to_odt( DOMDocument $doc, $node, $formatting, array &$style_require ) {
		$row_nodes = self::extract_table_row_nodes( $node );
		if ( empty( $row_nodes ) ) {
			return array();
		}

		$table_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table' );
		$table_element->setAttributeNS( self::ODF_TABLE_NS, 'table:style-name', 'DocumentateRichTable' );
		$style_require['table'] = true;
		$row_elements = array();
		$max_columns = 0;

		// Track rowspan coverage: column_index => remaining rows needing covered cells.
		$rowspan_map = array();

		foreach ( $row_nodes as $row ) {
			$row_data = self::convert_table_row_to_odt( $doc, $row, $formatting, $style_require, $rowspan_map );
			if ( $row_data['element'] ) {
				$row_elements[] = $row_data['element'];
				if ( $row_data['columns'] > $max_columns ) {
					$max_columns = $row_data['columns'];
				}
			}
		}

		if ( empty( $row_elements ) ) {
			return array();
		}

		for ( $i = 0; $i < $max_columns; $i++ ) {
			$table_element->appendChild( $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-column' ) );
		}

		foreach ( $row_elements as $row_element ) {
			$table_element->appendChild( $row_element );
		}

		// Tables in ODT are block-level elements that are siblings of paragraphs.
		// No line-breaks needed - the XML structure provides natural separation.
		return array( $table_element );
	}

	/**
	 * Convert a single table row to ODT.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMElement          $row           Row element.
	 * @param array<string,mixed> $formatting    Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @param array<int,int>      $rowspan_map   Column index => remaining rows covered by rowspan.
	 * @return array{element: DOMElement|null, columns: int}
	 */
	private static function convert_table_row_to_odt(
		DOMDocument $doc,
		DOMElement $row,
		$formatting,
		array &$style_require,
		array &$rowspan_map = array(),
	) {
		$row_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-row' );
		$column_index = 0;
		$column_count = 0;

		$cells = array();
		foreach ( $row->childNodes as $cell ) {
			if ( ! $cell instanceof DOMElement ) {
				continue;
			}
			$cell_tag = strtolower( $cell->nodeName );
			if ( 'td' === $cell_tag || 'th' === $cell_tag ) {
				$cells[] = $cell;
			}
		}

		$cell_iter  = 0;
		$cell_count = count( $cells );
		while ( $cell_iter < $cell_count || isset( $rowspan_map[ $column_index ] ) ) {
			// Insert covered cells for rowspan from previous rows.
			while ( isset( $rowspan_map[ $column_index ] ) && $rowspan_map[ $column_index ] > 0 ) {
				$covered = $doc->createElementNS( self::ODF_TABLE_NS, 'table:covered-table-cell' );
				$row_element->appendChild( $covered );
				// Consume: decrement and remove if exhausted.
				$rowspan_map[ $column_index ]--;
				if ( 0 === $rowspan_map[ $column_index ] ) {
					unset( $rowspan_map[ $column_index ] );
				}
				$column_index++;
				$column_count++;
			}

			if ( $cell_iter >= count( $cells ) ) {
				break;
			}

			$cell = $cells[ $cell_iter ];
			$cell_element = self::convert_table_cell_to_odt( $doc, $cell, $formatting, $style_require );
			$row_element->appendChild( $cell_element );

			$colspan = max( 1, (int) $cell->getAttribute( 'colspan' ) );
			$rowspan = max( 1, (int) $cell->getAttribute( 'rowspan' ) );

			// Register rowspan for subsequent rows.
			if ( $rowspan > 1 ) {
				for ( $c = 0; $c < $colspan; $c++ ) {
					$rowspan_map[ $column_index + $c ] = $rowspan - 1;
				}
			}

			// Append covered-table-cell for extra columns from colspan.
			for ( $c = 1; $c < $colspan; $c++ ) {
				$covered = $doc->createElementNS( self::ODF_TABLE_NS, 'table:covered-table-cell' );
				$row_element->appendChild( $covered );
			}

			$column_index += $colspan;
			$column_count += $colspan;
			$cell_iter++;
		}

		// Handle any remaining covered columns after all cells.
		while ( isset( $rowspan_map[ $column_index ] ) && $rowspan_map[ $column_index ] > 0 ) {
			$covered = $doc->createElementNS( self::ODF_TABLE_NS, 'table:covered-table-cell' );
			$row_element->appendChild( $covered );
			$rowspan_map[ $column_index ]--;
			if ( 0 === $rowspan_map[ $column_index ] ) {
				unset( $rowspan_map[ $column_index ] );
			}
			$column_index++;
			$column_count++;
		}

		return array(
			'element' => $column_count > 0 ? $row_element : null,
			'columns' => $column_count,
		);
	}

	/**
	 * Convert a single table cell to ODT.
	 *
	 * Handles block elements (p, div, ul, ol, table) as separate paragraphs
	 * to avoid invalid nested text:p elements. Inline content outside block
	 * elements is collected into a default paragraph.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMElement          $cell          Cell element (td or th).
	 * @param array<string,mixed> $formatting    Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @return DOMElement
	 */
	private static function convert_table_cell_to_odt(
		DOMDocument $doc,
		DOMElement $cell,
		$formatting,
		array &$style_require,
	) {
		$cell_formatting = $formatting;
		if ( 'th' === strtolower( $cell->nodeName ) ) {
			$cell_formatting['bold'] = true;
		}

		// Extract alignment from cell or first paragraph child.
		$cell_alignment = self::extract_text_alignment( $cell );
		if ( null === $cell_alignment ) {
			foreach ( $cell->childNodes as $child ) {
				if ( $child instanceof DOMElement && 'p' === strtolower( $child->nodeName ) ) {
					$cell_alignment = self::extract_text_alignment( $child );
					break;
				}
			}
		}

		$cell_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-cell' );
		$cell_element->setAttributeNS( self::ODF_TABLE_NS, 'table:style-name', 'DocumentateRichTableCell' );
		$style_require['table_cell'] = true;

		// Handle colspan attribute.
		$colspan = (int) $cell->getAttribute( 'colspan' );
		if ( $colspan > 1 ) {
			$cell_element->setAttributeNS( self::ODF_TABLE_NS, 'table:number-columns-spanned', (string) $colspan );
		}

		// Handle rowspan attribute.
		$rowspan = (int) $cell->getAttribute( 'rowspan' );
		if ( $rowspan > 1 ) {
			$cell_element->setAttributeNS( self::ODF_TABLE_NS, 'table:number-rows-spanned', (string) $rowspan );
		}

		// Walk cell children, separating block elements from inline content.
		// This avoids creating nested <text:p> which is invalid ODF.
		$cell_list_state = array(
			'unordered' => 0,
			'ordered' => array(),
		);
		$current_inline = array();
		$block_elements = array( 'p', 'div', 'ul', 'ol', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

		foreach ( $cell->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				// Text nodes or other non-element nodes: collect as inline.
				$inline_nodes = self::convert_html_node_to_odt( $doc, $child, $cell_formatting, $style_require, $cell_list_state );
				$current_inline = array_merge( $current_inline, $inline_nodes );
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( ! in_array( $tag, $block_elements, true ) ) {
				// Inline element (strong, em, span, a, br, etc.): collect.
				$inline_nodes = self::convert_html_node_to_odt( $doc, $child, $cell_formatting, $style_require, $cell_list_state );
				// Filter out any text:p elements that inline converters may return
				// (e.g. from nested block content) and append them separately.
				foreach ( $inline_nodes as $inline_node ) {
					if ( $inline_node instanceof DOMElement && 'text:p' === $inline_node->nodeName ) {
						// Flush current inline content first.
						if ( ! empty( $current_inline ) ) {
							$cell_element->appendChild(
								self::create_odt_paragraph_from_inline(
									$doc,
									$current_inline,
									$cell_alignment,
									$style_require,
								)
							);
							$current_inline = array();
						}
						$cell_element->appendChild( $inline_node );
					} else {
						$current_inline[] = $inline_node;
					}
				}
				continue;
			}

			// Block element: flush any accumulated inline content first.
			if ( ! empty( $current_inline ) ) {
				$cell_element->appendChild(
					self::create_odt_paragraph_from_inline(
						$doc,
						$current_inline,
						$cell_alignment,
						$style_require,
					)
				);
				$current_inline = array();
			}

			if ( 'table' === $tag ) {
				$table_nodes = self::convert_table_node_to_odt( $doc, $child, $cell_formatting, $style_require );
				foreach ( $table_nodes as $table_node ) {
					$cell_element->appendChild( $table_node );
				}
			} elseif ( 'ul' === $tag || 'ol' === $tag ) {
				// Lists inside cells: convert and wrap in paragraphs.
				$list_nodes = self::convert_html_node_to_odt( $doc, $child, $cell_formatting, $style_require, $cell_list_state );
				if ( ! empty( $list_nodes ) ) {
					$cell_element->appendChild(
						self::create_odt_paragraph_from_inline(
							$doc,
							$list_nodes,
							$cell_alignment,
							$style_require,
						)
					);
				}
			} else {
				// p, div, h1-h6: extract alignment and create a paragraph with their content.
				$p_alignment = self::extract_text_alignment( $child );
				if ( null === $p_alignment ) {
					$p_alignment = $cell_alignment;
				}
				$p_nodes = self::collect_html_children_as_odt( $doc, $child, $cell_formatting, $style_require, $cell_list_state );
				// Filter: if collect returned text:p elements (from nested blocks), append directly.
				$inline_part = array();
				foreach ( $p_nodes as $p_node ) {
					if ( $p_node instanceof DOMElement && 'text:p' === $p_node->nodeName ) {
						if ( ! empty( $inline_part ) ) {
							$cell_element->appendChild(
								self::create_odt_paragraph_from_inline(
									$doc,
									$inline_part,
									$p_alignment,
									$style_require,
								)
							);
							$inline_part = array();
						}
						$cell_element->appendChild( $p_node );
					} else {
						$inline_part[] = $p_node;
					}
				}
				// Create paragraph from inline content (or empty paragraph for spacing).
				$is_spacing = self::is_nbsp_only_paragraph( $child );
				if ( ! empty( $inline_part ) || $is_spacing ) {
					$paragraph = $doc->createElementNS( self::ODF_TEXT_NS, 'text:p' );
					if ( null !== $p_alignment && 'left' !== $p_alignment ) {
						$style_name = 'DocumentateAlign' . ucfirst( $p_alignment );
						$paragraph->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', $style_name );
						$style_require[ 'align_' . $p_alignment ] = true;
					}
					if ( ! empty( $inline_part ) ) {
						self::trim_odt_inline_nodes( $inline_part );
						foreach ( $inline_part as $node ) {
							$paragraph->appendChild( $node );
						}
					} elseif ( $is_spacing ) {
						$paragraph->appendChild( $doc->createTextNode( "\xC2\xA0" ) );
					}
					$cell_element->appendChild( $paragraph );
				}
			}
		}

		// Flush remaining inline content.
		if ( ! empty( $current_inline ) ) {
			$cell_element->appendChild(
				self::create_odt_paragraph_from_inline(
					$doc,
					$current_inline,
					$cell_alignment,
					$style_require,
				)
			);
		}

		// Ensure cell has at least one paragraph (required by ODF spec).
		if ( 0 === $cell_element->childNodes->length ) {
			$empty_p = $doc->createElementNS( self::ODF_TEXT_NS, 'text:p' );
			$empty_p->appendChild( $doc->createTextNode( '' ) );
			$cell_element->appendChild( $empty_p );
		}

		return $cell_element;
	}

	/**
	 * Create an ODT paragraph from inline nodes.
	 *
	 * @param DOMDocument        $doc           Target document.
	 * @param array<int,DOMNode> $nodes         Inline nodes.
	 * @param string|null        $alignment     Text alignment.
	 * @param array<string,bool> $style_require Styles required so far.
	 * @return DOMElement
	 */
	private static function create_odt_paragraph_from_inline(
		DOMDocument $doc,
		array $nodes,
		$alignment,
		array &$style_require,
	) {
		$paragraph = $doc->createElementNS( self::ODF_TEXT_NS, 'text:p' );
		if ( null !== $alignment && 'left' !== $alignment ) {
			$style_name = 'DocumentateAlign' . ucfirst( $alignment );
			$paragraph->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', $style_name );
			$style_require[ 'align_' . $alignment ] = true;
		}
		self::trim_odt_inline_nodes( $nodes );
		foreach ( $nodes as $node ) {
			$paragraph->appendChild( $node );
		}
		return $paragraph;
	}

	/**
	 * Extract <tr> nodes from table-related containers preserving order.
	 *
	 * @param DOMNode $node Table DOM node.
	 * @return array<int,DOMElement>
	 */
	private static function extract_table_row_nodes( DOMNode $node ) {
		$rows = array();
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			if ( 'tr' === $tag ) {
				$rows[] = $child;
				continue;
			}
			if ( in_array( $tag, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				$rows = array_merge( $rows, self::extract_table_row_nodes( $child ) );
			}
		}
		return $rows;
	}

	/**
	 * Normalize line endings for lookup keys to improve HTML fragment matching.
	 *
	 * Delegates to OpenTBS_HTML_Parser for implementation.
	 *
	 * @param array<string,string> $lookup Original lookup table.
	 * @return array<string,string>
	 */
	private static function normalize_lookup_line_endings( array $lookup ) {
		return OpenTBS_HTML_Parser::normalize_lookup_line_endings( $lookup );
	}

	/**
	 * Normalize text for HTML matching by removing newlines and excess whitespace.
	 *
	 * Delegates to OpenTBS_HTML_Parser for implementation.
	 *
	 * @param string $text Text to normalize.
	 * @return string Normalized text.
	 */
	private static function normalize_for_html_matching( $text ) {
		return OpenTBS_HTML_Parser::normalize_for_html_matching( $text );
	}

	/**
	 * Normalize literal newline escape sequences and CR characters to LF.
	 *
	 * Delegates to OpenTBS_HTML_Parser for implementation.
	 *
	 * @param string $value Source value.
	 * @return string
	 */
	private static function normalize_text_newlines( $value ) {
		return OpenTBS_HTML_Parser::normalize_text_newlines( $value );
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
	private static function wrap_nodes_with_formatting(
		DOMDocument $doc,
		array $nodes,
		$formatting,
		array &$style_require,
	) {
		$result = $nodes;
		if ( empty( $result ) ) {
			return $result;
		}

		if ( ! empty( $formatting['bold'] ) ) {
			$style_require['bold'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichBold' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['italic'] ) ) {
			$style_require['italic'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichItalic' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['underline'] ) ) {
			$style_require['underline'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichUnderline' );
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
			$link->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichLink' );
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
				$value = $last->nodeValue;
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
		$xpath->registerNamespace( 'table', self::ODF_TABLE_NS );

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
			'bold' => array(
				'name' => 'DocumentateRichBold',
				'family' => 'text',
				'props' => array(
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
			'italic' => array(
				'name' => 'DocumentateRichItalic',
				'family' => 'text',
				'props' => array(
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
				'name' => 'DocumentateRichUnderline',
				'family' => 'text',
				'props' => array(
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
			'link' => array(
				'name' => 'DocumentateRichLink',
				'family' => 'text',
				'props' => array(
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
			'table' => array(
				'name' => 'DocumentateRichTable',
				'family' => 'table',
				'props' => array(
					array(
						'ns' => self::ODF_TABLE_NS,
						'name' => 'table:border-model',
						'value' => 'collapsing',
					),
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:border',
						'value' => self::TABLE_BORDER,
					),
				),
			),
			'table_cell' => array(
				'name' => 'DocumentateRichTableCell',
				'family' => 'table-cell',
				'props' => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:border',
						'value' => self::TABLE_BORDER,
					),
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:padding',
						'value' => self::TABLE_CELL_PADDING,
					),
				),
			),
			'align_center' => array(
				'name' => 'DocumentateAlignCenter',
				'family' => 'paragraph',
				'props' => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:text-align',
						'value' => 'center',
					),
				),
			),
			'align_right' => array(
				'name' => 'DocumentateAlignRight',
				'family' => 'paragraph',
				'props' => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:text-align',
						'value' => 'end',
					),
				),
			),
			'align_justify' => array(
				'name' => 'DocumentateAlignJustify',
				'family' => 'paragraph',
				'props' => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:text-align',
						'value' => 'justify',
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
			// Select the correct properties element depending on the family.
			if ( 'text' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:text-properties' );
			} elseif ( 'table' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:table-properties' );
			} elseif ( 'table-cell' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:table-cell-properties' );
			} elseif ( 'paragraph' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:paragraph-properties' );
			} else {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:text-properties' );
			}
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
	private static function build_docx_nodes_from_html( DOMDocument $doc, $html, $base_rpr = null, &$relationships = null ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$tmp = self::create_html_document( $html );
		if ( ! $tmp ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$body = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$conversion = self::convert_html_children_to_docx( $doc, $body->childNodes, $base_rpr, array(), $relationships, true );
		if ( empty( $conversion['nodes'] ) ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		return $conversion;
	}

	/**
	 * Build inline run nodes from HTML without introducing block structures.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param string                   $html          HTML fragment.
	 * @param DOMElement|null          $base_rpr      Base run properties to clone.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function build_docx_inline_runs_from_html(
		DOMDocument $doc,
		$html,
		$base_rpr = null,
		&$relationships = null,
	) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$tmp = self::create_html_document( $html );
		if ( ! $tmp ) {
			return array();
		}

		$body = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
			return array();
		}

		$runs = self::collect_runs_from_children( $doc, $body->childNodes, $base_rpr, array(), $relationships );
		self::trim_trailing_break_runs( $runs );
		return $runs;
	}

	/**
	 * Convert a list of HTML nodes to WordprocessingML structures.
	 *
	 * @param DOMDocument              $doc                 Target DOMDocument.
	 * @param DOMNodeList              $nodes               HTML nodes to convert.
	 * @param DOMElement|null          $base_rpr            Base run properties to clone.
	 * @param array<string,bool>       $formatting          Active formatting flags.
	 * @param array<string,mixed>|null $relationships       Relationships context, passed by reference.
	 * @param bool                     $allow_inline_result Whether inline-only results are allowed.
	 * @return array{block:bool,nodes:array<int,DOMElement>}
	 */
	private static function convert_html_children_to_docx(
		DOMDocument $doc,
		$nodes,
		$base_rpr,
		array $formatting,
		&$relationships,
		$allow_inline_result,
	) {
		$result = array();
		$current_runs = array();
		$has_block = false;

		if ( ! $nodes instanceof DOMNodeList ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$current_runs = array_merge( $current_runs, self::collect_runs_from_text( $doc, $node, $base_rpr, $formatting ) );
				continue;
			}

			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );
			if ( self::is_block_tag( $tag ) ) {
				if ( ! empty( $current_runs ) ) {
					$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
					$current_runs = array();
				}

				switch ( $tag ) {
					case 'h1':
					case 'h2':
					case 'h3':
					case 'h4':
					case 'h5':
					case 'h6':
						$has_block = true;
						$result = array_merge(
							$result,
							self::convert_heading_node_to_paragraphs(
								$doc,
								$node,
								$base_rpr,
								$relationships,
								$tag,
							)
						);
						break;
					case 'table':
						$has_block = true;
						$table = self::convert_table_node_to_docx( $doc, $node, $base_rpr, $relationships );
						if ( $table ) {
							$result[] = $table;
						}
						break;
					case 'ul':
					case 'ol':
						$has_block = true;
						$list_paragraphs = self::convert_list_to_paragraphs(
							$doc,
							$node,
							$base_rpr,
							$formatting,
							'ol' === $tag,
							$relationships,
						);
						if ( ! empty( $list_paragraphs ) ) {
							$result = array_merge( $result, $list_paragraphs );
						}
						break;
					case 'p':
					case 'div':
						$has_block = true;
						$p_alignment = self::extract_text_alignment( $node );
						$paragraph_runs = self::collect_runs_from_children(
							$doc,
							$node->childNodes,
							$base_rpr,
							$formatting,
							$relationships,
						);
						$block_paragraph = self::create_paragraph_from_runs( $doc, $paragraph_runs, $base_rpr, $p_alignment );
						$result[] = $block_paragraph;
						break;
					default:
						$has_block = true;
						$paragraph_runs = self::collect_runs_from_children(
							$doc,
							$node->childNodes,
							$base_rpr,
							$formatting,
							$relationships,
						);
						$block_paragraph = self::create_paragraph_from_runs( $doc, $paragraph_runs, $base_rpr );
						$result[] = $block_paragraph;
				}
			} else {
				$current_runs = array_merge(
					$current_runs,
					self::collect_runs_from_element(
						$doc,
						$node,
						$base_rpr,
						$formatting,
						$relationships,
					)
				);
			}
		}

		if ( ! empty( $current_runs ) ) {
			if ( $has_block || ! $allow_inline_result ) {
				$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
				$current_runs = array();
			}
		}

		if ( $has_block || ! $allow_inline_result ) {
			return array(
				'block' => true,
				'nodes' => $result,
			);
		}

		return array(
			'block' => false,
			'nodes' => $current_runs,
		);
	}

	/**
	 * Determine whether a tag should be treated as block-level during conversion.
	 *
	 * @param string $tag Lowercase tag name.
	 * @return bool
	 */
	private static function is_block_tag( $tag ) {
		$block_tags = array(
			'p',
			'div',
			'section',
			'article',
			'blockquote',
			'address',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'ul',
			'ol',
			'table',
		);
		return in_array( $tag, $block_tags, true );
	}

	/**
	 * Collect runs for all children of a node.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNodeList              $children      Node list.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Active formatting.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_children(
		DOMDocument $doc,
		$children,
		$base_rpr,
		array $formatting,
		&$relationships,
	) {
		$runs = array();
		if ( ! $children instanceof DOMNodeList ) {
			return $runs;
		}

		foreach ( $children as $child ) {
			$runs = array_merge( $runs, self::collect_runs_from_node( $doc, $child, $base_rpr, $formatting, $relationships ) );
		}

		return $runs;
	}

	/**
	 * Collect runs from a single DOM node according to formatting flags.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNode                  $node          Node to convert.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_node(
		DOMDocument $doc,
		$node,
		$base_rpr,
		array $formatting,
		&$relationships,
	) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return self::collect_runs_from_text( $doc, $node, $base_rpr, $formatting );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		return self::collect_runs_from_element( $doc, $node, $base_rpr, $formatting, $relationships );
	}

	/**
	 * Convert a text node into formatted runs.
	 *
	 * @param DOMDocument        $doc        Target DOMDocument.
	 * @param DOMText            $text_node  Text node.
	 * @param DOMElement|null    $base_rpr   Base run properties to clone.
	 * @param array<string,bool> $formatting Formatting flags.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_text( DOMDocument $doc, DOMText $text_node, $base_rpr, array $formatting ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text_node->wholeText );
		$parts = explode( "\n", $text );
		$runs = array();

		foreach ( $parts as $index => $part ) {
			if ( '' !== $part ) {
				$run = self::create_text_run( $doc, $part, $base_rpr, $formatting );
				if ( $run ) {
					$runs[] = $run;
				}
			}

			if ( $index < ( count( $parts ) - 1 ) ) {
				$runs[] = self::create_break_run( $doc, $base_rpr );
			}
		}

		return $runs;
	}

	/**
	 * Convert an element node into formatted runs.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $element       Element node.
	 * @param DOMElement|null          $base_rpr      Base run properties to clone.
	 * @param array<string,bool>       $formatting    Active formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_element(
		DOMDocument $doc,
		DOMElement $element,
		$base_rpr,
		array $formatting,
		&$relationships,
	) {
		$tag = strtolower( $element->nodeName );
		switch ( $tag ) {
			case 'strong':
			case 'b':
				return self::collect_runs_from_children(
					$doc,
					$element->childNodes,
					$base_rpr,
					self::with_format_flag( $formatting, 'bold', true ),
					$relationships,
				);
			case 'em':
			case 'i':
				return self::collect_runs_from_children(
					$doc,
					$element->childNodes,
					$base_rpr,
					self::with_format_flag( $formatting, 'italic', true ),
					$relationships,
				);
			case 'u':
				return self::collect_runs_from_children(
					$doc,
					$element->childNodes,
					$base_rpr,
					self::with_format_flag( $formatting, 'underline', true ),
					$relationships,
				);
			case 'span':
				return self::collect_runs_from_children(
					$doc,
					$element->childNodes,
					$base_rpr,
					self::extract_span_formatting( $formatting, $element ),
					$relationships,
				);
			case 'br':
				return array( self::create_break_run( $doc, $base_rpr ) );
			case 'a':
				$href = trim( $element->getAttribute( 'href' ) );
				$link_formatting = self::with_format_flag( $formatting, 'hyperlink', true );
				$link_formatting = self::with_format_flag( $link_formatting, 'underline', true );
				$link_runs = self::collect_runs_from_children(
					$doc,
					$element->childNodes,
					$base_rpr,
					$link_formatting,
					$relationships,
				);

				if ( empty( $link_runs ) && '' !== $href ) {
					$fallback_run = self::create_text_run( $doc, $href, $base_rpr, $link_formatting );
					if ( $fallback_run ) {
						$link_runs[] = $fallback_run;
					}
				}

				$hyperlink = self::create_hyperlink_container( $doc, $link_runs, $relationships, $href );
				if ( $hyperlink ) {
					return array( $hyperlink );
				}

				return $link_runs;
			default:
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, $formatting, $relationships );
		}
	}

	/**
	 * Convert a heading element into paragraphs with spacing.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $heading       Heading element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @param string                   $tag           Heading tag name.
	 * @return array<int,DOMElement>
	 */
	private static function convert_heading_node_to_paragraphs(
		DOMDocument $doc,
		DOMElement $heading,
		$base_rpr,
		&$relationships,
		$tag,
	) {
		$paragraphs = array();
		$paragraphs[] = self::create_blank_paragraph( $doc, $base_rpr );

		$formatting = self::with_format_flag( array(), 'bold', true );
		$runs = self::collect_runs_from_children( $doc, $heading->childNodes, $base_rpr, $formatting, $relationships );
		$heading_p = self::create_paragraph_from_runs( $doc, $runs, $base_rpr );
		$paragraphs[] = $heading_p;

		$paragraphs[] = self::create_blank_paragraph( $doc, $base_rpr );

		return $paragraphs;
	}

	/**
	 * Convert a HTML list into basic paragraphs with bullet or numeric prefixes.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $list          List element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Active formatting flags.
	 * @param bool                     $ordered       Whether ordered list.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @param int                      $depth         Nesting depth for indentation.
	 * @return array<int,DOMElement>
	 */
	private static function convert_list_to_paragraphs(
		DOMDocument $doc,
		DOMElement $list,
		$base_rpr,
		array $formatting,
		$ordered,
		&$relationships,
		$depth = 0,
	) {
		$paragraphs = array();
		$index = 1;
		$indent = str_repeat( '  ', $depth );

		foreach ( $list->childNodes as $item ) {
			if ( ! $item instanceof DOMElement || 'li' !== strtolower( $item->nodeName ) ) {
				continue;
			}

			$prefix = $ordered ? $indent . $index . '. ' : $indent . '• ';
			$runs = array();

			$prefix_run = self::create_text_run( $doc, $prefix, $base_rpr, $formatting );
			if ( $prefix_run ) {
				$runs[] = $prefix_run;
			}

			// Collect runs from inline content, but handle nested lists separately.
			$nested_lists = array();
			foreach ( $item->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$child_tag = strtolower( $child->nodeName );
					if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
						$nested_lists[] = array(
							'node' => $child,
							'ordered' => 'ol' === $child_tag,
						);
						continue;
					}
				}
				// Collect runs from non-list child nodes.
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$runs = array_merge( $runs, self::collect_runs_from_text( $doc, $child, $base_rpr, $formatting ) );
				} elseif ( $child instanceof DOMElement ) {
					$runs = array_merge( $runs, self::collect_runs_from_element( $doc, $child, $base_rpr, $formatting, $relationships ) );
				}
			}

			$paragraphs[] = self::create_paragraph_from_runs( $doc, $runs, $base_rpr );

			// Process nested lists recursively.
			foreach ( $nested_lists as $nested ) {
				$nested_paragraphs = self::convert_list_to_paragraphs(
					$doc,
					$nested['node'],
					$base_rpr,
					$formatting,
					$nested['ordered'],
					$relationships,
					$depth + 1,
				);
				$paragraphs = array_merge( $paragraphs, $nested_paragraphs );
			}

			$index++;
		}

		return $paragraphs;
	}

	/**
	 * Convert an HTML table node into a WordprocessingML table.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $table         Table element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return DOMElement|null
	 */
	private static function convert_table_node_to_docx( DOMDocument $doc, DOMElement $table, $base_rpr, &$relationships ) {
		$rows = self::extract_table_row_nodes( $table );
		if ( empty( $rows ) ) {
			return null;
		}

		$tbl = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tbl' );
		// Add default table borders: 1px black for all edges and inner lines.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec (w:tblPr).
		$tblPr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tblPr' );
		$borders = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tblBorders' );
		$edges = array( 'top', 'left', 'bottom', 'right', 'insideH', 'insideV' );
		foreach ( $edges as $edge ) {
			$el = $doc->createElementNS( self::WORD_NAMESPACE, 'w:' . $edge );
			$el->setAttribute( 'w:val', 'single' );
			$el->setAttribute( 'w:sz', '8' );
			$el->setAttribute( 'w:space', '0' );
			$el->setAttribute( 'w:color', '000000' );
			$borders->appendChild( $el );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec.
		$tblPr->appendChild( $borders );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec.
		$tbl->appendChild( $tblPr );

		// Track rowspan coverage: column_index => remaining rows to cover.
		$rowspan_map = array();

		foreach ( $rows as $row ) {
			$tr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tr' );
			$column_index = 0;

			$cells = array();
			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}
				$cell_tag = strtolower( $cell->nodeName );
				if ( 'td' === $cell_tag || 'th' === $cell_tag ) {
					$cells[] = $cell;
				}
			}

			$cell_iter  = 0;
			$cell_count = count( $cells );
			while ( $cell_iter < $cell_count || isset( $rowspan_map[ $column_index ] ) ) {
				// Insert continuation cells for rowspan from previous rows.
				while ( isset( $rowspan_map[ $column_index ] ) && $rowspan_map[ $column_index ] > 0 ) {
					$tc = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tc' );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
					$tcPr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tcPr' );
					$vmerge = $doc->createElementNS( self::WORD_NAMESPACE, 'w:vMerge' );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
					$tcPr->appendChild( $vmerge );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
					$tc->appendChild( $tcPr );
					$tc->appendChild( self::create_blank_paragraph( $doc, $base_rpr ) );
					$tr->appendChild( $tc );
					// Consume: decrement and remove if exhausted.
					$rowspan_map[ $column_index ]--;
					if ( 0 === $rowspan_map[ $column_index ] ) {
						unset( $rowspan_map[ $column_index ] );
					}
					$column_index++;
				}

				if ( $cell_iter >= count( $cells ) ) {
					break;
				}

				$cell = $cells[ $cell_iter ];
				$cell_tag = strtolower( $cell->nodeName );

				$tc = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tc' );

				$colspan = max( 1, (int) $cell->getAttribute( 'colspan' ) );
				$rowspan = max( 1, (int) $cell->getAttribute( 'rowspan' ) );

				// Build cell properties if colspan or rowspan are used.
				if ( $colspan > 1 || $rowspan > 1 ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
					$tcPr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tcPr' );
					if ( $colspan > 1 ) {
						$grid_span = $doc->createElementNS( self::WORD_NAMESPACE, 'w:gridSpan' );
						$grid_span->setAttribute( 'w:val', (string) $colspan );
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
						$tcPr->appendChild( $grid_span );
					}
					if ( $rowspan > 1 ) {
						$vmerge = $doc->createElementNS( self::WORD_NAMESPACE, 'w:vMerge' );
						$vmerge->setAttribute( 'w:val', 'restart' );
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
						$tcPr->appendChild( $vmerge );
					}
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
					$tc->appendChild( $tcPr );
				}

				// Register rowspan for subsequent rows.
				if ( $rowspan > 1 ) {
					for ( $c = 0; $c < $colspan; $c++ ) {
						$rowspan_map[ $column_index + $c ] = $rowspan - 1;
					}
				}

				$cell_formatting = array();
				if ( 'th' === $cell_tag ) {
					$cell_formatting['bold'] = true;
				}

				// Extract alignment from cell or first paragraph child.
				$cell_alignment = self::extract_text_alignment( $cell );
				if ( null === $cell_alignment ) {
					foreach ( $cell->childNodes as $child ) {
						if ( $child instanceof DOMElement && 'p' === strtolower( $child->nodeName ) ) {
							$cell_alignment = self::extract_text_alignment( $child );
							break;
						}
					}
				}

				// Handle block elements (lists, nested tables) inside table cells.
				$cell_content = self::convert_cell_content_to_docx(
					$doc,
					$cell->childNodes,
					$base_rpr,
					$cell_formatting,
					$relationships,
					$cell_alignment,
				);
				foreach ( $cell_content as $content_node ) {
					if ( $content_node instanceof DOMElement ) {
						$tc->appendChild( $content_node );
					}
				}

				// Ensure cell has at least one paragraph (required by OOXML).
				// Check if there's a paragraph after tcPr (tcPr doesn't count as content).
				$has_content = false;
				foreach ( $tc->childNodes as $tc_child ) {
					if ( $tc_child instanceof DOMElement && 'w:tcPr' !== $tc_child->nodeName ) {
						$has_content = true;
						break;
					}
				}
				if ( ! $has_content ) {
					$tc->appendChild( self::create_blank_paragraph( $doc, $base_rpr ) );
				}

				$tr->appendChild( $tc );
				$column_index += $colspan;
				$cell_iter++;
			}

			// Handle any remaining covered columns after all cells.
			while ( isset( $rowspan_map[ $column_index ] ) && $rowspan_map[ $column_index ] > 0 ) {
				$tc = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tc' );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
				$tcPr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tcPr' );
				$vmerge = $doc->createElementNS( self::WORD_NAMESPACE, 'w:vMerge' );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
				$tcPr->appendChild( $vmerge );
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tcPr matches WordprocessingML spec.
				$tc->appendChild( $tcPr );
				$tc->appendChild( self::create_blank_paragraph( $doc, $base_rpr ) );
				$tr->appendChild( $tc );
				$rowspan_map[ $column_index ]--;
				if ( 0 === $rowspan_map[ $column_index ] ) {
					unset( $rowspan_map[ $column_index ] );
				}
				$column_index++;
			}

			if ( $tr->childNodes->length > 0 ) {
				$tbl->appendChild( $tr );
			}
		}

		if ( 0 === $tbl->childNodes->length ) {
			return null;
		}

		return $tbl;
	}

	/**
	 * Convert table cell content to DOCX nodes, handling both inline and block elements.
	 *
	 * Unlike collect_runs_from_children which only handles inline content, this method
	 * properly converts block elements like lists and nested tables inside table cells.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNodeList              $children      Child nodes of the cell.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @param string|null              $alignment     Cell alignment (left, center, right, justify).
	 * @return array<int,DOMElement>   Array of paragraph elements.
	 */
	private static function convert_cell_content_to_docx(
		DOMDocument $doc,
		$children,
		$base_rpr,
		array $formatting,
		&$relationships,
		$alignment = null,
	) {
		$result = array();
		$current_runs = array();

		if ( ! $children instanceof DOMNodeList ) {
			return $result;
		}

		foreach ( $children as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$current_runs = array_merge( $current_runs, self::collect_runs_from_text( $doc, $child, $base_rpr, $formatting ) );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			// Handle block elements.
			if ( 'ul' === $tag || 'ol' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr, $alignment );
					$current_runs = array();
				}
				// Convert list to paragraphs.
				$list_paragraphs = self::convert_list_to_paragraphs(
					$doc,
					$child,
					$base_rpr,
					$formatting,
					'ol' === $tag,
					$relationships,
				);
				$result = array_merge( $result, $list_paragraphs );
				continue;
			}

			if ( 'table' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr, $alignment );
					$current_runs = array();
				}
				// Convert nested table.
				$nested_table = self::convert_table_node_to_docx( $doc, $child, $base_rpr, $relationships );
				if ( $nested_table ) {
					$result[] = $nested_table;
				}
				continue;
			}

			if ( 'p' === $tag || 'div' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr, $alignment );
					$current_runs = array();
				}
				// Extract paragraph-specific alignment or use cell alignment.
				$p_alignment = self::extract_text_alignment( $child );
				if ( null === $p_alignment ) {
					$p_alignment = $alignment;
				}
				// Convert paragraph content.
				$p_runs = self::collect_runs_from_children( $doc, $child->childNodes, $base_rpr, $formatting, $relationships );
				$result[] = self::create_paragraph_from_runs( $doc, $p_runs, $base_rpr, $p_alignment );
				continue;
			}

			// Handle inline elements.
			$current_runs = array_merge(
				$current_runs,
				self::collect_runs_from_element(
					$doc,
					$child,
					$base_rpr,
					$formatting,
					$relationships,
				)
			);
		}

		// Flush remaining inline runs.
		if ( ! empty( $current_runs ) ) {
			$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr, $alignment );
		}

		return $result;
	}

	/**
	 * Create a paragraph element from a list of runs/hyperlink nodes.
	 *
	 * @param DOMDocument           $doc       Target DOMDocument.
	 * @param array<int,DOMElement> $runs      Runs to append.
	 * @param DOMElement|null       $base_rpr  Base run properties reference.
	 * @param string|null           $alignment Text alignment (left, center, right, justify).
	 * @param DOMElement|null       $base_ppr  Base paragraph properties reference.
	 * @return DOMElement
	 */
	private static function create_paragraph_from_runs(
		DOMDocument $doc,
		array $runs,
		$base_rpr,
		$alignment = null,
		$base_ppr = null,
	) {
		$paragraph = $doc->createElementNS( self::WORD_NAMESPACE, 'w:p' );

		if ( $base_ppr instanceof DOMElement ) {
			$paragraph->appendChild( $base_ppr->cloneNode( true ) );
		} elseif ( null !== $alignment && 'left' !== $alignment ) {
			// Add paragraph properties with justification if alignment is specified.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $pPr matches WordprocessingML spec.
			$pPr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:pPr' );
			$jc = $doc->createElementNS( self::WORD_NAMESPACE, 'w:jc' );
			$jc->setAttribute( 'w:val', self::css_alignment_to_docx( $alignment ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $pPr matches WordprocessingML spec.
			$pPr->appendChild( $jc );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $pPr matches WordprocessingML spec.
			$paragraph->appendChild( $pPr );
		}

		if ( empty( $runs ) ) {
			$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
			return $paragraph;
		}

		foreach ( $runs as $run ) {
			if ( $run instanceof DOMElement ) {
				$paragraph->appendChild( $run );
			}
		}

		// Check if paragraph only contains pPr (no actual content).
		$has_content = false;
		foreach ( $paragraph->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'w:pPr' !== $child->nodeName ) {
				$has_content = true;
				break;
			}
		}
		if ( ! $has_content ) {
			$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
		}

		return $paragraph;
	}

	/**
	 * Build paragraph replacements for DOCX block content inserted inline.
	 *
	 * Prefix and suffix text are merged into the first and last generated
	 * paragraphs when possible so inline placeholders keep their surrounding text.
	 *
	 * @param DOMDocument           $doc            Target DOMDocument.
	 * @param DOMElement            $source_para    Original paragraph.
	 * @param array<int,DOMElement> $nodes         Converted block nodes.
	 * @param string                $prefix         Text before the placeholder.
	 * @param string                $suffix         Text after the placeholder.
	 * @param DOMElement|null       $base_rpr       Base run properties.
	 * @return array<int,DOMElement>
	 */
	private static function build_docx_block_replacement_nodes(
		DOMDocument $doc,
		DOMElement $source_para,
		array $nodes,
		$prefix,
		$suffix,
		$base_rpr,
	) {
		$replacement_nodes = array();
		$inline_buffer = self::build_docx_runs_from_plain_text( $doc, $prefix, $base_rpr );
		$base_ppr = self::clone_paragraph_properties( $source_para );

		foreach ( $nodes as $node ) {
			if ( ! self::is_docx_paragraph( $node ) ) {
				if ( ! empty( $inline_buffer ) ) {
					$replacement_nodes[] = self::create_paragraph_from_runs( $doc, $inline_buffer, $base_rpr, null, $base_ppr );
					$inline_buffer = array();
				}

				$replacement_nodes[] = $node;
				continue;
			}

			self::inherit_docx_paragraph_properties( $node, $base_ppr );
			if ( ! empty( $inline_buffer ) ) {
				self::prepend_runs_to_docx_paragraph( $node, $inline_buffer );
				$inline_buffer = array();
			}

			$replacement_nodes[] = $node;
		}

		$inline_buffer = array_merge( $inline_buffer, self::build_docx_runs_from_plain_text( $doc, $suffix, $base_rpr ) );
		if ( ! empty( $inline_buffer ) ) {
			$last_node = ! empty( $replacement_nodes ) ? end( $replacement_nodes ) : null;
			if ( $last_node instanceof DOMElement && self::is_docx_paragraph( $last_node ) ) {
				self::append_runs_to_docx_paragraph( $last_node, $inline_buffer );
			} else {
				$replacement_nodes[] = self::create_paragraph_from_runs( $doc, $inline_buffer, $base_rpr, null, $base_ppr );
			}
		}

		return $replacement_nodes;
	}

	/**
	 * Convert plain text into DOCX runs while preserving soft line breaks.
	 *
	 * @param DOMDocument     $doc      Target DOMDocument.
	 * @param string          $text     Plain text.
	 * @param DOMElement|null $base_rpr Base run properties.
	 * @return array<int,DOMElement>
	 */
	private static function build_docx_runs_from_plain_text( DOMDocument $doc, $text, $base_rpr ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$parts = explode( "\n", $text );
		$runs = array();
		foreach ( $parts as $index => $part ) {
			if ( '' !== $part ) {
				$runs[] = self::build_docx_text_run( $doc, $part, $base_rpr );
			}

			if ( $index < ( count( $parts ) - 1 ) ) {
				$runs[] = self::create_break_run( $doc, $base_rpr );
			}
		}

		return $runs;
	}

	/**
	 * Determine whether a DOCX node is a paragraph.
	 *
	 * @param DOMElement $node Candidate node.
	 * @return bool
	 */
	private static function is_docx_paragraph( DOMElement $node ) {
		return self::WORD_NAMESPACE === $node->namespaceURI && 'p' === $node->localName;
	}

	/**
	 * Clone paragraph properties from an existing paragraph if available.
	 *
	 * @param DOMElement $paragraph Paragraph to inspect.
	 * @return DOMElement|null
	 */
	private static function clone_paragraph_properties( DOMElement $paragraph ) {
		foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'pPr' === $child->localName ) {
				return $child->cloneNode( true );
			}
		}

		return null;
	}

	/**
	 * Apply inherited paragraph properties to a generated paragraph when needed.
	 *
	 * @param DOMElement      $paragraph Paragraph to update.
	 * @param DOMElement|null $base_ppr  Paragraph properties to inherit.
	 */
	private static function inherit_docx_paragraph_properties( DOMElement $paragraph, $base_ppr ) {
		if ( ! $base_ppr instanceof DOMElement ) {
			return;
		}

		foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'pPr' === $child->localName ) {
				return;
			}
		}

		$paragraph->insertBefore( $base_ppr->cloneNode( true ), $paragraph->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Prepend runs to a DOCX paragraph after its paragraph properties.
	 *
	 * @param DOMElement            $paragraph Paragraph to update.
	 * @param array<int,DOMElement> $runs   Runs to prepend.
	 */
	private static function prepend_runs_to_docx_paragraph( DOMElement $paragraph, array $runs ) {
		$reference = null;
		foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'pPr' === $child->localName ) {
				continue;
			}

			$reference = $child;
			break;
		}

		foreach ( $runs as $run ) {
			if ( $reference instanceof DOMNode ) {
				$paragraph->insertBefore( $run, $reference );
				continue;
			}

			$paragraph->appendChild( $run );
		}
	}

	/**
	 * Append runs to a DOCX paragraph.
	 *
	 * @param DOMElement            $paragraph Paragraph to update.
	 * @param array<int,DOMElement> $runs   Runs to append.
	 */
	private static function append_runs_to_docx_paragraph( DOMElement $paragraph, array $runs ) {
		foreach ( $runs as $run ) {
			$paragraph->appendChild( $run );
		}
	}

	/**
	 * Create a blank Word run preserving whitespace.
	 *
	 * @param DOMDocument     $doc      Target DOMDocument.
	 * @param DOMElement|null $base_rpr Base run properties.
	 * @return DOMElement
	 */
	private static function create_blank_run( DOMDocument $doc, $base_rpr ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}

		$text = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		$text->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
		$text->appendChild( $doc->createTextNode( '' ) );
		$run->appendChild( $text );

		return $run;
	}

	/**
	 * Create a blank paragraph.
	 *
	 * @param DOMDocument     $doc      Target DOMDocument.
	 * @param DOMElement|null $base_rpr Base run properties reference.
	 * @return DOMElement
	 */
	private static function create_blank_paragraph( DOMDocument $doc, $base_rpr ) {
		$paragraph = $doc->createElementNS( self::WORD_NAMESPACE, 'w:p' );
		$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
		return $paragraph;
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
				list($prop, $val) = array_map( 'trim', explode( ':', $rule ) + array( '', '' ) );
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
	 * Extract text-align value from an element's style attribute.
	 *
	 * @param DOMElement $node Element to check for alignment.
	 * @return string|null Alignment value (left, center, right, justify) or null.
	 */
	private static function extract_text_alignment( DOMElement $node ) {
		$style = $node->getAttribute( 'style' );
		if ( empty( $style ) ) {
			return null;
		}

		if ( preg_match( '/text-align\s*:\s*(left|center|right|justify)/i', $style, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return null;
	}

	/**
	 * Check if a paragraph node contains only non-breaking spaces.
	 *
	 * This detects intentional spacing paragraphs like <p>&nbsp;</p>.
	 *
	 * @param DOMNode $node The paragraph node to check.
	 * @return bool True if the paragraph contains only nbsp characters.
	 */
	private static function is_nbsp_only_paragraph( DOMNode $node ) {
		$text_content = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text_content .= $child->wholeText;
			} elseif ( $child instanceof DOMElement ) {
				// If there are child elements (like <strong>), it's not a spacing-only paragraph.
				return false;
			}
		}

		// Decode HTML entities.
		$decoded = html_entity_decode( $text_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Check if it only contains non-breaking space characters (U+00A0).
		$trimmed = trim( $decoded );
		$nbsp_only = preg_replace( '/[\x{00A0}]+/u', '', $trimmed );

		return '' === $nbsp_only && '' !== $trimmed;
	}

	/**
	 * Convert CSS text-align value to DOCX w:jc value.
	 *
	 * @param string $alignment CSS alignment value.
	 * @return string DOCX justification value.
	 */
	private static function css_alignment_to_docx( $alignment ) {
		switch ( $alignment ) {
			case 'center':
				return 'center';
			case 'right':
				return 'right';
			case 'justify':
				return 'both';
			default:
				return 'left';
		}
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
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc->formatOutput = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$map = array();
		$next_id = 0;
		libxml_use_internal_errors( true );
		if ( false === $rels_xml || '' === trim( (string) $rels_xml ) || ! $doc->loadXML( $rels_xml, LIBXML_NONET ) ) {
			$doc->loadXML( '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships" />', LIBXML_NONET );
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
				if (
					'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' === $relationship->getAttribute(
						'Type',
					)
				) {
					$target = $relationship->getAttribute( 'Target' );
					if ( '' !== $target ) {
						$map[ $target ] = $id;
					}
				}
			}
		}
		return array(
			'path' => $rel_path,
			'doc' => $doc,
			'next_index' => (int) $next_id,
			'map' => $map,
			'modified' => false,
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
		$dir = dirname( $target );
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
		$doc = $relationships['doc'];
		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return '';
		}
		$relationships['next_index'] = (int) $relationships['next_index'] + 1;
		$r_id = 'rId' . $relationships['next_index'];
		$relationship = $doc->createElementNS( $root->namespaceURI, 'Relationship' );
		$relationship->setAttribute( 'Id', $r_id );
		$relationship->setAttribute( 'Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' );
		$relationship->setAttribute( 'Target', $target );
		$relationship->setAttribute( 'TargetMode', 'External' );
		$root->appendChild( $relationship );
		$relationships['map'][ $target ] = $r_id;
		$relationships['modified'] = true;
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
		$hyperlink->setAttributeNS(
			'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
			'r:id',
			$relationship_id,
		);
		$hyperlink->setAttribute( 'w:history', '1' );
		foreach ( $link_runs as $run ) {
			if ( ! $run instanceof DOMElement ) {
				continue;
			}

			$hyperlink->appendChild( $run );
		}
		return $hyperlink;
	}

	/**
	 * Split double-line-break sequences in an ODT content.xml string into real paragraphs.
	 *
	 * TBS converts "\n" to <text:line-break/>, so a blank-line paragraph separator ("\n\n")
	 * becomes two consecutive line-break elements inside a single paragraph. LibreOffice
	 * justifies the visible line before each manual break, producing huge spaces. This step
	 * detects those sequences and reconstructs them as proper sibling <text:p> nodes.
	 *
	 * @param string $xml Raw content.xml XML string.
	 * @return string Possibly-modified XML string.
	 */
	public static function split_odt_content_paragraphs( $xml ) {
		$doc = self::create_xml_document( $xml );
		if ( ! $doc ) {
			return $xml;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );

		$modified = false;

		// Repeat until no more splits are needed: inserting new paragraphs invalidates the list.
		do {
			$changed = false;

			$paragraphs = $xpath->query( '//text:p' );
			if ( ! $paragraphs instanceof DOMNodeList ) {
				break;
			}

			$para_array = array();
			foreach ( $paragraphs as $para ) {
				$para_array[] = $para;
			}

			foreach ( $para_array as $paragraph ) {
				if ( ! $paragraph instanceof DOMElement ) {
					continue;
				}

				// Look for the first span that contains any line-break.
				foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( ! $child instanceof DOMElement || self::ODF_TEXT_NS !== $child->namespaceURI || 'span' !== $child->localName ) {
						continue;
					}

					if ( ! self::odt_span_has_any_linebreak( $child ) ) {
						continue;
					}

					if ( self::split_odt_paragraph_at_span_linebreaks( $paragraph, $child ) ) {
						$changed = true;
						$modified = true;
					}
					break; // Process one span at a time; restart the paragraph scan.
				}
			}
		} while ( $changed );

		if ( $modified ) {
			return $doc->saveXML();
		}

		return $xml;
	}

	/**
	 * Return true when a <text:span> contains at least one <text:line-break/> element.
	 *
	 * @param DOMElement $span The span element to inspect.
	 * @return bool
	 */
	private static function odt_span_has_any_linebreak( DOMElement $span ) {
		foreach ( $span->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if (
				$child instanceof DOMElement
				&& self::ODF_TEXT_NS === $child->namespaceURI
				&& 'line-break' === $child->localName
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Split a paragraph at line-break sequences inside one of its spans.
	 *
	 * Each <text:line-break/> becomes a paragraph boundary (tight, no gap).
	 * Two or more consecutive <text:line-break/> elements become a paragraph boundary followed
	 * by an empty gap paragraph (visual blank line between paragraphs).
	 *
	 * The original paragraph is replaced by the generated sibling paragraphs.  Paragraph style
	 * and other sibling nodes (spans before/after the split span) are preserved.
	 *
	 * @param DOMElement $paragraph The paragraph that owns the span.
	 * @param DOMElement $span      The span that contains line-break sequences.
	 * @return bool True if the paragraph was actually split.
	 */
	private static function split_odt_paragraph_at_span_linebreaks( DOMElement $paragraph, DOMElement $span ) {
		$parent = $paragraph->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $parent instanceof DOMNode ) {
			return false;
		}

		$segments = self::split_span_content_at_linebreaks( $span );
		if ( count( $segments ) <= 1 ) {
			return false;
		}

		// Collect paragraph-level siblings before and after the target span.
		$before_span = array();
		$after_span = array();
		$found = false;
		foreach ( $paragraph->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child === $span ) {
				$found = true;
				continue;
			}
			if ( ! $found ) {
				$before_span[] = $child;
			} else {
				$after_span[] = $child;
			}
		}

		$segment_count = count( $segments );
		$new_paragraphs = array();

		foreach ( $segments as $idx => $segment ) {
			$new_para = $paragraph->cloneNode( false );

			// The first new paragraph inherits any nodes that preceded the split span.
			if ( 0 === $idx ) {
				foreach ( $before_span as $node ) {
					$new_para->appendChild( $node->cloneNode( true ) );
				}
			}

			// Wrap segment content in a clone of the original span (preserving its style).
			if ( ! empty( $segment['nodes'] ) ) {
				$new_span = $span->cloneNode( false );
				foreach ( $segment['nodes'] as $node ) {
					$new_span->appendChild( $node->cloneNode( true ) );
				}
				$new_para->appendChild( $new_span );
			}

			// The last new paragraph inherits any nodes that followed the split span.
			if ( ( $segment_count - 1 ) === $idx ) {
				foreach ( $after_span as $node ) {
					$new_para->appendChild( $node->cloneNode( true ) );
				}
			}

			$new_paragraphs[] = $new_para;

			// A double (or more) line-break separator means a visual gap: insert an empty paragraph.
			if ( $segment['gap_after'] ) {
				$new_paragraphs[] = $paragraph->cloneNode( false );
			}
		}

		foreach ( $new_paragraphs as $new_para ) {
			$parent->insertBefore( $new_para, $paragraph );
		}
		$parent->removeChild( $paragraph );

		return true;
	}

	/**
	 * Split the child-node sequence of a span at every line-break boundary.
	 *
	 * A single <text:line-break/> is a tight paragraph separator (no gap).
	 * Two or more consecutive <text:line-break/> elements are a gap separator (blank line follows).
	 *
	 * Returns an array of segments.  Each segment is an associative array:
	 *   'nodes'     => DOMNode[]  Content nodes for this logical paragraph.
	 *   'gap_after' => bool       True when a blank-line gap paragraph should follow this one.
	 *
	 * The line-break delimiters themselves are not included in any segment.
	 *
	 * @param DOMElement $span The span to analyse.
	 * @return array<int,array{nodes:array<int,DOMNode>,gap_after:bool}>
	 */
	private static function split_span_content_at_linebreaks( DOMElement $span ) {
		// Index all children for look-ahead.
		$children = array();
		foreach ( $span->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$children[] = $child;
		}
		$n = count( $children );

		$segments = array();
		$current = array();
		$i = 0;

		while ( $i < $n ) {
			$child = $children[ $i ];
			$is_lb =
				$child instanceof DOMElement && self::ODF_TEXT_NS === $child->namespaceURI && 'line-break' === $child->localName;

			if ( $is_lb ) {
				// Count how many consecutive line-breaks follow.
				$lb_count = 0;
				while ( $i < $n ) {
					$c = $children[ $i ];
					if ( ! ( $c instanceof DOMElement && self::ODF_TEXT_NS === $c->namespaceURI && 'line-break' === $c->localName ) ) {
						break;
					}
					$lb_count++;
					$i++;
				}
				// Close current segment; gap_after = true for 2+ consecutive line-breaks.
				$segments[] = array(
					'nodes' => $current,
					'gap_after' => $lb_count >= 2,
				);
				$current = array();
			} else {
				$current[] = $child;
				$i++;
			}
		}

		// Push the last (or only) segment.
		$segments[] = array(
			'nodes' => $current,
			'gap_after' => false,
		);

		return $segments;
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
