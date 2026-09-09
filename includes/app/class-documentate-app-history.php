<?php
/**
 * Revision history view of the front-end application.
 *
 * Compares two saved versions of a document the way wp-admin's revisions
 * screen does — side by side, red and green — but drawn inside the
 * application, without the slider or the admin chrome, and field by field:
 * the title and each field stored in the content of a version are read as
 * plain text (no field markers, no HTML) and compared with wp_text_diff(),
 * under the labels of the document's type.
 *
 * Restoring a version stays in wp-admin: it interacts with the workflow
 * statuses and administración already has a way there.
 *
 * @package Documentate
 * @subpackage App
 */

use Documentate\Documents\Documents_Meta_Handler;
use Documentate\Documents\Documents_Revision_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the history of one document.
 */
class Documentate_App_History {

	/**
	 * View key in the `vista` query argument.
	 *
	 * @var string
	 */
	const VIEW = 'historial';

	/**
	 * URL of the history view of a document.
	 *
	 * @param int $doc_id Document post ID.
	 * @param int $from   Revision to compare from; 0 for the previous one.
	 * @param int $to     Revision to compare to; 0 for the latest.
	 * @return string
	 */
	public static function url( $doc_id, $from = 0, $to = 0 ) {
		$args = array(
			'doc' => $doc_id,
			'vista' => self::VIEW,
		);
		if ( $from > 0 ) {
			$args['desde'] = $from;
		}
		if ( $to > 0 ) {
			$args['hasta'] = $to;
		}

		return Documentate_App_Shell::page_url( $args );
	}

	/**
	 * The row of the editor's "Estado" card: how many versions there are and
	 * the way to compare them — text and a link, the way wp-admin's publish
	 * box counts revisions.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	public static function meta_row( $post ) {
		$count = count( self::revisions( $post ) );
		$text = esc_html( ucfirst( self::count_text( $count ) ) );

		if ( $count > 0 ) {
			$text .= ' · <a href="' . esc_url( self::url( $post->ID ) ) . '">Ver cambios</a>';
		}

		return '<dt>Historial</dt><dd>' . $text . '</dd>';
	}

	/**
	 * The button at the foot of the document view, counting the saved versions.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	public static function button( $post ) {
		$count = count( self::revisions( $post ) );

		$text = 'Ver historial de cambios';
		if ( $count > 0 ) {
			$text .= ' (' . $count . ( 1 === $count ? ' versión' : ' versiones' ) . ')';
		}

		return '<a class="dcta-btn dcta-btn-ton dcta-historial-btn" href="' . esc_url( self::url( $post->ID ) ) . '">'
			. Documentate_App_Shell::icon( 'clock' )
			. esc_html( $text )
			. '</a>';
	}

	/**
	 * Render the history view.
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
			return Documentate_App_Shell::open( 'lista', 'Historial', '' )
				. '<div class="dcta-aviso">Este documento no existe o está fuera de tu ámbito.</div>'
				. Documentate_App_Shell::close();
		}

		$revisions = self::revisions( $post );

		$html = Documentate_App_Shell::open(
			'lista',
			Documentate_Document_Data::short_name( $post ),
			'Historial de cambios · ' . self::count_text( count( $revisions ) ),
			Documentate_App_Shell::document_tab( $post )
		);

		list( $from, $to ) = self::requested_range( $revisions );

		$html .= '<div class="dcta-detalle dcta-historial">';
		$html .= '<div class="dcta-detalle-cuerpo">';

		if ( empty( $revisions ) ) {
			$html .= '<div class="dcta-card dcta-historial-card">'
				. '<p class="dcta-ayuda">Todavía no hay versiones guardadas de este documento.</p>'
				. '</div>';
		} else {
			$html .= self::render_compare_form( $post, $revisions, $from, $to );
			$html .= self::render_diff( $post, $revisions, $from, $to );
		}

		$html .= '</div>';
		$html .= self::render_side( $post, $revisions, $to );
		$html .= '</div>';

		return $html . Documentate_App_Shell::close();
	}

	/**
	 * The saved versions of a document, newest first.
	 *
	 * Autosaves are left out: they are one person's unsaved typing, not a
	 * version anybody chose to keep.
	 *
	 * @param WP_Post $post Document.
	 * @return WP_Post[] Revisions keyed by ID, newest first.
	 */
	public static function revisions( $post ) {
		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'order' => 'DESC',
				'check_enabled' => false,
			)
		);

		foreach ( $revisions as $id => $revision ) {
			if ( wp_is_post_autosave( $revision ) ) {
				unset( $revisions[ $id ] );
			}
		}

		return $revisions;
	}

	/**
	 * "3 versiones", "1 versión", "sin versiones".
	 *
	 * @param int $count Number of revisions.
	 * @return string
	 */
	private static function count_text( $count ) {
		if ( 0 === $count ) {
			return 'sin versiones guardadas';
		}

		return 1 === $count ? '1 versión guardada' : $count . ' versiones guardadas';
	}

	/**
	 * The two revisions the request asks to compare, checked and defaulted.
	 *
	 * `hasta` defaults to the newest revision and `desde` to the one saved
	 * just before it; an ID that is not a revision of this document falls
	 * back the same way. When `hasta` is the oldest version there is nothing
	 * before it, and `desde` is 0: wp_get_revision_ui_diff() then shows the
	 * whole version as added, as wp-admin does for the first revision.
	 *
	 * @param WP_Post[] $revisions Revisions keyed by ID, newest first.
	 * @return array{0:int,1:int} From and to revision IDs; both 0 without revisions.
	 */
	private static function requested_range( array $revisions ) {
		if ( empty( $revisions ) ) {
			return array( 0, 0 );
		}

		$ids = array_keys( $revisions );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$to = isset( $_GET['hasta'] ) ? absint( $_GET['hasta'] ) : 0;
		if ( ! in_array( $to, $ids, true ) ) {
			$to = (int) $ids[0];
		}

		$position = array_search( $to, $ids, true );
		$previous = isset( $ids[ $position + 1 ] ) ? (int) $ids[ $position + 1 ] : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$from = isset( $_GET['desde'] ) ? absint( $_GET['desde'] ) : 0;
		if ( ! in_array( $from, $ids, true ) || $from === $to ) {
			$from = $previous;
		}

		return array( $from, $to );
	}

	/**
	 * One line naming a revision: its number, date, time and author.
	 *
	 * The number counts from the oldest version, so two saves within the same
	 * minute still read apart.
	 *
	 * @param WP_Post   $revision  Revision.
	 * @param WP_Post[] $revisions Every revision of the document, newest first.
	 * @return string
	 */
	public static function label( $revision, array $revisions ) {
		$ids = array_reverse( array_keys( $revisions ) );
		$number = (int) array_search( $revision->ID, $ids, true ) + 1;
		$author = get_the_author_meta( 'display_name', (int) $revision->post_author );
		$date = get_the_time( Documentate_App_Shell::DATE_FORMAT . ', H:i', $revision );

		$label = 'Versión ' . $number . ' · ' . $date;

		return '' !== $author ? $label . ' · ' . $author : $label;
	}

	/**
	 * The form that picks the two versions to compare.
	 *
	 * A plain GET form: the page's own query arguments travel as hidden
	 * inputs (see Documentate_App_Shell::page_query_fields()), then the
	 * document and the view, then the two selects.
	 *
	 * @param WP_Post   $post      Document.
	 * @param WP_Post[] $revisions Revisions keyed by ID, newest first.
	 * @param int       $from      Selected "from" revision (0 for none).
	 * @param int       $to        Selected "to" revision.
	 * @return string
	 */
	private static function render_compare_form( $post, array $revisions, $from, $to ) {
		$action = Documentate_App_Shell::page_url();

		$html = '<form class="dcta-card dcta-historial-card dcta-historial-form" method="get" action="' . esc_url( $action ) . '">'
			. Documentate_App_Shell::page_query_fields()
			. '<input type="hidden" name="doc" value="' . esc_attr( (string) $post->ID ) . '" />'
			. '<input type="hidden" name="vista" value="' . esc_attr( self::VIEW ) . '" />'
			. '<h2 class="dcta-h2">Comparar versiones</h2>'
			. '<div class="dcta-historial-rango">'
			. self::render_select( 'desde', 'Desde', $revisions, $from, true )
			. '<span class="dcta-historial-flecha" aria-hidden="true">→</span>'
			. self::render_select( 'hasta', 'Hasta', $revisions, $to, false )
			. '<button type="submit" class="dcta-btn dcta-btn-pri">Comparar</button>'
			. '</div>'
			. '<p class="dcta-ayuda">Lo que aparece en rojo estaba en la versión de la izquierda y ya no está; lo verde es lo que añade la versión de la derecha.</p>'
			. '</form>';

		return $html;
	}

	/**
	 * One of the two revision selects.
	 *
	 * @param string    $name       Query argument name.
	 * @param string    $label      Visible label.
	 * @param WP_Post[] $revisions  Revisions keyed by ID, newest first.
	 * @param int       $selected   Selected revision ID.
	 * @param bool      $allow_none Whether "nothing" (the empty document) is an option.
	 * @return string
	 */
	private static function render_select( $name, $label, array $revisions, $selected, $allow_none ) {
		$id = 'dcta-historial-' . $name;

		$html = '<div class="dcta-campo dcta-historial-campo">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

		foreach ( $revisions as $revision ) {
			$html .= '<option value="' . esc_attr( (string) $revision->ID ) . '"' . selected( $selected, $revision->ID, false ) . '>'
				. esc_html( self::label( $revision, $revisions ) )
				. '</option>';
		}

		if ( $allow_none ) {
			$html .= '<option value="0"' . selected( $selected, 0, false ) . '>Documento vacío</option>';
		}

		return $html . '</select></div>';
	}

	/**
	 * The comparison itself: one block per field that changed.
	 *
	 * The title and every field stored in the content of each version are
	 * compared as readable text — no field markers, no HTML — with
	 * wp_text_diff(), the table wp-admin's revisions screen draws: the left
	 * column is the "from" version, the right one the "to" version. Fields
	 * that read the same are left out.
	 *
	 * @param WP_Post   $post      Document.
	 * @param WP_Post[] $revisions Revisions keyed by ID.
	 * @param int       $from      Revision to compare from (0 for none).
	 * @param int       $to        Revision to compare to.
	 * @return string
	 */
	private static function render_diff( $post, array $revisions, $from, $to ) {
		$before = self::readable_fields( isset( $revisions[ $from ] ) ? $revisions[ $from ] : null );
		$after = self::readable_fields( $revisions[ $to ] );
		$labels = Documentate_Admin::revision_field_labels( $post->ID );

		$sections = '';
		foreach ( array_keys( $after + $before ) as $slug ) {
			$diff = wp_text_diff(
				isset( $before[ $slug ] ) ? $before[ $slug ] : '',
				isset( $after[ $slug ] ) ? $after[ $slug ] : '',
				array( 'show_split_view' => true )
			);
			if ( '' === $diff ) {
				continue;
			}

			$label = isset( $labels[ $slug ] ) ? $labels[ $slug ] : Documents_Meta_Handler::humanize_unknown_field_label( $slug );
			$sections .= '<section class="dcta-historial-campo-diff">'
				. '<h3 class="dcta-historial-h3">' . esc_html( $label ) . '</h3>'
				. $diff // Built by wp_text_diff() from escaped text; the markup wp-admin prints.
				. '</section>';
		}

		if ( '' === $sections ) {
			$sections = '<p class="dcta-ayuda dcta-historial-igual">No hay diferencias entre estas dos versiones.</p>';
		}

		return '<div class="dcta-card dcta-historial-card dcta-historial-diff">' . $sections . '</div>';
	}

	/**
	 * What a version says in each field, as readable text keyed by slug.
	 *
	 * The title comes first, then the fields in the order the content stores
	 * them. A repeater lists one row per line, its cells separated by dots.
	 *
	 * @param WP_Post|null $revision Revision; null for the empty document.
	 * @return array<string,string>
	 */
	public static function readable_fields( $revision ) {
		if ( ! $revision instanceof WP_Post ) {
			return array();
		}

		$fields = array( 'post_title' => $revision->post_title );

		foreach ( Documents_Meta_Handler::parse_structured_content( $revision->post_content ) as $slug => $entry ) {
			if ( 'array' === $entry['type'] ) {
				$rows = array();
				foreach ( Documents_Meta_Handler::get_array_field_items_from_structured( $entry ) as $row ) {
					$rows[] = implode( ' · ', array_filter( array_filter( (array) $row, 'is_scalar' ), 'strlen' ) );
				}
				$entry['value'] = implode( "\n", $rows );
			}
			$fields[ $slug ] = $entry['value'];
		}

		return array_map( array( Documents_Revision_Handler::class, 'readable_text' ), $fields );
	}

	/**
	 * The side rail: every saved version, and the way back to the document.
	 *
	 * Each version links to the comparison with the one saved before it,
	 * which is what "what changed here?" means.
	 *
	 * @param WP_Post   $post      Document.
	 * @param WP_Post[] $revisions Revisions keyed by ID, newest first.
	 * @param int       $to        Revision on screen, highlighted in the list.
	 * @return string
	 */
	private static function render_side( $post, array $revisions, $to ) {
		$html = '<div class="dcta-lado">';
		$html .= '<div class="dcta-card"><h2 class="dcta-h2">Versiones</h2>';

		if ( empty( $revisions ) ) {
			$html .= '<p class="dcta-ayuda">Cada vez que se guarda el documento queda una versión aquí.</p>';
		} else {
			$html .= '<ol class="dcta-historial-lista">';
			foreach ( $revisions as $revision ) {
				$current = $revision->ID === $to;
				$html .= '<li class="dcta-historial-item' . ( $current ? ' dcta-historial-item-on' : '' ) . '">'
					. '<a href="' . esc_url( self::url( $post->ID, 0, $revision->ID ) ) . '"'
					. ( $current ? ' aria-current="true"' : '' ) . '>'
					. esc_html( self::label( $revision, $revisions ) )
					. '</a></li>';
			}
			$html .= '</ol>';
		}

		$html .= '</div>';

		$html .= '<a class="dcta-editor-volver" href="' . esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ) . '">← Volver al documento</a>';

		return $html . '</div></div>';
	}
}
