<?php
/**
 * Document edit view of the front-end application.
 *
 * The form posts the same field names as the wp-admin sections metabox
 * (documentate_field_<slug>, tpl_fields[...]) together with its nonce, so a
 * plain wp_update_post() in Documentate_App_Actions::handle_save_document()
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
class Documentate_App_Edit {

	/**
	 * Whether a document can be edited in the app by the current user.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	public static function can_edit( $post ) {
		return current_user_can( 'edit_post', $post->ID )
			&& Documentate_Workflow::current_user_can_modify_document( $post->ID );
	}

	/**
	 * URL of the edit view of a document.
	 *
	 * @param int    $doc_id Document post ID.
	 * @param string $tray   Tray the visitor came from, so the back link knows.
	 * @return string
	 */
	public static function url( $doc_id, $tray = '' ) {
		$args = array(
			'doc' => $doc_id,
			'vista' => 'editar',
		);
		if ( '' !== $tray && 'mis' !== $tray ) {
			$args['bandeja'] = $tray;
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
			return Documentate_App_Shell::open( 'lista', 'Documento', '' )
				. '<div class="dcta-aviso">Este documento no existe o está fuera de tu ámbito.</div>'
				. Documentate_App_Shell::close();
		}

		if ( ! self::can_edit( $post ) ) {
			return Documentate_App_Shell::open( 'lista', Documentate_Document_Data::short_name( $post ), '' )
				. '<div class="dcta-aviso">Este documento está bloqueado en su estado actual y no se puede editar.'
				. ' <a href="' . esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ) . '">Ver el documento</a></div>'
				. Documentate_App_Shell::close();
		}

		$type = Documentate_Document_Data::type( $post );
		$type_name = $type ? $type->name : '—';

		$html = Documentate_App_Shell::open(
			Documentate_App_Shell::section_for_tray( Documentate_App_List::current_tray() ),
			Documentate_Document_Data::short_name( $post ),
			$type_name . ' · completa los datos y guarda; envíalo cuando esté listo.'
		);

		$html .= self::render_notices();
		$html .= self::render_banner( $post );

		ob_start();
		?>
		<form class="dcta-editor" id="<?php echo esc_attr( Documentate_App_Shell::FORM_ID ); ?>" method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::url( $post->ID ) ); ?>">
			<?php wp_nonce_field( 'documentate_app_guardar_' . $post->ID, 'documentate_app_nonce' ); ?>
			<input type="hidden" name="documentate_app_accion" value="guardar_documento" />
			<input type="hidden" name="documentate_app_doc" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
			<input type="hidden" name="documentate_app_bandeja" value="<?php echo esc_attr( Documentate_App_List::current_tray() ); ?>" />

			<div class="dcta-editor-cuerpo">
				<?php
				self::render_basics( $post, $type_name );
				self::render_fields( $post );
				self::render_attachment( $post );
				echo Documentate_App_Detail::render_activity( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				?>
			</div>

			<?php self::render_side( $post, $type_name ); ?>
		</form>
		<?php
		$html .= (string) ob_get_clean();
		$html .= Documentate_App_Detail::render_comment_form( $post, 'editar' );

		// The dialogs belong to the transition buttons: without them there is
		// nothing to confirm and nothing to give a reason for.
		$has_actions = ! empty( Documentate_App_Shell::app_transitions( $post ) );

		return $html . Documentate_App_Shell::close( $has_actions );
	}

	/**
	 * Feedback after a redirect from a handler.
	 *
	 * @return string
	 */
	private static function render_notices() {
		$saved = Documentate_App_Detail::flag( 'guardado' );
		$commented = Documentate_App_Detail::flag( 'comentado' );
		$error = Documentate_App_Detail::flag( 'error' );

		if ( '1' === $saved ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Cambios guardados.</div>';
		}

		if ( '1' === $commented ) {
			return '<div class="dcta-aviso dcta-aviso-ok">Comentario añadido.</div>';
		}

		if ( '' === $error ) {
			return '';
		}

		return '<div class="dcta-aviso dcta-aviso-mal">' . esc_html( Documentate_App_Detail::error_text( $error ) ) . '</div>';
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
		if ( null === Documentate_Document_Data::type( $post ) ) {
			// Without a type there is no template, no fields and no way
			// forward: the workflow sends the document straight back to draft.
			return '<div class="dcta-aviso dcta-aviso-info">'
				. esc_html( 'Este documento todavía no tiene tipo de documento. Elígelo y guarda: hasta entonces no se puede enviar ni tiene campos que rellenar.' )
				. '</div>';
		}

		$returned = Documentate_App_Shell::returned_notice( $post );
		if ( '' !== $returned ) {
			return '<div class="dcta-aviso dcta-aviso-devuelto">' . esc_html( $returned ) . '</div>';
		}

		if ( Documentate_Roles::is_management() || 'draft' !== $post->post_status ) {
			return '';
		}

		$text = Documentate_Document_Data::has_management( $post )
			? 'Este tipo de documento pasa por gestión documental. Cuando lo envíes, gestión completará los datos oficiales y ya no podrás modificarlo; si falta algo, te lo devolverán.'
			: 'Este tipo de documento va directo a administración. Cuando lo envíes ya no podrás modificarlo; si falta algo, te lo devolverán.';

		return '<div class="dcta-aviso dcta-aviso-info">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Print the "Datos básicos" card: internal name, official title and type.
	 *
	 * @param WP_Post $post        Document.
	 * @param string  $type_name Document type name.
	 * @return void
	 */
	private static function render_basics( $post, $type_name ) {
		$prefix = Documentate_Document_Data::type_prefix( $post );
		$without_type = null === Documentate_Document_Data::type( $post );
		?>
		<div class="dcta-card dcta-editor-card">
			<h2 class="dcta-h2">Datos básicos</h2>
			<div class="dcta-campo">
				<label for="documentate-app-nombre">Nombre interno</label>
				<span class="dcta-prefijo-grupo">
					<?php if ( '' !== $prefix ) : ?>
						<span class="dcta-prefijo"><?php echo esc_html( $prefix ); ?></span>
					<?php elseif ( $without_type ) : ?>
						<span class="dcta-prefijo" id="documentate-app-prefijo" hidden></span>
					<?php endif; ?>
					<input type="text" id="documentate-app-nombre" name="documentate_app_nombre" value="<?php echo esc_attr( Documentate_Document_Data::internal_name( $post ) ); ?>" required maxlength="80" />
				</span>
				<p class="dcta-ayuda">Corto: es el que verás en las listas. El prefijo lo pone el tipo; no aparece en el documento.</p>
			</div>
			<div class="dcta-campo">
				<label for="documentate-app-titulo">Título oficial</label>
				<textarea id="documentate-app-titulo" name="documentate_app_titulo" rows="2" required maxlength="500"><?php echo esc_textarea( $post->post_title ); ?></textarea>
				<p class="dcta-ayuda">El título completo tal y como saldrá en el documento.</p>
			</div>
			<?php self::render_type_field( $without_type, $type_name ); ?>
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
	 * @param bool   $without_type Whether the document still has no type.
	 * @param string $type_name    Document type name.
	 * @return void
	 */
	private static function render_type_field( $without_type, $type_name ) {
		if ( ! $without_type ) {
			?>
			<div class="dcta-campo">
				<label for="dcta-tipo-fijo">Tipo de documento</label>
				<input type="text" id="dcta-tipo-fijo" value="<?php echo esc_attr( $type_name ); ?>" readonly />
			</div>
			<?php
			return;
		}

		$types = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);
		$types = is_wp_error( $types ) ? array() : $types;
		?>
		<div class="dcta-campo">
			<label for="documentate-app-tipo">Tipo de documento</label>
			<?php wp_nonce_field( 'documentate_type_nonce', 'documentate_type_nonce' ); ?>
			<select id="documentate-app-tipo" name="documentate_doc_type" required>
				<option value="">Elige un tipo…</option>
				<?php foreach ( $types as $type ) : ?>
					<option value="<?php echo esc_attr( (string) $type->term_id ); ?>"
						data-prefijo="<?php echo esc_attr( Documentate_Document_Data::prefix_for_type( $type->term_id ) ); ?>"
						data-gestion="<?php echo esc_attr( Documentate_Document_Data::type_has_management( $type->term_id ) ? '1' : '' ); ?>"><?php echo esc_html( $type->name ); ?></option>
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
	private static function render_fields( $post ) {
		$notes = Documentate_Roles::is_management() ? self::notes_block( $post ) : '';

		echo '<div class="dcta-card dcta-editor-card">';
		( new Documentate_Document_Meta_Boxes() )->render_sections_metabox( $post, self::role_wrappers( $notes ) );

		if ( '' !== $notes && ! self::has_management_fields( $post ) ) {
			// No official fields to hang the notes on: they get their own section.
			echo '<h3 class="documentate-seccion-rol">Datos oficiales · los completa gestión documental</h3>';
			echo $notes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the builder.
		}

		echo '</div>';
	}

	/**
	 * The markup wrapped around the rol groups of the sections metabox.
	 *
	 * @param string $notes Internal notes block, empty for the área.
	 * @return array<string,string>
	 */
	private static function role_wrappers( $notes ) {
		if ( '' === $notes ) {
			return array();
		}

		return array(
			'area_open' => '<details class="dcta-seccion-area" open><summary>Datos del área</summary>',
			'area_close' => '</details>',
			'management_close' => $notes,
		);
	}

	/**
	 * The "Anotaciones internas" box, as markup.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function notes_block( $post ) {
		return '<div class="dcta-campo dcta-anotaciones">'
			. '<label for="documentate-app-anotaciones">Anotaciones internas</label>'
			. '<textarea id="documentate-app-anotaciones" name="documentate_app_anotaciones" rows="3">'
			. esc_textarea( Documentate_Document_Data::notes( $post ) )
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
	private static function has_management_fields( $post ) {
		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		$groups = Documentate_Field_Roles::group_by_role( $schema );

		return ! empty( $groups['gestion'] );
	}

	/**
	 * Print the file card: what is attached now, and how to replace it.
	 *
	 * @param WP_Post $post Document.
	 * @return void
	 */
	private static function render_attachment( $post ) {
		$attachment = Documentate_Document_Data::attachment( $post );
		?>
		<div class="dcta-card dcta-editor-card dcta-adjunto">
			<h2 class="dcta-h2">Fichero del documento</h2>
			<?php if ( $attachment ) : ?>
				<p class="dcta-adjunto-fila">
					<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
					<span class="dcta-adjunto-nombre"><?php echo esc_html( Documentate_App_Attachments::name( $attachment->ID ) ); ?></span>
					<span class="dcta-adjunto-peso"><?php echo esc_html( Documentate_App_Attachments::readable_size( $attachment->ID ) ); ?></span>
					<a href="<?php echo esc_url( Documentate_App_Attachments::url( $post->ID ) ); ?>" target="_blank" rel="noopener">Abrir</a>
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
	 * @param string  $type_name Document type name.
	 * @return void
	 */
	private static function render_side( $post, $type_name ) {
		$chip = Documentate_App_Shell::chip( $post );
		$save_label = 'draft' === $post->post_status ? 'Guardar borrador' : 'Guardar';
		?>
		<div class="dcta-lado dcta-editor-lado">
			<div class="dcta-card">
				<h2 class="dcta-h2">Estado</h2>
				<span class="<?php echo esc_attr( $chip['class'] ); ?>"><?php echo esc_html( $chip['text'] ); ?></span>
				<dl class="dcta-editor-meta">
					<dt>Tipo</dt>
					<dd><?php echo esc_html( $type_name ); ?></dd>
					<dt>Actualizado</dt>
					<dd><?php echo esc_html( get_the_modified_date( Documentate_App_Shell::DATE_FORMAT, $post ) . ' · ' . get_the_modified_time( Documentate_App_Shell::TIME_FORMAT, $post ) ); ?></dd>
				</dl>
			</div>
			<div class="dcta-card dcta-editor-acciones">
				<h2 class="dcta-h2">Acciones</h2>
				<button type="submit" class="dcta-btn dcta-btn-ton" name="documentate_app_estado" value="guardar" formnovalidate><?php echo esc_html( $save_label ); ?></button>
				<?php
				echo Documentate_App_Shell::transition_buttons( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				echo Documentate_Admin_Helper::export_block( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				echo Documentate_App_Shell::back_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
				?>
			</div>
		</div>
		<?php
	}
}
