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
 * Header, tabs, sheet and footer of the application.
 */
class Documentate_App_Shell {

	/**
	 * Slug of the page the application lives on ("/documentate/").
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'documentate';

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
	 * The sections this person can reach.
	 *
	 * @return array<string,array{tab:string,url:string}>
	 */
	public static function secciones() {
		$lista = current_user_can( 'manage_options' )
			? __( 'All documents', 'documentate' )
			: __( 'My documents', 'documentate' );

		return array(
			'lista' => array(
				'tab' => $lista,
				'url' => self::page_url(),
			),
			'nuevo' => array(
				'tab' => __( 'New document', 'documentate' ),
				'url' => self::page_url( array( 'vista' => 'nuevo' ) ),
			),
		);
	}

	/**
	 * Chip class and label for a document status.
	 *
	 * @param string $post_status Post status.
	 * @return array{clase:string,texto:string}
	 */
	public static function estado_chip( $post_status ) {
		$estados = array(
			'draft' => array( 'borrador', __( 'Draft', 'documentate' ) ),
			'auto-draft' => array( 'borrador', __( 'Draft', 'documentate' ) ),
			'en_gestion' => array( 'gestion', 'En gestión' ),
			'pending' => array( 'pendiente', __( 'In Review', 'documentate' ) ),
			'publish' => array( 'aprobado', __( 'Approved', 'documentate' ) ),
			'archived' => array( 'archivado', __( 'Archived', 'documentate' ) ),
		);

		$estado = isset( $estados[ $post_status ] ) ? $estados[ $post_status ] : $estados['draft'];

		return array(
			'clase' => 'dcta-estado dcta-estado-' . $estado[0],
			'texto' => $estado[1],
		);
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

		<nav class="dcta-tabs" aria-label="<?php esc_attr_e( 'Sections', 'documentate' ); ?>">
			<div class="dcta-tabs-fila">
				<?php foreach ( $secciones as $clave => $s ) : ?>
					<a class="dcta-tab<?php echo $clave === $seccion ? ' dcta-tab-on' : ''; ?>"
						<?php echo $clave === $seccion ? ' aria-current="page"' : ''; ?>
						href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['tab'] ); ?></a>
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
	 * Close the page: the sheet and the one-line footer.
	 *
	 * @return string
	 */
	public static function cerrar() {
		$inicio = self::page_url();
		$inicio = '' !== $inicio ? $inicio : home_url( '/' );

		ob_start();
		echo '</div>';
		?>
		<div class="dcta-pie"><div>
			<span>Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación</span>
			<a href="<?php echo esc_url( $inicio ); ?>"><?php esc_html_e( 'Home', 'documentate' ); ?></a>
		</div></div>
		<?php
		return (string) ob_get_clean();
	}
}
