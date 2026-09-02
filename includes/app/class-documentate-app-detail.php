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
class Documentate_App_Detail {

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
			return Documentate_App_Shell::open( 'lista', 'Documento', '' )
				. '<div class="dcta-aviso">Este documento no existe o está fuera de tu ámbito.</div>'
				. Documentate_App_Shell::close();
		}

		$html = Documentate_App_Shell::open(
			Documentate_App_Shell::section_for_tray( Documentate_App_List::current_tray() ),
			Documentate_Document_Data::short_name( $post ),
			self::subtitle( $post )
		);

		$html .= self::render_notices( $post );

		$html .= '<div class="dcta-detalle">';
		$html .= '<div class="dcta-detalle-cuerpo">';
		$html .= self::render_basics( $post );
		$html .= self::render_field_cards( $post );
		$html .= self::render_attachment( $post );
		$html .= self::render_activity( $post );
		$html .= '</div>';
		$html .= self::render_side( $post );
		$html .= '</div>';
		$html .= self::render_comment_form( $post, 'detalle' );

		// The dialogs belong to the transition buttons: without them there is
		// nothing to confirm and nothing to give a reason for.
		$has_actions = ! empty( Documentate_App_Shell::app_transitions( $post ) );

		return $html . Documentate_App_Shell::close( $has_actions );
	}

	/**
	 * The line under the heading: type, área, person and last change.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function subtitle( $post ) {
		$type = Documentate_Document_Data::type( $post );
		$parts = array_filter(
			array(
				$type ? $type->name : '',
				Documentate_Document_Data::area( $post ),
				Documentate_Document_Data::person( $post ),
				'actualizado el ' . get_the_modified_date( Documentate_App_Shell::DATE_FORMAT, $post ),
			)
		);

		$course = Documentate_Document_Data::course( $post );
		if ( '' !== $course ) {
			$parts[] = 'curso ' . $course;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Text of an error flag carried by a redirect.
	 *
	 * @param string $error Error key.
	 * @return string
	 */
	public static function error_text( $error ) {
		$texts = array(
			'motivo' => 'Para devolver un documento hay que decir por qué.',
			'adjunto' => 'No se pudo subir el fichero: solo PDF, ODT o DOCX de hasta 20 MB.',
			'adjunto_permiso' => 'Tu usuario no puede subir ficheros. Contacta con administración.',
			'transicion' => 'Esa acción no está disponible en el estado actual del documento.',
			'comentario' => 'No se pudo guardar el comentario.',
			'titulo' => 'El documento necesita un título oficial.',
			'nombre' => 'El documento necesita un nombre interno.',
			'bloqueado' => 'Este documento está bloqueado en su estado actual y no se puede editar.',
		);

		return isset( $texts[ $error ] ) ? $texts[ $error ] : 'No se pudo guardar el documento. Inténtalo de nuevo.';
	}

	/**
	 * The notices at the top: what just happened, and where the document is.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_notices( $post ) {
		$html = self::render_flags( $post );

		$returned = Documentate_App_Shell::returned_notice( $post );
		if ( '' !== $returned ) {
			$html .= '<div class="dcta-aviso dcta-aviso-devuelto">' . esc_html( $returned ) . '</div>';
		}

		$status = self::status_text( $post );

		return '' === $status ? $html : $html . '<div class="dcta-aviso dcta-aviso-info">' . esc_html( $status ) . '</div>';
	}

	/**
	 * Feedback flags left by the handlers on their redirect.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_flags( $post ) {
		$messages = array(
			'enviado' => self::sent_text( $post ),
			'devuelto' => 'Documento devuelto con el motivo indicado.',
			'aprobado' => 'Documento aprobado y publicado.',
			'comentado' => 'Comentario añadido.',
			'guardado' => 'Cambios guardados.',
		);

		foreach ( $messages as $flag => $text ) {
			if ( '1' === self::flag( $flag ) ) {
				return '<div class="dcta-aviso dcta-aviso-ok">' . esc_html( $text ) . '</div>';
			}
		}

		$error = self::flag( 'error' );

		return '' === $error
			? ''
			: '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( self::error_text( $error ) ) . '</div>';
	}

	/**
	 * One feedback flag of the query string.
	 *
	 * @param string $name Flag name.
	 * @return string Empty when absent.
	 */
	public static function flag( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flag on a redirect.
		if ( isset( $_GET[ $name ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flag on a redirect.
			return sanitize_key( wp_unslash( $_GET[ $name ] ) );
		}

		return self::flag_from_uri( $name );
	}

	/**
	 * The same flag, read from the query string of the request.
	 *
	 * "error" is a reserved query variable of WordPress: as soon as a rewrite
	 * rule matches, `WP::parse_request()` does `unset( $error, $_GET['error'] )`
	 * (wp-includes/class-wp.php), so on a site with pretty permalinks the
	 * error flag of a redirect never reaches the views through `$_GET`. The
	 * request URI is untouched, so it is the reliable source.
	 *
	 * @param string $name Flag name.
	 * @return string Empty when the request carries no such argument.
	 */
	private static function flag_from_uri( $name ) {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$query_string = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' === $query_string ) {
			return '';
		}

		$pairs = array();
		wp_parse_str( $query_string, $pairs );

		return isset( $pairs[ $name ] ) && is_scalar( $pairs[ $name ] )
			? sanitize_key( (string) $pairs[ $name ] )
			: '';
	}

	/**
	 * What the "enviado" flag means: the transition that ran, not who ran it.
	 *
	 * The handler carries the rule key on the redirect because two of them
	 * (enviar_revision and pasar_admin) land in the same status, and the same
	 * person can run either one. A redirect without the key — an older link,
	 * or a status change made in wp-admin — falls back to the status.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function sent_text( $post ) {
		$texts = array(
			'enviar_gestion' => 'Documento enviado a gestión documental.',
			'enviar_revision' => 'Documento enviado a revisión de administración.',
			'pasar_admin' => 'Documento pasado a administración.',
		);

		$key = self::flag( 'transicion' );
		if ( isset( $texts[ $key ] ) ) {
			return $texts[ $key ];
		}

		return 'en_gestion' === $post->post_status ? $texts['enviar_gestion'] : $texts['enviar_revision'];
	}

	/**
	 * The standing notice of the status the document is in.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function status_text( $post ) {
		$texts = array(
			'en_gestion' => 'En gestión documental: están completando los datos oficiales. Si falta algo te lo devolverán y podrás corregirlo.',
			'pending' => 'En revisión: administración lo aprobará o lo devolverá.',
			'publish' => 'Aprobado el ' . get_the_modified_date( Documentate_App_Shell::DATE_FORMAT, $post ) . '. Puedes previsualizarlo y descargarlo.',
			'archived' => 'Archivado.',
		);

		return isset( $texts[ $post->post_status ] ) ? $texts[ $post->post_status ] : '';
	}

	/**
	 * The "Datos básicos" card.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_basics( $post ) {
		$type = Documentate_Document_Data::type( $post );
		$rows = array(
			'Nombre interno' => Documentate_Document_Data::short_name( $post ),
			'Título oficial' => (string) $post->post_title,
			'Tipo de documento' => $type ? $type->name : '—',
		);

		$html = '<div class="dcta-card"><h2 class="dcta-h2">Datos básicos</h2><dl class="dcta-ficha">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' !== $value ? $value : '—' ) . '</dd>';
		}

		return $html . '</dl></div>';
	}

	/**
	 * The field cards, one per rol group the visitor may see.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_field_cards( $post ) {
		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		$values = Documents_Meta_Handler::get_structured_field_values( $post->ID );

		if ( empty( $schema ) ) {
			return '<div class="dcta-card"><dl class="dcta-ficha">'
				. '<dt>Contenido</dt><dd>Este documento todavía no tiene campos.</dd>'
				. '</dl></div>';
		}

		$groups = Documentate_Field_Roles::group_by_role( $schema );

		return self::render_group( $groups['area'], $values, 'Datos del área' )
			. self::render_group( $groups['gestion'], $values, 'Datos oficiales · los completa gestión' );
	}

	/**
	 * One card with the fields of a rol group.
	 *
	 * @param array  $rows   Schema rows of the group.
	 * @param array  $values Stored field values.
	 * @param string $title  Card heading.
	 * @return string Empty when the visitor may see none of the rows.
	 */
	private static function render_group( array $rows, array $values, $title ) {
		$html = '';

		foreach ( $rows as $field ) {
			$slug = isset( $field['slug'] ) ? sanitize_key( $field['slug'] ) : '';
			if ( '' === $slug || 'post_title' === $slug || ! Documentate_Field_Roles::can_view( (array) $field ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && '' !== $field['label']
				? $field['label']
				: Documents_Meta_Handler::humanize_unknown_field_label( $slug );

			$html .= '<dt>' . esc_html( $label ) . '</dt>';
			$html .= '<dd>' . esc_html( self::value_summary( $field, isset( $values[ $slug ] ) ? $values[ $slug ] : null ) ) . '</dd>';
		}

		if ( '' === $html ) {
			return '';
		}

		return '<div class="dcta-card"><h2 class="dcta-h2">' . esc_html( $title ) . '</h2>'
			. '<dl class="dcta-ficha">' . $html . '</dl></div>';
	}

	/**
	 * One-line summary of a stored field value.
	 *
	 * @param array      $field Schema row.
	 * @param array|null $info  Stored entry (value/type), or null.
	 * @return string
	 */
	private static function value_summary( $field, $info ) {
		if ( isset( $field['type'] ) && 'array' === $field['type'] ) {
			$items = null !== $info ? Documents_Meta_Handler::get_array_field_items_from_structured( $info ) : array();
			$total = count( $items );

			return 1 === $total ? '1 elemento' : $total . ' elementos';
		}

		$value = null !== $info && isset( $info['value'] ) ? (string) $info['value'] : '';
		$value = trim( wp_strip_all_tags( $value ) );

		if ( '' === $value ) {
			return '—';
		}

		return mb_strlen( $value ) > 240 ? mb_substr( $value, 0, 240 ) . '…' : $value;
	}

	/**
	 * The "Fichero del documento" card.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_attachment( $post ) {
		$attachment = Documentate_Document_Data::attachment( $post );

		$html = '<div class="dcta-card dcta-adjunto"><h2 class="dcta-h2">Fichero del documento</h2>';
		if ( ! $attachment ) {
			return $html . '<p class="dcta-ayuda">Sin fichero adjunto.</p></div>';
		}

		$author = get_userdata( (int) $attachment->post_author );
		$who = $author ? (string) $author->display_name : '';

		$html .= '<p class="dcta-adjunto-fila">'
			. '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
			. '<span class="dcta-adjunto-nombre">' . esc_html( Documentate_App_Attachments::name( $attachment->ID ) ) . '</span>'
			. '<span class="dcta-adjunto-peso">' . esc_html( Documentate_App_Attachments::readable_size( $attachment->ID ) ) . '</span>'
			. '<a href="' . esc_url( Documentate_App_Attachments::url( $post->ID ) ) . '" target="_blank" rel="noopener">Abrir</a>'
			. '</p>';

		$html .= '<p class="dcta-ayuda">'
			. esc_html( 'adjuntado por ' . $who . ' el ' . get_the_date( Documentate_App_Shell::DATE_FORMAT, $attachment ) )
			. '</p>';

		return $html . '</div>';
	}

	/**
	 * The "Actividad" card: events, comments and the box to add one.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	public static function render_activity( $post ) {
		$rows = Documentate_Activity::entries( $post->ID );

		$html = '<div class="dcta-card dcta-actividad"><h2 class="dcta-h2">Actividad</h2>';

		if ( empty( $rows ) ) {
			$html .= '<p class="dcta-ayuda">Todavía no hay actividad.</p>';
		} else {
			$html .= '<ul class="dcta-actividad-lista">';
			foreach ( $rows as $row ) {
				$html .= '<li class="dcta-actividad-item dcta-actividad-' . esc_attr( $row['type'] ) . '">'
					. '<b>' . esc_html( $row['author'] ) . '</b> '
					. esc_html( $row['text'] )
					. ' <small>' . esc_html( $row['relative'] ) . '</small>'
					. '</li>';
			}
			$html .= '</ul>';
		}

		$form = Documentate_App_Shell::FORM_COMMENT_ID;
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
	public static function render_comment_form( $post, $redirect_to = 'detalle' ) {
		$target = 'editar' === $redirect_to ? 'editar' : 'detalle';

		return '<form class="dcta-form-oculto" id="' . esc_attr( Documentate_App_Shell::FORM_COMMENT_ID ) . '" method="post" action="">'
			. wp_nonce_field( 'documentate_app_comentar_' . $post->ID, 'documentate_app_nonce', true, false )
			. '<input type="hidden" name="documentate_app_accion" value="comentar" />'
			. '<input type="hidden" name="documentate_app_doc" value="' . esc_attr( (string) $post->ID ) . '" />'
			. '<input type="hidden" name="documentate_app_redirect_to" value="' . esc_attr( $target ) . '" />'
			. '<input type="hidden" name="documentate_app_bandeja" value="' . esc_attr( Documentate_App_List::current_tray() ) . '" />'
			. '</form>';
	}

	/**
	 * Render the side rail: the stepper and the actions.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_side( $post ) {
		$chip = Documentate_App_Shell::chip( $post );

		$html = '<div class="dcta-lado">';
		$html .= '<div class="dcta-card">'
			. '<h2 class="dcta-h2">Estado</h2>'
			. '<span class="' . esc_attr( $chip['class'] ) . '">' . esc_html( $chip['text'] ) . '</span>'
			. self::render_stepper( $post )
			. '</div>';

		$html .= '<div class="dcta-card"><h2 class="dcta-h2">Acciones</h2>';

		if ( Documentate_App_Edit::can_edit( $post ) ) {
			// The tray travels with the link: the editor lights up the tab the
			// visitor came from and its back link points at a tray that really
			// holds this document.
			$edit_url = Documentate_App_Edit::url( $post->ID, Documentate_App_List::current_tray() );
			$html .= '<a class="dcta-btn dcta-btn-pri" href="' . esc_url( $edit_url ) . '">Editar</a>';
		}

		$html .= self::render_actions_form( $post );
		$html .= Documentate_Admin_Helper::export_block( $post );
		$html .= Documentate_App_Shell::back_link();

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
	private static function render_actions_form( $post ) {
		$buttons = Documentate_App_Shell::transition_buttons( $post );
		if ( '' === $buttons ) {
			return '';
		}

		return '<form class="dcta-acciones" id="' . esc_attr( Documentate_App_Shell::FORM_ID ) . '" method="post" action="">'
			. wp_nonce_field( 'documentate_app_transicion_' . $post->ID, 'documentate_app_nonce', true, false )
			. '<input type="hidden" name="documentate_app_accion" value="transicion" />'
			. '<input type="hidden" name="documentate_app_doc" value="' . esc_attr( (string) $post->ID ) . '" />'
			. '<input type="hidden" name="documentate_app_bandeja" value="' . esc_attr( Documentate_App_List::current_tray() ) . '" />'
			. $buttons
			. '</form>';
	}

	/**
	 * The workflow stepper: where the document has been and where it is going.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_stepper( $post ) {
		$steps = self::steps( $post );
		$keys = array_keys( $steps );
		$current = self::current_index( $post->post_status, $keys );

		$html = '<div class="dcta-stepper">';
		foreach ( $keys as $index => $key ) {
			$status = 'dcta-paso-futuro';
			$sub = '—';
			if ( $index < $current ) {
				$status = 'dcta-paso-hecho';
				$sub = self::done_sub( $post, $steps[ $key ] );
			} elseif ( $index === $current ) {
				$status = 'dcta-paso-actual';
				$sub = $steps[ $key ]['current'];
			}

			$html .= '<div class="dcta-paso ' . esc_attr( $status ) . '">'
				. '<span class="dcta-paso-punto" aria-hidden="true"></span>'
				. '<span class="dcta-paso-t">' . esc_html( $steps[ $key ]['label'] ) . '</span>'
				. '<small class="dcta-paso-sub">' . esc_html( $sub ) . '</small>'
				. '</div>';
		}

		return $html . '</div>';
	}

	/**
	 * The steps of the workflow for this document type.
	 *
	 * @param WP_Post $post Document.
	 * @return array<string,array{label:string,current:string,done:string,event:string}>
	 */
	private static function steps( $post ) {
		$steps = array(
			'draft' => array(
				'label' => 'Borrador',
				'current' => 'En preparación',
				'done' => 'enviado el ',
				'event' => 'envió el documento',
			),
			'en_gestion' => array(
				'label' => 'En gestión',
				'current' => 'Completando datos oficiales',
				'done' => 'completado el ',
				'event' => 'pasó el documento',
			),
			'pending' => array(
				'label' => 'En revisión',
				'current' => 'Pendiente de aprobar',
				'done' => 'aprobado el ',
				'event' => 'aprobó y publicó',
			),
			'publish' => array(
				'label' => 'Aprobado',
				'current' => 'Aprobado',
				'done' => 'aprobado el ',
				'event' => 'aprobó y publicó',
			),
		);

		// A type can stop going through gestión documental (its flag is
		// unchecked, or its template loses the rol='gestion' fields) while a
		// document of that type is already standing in en_gestion: the step it
		// is on stays on the rail, or the stepper would say "Borrador".
		if ( ! Documentate_Document_Data::has_management( $post ) && 'en_gestion' !== $post->post_status ) {
			unset( $steps['en_gestion'] );
		}

		return $steps;
	}

	/**
	 * Index of the step the document is standing on.
	 *
	 * @param string   $status Post status.
	 * @param string[] $keys   Step keys, in order.
	 * @return int
	 */
	private static function current_index( $status, array $keys ) {
		$effective = 'archived' === $status ? 'publish' : $status;
		$effective = 'auto-draft' === $effective ? 'draft' : $effective;
		$index = array_search( $effective, $keys, true );

		return false === $index ? 0 : (int) $index;
	}

	/**
	 * Sub-label of a step already left behind: when it happened.
	 *
	 * @param WP_Post $post Document.
	 * @param array   $step Step row.
	 * @return string
	 */
	private static function done_sub( $post, array $step ) {
		$date = Documentate_Activity::event_date( $post->ID, $step['event'] );
		if ( '' === $date ) {
			return $step['done'] . '—';
		}

		$timestamp = strtotime( $date );

		return $step['done'] . ( false === $timestamp ? '—' : date_i18n( 'j M', $timestamp ) );
	}
}
