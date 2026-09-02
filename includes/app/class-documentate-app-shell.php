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
	const FORM_COMENTARIO_ID = 'dcta-app-comentario';

	/**
	 * Transitions the rule table keeps for wp-admin only.
	 *
	 * Archiving is a records-management decision taken from the admin list,
	 * where the archive links have always lived; the application would offer
	 * it with no confirmation and no feedback of its own.
	 *
	 * @var string[]
	 */
	const SOLO_WP_ADMIN = array( 'archivar', 'desarchivar' );

	/**
	 * Tabs already built in this request, keyed by user ID.
	 *
	 * The badge of the actionable tab costs a count query and every view asks
	 * for the tabs twice (the tab bar and the back link), so they are built
	 * once per page: abrir() empties the cache as a page starts rendering.
	 *
	 * @var array<int,array<string,array{tab:string,url:string,n:int,externo:bool}>>
	 */
	private static $secciones = array();

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
	public static function campos_de_la_pagina() {
		$consulta = (string) wp_parse_url( self::page_url(), PHP_URL_QUERY );
		if ( '' === $consulta ) {
			return '';
		}

		$pares = array();
		wp_parse_str( $consulta, $pares );

		$html = '';
		foreach ( array_filter( $pares, 'is_scalar' ) as $nombre => $valor ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $nombre ) . '" value="' . esc_attr( (string) $valor ) . '" />';
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
	public static function rol() {
		return Documentate_Roles::etiqueta_rol();
	}

	/**
	 * The sections this person can reach, in tab order.
	 *
	 * Only the actionable tab carries a badge: the documents waiting for this
	 * role to do something with them.
	 *
	 * @return array<string,array{tab:string,url:string,n:int,externo:bool}>
	 */
	public static function secciones() {
		$usuario = get_current_user_id();
		if ( ! isset( self::$secciones[ $usuario ] ) ) {
			self::$secciones[ $usuario ] = self::construir_secciones();
		}

		return self::$secciones[ $usuario ];
	}

	/**
	 * Build the tabs of the current person.
	 *
	 * @return array<string,array{tab:string,url:string,n:int,externo:bool}>
	 */
	private static function construir_secciones() {
		if ( Documentate_Roles::es_administracion() ) {
			return self::secciones_administracion();
		}

		if ( Documentate_Roles::es_gestion() ) {
			return self::secciones_gestion();
		}

		return array(
			'lista' => self::seccion( 'Mis documentos', self::page_url() ),
			'nuevo' => self::seccion( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * Tabs of administración: the review tray first, then everything.
	 *
	 * @return array<string,array{tab:string,url:string,n:int,externo:bool}>
	 */
	private static function secciones_administracion() {
		return array(
			'revision' => self::seccion(
				'Para revisar',
				self::page_url( array( 'bandeja' => 'revision' ) ),
				Documentate_App_Lista::contar( array( 'post_status' => 'pending' ) )
			),
			'lista' => self::seccion( 'Todos los documentos', self::page_url() ),
			'nuevo' => self::seccion( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
			'tipos' => self::seccion(
				'Tipos y plantillas ↗',
				admin_url( 'edit-tags.php?taxonomy=documentate_doc_type&post_type=documentate_document' ),
				0,
				true
			),
		);
	}

	/**
	 * Tabs of gestión documental: their own área, and the documents to complete.
	 *
	 * @return array<string,array{tab:string,url:string,n:int,externo:bool}>
	 */
	private static function secciones_gestion() {
		return array(
			'lista' => self::seccion( 'Mis documentos', self::page_url() ),
			'revisar' => self::seccion(
				'Para revisar',
				self::page_url( array( 'bandeja' => 'revisar' ) ),
				Documentate_App_Lista::contar( array( 'post_status' => 'en_gestion' ) )
			),
			'nuevo' => self::seccion( 'Nuevo documento', self::page_url( array( 'vista' => 'nuevo' ) ) ),
		);
	}

	/**
	 * One tab row.
	 *
	 * @param string $tab     Tab label.
	 * @param string $url     Destination.
	 * @param int    $numero  Badge count (0 hides the badge).
	 * @param bool   $externo Whether the tab leaves the application.
	 * @return array{tab:string,url:string,n:int,externo:bool}
	 */
	private static function seccion( $tab, $url, $numero = 0, $externo = false ) {
		return array(
			'tab' => $tab,
			'url' => $url,
			'n' => (int) $numero,
			'externo' => (bool) $externo,
		);
	}

	/**
	 * Section key a tray belongs to, so the right tab lights up.
	 *
	 * @param string $bandeja Tray key (mis, revisar, revision, todos).
	 * @return string
	 */
	public static function seccion_de_bandeja( $bandeja ) {
		$mapa = array(
			'revisar' => 'revisar',
			'revision' => 'revision',
		);

		return isset( $mapa[ $bandeja ] ) ? $mapa[ $bandeja ] : 'lista';
	}

	/**
	 * Link back to the tray the visitor came from, named after its tab.
	 *
	 * @param string $bandeja Tray key; empty reads it from the request.
	 * @return string
	 */
	public static function enlace_volver( $bandeja = '' ) {
		if ( '' === $bandeja ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
			$bandeja = isset( $_GET['bandeja'] ) ? sanitize_key( wp_unslash( $_GET['bandeja'] ) ) : '';
		}

		$secciones = self::secciones();
		$clave = self::seccion_de_bandeja( $bandeja );
		if ( ! isset( $secciones[ $clave ] ) ) {
			$clave = 'lista';
		}

		if ( ! isset( $secciones[ $clave ] ) ) {
			return '';
		}

		return '<a class="dcta-editor-volver" href="' . esc_url( $secciones[ $clave ]['url'] ) . '">'
			. '← ' . esc_html( $secciones[ $clave ]['tab'] ) . '</a>';
	}

	/**
	 * Chip class and label for a document status.
	 *
	 * @param string $post_status Post status.
	 * @return array{clase:string,texto:string}
	 */
	public static function estado_chip( $post_status ) {
		$estados = array(
			'draft' => array( 'borrador', 'Borrador' ),
			'auto-draft' => array( 'borrador', 'Borrador' ),
			'en_gestion' => array( 'gestion', 'En gestión' ),
			'pending' => array( 'pendiente', 'En revisión' ),
			'publish' => array( 'aprobado', 'Aprobado' ),
			'archived' => array( 'archivado', 'Archivado' ),
		);

		$estado = isset( $estados[ $post_status ] ) ? $estados[ $post_status ] : $estados['draft'];

		return array(
			'clase' => 'dcta-estado dcta-estado-' . $estado[0],
			'texto' => $estado[1],
		);
	}

	/**
	 * Chip of a document: the status, or "Devuelto" when it came back to the área.
	 *
	 * A document returned to gestión documental keeps the "En gestión" chip;
	 * the returned line under the name is what tells gestión to correct it.
	 *
	 * @param WP_Post $post Document.
	 * @return array{clase:string,texto:string}
	 */
	public static function chip( $post ) {
		if ( 'draft' === $post->post_status && null !== Documentate_Documento::devuelto( $post ) ) {
			return array(
				'clase' => 'dcta-estado dcta-estado-devuelto',
				'texto' => 'Devuelto',
			);
		}

		return self::estado_chip( $post->post_status );
	}

	/**
	 * The "Devuelto por … el … : «…»" line of a returned document.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when the document was not returned.
	 */
	public static function texto_devuelto( $post ) {
		$devuelto = Documentate_Documento::devuelto( $post );
		if ( null === $devuelto ) {
			return '';
		}

		$quien = 'administracion' === $devuelto['desde'] ? 'administración' : 'gestión documental';
		$marca = strtotime( $devuelto['fecha'] );
		$fecha = false === $marca ? '' : ' el ' . date_i18n( 'j M', $marca );

		return 'Devuelto por ' . $quien . $fecha . ': «' . $devuelto['motivo'] . '»';
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
	public static function transiciones_app( WP_Post $post ) {
		$disponibles = Documentate_Transiciones::disponibles( $post );

		foreach ( self::SOLO_WP_ADMIN as $clave ) {
			unset( $disponibles[ $clave ] );
		}

		return $disponibles;
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
	public static function botones_transicion( $post ) {
		$disponibles = self::transiciones_app( $post );
		if ( empty( $disponibles ) ) {
			return '';
		}

		$devoluciones = array();
		$html = '';
		foreach ( $disponibles as $clave => $regla ) {
			if ( $regla['motivo'] ) {
				$devoluciones[ $clave ] = $regla;
				continue;
			}

			$html .= self::boton_transicion(
				$clave,
				(string) $regla['etiqueta'],
				'dcta-btn-pri',
				' data-confirmar="' . esc_attr( (string) $regla['confirmar'] ) . '"'
			);
		}

		return $html . self::botones_devolucion( $devoluciones );
	}

	/**
	 * The return buttons, and the fallback for browsers without dialogs.
	 *
	 * When a document can be returned to two places (administración on a
	 * document that went through gestión) there is a single "Devolver…"
	 * button and the dialog asks where to.
	 *
	 * @param array<string,array<string,mixed>> $devoluciones Return rules available.
	 * @return string
	 */
	private static function botones_devolucion( array $devoluciones ) {
		if ( empty( $devoluciones ) ) {
			return '';
		}

		if ( count( $devoluciones ) > 1 ) {
			return self::boton_transicion( 'devolver_area', 'Devolver…', 'dcta-btn-ton', ' data-motivo="1" data-destinos="1"' )
				. self::fallback_motivo( $devoluciones );
		}

		$html = '';
		foreach ( $devoluciones as $clave => $regla ) {
			$html .= self::boton_transicion( $clave, (string) $regla['etiqueta'], 'dcta-btn-ton', ' data-motivo="1"' );
		}

		return $html . self::fallback_motivo( array() );
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
	private static function fallback_motivo( array $extra ) {
		$html = '<details class="dcta-motivo-fallback">'
			. '<summary>Motivo de la devolución</summary>'
			. '<label for="dcta-motivo-fallback-texto">Motivo de la devolución</label>'
			. '<textarea id="dcta-motivo-fallback-texto" name="documentate_app_motivo" rows="3" placeholder="Qué falta o qué hay que corregir"></textarea>'
			. '<p class="dcta-ayuda">El motivo se envía por correo y queda en la actividad.</p>';

		foreach ( $extra as $clave => $regla ) {
			$html .= self::boton_transicion( $clave, (string) $regla['etiqueta'], 'dcta-btn-ton', '' );
		}

		return $html . '</details>';
	}

	/**
	 * One transition button.
	 *
	 * @param string $clave    Transition key posted by the button.
	 * @param string $etiqueta Button label.
	 * @param string $clase    Button modifier class.
	 * @param string $extra    Extra attributes, already escaped.
	 * @return string
	 */
	private static function boton_transicion( $clave, $etiqueta, $clase, $extra ) {
		return '<button type="submit" class="dcta-btn ' . esc_attr( $clase ) . '"'
			. ' name="documentate_app_transicion" value="' . esc_attr( $clave ) . '"' . $extra . '>'
			. esc_html( $etiqueta ) . '</button>';
	}

	/**
	 * Open the page: header, tabs and the sheet the content goes in.
	 *
	 * @param string $seccion Active section key.
	 * @param string $titulo  Page heading.
	 * @param string $sub     One line under the heading.
	 * @return string
	 */
	public static function abrir( $seccion, $titulo, $sub = '' ) {
		self::$secciones = array();
		$secciones = self::secciones();
		$rol = self::rol();
		$inicio = self::page_url();
		$inicio = '' !== $inicio ? $inicio : home_url( '/' );

		ob_start();
		?>
		<div class="dcta-top">
			<div class="dcta-top-fila">
				<span class="dcta-escudo" role="img" aria-label="Gobierno de Canarias"></span>
				<span class="dcta-marca">
					<small>Consejería de Educación, Formación Profesional, Actividad Física y Deportes</small>
				</span>
				<a class="dcta-marca-app" href="<?php echo esc_url( $inicio ); ?>">Documentate</a>
				<span class="dcta-top-derecha">
					<?php if ( '' !== $rol ) : ?>
						<span class="dcta-rol"><?php echo esc_html( $rol ); ?></span>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<nav class="dcta-tabs" aria-label="Secciones">
			<div class="dcta-tabs-fila">
				<?php foreach ( $secciones as $clave => $s ) : ?>
					<a class="dcta-tab<?php echo $clave === $seccion ? ' dcta-tab-on' : ''; ?>"
						<?php echo $clave === $seccion ? ' aria-current="page"' : ''; ?>
						href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['tab'] ); ?>
						<?php if ( $s['n'] > 0 ) : ?>
							<span class="dcta-tab-n"><?php echo esc_html( (string) $s['n'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>

		<div class="dcta-hoja">
			<?php if ( '' !== $titulo ) : ?>
				<h1 class="dcta-h1"><?php echo esc_html( $titulo ); ?></h1>
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
	 * @param bool $dialogos Whether the view has a form the dialogs post through.
	 * @return string
	 */
	public static function cerrar( $dialogos = false ) {
		$inicio = self::page_url();
		$inicio = '' !== $inicio ? $inicio : home_url( '/' );

		ob_start();
		echo '</div>';
		?>
		<div class="dcta-pie"><div>
			<span>Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación</span>
			<a href="<?php echo esc_url( $inicio ); ?>">Inicio</a>
		</div></div>
		<?php
		$html = (string) ob_get_clean();

		return $dialogos ? $html . self::dialogos() : $html;
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
	public static function dialogos() {
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
