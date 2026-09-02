<?php
/**
 * Prefix and gestión flag of a document type.
 *
 * Two term meta of the "Tipos de documento" taxonomy: the prefix shown before
 * the internal name in the lists, and whether the type goes through gestión
 * documental. They live apart from Documentate_Doc_Types_Admin, which owns the
 * template and schema fields and is already at its complexity budget.
 *
 * @package documentate
 * @subpackage Documentate/admin
 */

defined( 'ABSPATH' ) || exit();

/**
 * Renders and saves the workflow fields of the document type screens.
 */
class Documentate_Doc_Type_Workflow_Fields {

	/**
	 * Maximum length of a prefix.
	 *
	 * @var int
	 */
	const PREFIJO_MAX = 6;

	/**
	 * Register hooks. Priority 5 draws these fields before the template ones.
	 */
	public function __construct() {
		add_action( 'documentate_doc_type_add_form_fields', array( $this, 'add_fields' ), 5 );
		add_action( 'documentate_doc_type_edit_form_fields', array( $this, 'edit_fields' ), 5 );
		add_action( 'created_documentate_doc_type', array( $this, 'save_term' ) );
		add_action( 'edited_documentate_doc_type', array( $this, 'save_term' ) );
	}

	/**
	 * Render the fields on the Add term screen.
	 *
	 * @return void
	 */
	public function add_fields() {
		echo '<div class="form-field">';
		echo '<label for="documentate_type_prefijo">' . esc_html( 'Prefijo' ) . '</label>';
		$this->render_prefijo_control( '' );
		echo '</div>';
		echo '<div class="form-field">';
		$this->render_con_gestion_control( false );
		echo '</div>';
	}

	/**
	 * Render the fields on the Edit term screen.
	 *
	 * @param WP_Term $term Term being edited.
	 * @return void
	 */
	public function edit_fields( $term ) {
		$prefijo = (string) get_term_meta( $term->term_id, Documentate_Documento::TERM_META_PREFIJO, true );
		$con_gestion = '1' === (string) get_term_meta( $term->term_id, Documentate_Documento::TERM_META_CON_GESTION, true );

		echo '<tr class="form-field">';
		echo '<th scope="row"><label for="documentate_type_prefijo">' . esc_html( 'Prefijo' ) . '</label></th><td>';
		$this->render_prefijo_control( $prefijo );
		echo '</td></tr>';
		echo '<tr class="form-field">';
		echo '<th scope="row">' . esc_html( 'Gestión documental' ) . '</th><td>';
		$this->render_con_gestion_control( $con_gestion );
		echo '</td></tr>';
	}

	/**
	 * Render the prefix input with its help text.
	 *
	 * @param string $prefijo Stored prefix.
	 * @return void
	 */
	private function render_prefijo_control( $prefijo ) {
		echo '<input type="text" id="documentate_type_prefijo" name="documentate_type_prefijo" value="'
			. esc_attr( $prefijo )
			. '" maxlength="' . esc_attr( (string) self::PREFIJO_MAX ) . '" class="documentate-prefijo-field" style="text-transform:uppercase;width:8em" autocomplete="off" />';
		echo '<p class="description">'
			. esc_html( 'Hasta 6 letras o números en mayúsculas. Precede al nombre interno en las listas (RES · Bases del programa); no aparece en el documento.' )
			. '</p>';
	}

	/**
	 * Render the "pasa por gestión documental" checkbox with its note.
	 *
	 * @param bool $con_gestion Whether the type is flagged.
	 * @return void
	 */
	private function render_con_gestion_control( $con_gestion ) {
		echo '<label for="documentate_type_con_gestion">'
			. '<input type="checkbox" id="documentate_type_con_gestion" name="documentate_type_con_gestion" value="1"'
			. checked( $con_gestion, true, false )
			. ' /> '
			. esc_html( 'Pasa por gestión documental' )
			. '</label>';
		echo '<p class="description">'
			. esc_html( "Cualquier campo con rol='gestion' en la plantilla activa este paso." )
			. '</p>';
	}

	/**
	 * Normalise a prefix: letters and digits only, uppercase, at most PREFIJO_MAX characters.
	 *
	 * @param string $prefijo Raw prefix.
	 * @return string
	 */
	public static function normalize_prefijo( $prefijo ) {
		$prefijo = (string) preg_replace( '/[^\p{L}\p{N}]/u', '', (string) $prefijo );

		return mb_strtoupper( mb_substr( $prefijo, 0, self::PREFIJO_MAX ) );
	}

	/**
	 * Persist the prefix and the gestión flag of a saved term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_term( $term_id ) {
		$term_id = absint( $term_id );
		if ( ! $this->verify_term_save_nonce( $term_id ) || ! $this->current_user_can_edit_types() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_term_save_nonce().
		$prefijo = isset( $_POST['documentate_type_prefijo'] )
			? sanitize_text_field( wp_unslash( $_POST['documentate_type_prefijo'] ) )
			: '';
		$con_gestion = empty( $_POST['documentate_type_con_gestion'] ) ? '' : '1';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_term_meta( $term_id, Documentate_Documento::TERM_META_PREFIJO, self::normalize_prefijo( $prefijo ) );
		update_term_meta( $term_id, Documentate_Documento::TERM_META_CON_GESTION, $con_gestion );
	}

	/**
	 * Verify the core taxonomy nonce of a term save.
	 *
	 * The "add-tag" action is generic across taxonomies, so a nonce minted on
	 * another taxonomy's Add term screen only counts when the request is
	 * actually adding a document type.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	private function verify_term_save_nonce( $term_id ) {
		if ( isset( $_POST['_wpnonce'] ) ) {
			return (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'update-tag_' . $term_id );
		}
		if ( ! isset( $_POST['_wpnonce_add-tag'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is what this method verifies.
		if ( 'documentate_doc_type' !== sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce_add-tag'] ) ), 'add-tag' );
	}

	/**
	 * Whether the current user may edit document types.
	 *
	 * The taxonomy caps (manage_options) are what actually keeps non-admins
	 * out of these screens; the handler asserts it instead of inheriting it.
	 *
	 * @return bool
	 */
	private function current_user_can_edit_types() {
		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		return $taxonomy instanceof WP_Taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * Render the badge of a schema entry gestión documental fills in.
	 *
	 * @param array $entry Legacy field, repeater or item definition.
	 * @return void
	 */
	public static function render_rol_badge( array $entry ) {
		if ( Documentate_Campos_Rol::ROL_GESTION !== Documentate_Campos_Rol::rol_del_campo( $entry ) ) {
			return;
		}
		echo ' <span class="documentate-field-rol">' . esc_html( 'gestión' ) . '</span>';
	}
}

new Documentate_Doc_Type_Workflow_Fields();
