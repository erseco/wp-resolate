<?php
/**
 * Tests for the revision history view of the front-end application.
 *
 * The button at the foot of the document, the comparison of two versions,
 * the defaults and fallbacks of the range, the list of versions and the
 * assets the view loads.
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
	 * @return string
	 */
	private static function content( $text ) {
		return Documents_Meta_Handler::build_structured_field_fragment( 'objeto', 'textarea', $text );
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
	 * @return int Revision ID.
	 */
	private function save_version( $doc_id, $content, $title = '' ) {
		wp_set_current_user( $this->admin_id );
		$args = array(
			'ID' => $doc_id,
			'post_content' => self::content( $content ),
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
	 * The history view loads the revision diff assets with the type's labels.
	 */
	public function test_the_history_view_loads_the_revision_assets() {
		$doc_id = $this->create_document();
		wp_set_current_user( $this->area_id );

		$this->go_to( Documentate_App_Shell::page_url( array( 'doc' => $doc_id ) ) );
		$this->app->enqueue_assets();
		$this->assertFalse( wp_script_is( 'documentate-revisions', 'enqueued' ), 'The document view does not need the diff script.' );

		$this->go_to( Documentate_App_History::url( $doc_id ) );
		$this->app->enqueue_assets();
		$this->assertTrue( wp_style_is( 'documentate-revisions', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-revisions', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'documentate-revisions', 'data' );
		$this->assertStringContainsString( 'Objeto de la resoluci', $data, 'The field labels of the type travel to the script.' );
		$this->assertStringContainsString( 'post_title', $data );
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
