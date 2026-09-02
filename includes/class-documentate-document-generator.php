<?php
/**
 * Document generator for Documentate based on OpenTBS templates.
 *
 * Generates DOCX/ODT using preconfigured templates via OpenTBS. PDF follows the
 * engine the site selected: drawn natively by Documentate_Pdf_Generator out of
 * an HTML layout, or converted from the office template by
 * Documentate_Conversion_Manager.
 *
 * @package Documentate
 */

use Documentate\Document\Meta\Document_Meta;
use Documentate\Documents\Documents_Meta_Handler;
use Documentate\OpenTBS\OpenTBS_HTML_Parser;

/**
 * Documentate document generator service.
 */
class Documentate_Document_Generator {
	/**
	 * Generate a DOCX file for a given Document post using a DOCX template.
	 *
	 * The editable download is offered in the format the document type has a
	 * template for, and in no other: a type without a DOCX template reports
	 * that instead of rendering the ODT one and converting it.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_docx( $post_id ) {
		try {
			$docx_template = self::get_template_path( $post_id, 'docx' );
			if ( '' === $docx_template ) {
				return new WP_Error(
					'documentate_template_missing',
					'Configura una plantilla DOCX en el tipo de documento seleccionado.'
				);
			}

			return self::render_with_template( $post_id, $docx_template, 'docx' );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'documentate_docx_error', $e->getMessage() );
		}
	}

	/**
	 * Generate an ODT file for a given Document post using an ODT template.
	 *
	 * The mirror image of generate_docx(): no ODT template means no ODT, not a
	 * DOCX rendered and converted.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_odt( $post_id ) {
		try {
			$odt_template = self::get_template_path( $post_id, 'odt' );
			if ( '' === $odt_template ) {
				return new WP_Error(
					'documentate_template_missing',
					'Configura una plantilla ODT en el tipo de documento seleccionado.'
				);
			}

			return self::render_with_template( $post_id, $odt_template, 'odt' );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'documentate_odt_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve sanitized schema definition for a document type.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	private static function get_type_schema( $term_id ) {
		$storage = new Documentate\DocType\SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );
		if ( ! is_array( $schema_v2 ) || empty( $schema_v2 ) ) {
			return array();
		}

		$legacy = Documentate\DocType\SchemaConverter::to_legacy( $schema_v2 );
		$out = array();
		foreach ( $legacy as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
			$label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
			$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
			if ( '' === $slug || '' === $label ) {
				continue;
			}
			$out[] = array(
				'slug' => $slug,
				'label' => $label,
				'type' => $type,
			);
		}
		return $out;
	}

	/**
	 * Generate a PDF file for a given Document post.
	 *
	 * Which route it takes is the site's choice. Under the native engine the
	 * PDF is drawn here, out of the HTML layout the document type names, so it
	 * needs neither an office template nor a conversion service. Under either
	 * converter the old route stays: render the office template and hand the
	 * result to Documentate_Conversion_Manager.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_pdf( $post_id ) {
		try {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';

			if ( Documentate_Conversion_Manager::ENGINE_FPDF === Documentate_Conversion_Manager::get_engine() ) {
				require_once plugin_dir_path( __DIR__ ) . 'includes/pdf/class-documentate-pdf-generator.php';

				return Documentate_Pdf_Generator::generate( $post_id );
			}

			return self::convert_to_pdf( $post_id );
		} catch ( \Throwable $e ) {
			// The export handlers and the AJAX endpoint write their answer into
			// a download or a JSON body, so nothing may escape as a fatal.
			return new WP_Error( 'documentate_pdf_error', $e->getMessage() );
		}
	}

	/**
	 * Produce the PDF by converting the rendered office document.
	 *
	 * The route a site keeps while it stays on Collabora Online or on the
	 * in-browser LibreOffice WASM converter. Throwables are left to
	 * generate_pdf(), which is the only caller.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	private static function convert_to_pdf( $post_id ) {
		$source = self::render_pdf_source( $post_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! Documentate_Conversion_Manager::is_available() ) {
			return new WP_Error(
				'documentate_conversion_not_available',
				Documentate_Conversion_Manager::get_unavailable_message(
					$source['format'],
					'pdf',
				)
			);
		}

		$target = self::build_output_path( $post_id, 'pdf' );
		Documentate_Private_Output::prepare( $target );

		$result = Documentate_Conversion_Manager::convert( $source['path'], $target, 'pdf', $source['format'] );
		if ( is_wp_error( $result ) ) {
			Documentate_Private_Output::discard( $target );
			return $result;
		}
		Documentate_Private_Output::prepare( $target );

		return $target;
	}

	/**
	 * Render the office document a conversion to PDF starts from.
	 *
	 * @param int $post_id Document post ID.
	 * @return array{path:string,format:string}|WP_Error The rendered source, or
	 *                                                   why none could be made.
	 */
	private static function render_pdf_source( $post_id ) {
		$odt_result = self::generate_odt( $post_id );
		if ( ! is_wp_error( $odt_result ) ) {
			return array(
				'path' => $odt_result,
				'format' => 'odt',
			);
		}

		$docx_result = self::generate_docx( $post_id );
		if ( ! is_wp_error( $docx_result ) ) {
			return array(
				'path' => $docx_result,
				'format' => 'docx',
			);
		}

		return new WP_Error(
			'documentate_pdf_source_missing',
			__(
				'Could not generate the base document because the document type does not have a DOCX or ODT template configured.',
				'documentate',
			),
			array(
				'odt' => $odt_result,
				'docx' => $docx_result,
			),
		);
	}

	/**
	 * Retrieve the template path associated with a document post for a format.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $format  Desired template format (docx|odt).
	 * @return string Template path or empty string when not available.
	 */
	public static function get_template_path( $post_id, $format ) {
		$format = sanitize_key( $format );
		if ( ! in_array( $format, array( 'docx', 'odt' ), true ) ) {
			return '';
		}

		$tpl_id = 0;
		$types = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_id = intval( $types[0] );
			$type_template = intval( get_term_meta( $type_id, 'documentate_type_template_id', true ) );
			$template_kind = sanitize_key( (string) get_term_meta( $type_id, 'documentate_type_template_type', true ) );
			if ( 0 < $type_template ) {
				if ( $template_kind === $format ) {
					$tpl_id = $type_template;
				} elseif ( '' === $template_kind ) {
					$path = get_attached_file( $type_template );
					if ( $path && strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === $format ) {
						$tpl_id = $type_template;
					}
				}
			}
			if ( 0 >= $tpl_id ) {
				$meta_key = 'documentate_type_' . $format . '_template';
				$tpl_id = intval( get_term_meta( $type_id, $meta_key, true ) );
			}
		}

		if ( 0 >= $tpl_id ) {
			return '';
		}

		$template_path = get_attached_file( $tpl_id );
		if ( ! $template_path || ! file_exists( $template_path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );
		if ( $format !== $ext ) {
			return '';
		}

		return $template_path;
	}

	/**
	 * Values detected as rich text (HTML) during merge preparation.
	 *
	 * @var array<int, string>
	 */
	private static $rich_field_values = array();

	/**
	 * Render a template using OpenTBS and return the generated document path.
	 *
	 * @param int    $post_id         Document post ID.
	 * @param string $template_path   Absolute template path.
	 * @param string $template_format Template format (docx|odt).
	 * @return string|WP_Error
	 */
	private static function render_with_template( $post_id, $template_path, $template_format ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-opentbs.php';

		$fields = self::build_merge_fields( $post_id );
		$rich_values = self::get_rich_field_values();
		$path = self::build_output_path( $post_id, $template_format );
		Documentate_Private_Output::prepare( $path );
		$metadata = Document_Meta::get( $post_id );

		if ( 'docx' === $template_format ) {
			$res = Documentate_OpenTBS::render_docx( $template_path, $fields, $path, $rich_values, $metadata );
		} else {
			$res = Documentate_OpenTBS::render_odt( $template_path, $fields, $path, $rich_values, $metadata );
		}

		if ( is_wp_error( $res ) ) {
			Documentate_Private_Output::discard( $path );
			return $res;
		}

		Documentate_Private_Output::prepare( $path );
		return $path;
	}

	/**
	 * Build the array of merge fields used by OpenTBS templates.
	 *
	 * @param int $post_id Document post ID.
	 * @return array
	 */
	public static function build_merge_fields( $post_id ) {
		self::reset_rich_field_values();

		$opts = get_option( 'documentate_settings', array() );
		$structured = self::load_structured_content( $post_id );

		// Apply case transformation to title based on schema attribute.
		$title = self::apply_case_transformation(
			get_post_field( 'post_title', $post_id, 'raw' ),
			self::get_title_case_from_schema( $post_id )
		);

		$fields = array(
			'title' => $title,
			'post_title' => $title, // Alias for templates using [post_title].
			'margen' => wp_strip_all_tags( isset( $opts['doc_margin_text'] ) ? $opts['doc_margin_text'] : '' ),
		);

		$type_id = self::get_document_type_id( $post_id );
		if ( null !== $type_id ) {
			self::add_schema_fields( $fields, $type_id, $structured, $post_id );
			self::add_logo_fields( $fields, $type_id );
		}

		// Replace [sign] placeholder with empty string so it doesn't appear in the output.
		// Signature position is determined by template parameters (x, y, page), not by text detection.
		$fields['sign'] = '';

		self::add_unmapped_structured_fields( $fields, $structured, $post_id );

		return $fields;
	}

	/**
	 * Build the rows the generic PDF layout prints, one per schema field.
	 *
	 * The generic layout is what a document type that names no layout of its
	 * own falls back to, so it carries no field names: it prints the schema as
	 * it finds it. Each row is a label and a value, and a value is either
	 * plain text or the HTML a rich field keeps, never both. A repeater
	 * becomes a table of its records.
	 *
	 * @param int $post_id Document post ID.
	 * @return array<int,array{label:string,text:string,html:string}>
	 */
	public static function build_generic_rows( $post_id ) {
		$type_id = self::get_document_type_id( $post_id );
		if ( null === $type_id ) {
			return array();
		}

		$schema = class_exists( 'Documentate_Documents' )
			? Documentate_Documents::get_term_schema( $type_id )
			: self::get_type_schema( $type_id );

		$structured = self::load_structured_content( $post_id );
		$rows = array();

		foreach ( $schema as $def ) {
			$slug = isset( $def['slug'] ) ? sanitize_key( $def['slug'] ) : '';

			// The title heads the layout, so it is not one of the rows below it.
			if ( '' === $slug || 'post_title' === $slug ) {
				continue;
			}

			$type = isset( $def['type'] ) ? sanitize_key( $def['type'] ) : 'textarea';

			$rows[] = ( 'array' === $type )
				? self::generic_repeater_row( $def, $slug, $structured, $post_id )
				: self::generic_scalar_row( $def, $slug, $type, $structured, $post_id );
		}

		return $rows;
	}

	/**
	 * The generic-layout row of a scalar field.
	 *
	 * @param array  $def        Schema field definition.
	 * @param string $slug       Sanitized field slug.
	 * @param string $type       Declared control type.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return array{label:string,text:string,html:string}
	 */
	private static function generic_scalar_row( $def, $slug, $type, array $structured, $post_id ) {
		$resolved = self::resolve_scalar_field_value( $def, $slug, $type, $structured, $post_id );

		return Documentate_Pdf_Generic_Rows::scalar(
			self::generic_row_label( $def, $slug ),
			$resolved['value'],
			self::is_rich_type( $resolved['type'] ) || $resolved['has_html'],
		);
	}

	/**
	 * The generic-layout row of a repeater, whose records make a table.
	 *
	 * @param array  $def        Schema field definition.
	 * @param string $slug       Sanitized field slug.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return array{label:string,text:string,html:string}
	 */
	private static function generic_repeater_row( $def, $slug, array $structured, $post_id ) {
		$item_schema = isset( $def['item_schema'] ) && is_array( $def['item_schema'] ) ? $def['item_schema'] : array();

		return Documentate_Pdf_Generic_Rows::repeater(
			self::generic_row_label( $def, $slug ),
			$item_schema,
			self::get_array_field_items_for_merge( $structured, $slug, $post_id ),
		);
	}

	/**
	 * Label a generic-layout row is introduced by.
	 *
	 * @param array  $def  Schema field definition.
	 * @param string $slug Sanitized field slug.
	 * @return string
	 */
	private static function generic_row_label( $def, $slug ) {
		$label = isset( $def['label'] ) && is_string( $def['label'] ) ? trim( $def['label'] ) : '';

		return ( '' === $label ) ? $slug : $label;
	}

	/**
	 * Parse the structured field values stored in the document content.
	 *
	 * @param int $post_id Document post ID.
	 * @return array
	 */
	private static function load_structured_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! class_exists( 'Documentate_Documents' ) ) {
			return array();
		}

		$content = get_post_field( 'post_content', $post_id, 'raw' );
		if ( ! is_string( $content ) || '' === $content ) {
			$content = $post->post_content;
		}

		return Documentate_Documents::parse_structured_content( (string) $content );
	}

	/**
	 * Resolve the document type term assigned to a document.
	 *
	 * @param int $post_id Document post ID.
	 * @return int|null Term ID, or null when the document has no type.
	 */
	public static function get_document_type_id( $post_id ) {
		$types = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $types ) || empty( $types ) ) {
			return null;
		}

		return intval( $types[0] );
	}

	/**
	 * Add one merge field per schema definition of the document type.
	 *
	 * @param array $fields     Merge fields to extend.
	 * @param int   $type_id    Document type term ID.
	 * @param array $structured Parsed structured content.
	 * @param int   $post_id    Document post ID.
	 * @return void
	 */
	private static function add_schema_fields( array &$fields, $type_id, array $structured, $post_id ) {
		$schema = class_exists( 'Documentate_Documents' )
			? Documentate_Documents::get_term_schema( $type_id )
			: self::get_type_schema( $type_id );

		foreach ( $schema as $def ) {
			if ( empty( $def['slug'] ) ) {
				continue;
			}

			$slug = sanitize_key( $def['slug'] );

			// Skip post_title - it's already set from get_the_title() above.
			if ( 'post_title' === $slug ) {
				continue;
			}

			$names = self::resolve_field_names( $def, $slug );
			$type = isset( $def['type'] ) ? sanitize_key( $def['type'] ) : 'textarea';

			if ( 'array' === $type ) {
				self::add_array_schema_field( $fields, $def, $slug, $names, $structured, $post_id );
				continue;
			}

			self::add_scalar_schema_field( $fields, $def, $slug, $names, $type, $structured, $post_id );
		}
	}

	/**
	 * Resolve the merge name and legacy alias for a schema field.
	 *
	 * @param array  $def  Schema field definition.
	 * @param string $slug Sanitized field slug.
	 * @return array{tbs:string,alias:string}
	 */
	private static function resolve_field_names( $def, $slug ) {
		// Prefer the original template name for TinyButStrong merges when available.
		$tbs_name = '';
		if ( isset( $def['name'] ) && is_string( $def['name'] ) ) {
			$tbs_name = self::sanitize_placeholder_name( $def['name'] );
		}
		if ( '' === $tbs_name ) {
			$tbs_name = self::sanitize_placeholder_name( $slug );
		}

		// Keep the legacy key used by UI (placeholder or slug) as alias for backward compatibility.
		$alias_key = '';
		if ( isset( $def['placeholder'] ) && is_string( $def['placeholder'] ) ) {
			$alias_key = self::sanitize_placeholder_name( $def['placeholder'] );
		}
		if ( '' === $alias_key ) {
			$alias_key = self::sanitize_placeholder_name( $slug );
		}

		return array(
			'tbs' => $tbs_name,
			'alias' => $alias_key,
		);
	}

	/**
	 * Store a merge value under its merge name and its legacy alias.
	 *
	 * @param array $fields Merge fields to extend.
	 * @param array $names  Merge name and alias.
	 * @param mixed $value  Value to store.
	 * @return void
	 */
	private static function assign_field_value( array &$fields, array $names, $value ) {
		$fields[ $names['tbs'] ] = $value;
		if ( $names['alias'] !== $names['tbs'] ) {
			$fields[ $names['alias'] ] = $value;
		}
	}

	/**
	 * Add a repeater schema field as a merge block.
	 *
	 * @param array  $fields     Merge fields to extend.
	 * @param array  $def        Schema field definition.
	 * @param string $slug       Sanitized field slug.
	 * @param array  $names      Merge name and alias.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return void
	 */
	private static function add_array_schema_field( array &$fields, $def, $slug, array $names, array $structured, $post_id ) {
		$items = self::get_array_field_items_for_merge( $structured, $slug, $post_id );

		// Apply case transformations to repeater items.
		$item_schema = isset( $def['item_schema'] ) ? $def['item_schema'] : array();
		$items = self::apply_case_to_array_items( $items, $item_schema );

		// Use block name for MergeBlock, with alias for legacy behavior.
		self::assign_field_value( $fields, $names, $items );
		self::remember_rich_values_from_array_items( $items );
	}

	/**
	 * Add a scalar schema field, normalising its type and case.
	 *
	 * @param array  $fields     Merge fields to extend.
	 * @param array  $def        Schema field definition.
	 * @param string $slug       Sanitized field slug.
	 * @param array  $names      Merge name and alias.
	 * @param string $type       Declared control type.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return void
	 */
	private static function add_scalar_schema_field( array &$fields, $def, $slug, array $names, $type, array $structured, $post_id ) {
		$resolved = self::resolve_scalar_field_value( $def, $slug, $type, $structured, $post_id );

		self::assign_field_value( $fields, $names, $resolved['value'] );

		// Register for rich text conversion if typed as rich/html.
		// Also check if prepared value still contains HTML as a safety net.
		if ( self::is_rich_type( $resolved['type'] ) || $resolved['has_html'] ) {
			self::remember_rich_field_value( $resolved['value'] );
		}

		self::log_merge_field( $slug, $type, $resolved['type'], $resolved['raw'], $resolved['value'], $resolved['has_html'] );
	}

	/**
	 * Resolve the value a scalar schema field is merged with.
	 *
	 * Both output paths read a field through here, so what an ODT prints and
	 * what a PDF prints are the same string: same type promotion, same
	 * sanitization, same case transformation.
	 *
	 * @param array  $def        Schema field definition.
	 * @param string $slug       Sanitized field slug.
	 * @param string $type       Declared control type.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return array{raw:string,type:string,value:string,has_html:bool} Stored value,
	 *         the type it was actually treated as, the value to merge, and whether
	 *         that value still carries block HTML.
	 */
	private static function resolve_scalar_field_value( $def, $slug, $type, array $structured, $post_id ) {
		$data_type = isset( $def['data_type'] ) ? sanitize_key( $def['data_type'] ) : 'text';
		$value = self::get_structured_field_value( $structured, $slug, $post_id );

		// Force rich type if value contains block HTML, BEFORE prepare strips tags.
		if ( ! self::is_rich_type( $type ) && Documents_Meta_Handler::value_contains_block_html( $value ) ) {
			$type = 'rich';
		}

		$prepared = self::prepare_field_value( $value, $type, $data_type, $def );
		$prepared_has_html = Documents_Meta_Handler::value_contains_block_html( $prepared );

		// Apply case transformation if specified (skip for HTML content).
		$field_case = isset( $def['case'] ) ? sanitize_key( $def['case'] ) : '';
		if ( '' !== $field_case && ! self::is_rich_type( $type ) && ! $prepared_has_html ) {
			$prepared = self::apply_case_transformation( $prepared, $field_case );
		}

		return array(
			'raw' => $value,
			'type' => $type,
			'value' => $prepared,
			'has_html' => $prepared_has_html,
		);
	}

	/**
	 * Whether a control type carries HTML that must survive merging.
	 *
	 * @param string $type Control type.
	 * @return bool
	 */
	private static function is_rich_type( $type ) {
		return in_array( $type, array( 'rich', 'html' ), true );
	}

	/**
	 * Log how a schema field was resolved, when debugging is enabled.
	 *
	 * @param string $slug              Sanitized field slug.
	 * @param string $original_type     Type declared by the schema.
	 * @param string $type              Type actually used.
	 * @param string $value             Raw field value.
	 * @param string $prepared          Prepared field value.
	 * @param bool   $prepared_has_html Whether the prepared value still holds HTML.
	 * @return void
	 */
	private static function log_merge_field( $slug, $original_type, $type, $value, $prepared, $prepared_has_html ) {
		// Debug logging - enable with WP_DEBUG.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$raw_has_html = Documents_Meta_Handler::value_contains_block_html( $value );

		error_log(
			sprintf(
				'DOCUMENTATE [%s]: schema_type=%s, effective_type=%s, raw_has_html=%s, prepared_has_html=%s, raw_len=%d, prep_len=%d',
				$slug,
				$original_type,
				$type,
				$raw_has_html ? 'YES' : 'NO',
				$prepared_has_html ? 'YES' : 'NO',
				strlen( $value ),
				strlen( $prepared ),
			)
		);

		if ( $raw_has_html && strlen( $value ) < 500 ) {
			error_log( 'DOCUMENTATE [' . $slug . '] RAW: ' . substr( $value, 0, 300 ) );
		}
	}

	/**
	 * Add the logo path and URL fields declared on the document type.
	 *
	 * @param array $fields  Merge fields to extend.
	 * @param int   $type_id Document type term ID.
	 * @return void
	 */
	private static function add_logo_fields( array &$fields, $type_id ) {
		$logos = get_term_meta( $type_id, 'documentate_type_logos', true );
		if ( ! is_array( $logos ) || empty( $logos ) ) {
			return;
		}

		$i = 1;
		foreach ( $logos as $att_id ) {
			$att_id = intval( $att_id );
			if ( $att_id <= 0 ) {
				continue;
			}
			$fields[ 'logo' . $i . '_path' ] = get_attached_file( $att_id );
			$fields[ 'logo' . $i . '_url' ] = wp_get_attachment_url( $att_id );
			$i++;
		}
	}

	/**
	 * Add stored values that the document type schema does not declare.
	 *
	 * Keeps templates working when a placeholder exists in the content but the
	 * schema has since dropped it.
	 *
	 * @param array $fields     Merge fields to extend.
	 * @param array $structured Parsed structured content.
	 * @param int   $post_id    Document post ID.
	 * @return void
	 */
	private static function add_unmapped_structured_fields( array &$fields, array $structured, $post_id ) {
		foreach ( $structured as $slug => $info ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug ) {
				continue;
			}

			if ( isset( $fields[ $slug ] ) && '' !== $fields[ $slug ] ) {
				continue;
			}

			if ( isset( $info['type'] ) && 'array' === sanitize_key( $info['type'] ) ) {
				$items = self::get_array_field_items_for_merge( $structured, $slug, $post_id );
				$fields[ $slug ] = $items;
				self::remember_rich_values_from_array_items( $items );
				continue;
			}

			self::add_unmapped_scalar_field( $fields, $info, $slug, $structured, $post_id );
		}
	}

	/**
	 * Add a single scalar value that the schema does not declare.
	 *
	 * @param array  $fields     Merge fields to extend.
	 * @param array  $info       Stored field record.
	 * @param string $slug       Sanitized field slug.
	 * @param array  $structured Parsed structured content.
	 * @param int    $post_id    Document post ID.
	 * @return void
	 */
	private static function add_unmapped_scalar_field( array &$fields, $info, $slug, array $structured, $post_id ) {
		$value = isset( $info['value'] ) ? (string) $info['value'] : '';
		if ( '' === $value ) {
			$value = self::get_structured_field_value( $structured, $slug, $post_id );
		}

		$field_type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : 'rich';

		// Force rich type if value contains block HTML, BEFORE prepare strips tags.
		if ( ! self::is_rich_type( $field_type ) && Documents_Meta_Handler::value_contains_block_html( $value ) ) {
			$field_type = 'rich';
		}

		$fields[ $slug ] = self::prepare_field_value( $value, $field_type, 'text' );

		// Register for rich text conversion if typed as rich/html.
		// Also check if prepared value still contains HTML as a safety net.
		if ( self::is_rich_type( $field_type ) || Documents_Meta_Handler::value_contains_block_html( $fields[ $slug ] ) ) {
			self::remember_rich_field_value( $fields[ $slug ] );
		}
	}

	/**
	 * Reset tracked rich text field values.
	 */
	private static function reset_rich_field_values() {
		self::$rich_field_values = array();
	}

	/**
	 * Remember that a value contains HTML formatting for later conversions.
	 *
	 * @param string $value Field value.
	 */
	private static function remember_rich_field_value( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return;
		}
		if ( false === strpos( $value, '<' ) || false === strpos( $value, '>' ) ) {
			return;
		}
		self::$rich_field_values[ md5( $value ) ] = $value;
	}

	/**
	 * Remember rich values coming from array field items.
	 *
	 * @param array<int, array<string, string>> $items Array field items.
	 */
	private static function remember_rich_values_from_array_items( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item as $value ) {
				if ( is_string( $value ) ) {
					self::remember_rich_field_value( $value );
				} elseif ( is_array( $value ) ) {
					// Nested sub-block rows.
					self::remember_rich_values_from_array_items( $value );
				}
			}
		}
	}

	/**
	 * Get the list of rich text values detected during merge preparation.
	 *
	 * @return array<int, string>
	 */
	private static function get_rich_field_values() {
		if ( empty( self::$rich_field_values ) ) {
			return array();
		}
		return array_values( self::$rich_field_values );
	}

	/**
	 * Get a field value from structured content with dynamic meta fallback.
	 *
	 * @param array  $structured Structured field map.
	 * @param string $slug       Field slug.
	 * @param int    $post_id    Post ID.
	 * @return string
	 */
	private static function get_structured_field_value( $structured, $slug, $post_id ) {
		$slug = sanitize_key( $slug );
		if ( '' !== $slug && isset( $structured[ $slug ] ) && is_array( $structured[ $slug ] ) ) {
			$value = isset( $structured[ $slug ]['value'] ) ? (string) $structured[ $slug ]['value'] : '';
			if ( '' !== $value ) {
				return $value;
			}
		}

		if ( '' !== $slug ) {
			$meta_key = 'documentate_field_' . $slug;
			$legacy = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $legacy ) {
				return (string) $legacy;
			}
		}

		return '';
	}

	/**
	 * Retrieve array field items for template merges.
	 *
	 * @param array  $structured Structured map from post content.
	 * @param string $slug       Field slug.
	 * @param int    $post_id    Post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function get_array_field_items_for_merge( $structured, $slug, $post_id ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return array();
		}

		$items = array();

		if ( isset( $structured[ $slug ] ) && is_array( $structured[ $slug ] ) && isset( $structured[ $slug ]['value'] ) ) {
			$items = Documentate_Documents::decode_array_field_value( (string) $structured[ $slug ]['value'] );
		}

		if ( empty( $items ) ) {
			$meta_value = get_post_meta( $post_id, 'documentate_field_' . $slug, true );
			if ( '' !== $meta_value ) {
				$items = Documentate_Documents::decode_array_field_value( (string) $meta_value );
			}
		}

		if ( empty( $items ) ) {
			$legacy = get_post_meta( $post_id, 'documentate_' . $slug, true );
			if ( empty( $legacy ) && 'annexes' === $slug ) {
				$legacy = get_post_meta( $post_id, 'documentate_annexes', true );
			}
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$items = Documentate_Documents::decode_array_field_value( wp_json_encode( $legacy, JSON_UNESCAPED_UNICODE ) );
			}
		}

		return $items;
	}

	/**
	 * Build (and ensure) the target path for a generated document.
	 *
	 * @param int    $post_id   Document post ID.
	 * @param string $extension File extension (docx|odt|pdf).
	 * @return string
	 */
	public static function build_output_path( $post_id, $extension ) {
		$extension = sanitize_key( $extension );
		$dir = self::ensure_output_dir();
		$filename = sanitize_title( get_the_title( $post_id ) ) . '-' . $post_id . '.' . $extension;

		return trailingslashit( $dir ) . $filename;
	}

	/**
	 * Ensure the plugin output directory exists within uploads.
	 *
	 * @return string Absolute directory path.
	 */
	public static function ensure_output_dir() {
		require_once __DIR__ . '/class-documentate-private-output.php';
		return Documentate_Private_Output::directory();
	}

	/**
	 * Keep the generated documents out of reach of a plain HTTP request.
	 *
	 * Every download and every preview is streamed by admin-post.php after a
	 * capability check, so nothing ever links to these files: the file name
	 * ("<título>-<id>.pdf") is guessable and the uploads folder is served by
	 * the web server, which is the only way in. Apache is told to refuse it;
	 * on a server that ignores .htaccess the deny file is harmless and the
	 * index stops the directory from being listed.
	 *
	 * @param string $dir Absolute output directory path.
	 * @return void
	 */
	private static function protect_output_dir( $dir ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return;
		}

		$guards = array(
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.html' => '',
		);

		foreach ( $guards as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Sanitize placeholders preserving TinyButStrong supported characters.
	 *
	 * @param string $placeholder Placeholder name.
	 * @return string
	 */
	private static function sanitize_placeholder_name( $placeholder ) {
		$placeholder = (string) $placeholder;
		$placeholder = preg_replace( '/[^A-Za-z0-9._:-]/', '', $placeholder );
		return $placeholder;
	}

	/**
	 * Prepare a field value for merging according to the configured field type.
	 *
	 * @param string $value      Raw value retrieved from storage.
	 * @param string $field_type Field UI type (single|textarea|rich|array).
	 * @param string $data_type  Data type detected for the placeholder.
	 * @param array  $field_def  Field definition with parameters (optional).
	 * @return mixed
	 */
	private static function prepare_field_value( $value, $field_type, $data_type, $field_def = array() ) {
		$field_type = sanitize_key( $field_type );
		$value = is_string( $value ) ? $value : '';

		if ( in_array( $field_type, array( 'rich', 'html' ), true ) ) {
			// Apply sanitization and cleanup only at generation time.
			$sanitized = wp_kses_post( $value );
			$sanitized = self::strip_unsupported_html_tags( $sanitized );
			$sanitized = self::remove_linebreak_artifacts( $sanitized );
			return self::normalize_field_value( $sanitized, $data_type, $field_def );
		}

		if ( '' === $value ) {
			return self::normalize_field_value( '', $data_type, $field_def );
		}

		$value = wp_strip_all_tags( $value );

		return self::normalize_field_value( $value, $data_type, $field_def );
	}

	/**
	 * Convert plain textarea content with newlines into paragraph-aware HTML.
	 *
	 * Blank lines become new paragraphs and single newlines remain soft breaks.
	 *
	 * @param string $value Plain textarea value.
	 * @return string|null HTML fragment or null when no paragraph conversion is needed.
	 */
	private static function convert_plain_textarea_to_html( $value ) {
		$value = OpenTBS_HTML_Parser::normalize_text_newlines( (string) $value );
		$value = trim( $value );
		if ( '' === $value || ! str_contains( $value, "\n" ) ) {
			return null;
		}

		$paragraphs = preg_split( "/\n\s*\n+/u", $value );
		if ( ! is_array( $paragraphs ) || empty( $paragraphs ) ) {
			return null;
		}

		$html = array();
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( (string) $paragraph, "\n" );
			$lines = explode( "\n", $paragraph );
			$escaped_lines = array_map( 'esc_html', $lines );
			$html[] = '<p>' . implode( '<br>', $escaped_lines ) . '</p>';
		}

		return implode( '', $html );
	}

	/**
	 * Data type normalizer method map.
	 *
	 * @var array<string, string>
	 */
	private static $type_normalizers = array(
		'number' => 'normalize_number_value',
		'boolean' => 'normalize_boolean_value',
		'date' => 'normalize_date_value',
	);

	/**
	 * Normalize a field value based on the detected data type.
	 *
	 * @param string $value     Original value.
	 * @param string $data_type Detected data type.
	 * @param array  $field_def Field definition with parameters (optional).
	 * @return mixed
	 */
	private static function normalize_field_value( $value, $data_type, $field_def = array() ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		$data_type = sanitize_key( $data_type );

		if ( 'date' === $data_type ) {
			$format = isset( $field_def['parameters']['format'] ) ? $field_def['parameters']['format'] : 'd/m/Y';
			return self::normalize_date_value( $value, $format );
		}

		if ( isset( self::$type_normalizers[ $data_type ] ) ) {
			return call_user_func( array( __CLASS__, self::$type_normalizers[ $data_type ] ), $value );
		}

		return $value;
	}

	/**
	 * Normalize a number value.
	 *
	 * @param mixed $value Original value.
	 * @return mixed Normalized number or original value.
	 */
	private static function normalize_number_value( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( is_numeric( $value ) ) {
			return 0 + $value;
		}
		$filtered = preg_replace( '/[^0-9.,\-]/', '', (string) $value );
		if ( '' === $filtered ) {
			return '';
		}
		$normalized = str_replace( ',', '.', $filtered );
		if ( is_numeric( $normalized ) ) {
			return 0 + $normalized;
		}
		return $value;
	}

	/**
	 * Normalize a boolean value.
	 *
	 * @param mixed $value Original value.
	 * @return int 1 for truthy, 0 for falsy.
	 */
	private static function normalize_boolean_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		$value = strtolower( (string) $value );
		if ( in_array( $value, array( '1', 'true', 'si', 'sí', 'yes', 'on' ), true ) ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Normalize a date value.
	 *
	 * @param mixed  $value  Original value.
	 * @param string $format PHP date format string (default 'd/m/Y').
	 * @return string Formatted date or original value.
	 */
	private static function normalize_date_value( $value, $format = 'd/m/Y' ) {
		if ( '' === $value ) {
			return '';
		}

		$date = self::parse_date_value( (string) $value );
		if ( ! $date ) {
			return $value;
		}

		// Return ISO format (Y-m-d) so TBS can unambiguously parse dates
		// and apply its own frm='mmmm (locale)' etc. formatting correctly.
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Parse a date string into a DateTime object.
	 *
	 * Tries common input formats using strict parsing to avoid DD/MM vs MM/DD
	 * ambiguity inherent in PHP's strtotime().
	 *
	 * @param string $value Date string to parse.
	 * @return DateTime|false DateTime object or false on failure.
	 */
	private static function parse_date_value( $value ) {
		// Try strict parsing with common input formats.
		$input_formats = array( 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'Y/m/d' );
		foreach ( $input_formats as $input_format ) {
			$date = DateTime::createFromFormat( $input_format, $value );
			if ( $date && $date->format( $input_format ) === $value ) {
				return $date;
			}
		}

		// Fallback: replace "/" with "-" so strtotime treats it as D-M-Y instead of M/D/Y.
		$safe_value = str_replace( '/', '-', $value );
		$timestamp = strtotime( $safe_value );
		if ( false === $timestamp ) {
			return false;
		}
		$date = new DateTime();
		$date->setTimestamp( $timestamp );
		return $date;
	}

	/**
	 * Apply case transformation to a string value.
	 *
	 * @param string $value String to transform.
	 * @param string $case  Case type: 'upper', 'lower', 'title', or empty for no change.
	 * @return string Transformed string.
	 */
	private static function apply_case_transformation( $value, $case ) {
		if ( ! is_string( $value ) || '' === $value || '' === $case ) {
			return (string) $value;
		}
		$case = strtolower( trim( $case ) );
		switch ( $case ) {
			case 'upper':
				return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
			case 'lower':
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
			case 'title':
				return function_exists( 'mb_convert_case' )
					? mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' )
					: ucwords( strtolower( $value ) );
		}
		return $value;
	}

	/**
	 * Get the case attribute for the title field from the document type schema.
	 *
	 * @param int $post_id Document post ID.
	 * @return string Case value ('upper', 'lower', 'title') or empty string.
	 */
	private static function get_title_case_from_schema( $post_id ) {
		$types = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $types ) || empty( $types ) ) {
			return '';
		}
		$type_id = intval( $types[0] );
		$schema = class_exists( 'Documentate_Documents' )
			? Documentate_Documents::get_term_schema( $type_id )
			: self::get_type_schema( $type_id );
		foreach ( $schema as $def ) {
			$slug = isset( $def['slug'] ) ? sanitize_key( $def['slug'] ) : '';
			if ( in_array( $slug, array( 'title', 'post_title' ), true ) ) {
				return isset( $def['case'] ) ? sanitize_key( $def['case'] ) : '';
			}
		}
		return '';
	}

	/**
	 * Apply case transformations to array field items based on item schema.
	 *
	 * @param array $items       Array field items.
	 * @param array $item_schema Item field schema with 'case' attributes.
	 * @return array Transformed items.
	 */
	private static function apply_case_to_array_items( $items, $item_schema ) {
		if ( empty( $items ) || ! is_array( $items ) || empty( $item_schema ) ) {
			return $items;
		}

		foreach ( $items as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item as $key => $value ) {
				if ( is_array( $value ) && isset( $item_schema[ $key ]['item_schema'] ) ) {
					// Nested sub-block rows use their own item schema.
					$items[ $idx ][ $key ] = self::apply_case_to_array_items( $value, $item_schema[ $key ]['item_schema'] );
					continue;
				}
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				if ( isset( $item_schema[ $key ]['case'] ) ) {
					$field_case = sanitize_key( $item_schema[ $key ]['case'] );
					$field_type = isset( $item_schema[ $key ]['type'] ) ? $item_schema[ $key ]['type'] : '';
					if ( ! in_array( $field_type, array( 'rich', 'html' ), true ) ) {
						$items[ $idx ][ $key ] = self::apply_case_transformation( $value, $field_case );
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Strip unsupported HTML tags and attributes from content before document generation.
	 *
	 * Removes tags that are not properly supported by OpenTBS/ODT/DOCX conversion.
	 * Also removes id and class attributes which are not supported.
	 * Inline styles (style attribute) are kept as they are supported.
	 *
	 * @param string $value HTML content.
	 * @return string Content with unsupported tags and attributes removed.
	 */
	private static function strip_unsupported_html_tags( $value ) {
		$value = is_string( $value ) ? $value : '';
		if ( '' === $value ) {
			return '';
		}

		// List of unsupported tags to remove (keeping their inner content).
		$unsupported_tags = array(
			'span',
			'button',
			'form',
			'select',
			'input',
			'textarea',
			'div',
			'iframe',
			'embed',
			'object',
			'label',
			'font',
			'img',
			'video',
			'audio',
			'canvas',
			'svg',
			'script',
			'style',
			'noscript',
			'map',
			'area',
			'applet',
		);

		foreach ( $unsupported_tags as $tag ) {
			// Remove opening tags (with or without attributes).
			$value = preg_replace( '#<' . $tag . '\b[^>]*>#i', '', $value );
			if ( ! is_string( $value ) ) {
				$value = '';
			}
			// Remove closing tags.
			$value = preg_replace( '#</' . $tag . '>#i', '', $value );
			if ( ! is_string( $value ) ) {
				$value = '';
			}
		}

		// Remove id and class attributes (not supported by OpenTBS).
		$value = preg_replace( '/\s+id\s*=\s*["\'][^"\']*["\']/i', '', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		$value = preg_replace( '/\s+class\s*=\s*["\'][^"\']*["\']/i', '', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		return $value;
	}

	/**
	 * Remove newline artifacts from HTML content for document generation.
	 *
	 * Cleans up stray newline markers and empty paragraphs that can interfere
	 * with proper document formatting.
	 *
	 * @param string $value Sanitized HTML.
	 * @return string
	 */
	private static function remove_linebreak_artifacts( $value ) {
		$value = (string) $value;

		// 1) Remove paragraphs that contain stray literal newline markers (n or rn).
		// Only removes if at least one marker is present - preserves intentional <p>&nbsp;</p> spacing.
		// NOTE: Do NOT use case-insensitive flag to avoid matching "N" in words like "Numbered".
		$value = preg_replace( '#<p(?:[^>]*)>(?:\s)*(?:rn|n)+(?:\s)*</p>#', '', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 2) Remove standalone markers between any two tags: >  n  <  => ><.
		$value = preg_replace( '#>(?:\s|&nbsp;)*(?:rn|n)+(?:\s|&nbsp;)*<#', '><', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 3) Remove markers right after opening block/list/table tags.
		$value = preg_replace(
			'#(<(?:ul|ol|table|thead|tbody|tfoot|tr|td|th|li)[^>]*>)(?:\s|&nbsp;)*(?:rn|n)+#',
			'$1',
			$value,
		);
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 4) Remove markers right before closing block/list/table tags.
		$value = preg_replace(
			'#(?:\s|&nbsp;)*(?:rn|n)+(?:\s|&nbsp;)*(</(?:ul|ol|table|thead|tbody|tfoot|tr|td|th|li)>)#',
			'$1',
			$value,
		);
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		return $value;
	}
}
