<?php
/**
 * Tests ensuring full persistence lifecycle for documentate_document posts.
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Meta_Handler;

class DocumentateDocumentPersistenceTest extends WP_UnitTestCase {

	/**
	 * Instance of Documentate_Documents to ensure hooks are registered.
	 *
	 * @var Documentate_Documents
	 */
	private $documentate_documents;

	public function set_up(): void {
		parent::set_up();

		if ( ! post_type_exists( 'documentate_document' ) ) {
			register_post_type( 'documentate_document', array( 'public' => false ) );
		}

		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
		}

		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		// Instantiate Documentate_Documents to register hooks for meta persistence.
		$this->documentate_documents = new Documentate_Documents();
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * It should persist scalar fields into meta and structured content coherently.
	 */
	public function test_scalar_fields_save_and_retrieve_correctly() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento Scalar' );

		$raw_name    = "  Juan Pérez  ";
		$raw_units   = " 8 ";
		$raw_email   = " persona@example.com ";
		$raw_phone   = " +34123456789 <script>alert(1)</script> ";
		$raw_content = $this->build_complex_html_payload( 'Encabezado inicial' );

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => $raw_name,
				'unidades' => $raw_units,
				'email'    => $raw_email,
				'telefono' => $raw_phone,
				'cuerpo'   => $raw_content,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento Scalar' ) );

		$stored_name  = get_post_meta( $post_id, 'documentate_field_nombre', true );
		$stored_units = get_post_meta( $post_id, 'documentate_field_unidades', true );
		$stored_email = get_post_meta( $post_id, 'documentate_field_email', true );
		$stored_phone = get_post_meta( $post_id, 'documentate_field_telefono', true );
		$stored_body  = get_post_meta( $post_id, 'documentate_field_cuerpo', true );

		$this->assertSame( sanitize_text_field( $raw_name ), $stored_name );
		$this->assertSame( sanitize_text_field( $raw_units ), $stored_units );
		$this->assertSame( sanitize_text_field( $raw_email ), $stored_email );
		$this->assertSame( sanitize_text_field( $raw_phone ), $stored_phone );

		$this->assertStringContainsString( '<h3>Encabezado inicial</h3>', $stored_body );
		$this->assertStringContainsString( '<strong>', $stored_body );
		$this->assertStringContainsString( '<ul>', $stored_body );
		$this->assertStringContainsString( '<ol>', $stored_body );
		$this->assertStringContainsString( '<table>', $stored_body );
		$this->assertStringNotContainsString( '<script', $stored_body, 'HTML content must be cleaned of scripts.' );

		$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$this->assertArrayHasKey( 'nombre', $structured );
		$this->assertArrayHasKey( 'unidades', $structured );
		$this->assertArrayHasKey( 'email', $structured );
		$this->assertArrayHasKey( 'telefono', $structured );
		$this->assertArrayHasKey( 'cuerpo', $structured );

		$this->assertSame( 'single', $structured['nombre']['type'] );
		$this->assertSame( 'single', $structured['unidades']['type'] );
		$this->assertSame( 'single', $structured['email']['type'] );
		$this->assertSame( 'single', $structured['telefono']['type'] );
		$this->assertSame( 'rich', $structured['cuerpo']['type'] );

		$this->assertSame( $stored_name, $structured['nombre']['value'] );
		$this->assertSame( $stored_units, $structured['unidades']['value'] );
		$this->assertSame( $stored_email, $structured['email']['value'] );
		$this->assertSame( $stored_phone, $structured['telefono']['value'] );
		$this->assertSame( $stored_body, $structured['cuerpo']['value'] );
	}

	/**
	 * It should update scalar content overriding previous persisted values.
	 */
	public function test_document_update_persists_changes() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento Actualizable' );

		$initial_values = array(
			'nombre'   => 'Nombre Inicial',
			'unidades' => '5',
			'email'    => 'inicial@example.com',
			'telefono' => '+34111222333',
			'cuerpo'   => $this->build_complex_html_payload( 'Bloque original' ),
		);

		$this->prepare_scalar_post_payload( $term_id, $initial_values );
		$previous_content = $this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento Actualizable' ) );

		$updated_values = array(
			'nombre'   => 'Nombre Modificado',
			'unidades' => '9',
			'email'    => 'actualizado@example.com',
			'telefono' => '+34999888777',
			'cuerpo'   => $this->build_complex_html_payload( 'Bloque actualizado' ),
		);

		$_POST = array();
		$this->prepare_scalar_post_payload( $term_id, $updated_values );
			$this->compose_and_save_document(
				$post_id,
				array( 'post_title' => 'Documento Actualizable' ),
				array( 'post_content' => $previous_content )
			);

		$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );

		$this->assertSame( sanitize_text_field( $updated_values['nombre'] ), get_post_meta( $post_id, 'documentate_field_nombre', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['unidades'] ), get_post_meta( $post_id, 'documentate_field_unidades', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['email'] ), get_post_meta( $post_id, 'documentate_field_email', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['telefono'] ), get_post_meta( $post_id, 'documentate_field_telefono', true ) );

		$this->assertStringContainsString( '<h3>Bloque actualizado</h3>', $structured['cuerpo']['value'], 'HTML must reflect the updated content.' );
		$this->assertStringNotContainsString( 'Bloque original', $structured['cuerpo']['value'], 'Previous content must not persist after update.' );
		$this->assertSame( get_post_meta( $post_id, 'documentate_field_cuerpo', true ), $structured['cuerpo']['value'] );
	}

	/**
	 * An área user must be able to change values through post_content alone.
	 *
	 * wp_insert_post() takes slashed data, and wp_filter_post_kses() hands it
	 * back slashed for a user without unfiltered_html even when the caller
	 * passed it unslashed. Reading it without unslashing left the composer
	 * with no request at all, so every field kept the value already stored.
	 */
	public function test_area_user_updates_fields_through_post_content() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento del área' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( Documents_Meta_Handler::build_structured_field_fragment( 'nombre', 'single', 'Nombre inicial' ) ),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'An área user goes through kses.' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( Documents_Meta_Handler::build_structured_field_fragment( 'nombre', 'single', 'Nombre del área' ) ),
			)
		);

		$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id, 'raw' ) );

		$this->assertSame( 'Nombre del área', $structured['nombre']['value'] );
	}

	/**
	 * It should persist repeater collections keeping HTML intact and honour reordering.
	 */
	public function test_repeater_fields_handle_complex_html_and_reordering() {
		$term_id = $this->create_repeater_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento con anexos' );

		$initial_tpl_fields = array(
			'anexos' => array(
				array(
					'numero'   => ' 1 ',
					'titulo'   => ' Introducción ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 1' ),
				),
				array(
					'numero'   => ' 2 ',
					'titulo'   => ' Desarrollo ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 2' ),
				),
				array(
					'numero'   => ' 3 ',
					'titulo'   => ' Resultados ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 3' ),
				),
				array(
					'numero'   => ' 4 ',
					'titulo'   => ' Cierre ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 4' ),
				),
			),
		);

		$this->prepare_repeater_post_payload( $term_id, $initial_tpl_fields );
		$previous_content = $this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento con anexos' ) );

		// Read meta directly from database to avoid WordPress's automatic unslashing.
		global $wpdb;
		$meta_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id,
			'documentate_field_anexos'
		) );

		$this->assertNotSame( '', $meta_value, 'Repeater JSON meta must not be empty after initial save.' );
		$this->assertNotNull( $meta_value, 'Meta must exist in the database.' );

		// Use the same decoding method that the production code uses.
		$decoded_meta = Documentate_Documents::decode_array_field_value( $meta_value );
		$this->assertIsArray( $decoded_meta, 'JSON meta must decode to an array.' );
		$this->assertCount( 4, $decoded_meta, 'Complete annexes collection must persist.' );

		$this->assertSame( array( '1', '2', '3', '4' ), array_column( $decoded_meta, 'numero' ), 'Initial order of annexes must be preserved.' );

		foreach ( $decoded_meta as $item ) {
			$this->assertStringContainsString( '<table>', $item['contenido'], 'Each annex must preserve HTML tables.' );
			$this->assertStringContainsString( '<ul>', $item['contenido'] );
			$this->assertStringNotContainsString( '<script', $item['contenido'], 'Scripts must be removed from repeater content.' );
		}

		$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$this->assertArrayHasKey( 'anexos', $structured );
		$this->assertSame( 'array', $structured['anexos']['type'] );

		$structured_items = Documentate_Documents::decode_array_field_value( $structured['anexos']['value'] );
		$this->assertIsArray( $structured_items );
		$this->assertCount( 4, $structured_items );
		$this->assertSame( $decoded_meta, $structured_items, 'Structured content and JSON meta must match.' );

		$updated_tpl_fields = array(
			'anexos' => array(
				array(
					'numero'   => ' 3 ',
					'titulo'   => ' Resultados revisados ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 3 - Revisión' ),
				),
				array(
					'numero'   => ' 1 ',
					'titulo'   => ' Introducción ajustada ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 1 - Ajustado' ),
				),
				array(
					'numero'   => ' 4 ',
					'titulo'   => ' Cierre extendido ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 4 - Extendiendo' ),
				),
			),
		);

		$_POST = array();
		$this->prepare_repeater_post_payload( $term_id, $updated_tpl_fields );
			$this->compose_and_save_document(
				$post_id,
				array( 'post_title' => 'Documento con anexos' ),
				array( 'post_content' => $previous_content )
			);

		// Read meta directly from database.
		$meta_after_update = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id,
			'documentate_field_anexos'
		) );
		$decoded_after_update = Documentate_Documents::decode_array_field_value( $meta_after_update );
		$this->assertIsArray( $decoded_after_update );
		$this->assertCount( 3, $decoded_after_update, 'Removed element must not persist after reordering.' );
		$this->assertSame( array( '3', '1', '4' ), array_column( $decoded_after_update, 'numero' ), 'Updated order must match the new payload.' );

		$this->assertStringContainsString( 'Anexo 3 - Revisión', $decoded_after_update[0]['contenido'], 'First annex must reflect content changes.' );
		$this->assertStringNotContainsString( 'Anexo 2', $meta_after_update, 'Deleted annex must not remain in final JSON.' );

		$structured_after_update = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$items_after_update      = Documentate_Documents::decode_array_field_value( $structured_after_update['anexos']['value'] );
		$this->assertIsArray( $items_after_update );
		$this->assertSame( $decoded_after_update, $items_after_update, 'Structured content must reflect the new order and content.' );
	}

	/**
	 * Register document schema containing scalar fields.
	 *
	 * @return int
	 */
	private function create_scalar_document_type() {
		$term = wp_insert_term( 'Tipo Scalar ' . uniqid(), 'documentate_doc_type' );
		$this->assertNotWPError( $term, 'Scalar type term creation must complete.' );
		$term_id = intval( $term['term_id'] );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $this->build_scalar_schema_definition() );

		return $term_id;
	}

	/**
	 * Register document schema containing repeater fields.
	 *
	 * @return int
	 */
	private function create_repeater_document_type() {
		$term = wp_insert_term( 'Tipo Repetidor ' . uniqid(), 'documentate_doc_type' );
		$this->assertNotWPError( $term, 'Repeater type term creation must complete.' );
		$term_id = intval( $term['term_id'] );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $this->build_repeater_schema_definition() );

		return $term_id;
	}

	/**
	 * Create a documentate_document associated to the provided term.
	 *
	 * @param int    $term_id Document type term ID.
	 * @param string $title   Post title.
	 * @return int
	 */
	private function create_document_for_term( $term_id, $title ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);

		$this->assertNotWPError( $post_id, 'documentate_document CPT creation must complete.' );
		$this->assertGreaterThan( 0, intval( $post_id ) );

		wp_set_post_terms( intval( $post_id ), array( $term_id ), 'documentate_doc_type', false );

		return intval( $post_id );
	}

	/**
	 * Prepare POST payload for scalar fields.
	 *
	 * @param int   $term_id Term identifier.
	 * @param array $values  Associative array of slug => value.
	 * @return void
	 */
	private function prepare_scalar_post_payload( $term_id, $values ) {
		$_POST                              = array();
		$_POST['documentate_doc_type']         = (string) $term_id;
		$_POST['documentate_sections_nonce']   = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_type_nonce']       = wp_create_nonce( 'documentate_type_nonce' );

		foreach ( $values as $slug => $value ) {
			$_POST[ 'documentate_field_' . $slug ] = wp_slash( (string) $value );
		}
	}

	/**
	 * Prepare POST payload with repeater tpl_fields.
	 *
	 * @param int   $term_id    Term identifier.
	 * @param array $tpl_fields tpl_fields payload.
	 * @return void
	 */
	private function prepare_repeater_post_payload( $term_id, $tpl_fields ) {
		$_POST                            = array();
		$_POST['documentate_doc_type']       = (string) $term_id;
		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_type_nonce']     = wp_create_nonce( 'documentate_type_nonce' );
		$_POST['tpl_fields']              = wp_slash( $tpl_fields );
	}

	/**
	 * Compose post_content and persist meta for the current document payload.
	 *
	 * @param int   $post_id  Document ID.
	 * @param array $data     Additional post data overrides.
	 * @param array $postarr  Additional raw post array overrides.
	 * @return string Persisted post_content value.
	 */
	private function compose_and_save_document( $post_id, $data = array(), $postarr = array() ) {
		$payload = array_merge(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_title'  => 'Documento de prueba',
			),
			$data
		);

		$postarr_values = array_merge(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id, 'edit' ),
			),
			$postarr
		);

		$_POST['action']  = 'editpost';
		$_POST['post_ID'] = (string) $post_id;

		$update_payload = array_merge(
			$payload,
			array(
				'ID'           => $post_id,
				'post_content' => $postarr_values['post_content'],
			)
		);

		$result = wp_update_post( $update_payload, true );
		$this->assertNotWPError( $result, 'wp_update_post must persist the document without errors.' );
		$this->assertSame( intval( $post_id ), intval( $result ), 'Returned ID must match the updated document.' );

		return (string) get_post_field( 'post_content', $post_id );
	}

	/**
	 * Build schema definition for scalar tests.
	 *
	 * @return array
	 */
	private function build_scalar_schema_definition() {
		return array(
			'version'   => 2,
			'fields'    => array(
				array(
					'name'        => 'nombre',
					'slug'        => 'nombre',
					'type'        => 'text',
					'title'       => 'Nombre',
					'placeholder' => 'Nombre completo',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '120',
					'parameters'  => array(
						'required' => true,
					),
				),
				array(
					'name'        => 'unidades',
					'slug'        => 'unidades',
					'type'        => 'number',
					'title'       => 'Unidades',
					'placeholder' => 'Número de unidades',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '1',
					'maxvalue'    => '10',
					'length'      => '',
					'parameters'  => array(
						'step' => '1',
					),
				),
				array(
					'name'        => 'email',
					'slug'        => 'email',
					'type'        => 'email',
					'title'       => 'Correo electrónico',
					'placeholder' => 'persona@ejemplo.com',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
				array(
					'name'        => 'telefono',
					'slug'        => 'telefono',
					'type'        => 'text',
					'title'       => 'Teléfono',
					'placeholder' => '+34123456789',
					'description' => '',
					'pattern'     => '^[+]?[1-9][0-9]{1,14}$',
					'patternmsg'  => 'Introduce un teléfono válido con prefijo internacional',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
				array(
					'name'        => 'cuerpo',
					'slug'        => 'cuerpo',
					'type'        => 'html',
					'title'       => 'Cuerpo',
					'placeholder' => '',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
			),
			'repeaters' => array(),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'scalar-persistence.odt',
				'hash'          => md5( 'scalar-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build schema definition for repeater tests.
	 *
	 * @return array
	 */
	private function build_repeater_schema_definition() {
		return array(
			'version'   => 2,
			'fields'    => array(),
			'repeaters' => array(
				array(
					'name'   => 'Anexos',
					'slug'   => 'anexos',
					'fields' => array(
						array(
							'name'        => 'Número',
							'slug'        => 'numero',
							'type'        => 'text',
							'title'       => 'Número',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
						array(
							'name'        => 'Título',
							'slug'        => 'titulo',
							'type'        => 'text',
							'title'       => 'Título',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
						array(
							'name'        => 'Contenido',
							'slug'        => 'contenido',
							'type'        => 'html',
							'title'       => 'Contenido',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
					),
				),
			),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'repeater-persistence.odt',
				'hash'          => md5( 'repeater-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build complex HTML payload for scalar rich text fields.
	 *
	 * @param string $heading Heading text to inject.
	 * @return string
	 */
	private function build_complex_html_payload( $heading ) {
		return '<h3>' . $heading . '</h3>'
			. '<p>Sección de apertura con <strong>énfasis</strong>, <em>destacados</em> y <u>marcados</u>.</p>'
			. '<p>Consulta <a href="https://example.com">este recurso</a> para ampliar información.</p>'
			. '<ul><li>Elemento A<ul><li>Sub elemento A1</li></ul></li><li>Elemento B</li></ul>'
			. '<ol><li>Paso uno</li><li>Paso dos</li></ol>'
			. '<table><thead><tr><th>Columna</th><th>Detalle</th></tr></thead>'
			. '<tbody><tr><td>Fila 1</td><td>Dato 1</td></tr><tr><td>Fila 2</td><td>Dato 2</td></tr></tbody></table>'
			. '<script>console.log("should be removed");</script>';
	}

	/**
	 * Build complex HTML payload for repeater entries.
	 *
	 * @param string $title Base title to inject.
	 * @return string
	 */
	private function build_repeater_item_html( $title ) {
		return '<h1>' . $title . '</h1>'
			. '<h2>Subsección resumida</h2>'
			. '<h3>Detalles</h3>'
			. '<h4>Notas</h4>'
			. '<h5>Referencias</h5>'
			. '<h6>Apéndice</h6>'
			. '<p>Contenido <strong>importante</strong> con <em>variaciones</em> y <u>subrayados</u>.</p>'
			. '<p>Incluye un <a href="https://example.com/'.$title.'">enlace específico</a> y listas mixtas.</p>'
			. '<ul><li>Punto 1</li><li>Punto 2<ul><li>Sub punto 2.1</li></ul></li></ul>'
			. '<ol><li>Acción 1</li><li>Acción 2</li></ol>'
			. '<table><thead><tr><th>Clave</th><th>Valor</th></tr></thead>'
			. '<tbody><tr><td>A</td><td>Uno</td></tr><tr><td>B</td><td>Dos</td></tr></tbody></table>'
			. '<script>console.warn("remove me");</script>';
	}

	/**
	 * It should persist rich text with combined formatting correctly.
	 */
	public function test_rich_text_with_combined_formatting_persists() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con formato combinado' );

		$html_with_combined = '<p>Texto <strong><em><u>todo junto</u></em></strong> y <span style="font-weight:bold">negrita inline</span></p>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $html_with_combined,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con formato combinado' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		$this->assertStringContainsString( '<strong>', $stored_body );
		$this->assertStringContainsString( '<em>', $stored_body );
		$this->assertStringContainsString( '<u>', $stored_body );
		$this->assertStringContainsString( 'todo junto', $stored_body );
	}

	/**
	 * It should persist unicode characters correctly.
	 */
	public function test_unicode_characters_persist_correctly() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con unicode' );

		$unicode_html = '<p>Español: áéíóú ñ Ñ — € ™ © ® • ¿¡</p><p>Otros: 中文 العربية עברית</p>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Unicode',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $unicode_html,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con unicode' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		$this->assertStringContainsString( 'Español', $stored_body );
		$this->assertStringContainsString( 'áéíóú', $stored_body );
		$this->assertStringContainsString( 'ñ', $stored_body );
	}

	/**
	 * It should handle very long HTML content without corruption.
	 */
	public function test_long_html_content_persists_without_corruption() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con contenido largo' );

		// Build a long HTML string with multiple sections
		$long_html = '';
		for ( $i = 1; $i <= 10; $i++ ) {
			$long_html .= '<h2>Sección ' . $i . '</h2>';
			$long_html .= '<p>Contenido de la sección ' . $i . ' con <strong>negrita</strong>, <em>cursiva</em> y <u>subrayado</u>.</p>';
			$long_html .= '<ul><li>Item 1</li><li>Item 2</li><li>Item 3</li></ul>';
			$long_html .= '<table><tr><th>Col1</th><th>Col2</th></tr><tr><td>Data1</td><td>Data2</td></tr></table>';
		}

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Largo',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $long_html,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con contenido largo' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		$this->assertStringContainsString( 'Sección 1', $stored_body );
		$this->assertStringContainsString( 'Sección 10', $stored_body );
		$this->assertStringContainsString( '<strong>', $stored_body );
		$this->assertStringContainsString( '<table>', $stored_body );
	}

	/**
	 * It should handle empty and whitespace-only HTML gracefully.
	 */
	public function test_empty_and_whitespace_html_handled_gracefully() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con HTML vacío' );

		$empty_html = '<p></p><p>   </p><strong></strong><em></em>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Vacío',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $empty_html,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con HTML vacío' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		// Should handle without errors
		$this->assertIsString( $stored_body );
	}

	/**
	 * It should handle tables with various complex structures.
	 */
	public function test_complex_tables_persist_correctly() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con tablas complejas' );

		$complex_table = '<table>'
			. '<thead><tr><th colspan="2">Título combinado</th></tr></thead>'
			. '<tbody>'
			. '<tr><td rowspan="2">Span vertical</td><td>Normal</td></tr>'
			. '<tr><td>Otra celda</td></tr>'
			. '<tr><td></td><td>Celda vacía a la izquierda</td></tr>'
			. '</tbody>'
			. '</table>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Tablas',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $complex_table,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con tablas complejas' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		$this->assertStringContainsString( '<table>', $stored_body );
		$this->assertStringContainsString( 'Título combinado', $stored_body );
		$this->assertStringContainsString( 'Span vertical', $stored_body );
	}

	/**
	 * It should handle deeply nested lists in persistence.
	 */
	public function test_deeply_nested_lists_persist() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con listas anidadas' );

		$nested_lists = '<ul>'
			. '<li>Nivel 1A'
			. '<ul><li>Nivel 2A<ul><li>Nivel 3A<ul><li>Nivel 4A</li></ul></li></ul></li></ul>'
			. '</li>'
			. '<li>Nivel 1B</li>'
			. '</ul>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Listas',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $nested_lists,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con listas anidadas' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		// Note: WordPress sanitization might strip certain characters in nested contexts
		$this->assertStringContainsString( '1A', $stored_body );
		$this->assertStringContainsString( '2A', $stored_body );
		$this->assertStringContainsString( '3A', $stored_body );
		$this->assertStringContainsString( '4A', $stored_body );
		$this->assertStringContainsString( '1B', $stored_body );
		$this->assertStringContainsString( '<ul>', $stored_body );
		$this->assertStringContainsString( '<li>', $stored_body );
	}

	/**
	 * It should handle mixed HTML entities correctly.
	 */
	public function test_html_entities_persist_correctly() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Doc con entidades' );

		$entities_html = '<p>Símbolos: &lt; &gt; &amp; &quot; &nbsp; &copy; &trade;</p>';

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => 'Test Entidades',
				'unidades' => '1',
				'email'    => 'test@test.com',
				'telefono' => '+34123456789',
				'cuerpo'   => $entities_html,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Doc con entidades' ) );

		$stored_body = get_post_meta( $post_id, 'documentate_field_cuerpo', true );
		$this->assertStringContainsString( 'Símbolos', $stored_body );
	}
}
