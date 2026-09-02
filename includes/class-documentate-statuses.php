<?php
/**
 * Custom post statuses of the document workflow and their admin-list plumbing.
 *
 * Registers "en_gestion" and "archived", names them in the admin list,
 * holds the per-status texts of the management metabox and removes Quick
 * Edit for documents so the hidden status select of the inline editor can
 * never publish behind the workflow's back.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Statuses
 *
 * Status registration, labels, post states and Quick Edit removal.
 */
class Documentate_Statuses {

	/**
	 * Post type of the documents.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'display_post_states', array( __CLASS__, 'display_post_states' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'remove_quick_edit' ), 10, 2 );
	}

	/**
	 * Human labels of every workflow status, in workflow order.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'draft' => 'Borrador',
			'en_gestion' => 'En gestión',
			'pending' => 'En revisión',
			'publish' => 'Aprobado',
			'archived' => 'Archivado',
		);
	}

	/**
	 * Message the management metabox shows for a status: modifier, icon and text.
	 *
	 * Each row carries the flag that picks the text and the two texts
	 * (index 0 when the flag is off, 1 when it is on).
	 *
	 * @param string $key            Status key (draft, en_gestion, pending, publish, archived).
	 * @param bool   $is_admin       Whether current user is admin.
	 * @param bool   $has_management Whether the type goes through gestión documental.
	 * @param bool   $can_modify     Whether the current user may modify the document.
	 * @return array{0:string,1:string,2:string}|null Modifier, dashicon and text; null for other statuses.
	 */
	public static function metabox_message( $key, $is_admin, $has_management, $can_modify ) {
		$messages = array(
			'publish' => array(
				'success',
				'lock',
				$is_admin,
				array(
					'El documento está bloqueado. Contacta con administración.',
					'El documento es de solo lectura. Devuélvelo a revisión para habilitar la edición.',
				),
			),
			'archived' => array(
				'success',
				'archive',
				$is_admin,
				array(
					'El documento está archivado. Contacta con administración para desarchivarlo.',
					'El documento está archivado y es de solo lectura. Desarchívalo para habilitar la edición.',
				),
			),
			'pending' => array(
				'pending',
				'clock',
				$is_admin,
				array(
					'El documento está en revisión. Administración lo aprobará o lo devolverá.',
					'El documento está en revisión. Apruébalo o devuélvelo.',
				),
			),
			'en_gestion' => array(
				'pending',
				'clipboard',
				$can_modify,
				array(
					'El documento está en gestión documental. Ya no puedes modificarlo; si falta algo, te lo devolverán.',
					'El documento está en gestión documental. Completa los datos oficiales y pásalo a administración, o devuélvelo al área si falta algo.',
				),
			),
			'draft' => array(
				'draft',
				'info-outline',
				$has_management,
				array(
					'Envía a revisión cuando esté listo. Administración lo aprobará.',
					'Envía a gestión documental cuando esté listo. Gestión completará los datos oficiales y administración lo aprobará.',
				),
			),
		);

		if ( ! isset( $messages[ $key ] ) ) {
			return null;
		}

		list( $type, $icon, $variant, $texts ) = $messages[ $key ];

		return array( $type, $icon, $texts[ $variant ? 1 : 0 ] );
	}

	/**
	 * Register the custom statuses (en_gestion, archived).
	 *
	 * @return void
	 */
	public static function register() {
		register_post_status(
			'en_gestion',
			array(
				'label' => 'En gestión',
				'public' => false,
				'protected' => true,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				'label_count' => self::label_count( 'En gestión' ),
			)
		);

		register_post_status(
			'archived',
			array(
				'label' => 'Archivado',
				'public' => false,
				'exclude_from_search' => true,
				'show_in_admin_all_list' => false,
				'show_in_admin_status_list' => true,
				'label_count' => self::label_count( 'Archivado' ),
			)
		);
	}

	/**
	 * The nooped-plural shape register_post_status() expects for label_count.
	 *
	 * @param string $label Status label.
	 * @return array<string|int,string|null>
	 */
	private static function label_count( $label ) {
		$text = $label . ' <span class="count">(%s)</span>';

		return array(
			0 => $text,
			1 => $text,
			'singular' => $text,
			'plural' => $text,
			'context' => null,
			'domain' => null,
		);
	}

	/**
	 * Name the custom statuses in the admin list title column.
	 *
	 * @param string[] $states Post states.
	 * @param WP_Post  $post   Post.
	 * @return string[]
	 */
	public static function display_post_states( $states, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $states;
		}

		if ( 'en_gestion' === $post->post_status ) {
			$states['en_gestion'] = 'En gestión';
		}

		return $states;
	}

	/**
	 * Remove Quick Edit from the document rows of the admin list.
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    Post.
	 * @return string[]
	 */
	public static function remove_quick_edit( $actions, $post ) {
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}
}
