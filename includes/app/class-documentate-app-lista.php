<?php
/**
 * Document list view of the front-end application.
 *
 * One view with several trays. This class draws them: the counters, the status
 * chips, the área select and the table. Which tray the request means, and what
 * it holds, is Documentate_App_Bandeja's job; each row of the table is
 * Documentate_App_Lista_Fila's. The tray questions the rest of the application
 * asks the list — its trays, the current one, its query arguments and its
 * counts — stay on this class and are answered by the tray.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the document trays.
 */
class Documentate_App_Lista {

	/**
	 * Trays this person may open, and the one they land on.
	 *
	 * @return string[] First element is the default tray.
	 */
	public static function bandejas() {
		return Documentate_App_Bandeja::bandejas();
	}

	/**
	 * Tray asked for by the request, falling back to the default one.
	 *
	 * @return string
	 */
	public static function bandeja_actual() {
		return Documentate_App_Bandeja::actual();
	}

	/**
	 * Query arguments of a tray.
	 *
	 * @param string $bandeja Tray key (mis, revisar, revision, todos).
	 * @param string $estado  Status chip, "devuelto", or empty for every status.
	 * @param int    $area    Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function argumentos_consulta( $bandeja, $estado, $area = 0 ) {
		return Documentate_App_Bandeja::argumentos_consulta( $bandeja, $estado, $area );
	}

	/**
	 * Count the documents a set of query arguments matches.
	 *
	 * @param array<string,mixed> $extra Query arguments on top of the defaults.
	 * @return int
	 */
	public static function contar( array $extra ) {
		return Documentate_App_Bandeja::contar( $extra );
	}

	/**
	 * Render the tray the request asks for.
	 *
	 * @return string
	 */
	public static function render() {
		$bandeja = Documentate_App_Bandeja::actual();
		$estado = Documentate_App_Bandeja::estado_actual( $bandeja );
		$area = Documentate_App_Bandeja::area_actual();
		$titulos = self::titulos( $bandeja );

		$html = Documentate_App_Shell::abrir(
			Documentate_App_Shell::seccion_de_bandeja( $bandeja ),
			$titulos[0],
			$titulos[1]
		);

		if ( Documentate_App_Bandeja::sin_ambito( $bandeja ) ) {
			return $html
				. '<div class="dcta-aviso">Tu usuario no tiene un ámbito asignado. Contacta con administración.</div>'
				. Documentate_App_Shell::cerrar();
		}

		$html .= self::render_avisos();
		$html .= self::render_contadores( $bandeja, $area );
		$html .= self::render_filtros( $bandeja, $estado, $area );
		$html .= self::render_tabla( $bandeja, $estado, $area );

		return $html . Documentate_App_Shell::cerrar();
	}

	/**
	 * Feedback left by a handler that redirected here.
	 *
	 * A return is the one action that lands on a tray instead of on the
	 * document, so this is where the reviewer is told it went through.
	 *
	 * @return string
	 */
	private static function render_avisos() {
		if ( '1' === Documentate_App_Detalle::bandera( 'devuelto' ) ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Documento devuelto con el motivo indicado.</div>';
		}

		$error = Documentate_App_Detalle::bandera( 'error' );

		return '' === $error
			? ''
			: '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( Documentate_App_Detalle::texto_error( $error ) ) . '</div>';
	}

	/**
	 * Heading and sub-heading of a tray.
	 *
	 * @param string $bandeja Tray key.
	 * @return array{0:string,1:string}
	 */
	private static function titulos( $bandeja ) {
		$titulos = array(
			'mis' => array( 'Mis documentos', 'Los documentos de tu área, con su estado.' ),
			'revisar' => array( 'Para revisar', 'Documentos que han salido de su área y esperan a gestión documental.' ),
			'revision' => array( 'Para revisar', 'Documentos que esperan tu aprobación.' ),
			'todos' => array( 'Todos los documentos', 'Todas las áreas, todos los estados.' ),
		);

		return isset( $titulos[ $bandeja ] ) ? $titulos[ $bandeja ] : $titulos['mis'];
	}

	/**
	 * The counters of a tray: what is waiting, and for whom.
	 *
	 * @param string $bandeja Tray key.
	 * @param int    $area    Área filter, 0 for every área.
	 * @return string
	 */
	private static function render_contadores( $bandeja, $area ) {
		$cifras = self::cifras_bandeja( $bandeja );

		$html = '<div class="dcta-cifras">';
		$primero = true;
		foreach ( $cifras as $cifra ) {
			$args = Documentate_App_Bandeja::argumentos_consulta( $bandeja, $cifra[1], $area );
			$etiqueta = $cifra[0];
			if ( 'draft' === $cifra[1] ) {
				$etiqueta .= self::sufijo_devueltos( $bandeja, $area );
			}

			$html .= '<div class="dcta-cifra' . ( $primero ? ' dcta-cifra-acento' : '' ) . '">'
				. '<b>' . esc_html( (string) Documentate_App_Bandeja::contar( $args ) ) . '</b>'
				. '<span>' . esc_html( $etiqueta ) . '</span>'
				. '</div>';
			$primero = false;
		}

		return $html . '</div>';
	}

	/**
	 * The counters of a tray, the one it exists for first.
	 *
	 * Gestión opens "Para revisar" to work on what is in gestión, and
	 * administración to approve what is in revisión: that is the figure the
	 * accent belongs to.
	 *
	 * @param string $bandeja Tray key.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function cifras_bandeja( $bandeja ) {
		$filas = array(
			'mis' => array(
				array( 'Por enviar', 'draft' ),
				array( 'En gestión', 'en_gestion' ),
				array( 'En revisión', 'pending' ),
				array( 'Aprobados', 'publish' ),
			),
			'revisar' => array(
				array( 'En gestión', 'en_gestion' ),
				array( 'En revisión', 'pending' ),
				array( 'Aprobados', 'publish' ),
				array( 'Devueltos', 'devuelto' ),
			),
		);

		$revision = array(
			array( 'En revisión', 'pending' ),
			array( 'En gestión', 'en_gestion' ),
			array( 'Aprobados', 'publish' ),
			array( 'Devueltos', 'devuelto' ),
		);

		return isset( $filas[ $bandeja ] ) ? $filas[ $bandeja ] : $revision;
	}

	/**
	 * " (n devueltos)" next to the drafts counter, only when there are any.
	 *
	 * @param string $bandeja Tray key.
	 * @param int    $area    Área filter.
	 * @return string
	 */
	private static function sufijo_devueltos( $bandeja, $area ) {
		$args = Documentate_App_Bandeja::argumentos_consulta( $bandeja, 'devuelto', $area );
		$args['post_status'] = 'draft';
		$devueltos = Documentate_App_Bandeja::contar( $args );

		if ( 0 === $devueltos ) {
			return '';
		}

		return 1 === $devueltos ? ' (1 devuelto)' : ' (' . $devueltos . ' devueltos)';
	}

	/**
	 * The status chips of a tray, and the área select for administración.
	 *
	 * A chip is drawn only when it would find something; "Todos" always is.
	 *
	 * @param string $bandeja Tray key.
	 * @param string $estado  Active status filter.
	 * @param int    $area    Área filter.
	 * @return string
	 */
	private static function render_filtros( $bandeja, $estado, $area ) {
		$chips = array( 'devuelto' => 'Devuelto' );
		foreach ( Documentate_Estados::etiquetas() as $status => $etiqueta ) {
			if ( in_array( $status, Documentate_App_Bandeja::estados( $bandeja ), true ) ) {
				$chips[ $status ] = 'draft' === $status ? 'Por enviar' : $etiqueta;
			}
		}

		$html = '<div class="dcta-filtros">';
		$html .= self::chip_filtro( $bandeja, 'todos', 'Todos', '' === $estado, $area );

		foreach ( $chips as $clave => $etiqueta ) {
			$args = Documentate_App_Bandeja::argumentos_consulta( $bandeja, $clave, $area );
			if ( 0 === Documentate_App_Bandeja::contar( $args ) ) {
				continue;
			}
			$html .= self::chip_filtro( $bandeja, $clave, $etiqueta, $clave === $estado, $area );
		}

		$html .= self::render_busqueda();

		return $html . '</div>' . self::render_area_select( $bandeja, $estado, $area );
	}

	/**
	 * The quick filter box, to the right of the chips.
	 *
	 * It narrows the rows already on screen as you type (documentate-app.js);
	 * without JavaScript it stays hidden, because there is nothing behind it.
	 *
	 * @return string
	 */
	private static function render_busqueda() {
		return '<span class="dcta-busqueda" data-dcta-busqueda hidden>'
			. '<label class="screen-reader-text" for="dcta-busqueda">Filtrar los documentos de la lista</label>'
			. '<input type="search" id="dcta-busqueda" class="dcta-busqueda-campo" placeholder="Filtrar…" autocomplete="off" />'
			. '</span>';
	}

	/**
	 * One filter chip.
	 *
	 * @param string $bandeja  Tray key.
	 * @param string $clave    Status key, "devuelto" or "todos".
	 * @param string $etiqueta Chip label.
	 * @param bool   $activo   Whether it is the active filter.
	 * @param int    $area     Área filter to keep.
	 * @return string
	 */
	private static function chip_filtro( $bandeja, $clave, $etiqueta, $activo, $area ) {
		$args = array( 'estado' => $clave );
		if ( 'mis' !== $bandeja ) {
			$args['bandeja'] = $bandeja;
		}
		if ( $area > 0 ) {
			$args['area'] = (string) $area;
		}

		return '<a class="dcta-fchip' . ( $activo ? ' dcta-fchip-on' : '' ) . '" href="'
			. esc_url( Documentate_App_Shell::page_url( $args ) ) . '">'
			. esc_html( $etiqueta ) . '</a>';
	}

	/**
	 * The área select administración narrows the trays with.
	 *
	 * @param string $bandeja Tray key.
	 * @param string $estado  Active status filter.
	 * @param int    $area    Active área filter.
	 * @return string
	 */
	private static function render_area_select( $bandeja, $estado, $area ) {
		if ( ! Documentate_Roles::es_administracion() ) {
			return '';
		}

		$areas = get_terms(
			array(
				'taxonomy' => 'category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $areas ) || empty( $areas ) ) {
			return '';
		}

		$html = '<form class="dcta-areas" method="get" action="' . esc_url( Documentate_App_Shell::page_url() ) . '">';
		$html .= Documentate_App_Shell::campos_de_la_pagina();
		$html .= '<input type="hidden" name="bandeja" value="' . esc_attr( $bandeja ) . '" />';
		$html .= '<input type="hidden" name="estado" value="' . esc_attr( '' === $estado ? 'todos' : $estado ) . '" />';
		$html .= '<label for="dcta-area">Área</label>';
		$html .= '<select id="dcta-area" name="area">';
		$html .= '<option value="0">Todas las áreas</option>';
		foreach ( $areas as $termino ) {
			$html .= '<option value="' . esc_attr( (string) $termino->term_id ) . '"'
				. selected( $area, (int) $termino->term_id, false ) . '>'
				. esc_html( $termino->name ) . '</option>';
		}
		$html .= '</select>';
		$html .= '<button type="submit" class="dcta-btn dcta-btn-ton">Filtrar</button>';

		return $html . '</form>';
	}

	/**
	 * The rows of a tray, with their header and footer.
	 *
	 * @param string $bandeja Tray key.
	 * @param string $estado  Active status filter.
	 * @param int    $area    Área filter.
	 * @return string
	 */
	private static function render_tabla( $bandeja, $estado, $area ) {
		$query = new WP_Query( Documentate_App_Bandeja::argumentos_consulta( $bandeja, $estado, $area ) );

		$html = '<div class="dcta-tabla">';
		$html .= '<div class="dcta-fila dcta-fila-cab">'
			. '<span>Documento</span>'
			. '<span>Tipo</span>'
			. '<span>Actualizado</span>'
			. '<span>Estado</span>'
			. '<span></span>'
			. '</div>';

		if ( ! $query->have_posts() ) {
			return $html . '<div class="dcta-vacio">' . esc_html( self::texto_vacio( $bandeja ) ) . '</div></div>';
		}

		foreach ( $query->posts as $post ) {
			$html .= Documentate_App_Lista_Fila::render( $post, $bandeja );
		}

		// The total, not the drawn rows: the quick filter only sees one page,
		// and without it its counts would claim the tray holds just those. The
		// live region is what announces every rewrite of this footer, which is
		// the only signal a screen reader gets while filtering.
		$html .= '<div class="dcta-tabla-pie" role="status" data-dcta-pie data-dcta-pie-total="' . esc_attr( (string) (int) $query->found_posts ) . '">'
			. esc_html( self::texto_pie( (int) $query->found_posts, count( $query->posts ) ) ) . '</div>';

		return $html . '</div>';
	}

	/**
	 * The footer of the table: how many there are, and how many are drawn.
	 *
	 * The list is not paginated; when a tray holds more than one page it says
	 * so instead of letting the count contradict the rows.
	 *
	 * @param int $total    Documents the tray matches.
	 * @param int $mostrado Documents drawn.
	 * @return string
	 */
	private static function texto_pie( $total, $mostrado ) {
		if ( $total > $mostrado ) {
			return 'mostrando ' . $mostrado . ' de ' . $total . ' documentos · afina con los filtros';
		}

		return 1 === $total ? '1 documento' : $total . ' documentos';
	}

	/**
	 * What an empty tray says.
	 *
	 * @param string $bandeja Tray key.
	 * @return string
	 */
	private static function texto_vacio( $bandeja ) {
		$textos = array(
			'mis' => 'Todavía no hay documentos. Crea el primero desde «Nuevo documento».',
			'revisar' => 'No hay documentos pendientes de revisar.',
			'revision' => 'No hay documentos pendientes de revisar.',
			'todos' => 'No hay documentos.',
		);

		return isset( $textos[ $bandeja ] ) ? $textos[ $bandeja ] : $textos['mis'];
	}
}
