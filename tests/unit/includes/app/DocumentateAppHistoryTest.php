<?php
/**
 * Tests for the revision history view of the front-end application.
 *
 * The button at the foot of the document and of the editor's rail, the
 * comparison of two versions field by field, the defaults and fallbacks of
 * the range, the list of versions and the assets the view does not need.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * @covers Documentate_App_History
 * @covers Documentate_App_Detail
 * @covers Documentate_App
 * @covers Documentate_Admin
 */
class DocumentateAppHistoryTest extends WP_UnitTestCase {

	/**
	 * Application instance under test.
	 *
	 * @var Documentate_App
	 */
	private $app;

	/**
	 * Administración user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Área user ID, author of the documents.
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Another área user, with no scope and no documents.
	 *
	 * @var int
	 */
	private $stranger_id;

	/**
	 * Scope category term ID.
	 *
	 * @var int
	 */
	private $cat_id;

	/**
	 * Document type with a schema.
	 *
	 * @var int
	 */
	private $type_id;

	/**
	 * Roles, scope, type and the application page.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();

		$this->app = new Documentate_App();
		$this->app->ensure_page();

		$this->admin_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
				'display_name' => 'Adela Administración',
			)
		);
		$this->area_id = self::factory()->user->create(
			array(
				'role' => 'author',
				'display_name' => 'Ana Área',
			)
		);
		$this->stranger_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$area = wp_insert_term( 'Área historial ' . uniqid(), 'category' );
		$this->cat_id = (int) $area['term_id'];
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );

		$type = wp_insert_term( 'Resolución historial ' . uniqid(), 'documentate_doc_type' );
		$this->type_id = (int) $type['term_id'];
		( new SchemaStorage() )->save_schema(
			$this->type_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'title' => 'Objeto de la resolución',
						'type' => 'text',
					),
				),
				'repeaters' => array(
					array(
						'name' => 'partidas',
						'slug' => 'partidas',
						'title' => 'Partidas del gasto',
						'type' => 'array',
						'fields' => array(
							array(
								'name' => 'concepto',
								'slug' => 'concepto',
								'title' => 'Concepto',
								'type' => 'text',
							),
							array(
								'name' => 'importe',
								'slug' => 'importe',
								'title' => 'Importe',
								'type' => 'text',
							),
						),
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'historial.odt',
					'hash' => md5( 'historial' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);
	}

	/**
	 * Reset the request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		wp_dequeue_style( 'documentate-revisions' );
		wp_dequeue_script( 'documentate-revisions' );
		wp_dequeue_style( 'documentate-app' );
		parent::tear_down();
	}

	/**
	 * Create a draft document of the área.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function create_document( $title = 'Resolución de material' ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => 'draft',
				'post_author' => $this->area_id,
				'post_content' => self::content( 'Texto inicial de la resolución.' ),
				'tax_input' => array( 'documentate_doc_type' => array( $this->type_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		Documentate_Document_Data::save_internal_name( $post_id, 'Material aulas' );
		wp_set_current_user( 0 );

		return (int) $post_id;
	}

	/**
	 * The stored shape of a document whose only field, "objeto", holds a text.
	 *
	 * The plugin composes post_content from the schema fields on every save,
	 * so a plain string would be thrown away; a structured fragment is read
	 * back as the field's value.
	 *
	 * @param string $text Field value.
	 * @param string $type Field type; rich keeps its HTML, array holds JSON rows.
	 * @param string $slug Field slug.
	 * @return string
	 */
	private static function content( $text, $type = 'textarea', $slug = 'objeto' ) {
		return Documents_Meta_Handler::build_structured_field_fragment( $slug, $type, $text );
	}

	/**
	 * Save a version of a document with new text, as administración.
	 *
	 * The revision handler forces a revision on every save, and the revision
	 * is a copy of the document as saved: its ID is what the view compares.
	 *
	 * @param int    $doc_id  Document ID.
	 * @param string $content New post content.
	 * @param string $title   New title; empty keeps the current one.
	 * @param string $type    Field type of the content.
	 * @param string $slug    Field slug of the content.
	 * @return int Revision ID.
	 */
	private function save_version( $doc_id, $content, $title = '', $type = 'textarea', $slug = 'objeto' ) {
		wp_set_current_user( $this->admin_id );
		$args = array(
			'ID' => $doc_id,
			'post_content' => self::content( $content, $type, $slug ),
		);
		if ( '' !== $title ) {
			$args['post_title'] = $title;
		}
		wp_update_post( $args );
		wp_set_current_user( 0 );

		$revisions = wp_get_post_revisions( $doc_id, array( 'order' => 'DESC' ) );
		$latest = reset( $revisions );

		return $latest instanceof WP_Post ? (int) $latest->ID : 0;
	}

	/**
	 * The text of every diff cell of one kind, tags stripped.
	 *
	 * @param string $html      Rendered view.
	 * @param string $css_class diff-deletedline, diff-addedline or diff-context.
	 * @return string[]
	 */
	private static function diff_cells( $html, $css_class ) {
		preg_match_all( "/<td class='" . preg_quote( $css_class, '/' ) . "'>(.*?)<\/td>/s", $html, $matches );

		return array_map(
			static function ( $cell ) {
				return trim( wp_strip_all_tags( $cell ) );
			},
			$matches[1]
		);
	}

	/**
	 * Render a view as a user.
	 *
	 * @param int   $user_id User to render as.
	 * @param array $args    Query arguments.
	 * @return string HTML.
	 */
	private function view( $user_id, array $args ) {
		wp_set_current_user( $user_id );
		$_GET = array_map( 'strval', $args );

		return $this->app->render();
	}

	/**
	 * The document view ends with the way to the history.
	 */
	public function test_the_document_view_ends_with_the_history_button() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'Segunda versión.' );

		$html = $this->view( $this->area_id, array( 'doc' => $doc_id ) );

		$this->assertStringContainsString( 'dcta-historial-btn', $html );
		$this->assertStringContainsString( 'Ver historial de cambios', $html );
		$this->assertStringContainsString( 'vista=historial', $html );
		$this->assertStringContainsString( '(1 versión)', $html, 'The button counts the versions.' );

		$this->save_version( $doc_id, 'Tercera versión.' );
		$this->assertStringContainsString( '(2 versiones)', $this->view( $this->area_id, array( 'doc' => $doc_id ) ) );

		$body_end = strrpos( $html, '</div><div class="dcta-lado">' );
		$button = strpos( $html, 'dcta-historial-btn' );
		$this->assertNotFalse( $body_end );
		$this->assertLessThan( $body_end, $button, 'The button closes the body of the document, before the rail.' );
	}

	/**
	 * The editor's rail ends with the same button, right above the way back.
	 */
	public function test_the_editor_rail_offers_the_history_too() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'Segunda versión.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'editar',
			)
		);

		$rail = strpos( $html, 'dcta-editor-lado' );
		$button = strpos( $html, 'dcta-historial-btn' );
		$back = strpos( $html, 'dcta-editor-volver' );
		$this->assertNotFalse( $rail );
		$this->assertNotFalse( $button );
		$this->assertNotFalse( $back );
		$this->assertGreaterThan( $rail, $button, 'The button is in the rail.' );
		$this->assertLessThan( $back, $button, 'The button comes right before the way back.' );
		$this->assertStringContainsString( 'Ver historial de cambios (1 versión)', $html );
	}

	/**
	 * The history view carries the document's own tab, like the document does.
	 */
	public function test_the_history_view_keeps_the_document_tab() {
		$doc_id = $this->create_document();

		$html = $this->view(
			$this->admin_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'dcta-tab-documento dcta-tab-on', $html );
		$this->assertStringContainsString( '>Material aulas', $html );
	}

	/**
	 * A document nobody has saved yet has a history view that says so.
	 *
	 * Creating a document leaves no revision behind; the first save does.
	 */
	public function test_a_document_without_versions_explains_itself() {
		$doc_id = $this->create_document();
		$this->assertEmpty( wp_get_post_revisions( $doc_id ) );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'sin versiones guardadas', $html );
		$this->assertStringContainsString( 'Todavía no hay versiones guardadas', $html );
		$this->assertStringNotContainsString( "<table class='diff", $html );
		$this->assertStringContainsString( '← Volver al documento', $html );
	}

	/**
	 * The history compares the last two versions side by side, red and green.
	 */
	public function test_the_history_compares_the_last_two_versions() {
		$doc_id = $this->create_document( 'Resolución de material' );
		$this->save_version( $doc_id, 'Compra de mesas para las aulas.' );
		$this->save_version( $doc_id, 'Compra de sillas para las aulas.', 'Resolución de mobiliario' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'Historial de cambios', $html );
		$this->assertStringContainsString( 'Comparar versiones', $html );
		$this->assertStringContainsString( "<table class='diff", $html );

		$deleted = self::diff_cells( $html, 'diff-deletedline' );
		$added = self::diff_cells( $html, 'diff-addedline' );
		$this->assertContains( 'Deleted: Compra de mesas para las aulas.', $deleted );
		$this->assertContains( 'Added: Compra de sillas para las aulas.', $added );
		$this->assertContains( 'Deleted: Resolución de material', $deleted );
		$this->assertContains( 'Added: Resolución de mobiliario', $added );
		$this->assertStringContainsString( 'Adela Administración', $html, 'Each version names who saved it.' );
	}

	/**
	 * The comparison names each field and reads it as text: no markers, no HTML.
	 */
	public function test_the_comparison_names_the_fields_and_reads_them_as_text() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, '<p>Compra de <b>mesas</b> para las aulas.</p>', '', 'rich' );
		$latest = $this->save_version( $doc_id, '<p>Compra de <b>sillas</b> para las aulas.</p><ul><li>Con respaldo</li></ul>', '', 'rich' );
		$this->assertStringContainsString( '<b>sillas</b>', get_post( $latest )->post_content, 'The version keeps its HTML; the view is what reads it as text.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( '<h3 class="dcta-historial-h3">Objeto de la resolución</h3>', $html, 'The heading is the label of the type, not the slug.' );
		$this->assertStringNotContainsString( 'Contenido</h3>', $html, 'The content is not one block.' );
		$this->assertStringNotContainsString( 'documentate-field', $html, 'The stored field markers never reach the screen.' );
		$this->assertStringNotContainsString( '&lt;b&gt;', $html );
		$this->assertStringNotContainsString( '&lt;p&gt;', $html );

		$added = self::diff_cells( $html, 'diff-addedline' );
		$this->assertContains( 'Deleted: Compra de mesas para las aulas.', self::diff_cells( $html, 'diff-deletedline' ) );
		$this->assertContains( 'Added: Compra de sillas para las aulas.', $added );
		$this->assertContains( 'Added: Con respaldo', $added, 'Each list item is a line of its own.' );
	}

	/**
	 * A repeater is compared row by row, never as the JSON that stores it.
	 */
	public function test_a_repeater_is_compared_row_by_row() {
		$doc_id = $this->create_document();
		$sillas = array(
			'concepto' => 'Sillas',
			'importe' => '120',
		);
		$mesas = array(
			'concepto' => 'Mesas',
			'importe' => '300',
		);
		$this->save_version( $doc_id, wp_json_encode( array( $sillas ) ), '', 'array', 'partidas' );
		$this->save_version( $doc_id, wp_json_encode( array( $sillas, $mesas ) ), '', 'array', 'partidas' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( '<h3 class="dcta-historial-h3">Partidas del gasto</h3>', $html );
		$this->assertContains( 'Unchanged: Sillas · 120', self::diff_cells( $html, 'diff-context' ) );
		$this->assertContains( 'Added: Mesas · 300', self::diff_cells( $html, 'diff-addedline' ) );
		$this->assertStringNotContainsString( 'concepto', $html, 'The stored JSON never reaches the screen.' );
	}

	/**
	 * Two versions that read the same say so instead of drawing an empty table.
	 */
	public function test_identical_versions_show_no_differences() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'El mismo texto.' );
		$this->save_version( $doc_id, 'El mismo texto.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'No hay diferencias entre estas dos versiones.', $html );
		$this->assertStringNotContainsString( "<table class='diff", $html );
	}

	/**
	 * The request picks the two versions; the older one always goes left.
	 */
	public function test_the_request_picks_the_versions_to_compare() {
		$doc_id = $this->create_document();
		$first = $this->save_version( $doc_id, 'Primera redacción.' );
		$this->save_version( $doc_id, 'Segunda redacción.' );
		$third = $this->save_version( $doc_id, 'Tercera redacción.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
				'desde' => $first,
				'hasta' => $third,
			)
		);

		$this->assertContains( 'Deleted: Primera redacción.', self::diff_cells( $html, 'diff-deletedline' ) );
		$this->assertContains( 'Added: Tercera redacción.', self::diff_cells( $html, 'diff-addedline' ) );
		$this->assertStringNotContainsString( 'Segunda redacción', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $first . '" selected/', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $third . '" selected/', $html );
	}

	/**
	 * A revision of another document, or a made-up ID, falls back to the defaults.
	 */
	public function test_foreign_or_unknown_revisions_fall_back_to_the_latest() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'Versión propia antigua.' );
		$latest = $this->save_version( $doc_id, 'Versión propia reciente.' );

		$other_id = $this->create_document( 'Otro documento' );
		$foreign = $this->save_version( $other_id, 'Texto de otro documento.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
				'desde' => 999999,
				'hasta' => $foreign,
			)
		);

		$this->assertStringNotContainsString( 'otro documento', $html );
		$this->assertMatchesRegularExpression( '/name="hasta">.*<option value="' . $latest . '" selected/s', $html );
		$this->assertContains( 'Deleted: Versión propia antigua.', self::diff_cells( $html, 'diff-deletedline' ) );
		$this->assertContains( 'Added: Versión propia reciente.', self::diff_cells( $html, 'diff-addedline' ) );
	}

	/**
	 * The oldest version is compared with the empty document.
	 */
	public function test_the_oldest_version_is_compared_with_nothing() {
		$doc_id = $this->create_document();
		$oldest = $this->save_version( $doc_id, 'Texto inicial de la resolución.' );
		$this->save_version( $doc_id, 'Otra versión.' );

		$html = $this->view(
			$this->area_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
				'hasta' => $oldest,
			)
		);

		$this->assertMatchesRegularExpression( '/<option value="0" selected[^>]*>Documento vacío/', $html );
		$this->assertContains( 'Added: Texto inicial de la resolución.', self::diff_cells( $html, 'diff-addedline' ) );
		$this->assertStringNotContainsString( 'Otra versión.', $html, 'The newer version is not part of the comparison.' );
	}

	/**
	 * The rail lists every version, marks the one on screen and links the rest.
	 */
	public function test_the_rail_lists_the_versions() {
		$doc_id = $this->create_document();
		$first = $this->save_version( $doc_id, 'Uno.' );
		$second = $this->save_version( $doc_id, 'Dos.' );

		$html = $this->view(
			$this->admin_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'Versiones', $html );
		$this->assertMatchesRegularExpression( '/hasta=' . $second . '"[^>]*>Versión 2 · /', $html, 'Versions are numbered from the oldest.' );
		$this->assertMatchesRegularExpression( '/hasta=' . $first . '"[^>]*>Versión 1 · /', $html );
		$this->assertMatchesRegularExpression( '/dcta-historial-item-on"><a href="[^"]*hasta=' . $second . '"/', $html );
		$this->assertMatchesRegularExpression( '/dcta-historial-item"><a href="[^"]*hasta=' . $first . '"/', $html );
		$this->assertMatchesRegularExpression( '/dcta-editor-volver" href="[^"]*doc=' . $doc_id . '"/', $html, 'The way back leads to the document.' );
	}

	/**
	 * Autosaves are one person's typing, not a version.
	 */
	public function test_autosaves_are_not_versions() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'Guardado.' );
		wp_set_current_user( $this->area_id );
		$autosave_id = wp_create_post_autosave(
			array(
				'post_ID' => $doc_id,
				'post_type' => 'documentate_document',
				'post_content' => 'Texto a medio escribir.',
				'post_title' => 'Resolución de material',
				'post_excerpt' => '',
			)
		);
		$this->assertIsInt( $autosave_id );

		$revisions = Documentate_App_History::revisions( get_post( $doc_id ) );

		$this->assertArrayNotHasKey( $autosave_id, $revisions );
		$this->assertNotEmpty( $revisions );
	}

	/**
	 * A document out of the visitor's scope has no history for them.
	 */
	public function test_the_history_respects_scope() {
		$doc_id = $this->create_document();
		$this->save_version( $doc_id, 'Texto que no debe verse.' );

		$html = $this->view(
			$this->stranger_id,
			array(
				'doc' => $doc_id,
				'vista' => 'historial',
			)
		);

		$this->assertStringContainsString( 'fuera de tu ámbito', $html );
		$this->assertStringNotContainsString( 'Texto que no debe verse', $html );
	}

	/**
	 * The comparison is drawn on the server: the view needs no diff script.
	 */
	public function test_the_history_view_needs_no_revision_script() {
		$doc_id = $this->create_document();
		wp_set_current_user( $this->area_id );

		$this->go_to( Documentate_App_History::url( $doc_id ) );
		$this->app->enqueue_assets();

		$this->assertTrue( wp_style_is( 'documentate-app', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'documentate-revisions', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-revisions', 'enqueued' ) );
	}

	/**
	 * The history URL carries the document, the view and the range.
	 */
	public function test_the_history_url_names_the_range() {
		$url = Documentate_App_History::url( 12, 3, 7 );

		$this->assertStringContainsString( 'doc=12', $url );
		$this->assertStringContainsString( 'vista=historial', $url );
		$this->assertStringContainsString( 'desde=3', $url );
		$this->assertStringContainsString( 'hasta=7', $url );

		$plain = Documentate_App_History::url( 12 );
		$this->assertStringNotContainsString( 'desde', $plain, 'Without a range the defaults apply.' );
		$this->assertStringNotContainsString( 'hasta', $plain );
	}
}
