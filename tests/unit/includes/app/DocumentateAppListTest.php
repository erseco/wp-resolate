<?php
/**
 * Tests for the document trays of the front-end application.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_App_List
 * @covers Documentate_App_Tray
 * @covers Documentate_App_List_Row
 * @covers Documentate_App_Shell
 */
class DocumentateAppListTest extends WP_UnitTestCase {

	/**
	 * Administración user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Gestión documental user ID.
	 *
	 * @var int
	 */
	private $management_id;

	/**
	 * Área user ID.
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Category of the área.
	 *
	 * @var int
	 */
	private $cat_a;

	/**
	 * Category of another área.
	 *
	 * @var int
	 */
	private $cat_b;

	/**
	 * Document type that goes through gestión documental.
	 *
	 * @var int
	 */
	private $type_id;

	/**
	 * Documents of the fixture, by key.
	 *
	 * @var array<string,int>
	 */
	private $docs = array();

	/**
	 * Two áreas, three roles and one document per status.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();
		( new Documentate_App() )->ensure_page();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create(
			array(
				'role' => 'author',
				'display_name' => 'Ana Área',
			)
		);

		$a = wp_insert_term( 'Departamento de Proyectos ' . uniqid(), 'category' );
		$b = wp_insert_term( 'Subdirección ' . uniqid(), 'category' );
		$this->cat_a = (int) $a['term_id'];
		$this->cat_b = (int) $b['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_a );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_a );

		$this->type_id = $this->create_type();

		$this->docs['borrador'] = $this->create_document( 'Jornadas de competencia digital', 'Jornadas digitales', $this->cat_a, 'draft' );
		$this->docs['devuelto'] = $this->create_document( 'Certificación del tribunal', 'Certificación tribunal', $this->cat_a, 'draft' );
		$this->docs['gestion'] = $this->create_document( 'Listado definitivo del piloto', 'Listado piloto', $this->cat_b, 'en_gestion' );
		$this->docs['revision'] = $this->create_document( 'Formación del profesorado', 'Formación profesorado', $this->cat_a, 'pending' );
		$this->docs['aprobado'] = $this->create_document( 'Bases del programa piloto', 'Bases piloto', $this->cat_b, 'publish' );

		Documentate_Document_Data::mark_returned(
			$this->docs['devuelto'],
			'Falta el anexo firmado por la dirección',
			'gestion',
			'area',
			$this->management_id
		);
	}

	/**
	 * Reset the request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * A document type with a gestión field and a prefix.
	 *
	 * @return int Term ID.
	 */
	private function create_type() {
		$term = wp_insert_term( 'Resolución lista ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, Documentate_Document_Data::TERM_META_PREFIX, 'RES' );

		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'title' => 'Objeto',
						'type' => 'text',
					),
					array(
						'name' => 'numero_resolucion',
						'slug' => 'numero_resolucion',
						'title' => 'Nº de resolución',
						'type' => 'text',
						'rol' => 'gestion',
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'lista.odt',
					'hash' => md5( 'lista' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		return $term_id;
	}

	/**
	 * Create a document with an internal name.
	 *
	 * @param string $title Official title.
	 * @param string $name Internal name.
	 * @param int    $cat_id Category term ID.
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_document( $title, $name, $cat_id, $status ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( $this->type_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		Documentate_Document_Data::save_internal_name( $post_id, $name );
		wp_set_current_user( 0 );

		return (int) $post_id;
	}

	/**
	 * Render a tray as a user.
	 *
	 * @param int    $user_id User to render as.
	 * @param array  $args    Query arguments (bandeja, estado, area).
	 * @return string HTML.
	 */
	private function render( $user_id, array $args = array() ) {
		wp_set_current_user( $user_id );
		$_GET = $args;

		return Documentate_App_List::render();
	}

	/**
	 * The label of the action offered by the row of a document.
	 *
	 * @param string $html   Rendered tray.
	 * @param string $name Short name of the document.
	 * @return string Empty when the row is not there.
	 */
	private function row_action( $html, $name ) {
		$home_url = strpos( $html, $name );
		if ( false === $home_url ) {
			return '';
		}

		// The action link closes the row, so the first one after the name is its own.
		return preg_match( '/<a class="dcta-mini"[^>]*>([^<]+)<\/a>/', substr( $html, $home_url ), $matches )
			? $matches[1]
			: '';
	}

	/**
	 * The tray each role lands on.
	 */
	public function test_each_role_lands_on_its_own_tray() {
		wp_set_current_user( $this->area_id );
		$this->assertSame( 'mis', Documentate_App_List::current_tray() );

		wp_set_current_user( $this->management_id );
		$this->assertSame( 'mis', Documentate_App_List::current_tray() );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 'todos', Documentate_App_List::current_tray() );

		$_GET['bandeja'] = 'revisar';
		$this->assertSame( 'todos', Documentate_App_List::current_tray(), 'Administración has no gestión tray.' );
	}

	/**
	 * The tabs of each role, and the badge on the one that needs attention.
	 */
	public function test_tabs_and_badges_per_role() {
		wp_set_current_user( $this->area_id );
		$sections = Documentate_App_Shell::sections();
		$this->assertSame( array( 'lista', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 'Mis documentos', $sections['lista']['tab'] );

		wp_set_current_user( $this->management_id );
		$sections = Documentate_App_Shell::sections();
		$this->assertSame( array( 'lista', 'revisar', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 1, $sections['revisar']['n'], 'One document waits in gestión.' );

		wp_set_current_user( $this->admin_id );
		$sections = Documentate_App_Shell::sections();
		// Same shape as gestión: the whole list first, then the review tray.
		$this->assertSame( array( 'lista', 'revision', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 1, $sections['revision']['n'], 'One document waits for approval.' );
		$this->assertSame( 'Todos los documentos', $sections['lista']['tab'] );
		// Document types and their templates are wp-admin work, not a tab.
		$this->assertStringNotContainsString( 'edit-tags.php', wp_json_encode( $sections ) );
	}

	/**
	 * "Mis documentos" keeps the scope rules and shows what is still ours.
	 */
	public function test_my_documents_keeps_the_scope() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'RES · Jornadas digitales', $html );
		$this->assertStringContainsString( 'Jornadas de competencia digital', $html, 'The official title is the second line.' );
		$this->assertStringContainsString( 'RES · Formación profesorado', $html );
		$this->assertStringNotContainsString( 'Listado piloto', $html, 'Another área is out of scope.' );
		$this->assertStringContainsString( 'Por enviar', $html );
		$this->assertStringContainsString( '3 documentos', $html );
	}

	/**
	 * A returned document is marked, tinted and offered for correction.
	 */
	public function test_a_returned_document_is_marked_and_correctable() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'dcta-estado-devuelto', $html );
		$this->assertStringContainsString( 'dcta-fila-devuelta', $html );
		$this->assertStringContainsString( 'Devuelto por gestión documental', $html );
		$this->assertStringContainsString( 'Falta el anexo firmado por la dirección', $html );
		$this->assertStringContainsString( 'Corregir', $html );
		$this->assertStringContainsString( ' (1 devuelto)', $html );
	}

	/**
	 * The área continues its drafts and only looks at what left its hands.
	 */
	public function test_the_area_continues_drafts_and_only_views_the_rest() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'Continuar', $html );
		$this->assertStringNotContainsString( 'Revisar', $html );
	}

	/**
	 * Gestión reviews every área, and the tray pre-selects "En gestión".
	 */
	public function test_the_management_tray_shows_every_area() {
		$html = $this->render( $this->management_id, array( 'bandeja' => 'revisar' ) );

		$this->assertStringContainsString( 'RES · Listado piloto', $html );
		$this->assertStringNotContainsString( 'Jornadas digitales', $html, 'Drafts stay with their área.' );
		$this->assertStringContainsString( 'Revisar', $html );
		$this->assertStringContainsString( 'Ana Área', $html, 'The review trays name the área and the person.' );
		$this->assertStringContainsString( '1 documento', $html );
	}

	/**
	 * The chips of the gestión tray narrow it down.
	 */
	public function test_the_chips_narrow_the_tray_down() {
		$html = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'estado' => 'publish',
			)
		);

		$this->assertStringContainsString( 'RES · Bases piloto', $html );
		$this->assertStringNotContainsString( 'RES · Listado piloto', $html );

		$all = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'estado' => 'todos',
			)
		);

		$this->assertStringContainsString( 'RES · Bases piloto', $all );
		$this->assertStringContainsString( 'RES · Listado piloto', $all );
	}

	/**
	 * A chip nothing would match is not drawn.
	 */
	public function test_chips_are_only_drawn_when_they_find_something() {
		$html = $this->render( $this->management_id, array( 'bandeja' => 'revisar' ) );

		$this->assertStringContainsString( '>Todos</a>', $html );
		$this->assertStringNotContainsString( 'estado=draft', $html, 'Drafts are not part of this tray.' );
		$this->assertStringNotContainsString( 'estado=archived', $html, 'Nothing in this tray is archived.' );
		// "Devuelto" is not a status but a mark, and it follows the document
		// wherever it was sent back to — including the drafts of the áreas.
		$this->assertStringContainsString( 'estado=devuelto', $html );

		Documentate_Document_Data::clear_returned( $this->docs['devuelto'] );
		$is_empty = $this->render( $this->management_id, array( 'bandeja' => 'revisar' ) );
		$this->assertStringNotContainsString( 'estado=devuelto', $is_empty, 'With nothing returned the chip is gone.' );
	}

	/**
	 * Administración sees everything, and its review tray only what waits.
	 */
	public function test_administration_sees_everything_and_reviews_the_pending() {
		$all = $this->render( $this->admin_id );
		$this->assertStringContainsString( 'RES · Jornadas digitales', $all );
		$this->assertStringContainsString( 'RES · Listado piloto', $all );
		$this->assertStringContainsString( '5 documentos', $all );

		$revision = $this->render( $this->admin_id, array( 'bandeja' => 'revision' ) );
		$this->assertStringContainsString( 'RES · Formación profesorado', $revision );
		$this->assertStringNotContainsString( 'RES · Jornadas digitales', $revision );
		$this->assertStringContainsString( 'Revisar', $revision );

		$this->assertSame(
			'Ver',
			$this->row_action( $all, 'RES · Listado piloto' ),
			'A document gestión has not finished with is not administración\'s to review yet.'
		);
	}

	/**
	 * A return lands on the tray, and the tray says it went through.
	 */
	public function test_the_tray_confirms_a_returned_document() {
		$html = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'devuelto' => '1',
			)
		);

		$this->assertStringContainsString( 'dcta-aviso-ok', $html );
		$this->assertStringContainsString( 'Documento devuelto con el motivo indicado.', $html );

		$error = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'error' => 'motivo',
			)
		);

		$this->assertStringContainsString( 'dcta-aviso-mal', $error );
		$this->assertStringContainsString( 'Para devolver un documento hay que decir por qué.', $error );
	}

	/**
	 * "Devueltos" means the same in every tray it is drawn in.
	 */
	public function test_the_returned_tile_counts_the_same_everywhere() {
		$doc = $this->create_document( 'Devuelto al área', 'Devuelto área', $this->cat_a, 'draft' );
		Documentate_Document_Data::mark_returned( $doc, 'Falta el número de expediente', 'administracion', 'area', $this->admin_id );

		$revision = $this->render( $this->admin_id, array( 'bandeja' => 'revision' ) );
		$all = $this->render( $this->admin_id, array( 'bandeja' => 'todos' ) );

		$counter = static function ( $html ) {
			preg_match( '/<b>(\d+)<\/b><span>Devueltos<\/span>/', $html, $m );

			return isset( $m[1] ) ? (int) $m[1] : -1;
		};

		$this->assertGreaterThan( 0, $counter( $all ) );
		$this->assertSame( $counter( $all ), $counter( $revision ), 'The tile promises the same set in both trays.' );

		// And the chip finds it: clicking it is how the count is read.
		$chip = $this->render(
			$this->admin_id,
			array(
				'bandeja' => 'revision',
				'estado' => 'devuelto',
			)
		);
		$this->assertStringContainsString( 'Devuelto área', $chip );
	}

	/**
	 * Each review tray leads with the figure it exists for.
	 */
	public function test_each_review_tray_accents_its_own_figure() {
		$management = $this->render( $this->management_id, array( 'bandeja' => 'revisar' ) );
		$this->assertMatchesRegularExpression(
			'/dcta-cifra-acento"><b>[^<]*<\/b><span>En gestión<\/span>/',
			$management,
			'Gestión is there for what is in gestión.'
		);

		$admin = $this->render( $this->admin_id, array( 'bandeja' => 'revision' ) );
		$this->assertMatchesRegularExpression(
			'/dcta-cifra-acento"><b>[^<]*<\/b><span>En revisión<\/span>/',
			$admin,
			'Administración is there for what waits for approval.'
		);
	}

	/**
	 * Administración narrows the trays by área.
	 */
	public function test_administration_filters_by_area() {
		$html = $this->render(
			$this->admin_id,
			array(
				'estado' => 'todos',
				'area' => (string) $this->cat_b,
			)
		);

		$this->assertStringContainsString( 'dcta-areas', $html );
		$this->assertStringContainsString( 'RES · Listado piloto', $html );
		$this->assertStringNotContainsString( 'RES · Jornadas digitales', $html );
	}

	/**
	 * The área filter keeps the page reference a GET form would throw away.
	 */
	public function test_the_area_filter_keeps_the_page_reference() {
		$html = $this->render( $this->admin_id, array( 'estado' => 'todos' ) );

		$pairs = array();
		wp_parse_str( (string) wp_parse_url( Documentate_App_Shell::page_url(), PHP_URL_QUERY ), $pairs );

		$this->assertNotEmpty( $pairs, 'The tests run on plain permalinks: the page travels in the query string.' );
		foreach ( $pairs as $name => $value ) {
			$this->assertStringContainsString(
				'<input type="hidden" name="' . $name . '" value="' . $value . '" />',
				$html
			);
		}
	}

	/**
	 * A tray longer than one page says how much of it is on screen.
	 */
	public function test_a_tray_longer_than_one_page_says_so() {
		add_filter(
			'found_posts',
			static function () {
				return 412;
			}
		);

		$html = $this->render( $this->admin_id, array( 'estado' => 'todos' ) );

		$this->assertStringContainsString( 'mostrando 5 de 412 documentos · afina con los filtros', $html );
		// The quick filter only sees the rows on screen: without the real
		// total it would answer "0 de 5" for the other 407.
		$this->assertStringContainsString( 'data-dcta-pie-total="412"', $html );
	}

	/**
	 * An approved document is opened straight at its export block.
	 */
	public function test_an_approved_document_links_to_its_pdf() {
		$html = $this->render( $this->admin_id );

		$this->assertStringContainsString( 'Ver PDF', $html );
		$this->assertStringContainsString( '#exportar', $html );
	}

	/**
	 * A document with a file carries the paper clip.
	 */
	public function test_a_document_with_a_file_shows_the_clip() {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $this->docs['borrador'],
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $this->docs['borrador'], Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment ) );

		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'dcta-doc-adjunto', $html );
		$this->assertStringContainsString( 'Con fichero adjunto', $html );
	}

	/**
	 * Every tray says something of its own when it is empty.
	 */
	public function test_empty_trays_explain_themselves() {
		$is_empty = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'estado' => 'archived',
			)
		);

		$this->assertStringContainsString( 'dcta-vacio', $is_empty );
		$this->assertStringContainsString( 'No hay documentos pendientes de revisar.', $is_empty );

		$without_scope = self::factory()->user->create( array( 'role' => 'author' ) );
		$html = $this->render( $without_scope );
		$this->assertStringContainsString( 'no tiene un ámbito asignado', $html );
	}

	/**
	 * The tray of the área shows its own drafts; the review trays never do.
	 */
	public function test_query_arguments_per_tray() {
		wp_set_current_user( $this->management_id );

		$mine = Documentate_App_List::query_args( 'mis', '', 0 );
		$this->assertContains( 'draft', $mine['post_status'] );
		$this->assertArrayHasKey( 'tax_query', $mine, 'The own tray is scoped.' );

		$to_review = Documentate_App_List::query_args( 'revisar', '', 0 );
		$this->assertNotContains( 'draft', $to_review['post_status'] );
		$this->assertArrayNotHasKey( 'tax_query', $to_review, 'The review tray covers every área.' );

		$returned = Documentate_App_List::query_args( 'revisar', 'devuelto', 0 );
		$this->assertSame( Documentate_Document_Data::META_RETURNED, $returned['meta_key'] );
		$this->assertSame( 'EXISTS', $returned['meta_compare'] );
		// A return travels with the document: the most common one lands in a
		// draft, which the review trays would otherwise never show, so the
		// "Devueltos" tile and chip would count something else than they say.
		$this->assertContains( 'draft', $returned['post_status'], 'The returned filter reaches across statuses.' );

		$area = Documentate_App_List::query_args( 'todos', 'pending', $this->cat_b );
		$this->assertSame( 'pending', $area['post_status'] );
		$this->assertSame( array( $this->cat_b ), $area['tax_query'][0]['terms'] );
	}

	/**
	 * The back link of a document names the tray it was opened from.
	 */
	public function test_the_back_link_names_the_tray() {
		wp_set_current_user( $this->management_id );

		$_GET = array( 'bandeja' => 'revisar' );
		$this->assertStringContainsString( '← Para revisar', Documentate_App_Shell::back_link() );

		$_GET = array();
		$this->assertStringContainsString( '← Mis documentos', Documentate_App_Shell::back_link() );

		wp_set_current_user( $this->admin_id );
		$this->assertStringContainsString( '← Todos los documentos', Documentate_App_Shell::back_link() );
	}

	/**
	 * The quick filter is offered on every tray and carries what to match on.
	 */
	public function test_the_tray_offers_a_quick_filter() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'data-dcta-busqueda', $html );
		$this->assertStringContainsString( 'placeholder="Filtrar…"', $html );
		$this->assertStringContainsString( 'Filtrar los documentos de la lista', $html );

		// Hidden until the script shows it: without JavaScript it would do nothing.
		$this->assertMatchesRegularExpression( '/<span class="dcta-busqueda" data-dcta-busqueda hidden>/', $html );
	}

	/**
	 * Every row carries the text the quick filter matches: name, official
	 * title, type and status.
	 */
	public function test_every_row_carries_the_text_the_filter_matches() {
		$html = $this->render( $this->area_id );

		$this->assertMatchesRegularExpression(
			'/data-dcta-texto="[^"]*Jornadas digitales[^"]*Jornadas de competencia digital[^"]*Borrador[^"]*"/u',
			$html
		);
	}

	/**
	 * The footer publishes the documents the tray holds, not the rows drawn.
	 */
	public function test_the_footer_publishes_the_tray_total() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString( 'data-dcta-pie-total="3"', $html );
		$this->assertStringContainsString( '>3 documentos</div>', $html );
	}

	/**
	 * The footer is a live region: it is the only thing that changes as the
	 * quick filter narrows the list, and hidden rows are hidden from assistive
	 * technology too.
	 */
	public function test_the_footer_is_announced_as_it_changes() {
		$html = $this->render( $this->area_id );

		$this->assertStringContainsString(
			'<div class="dcta-tabla-pie" role="status" data-dcta-pie',
			$html
		);
	}

	/**
	 * Outside "mis documentos" the rows show the área and the person, so the
	 * filter has to match them: they are what a reviewer types.
	 */
	public function test_the_filter_text_carries_the_area_and_the_person() {
		$html = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'estado' => 'todos',
			)
		);

		$area = get_term( $this->cat_a )->name;
		$this->assertStringContainsString( $area . ' · Ana Área', $html, 'The row draws them.' );
		$this->assertMatchesRegularExpression(
			'/data-dcta-texto="[^"]*' . preg_quote( $area, '/' ) . ' Ana Área[^"]*"/u',
			$html
		);

		// "Mis documentos" draws neither, so neither belongs in its text.
		$this->assertDoesNotMatchRegularExpression(
			'/data-dcta-texto="[^"]*Ana Área[^"]*"/u',
			$this->render( $this->area_id ),
			'The own tray shows no person, so the filter must not carry one.'
		);
	}

	/**
	 * A document returned to gestión documental keeps its "En gestión" chip,
	 * so only the return line puts the word "Devuelto" within reach of the
	 * filter — with the reason, which is on screen as well.
	 */
	public function test_the_filter_text_carries_the_return_line() {
		Documentate_Document_Data::mark_returned(
			$this->docs['gestion'],
			'Falta la firma de la persona titular',
			'administracion',
			'gestion',
			$this->admin_id
		);

		$html = $this->render(
			$this->management_id,
			array(
				'bandeja' => 'revisar',
				'estado' => 'todos',
			)
		);

		$this->assertMatchesRegularExpression(
			'/data-dcta-texto="[^"]*En gestión Devuelto por administración[^"]*Falta la firma de la persona titular[^"]*"/u',
			$html
		);
	}
}
