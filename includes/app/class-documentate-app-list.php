<?php
/**
 * Document list view of the front-end application.
 *
 * One view with several trays. This class draws them: the counters, the status
 * chips, the área select and the table. Which tray the request means, and what
 * it holds, is Documentate_App_Tray's job; each row of the table is
 * Documentate_App_List_Row's. The tray questions the rest of the application
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
class Documentate_App_List {

	/**
	 * Trays this person may open, and the one they land on.
	 *
	 * @return string[] First element is the default tray.
	 */
	public static function trays() {
		return Documentate_App_Tray::trays();
	}

	/**
	 * Tray asked for by the request, falling back to the default one.
	 *
	 * @return string
	 */
	public static function current_tray() {
		return Documentate_App_Tray::current();
	}

	/**
	 * Query arguments of a tray.
	 *
	 * @param string $tray   Tray key (mis, revisar, revision, todos).
	 * @param string $status Status chip, "devuelto", or empty for every status.
	 * @param int    $area   Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function query_args( $tray, $status, $area = 0 ) {
		return Documentate_App_Tray::query_args( $tray, $status, $area );
	}

	/**
	 * Count the documents a set of query arguments matches.
	 *
	 * @param array<string,mixed> $extra Query arguments on top of the defaults.
	 * @return int
	 */
	public static function count_documents( array $extra ) {
		return Documentate_App_Tray::count_documents( $extra );
	}

	/**
	 * Render the tray the request asks for.
	 *
	 * @return string
	 */
	public static function render() {
		$tray = Documentate_App_Tray::current();
		$status = Documentate_App_Tray::current_status( $tray );
		$area = Documentate_App_Tray::current_area();
		$titles = self::titles( $tray );

		$html = Documentate_App_Shell::open(
			Documentate_App_Shell::section_for_tray( $tray ),
			$titles[0],
			$titles[1]
		);

		if ( Documentate_App_Tray::without_scope( $tray ) ) {
			return $html
				. '<div class="dcta-aviso">Tu usuario no tiene un ámbito asignado. Contacta con administración.</div>'
				. Documentate_App_Shell::close();
		}

		$html .= self::render_notices();
		$html .= self::render_counters( $tray, $area );
		$html .= self::render_filters( $tray, $status, $area );
		$html .= self::render_table( $tray, $status, $area );

		return $html . Documentate_App_Shell::close();
	}

	/**
	 * Feedback left by a handler that redirected here.
	 *
	 * A return is the one action that lands on a tray instead of on the
	 * document, so this is where the reviewer is told it went through.
	 *
	 * @return string
	 */
	private static function render_notices() {
		if ( '1' === Documentate_App_Detail::flag( 'devuelto' ) ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Documento devuelto con el motivo indicado.</div>';
		}

		$error = Documentate_App_Detail::flag( 'error' );

		return '' === $error
			? ''
			: '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( Documentate_App_Detail::error_text( $error ) ) . '</div>';
	}

	/**
	 * Heading and sub-heading of a tray.
	 *
	 * @param string $tray Tray key.
	 * @return array{0:string,1:string}
	 */
	private static function titles( $tray ) {
		$titles = array(
			'mis' => array( 'Mis documentos', 'Los documentos de tu área, con su estado.' ),
			'revisar' => array( 'Para revisar', 'Documentos que han salido de su área y esperan a gestión documental.' ),
			'revision' => array( 'Para revisar', 'Documentos que esperan tu aprobación.' ),
			'todos' => array( 'Todos los documentos', 'Todas las áreas, todos los estados.' ),
		);

		return isset( $titles[ $tray ] ) ? $titles[ $tray ] : $titles['mis'];
	}

	/**
	 * The counters of a tray: what is waiting, and for whom.
	 *
	 * @param string $tray Tray key.
	 * @param int    $area Área filter, 0 for every área.
	 * @return string
	 */
	private static function render_counters( $tray, $area ) {
		$counters = self::tray_counters( $tray );

		$html = '<div class="dcta-cifras">';
		$first = true;
		foreach ( $counters as $counter ) {
			$args = Documentate_App_Tray::query_args( $tray, $counter[1], $area );
			$label = $counter[0];
			if ( 'draft' === $counter[1] ) {
				$label .= self::returned_suffix( $tray, $area );
			}

			$html .= '<div class="dcta-cifra' . ( $first ? ' dcta-cifra-acento' : '' ) . '">'
				. '<b>' . esc_html( (string) Documentate_App_Tray::count_documents( $args ) ) . '</b>'
				. '<span>' . esc_html( $label ) . '</span>'
				. '</div>';
			$first = false;
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
	 * @param string $tray Tray key.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function tray_counters( $tray ) {
		$rows = array(
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

		return isset( $rows[ $tray ] ) ? $rows[ $tray ] : $revision;
	}

	/**
	 * " (n devueltos)" next to the drafts counter, only when there are any.
	 *
	 * @param string $tray Tray key.
	 * @param int    $area Área filter.
	 * @return string
	 */
	private static function returned_suffix( $tray, $area ) {
		$args = Documentate_App_Tray::query_args( $tray, 'devuelto', $area );
		$args['post_status'] = 'draft';
		$returned_count = Documentate_App_Tray::count_documents( $args );

		if ( 0 === $returned_count ) {
			return '';
		}

		return 1 === $returned_count ? ' (1 devuelto)' : ' (' . $returned_count . ' devueltos)';
	}

	/**
	 * The status chips of a tray, and the área select for administración.
	 *
	 * A chip is drawn only when it would find something; "Todos" always is.
	 *
	 * @param string $tray   Tray key.
	 * @param string $status Active status filter.
	 * @param int    $area   Área filter.
	 * @return string
	 */
	private static function render_filters( $tray, $status, $area ) {
		$chips = array( 'devuelto' => 'Devuelto' );
		foreach ( Documentate_Statuses::labels() as $status_key => $label ) {
			if ( in_array( $status_key, Documentate_App_Tray::statuses( $tray ), true ) ) {
				$chips[ $status_key ] = 'draft' === $status_key ? 'Por enviar' : $label;
			}
		}

		$html = '<div class="dcta-filtros">';
		$html .= self::filter_chip( $tray, 'todos', 'Todos', '' === $status, $area );

		foreach ( $chips as $key => $label ) {
			$args = Documentate_App_Tray::query_args( $tray, $key, $area );
			if ( 0 === Documentate_App_Tray::count_documents( $args ) ) {
				continue;
			}
			$html .= self::filter_chip( $tray, $key, $label, $key === $status, $area );
		}

		$html .= self::render_search();

		return $html . '</div>' . self::render_area_select( $tray, $status, $area );
	}

	/**
	 * The quick filter box, to the right of the chips.
	 *
	 * It narrows the rows already on screen as you type (documentate-app.js);
	 * without JavaScript it stays hidden, because there is nothing behind it.
	 *
	 * @return string
	 */
	private static function render_search() {
		return '<span class="dcta-busqueda" data-dcta-busqueda hidden>'
			. '<label class="screen-reader-text" for="dcta-busqueda">Filtrar los documentos de la lista</label>'
			. '<input type="search" id="dcta-busqueda" class="dcta-busqueda-campo" placeholder="Filtrar…" autocomplete="off" />'
			. '</span>';
	}

	/**
	 * One filter chip.
	 *
	 * @param string $tray   Tray key.
	 * @param string $key    Status key, "devuelto" or "todos".
	 * @param string $label  Chip label.
	 * @param bool   $active Whether it is the active filter.
	 * @param int    $area   Área filter to keep.
	 * @return string
	 */
	private static function filter_chip( $tray, $key, $label, $active, $area ) {
		$args = array( 'estado' => $key );
		if ( 'mis' !== $tray ) {
			$args['bandeja'] = $tray;
		}
		if ( $area > 0 ) {
			$args['area'] = (string) $area;
		}

		return '<a class="dcta-fchip' . ( $active ? ' dcta-fchip-on' : '' ) . '" href="'
			. esc_url( Documentate_App_Shell::page_url( $args ) ) . '">'
			. esc_html( $label ) . '</a>';
	}

	/**
	 * The área select administración narrows the trays with.
	 *
	 * @param string $tray   Tray key.
	 * @param string $status Active status filter.
	 * @param int    $area   Active área filter.
	 * @return string
	 */
	private static function render_area_select( $tray, $status, $area ) {
		if ( ! Documentate_Roles::is_administration() ) {
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
		$html .= Documentate_App_Shell::page_query_fields();
		$html .= '<input type="hidden" name="bandeja" value="' . esc_attr( $tray ) . '" />';
		$html .= '<input type="hidden" name="estado" value="' . esc_attr( '' === $status ? 'todos' : $status ) . '" />';
		$html .= '<label for="dcta-area">Área</label>';
		$html .= '<select id="dcta-area" name="area">';
		$html .= '<option value="0">Todas las áreas</option>';
		foreach ( $areas as $term ) {
			$html .= '<option value="' . esc_attr( (string) $term->term_id ) . '"'
				. selected( $area, (int) $term->term_id, false ) . '>'
				. esc_html( $term->name ) . '</option>';
		}
		$html .= '</select>';
		$html .= '<button type="submit" class="dcta-btn dcta-btn-ton">Filtrar</button>';

		return $html . '</form>';
	}

	/**
	 * The rows of a tray, with their header and footer.
	 *
	 * @param string $tray   Tray key.
	 * @param string $status Active status filter.
	 * @param int    $area   Área filter.
	 * @return string
	 */
	private static function render_table( $tray, $status, $area ) {
		$query = new WP_Query( Documentate_App_Tray::query_args( $tray, $status, $area ) );

		$html = '<div class="dcta-tabla">';
		$html .= '<div class="dcta-fila dcta-fila-cab">'
			. '<span>Documento</span>'
			. '<span>Tipo</span>'
			. '<span>Actualizado</span>'
			. '<span>Estado</span>'
			. '<span></span>'
			. '</div>';

		if ( ! $query->have_posts() ) {
			return $html . '<div class="dcta-vacio">' . esc_html( self::empty_text( $tray ) ) . '</div></div>';
		}

		foreach ( $query->posts as $post ) {
			$html .= Documentate_App_List_Row::render( $post, $tray );
		}

		// The total, not the drawn rows: the quick filter only sees one page,
		// and without it its counts would claim the tray holds just those. The
		// live region is what announces every rewrite of this footer, which is
		// the only signal a screen reader gets while filtering.
		$html .= '<div class="dcta-tabla-pie" role="status" data-dcta-pie data-dcta-pie-total="' . esc_attr( (string) (int) $query->found_posts ) . '">'
			. esc_html( self::footer_text( (int) $query->found_posts, count( $query->posts ) ) ) . '</div>';

		return $html . '</div>';
	}

	/**
	 * The footer of the table: how many there are, and how many are drawn.
	 *
	 * The list is not paginated; when a tray holds more than one page it says
	 * so instead of letting the count contradict the rows.
	 *
	 * @param int $total    Documents the tray matches.
	 * @param int $shown Documents drawn.
	 * @return string
	 */
	private static function footer_text( $total, $shown ) {
		if ( $total > $shown ) {
			return 'mostrando ' . $shown . ' de ' . $total . ' documentos · afina con los filtros';
		}

		return 1 === $total ? '1 documento' : $total . ' documentos';
	}

	/**
	 * What an empty tray says.
	 *
	 * @param string $tray Tray key.
	 * @return string
	 */
	private static function empty_text( $tray ) {
		$texts = array(
			'mis' => 'Todavía no hay documentos. Crea el primero desde «Nuevo documento».',
			'revisar' => 'No hay documentos pendientes de revisar.',
			'revision' => 'No hay documentos pendientes de revisar.',
			'todos' => 'No hay documentos.',
		);

		return isset( $texts[ $tray ] ) ? $texts[ $tray ] : $texts['mis'];
	}
}
