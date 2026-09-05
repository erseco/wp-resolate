<?php
/**
 * Base test class for document generation tests.
 *
 * Provides helper methods for creating document types, documents,
 * generating output files, and extracting XML for assertions.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

/**
 * Class Documentate_Generation_Test_Base
 */
class Documentate_Generation_Test_Base extends Documentate_Test_Base {

	/**
	 * Temporary files to clean up after each test.
	 *
	 * @var array
	 */
	protected $temp_files = array();

	/**
	 * Admin user ID for tests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * XML Asserter helper instance.
	 *
	 * @var Document_Xml_Asserter
	 */
	protected $xml_asserter;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Register post type and taxonomy if not already registered.
		if ( ! post_type_exists( 'documentate_document' ) ) {
			register_post_type( 'documentate_document', array( 'public' => false ) );
		}
		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
		}

		// Create admin user for permissions.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize XML asserter if available.
		if ( class_exists( 'Document_Xml_Asserter' ) ) {
			$this->xml_asserter = new Document_Xml_Asserter();
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		// Remove temporary files.
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		$this->temp_files = array();

		// Clear POST data.
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Import a template fixture and create a document type with its schema.
	 *
	 * @param string      $fixture_name Template filename in fixtures/ directory.
	 * @param string|null $type_name    Optional custom name for the doc type.
	 * @return array {
	 *     @type int    $term_id       Document type term ID.
	 *     @type int    $template_id   Attachment ID of the template.
	 *     @type string $template_path Full path to the template file.
	 *     @type array  $schema        Extracted schema from template.
	 * }
	 */
	protected function create_doc_type_with_template( $fixture_name, $type_name = null ) {
		// Build path to fixture file.
		$fixture_path = dirname( __DIR__ ) . '/fixtures/templates/' . $fixture_name;
		$this->assertFileExists( $fixture_path, "Fixture file '$fixture_name' should exist at $fixture_path." );

		// Copy fixture to WordPress uploads directory.
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$template_path = trailingslashit( $upload_dir['basedir'] ) . $fixture_name;

		// Copy file (overwrite if exists from previous test).
		copy( $fixture_path, $template_path );
		$this->assertFileExists( $template_path, "Template should be copied to uploads." );

		// Track for cleanup.
		$this->temp_files[] = $template_path;

		// Create WordPress attachment for the template.
		$filetype   = wp_check_filetype( basename( $template_path ), null );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => pathinfo( $fixture_name, PATHINFO_FILENAME ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$template_id = wp_insert_attachment( $attachment, $template_path );
		$this->assertGreaterThan( 0, $template_id, "Attachment should be created for '$fixture_name'." );

		// Determine template type from extension.
		$ext = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );

		// Create document type term.
		$type_name = $type_name ?: 'Test Type ' . uniqid();
		$term      = wp_insert_term( $type_name, 'documentate_doc_type' );
		$this->assertNotWPError( $term, 'Document type term should be created.' );
		$term_id = intval( $term['term_id'] );

		// Set template metadata.
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', $ext );

		// Extract and save schema.
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $template_path );
		$this->assertNotWPError( $schema, "Schema should be extracted from '$fixture_name'." );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		return array(
			'term_id'       => $term_id,
			'template_id'   => $template_id,
			'template_path' => $template_path,
			'schema'        => $schema,
		);
	}

	/**
	 * Create a document type from a template attachment already in the library.
	 *
	 * Mirrors create_doc_type_with_template(), but starts from an attachment
	 * the demo importer has already created, so a test can render one of the
	 * real templates shipped under fixtures/ instead of a test-only one. The
	 * file is left alone on tear down: it belongs to the media library and
	 * other tests import the very same attachment.
	 *
	 * @param int         $template_id Attachment ID of the template.
	 * @param string      $ext         Template format: odt or docx.
	 * @param string|null $type_name   Optional custom name for the doc type.
	 * @return array {
	 *     @type int    $term_id       Document type term ID.
	 *     @type int    $template_id   Attachment ID of the template.
	 *     @type string $template_path Full path to the template file.
	 *     @type array  $schema        Extracted schema from template.
	 * }
	 */
	protected function create_doc_type_with_attachment( $template_id, $ext, $type_name = null ) {
		$template_id = intval( $template_id );
		$this->assertGreaterThan( 0, $template_id, 'Template attachment should have been imported.' );

		$template_path = get_attached_file( $template_id );
		$this->assertIsString( $template_path, 'Template attachment should point at a file.' );
		$this->assertFileExists( $template_path, 'Template file should exist.' );

		// Create document type term.
		$type_name = $type_name ?: 'Test Type ' . uniqid();
		$term      = wp_insert_term( $type_name, 'documentate_doc_type' );
		$this->assertNotWPError( $term, 'Document type term should be created.' );
		$term_id = intval( $term['term_id'] );

		// Set template metadata.
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', sanitize_key( $ext ) );

		// Extract and save schema.
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $template_path );
		$this->assertNotWPError( $schema, 'Schema should be extracted from the attachment.' );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		return array(
			'term_id'       => $term_id,
			'template_id'   => $template_id,
			'template_path' => $template_path,
			'schema'        => $schema,
		);
	}

	/**
	 * Create a document with field data.
	 *
	 * @param int   $term_id   Document type term ID.
	 * @param array $fields    Scalar field data (slug => value).
	 * @param array $repeaters Array field data (slug => array of items).
	 * @return int Post ID of the created document.
	 */
	protected function create_document_with_data( $term_id, array $fields = array(), array $repeaters = array() ) {
		// Create the document post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Test Document ' . uniqid(),
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id, 'Document post should be created.' );

		// Assign document type.
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Save scalar fields directly to post meta (bypasses nonce check).
		foreach ( $fields as $slug => $value ) {
			$meta_key = 'documentate_field_' . $slug;
			update_post_meta( $post_id, $meta_key, $value );
		}

		// Save repeater fields to post meta as JSON.
		foreach ( $repeaters as $slug => $items ) {
			$meta_key   = 'documentate_field_' . $slug;
			$json_flags = JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
			$json_value = wp_json_encode( $items, $json_flags );
			update_post_meta( $post_id, $meta_key, $json_value );
		}

		// Set POST data for content composition.
		$_POST['documentate_doc_type'] = (string) $term_id;
		foreach ( $fields as $slug => $value ) {
			$_POST[ 'documentate_field_' . $slug ] = wp_slash( $value );
		}
		if ( ! empty( $repeaters ) ) {
			$_POST['tpl_fields'] = wp_slash( $repeaters );
		}

		// Trigger content composition for post_content.
		$doc     = new Documentate_Documents();
		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Update post with composed content.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $result['post_content'],
			)
		);

		// Clear POST data.
		$_POST = array();

		return $post_id;
	}

	/**
	 * Generate a document and track for cleanup.
	 *
	 * @param int    $post_id Post ID of the document.
	 * @param string $format  Output format: 'odt', 'docx', or 'pdf'.
	 * @return string|WP_Error Path to the generated file or WP_Error on failure.
	 */
	protected function generate_document( $post_id, $format = 'odt' ) {
		$path = match ( $format ) {
			'docx' => Documentate_Document_Generator::generate_docx( $post_id ),
			'pdf'  => Documentate_Document_Generator::generate_pdf( $post_id ),
			default => Documentate_Document_Generator::generate_odt( $post_id ),
		};

		// Track file for cleanup if generation succeeded.
		if ( is_string( $path ) && file_exists( $path ) ) {
			$this->temp_files[] = $path;
		}

		return $path;
	}

	/**
	 * Extract XML content from a generated document.
	 *
	 * @param string      $doc_path Path to the document file.
	 * @param string|null $xml_file Optional XML file to extract. Defaults based on format.
	 * @return string|false XML content or false on failure.
	 */
	protected function extract_document_xml( $doc_path, $xml_file = null ) {
		$ext      = strtolower( pathinfo( $doc_path, PATHINFO_EXTENSION ) );
		$xml_file = $xml_file ?? ( 'docx' === $ext ? 'word/document.xml' : 'content.xml' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $doc_path ) ) {
			return false;
		}
		$xml = $zip->getFromName( $xml_file );
		$zip->close();

		return $xml;
	}

	/**
	 * Extract styles XML from a generated document.
	 *
	 * @param string $doc_path Path to the document file.
	 * @return string|false XML content or false on failure.
	 */
	protected function extract_styles_xml( $doc_path ) {
		$ext      = strtolower( pathinfo( $doc_path, PATHINFO_EXTENSION ) );
		$xml_file = 'docx' === $ext ? 'word/styles.xml' : 'styles.xml';

		return $this->extract_document_xml( $doc_path, $xml_file );
	}

	/**
	 * Extract numbering XML from a DOCX document.
	 *
	 * Numbering.xml defines list formats (bullet, decimal, roman, etc.) for DOCX files.
	 *
	 * @param string $doc_path Path to the DOCX file.
	 * @return string|false XML content or false on failure.
	 */
	protected function extract_numbering_xml( $doc_path ) {
		$ext = strtolower( pathinfo( $doc_path, PATHINFO_EXTENSION ) );
		if ( 'docx' !== $ext ) {
			return false;
		}

		return $this->extract_document_xml( $doc_path, 'word/numbering.xml' );
	}

	/**
	 * Extract relationships XML from a document.
	 *
	 * Relationships define hyperlink targets and other external references.
	 *
	 * @param string $doc_path Path to the document file.
	 * @return string|false XML content or false on failure.
	 */
	protected function extract_relationships_xml( $doc_path ) {
		$ext      = strtolower( pathinfo( $doc_path, PATHINFO_EXTENSION ) );
		$xml_file = 'docx' === $ext ? 'word/_rels/document.xml.rels' : 'META-INF/manifest.xml';

		return $this->extract_document_xml( $doc_path, $xml_file );
	}

	/**
	 * Assert that a document contains expected text.
	 *
	 * @param string $doc_path Path to the document file.
	 * @param string $expected Expected text to find.
	 * @param string $message  Optional assertion message.
	 */
	protected function assertDocumentContains( $doc_path, $expected, $message = '' ) {
		$xml = $this->extract_document_xml( $doc_path );
		$this->assertNotFalse( $xml, 'Document XML should be extractable.' );
		$this->assertStringContainsString( $expected, $xml, $message ?: "Document should contain: $expected" );
	}

	/**
	 * Assert that a document does not contain unexpected text.
	 *
	 * @param string $doc_path   Path to the document file.
	 * @param string $unexpected Text that should not be present.
	 * @param string $message    Optional assertion message.
	 */
	protected function assertDocumentNotContains( $doc_path, $unexpected, $message = '' ) {
		$xml = $this->extract_document_xml( $doc_path );
		$this->assertNotFalse( $xml, 'Document XML should be extractable.' );
		$this->assertStringNotContainsString( $unexpected, $xml, $message ?: "Document should not contain: $unexpected" );
	}

	/**
	 * Assert that no placeholder artifacts remain in the document.
	 *
	 * @param string $doc_path Path to the document file.
	 */
	protected function assertNoPlaceholderArtifacts( $doc_path ) {
		$xml = $this->extract_document_xml( $doc_path );
		$this->assertNotFalse( $xml, 'Document XML should be extractable.' );

		// Check for unresolved placeholders.
		$this->assertStringNotContainsString( '[onshow', $xml, 'No [onshow...] placeholders should remain.' );
		$this->assertStringNotContainsString( '[block', $xml, 'No [block...] placeholders should remain.' );

		// Check for "Array" artifact from unprocessed arrays.
		$this->assertStringNotContainsString( '>Array<', $xml, 'No "Array" literal should appear in document.' );
	}

	/**
	 * Assert that no raw HTML tags remain in the document XML.
	 *
	 * @param string $doc_path Path to the document file.
	 */
	protected function assertNoRawHtmlTags( $doc_path ) {
		$xml = $this->extract_document_xml( $doc_path );
		$this->assertNotFalse( $xml, 'Document XML should be extractable.' );

		$html_tags = array( '<strong>', '<em>', '<table>', '<tr>', '<td>', '<ul>', '<ol>', '<li>', '<br>', '<p>' );
		foreach ( $html_tags as $tag ) {
			$this->assertStringNotContainsString( $tag, $xml, "No raw HTML '$tag' should remain in document." );
		}
	}

	/**
	 * Create a DOMXPath instance for DOCX XML.
	 *
	 * @param string $xml XML content.
	 * @return DOMXPath XPath instance with namespaces registered.
	 */
	protected function createDocxXPath( $xml ) {
		$dom = new DOMDocument();
		$dom->loadXML( $xml );

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		$xpath->registerNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );

		return $xpath;
	}

	/**
	 * Create a DOMXPath instance for ODT XML.
	 *
	 * @param string $xml XML content.
	 * @return DOMXPath XPath instance with namespaces registered.
	 */
	protected function createOdtXPath( $xml ) {
		$dom = new DOMDocument();
		$dom->loadXML( $xml );

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );
		$xpath->registerNamespace( 'table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0' );
		$xpath->registerNamespace( 'office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0' );
		$xpath->registerNamespace( 'style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0' );
		$xpath->registerNamespace( 'fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0' );

		return $xpath;
	}

	/**
	 * Get path to test fixtures templates directory.
	 *
	 * @return string Path to templates directory.
	 */
	protected function getTemplatesPath() {
		return dirname( __DIR__ ) . '/fixtures/templates/';
	}

	/**
	 * Get path to snapshots directory.
	 *
	 * @return string Path to snapshots directory.
	 */
	protected function getSnapshotsPath() {
		return dirname( __DIR__ ) . '/snapshots/';
	}
}
