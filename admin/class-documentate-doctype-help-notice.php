<?php
/**
 * Display a transient help notice on the doctype taxonomy screens.
 *
 * @package    documentate
 * @subpackage Documentate/admin
 */

defined( 'ABSPATH' ) || exit();

/**
 * Render an informational notice on the doctype taxonomy list.
 *
 * @package    documentate
 * @subpackage Documentate/admin
 */
class Documentate_Doctype_Help_Notice {
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

		$target_taxonomy = apply_filters( 'documentate_doctype_help_notice_taxonomy', 'documentate_doc_type' );
		if ( empty( $screen->taxonomy ) || $target_taxonomy !== $screen->taxonomy ) {
			return;
		}

		$content = $this->get_notice_content();
		$content = apply_filters( 'documentate_doctype_help_notice_html', $content, $screen );
		if ( empty( $content ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible documentate-doctype-help">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses( $content, $this->get_allowed_tags() );
		echo '</div>';
	}

	/**
	 * Return the default HTML content for the help notice.
	 *
	 * @return string
	 */
	private function get_notice_content() {
		$markup = '';
		$markup .= '<p><strong>' . esc_html( 'Plantillas para ODT/DOCX:' ) . '</strong> ';
		$markup .=
			esc_html(
				'wp-documentate puede leer los siguientes campos definidos en la plantilla y generar el documento final.',
			) . '</p>';

		$markup .= '<p><strong>' . esc_html( 'Campos:' ) . '</strong> ';
		$markup .= esc_html( 'escribe marcadores así:' ) . ' <code>';
		$markup .= esc_html( "[name;type='...';title='...';placeholder='...';description='...']" );
		$markup .= '</code>.</p>';

		$markup .= '<ul style="margin-left:1.2em;list-style:disc;">';
		$markup .= '<li><strong>' . esc_html( 'Tipos' ) . '</strong>: ';
		$markup .=
			esc_html( 'si no pones' )
			. ' <code>type</code> &rarr; <em>'
			. esc_html( 'textarea' )
			. '</em>. ';
		$markup .= esc_html( 'Soportados:' ) . ' <code>text</code>, <code>textarea</code>, <code>html</code> ';
		$markup .=
			'('
			. esc_html( 'TinyMCE' )
			. '), <code>number</code>, <code>date</code>, <code>email</code>, <code>url</code>, <code>select</code>.</li>';

		$markup .= '<li><strong>' . esc_html( 'Validación' ) . '</strong>: ';
		$markup .= '<code>required</code> ' . esc_html( '(campo obligatorio)' ) . ', ';
		$markup .= '<code>pattern</code> ' . esc_html( '(regex) y' ) . ' <code>patternmsg</code>. ';
		$markup .= esc_html( 'Límites con' ) . ' <code>minvalue</code>/<code>maxvalue</code>. ';
		$markup .= esc_html( 'Longitud con' ) . ' <code>length</code>.</li>';

		$markup .= '<li><strong>' . esc_html( 'Ayuda UI' ) . '</strong>: <code>title</code> ';
		$markup .= '(' . esc_html( 'etiqueta' ) . '), <code>placeholder</code>, <code>description</code> ';
		$markup .= '(' . esc_html( 'texto de ayuda' ) . ').</li>';

		$markup .= '<li><strong>' . esc_html( 'Capitalización' ) . '</strong>: <code>ope</code> ';
		$markup .= '(<code>upper</code>, <code>lower</code>, <code>upperw</code>). ';
		$markup .= esc_html( 'Transformación de mayúsculas/minúsculas en línea.' ) . ' ';
		$markup .=
			esc_html( 'Usa' ) . ' <code>utf8</code> ' . esc_html( 'antes para acentos/ñ.' ) . ' ';
		$markup .= esc_html( 'Ejemplo:' ) . ' <code>[name;ope=utf8,upper]</code>.</li>';

		$markup .= '<li><strong>' . esc_html( 'Formato de fecha' ) . '</strong>: <code>frm</code> ';
		$markup .= esc_html( 'para campos de fecha.' ) . ' ';
		$markup .=
			esc_html( 'Ejemplo:' )
			. ' <code>[fecha;frm=\'dd/mm/yyyy\']</code>, <code>[fecha;frm=\'d mmmm yyyy\']</code>.</li>';

		$markup .= '<li><strong>' . esc_html( 'Más información' ) . '</strong>: ';
		$markup .=
			'<a href="https://www.tinybutstrong.com/manual.php" target="_blank" rel="noopener">'
			. esc_html( 'Manual TBS' )
			. '</a> ';
		$markup .= '(' . esc_html( 'frm, ope, condiciones, etc.' ) . ').</li>';
		$markup .= '</ul>';

		$markup .= '<p><strong>' . esc_html( 'Repetidor (listas):' ) . '</strong> ';
		$markup .=
			esc_html( 'usa bloques con' )
			. ' <code>[items;block=begin]</code> &hellip; <code>[items;block=end]</code> ';
		$markup .= esc_html( 'y define dentro los campos de cada elemento.' ) . '</p>';

		$markup .= '<p><strong>' . esc_html( 'Repetidor en tablas:' ) . '</strong> ';
		$markup .= esc_html( 'para repetir filas de tabla, usa' ) . ' <code>block=tbs:row</code> ';
		$markup .= esc_html( 'en el primer campo de la fila en lugar de block=begin/end.' ) . '</p>';

		$markup .= '<p><strong>' . esc_html( 'Ejemplos rápidos:' ) . '</strong></p>';

		$markup .= '<pre style="white-space:pre-wrap;">';
		$markup .= esc_html( "[nombre;type='text';required='true';title='Nombre completo']\n" );
		$markup .= esc_html( "[Email;type='email';title='Email';placeholder='you@domain.com']\n" );
		$markup .= esc_html( "[fecha;type='date';frm='d mmmm yyyy']\n" );
		$markup .= esc_html(
			"[persona;type='select';values='COORDINADOR|PROVEEDOR/A|EMPRESA';description='Elegir un tipo de persona']\n",
		);
		$markup .= esc_html( "[items;block=begin][items.title;type='text'] [items.content;type='html'][items;block=end]\n" );
		$markup .= esc_html( '-- Fila de tabla:' ) . "\n";
		$markup .= esc_html( "| [items.name;block=tbs:row;type='text'] | [items.qty;type='number'] |" );
		$markup .= '</pre>';

		$markup .= '<p><strong>' . esc_html( 'Firma digital (AutoFirma):' ) . '</strong> ';
		$markup .=
			esc_html(
				'añade [sign] para activar el botón "Firmar y descargar". Los parámetros opcionales x e y definen la posición de la firma en puntos PDF desde la esquina inferior izquierda de la página. Usa page para indicar el número de página (-1 = última página).',
			) . '</p>';

		$markup .= '<pre style="white-space:pre-wrap;">';
		$markup .=
			esc_html( '[sign]                          -- ' )
			. esc_html( 'posición por defecto (abajo-izquierda, última página)' )
			. "\n";
		$markup .=
			esc_html( '[sign;x=100;y=200]             -- ' ) . esc_html( 'posición personalizada en la última página' ) . "\n";
		$markup .= esc_html( '[sign;x=50;y=80;page=2]        -- ' ) . esc_html( 'posición personalizada en la página 2' );
		$markup .= '</pre>';

		$markup .=
			'<p>'
			. esc_html( 'Consejo: en ODT/DOCX el texto puede fragmentarse internamente. Para asegurar que cada marcador' )
			. ' ';
		$markup .=
			'<code>[...]</code> '
			. esc_html(
				'quede íntegro, escríbelo en un editor de texto plano y luego copia y pega sin formato.',
			)
			. '</p>';

		return $markup;
	}

	/**
	 * Allowed HTML tags for the notice content.
	 *
	 * @return array
	 */
	private function get_allowed_tags() {
		return array(
			'p' => array(),
			'strong' => array(),
			'code' => array(),
			'ul' => array(
				'style' => array(),
			),
			'li' => array(),
			'em' => array(),
			'pre' => array(
				'style' => array(),
			),
			'a' => array(
				'href' => array(),
				'target' => array(),
				'rel' => array(),
			),
		);
	}
}

new Documentate_Doctype_Help_Notice();
