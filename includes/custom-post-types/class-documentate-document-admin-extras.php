<?php
/**
 * Small wp-admin editor additions that have nothing to do with the schema.
 *
 * The "Nombre interno" input under the title, the "Anotaciones internas"
 * metabox (gestión / administración only) and the read-only "Actividad"
 * metabox. Kept out of Documentate_Document_Meta_Boxes, which already renders
 * the whole schema-driven sections metabox, so that class does not grow past
 * its own job.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

/**
 * Registers and renders the "Nombre interno", "Anotaciones internas" and
 * "Actividad" additions of the document editor.
 */
class Documentate_Document_Admin_Extras {

	/**
	 * Register the two metaboxes and the field printed under the title.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_internal_name_field' ) );
	}
	/**
	 * Add the "Anotaciones internas" (gestión / administración only) and
	 * "Actividad" (everyone, read-only) side metaboxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		if ( Documentate_Roles::is_management() ) {
			add_meta_box(
				'documentate_anotaciones',
				'Anotaciones internas',
				array( $this, 'render_notes_metabox' ),
				'documentate_document',
				'side',
				'low',
			);
		}

		add_meta_box(
			'documentate_actividad',
			'Actividad',
			array( $this, 'render_activity_metabox' ),
			'documentate_document',
			'side',
			'low',
		);
	}
	/**
	 * Render the "Nombre interno" input under the title.
	 *
	 * Hooked on edit_form_after_title, which fires for every post type, so this
	 * checks the post itself rather than relying on a post-type-scoped hook.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_internal_name_field( $post ) {
		if ( ! $post instanceof WP_Post || 'documentate_document' !== $post->post_type ) {
			return;
		}

		$prefix = Documentate_Document_Data::type_prefix( $post );
		$locked = ! Documentate_Workflow::current_user_can_modify_document( $post->ID );
		?>
		<div class="documentate-nombre-interno">
			<label for="documentate_nombre_interno"><?php echo esc_html( 'Nombre interno' ); ?></label>
			<div class="documentate-nombre-interno__grupo">
				<?php if ( '' !== $prefix ) : ?>
					<span class="documentate-nombre-interno__prefijo"><?php echo esc_html( $prefix ); ?></span>
				<?php endif; ?>
				<input
					type="text"
					id="documentate_nombre_interno"
					name="documentate_nombre_interno"
					value="<?php echo esc_attr( Documentate_Document_Data::internal_name( $post ) ); ?>"
					maxlength="<?php echo esc_attr( (string) Documentate_Document_Data::NAME_MAX ); ?>"
					class="widefat"
					<?php disabled( $locked ); ?>
				/>
			</div>
			<p class="description">
				<?php echo esc_html( 'Corto: es el que aparece en las listas. El prefijo lo pone el tipo; no aparece en el documento.' ); ?>
			</p>
		</div>
		<?php
	}
	/**
	 * Render the "Anotaciones internas" metabox (gestión / administración only).
	 *
	 * Notes visible to gestión documental and administración, never printed in
	 * the generated document.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_notes_metabox( $post ) {
		$locked = ! Documentate_Workflow::current_user_can_modify_document( $post->ID );
		?>
		<textarea
			name="documentate_anotaciones"
			class="widefat"
			rows="4"
			<?php disabled( $locked ); ?>
		><?php echo esc_textarea( Documentate_Document_Data::notes( $post ) ); ?></textarea>
		<p class="description">
			<?php echo esc_html( 'Solo las ven gestión y administración; no salen en el documento.' ); ?>
		</p>
		<?php
	}
	/**
	 * Render the "Actividad" metabox: a read-only list of events and comments.
	 *
	 * Shows the same history the front-end application does, but no comment
	 * form: the application's own comment box is the one place to add to it.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_activity_metabox( $post ) {
		$rows = Documentate_Activity::entries( $post->ID );
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html( 'Todavía no hay actividad.' ) . '</p>';
			return;
		}

		echo '<ul class="documentate-actividad-lista">';
		foreach ( $rows as $row ) {
			printf(
				'<li class="documentate-actividad-lista__item documentate-actividad-lista__item--%1$s"><strong>%2$s</strong> %3$s <span class="description">(%4$s)</span></li>',
				esc_attr( $row['type'] ),
				esc_html( $row['author'] ),
				esc_html( $row['text'] ),
				esc_html( '' !== $row['relative'] ? $row['relative'] : $row['date'] ),
			);
		}
		echo '</ul>';
	}
}
