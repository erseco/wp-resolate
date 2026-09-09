<?php
/**
 * Chrome shared by every page of the Documentate front-end application.
 *
 * Same shell pattern as the Registro de Visitas application: a header with the
 * institutional mark and who is signed in (name, role and ámbito), a tab bar
 * with what this person can do, the sheet the content goes in and the
 * institutional footer. The theme chrome is hidden by the stylesheet under
 * `body.documentate-app`, so the app owns the whole page.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Header, tabs, sheet, dialogs and footer of the application.
 */
class Documentate_App_Shell {

	/**
	 * Slug of the page the application lives on ("/documentate/").
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'documentate';

	/**
	 * ID of the form the dialogs post through.
	 *
	 * The dialogs are printed after the footer so the break-out transform of
	 * the sheet never traps them; their controls join the form again with the
	 * HTML "form" attribute, which is why exactly one motivo can be posted.
	 *
	 * @var string
	 */
	const FORM_ID = 'dcta-app-form';

	/**
	 * ID of the form the activity comment box posts through.
	 *
	 * @var string
	 */
	const FORM_COMMENT_ID = 'dcta-app-comentario';

	/**
	 * Longest a document tab gets before it is trimmed.
	 *
	 * A tab bar is read at a glance, and the internal name of a document can
	 * be eighty characters long.
	 *
	 * @var int
	 */
	const TAB_MAX = 34;

	/**
	 * How the application writes a date.
	 *
	 * The site options are whatever the installation left them at (WordPress
	 * ships US defaults), and this interface is Spanish only: a ficha that
	 * says "septiembre 2, 2026" reads as a bug, so the format is fixed here.
	 *
	 * @var string
	 */
	const DATE_FORMAT = 'j \d\e F \d\e Y';

	/**
	 * How the application writes a time of day.
	 *
	 * @var string
	 */
	const TIME_FORMAT = 'H:i';

	/**
	 * Transitions the rule table keeps for wp-admin only.
	 *
	 * Archiving is a records-management decision taken from the admin list,
	 * where the archive links have always lived; the application would offer
	 * it with no confirmation and no feedback of its own. Un-approving an
	 * already published document ("Devolver a revisión") belongs to the same
	 * toolbox: the application shows an approved document as finished.
	 *
	 * @var string[]
	 */
	const WP_ADMIN_ONLY = array( 'archivar', 'desarchivar', 'devolver_revision' );

	/**
	 * Tabs already built in this request, keyed by user ID.
	 *
	 * The badge of the actionable tab costs a count query and every view asks
	 * for the tabs twice (the tab bar and the back link), so they are built
	 * once per page: open() empties the cache as a page starts rendering.
	 *
	 * @var array<int,array<string,array{tab:string,url:string}>>
	 */
	private static $sections = array();

	/**
	 * URL of the application page, optionally with view arguments.
	 *
	 * @param array<string,string|int> $args Query arguments (vista, doc, estado).
	 * @return string Empty when the page does not exist yet.
	 */
	public static function page_url( array $args = array() ) {
		// The stored ID first: the page can be renamed, and the shortcode can
		// be moved to a page of another name entirely, which `is_app_page()`
		// supports. Resolving by slug alone would then return nothing and
		// every link, tab and post-save redirect in the application would
		// point at the empty string.
		$page_id = (int) get_option( Documentate_App::OPTION_PAGE_ID );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;

		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			$page = get_page_by_path( self::PAGE_SLUG );
		}

		if ( ! $page ) {
			return '';
		}

		$url = (string) get_permalink( $page );

		return empty( $args ) ? $url : add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	/**
	 * The query arguments of the application page, as hidden inputs.
	 *
	 * A GET form throws away the query string of its action, so on a site
	 * without pretty permalinks (…/?page_id=12) a filter form would submit to
	 * the site root and drop the visitor out of the application.
	 *
	 * @return string Empty when the permalink carries no query string.
	 */
	public static function page_query_fields() {
		$query_string = (string) wp_parse_url( self::page_url(), PHP_URL_QUERY );
		if ( '' === $query_string ) {
			return '';
		}

		$pairs = array();
		wp_parse_str( $query_string, $pairs );

		$html = '';
		foreach ( array_filter( $pairs, 'is_scalar' ) as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		return $html;
	}

	/**
	 * Whether the post being viewed is the application page.
	 *
	 * The shortcode marks the page, not its slug: whoever deploys can move it.
	 *
	 * @return bool
	 */
	public static function is_app_page() {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, Documentate_App::SHORTCODE );
	}

	/**
	 * Mark the application pages so the theme chrome can step aside.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( array $classes ) {
		if ( self::is_app_page() ) {
			$classes[] = 'documentate-app';
		}

		return $classes;
	}

	/**
	 * Show the front-end toolbar for administrators and switched sessions.
	 *
	 * User Switching validates its own session; visibility grants no capability.
	 *
	 * @return bool
	 */
	public static function show_admin_bar() {
		return Documentate_Roles::is_administration()
			|| ( function_exists( 'current_user_switched' ) && current_user_switched() instanceof WP_User );
	}

	/**
	 * Label of the role, for the header.
	 *
	 * @return string
	 */
	public static function role() {
		return Documentate_Roles::role_label();
	}

	/**
	 * Label of the ámbito, for the header.
	 *
	 * @return string
	 */
	public static function scope() {
		return Documentate_Roles::scope_label();
	}

	/**
	 * Initials of a display name, for the avatar of the header.
	 *
	 * @param string $name Display name.
	 * @return string Up to two upper-case letters.
	 */
	public static function initials( $name ) {
		$parts = preg_split( '/\s+/u', trim( (string) $name ) );
		$letters = '';
		foreach ( array_slice( is_array( $parts ) ? $parts : array(), 0, 2 ) as $part ) {
			$letters .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}

		return $letters;
	}

	/**
	 * One of the inline icons of the shell.
	 *
	 * @param string $name plus, lock, chevron or clock.
	 * @return string SVG markup, empty for an unknown name.
	 */
	public static function icon( $name ) {
		$paths = array(
			'plus' => 'M10.5 6.5a1.5 1.5 0 0 1 3 0v4h4a1.5 1.5 0 0 1 0 3h-4v4a1.5 1.5 0 0 1-3 0v-4h-4a1.5 1.5 0 0 1 0-3h4v-4Z',
			'lock' => 'M7 10V8a5 5 0 0 1 10 0v2h1a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h1Zm2 0h6V8a3 3 0 0 0-6 0v2Z',
			'chevron' => 'M6.7 9.3a1 1 0 0 1 1.4 0l3.9 3.9 3.9-3.9a1 1 0 1 1 1.4 1.4l-4.6 4.6a1 1 0 0 1-1.4 0L6.7 10.7a1 1 0 0 1 0-1.4Z',
			'clock' => 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 3a1 1 0 0 1 1 1v3.6l2.7 1.6a1 1 0 1 1-1 1.7l-3.2-1.9a1 1 0 0 1-.5-.9V8a1 1 0 0 1 1-1Z',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="dcta-icono dcta-icono-' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
			. '<path fill="currentColor" d="' . esc_attr( $paths[ $name ] ) . '"/></svg>';
	}

	/**
	 * Who holds a document this person cannot edit right now.
	 *
	 * @param WP_Post $post Document.
	 * @return string "revisión", "la jefatura de servicio", or empty when the
	 *                status hands the document to nobody in particular.
	 */
	public static function holder( $post ) {
		$holders = array(
			'en_gestion' => 'revisión',
			'pending' => 'la jefatura de servicio',
		);

		return isset( $holders[ $post->post_status ] ) ? $holders[ $post->post_status ] : '';
	}

	/**
	 * The notice of a document that is locked for this person.
	 *
	 * The same block whoever finds a document in somebody else's hands sees,
	 * in the document view, in the editor and beside the row action: a lock,
	 * who has it and what that means for them.
	 *
	 * @param WP_Post $post  Document.
	 * @param string  $extra Escaped markup appended after the sentence (a link).
	 * @return string Empty when the document is not locked for this person.
	 */
	public static function lock_notice( $post, $extra = '' ) {
		$holder = self::holder( $post );
		if ( '' === $holder || Documentate_App_Edit::can_edit( $post ) ) {
			return '';
		}

		$text = 'en_gestion' === $post->post_status
			? 'Lo tiene revisión: está completando los datos oficiales y solo revisión puede modificarlo ahora. Si falta algo, te lo devolverán.'
			: 'Lo tiene la jefatura de servicio: lo aprobará o lo devolverá. Nadie más puede modificarlo mientras tanto.';

		return '<div class="dcta-aviso dcta-aviso-bloqueo">' . self::icon( 'lock' )
			. '<span>' . esc_html( $text ) . $extra . '</span></div>';
	}

	/**
	 * The sections this person can reach, in tab order.
	 *
	 * Only the actionable tab carries a badge: the documents waiting for this
	 * role to do something with them.
	 *
	 * @return array<string,array{tab:string,url:string}>
	 */
	public static function sections() {
		$user = get_current_user_id();
		if ( ! isset( self::$sections[ $user ] ) ) {
			self::$sections[ $user ] = self::build_sections();
		}

		return self::$sections[ $user ];
	}

	/**
	 * Build the tabs of the current person.
	 *
	 * @return array<string,array{tab:string,url:string}>
	 */
	private static function build_sections() {
		if ( Documentate_Roles::is_management() ) {
			return self::management_sections();
		}

		return array(
			'lista' => self::section( 'Mis documentos', self::page_url() ),
			'nuevo' => self::section( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * Tabs of whoever looks after several áreas: the list and the new document.
	 *
	 * What waits for each rol is not a tab: it is the status chip the list
	 * opens on, and the chips carry their own numbers. Administración sees
	 * every área of the site, the rest their ámbito. Document types and their
	 * templates are not here: that is wp-admin work.
	 *
	 * @return array<string,array{tab:string,url:string}>
	 */
	private static function management_sections() {
		return array(
			'lista' => self::section( Documentate_Roles::is_administration() ? 'Todos los documentos' : 'Documentos', self::page_url() ),
			'nuevo' => self::section( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * One tab row.
	 *
	 * @param string $tab Tab label.
	 * @param string $url Destination.
	 * @return array{tab:string,url:string}
	 */
	private static function section( $tab, $url ) {
		return array(
			'tab' => $tab,
			'url' => $url,
		);
	}

	/**
	 * The tab of the document being read or edited.
	 *
	 * A document is not the tray it was opened from, and lighting "Mis
	 * documentos" while the editor is on screen says it is. It gets a tab of
	 * its own instead, next to the tray it came from, and the tray stops
	 * being the current one.
	 *
	 * @param WP_Post $post Document.
	 * @return array{tab:string,url:string}
	 */
	public static function document_tab( $post ) {
		$name = Documentate_Document_Data::short_name( $post );
		if ( mb_strlen( $name ) > self::TAB_MAX ) {
			$name = mb_substr( $name, 0, self::TAB_MAX - 1 ) . '…';
		}

		return array(
			'tab' => $name,
			'url' => self::page_url( self::detail_args( $post->ID ) ),
		);
	}

	/**
	 * View arguments of a document.
	 *
	 * @param int $doc_id Document ID.
	 * @return array<string,string|int>
	 */
	private static function detail_args( $doc_id ) {
		return array( 'doc' => (int) $doc_id );
	}

	/**
	 * Link back to the list, named after its tab.
	 *
	 * @return string
	 */
	public static function back_link() {
		$sections = self::sections();
		$key = 'lista';

		if ( ! isset( $sections[ $key ] ) ) {
			return '';
		}

		return '<a class="dcta-editor-volver" href="' . esc_url( $sections[ $key ]['url'] ) . '">'
			. '← ' . esc_html( $sections[ $key ]['tab'] ) . '</a>';
	}

	/**
	 * Chip class and label for a document status.
	 *
	 * @param string $post_status Post status.
	 * @return array{class:string,text:string}
	 */
	public static function status_chip( $post_status ) {
		$statuses = array(
			'draft' => array( 'borrador', 'Borrador' ),
			'auto-draft' => array( 'borrador', 'Borrador' ),
			'en_gestion' => array( 'gestion', 'En revisión' ),
			'pending' => array( 'pendiente', 'En aprobación' ),
			'publish' => array( 'aprobado', 'Aprobado' ),
			'archived' => array( 'archivado', 'Archivado' ),
		);

		$status = isset( $statuses[ $post_status ] ) ? $statuses[ $post_status ] : $statuses['draft'];

		return array(
			'class' => 'dcta-estado dcta-estado-' . $status[0],
			'text' => $status[1],
		);
	}

	/**
	 * Chip of a document: the status, or "Devuelto" when it came back to the área.
	 *
	 * A document returned to revisión keeps the "En revisión" chip; the
	 * returned line under the name is what tells revisión to correct it.
	 *
	 * @param WP_Post $post Document.
	 * @return array{class:string,text:string}
	 */
	public static function chip( $post ) {
		if ( 'draft' === $post->post_status && null !== Documentate_Document_Data::returned( $post ) ) {
			return array(
				'class' => 'dcta-estado dcta-estado-devuelto',
				'text' => 'Devuelto',
			);
		}

		return self::status_chip( $post->post_status );
	}

	/**
	 * The "Devuelto por … el … : «…»" line of a returned document.
	 *
	 * Only for the side the return was addressed to. Jefatura de servicio
	 * returning a document to revisión writes a note to revisión: the área,
	 * which cannot open the document while it is in revisión, is neither told
	 * to correct anything nor shown what was said.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when the document was not returned, or when it was
	 *                returned to somebody else.
	 */
	public static function returned_text( $post ) {
		$returned = Documentate_Document_Data::returned( $post );
		if ( null === $returned || ( 'gestion' === $returned['a'] && ! Documentate_Roles::is_management() ) ) {
			return '';
		}

		$who = 'administracion' === $returned['desde'] ? 'la jefatura de servicio' : 'revisión';
		$timestamp = strtotime( $returned['fecha'] );
		$date = false === $timestamp ? '' : ' el ' . date_i18n( 'j M', $timestamp );

		return 'Devuelto por ' . $who . $date . ': «' . $returned['motivo'] . '»';
	}

	/**
	 * The returned notice of the document view and the editor.
	 *
	 * The call to action is only added for whoever can actually act on it:
	 * a document sitting in revisión is read-only for its área, so telling
	 * them to correct it and send it again is an instruction they cannot
	 * follow.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when there is nothing to show this person.
	 */
	public static function returned_notice( $post ) {
		$text = self::returned_text( $post );
		if ( '' === $text ) {
			return '';
		}

		$text = rtrim( $text, '.' ) . '.';

		return Documentate_App_Edit::can_edit( $post )
			? $text . ' Corrige lo que haga falta y vuelve a enviarlo.'
			: $text;
	}

	/**
	 * The transitions the application offers on a document right now.
	 *
	 * The rule table also carries the archive moves, which belong to the
	 * wp-admin list; everything else is drawn as a button.
	 *
	 * @param WP_Post $post Document.
	 * @return array<string,array<string,mixed>>
	 */
	public static function app_transitions( WP_Post $post ) {
		$available = Documentate_Transitions::available( $post );

		foreach ( self::WP_ADMIN_ONLY as $key ) {
			unset( $available[ $key ] );
		}

		return $available;
	}

	/**
	 * The transition buttons available on a document right now.
	 *
	 * Every button is a plain submit carrying its transition key, so the
	 * application works without JavaScript; the dialogs of
	 * public/js/documentate-app.js hook onto the data attributes.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when no transition is available.
	 */
	public static function transition_buttons( $post ) {
		$available = self::app_transitions( $post );
		if ( empty( $available ) ) {
			return '';
		}

		$returns = array();
		$html = '';
		foreach ( $available as $key => $rule ) {
			if ( $rule['reason'] ) {
				$returns[ $key ] = $rule;
				continue;
			}

			$html .= self::transition_button(
				$key,
				(string) $rule['label'],
				'dcta-btn-pri',
				' data-confirmar="' . esc_attr( (string) $rule['confirm'] ) . '"'
			);
		}

		return $html . self::return_buttons( $returns );
	}

	/**
	 * The return buttons, and the fallback for browsers without dialogs.
	 *
	 * When a document can be returned to two places (jefatura de servicio on
	 * a document that went through revisión) there is a single "Devolver…"
	 * button and the dialog asks where to.
	 *
	 * @param array<string,array<string,mixed>> $returns Return rules available.
	 * @return string
	 */
	private static function return_buttons( array $returns ) {
		if ( empty( $returns ) ) {
			return '';
		}

		if ( count( $returns ) > 1 ) {
			return self::transition_button( 'devolver_area', 'Devolver…', 'dcta-btn-ton', ' data-motivo="1" data-destinos="1"' )
				. self::reason_fallback( $returns );
		}

		$html = '';
		foreach ( $returns as $key => $rule ) {
			$html .= self::transition_button( $key, (string) $rule['label'], 'dcta-btn-ton', ' data-motivo="1"' );
		}

		return $html . self::reason_fallback( array() );
	}

	/**
	 * The reason box a browser without <dialog> support posts instead.
	 *
	 * The script documentate-app.js hides and disables it when the dialog is available,
	 * so exactly one documentate_app_motivo is ever posted.
	 *
	 * @param array<string,array<string,mixed>> $extra Return rules needing their own button here.
	 * @return string
	 */
	private static function reason_fallback( array $extra ) {
		$html = '<details class="dcta-motivo-fallback">'
			. '<summary>Motivo de la devolución</summary>'
			. '<label for="dcta-motivo-fallback-texto">Motivo de la devolución</label>'
			. '<textarea id="dcta-motivo-fallback-texto" name="documentate_app_motivo" rows="3" placeholder="Qué falta o qué hay que corregir"></textarea>'
			. '<p class="dcta-ayuda">El motivo se envía por correo y queda en la actividad.</p>';

		foreach ( $extra as $key => $rule ) {
			$html .= self::transition_button( $key, (string) $rule['label'], 'dcta-btn-ton', '' );
		}

		return $html . '</details>';
	}

	/**
	 * One transition button.
	 *
	 * @param string $key       Transition key posted by the button.
	 * @param string $label     Button label.
	 * @param string $css_class Button modifier class.
	 * @param string $extra     Extra attributes, already escaped.
	 * @return string
	 */
	private static function transition_button( $key, $label, $css_class, $extra ) {
		return '<button type="submit" class="dcta-btn ' . esc_attr( $css_class ) . '"'
			. ' name="documentate_app_transicion" value="' . esc_attr( $key ) . '"' . $extra . '>'
			. esc_html( $label ) . '</button>';
	}

	/**
	 * Open the page: header, tabs and the sheet the content goes in.
	 *
	 * @param string                       $section  Active section key.
	 * @param string                       $title    Page heading.
	 * @param string                       $sub      One line under the heading.
	 * @param array{tab:string,url:string} $document Tab of the document on
	 *                                     screen, from document_tab().
	 * @return string
	 */
	public static function open( $section, $title, $sub = '', array $document = array() ) {
		self::$sections = array();
		$sections = self::sections();

		if ( ! empty( $document['tab'] ) ) {
			$sections = self::with_document_tab( $sections, $document, $section );
			$section = 'documento';
		}
		$user = wp_get_current_user();
		$home_url = self::page_url();
		$home_url = '' !== $home_url ? $home_url : home_url( '/' );

		ob_start();
		?>
		<div class="dcta-top">
			<div class="dcta-top-fila">
				<img class="dcta-escudo" src="<?php echo esc_url( plugins_url( 'assets/images/canary-islands-government.png', DOCUMENTATE_PLUGIN_FILE ) ); ?>" alt="Gobierno de Canarias" width="104" height="60" />
				<span class="dcta-marca">
					<small>Consejería de Educación, Formación Profesional, Actividad Física y Deportes</small>
				</span>
				<a class="dcta-marca-app" href="<?php echo esc_url( $home_url ); ?>">Documentate</a>
				<details class="dcta-yo">
					<summary>
						<span class="dcta-yo-ava" aria-hidden="true"><?php echo esc_html( self::initials( $user->display_name ) ); ?></span>
						<span class="dcta-yo-txt">
							<span class="dcta-yo-n"><?php echo esc_html( $user->display_name ); ?> <?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal. ?></span>
							<span class="dcta-rol"><?php echo esc_html( self::role() ); ?></span>
							<span class="dcta-yo-ambito"><?php echo esc_html( self::scope() ); ?></span>
						</span>
					</summary>
					<div class="dcta-yo-menu">
						<a href="<?php echo esc_url( wp_logout_url( $home_url ) ); ?>">Salir</a>
					</div>
				</details>
			</div>
		</div>

		<nav class="dcta-tabs" aria-label="Secciones">
			<div class="dcta-tabs-fila">
				<?php foreach ( $sections as $key => $s ) : ?>
					<a class="dcta-tab dcta-tab-<?php echo esc_attr( $key ); ?><?php echo $key === $section ? ' dcta-tab-on' : ''; ?>"
						<?php echo $key === $section ? ' aria-current="page"' : ''; ?>
						href="<?php echo esc_url( $s['url'] ); ?>"><?php echo 'nuevo' === $key ? self::icon( 'plus' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal. ?><?php echo esc_html( $s['tab'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</nav>

		<div class="dcta-hoja">
			<?php if ( '' !== $title ) : ?>
				<h1 class="dcta-h1"><?php echo esc_html( $title ); ?></h1>
			<?php endif; ?>
			<?php if ( '' !== $sub ) : ?>
				<p class="dcta-sub"><?php echo esc_html( $sub ); ?></p>
			<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The tabs with the document's own one, right after the tray it came from.
	 *
	 * @param array<string,array{tab:string,url:string}> $sections Tabs of the role.
	 * @param array{tab:string,url:string}               $document Document tab.
	 * @param string                                     $after    Tab the document was opened from.
	 * @return array<string,array{tab:string,url:string}>
	 */
	private static function with_document_tab( array $sections, array $document, $after ) {
		$row = self::section( $document['tab'], isset( $document['url'] ) ? $document['url'] : '' );
		if ( ! isset( $sections[ $after ] ) ) {
			$sections['documento'] = $row;

			return $sections;
		}

		$with = array();
		foreach ( $sections as $key => $section ) {
			$with[ $key ] = $section;
			if ( $key === $after ) {
				$with['documento'] = $row;
			}
		}

		return $with;
	}

	/**
	 * Close the page: the sheet, institutional footer and dialogs.
	 *
	 * @param bool $dialogs Whether the view has a form the dialogs post through.
	 * @return string
	 */
	public static function close( $dialogs = false ) {
		$home_url = self::page_url();
		$home_url = '' !== $home_url ? $home_url : home_url( '/' );

		ob_start();
		echo '</div>';
		?>
		<footer class="dcta-pie"><div>
			<span class="dcta-pie-quien">
				<a href="<?php echo esc_url( $home_url ); ?>">&copy; Gobierno de Canarias</a>
				<span class="dcta-pie-ate">Desarrollado por el Área de Tecnología Educativa</span>
			</span>
			<span class="dcta-pie-enlaces">
				<a href="https://www.gobiernodecanarias.org/principal/avisolegal.html" rel="noopener">Aviso legal</a>
				<a href="https://www.gobiernodecanarias.org/eucd/politica_privacidad/" rel="noopener">Política de privacidad</a>
			</span>
		</div></footer>
		<?php
		$html = (string) ob_get_clean();

		return $dialogs ? $html . self::dialogs() : $html;
	}

	/**
	 * The two dialogs of the application: the return reason and the confirmation.
	 *
	 * Every control is disabled in the markup and enabled by
	 * public/js/documentate-app.js when it opens the dialog, so a browser
	 * without JavaScript posts none of them and uses the inline fallback
	 * instead. The dialogs live after the footer, outside the sheet, and reach
	 * their form through the "form" attribute.
	 *
	 * @return string
	 */
	public static function dialogs() {
		$form = self::FORM_ID;

		ob_start();
		?>
		<dialog class="dcta-dialogo" id="dcta-dialogo-motivo" aria-labelledby="dcta-dialogo-motivo-titulo">
			<h2 class="dcta-dialogo-titulo" id="dcta-dialogo-motivo-titulo">Devolver el documento</h2>
			<div class="dcta-dialogo-destinos" hidden>
				<span class="dcta-dialogo-etiqueta">Devolver a:</span>
				<label><input type="radio" name="documentate_app_transicion" value="devolver_gestion" form="<?php echo esc_attr( $form ); ?>" checked disabled /> A revisión</label>
				<label><input type="radio" name="documentate_app_transicion" value="devolver_area" form="<?php echo esc_attr( $form ); ?>" disabled /> Al área</label>
			</div>
			<label class="dcta-dialogo-etiqueta" for="dcta-dialogo-motivo-texto">Motivo de la devolución</label>
			<textarea id="dcta-dialogo-motivo-texto" name="documentate_app_motivo" form="<?php echo esc_attr( $form ); ?>" rows="4" placeholder="Qué falta o qué hay que corregir" disabled></textarea>
			<p class="dcta-ayuda">El motivo se envía por correo y queda en la actividad.</p>
			<input type="hidden" id="dcta-dialogo-motivo-clave" name="documentate_app_transicion" value="" form="<?php echo esc_attr( $form ); ?>" disabled />
			<div class="dcta-dialogo-pie">
				<button type="button" class="dcta-btn dcta-btn-ton" data-dcta-cerrar="1">Cancelar</button>
				<button type="submit" class="dcta-btn dcta-btn-pri" id="dcta-dialogo-motivo-ok" form="<?php echo esc_attr( $form ); ?>">Devolver</button>
			</div>
		</dialog>

		<dialog class="dcta-dialogo" id="dcta-dialogo-confirmar" aria-labelledby="dcta-dialogo-confirmar-titulo">
			<h2 class="dcta-dialogo-titulo" id="dcta-dialogo-confirmar-titulo">Confirmar</h2>
			<p class="dcta-dialogo-texto" id="dcta-dialogo-confirmar-texto"></p>
			<input type="hidden" id="dcta-dialogo-confirmar-clave" name="documentate_app_transicion" value="" form="<?php echo esc_attr( $form ); ?>" disabled />
			<div class="dcta-dialogo-pie">
				<button type="button" class="dcta-btn dcta-btn-ton" data-dcta-cerrar="1">Cancelar</button>
				<button type="submit" class="dcta-btn dcta-btn-pri" id="dcta-dialogo-confirmar-ok" form="<?php echo esc_attr( $form ); ?>">Confirmar</button>
			</div>
		</dialog>
		<?php
		return (string) ob_get_clean();
	}
}
