<?php
/**
 * Renders every shipped PDF layout against the ODT template it reproduces.
 *
 * Each test builds a document type from the real fixture in `fixtures/`, so the
 * schema the merge fields are taken from is the one the layout was written
 * against, points the type at the layout under test, fills the document with
 * the demo content the plugin ships for that type, and renders it natively.
 *
 * The assertions read the text back out of the PDF bytes. They check the fixed
 * wording the layout carries, the values the document supplied, the formatting
 * the ODT asks for, and that no TinyButStrong tag survived the merge.
 *
 * @package Documentate
 */

/**
 * One test per layout under templates/pdf/.
 */
class DocumentPdfLayoutsTest extends Documentate_Generation_Test_Base {

	/**
	 * Shape of a tag TinyButStrong should have consumed.
	 *
	 * `[` followed by a field name and then either a parameter list, the end
	 * of the tag or a sub-field. Prose brackets — "[sic]", "[1]" — do not
	 * match, so a document quoting them still passes.
	 */
	const UNMERGED_TAG = '/\[[a-z0-9_]+[;\].]/';

	/**
	 * Render a document of the given type on the given layout.
	 *
	 * @param string $fixture   Template file name under fixtures/.
	 * @param string $layout    Layout slug under templates/pdf/.
	 * @param string $title     Document title, which the layouts print.
	 * @param array  $fields    Scalar field values, keyed by schema slug.
	 * @param array  $repeaters Repeater rows, keyed by schema slug.
	 * @return string Raw PDF bytes.
	 */
	private function render( $fixture, $layout, $title, array $fields, array $repeaters = array() ) {
		Documentate_Demo_Data::ensure_default_media();
		$template_id = Documentate_Demo_Data::import_fixture_file( $fixture );

		$type = $this->create_doc_type_with_attachment( $template_id, 'odt', 'Tipo ' . $layout );
		update_term_meta( $type['term_id'], Documentate_Pdf_Layout::META_KEY, $layout );

		$post_id = $this->create_document_with_data( $type['term_id'], $fields, $repeaters );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);

		$path = $this->generate_document( $post_id, 'pdf' );
		$this->assertIsString( $path, 'The native renderer should return a path: ' . $this->reason( $path ) );
		$this->assertFileExists( $path, 'The rendered PDF should be on disk.' );

		$pdf = file_get_contents( $path );
		$this->assertIsString( $pdf, 'The rendered PDF should be readable.' );
		$this->assertStringStartsWith( '%PDF', $pdf, 'The rendered file should be a PDF.' );

		return $pdf;
	}

	/**
	 * Why a generation failed, for the message of a failing assertion.
	 *
	 * @param mixed $path Whatever the generator returned.
	 * @return string
	 */
	private function reason( $path ) {
		return $path instanceof WP_Error ? $path->get_error_message() : gettype( $path );
	}

	/**
	 * Every text the PDF draws, run together and with runs of spaces collapsed.
	 *
	 * The renderer draws one text operation per styled run per line, so a
	 * sentence that wrapped, or that changed to bold halfway, arrives in
	 * pieces. Joining them with a space and collapsing puts it back together
	 * closely enough to search for a phrase.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return string
	 */
	private function drawn_text( $pdf ) {
		$joined = implode( ' ', Documentate_Pdf_Test_Helper::texts( $pdf ) );

		return trim( preg_replace( '/\s+/u', ' ', $joined ) );
	}

	/**
	 * Assert that the PDF draws a phrase.
	 *
	 * @param string $pdf      Raw PDF bytes.
	 * @param string $expected Phrase the document should show.
	 * @param string $message  Why it should.
	 */
	private function assertDrawn( $pdf, $expected, $message ) {
		$this->assertStringContainsString( $expected, $this->drawn_text( $pdf ), $message );
	}

	/**
	 * Assert that the text the PDF draws matches a pattern.
	 *
	 * @param string $pdf     Raw PDF bytes.
	 * @param string $pattern Regular expression the drawn text should match.
	 * @param string $message Why it should.
	 */
	private function assertDrawnMatches( $pdf, $pattern, $message ) {
		$this->assertMatchesRegularExpression( $pattern, $this->drawn_text( $pdf ), $message );
	}

	/**
	 * Assert that the PDF does not draw a phrase.
	 *
	 * @param string $pdf        Raw PDF bytes.
	 * @param string $unexpected Phrase the document should not show.
	 * @param string $message    Why it should not.
	 */
	private function assertNotDrawn( $pdf, $unexpected, $message ) {
		$this->assertStringNotContainsString( $unexpected, $this->drawn_text( $pdf ), $message );
	}

	/**
	 * How far down page one the topmost line of text is drawn, in millimetres.
	 *
	 * PDF coordinates run up from the foot of the page, so the highest text
	 * operation of page one becomes a distance from the top edge. It is a
	 * baseline, so it sits a little below the top of the glyphs.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return float
	 */
	private function first_line_top( $pdf ) {
		$page = array_filter(
			Documentate_Pdf_Test_Helper::text_ops( $pdf ),
			static function ( $op ) {
				return 1 === $op['page'];
			}
		);
		$this->assertNotEmpty( $page, 'Page one should draw some text.' );

		$page_height = 297.0 * 72 / 25.4;

		return ( $page_height - max( array_column( $page, 'y' ) ) ) * 25.4 / 72;
	}

	/**
	 * Assert that no TinyButStrong tag reached the page.
	 *
	 * A tag that survives is printed verbatim into an official document, so
	 * every layout is checked for one, whether it is a field the layout named
	 * wrongly or a block marker that never expanded.
	 *
	 * @param string $pdf Raw PDF bytes.
	 */
	private function assertNothingUnmerged( $pdf ) {
		$this->assertDoesNotMatchRegularExpression(
			self::UNMERGED_TAG,
			$this->drawn_text( $pdf ),
			'No TinyButStrong tag should survive the merge.'
		);
	}

	/**
	 * The ruling prints its four fixed headings, its rich sections and its annex.
	 */
	public function test_resolucion_layout_prints_the_ruling_sections_and_its_annex() {
		$pdf = $this->render(
			'resolucion.odt',
			'resolucion',
			'Resolución de prueba',
			array(
				'objeto'       => 'Aprobación de las bases reguladoras y convocatoria del programa piloto de innovación educativa para el curso 2025-2026.',
				'antecedentes' => '<p><strong>Primero.</strong> El Decreto 114/2011, de 11 de mayo, regula el reconocimiento de las actividades de formación permanente del profesorado.</p>'
					. '<p><strong>Segundo.</strong> Se hace necesario impulsar programas que fomenten la innovación educativa en los centros docentes públicos.</p>',
				'fundamentos'  => '<p><strong>Primero.</strong> La Ley Orgánica 2/2006, de 3 de mayo, de Educación, establece que la formación permanente constituye un derecho del profesorado.</p>',
				'resuelvo'     => '<p><strong>Primero.</strong> Aprobar las bases reguladoras del programa piloto de innovación educativa para el curso 2025-2026.</p>'
					. '<p><strong>Segundo.</strong> Convocar la participación de los centros docentes públicos no universitarios de Canarias.</p>',
			),
			array(
				'anexos' => array(
					array(
						'code'    => 'Anexo I',
						'title'   => 'BASES REGULADORAS DEL PROGRAMA',
						'summary' => '<p>El presente programa promueve la innovación educativa en los centros docentes públicos.</p>',
					),
				),
			)
		);

		$this->assertDrawn( $pdf, 'RESOLUCIÓN DE PRUEBA', 'The title should be printed in upper case, as ope=utf8,upper asks.' );
		$this->assertDrawn( $pdf, 'ANTECEDENTES DE HECHO', 'The first fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'FUNDAMENTOS DE DERECHO', 'The second fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'RESUELVO', 'The third fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'A estos hechos les son de aplicación los siguientes', 'The fixed link between the sections should be printed.' );
		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signing office should be printed.' );

		$this->assertDrawn( $pdf, 'Aprobación de las bases reguladoras', 'The «objeto» field should be merged.' );
		$this->assertDrawn( $pdf, 'El Decreto 114/2011', 'The rich «antecedentes» field should be merged as text, not as escaped markup.' );
		$this->assertDrawn( $pdf, 'La Ley Orgánica 2/2006', 'The rich «fundamentos» field should be merged.' );
		$this->assertDrawn( $pdf, 'Aprobar las bases reguladoras del programa piloto', 'The rich «resuelvo» field should be merged.' );
		$this->assertNotDrawn( $pdf, '<strong>', 'Rich fields carry strconv=no, so their markup should be rendered, never printed.' );

		$this->assertDrawn( $pdf, 'Anexo I', 'The annex code should be merged from the repeater.' );
		$this->assertDrawn( $pdf, 'BASES REGULADORAS DEL PROGRAMA', 'The annex title should be merged from the repeater.' );
		$this->assertDrawn( $pdf, 'promueve la innovación educativa', 'The annex summary should be merged from the repeater.' );

		$this->assertGreaterThanOrEqual( 2, Documentate_Pdf_Test_Helper::page_count( $pdf ), 'The annex starts a page of its own.' );
		$this->assertDrawn( $pdf, 'Folio 1/', 'The header folio box should be printed.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * Three suppliers with two concepts each expand into six concept rows, and
	 * the section of a repeater left empty is dropped whole.
	 */
	public function test_propuestagasto_layout_expands_nested_repeaters_and_hides_empty_sections() {
		$pdf = $this->render(
			'propuestagasto.odt',
			'propuestagasto',
			'Programa de formación en metodologías activas',
			array(
				'curso'               => '2024/2025',
				'letra_decreto'       => 'a',
				'para'                => 'la formación del profesorado en metodologías activas y competencias digitales',
				'objeto'              => 'Desarrollo de un programa de formación continua para el profesorado de centros públicos de Canarias.',
				'lineadeactuacion'    => 'Formación del profesorado y desarrollo profesional docente',
				'destinatarios'       => 'Profesorado de centros públicos de primaria y secundaria',
				'alcance_centros'     => '150',
				'alcance_profesorado' => '2500',
				'alcance_alumnado'    => '45000',
				'alcance_familias'    => '',
				'gasto_letra'         => 'veinticinco mil euros',
				'gasto_numero'        => '25000',
				'partida'             => '18.03.322B.229.0100',
			),
			array(
				'servicios' => array(
					$this->supplier( 'Formación Docente Canarias S.L.', 'B76543210', 'Curso de metodologías activas', 'Taller de competencia digital' ),
					$this->supplier( 'Aula Abierta Formación S.C.P.', 'J35678901', 'Seminario de evaluación', 'Mentoría en centros' ),
					$this->supplier( 'Innova Educación Canarias S.L.', 'B11223344', 'Jornada de buenas prácticas', 'Acompañamiento a claustros' ),
				),
			)
		);

		$this->assertDrawn( $pdf, 'INFORME-PROPUESTA DEL RESPONSABLE DEL SERVICIO', 'The fixed opening of the report should be printed.' );
		$this->assertDrawn( $pdf, 'PROGRAMA DE FORMACIÓN EN METODOLOGÍAS ACTIVAS', 'The heading prints the title in upper case.' );
		$this->assertDrawn( $pdf, 'A DESARROLLAR EN EL CURSO ESCOLAR 2024/2025', 'The school year should be merged into the heading.' );
		$this->assertDrawn( $pdf, 'BLOQUE I: PROPUESTA EDUCATIVA', 'The first block heading should be printed.' );
		$this->assertDrawn( $pdf, 'BLOQUE II: PROPUESTA ECONÓMICA', 'The second block heading should be printed.' );
		$this->assertDrawn( $pdf, 'Programa de formación en metodologías activas', 'The «Título:» line prints the title as it was typed.' );

		$this->assertDrawn( $pdf, 'CENTROS', 'The reach table should carry its fixed column headings.' );
		$this->assertDrawn( $pdf, '45.000', 'A reach figure should be printed with the thousands separator frm asks for.' );
		$this->assertDrawn( $pdf, '---', 'A reach figure left blank should print the three dashes ifempty asks for.' );

		$this->assertDrawn( $pdf, '25.000,00 €', 'The total spend should be printed as an amount, not as a bare number.' );
		$this->assertDrawn( $pdf, '18.03.322B.229.0100', 'The budget line should be merged.' );

		$this->assertDrawn( $pdf, 'SERVICIOS a contratar', 'The services section should be shown, because the repeater has rows.' );
		$this->assertNotDrawn( $pdf, 'SUMINISTROS a contratar', 'The supplies section should be dropped, because its repeater is empty.' );
		$this->assertNotDrawn( $pdf, 'PERSONAL experto a contratar', 'The experts section should be dropped, because its repeater is empty.' );

		foreach ( array( 'Formación Docente Canarias S.L.', 'Aula Abierta Formación S.C.P.', 'Innova Educación Canarias S.L.' ) as $supplier ) {
			$this->assertDrawn( $pdf, $supplier, 'Every supplier of the repeater should get a table of its own.' );
		}

		$concepts = array(
			'Curso de metodologías activas',
			'Taller de competencia digital',
			'Seminario de evaluación',
			'Mentoría en centros',
			'Jornada de buenas prácticas',
			'Acompañamiento a claustros',
		);
		foreach ( $concepts as $concept ) {
			$this->assertDrawn( $pdf, $concept, 'Every concept of every supplier should get a row of its own.' );
		}
		$this->assertSame( 6, $this->count_drawn( $pdf, $concepts ), 'Three suppliers of two concepts each should draw six concept rows.' );

		$this->assertDrawn( $pdf, '4.500,00 €', 'The gross amount of a supplier should be printed as an amount.' );
		$this->assertDrawn( $pdf, '1.500,00 €', 'A unit price should be printed as an amount.' );

		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signature block should close the report.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The two sections the previous test leaves empty are printed when their
	 * repeaters do have rows, and the one it filled is the one dropped here.
	 *
	 * Without this, deleting a whole section from the layout would satisfy the
	 * "empty section is hidden" assertion of the previous test.
	 */
	public function test_propuestagasto_layout_shows_the_supplies_and_experts_sections_when_they_have_rows() {
		$pdf = $this->render(
			'propuestagasto.odt',
			'propuestagasto',
			'Equipamiento de aulas del futuro',
			array(
				'curso'        => '2025/2026',
				'letra_decreto' => 'b',
				'para'         => 'la dotación de equipamiento tecnológico',
				'objeto'       => 'Dotación de equipamiento para las aulas del futuro.',
				'gasto_letra'  => 'nueve mil euros',
				'gasto_numero' => '9000',
				'partida'      => '18.03.322B.640.2010 - PCT BE',
			),
			array(
				'suministros' => array(
					$this->supplier( 'TecnoEducación S.A.', 'A12345678', 'Tabletas educativas', 'Pizarras digitales' ),
				),
				'expertos'    => array(
					$this->supplier( 'Ivonne Montesdeoca Morales', '43111222C', 'Diseño de la formación', 'Impartición de la formación' ),
				),
			)
		);

		$this->assertDrawn( $pdf, 'SUMINISTROS a contratar', 'The supplies section should be shown, because its repeater has rows.' );
		$this->assertDrawn( $pdf, 'PERSONAL experto a contratar', 'The experts section should be shown, because its repeater has rows.' );
		$this->assertNotDrawn( $pdf, 'SERVICIOS a contratar', 'The services section should be dropped, because its repeater is empty.' );

		$this->assertDrawn( $pdf, 'TecnoEducación S.A.', 'The supplies table should carry its supplier.' );
		$this->assertDrawn( $pdf, 'Ivonne Montesdeoca Morales', 'The experts table should carry its expert.' );
		$this->assertDrawn( $pdf, 'Tabletas educativas', 'A concept of the supplies repeater should get a row.' );
		$this->assertDrawn( $pdf, 'Impartición de la formación', 'A concept of the experts repeater should get a row.' );
		$this->assertDrawn( $pdf, '9.000,00 €', 'The total spend should be printed as an amount.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The report prints its subject, its rich body and the signing office, on
	 * the large letterhead.
	 */
	public function test_modelo_informe_layout_prints_the_subject_and_the_rich_body() {
		$pdf = $this->render(
			'modelo_informe.odt',
			'modelo_informe',
			'Informe sobre adaptaciones curriculares',
			array(
				'asunto'      => 'Remisión de informe sobre adaptaciones curriculares en centros de educación secundaria obligatoria',
				'respuesta'   => '<p>En relación con el asunto indicado, y tras el análisis realizado por esta Dirección General, se informa lo siguiente:</p>'
					. '<p><strong>PRIMERO.</strong> De conformidad con lo establecido en el artículo 71 de la Ley Orgánica 2/2006, de 3 de mayo, de Educación.</p>'
					. '<p>Es cuanto se informa a los efectos oportunos.</p>',
				'firma_cargo' => 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS, INCLUSIÓN E INNOVACIÓN',
			)
		);

		// The large letterhead is drawn from 35.3 mm to 50.0 mm down the page,
		// and the first page keeps a top margin of its own to clear it.
		$top = $this->first_line_top( $pdf );
		$this->assertGreaterThan( 50.0, $top, 'The body of page one should start below the large letterhead.' );
		$this->assertLessThan( 90.0, $top, 'The body of page one should not be pushed halfway down the sheet either.' );

		$this->assertDrawn( $pdf, 'Asunto: Remisión de informe sobre adaptaciones curriculares', 'The subject line should carry its fixed label and the merged subject.' );
		$this->assertDrawn( $pdf, 'En relación con el asunto indicado', 'The rich body should be merged as text, not as escaped markup.' );
		$this->assertDrawn( $pdf, 'PRIMERO.', 'The numbered points of the rich body should be merged.' );
		$this->assertDrawn( $pdf, 'Es cuanto se informa a los efectos oportunos.', 'The closing of the rich body should be merged.' );
		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signing office should be merged.' );
		$this->assertNotDrawn( $pdf, '<p>', 'The rich field carries strconv=no, so its markup should be rendered, never printed.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The reply to a written request prints the addressee, the registry number
	 * and the answer.
	 */
	public function test_respuesta_escrito_layout_prints_the_addressee_and_the_answer() {
		$pdf = $this->render(
			'respuesta_escrito.odt',
			'respuesta_escrito',
			'Respuesta a solicitud de información',
			array(
				'destinatario'       => 'D./D.ª Juan Rodríguez Martín',
				'destinatario_email' => 'juan.rodriguez@example.es',
				'asunto'             => 'Remisión de informe solicitado con referencia de expediente 2025/00123',
				'numero_solicitud'   => '2025/00123',
				'respuesta'          => 'se hace constar que esta Dirección General no dispone de antecedentes, datos ni información en relación con el caso referido.',
				'firma_cargo'        => 'EL RESPONSABLE DEL SERVICIO DE ORDENACIÓN DE LAS ENSEÑANZAS Y EDUCACIÓN DE PERSONAS ADULTAS',
			)
		);

		$top = $this->first_line_top( $pdf );
		$this->assertGreaterThan( 50.0, $top, 'The body of page one should start below the large letterhead.' );
		$this->assertLessThan( 90.0, $top, 'The body of page one should not be pushed halfway down the sheet either.' );

		$this->assertDrawn( $pdf, 'DESTINATARIO/A: D./D.ª Juan Rodríguez Martín', 'The addressee should carry its fixed label and the merged name.' );
		$this->assertDrawn( $pdf, 'juan.rodriguez@example.es', 'The address of the addressee should be merged.' );
		$this->assertDrawn( $pdf, 'Asunto: Remisión de informe solicitado', 'The subject line should be merged.' );
		$this->assertDrawn( $pdf, 'En relación a su solicitud con número de registro de entrada 2025/00123', 'The registry number should be merged into the fixed lead-in.' );
		$this->assertDrawn( $pdf, 'no dispone de antecedentes, datos ni información', 'The answer should be merged.' );
		$this->assertDrawn( $pdf, 'EL RESPONSABLE DEL SERVICIO DE ORDENACIÓN', 'The signing office should be merged.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The out-of-pocket expenses form prints its inline letterhead, the
	 * declaration and one table row per invoice.
	 */
	public function test_gastossuplidos_layout_prints_the_inline_letterhead_and_every_invoice() {
		$pdf = $this->render(
			'gastossuplidos.odt',
			'gastossuplidos',
			'Solicitud de reembolso de gastos de viaje',
			array(
				'nombre_completo' => 'María del Carmen García Hernández',
				'dni'             => '43123456A',
				'iban'            => 'ES9121000418450200051332',
			),
			array(
				'gastos' => array(
					array(
						'proveedor' => 'Iberia LAE S.A.',
						'cif'       => 'A28017648',
						'factura'   => 'IBE-2025-00123',
						'fecha'     => '2025-03-10',
						'importe'   => '245.80',
					),
					array(
						'proveedor' => 'Hotel Meliá Castilla',
						'cif'       => 'A28011069',
						'factura'   => 'FAC-2025-4567',
						'fecha'     => '2025-03-12',
						'importe'   => '312.50',
					),
					array(
						'proveedor' => 'Taxi Madrid S.L.',
						'cif'       => 'B12345678',
						'factura'   => 'T-2025-0089',
						'fecha'     => '2025-03-10',
						'importe'   => '35.00',
					),
				),
			)
		);

		$this->assertNotEmpty(
			Documentate_Pdf_Test_Helper::image_ops( $pdf ),
			'The layout carries its letterhead in the body, so an image should be placed on the page.'
		);
		$this->assertDrawn( $pdf, 'GASTOS SUPLIDOS', 'The first fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'DECLARACIÓN FORMAL RESPONSABLE', 'The second fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'D./Dña.: María Del Carmen García Hernández', 'The declaring person should be merged in title case, as ope=utf8,upperw asks.' );
		$this->assertDrawn( $pdf, 'con DNI : 43123456A', 'The identity number should be merged.' );
		$this->assertDrawn( $pdf, 'e IBAN: ES9121000418450200051332', 'The bank account should be merged.' );
		$this->assertDrawn( $pdf, 'relativas a los gastos generados por Solicitud de reembolso de gastos de viaje', 'The title of the document should be merged into the declaration.' );
		$this->assertDrawn( $pdf, 'N.º DE FACTURA', 'The invoice table should carry its fixed headings.' );

		foreach ( array( 'Iberia LAE S.A.', 'Hotel Meliá Castilla', 'Taxi Madrid S.L.' ) as $supplier ) {
			$this->assertDrawn( $pdf, $supplier, 'Every invoice should get a row of its own.' );
		}
		$this->assertSame( 3, $this->count_drawn( $pdf, array( 'IBE-2025-00123', 'FAC-2025-4567', 'T-2025-0089' ) ), 'Three invoices should draw three rows.' );
		$this->assertDrawn( $pdf, '245.80 €', 'An amount should reach the page as it was typed, followed by the euro sign the ODT prints after the tag.' );
		$this->assertDrawn( $pdf, 'Fdo.:', 'The signature line should close the form.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The payment memorandum prints its inline letterhead, the heading built
	 * from two fields and one table row per invoice.
	 */
	public function test_memoria_pago_cep_layout_prints_the_heading_and_every_invoice() {
		$pdf = $this->render(
			'memoria_pago_cep.odt',
			'memoria_pago_cep',
			'Memoria justificativa de pago de jornadas formativas',
			array(
				'cep'              => 'CEP de Santa Cruz de Tenerife',
				'concepto'         => 'de varias facturas para sufragar los gastos de las jornadas de innovación pedagógica 2025',
				'parrafo_jornadas' => 'Las jornadas de innovación pedagógica tienen como objetivo la formación del profesorado en metodologías activas de aprendizaje.',
				'resolucion_num'   => '1539/2025',
				'resolucion_fecha' => '15/02/2025',
				'year'             => '2025',
				'persona'          => 'COORDINADOR/A',
			),
			array(
				'items' => array(
					array(
						'nombre'      => 'María García López',
						'concepto'    => 'Material didáctico para talleres',
						'num_factura' => 'FAC-2025-0112',
						'importe'     => '245.80',
					),
					array(
						'nombre'      => 'Suministros Educativos S.L.',
						'concepto'    => 'Equipamiento audiovisual',
						'num_factura' => 'SE-2025-0034',
						'importe'     => '890.00',
					),
				),
			)
		);

		$this->assertNotEmpty(
			Documentate_Pdf_Test_Helper::image_ops( $pdf ),
			'The layout carries its letterhead in the body, so an image should be placed on the page.'
		);
		$this->assertDrawn( $pdf, 'MEMORIA JUSTIFICATIVA DE LA DIRECCIÓN GENERAL', 'The fixed opening of the heading should be printed.' );
		$this->assertDrawn( $pdf, 'PARA EL CEP DE SANTA CRUZ DE TENERIFE', 'The centre should be merged into the heading in upper case.' );
		$this->assertDrawn( $pdf, 'DESTINADA A ORDENAR EL PAGO DE VARIAS FACTURAS', 'The purpose should be merged into the heading in upper case.' );
		$this->assertDrawn( $pdf, 'Las jornadas de innovación pedagógica', 'The description of the activity should be merged.' );
		$this->assertDrawn( $pdf, 'Según consta en la resolución nº 1539/2025 del 15/02/2025', 'The ruling that authorises the payment should be merged.' );
		$this->assertDrawn( $pdf, 'durante el ejercicio 2025', 'The financial year should be merged.' );
		$this->assertDrawn( $pdf, 'COORDINADOR/A', 'The first column heading is a field of its own, and should be merged.' );
		$this->assertDrawn( $pdf, 'NºFACTURA', 'The invoice table should carry its fixed headings.' );

		foreach ( array( 'María García López', 'Suministros Educativos S.L.' ) as $payee ) {
			$this->assertDrawn( $pdf, $payee, 'Every invoice should get a row of its own.' );
		}
		$this->assertSame( 2, $this->count_drawn( $pdf, array( 'FAC-2025-0112', 'SE-2025-0034' ) ), 'Two invoices should draw two rows.' );
		$this->assertDrawn( $pdf, '890.00 €', 'An amount should reach the page as it was typed, followed by the euro sign the ODT prints after the tag.' );
		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signing office should close the memorandum.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * One supplier of the propuesta de gasto, with two concepts.
	 *
	 * @param string $name    Trading name of the supplier.
	 * @param string $cif     Tax number of the supplier.
	 * @param string $first   Description of its first concept.
	 * @param string $second  Description of its second concept.
	 * @return array<string,mixed>
	 */
	private function supplier( $name, $cif, $first, $second ) {
		return array(
			'proveedor' => $name,
			'cif'       => $cif,
			'email'     => 'contacto@example.es',
			'telefono'  => '922123456',
			'bruto'     => '4500',
			'igic'      => '315',
			'irpf'      => '0',
			'total'     => '4815',
			'conceptos' => array(
				array(
					'concepto' => $first,
					'cantidad' => '2',
					'unitario' => '1500',
					'total'    => '3000',
				),
				array(
					'concepto' => $second,
					'cantidad' => '3',
					'unitario' => '500',
					'total'    => '1500',
				),
			),
		);
	}

	/**
	 * The certificate prints who certifies, the fixed HACE CONSTAR wording and
	 * the list of what the person took part in.
	 */
	public function test_haceconstar_layout_prints_the_certification_and_its_list() {
		$pdf = $this->render(
			'haceconstar.odt',
			'haceconstar',
			'Certificado de participación',
			array(
				'firmante'        => 'Ivonne Piñero Montesdeoca',
				'cargo'           => 'RESPONSABLE DEL SERVICIO DE ORDENACIÓN DE LAS ENSEÑANZAS Y EDUCACIÓN DE PERSONAS ADULTAS',
				'tratamiento'     => 'Doña',
				'nombre_completo' => 'Beatriz Oliver Taño',
				'dni'             => '12345678A',
				'participaciones' => '<ul><li>Comisión de evaluación del programa de innovación educativa.</li>'
					. '<li>Tribunal de selección de materiales didácticos.</li></ul>',
				'lugar_firma'     => 'Santa Cruz de Tenerife',
			)
		);

		$this->assertDrawn( $pdf, 'IVONNE PIÑERO MONTESDEOCA', 'The signer should be printed in upper case, as ope=utf8,upper asks.' );
		$this->assertDrawn( $pdf, 'RESPONSABLE DEL SERVICIO DE ORDENACIÓN', 'The office of the signer should be merged.' );
		$this->assertDrawn( $pdf, 'HACE CONSTAR que según los datos que obran', 'The fixed certification wording should be printed.' );
		$this->assertDrawn( $pdf, 'Doña BEATRIZ OLIVER TAÑO', 'The courtesy title and the name should be merged, the name in upper case.' );
		$this->assertDrawn( $pdf, 'con DNI n.º 12345678A', 'The identity number should be merged.' );
		$this->assertDrawn( $pdf, 'Comisión de evaluación del programa', 'The rich list of participations should be merged.' );
		$this->assertDrawn( $pdf, '•', 'The rich list should be drawn as a bulleted list.' );
		$this->assertDrawn( $pdf, 'Y para que conste, firmo la presente.', 'The fixed closing should be printed.' );
		$this->assertDrawn( $pdf, 'En Santa Cruz de Tenerife, a la fecha de la firma electrónica', 'The place of signature should be merged into the closing line.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The meeting call prints its fixed letter, the indented where-and-when
	 * block and the agenda.
	 */
	public function test_convocatoriareunion_layout_prints_the_call_and_its_agenda() {
		$pdf = $this->render(
			'convocatoriareunion.odt',
			'convocatoriareunion',
			'Convocatoria de reunión de coordinación',
			array(
				'motivo_reunion' => 'de coordinación de centros de referencia',
				'area'           => 'Área de Tecnología Educativa',
				'convocado'      => 'la persona responsable de las TIC',
				'tipo_reunion'   => 'telemática',
				'lugar'          => 'Videoconferencia (se enviará enlace por correo electrónico)',
				'dia'            => '2025-03-15',
				'horario'        => 'de 10:00 a 12:00 horas',
				'orden_del_dia'  => '<ul><li>Bienvenida y presentación de los asistentes.</li>'
					. '<li>Planificación de las jornadas de formación del profesorado.</li>'
					. '<li>Ruegos y preguntas.</li></ul>',
			)
		);

		$this->assertDrawn( $pdf, 'Convocatoria de reunión de coordinación de centros de referencia', 'The heading should be the fixed wording followed by the reason.' );
		$this->assertDrawn( $pdf, 'Estimado Director, estimada Directora', 'The fixed salutation should be printed.' );
		$this->assertDrawn( $pdf, 'Desde el Área de Tecnología Educativa', 'The calling area should be merged.' );
		$this->assertDrawn( $pdf, 'La Persona Responsable De Las Tic', 'The person called should be merged in title case, as ope=utf8,upperw asks.' );
		$this->assertDrawn( $pdf, 'a la reunión telemática', 'The kind of meeting should be merged.' );
		$this->assertDrawn( $pdf, 'Lugar: Videoconferencia', 'The place of the meeting should be merged.' );
		$this->assertDrawn( $pdf, 'Horario: de 10:00 a 12:00 horas', 'The time of the meeting should be merged.' );
		$this->assertDrawn( $pdf, 'Con el siguiente orden del día', 'The fixed lead-in of the agenda should be printed.' );
		$this->assertDrawn( $pdf, 'Bienvenida y presentación de los asistentes', 'The rich agenda should be merged.' );
		$this->assertDrawn( $pdf, 'Se ruega trasladar esta información', 'The fixed closing should be printed.' );
		$this->assertDrawn( $pdf, 'EL RESPONSABLE DEL SERVICIO DE ORDENACIÓN', 'The signing office should close the letter.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * The travel authorisation prints its heading, the event table and one row
	 * per traveller.
	 */
	public function test_autorizacionviaje_layout_prints_the_event_table_and_every_traveller() {
		$pdf = $this->render(
			'autorizacionviaje.odt',
			'autorizacionviaje',
			'Autorización de viaje a Madrid',
			array(
				'lugar'               => 'Madrid',
				'fecha_evento_inicio' => '2025-03-10',
				'fecha_evento_fin'    => '2025-03-12',
				'invitante'           => 'Ministerio de Educación, Formación Profesional y Deportes',
				'temas'               => '<p>Reunión de coordinación interterritorial sobre programas de innovación educativa.</p>',
				'pagador'             => 'Consejería de Educación, Formación Profesional, Actividad Física y Deportes',
			),
			array(
				'asistentes' => array(
					array(
						'apellido1' => 'García',
						'apellido2' => 'Hernández',
						'nombre'    => 'María del Carmen',
					),
					array(
						'apellido1' => 'Rodríguez',
						'apellido2' => 'Pérez',
						'nombre'    => 'Juan Antonio',
					),
				),
			)
		);

		$this->assertDrawn( $pdf, 'AUTORIZACIÓN - VIAJE', 'The fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'TÍTULO', 'The event table should carry its fixed labels.' );
		$this->assertDrawn( $pdf, 'AUTORIZACIÓN DE VIAJE A MADRID', 'The event title should be printed in upper case in the table.' );
		$this->assertDrawn( $pdf, 'MADRID', 'The place of the event should be printed in upper case in the table.' );
		$this->assertDrawn( $pdf, 'Desde Ministerio de Educación', 'The inviting body should be merged.' );
		// The month name comes from strftime under the LC_TIME locale, which a
		// container without es_ES falls back to English for. The day and the
		// year are what the frm parameters of the ODT are being checked for.
		$this->assertDrawnMatches( $pdf, '/del 10 de \\w+ de 2025 al 12 de \\w+ de 2025\\./u', 'The dates should be spelled out as the frm parameters of the ODT ask.' );
		$this->assertDrawn( $pdf, 'FECHA: Del 2025-03-10 al 2025-03-12', 'The table prints the raw dates, because the ODT gives those two tags no frm.' );
		$this->assertDrawn( $pdf, 'Reunión de coordinación interterritorial', 'The rich list of topics should be merged.' );
		$this->assertDrawn( $pdf, 'Apellido 1', 'The travellers table should carry its fixed headings.' );

		foreach ( array( 'García', 'Rodríguez', 'Hernández', 'Pérez', 'María Del Carmen', 'Juan Antonio' ) as $part ) {
			$this->assertDrawn( $pdf, $part, 'Every traveller should get a row of its own.' );
		}
		$this->assertSame( 2, $this->count_drawn( $pdf, array( 'García', 'Rodríguez' ) ), 'Two travellers should draw two rows.' );

		$this->assertDrawn( $pdf, 'Los gastos de desplazamiento, alojamiento y dietas serán a cargo de Consejería', 'The paying body should be merged.' );
		$this->assertDrawn( $pdf, 'CONFORME CON LO QUE SE PROPONE AUTORIZO', 'The fixed approval line should be printed.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * How many text operations of the PDF are one of the given strings.
	 *
	 * Counting the operations rather than searching the joined text is what
	 * tells one row per concept from a repeater that merged every concept into
	 * a single cell.
	 *
	 * @param string   $pdf      Raw PDF bytes.
	 * @param string[] $expected Strings to count.
	 * @return int
	 */
	private function count_drawn( $pdf, array $expected ) {
		$found = 0;

		foreach ( Documentate_Pdf_Test_Helper::texts( $pdf ) as $text ) {
			if ( in_array( trim( $text ), $expected, true ) ) {
				++$found;
			}
		}

		return $found;
	}
}
