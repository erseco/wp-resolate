<?php
/**
 * Document list view of the front-end application.
 *
 * One list per person. This class draws it: the status chips with their
 * counts, the área select and the table. Which filters the request means, and
 * what they hold, is Documentate_App_Tray's job; each row of the table is
 * Documentate_App_List_Row's. The query arguments and counts the rest of the
 * application asks the list for stay on this class and are answered by the
 * filters.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the document list.
 */
class Documentate_App_List {

	/**
	 * Query arguments of the list.
	 *
	 * @param string $status Status chip, "devuelto", "mios", or empty for every status.
	 * @param int    $area   Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function query_args( $status, $area = 0 ) {
		return Documentate_App_Tray::query_args( $status, $area );
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
	 * Render the list the request asks for.
	 *
	 * @return string
	 */
	public static function render() {
		$status = Documentate_App_Tray::current_status();
		$area = Documentate_App_Tray::current_area();
		$titles = self::titles();

		$html = Documentate_App_Shell::open( 'lista', $titles[0], $titles[1], array(), self::render_area_select( $status, $area ) );

		if ( Documentate_App_Tray::without_scope() ) {
			return $html
				. '<div class="dcta-aviso">Tu usuario no tiene un ámbito asignado. Contacta con administración.</div>'
				. Documentate_App_Shell::close();
		}

		$html .= self::render_notices();
		$html .= self::render_filters( $status, $area );
		$html .= self::render_table( $status, $area );

		return $html . Documentate_App_Shell::close();
	}

	/**
	 * Feedback left by a handler that redirected here.
	 *
	 * A return is the one action that lands on the list instead of on the
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
	 * Heading and sub-heading of the list.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function titles() {
		if ( ! Documentate_Roles::is_management() ) {
			return array( 'Mis documentos', 'Los documentos de tu área, con su estado.' );
		}

		return array(
			'Todos los documentos',
			Documentate_Roles::is_administration()
				? 'Todas las áreas, todos los estados.'
				: 'Todas las áreas de tu ámbito, todos los estados.',
		);
	}

	/**
	 * The status chips of a list, each with what it holds.
	 *
	 * The number is the point of the chip: it is what tells this person that
	 * two documents wait for their approval, and it is why the list opens on
	 * the chip of their own rol. A chip is drawn only when it would find
	 * something; "Todos" always is.
	 *
	 * "Mis documentos" is the one chip that does not narrow by status: it
	 * holds what this person wrote, in whatever state it ended up. An área
	 * gets no such chip — its whole list is already its own documents.
	 *
	 * @param string $status Active status filter.
	 * @param int    $area   Área filter.
	 * @return string
	 */
	private static function render_filters( $status, $area ) {
		$chips = array();
		if ( Documentate_Roles::is_management() ) {
			$chips['mios'] = 'Mis documentos';
		}
		$chips['devuelto'] = 'Devuelto';
		foreach ( Documentate_Statuses::labels() as $status_key => $label ) {
			$chips[ $status_key ] = 'draft' === $status_key ? 'Por enviar' : $label;
		}

		$html = '<div class="dcta-filtros">';
		$html .= self::filter_chip(
			'todos',
			'Todos',
			'' === $status,
			$area,
			Documentate_App_Tray::count_documents( Documentate_App_Tray::query_args( '', $area ) )
		);

		foreach ( $chips as $key => $label ) {
			$total = Documentate_App_Tray::count_documents( Documentate_App_Tray::query_args( $key, $area ) );
			if ( 0 === $total ) {
				continue;
			}
			$html .= self::filter_chip( $key, $label, $key === $status, $area, $total );
		}

		$html .= self::render_search();

		return $html . '</div>';
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
	 * @param string $key    Status key, "devuelto" or "todos".
	 * @param string $label  Chip label.
	 * @param bool   $active Whether it is the active filter.
	 * @param int    $area   Área filter to keep.
	 * @param int    $total  Documents behind the chip.
	 * @return string
	 */
	private static function filter_chip( $key, $label, $active, $area, $total = 0 ) {
		$args = array( 'estado' => $key );
		if ( $area > 0 ) {
			$args['area'] = (string) $area;
		}

		$classes = 'dcta-fchip';
		if ( $active ) {
			$classes .= ' dcta-fchip-on';
		}
		if ( Documentate_App_Tray::default_status() === $key ) {
			$classes .= ' dcta-fchip-mio';
		}

		return '<a class="' . esc_attr( $classes ) . '" href="'
			. esc_url( Documentate_App_Shell::page_url( $args ) ) . '">'
			. esc_html( $label )
			. '<span class="dcta-fchip-n">' . esc_html( (string) $total ) . '</span>'
			. '</a>';
	}

	/**
	 * The área select whoever looks after several áreas narrows the list with.
	 *
	 * It rides at the top right of the heading, beside "Todos los documentos":
	 * an ámbito is not one more status chip. Choosing an área is the whole
	 * interaction: the script submits the form on change, and hides the button
	 * that is only there for a reader without JavaScript. Revisión and
	 * jefatura de servicio get the categories of their ámbito, administración
	 * every one of them; an área, with a single category, gets no select at
	 * all.
	 *
	 * @param string $status Active status filter.
	 * @param int    $area   Active área filter.
	 * @return string
	 */
	private static function render_area_select( $status, $area ) {
		if ( ! Documentate_Roles::is_management() ) {
			return '';
		}

		$areas = Documentate_App_Tray::areas();
		if ( empty( $areas ) ) {
			return '';
		}

		$html = '<form class="dcta-areas" method="get" action="' . esc_url( Documentate_App_Shell::page_url() ) . '" data-dcta-areas="1">';
		$html .= Documentate_App_Shell::page_query_fields();
		$html .= '<input type="hidden" name="estado" value="' . esc_attr( '' === $status ? 'todos' : $status ) . '" />';
		$html .= '<label class="screen-reader-text" for="dcta-area">Área</label>';
		$html .= '<select id="dcta-area" name="area">';
		$html .= '<option value="0">Todas las áreas</option>';
		foreach ( $areas as $term ) {
			$html .= '<option value="' . esc_attr( (string) $term->term_id ) . '"'
				. selected( $area, (int) $term->term_id, false ) . '>'
				. esc_html( $term->name ) . '</option>';
		}
		$html .= '</select>';
		$html .= '<button type="submit" class="dcta-btn dcta-btn-ton dcta-areas-ok">Filtrar</button>';

		return $html . '</form>';
	}

	/**
	 * The rows of the list, with their header and footer.
	 *
	 * @param string $status Active status filter.
	 * @param int    $area   Área filter.
	 * @return string
	 */
	private static function render_table( $status, $area ) {
		$query = new WP_Query( Documentate_App_Tray::query_args( $status, $area ) );

		$html = '<div class="dcta-tabla">';
		$html .= '<div class="dcta-fila dcta-fila-cab">'
			. '<span>Documento</span>'
			. '<span>Tipo</span>'
			. '<span>Actualizado</span>'
			. '<span>Estado</span>'
			. '<span></span>'
			. '</div>';

		if ( ! $query->have_posts() ) {
			return $html . '<div class="dcta-vacio">' . esc_html( self::empty_text( $status ) ) . '</div></div>';
		}

		foreach ( $query->posts as $post ) {
			$html .= Documentate_App_List_Row::render( $post );
		}

		// The total, not the drawn rows: the quick filter only sees one page,
		// and without it its counts would claim the list holds just those. The
		// live region is what announces every rewrite of this footer, which is
		// the only signal a screen reader gets while filtering.
		$html .= '<div class="dcta-tabla-pie" role="status" data-dcta-pie data-dcta-pie-total="' . esc_attr( (string) (int) $query->found_posts ) . '">'
			. esc_html( self::footer_text( (int) $query->found_posts, count( $query->posts ) ) ) . '</div>';

		return $html . '</div>';
	}

	/**
	 * The footer of the table: how many there are, and how many are drawn.
	 *
	 * The list is not paginated; when a filter holds more than one page it
	 * says so instead of letting the count contradict the rows.
	 *
	 * @param int $total Documents the filter matches.
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
	 * What an empty list says.
	 *
	 * The chips only offer a status that holds something, so an empty list is
	 * either a chip that has just been emptied or an ámbito with nothing in
	 * it at all — and only the second one is worth pointing anywhere.
	 *
	 * @param string $status Active status filter.
	 * @return string
	 */
	private static function empty_text( $status ) {
		if ( '' !== $status ) {
			return 'No hay documentos con este filtro.';
		}

		return Documentate_Roles::is_management()
			? 'No hay documentos.'
			: 'Todavía no hay documentos. Crea el primero desde «Nuevo documento».';
	}
}
