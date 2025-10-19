<?php
/**
 * Display a transient help notice on the doctype taxonomy screens.
 *
 * @package    resolate
 * @subpackage Resolate/admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render an informational notice on the doctype taxonomy list.
 *
 * @package    resolate
 * @subpackage Resolate/admin
 */
class Resolate_Doctype_Help_Notice {

	/**
	 * Hook notice output callbacks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_print_notice' ) );
	}

	/**
	 * Print the help notice on the doctype taxonomy list screen.
	 *
	 * @return void
	 */
	public function maybe_print_notice() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}

		$target_taxonomy = apply_filters( 'resolate_doctype_help_notice_taxonomy', 'resolate_doc_type' );
		if ( empty( $screen->taxonomy ) || $target_taxonomy !== $screen->taxonomy ) {
			return;
		}

		$content = $this->get_notice_content();
		$content = apply_filters( 'resolate_doctype_help_notice_html', $content, $screen );
		if ( empty( $content ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible resolate-doctype-help">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses( $content, $this->get_allowed_tags() );
		echo '</div>';
	}

	/**
	 * Return the default HTML content for the help notice.
	 *
	 * @return string
	 */
	private function get_notice_content() {
		$markup   = '';
		$markup  .= '<p><strong>' . esc_html__( 'Plantillas para ODT/DOCX:', 'resolate' ) . '</strong> ';
		$markup  .= esc_html__( 'wp-resolate puede leer los siguientes campos definidos en la plantilla y generar el documento final.', 'resolate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Campos:', 'resolate' ) . '</strong> ';
		$markup .= esc_html__( 'escribe marcadores así:', 'resolate' ) . ' <code>';
		$markup .= esc_html( "[nombre;type='...';title='...';placeholder='...';description='...';pattern='...';patternmsg='...';minvalue='...';maxvalue='...';length='...']" );
		$markup .= '</code>.</p>';

		$markup .= '<ul style="margin-left:1.2em;list-style:disc;">';
		$markup .= '<li><strong>' . esc_html__( 'Tipos', 'resolate' ) . '</strong>: ';
		$markup .= esc_html__( 'si no pones', 'resolate' ) . ' <code>type</code> &rarr; <em>' . esc_html__( 'textarea', 'resolate' ) . '</em>. ';
		$markup .= esc_html__( 'Soportados:', 'resolate' ) . ' <code>text</code>, <code>textarea</code>, <code>html</code> ';
		$markup .= '(' . esc_html__( 'TinyMCE', 'resolate' ) . '), <code>number</code>, <code>date</code>, <code>email</code>, <code>url</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'Validación', 'resolate' ) . '</strong>: ';
		$markup .= '<code>pattern</code> ' . esc_html__( '(regex) y', 'resolate' ) . ' <code>patternmsg</code>. ';
		$markup .= esc_html__( 'Límites con', 'resolate' ) . ' <code>minvalue</code>/<code>maxvalue</code>. ';
		$markup .= esc_html__( 'Longitud con', 'resolate' ) . ' <code>length</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'Ayuda UI', 'resolate' ) . '</strong>: <code>title</code> ';
		$markup .= '(' . esc_html__( 'etiqueta', 'resolate' ) . '), <code>placeholder</code>, <code>description</code> ';
		$markup .= '(' . esc_html__( 'texto de ayuda', 'resolate' ) . ').</li>';
		$markup .= '</ul>';

		$markup .= '<p><strong>' . esc_html__( 'Repeater (listas):', 'resolate' ) . '</strong> ';
		$markup .= esc_html__( 'usa bloques con', 'resolate' ) . ' <code>[items;block=begin]</code> &hellip; <code>[items;block=end]</code> ';
		$markup .= esc_html__( 'y define dentro los campos de cada elemento.', 'resolate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Ejemplos rápidos:', 'resolate' ) . '</strong></p>';

		$markup .= '<pre style="white-space:pre-wrap;">';
		$markup .= esc_html( "[Email;type='email';title='Correo';placeholder='tu@dominio.es']\n" );
		$markup .= esc_html( "[items;block=begin][Título ítem;type='text'] [items.content;type='html'][items;block=end]" );
		$markup .= '</pre>';

		$markup .= '<p>' . esc_html__( 'Consejo: en DOCX el texto puede fragmentarse; asegúrate de que cada marcador', 'resolate' ) . ' ';
		$markup .= '<code>[...]</code> ' . esc_html__( 'queda íntegro.', 'resolate' ) . '</p>';

		return $markup;
	}

	/**
	 * Allowed HTML tags for the notice content.
	 *
	 * @return array
	 */
	private function get_allowed_tags() {
		return array(
			'p'      => array(),
			'strong' => array(),
			'code'   => array(),
			'ul'     => array(
				'style' => array(),
			),
			'li'     => array(),
			'em'     => array(),
			'pre'    => array(
				'style' => array(),
			),
		);
	}
}

new Resolate_Doctype_Help_Notice();
