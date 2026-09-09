<?php
/**
 * Tests for the document list of the front-end application.
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
	 * Revisión user ID.
	 *
	 * @var int
	 */
	private $management_id;

	/**
	 * Jefatura de servicio user ID (editor).
	 *
	 * @var int
	 */
	private $head_id;

	/**
	 * Category of the service, parent of both departments.
	 *
	 * @var int
	 */
	private $cat_service;

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
	 * Document type that goes through revisión.
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
		// Revisión and jefatura are appointed account by account: the plugin
		// keeps the capabilities in roles of their own and never grants them
		// to the stock editor role, so the accounts are given them here the
		// way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->head_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new WP_User( $this->head_id ) )->add_cap( Documentate_Roles::CAP_HEAD );
		$this->area_id = self::factory()->user->create(
			array(
				'role' => 'author',
				'display_name' => 'Ana Área',
			)
		);

		// The scope is a tree: the área sits on its department, whoever
		// reviews and approves on the service above both departments.
		$service = wp_insert_term( 'Servicio ' . uniqid(), 'category' );
		$this->cat_service = (int) $service['term_id'];
		$a = wp_insert_term( 'Departamento de Proyectos ' . uniqid(), 'category', array( 'parent' => $this->cat_service ) );
		$b = wp_insert_term( 'Subdirección ' . uniqid(), 'category', array( 'parent' => $this->cat_service ) );
		$this->cat_a = (int) $a['term_id'];
		$this->cat_b = (int) $b['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_service );
		update_user_meta( $this->head_id, 'documentate_scope_term_id', $this->cat_service );
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
	 * @param int    $author Author, the área by default.
	 * @return int
	 */
	private function create_document( $title, $name, $cat_id, $status, $author = 0 ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => $status,
				'post_author' => $author > 0 ? $author : $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( $this->type_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		Documentate_Document_Data::save_internal_name( $post_id, $name );
		wp_set_current_user( 0 );

		return (int) $post_id;
	}

	/**
	 * Render the list as a user.
	 *
	 * @param int   $user_id User to render as.
	 * @param array $args    Query arguments (estado, area).
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
	 * @param string $html Rendered list.
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
	 * The list opens on the chip that holds what waits for each rol.
	 */
	public function test_each_role_lands_on_its_own_chip() {
		wp_set_current_user( $this->area_id );
		$this->assertSame( 'draft', Documentate_App_Tray::default_status(), 'The área lands on what it has still to send.' );

		wp_set_current_user( $this->management_id );
		$this->assertSame( 'en_gestion', Documentate_App_Tray::default_status(), 'Revisión lands on what waits for review.' );

		wp_set_current_user( $this->head_id );
		$this->assertSame( 'pending', Documentate_App_Tray::default_status(), 'The head of service lands on what waits for approval.' );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 'pending', Documentate_App_Tray::default_status() );

		// And that is the chip the list draws as the active one, with the
		// documents of that status alone.
		$html = $this->render( $this->head_id );
		$this->assertMatchesRegularExpression( '/class="dcta-fchip dcta-fchip-on dcta-fchip-mio"[^>]*>En aprobación/', $html );
		$this->assertStringContainsString( 'RES · Formación profesorado', $html );
		$this->assertStringNotContainsString( 'RES · Jornadas digitales', $html );
	}

	/**
	 * Each chip carries what it holds, and "Todos" the whole list.
	 */
	public function test_the_chips_carry_their_counts() {
		$html = $this->render( $this->management_id );

		$this->assertMatchesRegularExpression( '/>Todos<span class="dcta-fchip-n">5<\/span>/', $html, 'Five documents in the ámbito.' );
		$this->assertMatchesRegularExpression( '/>Por enviar<span class="dcta-fchip-n">2<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/>En revisión<span class="dcta-fchip-n">1<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/>En aprobación<span class="dcta-fchip-n">1<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/>Devuelto<span class="dcta-fchip-n">1<\/span>/', $html );

		// An área counts its own ámbito, not the site.
		$this->assertMatchesRegularExpression( '/>Todos<span class="dcta-fchip-n">3<\/span>/', $this->render( $this->area_id ) );
	}

	/**
	 * The tabs of each role: the list, and the new document.
	 */
	public function test_tabs_per_role() {
		wp_set_current_user( $this->area_id );
		$sections = Documentate_App_Shell::sections();
		$this->assertSame( array( 'lista', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 'Mis documentos', $sections['lista']['tab'] );

		wp_set_current_user( $this->management_id );
		$sections = Documentate_App_Shell::sections();
		// What waits for a rol is a chip of the list, not a tab of its own.
		$this->assertSame( array( 'lista', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 'Todos los documentos', $sections['lista']['tab'] );

		wp_set_current_user( $this->head_id );
		$this->assertSame( array( 'lista', 'nuevo' ), array_keys( Documentate_App_Shell::sections() ) );

		wp_set_current_user( $this->admin_id );
		$sections = Documentate_App_Shell::sections();
		$this->assertSame( array( 'lista', 'nuevo' ), array_keys( $sections ) );
		$this->assertSame( 'Todos los documentos', $sections['lista']['tab'] );
		// Document types and their templates are wp-admin work, not a tab.
		$this->assertStringNotContainsString( 'edit-tags.php', wp_json_encode( $sections ) );
	}

	/**
	 * "Mis documentos" keeps the scope rules and shows what is still ours.
	 */
	public function test_my_documents_keeps_the_scope() {
		$html = $this->render( $this->area_id, array( 'estado' => 'todos' ) );

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
		$this->assertStringContainsString( 'Devuelto por revisión', $html );
		$this->assertStringContainsString( 'Falta el anexo firmado por la dirección', $html );
		$this->assertStringContainsString( 'Editar', $html );
	}

	/**
	 * The área edits its drafts and only looks at what left its hands.
	 *
	 * The row action has two labels and no more: `Editar` when it opens the
	 * editor, `Ver` when it opens the document.
	 */
	public function test_the_area_edits_drafts_and_only_views_the_rest() {
		$html = $this->render( $this->area_id, array( 'estado' => 'todos' ) );

		$this->assertSame( 'Editar', $this->row_action( $html, 'RES · Jornadas digitales' ) );
		$this->assertSame(
			'Ver',
			$this->row_action( $html, 'RES · Formación profesorado' ),
			'What already left the área is not the área\'s to edit.'
		);
	}

	/**
	 * Revisión reads every área of its scope, and opens on what waits for it.
	 */
	public function test_the_management_list_shows_every_area() {
		$html = $this->render( $this->management_id );

		$this->assertStringContainsString( 'RES · Listado piloto', $html );
		$this->assertStringNotContainsString( 'Jornadas digitales', $html, 'The chip opens on what waits for review.' );
		$this->assertSame( 'Editar', $this->row_action( $html, 'RES · Listado piloto' ) );
		$this->assertStringContainsString( 'Ana Área', $html, 'Reading several áreas, the rows name the área and the person.' );
		$this->assertStringContainsString( '1 documento', $html );
	}

	/**
	 * A reviewer whose scope is one department only sees that department.
	 */
	public function test_the_list_stops_at_the_scope() {
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_a );

		$html = $this->render( $this->management_id, array( 'estado' => 'todos' ) );
		$this->assertStringNotContainsString( 'Listado piloto', $html, 'Another department is out of scope.' );
		$this->assertStringContainsString( 'RES · Jornadas digitales', $html );
		$this->assertStringNotContainsString( 'Bases piloto', $html );
	}

	/**
	 * The head of service opens what waits for approval, and edits it.
	 */
	public function test_the_head_opens_on_what_waits_for_approval() {
		$html = $this->render( $this->head_id );

		$this->assertStringContainsString( 'RES · Formación profesorado', $html );
		$this->assertSame( 'Editar', $this->row_action( $html, 'RES · Formación profesorado' ) );
		$this->assertStringNotContainsString( 'Listado piloto', $html, 'What is still in revisión is not theirs yet.' );

		$html = $this->render( $this->head_id, array( 'estado' => 'todos' ) );
		$this->assertStringContainsString( '<h1 class="dcta-h1">Todos los documentos</h1>', $html );
		$this->assertSame( 'Ver', $this->row_action( $html, 'RES · Listado piloto' ), 'Revisión has not finished with it.' );
	}

	/**
	 * The chips narrow the list down.
	 */
	public function test_the_chips_narrow_the_list_down() {
		$html = $this->render( $this->management_id, array( 'estado' => 'publish' ) );

		$this->assertStringContainsString( 'RES · Bases piloto', $html );
		$this->assertStringNotContainsString( 'RES · Listado piloto', $html );

		$all = $this->render( $this->management_id, array( 'estado' => 'todos' ) );

		$this->assertStringContainsString( 'RES · Bases piloto', $all );
		$this->assertStringContainsString( 'RES · Listado piloto', $all );
	}

	/**
	 * A chip nothing would match is not drawn.
	 */
	public function test_chips_are_only_drawn_when_they_find_something() {
		$html = $this->render( $this->management_id );

		$this->assertStringContainsString( '>Todos<span class="dcta-fchip-n">', $html );
		$this->assertStringContainsString( 'estado=draft', $html, 'Two drafts wait in the ámbito.' );
		$this->assertStringNotContainsString( 'estado=archived', $html, 'Nothing is archived.' );
		// "Devuelto" is not a status but a mark, and it follows the document
		// wherever it was sent back to — including the drafts of the áreas.
		$this->assertStringContainsString( 'estado=devuelto', $html );

		Documentate_Document_Data::clear_returned( $this->docs['devuelto'] );
		$is_empty = $this->render( $this->management_id );
		$this->assertStringNotContainsString( 'estado=devuelto', $is_empty, 'With nothing returned the chip is gone.' );
	}

	/**
	 * Administración sees everything, and opens on what waits for approval.
	 */
	public function test_administration_sees_everything_and_opens_on_the_pending() {
		$all = $this->render( $this->admin_id, array( 'estado' => 'todos' ) );
		$this->assertStringContainsString( 'RES · Jornadas digitales', $all );
		$this->assertStringContainsString( 'RES · Listado piloto', $all );
		$this->assertStringContainsString( '5 documentos', $all );

		$pending = $this->render( $this->admin_id );
		$this->assertStringContainsString( 'RES · Formación profesorado', $pending );
		$this->assertStringNotContainsString( 'RES · Jornadas digitales', $pending );
		$this->assertSame( 'Editar', $this->row_action( $pending, 'RES · Formación profesorado' ) );

		$this->assertSame(
			'Ver',
			$this->row_action( $all, 'RES · Listado piloto' ),
			'A document revisión has not finished with is not administración\'s to review yet.'
		);
	}

	/**
	 * A return lands on the list, and the list says it went through.
	 */
	public function test_the_list_confirms_a_returned_document() {
		$html = $this->render( $this->management_id, array( 'devuelto' => '1' ) );

		$this->assertStringContainsString( 'dcta-aviso-ok', $html );
		$this->assertStringContainsString( 'Documento devuelto con el motivo indicado.', $html );

		$error = $this->render( $this->management_id, array( 'error' => 'motivo' ) );

		$this->assertStringContainsString( 'dcta-aviso-mal', $error );
		$this->assertStringContainsString( 'Para devolver un documento hay que decir por qué.', $error );
	}

	/**
	 * The "Devuelto" chip counts the returns of every status, and finds them.
	 */
	public function test_the_returned_chip_reaches_across_statuses() {
		$doc = $this->create_document( 'Devuelto al área', 'Devuelto área', $this->cat_a, 'draft' );
		Documentate_Document_Data::mark_returned( $doc, 'Falta el número de expediente', 'administracion', 'area', $this->admin_id );

		$html = $this->render( $this->admin_id );
		$this->assertMatchesRegularExpression( '/>Devuelto<span class="dcta-fchip-n">2<\/span>/', $html );

		// And the chip finds them: clicking it is how the count is read.
		$chip = $this->render( $this->admin_id, array( 'estado' => 'devuelto' ) );
		$this->assertStringContainsString( 'Devuelto área', $chip );
		$this->assertStringContainsString( 'Certificación tribunal', $chip );
	}

	/**
	 * Revisión and jefatura narrow the list by área, inside their ámbito.
	 */
	public function test_the_reviewing_roles_filter_by_area() {
		foreach ( array( $this->management_id, $this->head_id ) as $user_id ) {
			$html = $this->render( $user_id, array( 'area' => (string) $this->cat_b, 'estado' => 'todos' ) );

			$this->assertStringContainsString( 'RES · Bases piloto', $html, 'Área B is in their ámbito.' );
			$this->assertStringNotContainsString( 'RES · Jornadas digitales', $html, 'Área A is filtered out.' );
			$this->assertStringContainsString( 'id="dcta-area"', $html );
			// The select rides at the top right of the heading, out of the
			// chip row, and is the whole interaction: the script submits on
			// change and hides the button behind it.
			$this->assertStringContainsString( 'data-dcta-areas="1"', $html );
			$this->assertMatchesRegularExpression(
				'/<div class="dcta-cabecera">.*?<form class="dcta-areas".*?<\/div>\s*<div class="dcta-filtros">/s',
				$html,
				'The select closes the heading, before the chip row.'
			);
			$this->assertStringContainsString( 'class="screen-reader-text" for="dcta-area"', $html, 'No visible label: the options say what it filters.' );
		}

		// The área has a single category: there is nothing to narrow.
		$this->assertStringNotContainsString( 'id="dcta-area"', $this->render( $this->area_id ) );
	}

	/**
	 * "Mis documentos" holds what this person wrote, whatever became of it.
	 *
	 * It is the one chip that does not narrow by status, and the one an área
	 * never gets: its whole list is its own documents already, and the
	 * heading says so.
	 */
	public function test_the_reviewing_roles_filter_their_own_documents() {
		$mine = $this->create_document( 'Instrucciones de la jefatura', 'Instrucciones jefatura', $this->cat_b, 'pending', $this->head_id );

		$html = $this->render( $this->head_id, array( 'estado' => 'mios' ) );
		$this->assertMatchesRegularExpression( '/class="dcta-fchip dcta-fchip-on"[^>]*>Mis documentos<span class="dcta-fchip-n">1<\/span>/', $html );
		$this->assertStringContainsString( 'RES · Instrucciones jefatura', $html );
		$this->assertStringNotContainsString( 'RES · Jornadas digitales', $html, 'Written by the área, not by them.' );

		// It reaches across statuses, and rides right after "Todos".
		$args = Documentate_App_List::query_args( 'mios', 0 );
		$this->assertSame( $this->head_id, $args['author'] );
		$this->assertContains( 'draft', $args['post_status'] );
		$this->assertLessThan( strpos( $html, 'estado=mios' ), strpos( $html, 'estado=todos' ) );
		$this->assertLessThan( strpos( $html, 'estado=devuelto' ), strpos( $html, 'estado=mios' ) );

		// Unlike the status chips, it is drawn even at zero: revisión has
		// written none of these, and still has to be able to find the filter.
		$this->assertMatchesRegularExpression(
			'/>Mis documentos<span class="dcta-fchip-n">0<\/span>/',
			$this->render( $this->management_id )
		);

		// The área is offered no such chip, and asking for it by hand lands
		// on the chip its rol opens on.
		$area_list = $this->render( $this->area_id, array( 'estado' => 'mios' ) );
		$this->assertStringNotContainsString( 'estado=mios', $area_list );
		$this->assertSame( 'draft', Documentate_App_Tray::current_status() );

		wp_delete_post( $mine, true );
	}

	/**
	 * A category outside the ámbito is ignored, not obeyed.
	 */
	public function test_the_area_filter_never_reaches_past_the_scope() {
		$outside = wp_insert_term( 'Otro servicio ' . uniqid(), 'category' );
		$outside_id = (int) $outside['term_id'];
		$foreign = $this->create_document( 'De otro servicio', 'Otro servicio doc', $outside_id, 'en_gestion' );

		$_GET = array( 'area' => (string) $outside_id );
		wp_set_current_user( $this->management_id );
		$this->assertSame( 0, Documentate_App_Tray::current_area(), 'A term outside the ámbito is no filter at all.' );

		$args = Documentate_App_List::query_args( '', $outside_id );
		$this->assertSame(
			array( $this->cat_service, $this->cat_a, $this->cat_b ),
			$args['tax_query'][0]['terms'],
			'The scope stands.'
		);

		$html = $this->render( $this->management_id, array( 'area' => (string) $outside_id, 'estado' => 'todos' ) );
		$this->assertStringNotContainsString( 'Otro servicio doc', $html );
		$this->assertStringContainsString( 'RES · Jornadas digitales', $html );
		wp_delete_post( $foreign, true );

		// Administración is unrestricted: every category filters.
		wp_set_current_user( $this->admin_id );
		$this->assertSame( $outside_id, Documentate_App_Tray::current_area() );
	}

	/**
	 * Administración narrows the list by área.
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
	 * A list longer than one page says how much of it is on screen.
	 */
	public function test_a_list_longer_than_one_page_says_so() {
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
	public function test_an_approved_document_opens_its_document_view() {
		$html = $this->render( $this->admin_id, array( 'estado' => 'todos' ) );

		$this->assertSame( 'Ver', $this->row_action( $html, 'RES · Bases piloto' ) );
		$this->assertStringNotContainsString(
			'#exportar',
			$html,
			'The document view opens with the PDF, so there is nothing to scroll past it to.'
		);
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
	 * An empty list says whether it is the filter or the ámbito that is empty.
	 */
	public function test_an_empty_list_explains_itself() {
		$is_empty = $this->render( $this->management_id, array( 'estado' => 'archived' ) );

		$this->assertStringContainsString( 'dcta-vacio', $is_empty );
		$this->assertStringContainsString( 'No hay documentos con este filtro.', $is_empty );

		// With no filter and nothing there, the área is pointed at the way in;
		// whoever reads several áreas has nothing to be pointed at.
		foreach ( $this->docs as $doc_id ) {
			wp_delete_post( $doc_id, true );
		}
		$this->assertStringContainsString(
			'Crea el primero desde «Nuevo documento»',
			$this->render( $this->area_id, array( 'estado' => 'todos' ) )
		);
		$this->assertStringContainsString(
			'>No hay documentos.<',
			$this->render( $this->management_id, array( 'estado' => 'todos' ) )
		);

		$without_scope = self::factory()->user->create( array( 'role' => 'author' ) );
		$html = $this->render( $without_scope );
		$this->assertStringContainsString( 'no tiene un ámbito asignado', $html );
	}

	/**
	 * The query arguments of each chip, and of the scope behind them.
	 */
	public function test_query_arguments_per_chip() {
		wp_set_current_user( $this->management_id );

		$every = Documentate_App_List::query_args( '', 0 );
		$this->assertContains( 'draft', $every['post_status'], 'The list carries the drafts of its ámbito.' );
		$this->assertSame(
			array( $this->cat_service, $this->cat_a, $this->cat_b ),
			$every['tax_query'][0]['terms'],
			'The list covers every área of the scope.'
		);

		wp_set_current_user( $this->admin_id );
		$this->assertArrayNotHasKey( 'tax_query', Documentate_App_List::query_args( '', 0 ), 'Administración is unrestricted.' );
		wp_set_current_user( $this->management_id );

		$returned = Documentate_App_List::query_args( 'devuelto', 0 );
		$this->assertSame( Documentate_Document_Data::META_RETURNED, $returned['meta_key'] );
		$this->assertSame( 'EXISTS', $returned['meta_compare'] );
		// A return travels with the document: the most common one lands in a
		// draft, so narrowing by status would make the chip count something
		// else than it says.
		$this->assertContains( 'draft', $returned['post_status'], 'The returned filter reaches across statuses.' );

		$area = Documentate_App_List::query_args( 'pending', $this->cat_b );
		$this->assertSame( 'pending', $area['post_status'] );
		$this->assertSame( array( $this->cat_b ), $area['tax_query'][0]['terms'] );
	}

	/**
	 * The back link of a document names the list tab it goes back to.
	 */
	public function test_the_back_link_names_the_list() {
		wp_set_current_user( $this->management_id );
		$this->assertStringContainsString( '← Todos los documentos', Documentate_App_Shell::back_link() );

		wp_set_current_user( $this->area_id );
		$this->assertStringContainsString( '← Mis documentos', Documentate_App_Shell::back_link() );

		wp_set_current_user( $this->admin_id );
		$this->assertStringContainsString( '← Todos los documentos', Documentate_App_Shell::back_link() );
	}

	/**
	 * The quick filter is offered on the list and carries what to match on.
	 */
	public function test_the_list_offers_a_quick_filter() {
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
	 * The footer publishes the documents the filter holds, not the rows drawn.
	 */
	public function test_the_footer_publishes_the_list_total() {
		$html = $this->render( $this->area_id, array( 'estado' => 'todos' ) );

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
	 * Reading several áreas the rows name the área, so the filter has to match
	 * it: it is what a reviewer types.
	 */
	public function test_the_filter_text_carries_the_area_and_the_person() {
		$html = $this->render( $this->management_id, array( 'estado' => 'todos' ) );

		$area = get_term( $this->cat_a )->name;
		$this->assertStringContainsString( $area . ' · Ana Área', $html, 'The row draws them.' );
		$this->assertMatchesRegularExpression(
			'/data-dcta-texto="[^"]*Ana Área ' . preg_quote( $area, '/' ) . '[^"]*"/u',
			$html
		);

		// An área reads one área: every row would carry the same name, so the
		// row names the person alone and the text follows it.
		$own = $this->render( $this->area_id, array( 'estado' => 'todos' ) );
		$this->assertStringContainsString( 'Ana Área', $own, 'Whose document it is still tells the área apart from itself.' );
		$this->assertStringNotContainsString( $area . ' · Ana Área', $own );
		$this->assertDoesNotMatchRegularExpression(
			'/data-dcta-texto="[^"]*' . preg_quote( $area, '/' ) . '[^"]*"/u',
			$own,
			'The área of every row is the reader\'s own: nothing to match on.'
		);
	}

	/**
	 * A document returned to revisión keeps its "En revisión" chip,
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

		$html = $this->render( $this->management_id, array( 'estado' => 'todos' ) );

		$this->assertMatchesRegularExpression(
			'/data-dcta-texto="[^"]*En revisión Devuelto por la jefatura de servicio[^"]*Falta la firma de la persona titular[^"]*"/u',
			$html
		);
	}
}
