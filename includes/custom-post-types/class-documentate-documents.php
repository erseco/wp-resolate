<?php
/**
 * The file that defines the Documents custom post type for Documentate.
 *
 * This CPT is the base for generating official documents with structured
 * sections stored as post meta and a document type taxonomy that defines
 * the available template fields.
 *
 * @link       https://github.com/ateeducacion/wp-documentate
 * @since      0.1.0
 *
 * @package    documentate
 * @subpackage Documentate/includes/custom-post-types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

use Documentate\Documents\Documents_Comments_Handler;
use Documentate\Documents\Documents_CPT_Registration;
use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;
use Documentate\Documents\Documents_Revision_Handler;

/**
 * Class to handle the Documentate Documents custom post type.
 *
 * This class now delegates to specialized classes for better separation of concerns:
 * - Documents_CPT_Registration: CPT and taxonomy registration
 * - Documents_Revision_Handler: Revision management
 * - Documents_Meta_Handler: Meta field utilities
 */
class Documentate_Documents {
	/**
	 * CPT registration handler.
	 *
	 * @var Documents_CPT_Registration
	 */
	private $cpt_registration;

	/**
	 * Revision handler.
	 *
	 * @var Documents_Revision_Handler
	 */
	private $revision_handler;

	/**
	 * Maximum number of items allowed per array field.
	 */
	/**
	 * Decode a stored repeater value into its rows.
	 *
	 * Kept here as the published entry point; the implementation lives with the
	 * rest of the field-value reading in Documents_Meta_Handler.
	 *
	 * @param string $value Stored JSON value.
	 * @return array
	 */
	public static function decode_array_field_value( $value ) {
		return Documents_Meta_Handler::decode_array_field_value( $value );
	}

	/**
	 * Editor metabox renderer.
	 *
	 * @var Documentate_Document_Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Builds the filters, columns and views on the documents list table.
	 *
	 * @var Documentate_Document_Admin_List
	 */
	private $admin_list;

	/**
	 * Writes submitted document fields to meta and composes post_content.
	 *
	 * @var Documentate_Document_Meta_Saver
	 */
	private $meta_saver;

	/**
	 * Small editor additions unrelated to the schema: nombre interno,
	 * anotaciones internas, actividad.
	 *
	 * @var Documentate_Document_Admin_Extras
	 */
	private $admin_extras;

	/**
	 * Hard cap on how many rows a repeater stores.
	 *
	 * @var int
	 */
	const ARRAY_FIELD_MAX_ITEMS = Documents_Meta_Handler::ARRAY_FIELD_MAX_ITEMS;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->admin_list = new Documentate_Document_Admin_List();
		$this->admin_list->register_hooks();
		$this->meta_saver = new Documentate_Document_Meta_Saver();
		$this->meta_boxes = new Documentate_Document_Meta_Boxes();
		$this->admin_extras = new Documentate_Document_Admin_Extras();
		$this->admin_extras->register_hooks();
		$this->cpt_registration = new Documents_CPT_Registration();
		$this->revision_handler = new Documents_Revision_Handler();
		( new Documents_Comments_Handler() )->register_hooks();
		$this->define_hooks();
	}

	/**
	 * Define hooks.
	 *
	 * Note: Hooks are registered with $this for backwards compatibility,
	 * but delegate to specialized handler classes internally.
	 */
	private function define_hooks() {
		// CPT/taxonomy registration - keep hooks on $this for backwards compatibility.
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_filter( 'get_default_comment_status', array( $this, 'set_default_comment_status_open' ), 10, 3 );

		// Meta boxes.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_documentate_document', array( $this, 'save_meta_boxes' ) );

		// Title placeholder.
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		// Revision handling - keep hooks on $this for backwards compatibility.
		add_action( 'wp_save_post_revision', array( $this, 'copy_meta_to_revision' ), 10, 2 );
		add_action( 'wp_restore_post_revision', array( $this, 'restore_meta_from_revision' ), 10, 2 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions_for_cpt' ), 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'force_revision_on_meta' ), 10, 3 );

		// Compose Gutenberg-friendly content before saving to ensure revision UI diffs.

		/**
		 * Lock document type after the first assignment.
		 * Reapplies the original term if an attempt to change it is detected.
		 */
		// Compose Gutenberg-friendly content before saving so the revision UI can diff it.
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data_compose_content' ), 10, 2 );

		add_action( 'set_object_terms', array( $this, 'enforce_locked_doc_type' ), 10, 6 );

		// Admin list table filters and columns.
		// Suppress the native category dropdown so it does not duplicate our
		// custom category_name filter (both target the 'category' taxonomy).

		$this->register_revision_ui();
	}

	/**
	 * Enforce that a document's type cannot change after it is first set.
	 *
	 * @param int    $object_id  Object (post) ID.
	 * @param array  $terms      Term IDs or slugs being set.
	 * @param array  $tt_ids     Term taxonomy IDs being set.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms are being appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public function enforce_locked_doc_type( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $tt_ids, $append );
		$taxonomy = (string) $taxonomy;
		if ( 'documentate_doc_type' !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || 'documentate_document' !== $post->post_type ) {
			return;
		}

		static $lock_guard = false;
		if ( $lock_guard ) {
			return;
		}

		$locked = intval( get_post_meta( $object_id, 'documentate_locked_doc_type', true ) );

		// If not yet locked, lock to the current assigned term (if any) on first set.
		if ( $locked <= 0 ) {
			$this->lock_doc_type_to_current( $object_id );
			return;
		}

		// Already locked: ensure the post keeps the locked term.
		if ( ! $this->doc_type_drifted_from_lock( $object_id, $locked ) ) {
			return;
		}

		// If old assignment existed, or current differs, reapply the locked term.
		$lock_guard = true;
		wp_set_post_terms( $object_id, array( $locked ), 'documentate_doc_type', false );
		$lock_guard = false;
	}

	/**
	 * Record the currently assigned document type as the locked one.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	private function lock_doc_type_to_current( $object_id ) {
		$assigned = wp_get_post_terms( $object_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
			return;
		}

		update_post_meta( $object_id, 'documentate_locked_doc_type', intval( $assigned[0] ) );
	}

	/**
	 * Whether the assigned terms differ from the locked document type.
	 *
	 * @param int $object_id Post ID.
	 * @param int $locked    Locked term ID.
	 * @return bool False when the post already carries exactly the locked term, and
	 *              also when the terms cannot be read, so the caller leaves them be.
	 */
	private function doc_type_drifted_from_lock( $object_id, $locked ) {
		$current = wp_get_post_terms( $object_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $current ) ) {
			return false;
		}

		$current_one = ! empty( $current ) ? intval( $current[0] ) : 0;

		return ! ( $current_one === $locked && 1 === count( $current ) );
	}

	/**
	 * Return the list of custom meta keys used by this CPT for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function get_meta_fields_for_post( $post_id ) {
		return Documents_Meta_Handler::get_meta_fields_for_post( $post_id );
	}

	/**
	 * Copy custom meta to the newly created revision.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Revision post ID.
	 * @return void
	 */
	public function copy_meta_to_revision( $post_id, $revision_id ) {
		$this->revision_handler->copy_meta_to_revision( $post_id, $revision_id );
	}

	/**
	 * Restore custom meta when a revision is restored.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 * @return void
	 */
	public function restore_meta_from_revision( $post_id, $revision_id ) {
		$this->revision_handler->restore_meta_from_revision( $post_id, $revision_id );
	}

	/**
	 * Limit number of revisions for this CPT (optional).
	 *
	 * @param int     $num  Default number of revisions.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions_for_cpt( $num, $post ) {
		return $this->revision_handler->limit_revisions_for_cpt( $num, $post );
	}

	/**
	 * Force creating a revision on save even if core fields don't change.
	 *
	 * @param bool    $post_has_changed Default change detection.
	 * @param WP_Post $last_revision    Last revision object.
	 * @param WP_Post $post             Current post object.
	 * @return bool
	 */
	public function force_revision_on_meta( $post_has_changed, $last_revision, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return $this->revision_handler->force_revision_on_meta( $post_has_changed, $last_revision, $post );
	}

	/**
	 * Register revision UI fields and providers so the diff shows meta changes.
	 *
	 * Hook this in define_hooks().
	 */
	private function register_revision_ui() {
		add_filter( '_wp_post_revision_fields', array( $this, 'add_revision_fields' ), 10, 2 );
	}

	/**
	 * Add custom meta fields to the revisions UI.
	 *
	 * @param array   $fields Existing fields.
	 * @param WP_Post $post   Post being compared.
	 * @return array
	 */
	public function add_revision_fields( $fields, $post ) {
		return $this->revision_handler->add_revision_fields( $fields, $post );
	}

	/**
	 * Generic provider for WYSIWYG meta fields in revisions diff.
	 *
	 * @param string  $value     Current value (unused).
	 * @param WP_Post $revision  Revision post object.
	 * @return string
	 */
	public function revision_field_value( $value, $revision = null ) {
		return $this->revision_handler->revision_field_value( $value, $revision );
	}

	/**
	 * Normalize HTML to plain text to improve wp_text_diff visibility.
	 *
	 * @param string $html HTML input.
	 * @return string
	 */
	private function normalize_html_for_diff( $html ) {
		if ( '' === $html ) {
			return '';
		}
		// Decode entities, strip tags, collapse whitespace, keep line breaks sensibly.
		$text = wp_specialchars_decode( (string) $html );
		// Preserve basic block separations.
		$text = preg_replace( '/<(?:p|div|br|li|h[1-6])[^>]*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Register the Documents custom post type and attach core categories.
	 */
	public function register_post_type() {
		$this->cpt_registration->register_post_type();
	}

	/**
	 * Register taxonomies used by the documents CPT.
	 */
	public function register_taxonomies() {
		$this->cpt_registration->register_taxonomies();
	}

	/**
	 * Disable block editor for this CPT (use classic meta boxes).
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		return $this->cpt_registration->disable_gutenberg( $use_block_editor, $post_type );
	}

	/**
	 * Set default comment status to open for documentate_document.
	 *
	 * @param string $status       Default comment status ('open' or 'closed').
	 * @param string $post_type    Post type being queried.
	 * @param string $comment_type Comment type.
	 * @return string Modified default comment status.
	 */
	public function set_default_comment_status_open( $status, $post_type, $comment_type ) {
		return $this->cpt_registration->set_default_comment_status_open( $status, $post_type, $comment_type );
	}

	/**
	 * Set custom placeholder for the title field.
	 *
	 * @param string  $placeholder Default placeholder text.
	 * @param WP_Post $post        Current post object.
	 * @return string
	 */
	public function title_placeholder( $placeholder, $post ) {
		if ( 'documentate_document' === $post->post_type ) {
			return __( 'Enter document title', 'documentate' );
		}
		return $placeholder;
	}
	/**
	 * Evaluate truthy values commonly used in schema flags.
	 *
	 * @param mixed $value Value to evaluate.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		return Documents_Field_Validator::is_truthy( $value );
	}
	/**
	 * Map of field types to sanitization methods.
	 *
	 * @var array<string,string>
	 */
	private static $sanitizer_map = array(
		'single' => 'sanitize_text_field',
	);
	/**
	 * Normalize literal string newline escape sequences into actual line breaks.
	 *
	 * @param string $value Raw string value.
	 * @return string
	 */
	private function normalize_literal_line_endings( $value ) {
		$value = (string) $value;
		while ( false !== strpos( $value, '\\\\' ) ) {
			$normalized = str_replace( array( '\\r\\n', '\\n', '\\r' ), array( "\n", "\n", "\n" ), $value );
			if ( $normalized === $value ) {
				break;
			}
			$value = $normalized;
		}

		return $value;
	}

	/**
	 * Remove newline artifacts that survived sanitization.
	 *
	 * @param string $value Sanitized HTML.
	 * @return string
	 */
	private function remove_linebreak_artifacts( $value ) {
		$value = (string) $value;

		// 1) Remove paragraphs that only contain stray literal newline markers (n or rn) or whitespace.
		// NOTE: Do NOT use case-insensitive flag to avoid matching "N" in words like "Numbered".
		$value = preg_replace( '#<p(?:[^>]*)>(?:\s|&nbsp;)*(?:rn|n)*(?:\s|&nbsp;)*</p>#', '', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 2) Remove standalone markers between any two tags: >  n  <  => ><
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
	/**
	 * Register the editor metaboxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		$this->meta_boxes->register_meta_boxes();
	}

	/**
	 * Render the document type metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_type_metabox( $post ) {
		$this->meta_boxes->render_type_metabox( $post );
	}

	/**
	 * Persist the submitted editor fields.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_meta_boxes( $post_id ) {
		$this->meta_saver->save_meta_boxes( $post_id );
	}

	/**
	 * Compose the post_content that revisions diff against.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function filter_post_data_compose_content( $data, $postarr ) {
		return Documentate_Document_Content_Writer::filter_post_data_compose_content( $data, $postarr );
	}

	/**
	 * Render the dynamic sections metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_sections_metabox( $post ) {
		$this->meta_boxes->render_sections_metabox( $post );
	}
	/**
	 * Parse the structured post_content string into slug/value pairs.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function parse_structured_content( $content ) {
		return Documents_Meta_Handler::parse_structured_content( $content );
	}

	/**
	 * Parse attribute string from a structured field marker.
	 *
	 * @param string $attribute_string Raw attribute string.
	 * @return array<string,string>
	 */
	private static function parse_structured_field_attributes( $attribute_string ) {
		$result = array();
		$pattern = '/([a-zA-Z0-9_-]+)="([^"]*)"/';
		if ( preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key = strtolower( $match[1] );
				$result[ $key ] = $match[2];
			}
		}
		return $result;
	}
	/**
	 * Get sanitized schema array for a document type term.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	public static function get_term_schema( $term_id ) {
		return Documents_Meta_Handler::get_term_schema( $term_id );
	}
}

// Initialize.
new Documentate_Documents();
