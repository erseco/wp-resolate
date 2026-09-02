<?php
/**
 * Document edit view of the front-end application.
 *
 * The form posts the same field names as the wp-admin sections metabox
 * (documentate_field_<slug>, tpl_fields[...]) together with its nonce, so a
 * plain wp_update_post() in Documentate_App_Acciones::handle_save_document()
 * runs the whole existing pipeline: content composition, meta persistence and
 * the workflow status rules. Nothing about how fields are stored is duplicated
 * here; the view only draws the metabox markup inside the app sheet, adds what
 * the application owns (internal name, notes, file, activity) and offers the
 * transitions the rule table allows.
 *
 * @package Documentate
 * @subpackage App
 */

use Documentate\Documents\Documents_Meta_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the edit form of one document.
 */
class Documentate_App_Editar {

	/**
	 * Whether a document can be edited in the app by the current user.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	public static function puede_editar( $post ) {
		return current_user_can( 'edit_post', $post->ID )
			&& Documentate_Workflow::current_user_can_modify_document( $post->ID );
	}

	/**
	 * URL of the edit view of a document.
	 *
	 * @param int    $doc_id  Document post ID.
	 * @param string $bandeja Tray the visitor came from, so the back link knows.
	 * @return string
	 */
	public static function url( $doc_id, $bandeja = '' ) {
		$args = array(
			'doc' => $doc_id,
			'vista' => 'editar',
		);
		if ( '' !== $bandeja && 'mis' !== $bandeja ) {
			$args['bandeja'] = $bandeja;
		}

		return Documentate_App_Shell::page_url( $args );
	}

	/**
	 * Render the edit view.
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

		if ( ! self::puede_editar( $post ) ) {
			return Documentate_App_Shell::abrir( 'lista', Documentate_Documento::nombre_corto( $post ), '' )
				. '<div class="dcta-aviso">Este documento está bloqueado en su estado actual y no se puede editar.'
				. ' <a href="' . esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ) . '">Ver el documento</a></div>'
				. Documentate_App_Shell::cerrar();
		}

		$tipo = Documentate_Documento::tipo( $post );
		$nombre_tipo = $tipo ? $tipo->name : '—';

		$html = Documentate_App_Shell::abrir(
			Documentate_App_Shell::seccion_de_bandeja( Documentate_App_Lista::bandeja_actual() ),
			Documentate_Documento::nombre_corto( $post ),
			$nombre_tipo . ' · completa los datos y guarda; envíalo cuando esté listo.'
		);

		$html .= self::render_avisos();
		$html .= self::render_banner( $post );

		ob_start();
		?>
		<form class="dcta-editor" id="<?php echo esc_attr( Documentate_App_Shell::FORM_ID ); ?>" method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::url( $post->ID ) ); ?>">
			<?php wp_nonce_field( 'documentate_app_guardar_' . $post->ID, 'documentate_app_nonce' ); ?>
			<input type="hidden" name="documentate_app_accion" value="guardar_documento" />
			<input type="hidden" name="documentate_app_doc" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
			<input type="hidden" name="documentate_app_bandeja" value="<?php echo esc_attr( Documentate_App_Lista::bandeja_actual() ); ?>" />

			<div class="dcta-editor-cuerpo">
				<?php
				self::render_basicos( $post, $nombre_tipo );
				self::render_campos( $post );
				self::render_adjunto( $post );
				echo Documentate_App_Detalle::render_actividad( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				?>
			</div>

			<?php self::render_lado( $post, $nombre_tipo ); ?>
		</form>
		<?php
		$html .= (string) ob_get_clean();
		$html .= Documentate_App_Detalle::render_form_comentario( $post, 'editar' );

		// The dialogs belong to the transition buttons: without them there is
		// nothing to confirm and nothing to give a reason for.
		$hay_acciones = ! empty( Documentate_App_Shell::transiciones_app( $post ) );

		return $html . Documentate_App_Shell::cerrar( $hay_acciones );
	}

	/**
	 * Feedback after a redirect from a handler.
	 *
	 * @return string
	 */
	private static function render_avisos() {
		$guardado = Documentate_App_Detalle::bandera( 'guardado' );
		$comentado = Documentate_App_Detalle::bandera( 'comentado' );
		$error = Documentate_App_Detalle::bandera( 'error' );

		if ( '1' === $guardado ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Cambios guardados.</div>';
		}

		if ( '1' === $comentado ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Comentario añadido.</div>';
		}

		if ( '' === $error ) {
			return '';
		}

		return '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( Documentate_App_Detalle::texto_error( $error ) ) . '</div>';
	}

	/**
	 * The banner explaining where this type of document goes when it is sent.
	 *
	 * Only the área needs it, and only while the document is still theirs.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_banner( $post ) {
		if ( null === Documentate_Documento::tipo( $post ) ) {
			// Without a type there is no template, no fields and no way
			// forward: the workflow sends the document straight back to draft.
			return '<div class="dcta-aviso dcta-aviso-info">'
				. esc_html( 'Este documento todavía no tiene tipo de documento. Elígelo y guarda: hasta entonces no se puede enviar ni tiene campos que rellenar.' )
				. '</div>';
		}

		$devuelto = Documentate_App_Shell::aviso_devuelto( $post );
		if ( '' !== $devuelto ) {
			return '<div class="dcta-aviso dcta-aviso-devuelto">' . esc_html( $devuelto ) . '</div>';
		}

		if ( Documentate_Roles::es_gestion() || 'draft' !== $post->post_status ) {
			return '';
		}

		$texto = Documentate_Documento::con_gestion( $post )
			? 'Este tipo de documento pasa por gestión documental. Cuando lo envíes, gestión completará los datos oficiales y ya no podrás modificarlo; si falta algo, te lo devolverán.'
			: 'Este tipo de documento va directo a administración. Cuando lo envíes ya no podrás modificarlo; si falta algo, te lo devolverán.';

		return '<div class="dcta-aviso dcta-aviso-info">' . esc_html( $texto ) . '</div>';
	}

	/**
	 * Print the "Datos básicos" card: internal name, official title and type.
	 *
	 * @param WP_Post $post        Document.
	 * @param string  $nombre_tipo Document type name.
	 * @return void
	 */
	private static function render_basicos( $post, $nombre_tipo ) {
		$prefijo = Documentate_Documento::prefijo_tipo( $post );
		$sin_tipo = null === Documentate_Documento::tipo( $post );
		?>
		<div class="dcta-card dcta-editor-card">
			<h2 class="dcta-h2">Datos básicos</h2>
			<div class="dcta-campo">
				<label for="documentate-app-nombre">Nombre interno</label>
				<span class="dcta-prefijo-grupo">
					<?php if ( '' !== $prefijo ) : ?>
						<span class="dcta-prefijo"><?php echo esc_html( $prefijo ); ?></span>
					<?php elseif ( $sin_tipo ) : ?>
						<span class="dcta-prefijo" id="documentate-app-prefijo" hidden></span>
					<?php endif; ?>
					<input type="text" id="documentate-app-nombre" name="documentate_app_nombre" value="<?php echo esc_attr( Documentate_Documento::nombre_interno( $post ) ); ?>" required maxlength="80" />
				</span>
				<p class="dcta-ayuda">Corto: es el que verás en las listas. El prefijo lo pone el tipo; no aparece en el documento.</p>
			</div>
			<div class="dcta-campo">
				<label for="documentate-app-titulo">Título oficial</label>
				<textarea id="documentate-app-titulo" name="documentate_app_titulo" rows="2" required maxlength="500"><?php echo esc_textarea( $post->post_title ); ?></textarea>
				<p class="dcta-ayuda">El título completo tal y como saldrá en el documento.</p>
			</div>
			<?php self::render_campo_tipo( $sin_tipo, $nombre_tipo ); ?>
		</div>
		<?php
	}

	/**
	 * Print the document type: fixed once it is set, a select while it is not.
	 *
	 * A document saved in wp-admin without picking a type would otherwise be a
	 * dead end here: no fields, no transitions and no way of giving it one.
	 * The select posts the same field and nonce as the wp-admin type metabox,
	 * so Documentate_Document_Meta_Saver::save_doc_type_selection() stores it
	 * — and locks it, exactly as everywhere else.
	 *
	 * @param bool   $sin_tipo    Whether the document still has no type.
	 * @param string $nombre_tipo Document type name.
	 * @return void
	 */
	private static function render_campo_tipo( $sin_tipo, $nombre_tipo ) {
		if ( ! $sin_tipo ) {
			?>
			<div class="dcta-campo">
				<label for="dcta-tipo-fijo">Tipo de documento</label>
				<input type="text" id="dcta-tipo-fijo" value="<?php echo esc_attr( $nombre_tipo ); ?>" readonly />
			</div>
			<?php
			return;
		}

		$tipos = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);
		$tipos = is_wp_error( $tipos ) ? array() : $tipos;
		?>
		<div class="dcta-campo">
			<label for="documentate-app-tipo">Tipo de documento</label>
			<?php wp_nonce_field( 'documentate_type_nonce', 'documentate_type_nonce' ); ?>
			<select id="documentate-app-tipo" name="documentate_doc_type" required>
				<option value="">Elige un tipo…</option>
				<?php foreach ( $tipos as $tipo ) : ?>
					<option value="<?php echo esc_attr( (string) $tipo->term_id ); ?>"
						data-prefijo="<?php echo esc_attr( Documentate_Documento::prefijo_de_tipo( $tipo->term_id ) ); ?>"
						data-gestion="<?php echo esc_attr( Documentate_Documento::tipo_con_gestion( $tipo->term_id ) ? '1' : '' ); ?>"><?php echo esc_html( $tipo->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="dcta-ayuda" id="documentate-app-tipo-nota"></p>
			<p class="dcta-ayuda">El tipo decide la plantilla y los campos, y no se puede cambiar después de guardarlo.</p>
		</div>
		<?php
	}

	/**
	 * Print the fields card: the sections metabox and the internal notes.
	 *
	 * Whoever completes the official data works on their own rows, so the
	 * área ones are folded into a "Datos del área" foldable and the internal
	 * notes go inside the "Datos oficiales" section they belong to. The área
	 * itself sees its fields as they are, and no notes at all.
	 *
	 * @param WP_Post $post Document.
	 * @return void
	 */
	private static function render_campos( $post ) {
		$anotaciones = Documentate_Roles::es_gestion() ? self::bloque_anotaciones( $post ) : '';

		echo '<div class="dcta-card dcta-editor-card">';
		( new Documentate_Document_Meta_Boxes() )->render_sections_metabox( $post, self::envoltorios_de_rol( $anotaciones ) );

		if ( '' !== $anotaciones && ! self::hay_campos_de_gestion( $post ) ) {
			// No official fields to hang the notes on: they get their own section.
			echo '<h3 class="documentate-seccion-rol">Datos oficiales · los completa gestión documental</h3>';
			echo $anotaciones; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the builder.
		}

		echo '</div>';
	}

	/**
	 * The markup wrapped around the rol groups of the sections metabox.
	 *
	 * @param string $anotaciones Internal notes block, empty for the área.
	 * @return array<string,string>
	 */
	private static function envoltorios_de_rol( $anotaciones ) {
		if ( '' === $anotaciones ) {
			return array();
		}

		return array(
			'area_abrir' => '<details class="dcta-seccion-area" open><summary>Datos del área</summary>',
			'area_cerrar' => '</details>',
			'gestion_cerrar' => $anotaciones,
		);
	}

	/**
	 * The "Anotaciones internas" box, as markup.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function bloque_anotaciones( $post ) {
		return '<div class="dcta-campo dcta-anotaciones">'
			. '<label for="documentate-app-anotaciones">Anotaciones internas</label>'
			. '<textarea id="documentate-app-anotaciones" name="documentate_app_anotaciones" rows="3">'
			. esc_textarea( Documentate_Documento::anotaciones( $post ) )
			. '</textarea>'
			. '<p class="dcta-ayuda">Solo las ven gestión y administración; no salen en el documento.</p>'
			. '</div>';
	}

	/**
	 * Whether the document type has fields gestión documental completes.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	private static function hay_campos_de_gestion( $post ) {
		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		$grupos = Documentate_Campos_Rol::agrupar( $schema );

		return ! empty( $grupos['gestion'] );
	}

	/**
	 * Print the file card: what is attached now, and how to replace it.
	 *
	 * @param WP_Post $post Document.
	 * @return void
	 */
	private static function render_adjunto( $post ) {
		$adjunto = Documentate_Documento::adjunto( $post );
		?>
		<div class="dcta-card dcta-editor-card dcta-adjunto">
			<h2 class="dcta-h2">Fichero del documento</h2>
			<?php if ( $adjunto ) : ?>
				<p class="dcta-adjunto-fila">
					<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
					<span class="dcta-adjunto-nombre"><?php echo esc_html( Documentate_App_Adjuntos::nombre( $adjunto->ID ) ); ?></span>
					<span class="dcta-adjunto-peso"><?php echo esc_html( Documentate_App_Adjuntos::tamano_legible( $adjunto->ID ) ); ?></span>
					<a href="<?php echo esc_url( Documentate_App_Adjuntos::url( $post->ID ) ); ?>" target="_blank" rel="noopener">Abrir</a>
				</p>
				<label class="dcta-adjunto-quitar">
					<input type="checkbox" name="documentate_app_quitar_adjunto" value="1" /> Quitar el fichero al guardar
				</label>
			<?php endif; ?>

			<div class="dcta-dropzone" data-dcta-dropzone="1" hidden>
				<p class="dcta-dropzone-texto">Arrastra aquí el fichero del documento</p>
				<p class="dcta-ayuda">PDF, ODT o DOCX · máximo 20 MB</p>
				<button type="button" class="dcta-btn dcta-btn-ton" data-dcta-elegir="1">Elegir fichero</button>
				<p class="dcta-dropzone-elegido" hidden></p>
				<p class="dcta-dropzone-error" role="alert" hidden></p>
			</div>

			<input type="file" id="documentate-app-adjunto" name="documentate_app_adjunto" accept=".pdf,.odt,.docx" />
			<p class="dcta-ayuda">PDF, ODT o DOCX · máximo 20 MB. Si subes otro fichero, sustituye al actual.</p>
		</div>
		<?php
	}

	/**
	 * Print the side rail: status, save, transitions and exports.
	 *
	 * @param WP_Post $post        Document.
	 * @param string  $nombre_tipo Document type name.
	 * @return void
	 */
	private static function render_lado( $post, $nombre_tipo ) {
		$chip = Documentate_App_Shell::chip( $post );
		$guardar = 'draft' === $post->post_status ? 'Guardar borrador' : 'Guardar';
		?>
		<div class="dcta-lado dcta-editor-lado">
			<div class="dcta-card">
				<h2 class="dcta-h2">Estado</h2>
				<span class="<?php echo esc_attr( $chip['clase'] ); ?>"><?php echo esc_html( $chip['texto'] ); ?></span>
				<dl class="dcta-editor-meta">
					<dt>Tipo</dt>
					<dd><?php echo esc_html( $nombre_tipo ); ?></dd>
					<dt>Actualizado</dt>
					<dd><?php echo esc_html( get_the_modified_date( Documentate_App_Shell::FORMATO_FECHA, $post ) . ' · ' . get_the_modified_time( Documentate_App_Shell::FORMATO_HORA, $post ) ); ?></dd>
				</dl>
			</div>
			<div class="dcta-card dcta-editor-acciones">
				<h2 class="dcta-h2">Acciones</h2>
				<button type="submit" class="dcta-btn dcta-btn-ton" name="documentate_app_estado" value="guardar" formnovalidate><?php echo esc_html( $guardar ); ?></button>
				<?php
				echo Documentate_App_Shell::botones_transicion( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				echo Documentate_Admin_Helper::bloque_exportar( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				echo Documentate_App_Shell::enlace_volver(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				?>
			</div>
		</div>
		<?php
	}
}
