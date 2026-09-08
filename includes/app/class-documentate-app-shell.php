<?php
/**
 * Chrome shared by every page of the Documentate front-end application.
 *
 * Same shell pattern as the Registro de Visitas application: a header with the
 * institutional mark, a tab bar with what this person can do, the sheet the
 * content goes in and a one-line footer. The theme chrome is hidden by the
 * stylesheet under `body.documentate-app`, so the app owns the whole page.
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
	 * @var array<int,array<string,array{tab:string,url:string,n:int}>>
	 */
	private static $sections = array();

	/**
	 * URL of the application page, optionally with view arguments.
	 *
	 * @param array<string,string|int> $args Query arguments (vista, doc, estado).
	 * @return string Empty when the page does not exist yet.
	 */
	public static function page_url( array $args = array() ) {
		$page = get_page_by_path( self::PAGE_SLUG );
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
	 * Label of the role, for the chip in the header.
	 *
	 * @return string
	 */
	public static function role() {
		return Documentate_Roles::role_label();
	}

	/**
	 * The sections this person can reach, in tab order.
	 *
	 * Only the actionable tab carries a badge: the documents waiting for this
	 * role to do something with them.
	 *
	 * @return array<string,array{tab:string,url:string,n:int}>
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
	 * @return array<string,array{tab:string,url:string,n:int}>
	 */
	private static function build_sections() {
		if ( Documentate_Roles::is_administration() ) {
			return self::admin_sections();
		}

		if ( Documentate_Roles::is_management() ) {
			return self::management_sections();
		}

		return array(
			'lista' => self::section( 'Mis documentos', self::page_url() ),
			'nuevo' => self::section( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * Tabs of administración: everything first, then what waits for them.
	 *
	 * Same shape as gestión documental — the whole list, then the review tray —
	 * so moving between the two roles does not move the tabs around. Document
	 * types and their templates are not here: that is wp-admin work.
	 *
	 * @return array<string,array{tab:string,url:string,n:int}>
	 */
	private static function admin_sections() {
		return array(
			'lista' => self::section( 'Todos los documentos', self::page_url() ),
			'revision' => self::section(
				'Para revisar',
				self::page_url( array( 'bandeja' => 'revision' ) ),
				Documentate_App_List::count_documents( array( 'post_status' => 'pending' ) )
			),
			'nuevo' => self::section( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * Tabs of gestión documental: their own área, and the documents to complete.
	 *
	 * @return array<string,array{tab:string,url:string,n:int}>
	 */
	private static function management_sections() {
		return array(
			'lista' => self::section( 'Mis documentos', self::page_url() ),
			'revisar' => self::section(
				'Para revisar',
				self::page_url( array( 'bandeja' => 'revisar' ) ),
				Documentate_App_List::count_documents( array( 'post_status' => 'en_gestion' ) )
			),
			'nuevo' => self::section( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * One tab row.
	 *
	 * @param string $tab   Tab label.
	 * @param string $url   Destination.
	 * @param int    $count Badge count (0 hides the badge).
	 * @return array{tab:string,url:string,n:int}
	 */
	private static function section( $tab, $url, $count = 0 ) {
		return array(
			'tab' => $tab,
			'url' => $url,
			'n' => (int) $count,
		);
	}

	/**
	 * Section key a tray belongs to, so the right tab lights up.
	 *
	 * @param string $tray Tray key (mis, revisar, revision, todos).
	 * @return string
	 */
	public static function section_for_tray( $tray ) {
		$map = array(
			'revisar' => 'revisar',
			'revision' => 'revision',
		);

		return isset( $map[ $tray ] ) ? $map[ $tray ] : 'lista';
	}

	/**
	 * Link back to the tray the visitor came from, named after its tab.
	 *
	 * @param string $tray Tray key; empty reads it from the request.
	 * @return string
	 */
	public static function back_link( $tray = '' ) {
		if ( '' === $tray ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
			$tray = isset( $_GET['bandeja'] ) ? sanitize_key( wp_unslash( $_GET['bandeja'] ) ) : '';
		}

		$sections = self::sections();
		$key = self::section_for_tray( $tray );
		if ( ! isset( $sections[ $key ] ) ) {
			$key = 'lista';
		}

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
			'en_gestion' => array( 'gestion', 'En gestión' ),
			'pending' => array( 'pendiente', 'En revisión' ),
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
	 * A document returned to gestión documental keeps the "En gestión" chip;
	 * the returned line under the name is what tells gestión to correct it.
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
	 * Only for the side the return was addressed to. Administración returning
	 * a document to gestión documental writes a note to gestión: the área,
	 * which cannot open the document while it is in gestión, is neither told
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

		$who = 'administracion' === $returned['desde'] ? 'administración' : 'gestión documental';
		$timestamp = strtotime( $returned['fecha'] );
		$date = false === $timestamp ? '' : ' el ' . date_i18n( 'j M', $timestamp );

		return 'Devuelto por ' . $who . $date . ': «' . $returned['motivo'] . '»';
	}

	/**
	 * The returned notice of the document view and the editor.
	 *
	 * The call to action is only added for whoever can actually act on it:
	 * a document sitting in gestión documental is read-only for its área, so
	 * telling them to correct it and send it again is an instruction they
	 * cannot follow.
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
	 * When a document can be returned to two places (administración on a
	 * document that went through gestión) there is a single "Devolver…"
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
	 * @param string $section Active section key.
	 * @param string $title   Page heading.
	 * @param string $sub     One line under the heading.
	 * @return string
	 */
	public static function open( $section, $title, $sub = '' ) {
		self::$sections = array();
		$sections = self::sections();
		$role = self::role();
		$home_url = self::page_url();
		$home_url = '' !== $home_url ? $home_url : home_url( '/' );

		ob_start();
		?>
		<div class="dcta-top">
			<div class="dcta-top-fila">
				<span class="dcta-escudo" role="img" aria-label="Gobierno de Canarias"></span>
				<span class="dcta-marca">
					<small>Consejería de Educación, Formación Profesional, Actividad Física y Deportes</small>
				</span>
				<a class="dcta-marca-app" href="<?php echo esc_url( $home_url ); ?>">Documentate</a>
				<span class="dcta-top-derecha">
					<?php if ( '' !== $role ) : ?>
						<span class="dcta-rol"><?php echo esc_html( $role ); ?></span>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<nav class="dcta-tabs" aria-label="Secciones">
			<div class="dcta-tabs-fila">
				<?php foreach ( $sections as $key => $s ) : ?>
					<a class="dcta-tab<?php echo $key === $section ? ' dcta-tab-on' : ''; ?>"
						<?php echo $key === $section ? ' aria-current="page"' : ''; ?>
						href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['tab'] ); ?>
						<?php if ( $s['n'] > 0 ) : ?>
							<span class="dcta-tab-n"><?php echo esc_html( (string) $s['n'] ); ?></span>
						<?php endif; ?>
					</a>
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
	 * Close the page: the sheet, the one-line footer and the dialogs.
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
		<div class="dcta-pie"><div>
			<span>Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación</span>
			<a href="<?php echo esc_url( $home_url ); ?>">Inicio</a>
		</div></div>
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
				<label><input type="radio" name="documentate_app_transicion" value="devolver_gestion" form="<?php echo esc_attr( $form ); ?>" checked disabled /> Gestión documental</label>
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
