<?php
/**
 * Read-only document view of the front-end application.
 *
 * Shows what the document carries, where it stands in the workflow and what
 * can be done with it right now: edit it, move it on, export it or comment on
 * it. The fields gestión documental completes are only rendered for whoever
 * may see them.
 *
 * @package Documentate
 * @subpackage App
 */

use Documentate\Documents\Documents_Meta_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders one document.
 */
class Documentate_App_Detalle {

	/**
	 * Render the document view.
	 *
	 * @param int $doc_id Document post ID.
	 * @return string
	 */
	public static function render( $doc_id ) {
		$post = get_post( $doc_id );

		if (
			! $post instanceof WP_Post
			|| 'documentate_document' !== $post->post_type
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return Documentate_App_Shell::abrir( 'lista', 'Documento', '' )
				. '<div class="dcta-aviso">Este documento no existe o está fuera de tu ámbito.</div>'
				. Documentate_App_Shell::cerrar();
		}

		$html = Documentate_App_Shell::abrir(
			Documentate_App_Shell::seccion_de_bandeja( Documentate_App_Lista::bandeja_actual() ),
			Documentate_Documento::nombre_corto( $post ),
			self::subtitulo( $post )
		);

		$html .= self::render_avisos( $post );

		$html .= '<div class="dcta-detalle">';
		$html .= '<div class="dcta-detalle-cuerpo">';
		$html .= self::render_basicos( $post );
		$html .= self::render_ficha( $post );
		$html .= self::render_adjunto( $post );
		$html .= self::render_actividad( $post );
		$html .= '</div>';
		$html .= self::render_lado( $post );
		$html .= '</div>';
		$html .= self::render_form_comentario( $post, 'detalle' );

		// The dialogs belong to the transition buttons: without them there is
		// nothing to confirm and nothing to give a reason for.
		$hay_acciones = ! empty( Documentate_App_Shell::transiciones_app( $post ) );

		return $html . Documentate_App_Shell::cerrar( $hay_acciones );
	}

	/**
	 * The line under the heading: type, área, person and last change.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function subtitulo( $post ) {
		$tipo = Documentate_Documento::tipo( $post );
		$partes = array_filter(
			array(
				$tipo ? $tipo->name : '',
				Documentate_Documento::area( $post ),
				Documentate_Documento::persona( $post ),
				'actualizado el ' . get_the_modified_date( '', $post ),
			)
		);

		$curso = Documentate_Documento::curso( $post );
		if ( '' !== $curso ) {
			$partes[] = 'curso ' . $curso;
		}

		return implode( ' · ', $partes );
	}

	/**
	 * Text of an error flag carried by a redirect.
	 *
	 * @param string $error Error key.
	 * @return string
	 */
	public static function texto_error( $error ) {
		$textos = array(
			'motivo' => 'Para devolver un documento hay que decir por qué.',
			'adjunto' => 'No se pudo subir el fichero: solo PDF, ODT o DOCX de hasta 20 MB.',
			'adjunto_permiso' => 'Tu usuario no puede subir ficheros. Contacta con administración.',
			'transicion' => 'Esa acción no está disponible en el estado actual del documento.',
			'comentario' => 'No se pudo guardar el comentario.',
			'titulo' => 'El documento necesita un título oficial.',
			'nombre' => 'El documento necesita un nombre interno.',
			'bloqueado' => 'Este documento está bloqueado en su estado actual y no se puede editar.',
		);

		return isset( $textos[ $error ] ) ? $textos[ $error ] : 'No se pudo guardar el documento. Inténtalo de nuevo.';
	}

	/**
	 * The notices at the top: what just happened, and where the document is.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_avisos( $post ) {
		$html = self::render_banderas( $post );

		$devuelto = Documentate_App_Shell::texto_devuelto( $post );
		if ( '' !== $devuelto ) {
			$html .= '<div class="dcta-aviso dcta-aviso-devuelto">'
				. esc_html( $devuelto . ' Corrige lo que haga falta y vuelve a enviarlo.' )
				. '</div>';
		}

		$estado = self::texto_estado( $post );

		return '' === $estado ? $html : $html . '<div class="dcta-aviso dcta-aviso-info">' . esc_html( $estado ) . '</div>';
	}

	/**
	 * Feedback flags left by the handlers on their redirect.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_banderas( $post ) {
		$mensajes = array(
			'enviado' => self::texto_enviado( $post ),
			'devuelto' => 'Documento devuelto con el motivo indicado.',
			'aprobado' => 'Documento aprobado y publicado.',
			'comentado' => 'Comentario añadido.',
			'guardado' => 'Cambios guardados.',
		);

		foreach ( $mensajes as $bandera => $texto ) {
			if ( '1' === self::bandera( $bandera ) ) {
				return '<div class="dcta-aviso dcta-aviso-ok">' . esc_html( $texto ) . '</div>';
			}
		}

		$error = self::bandera( 'error' );

		return '' === $error
			? ''
			: '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( self::texto_error( $error ) ) . '</div>';
	}

	/**
	 * One feedback flag of the query string.
	 *
	 * @param string $nombre Flag name.
	 * @return string Empty when absent.
	 */
	public static function bandera( $nombre ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flag on a redirect.
		return isset( $_GET[ $nombre ] ) ? sanitize_key( wp_unslash( $_GET[ $nombre ] ) ) : '';
	}

	/**
	 * What the "enviado" flag means for the status the document landed in.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function texto_enviado( $post ) {
		if ( 'en_gestion' === $post->post_status ) {
			return 'Documento enviado a gestión documental.';
		}

		return Documentate_Roles::es_gestion() && ! Documentate_Roles::es_administracion()
			? 'Documento pasado a administración.'
			: 'Documento enviado a revisión de administración.';
	}

	/**
	 * The standing notice of the status the document is in.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function texto_estado( $post ) {
		$textos = array(
			'en_gestion' => 'En gestión documental: están completando los datos oficiales. Si falta algo te lo devolverán y podrás corregirlo.',
			'pending' => 'En revisión: administración lo aprobará o lo devolverá.',
			'publish' => 'Aprobado el ' . get_the_modified_date( '', $post ) . '. Puedes previsualizarlo y descargarlo.',
			'archived' => 'Archivado.',
		);

		return isset( $textos[ $post->post_status ] ) ? $textos[ $post->post_status ] : '';
	}

	/**
	 * The "Datos básicos" card.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_basicos( $post ) {
		$tipo = Documentate_Documento::tipo( $post );
		$filas = array(
			'Nombre interno' => Documentate_Documento::nombre_corto( $post ),
			'Título oficial' => (string) $post->post_title,
			'Tipo de documento' => $tipo ? $tipo->name : '—',
		);

		$html = '<div class="dcta-card"><h2 class="dcta-h2">Datos básicos</h2><dl class="dcta-ficha">';
		foreach ( $filas as $etiqueta => $valor ) {
			$html .= '<dt>' . esc_html( $etiqueta ) . '</dt><dd>' . esc_html( '' !== $valor ? $valor : '—' ) . '</dd>';
		}

		return $html . '</dl></div>';
	}

	/**
	 * The field cards, one per rol group the visitor may see.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_ficha( $post ) {
		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		$valores = Documents_Meta_Handler::get_structured_field_values( $post->ID );

		if ( empty( $schema ) ) {
			return '<div class="dcta-card"><dl class="dcta-ficha">'
				. '<dt>Contenido</dt><dd>Este documento todavía no tiene campos.</dd>'
				. '</dl></div>';
		}

		$grupos = Documentate_Campos_Rol::agrupar( $schema );

		return self::render_grupo( $grupos['area'], $valores, 'Datos del área' )
			. self::render_grupo( $grupos['gestion'], $valores, 'Datos oficiales · los completa gestión' );
	}

	/**
	 * One card with the fields of a rol group.
	 *
	 * @param array  $filas    Schema rows of the group.
	 * @param array  $valores  Stored field values.
	 * @param string $titulo   Card heading.
	 * @return string Empty when the visitor may see none of the rows.
	 */
	private static function render_grupo( array $filas, array $valores, $titulo ) {
		$html = '';

		foreach ( $filas as $campo ) {
			$slug = isset( $campo['slug'] ) ? sanitize_key( $campo['slug'] ) : '';
			if ( '' === $slug || 'post_title' === $slug || ! Documentate_Campos_Rol::puede_ver( (array) $campo ) ) {
				continue;
			}

			$etiqueta = isset( $campo['label'] ) && '' !== $campo['label']
				? $campo['label']
				: Documents_Meta_Handler::humanize_unknown_field_label( $slug );

			$html .= '<dt>' . esc_html( $etiqueta ) . '</dt>';
			$html .= '<dd>' . esc_html( self::resumen_valor( $campo, isset( $valores[ $slug ] ) ? $valores[ $slug ] : null ) ) . '</dd>';
		}

		if ( '' === $html ) {
			return '';
		}

		return '<div class="dcta-card"><h2 class="dcta-h2">' . esc_html( $titulo ) . '</h2>'
			. '<dl class="dcta-ficha">' . $html . '</dl></div>';
	}

	/**
	 * One-line summary of a stored field value.
	 *
	 * @param array      $campo Schema row.
	 * @param array|null $info  Stored entry (value/type), or null.
	 * @return string
	 */
	private static function resumen_valor( $campo, $info ) {
		if ( isset( $campo['type'] ) && 'array' === $campo['type'] ) {
			$items = null !== $info ? Documents_Meta_Handler::get_array_field_items_from_structured( $info ) : array();
			$total = count( $items );

			return 1 === $total ? '1 elemento' : $total . ' elementos';
		}

		$valor = null !== $info && isset( $info['value'] ) ? (string) $info['value'] : '';
		$valor = trim( wp_strip_all_tags( $valor ) );

		if ( '' === $valor ) {
			return '—';
		}

		return mb_strlen( $valor ) > 240 ? mb_substr( $valor, 0, 240 ) . '…' : $valor;
	}

	/**
	 * The "Fichero del documento" card.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_adjunto( $post ) {
		$adjunto = Documentate_Documento::adjunto( $post );

		$html = '<div class="dcta-card dcta-adjunto"><h2 class="dcta-h2">Fichero del documento</h2>';
		if ( ! $adjunto ) {
			return $html . '<p class="dcta-ayuda">Sin fichero adjunto.</p></div>';
		}

		$autor = get_userdata( (int) $adjunto->post_author );
		$quien = $autor ? (string) $autor->display_name : '';

		$html .= '<p class="dcta-adjunto-fila">'
			. '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
			. '<span class="dcta-adjunto-nombre">' . esc_html( Documentate_App_Adjuntos::nombre( $adjunto->ID ) ) . '</span>'
			. '<span class="dcta-adjunto-peso">' . esc_html( Documentate_App_Adjuntos::tamano_legible( $adjunto->ID ) ) . '</span>'
			. '<a href="' . esc_url( (string) wp_get_attachment_url( $adjunto->ID ) ) . '" target="_blank" rel="noopener">Abrir</a>'
			. '</p>';

		$html .= '<p class="dcta-ayuda">'
			. esc_html( 'adjuntado por ' . $quien . ' el ' . get_the_date( '', $adjunto ) )
			. '</p>';

		return $html . '</div>';
	}

	/**
	 * The "Actividad" card: events, comments and the box to add one.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	public static function render_actividad( $post ) {
		$filas = Documentate_Actividad::listar( $post->ID );

		$html = '<div class="dcta-card dcta-actividad"><h2 class="dcta-h2">Actividad</h2>';

		if ( empty( $filas ) ) {
			$html .= '<p class="dcta-ayuda">Todavía no hay actividad.</p>';
		} else {
			$html .= '<ul class="dcta-actividad-lista">';
			foreach ( $filas as $fila ) {
				$html .= '<li class="dcta-actividad-item dcta-actividad-' . esc_attr( $fila['tipo'] ) . '">'
					. '<b>' . esc_html( $fila['autor'] ) . '</b> '
					. esc_html( $fila['texto'] )
					. ' <small>' . esc_html( $fila['relativa'] ) . '</small>'
					. '</li>';
			}
			$html .= '</ul>';
		}

		$form = Documentate_App_Shell::FORM_COMENTARIO_ID;
		$html .= '<div class="dcta-campo dcta-comentar">'
			. '<label for="documentate-app-comentario">Comentario</label>'
			. '<textarea id="documentate-app-comentario" name="documentate_app_comentario" form="' . esc_attr( $form ) . '" rows="3" placeholder="Escribe un comentario…"></textarea>'
			. '<button type="submit" class="dcta-btn dcta-btn-ton" form="' . esc_attr( $form ) . '">Comentar</button>'
			. '<p class="dcta-ayuda">Los comentarios quedan en la actividad, a la vista del área, gestión y administración.</p>'
			. '</div>';

		return $html . '</div>';
	}

	/**
	 * The empty form the comment box posts through.
	 *
	 * It is printed outside the editor form, because a form cannot be nested
	 * in another one; the textarea and the button join it with the HTML "form"
	 * attribute.
	 *
	 * @param WP_Post $post        Document.
	 * @param string  $redirect_to Where to come back to (editar or detalle).
	 * @return string
	 */
	public static function render_form_comentario( $post, $redirect_to = 'detalle' ) {
		$destino = 'editar' === $redirect_to ? 'editar' : 'detalle';

		return '<form class="dcta-form-oculto" id="' . esc_attr( Documentate_App_Shell::FORM_COMENTARIO_ID ) . '" method="post" action="">'
			. wp_nonce_field( 'documentate_app_comentar_' . $post->ID, 'documentate_app_nonce', true, false )
			. '<input type="hidden" name="documentate_app_accion" value="comentar" />'
			. '<input type="hidden" name="documentate_app_doc" value="' . esc_attr( (string) $post->ID ) . '" />'
			. '<input type="hidden" name="documentate_app_redirect_to" value="' . esc_attr( $destino ) . '" />'
			. '</form>';
	}

	/**
	 * Render the side rail: the stepper and the actions.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_lado( $post ) {
		$chip = Documentate_App_Shell::chip( $post );

		$html = '<div class="dcta-lado">';
		$html .= '<div class="dcta-card">'
			. '<h2 class="dcta-h2">Estado</h2>'
			. '<span class="' . esc_attr( $chip['clase'] ) . '">' . esc_html( $chip['texto'] ) . '</span>'
			. self::render_stepper( $post )
			. '</div>';

		$html .= '<div class="dcta-card"><h2 class="dcta-h2">Acciones</h2>';

		if ( Documentate_App_Editar::puede_editar( $post ) ) {
			$html .= '<a class="dcta-btn dcta-btn-pri" href="' . esc_url( Documentate_App_Editar::url( $post->ID ) ) . '">Editar</a>';
		}

		$html .= self::render_form_acciones( $post );
		$html .= Documentate_Admin_Helper::bloque_exportar( $post );
		$html .= Documentate_App_Shell::enlace_volver();

		if ( current_user_can( 'manage_options' ) ) {
			// Signature, revisions and the rest of the toolbox still live in
			// wp-admin. The link is built directly so it does not depend on
			// the post type object registered by the current request.
			$html .= '<a class="dcta-editor-volver" href="'
				. esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) )
				. '">Abrir en wp-admin</a>';
		}

		return $html . '</div></div>';
	}

	/**
	 * The form the transition buttons post through.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when no transition is available.
	 */
	private static function render_form_acciones( $post ) {
		$botones = Documentate_App_Shell::botones_transicion( $post );
		if ( '' === $botones ) {
			return '';
		}

		return '<form class="dcta-acciones" id="' . esc_attr( Documentate_App_Shell::FORM_ID ) . '" method="post" action="">'
			. wp_nonce_field( 'documentate_app_transicion_' . $post->ID, 'documentate_app_nonce', true, false )
			. '<input type="hidden" name="documentate_app_accion" value="transicion" />'
			. '<input type="hidden" name="documentate_app_doc" value="' . esc_attr( (string) $post->ID ) . '" />'
			. '<input type="hidden" name="documentate_app_bandeja" value="' . esc_attr( Documentate_App_Lista::bandeja_actual() ) . '" />'
			. $botones
			. '</form>';
	}

	/**
	 * The workflow stepper: where the document has been and where it is going.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_stepper( $post ) {
		$pasos = self::pasos( $post );
		$claves = array_keys( $pasos );
		$actual = self::indice_actual( $post->post_status, $claves );

		$html = '<div class="dcta-stepper">';
		foreach ( $claves as $indice => $clave ) {
			$estado = 'dcta-paso-futuro';
			$sub = '—';
			if ( $indice < $actual ) {
				$estado = 'dcta-paso-hecho';
				$sub = self::sub_hecho( $post, $pasos[ $clave ] );
			} elseif ( $indice === $actual ) {
				$estado = 'dcta-paso-actual';
				$sub = $pasos[ $clave ]['actual'];
			}

			$html .= '<div class="dcta-paso ' . esc_attr( $estado ) . '">'
				. '<span class="dcta-paso-punto" aria-hidden="true"></span>'
				. '<span class="dcta-paso-t">' . esc_html( $pasos[ $clave ]['etiqueta'] ) . '</span>'
				. '<small class="dcta-paso-sub">' . esc_html( $sub ) . '</small>'
				. '</div>';
		}

		return $html . '</div>';
	}

	/**
	 * The steps of the workflow for this document type.
	 *
	 * @param WP_Post $post Document.
	 * @return array<string,array{etiqueta:string,actual:string,hecho:string,evento:string}>
	 */
	private static function pasos( $post ) {
		$pasos = array(
			'draft' => array(
				'etiqueta' => 'Borrador',
				'actual' => 'En preparación',
				'hecho' => 'enviado el ',
				'evento' => 'envió el documento',
			),
			'en_gestion' => array(
				'etiqueta' => 'En gestión',
				'actual' => 'Completando datos oficiales',
				'hecho' => 'completado el ',
				'evento' => 'pasó el documento',
			),
			'pending' => array(
				'etiqueta' => 'En revisión',
				'actual' => 'Pendiente de aprobar',
				'hecho' => 'aprobado el ',
				'evento' => 'aprobó y publicó',
			),
			'publish' => array(
				'etiqueta' => 'Aprobado',
				'actual' => 'Aprobado',
				'hecho' => 'aprobado el ',
				'evento' => 'aprobó y publicó',
			),
		);

		if ( ! Documentate_Documento::con_gestion( $post ) ) {
			unset( $pasos['en_gestion'] );
		}

		return $pasos;
	}

	/**
	 * Index of the step the document is standing on.
	 *
	 * @param string   $status Post status.
	 * @param string[] $claves Step keys, in order.
	 * @return int
	 */
	private static function indice_actual( $status, array $claves ) {
		$efectivo = 'archived' === $status ? 'publish' : $status;
		$efectivo = 'auto-draft' === $efectivo ? 'draft' : $efectivo;
		$indice = array_search( $efectivo, $claves, true );

		return false === $indice ? 0 : (int) $indice;
	}

	/**
	 * Sub-label of a step already left behind: when it happened.
	 *
	 * @param WP_Post $post Document.
	 * @param array   $paso Step row.
	 * @return string
	 */
	private static function sub_hecho( $post, array $paso ) {
		$fecha = Documentate_Actividad::fecha_evento( $post->ID, $paso['evento'] );
		if ( '' === $fecha ) {
			return $paso['hecho'] . '—';
		}

		$marca = strtotime( $fecha );

		return $paso['hecho'] . ( false === $marca ? '—' : date_i18n( 'j M', $marca ) );
	}
}
