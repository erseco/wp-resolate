<?php
/**
 * Document list view of the front-end application.
 *
 * One view with several trays: "Mis documentos" keeps the scope rules of the
 * admin list (a scoped user sees the documents of their category and its
 * descendants), while the review trays of gestión documental and
 * administración show every área, because reviewing is precisely the job of
 * looking outside your own.
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
	 * Post type of the documents.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Documents drawn in one page of the list.
	 *
	 * @var int
	 */
	const POR_PAGINA = 100;

	/**
	 * Statuses of every tray, in workflow order.
	 *
	 * @param string $bandeja Tray key.
	 * @return string[]
	 */
	private static function estados_bandeja( $bandeja ) {
		$todos = array_keys( Documentate_Estados::etiquetas() );

		// The review trays start where the pipeline starts: a draft belongs to
		// its área until it is sent.
		return 'mis' === $bandeja || 'todos' === $bandeja
			? $todos
			: array_values( array_diff( $todos, array( 'draft' ) ) );
	}

	/**
	 * Trays this person may open, and the one they land on.
	 *
	 * @return string[] First element is the default tray.
	 */
	public static function bandejas() {
		if ( Documentate_Roles::es_administracion() ) {
			return array( 'todos', 'revision' );
		}

		return Documentate_Roles::es_gestion() ? array( 'mis', 'revisar' ) : array( 'mis' );
	}

	/**
	 * Tray asked for by the request, falling back to the default one.
	 *
	 * @return string
	 */
	public static function bandeja_actual() {
		$bandejas = self::bandejas();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$bandeja = isset( $_GET['bandeja'] ) ? sanitize_key( wp_unslash( $_GET['bandeja'] ) ) : '';

		return in_array( $bandeja, $bandejas, true ) ? $bandeja : $bandejas[0];
	}

	/**
	 * Status chip pre-selected in a tray when the request names none.
	 *
	 * @param string $bandeja Tray key.
	 * @return string Status key, or empty for "every status".
	 */
	private static function estado_por_defecto( $bandeja ) {
		$defectos = array(
			'revisar' => 'en_gestion',
			'revision' => 'pending',
		);

		return isset( $defectos[ $bandeja ] ) ? $defectos[ $bandeja ] : '';
	}

	/**
	 * Status filter asked for by the request.
	 *
	 * "todos" is the explicit way of clearing the chip a tray pre-selects.
	 *
	 * @param string $bandeja Tray key.
	 * @return string Status key, "devuelto", or empty for every status.
	 */
	private static function estado_actual( $bandeja ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$estado = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';

		if ( 'todos' === $estado ) {
			return '';
		}

		$validos = array_merge( self::estados_bandeja( $bandeja ), array( 'devuelto' ) );

		return in_array( $estado, $validos, true ) ? $estado : self::estado_por_defecto( $bandeja );
	}

	/**
	 * Área filter asked for by the request (administración only).
	 *
	 * @return int Category term ID, 0 when there is no filter.
	 */
	private static function area_actual() {
		if ( ! Documentate_Roles::es_administracion() ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_GET['area'] ) ? absint( $_GET['area'] ) : 0;
	}

	/**
	 * Scope term IDs of the current user.
	 *
	 * Same contract as Documentate_Scope_Filter::get_scope_term_ids(), inlined
	 * here because that class registers hooks on construction.
	 *
	 * @return int[]|null Null for unrestricted users; empty array when the user
	 *                    is restricted but has no scope assigned.
	 */
	private static function scope_term_ids() {
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		$scope_term = absint( get_user_meta( get_current_user_id(), 'documentate_scope_term_id', true ) );
		if ( 0 === $scope_term ) {
			return array();
		}

		$term_ids = array( $scope_term );
		$children = get_term_children( $scope_term, 'category' );
		if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
			$term_ids = array_merge( $term_ids, $children );
		}

		return array_map( 'absint', $term_ids );
	}

	/**
	 * Taxonomy clause restricting a query to a set of categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return array
	 */
	private static function tax_query_categorias( array $term_ids ) {
		return array(
			array(
				'taxonomy' => 'category',
				'field' => 'term_id',
				'terms' => $term_ids,
				'include_children' => false,
			),
		);
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
		$args = array(
			'post_type' => self::POST_TYPE,
			'post_status' => self::estados_bandeja( $bandeja ),
			'posts_per_page' => self::POR_PAGINA,
			'orderby' => 'modified',
			'order' => 'DESC',
			'ignore_sticky_posts' => true,
		);

		if ( 'devuelto' === $estado ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The returned mark is only written on returns, so the set is small.
			$args['meta_key'] = Documentate_Documento::META_DEVUELTO;
			$args['meta_compare'] = 'EXISTS';
			// A return leaves the document wherever it was sent back to, and
			// the most common one (administración → área) lands in a draft.
			// The tray statuses would hide exactly those, so the counter and
			// the chip would promise a set the tray cannot show.
			$args['post_status'] = array_keys( Documentate_Estados::etiquetas() );
		} elseif ( '' !== $estado ) {
			$args['post_status'] = $estado;
		}

		$term_ids = 'mis' === $bandeja ? self::scope_term_ids() : null;
		if ( $area > 0 ) {
			$term_ids = array( $area );
		}

		if ( is_array( $term_ids ) ) {
			$args['tax_query'] = self::tax_query_categorias( $term_ids ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scope restriction, bounded list.
		}

		return $args;
	}

	/**
	 * Count the documents a set of query arguments matches.
	 *
	 * @param array<string,mixed> $extra Query arguments on top of the defaults.
	 * @return int
	 */
	public static function contar( array $extra ) {
		$args = array_merge(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array_keys( Documentate_Estados::etiquetas() ),
				'ignore_sticky_posts' => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$extra,
			array(
				'fields' => 'ids',
				'posts_per_page' => 1,
				'no_found_rows' => false,
			)
		);

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Render the tray the request asks for.
	 *
	 * @return string
	 */
	public static function render() {
		$bandeja = self::bandeja_actual();
		$estado = self::estado_actual( $bandeja );
		$area = self::area_actual();
		$titulos = self::titulos( $bandeja );

		$html = Documentate_App_Shell::abrir(
			Documentate_App_Shell::seccion_de_bandeja( $bandeja ),
			$titulos[0],
			$titulos[1]
		);

		$term_ids = 'mis' === $bandeja ? self::scope_term_ids() : null;
		if ( is_array( $term_ids ) && empty( $term_ids ) ) {
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
			$args = self::argumentos_consulta( $bandeja, $cifra[1], $area );
			$etiqueta = $cifra[0];
			if ( 'draft' === $cifra[1] ) {
				$etiqueta .= self::sufijo_devueltos( $bandeja, $area );
			}

			$html .= '<div class="dcta-cifra' . ( $primero ? ' dcta-cifra-acento' : '' ) . '">'
				. '<b>' . esc_html( (string) self::contar( $args ) ) . '</b>'
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
		$args = self::argumentos_consulta( $bandeja, 'devuelto', $area );
		$args['post_status'] = 'draft';
		$devueltos = self::contar( $args );

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
			if ( in_array( $status, self::estados_bandeja( $bandeja ), true ) ) {
				$chips[ $status ] = 'draft' === $status ? 'Por enviar' : $etiqueta;
			}
		}

		$html = '<div class="dcta-filtros">';
		$html .= self::chip_filtro( $bandeja, 'todos', 'Todos', '' === $estado, $area );

		foreach ( $chips as $clave => $etiqueta ) {
			$args = self::argumentos_consulta( $bandeja, $clave, $area );
			if ( 0 === self::contar( $args ) ) {
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
		$query = new WP_Query( self::argumentos_consulta( $bandeja, $estado, $area ) );

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
			$html .= self::render_fila( $post, $bandeja );
		}

		$html .= '<div class="dcta-tabla-pie" data-dcta-pie data-dcta-pie-total="' . esc_attr( (string) count( $query->posts ) ) . '">'
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

	/**
	 * Render one document row.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return string
	 */
	private static function render_fila( $post, $bandeja ) {
		$chip = Documentate_App_Shell::chip( $post );
		$devuelto = Documentate_App_Shell::texto_devuelto( $post );
		$tipo = Documentate_Documento::tipo( $post );
		$accion = self::accion_fila( $post, $bandeja );
		$detalle_url = self::url_detalle( $post->ID, $bandeja );

		$html = '<div class="dcta-fila' . ( '' !== $devuelto ? ' dcta-fila-devuelta' : '' ) . '"'
			. ' data-dcta-texto="' . esc_attr( self::texto_buscable( $post, $tipo, $chip['texto'] ) ) . '">';
		$html .= '<div class="dcta-doc-nombre">'
			. '<a href="' . esc_url( $detalle_url ) . '">' . esc_html( Documentate_Documento::nombre_corto( $post ) ) . '</a>'
			. self::icono_adjunto( $post )
			. self::sublineas( $post, $bandeja )
			. ( '' !== $devuelto ? '<small class="dcta-doc-motivo">' . esc_html( $devuelto ) . '</small>' : '' )
			. '</div>';
		$html .= '<span class="dcta-doc-tipo">' . esc_html( $tipo ? $tipo->name : '—' ) . '</span>';
		$html .= '<span class="dcta-doc-fecha">' . esc_html( get_the_modified_date( 'j M', $post ) ) . '</span>';
		$html .= '<span><span class="' . esc_attr( $chip['clase'] ) . '">' . esc_html( $chip['texto'] ) . '</span></span>';
		$html .= '<a class="dcta-mini" href="' . esc_url( $accion[1] ) . '">' . esc_html( $accion[0] ) . '</a>';

		return $html . '</div>';
	}

	/**
	 * Everything the quick filter matches a row against.
	 *
	 * The internal name, the official title, the type and the status, so
	 * typing "gasto", "devuelto" or "RES" all narrow the list.
	 *
	 * @param WP_Post      $post   Document.
	 * @param WP_Term|null $tipo   Document type.
	 * @param string       $estado Status label.
	 * @return string
	 */
	private static function texto_buscable( $post, $tipo, $estado ) {
		$partes = array(
			Documentate_Documento::nombre_corto( $post ),
			wp_strip_all_tags( (string) $post->post_title ),
			$tipo ? $tipo->name : '',
			$estado,
		);

		return trim( implode( ' ', array_filter( $partes ) ) );
	}

	/**
	 * The paper-clip mark of a document that carries a file.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function icono_adjunto( $post ) {
		if ( null === Documentate_Documento::adjunto( $post ) ) {
			return '';
		}

		return '<span class="dcta-doc-adjunto dashicons dashicons-paperclip" title="Con fichero adjunto" aria-label="Con fichero adjunto" role="img"></span>';
	}

	/**
	 * The lines under the name: the official title, and who it belongs to.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return string
	 */
	private static function sublineas( $post, $bandeja ) {
		$titulo = trim( wp_strip_all_tags( (string) $post->post_title ) );
		if ( mb_strlen( $titulo ) > 90 ) {
			$titulo = mb_substr( $titulo, 0, 89 ) . '…';
		}

		$html = '' !== $titulo ? '<small class="dcta-doc-sub">' . esc_html( $titulo ) . '</small>' : '';
		if ( 'mis' === $bandeja ) {
			return $html;
		}

		$quien = array_filter( array( Documentate_Documento::area( $post ), Documentate_Documento::persona( $post ) ) );

		return '' === implode( '', $quien )
			? $html
			: $html . '<small class="dcta-doc-sub">' . esc_html( implode( ' · ', $quien ) ) . '</small>';
	}

	/**
	 * Label and destination of the row action.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return array{0:string,1:string}
	 */
	private static function accion_fila( $post, $bandeja ) {
		$editable = Documentate_App_Editar::puede_editar( $post );
		$editar = Documentate_App_Editar::url( $post->ID, $bandeja );

		if ( $editable && null !== Documentate_Documento::devuelto( $post ) ) {
			return array( 'Corregir', $editar );
		}

		if ( $editable && 'draft' === $post->post_status ) {
			return array( 'Continuar', $editar );
		}

		if ( $editable && self::esta_esperando_a( $post ) ) {
			return array( 'Revisar', $editar );
		}

		if ( 'publish' === $post->post_status ) {
			return array( 'Ver PDF', self::url_detalle( $post->ID, $bandeja ) . '#exportar' );
		}

		return array( 'Ver', self::url_detalle( $post->ID, $bandeja ) );
	}

	/**
	 * Whether the document is waiting for this rol to review it.
	 *
	 * Administración may edit a document in gestión, but it is not theirs to
	 * review yet: gestión has not finished with it.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	private static function esta_esperando_a( $post ) {
		$esperado = Documentate_Roles::es_administracion() ? 'pending' : 'en_gestion';

		return $esperado === $post->post_status;
	}

	/**
	 * URL of the document view, remembering which tray it was opened from.
	 *
	 * @param int    $doc_id  Document ID.
	 * @param string $bandeja Tray key.
	 * @return string
	 */
	private static function url_detalle( $doc_id, $bandeja ) {
		$args = array( 'doc' => $doc_id );
		if ( 'mis' !== $bandeja ) {
			$args['bandeja'] = $bandeja;
		}

		return Documentate_App_Shell::page_url( $args );
	}
}
