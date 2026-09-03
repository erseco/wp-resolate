<?php
/**
 * Put a development site back to just its demo content.
 *
 * A day of running the end-to-end suite leaves hundreds of documents behind:
 * every spec creates its own fixtures with a timestamped name, and a failed
 * run never reaches its clean-up. The demo set then sits in a list of noise,
 * which defeats the point of having it. This removes what the tests left and
 * keeps what the demo is made of.
 *
 * It refuses to run anywhere demo content is not allowed in the first place,
 * so a production site cannot lose data to it.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Demo_Reset
 */
class Documentate_Demo_Reset {

	/**
	 * Meta keys that mark a document as part of the demo content.
	 *
	 * @var string[]
	 */
	const DEMO_MARKS = array(
		'_documentate_demo_app',
		'_documentate_demo_key',
		'_documentate_demo_type_id',
	);

	/**
	 * Logins and term names the specs build carry a millisecond timestamp.
	 *
	 * `app1788339265704editor`, `App Scope app1788339265704`, `Scope Doc Type
	 * e2e1788339266575`: ten digits in a row is a signature nothing a person
	 * types has, and nothing the seeders write.
	 *
	 * @var string
	 */
	const TEST_SIGNATURE = '[0-9]{10,}';

	/**
	 * Remove what the test suite left behind and re-seed the demo content.
	 *
	 * @param string|null $environment Environment to evaluate. Only the test
	 *                                 suite passes one, the same way the gate
	 *                                 takes it: wp_get_environment_type()
	 *                                 caches its answer for the whole request,
	 *                                 so a production site cannot be simulated
	 *                                 any other way.
	 * @return array<string,int>|WP_Error What was removed, or the refusal.
	 */
	public static function run( $environment = null ) {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding( $environment ) ) {
			return new WP_Error(
				'entorno_no_permitido',
				'Este sitio no admite contenido de ejemplo, así que tampoco se borra nada.'
			);
		}

		$removed = array(
			'documentos' => self::delete_documents_without_a_demo_mark(),
			'usuarios' => self::delete_test_users(),
			'categorias' => self::delete_test_terms( 'category' ),
			'tipos' => self::delete_test_terms( 'documentate_doc_type' ),
		);

		Documentate_Demo_App::ensure_environment();
		$removed['sembrados'] = count( Documentate_Demo_App::seed() );

		return $removed;
	}

	/**
	 * Delete every document the demo does not claim, with what hangs off it.
	 *
	 * @return int Documents deleted.
	 */
	private static function delete_documents_without_a_demo_mark() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Development-only clean-up; WP_Query is filtered by the access protection when no user is logged in.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s
				AND p.ID NOT IN (
					SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s )
				)",
				'documentate_document',
				self::DEMO_MARKS[0],
				self::DEMO_MARKS[1],
				self::DEMO_MARKS[2]
			)
		);

		foreach ( $ids as $id ) {
			self::delete_document( (int) $id );
		}

		return count( $ids );
	}

	/**
	 * Delete one document, its attachments and its activity.
	 *
	 * Core reparents the attachments of a deleted post instead of removing
	 * them, and the comments of a document are its activity: both would
	 * outlive the document and keep the media library growing.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	private static function delete_document( $post_id ) {
		foreach ( get_children( array( 'post_parent' => $post_id ) ) as $child ) {
			wp_delete_attachment( (int) $child->ID, true );
		}

		$comentarios = get_comments(
			array(
				'post_id' => $post_id,
				'fields' => 'ids',
			)
		);

		foreach ( $comentarios as $comment_id ) {
			wp_delete_comment( (int) $comment_id, true );
		}

		wp_delete_post( $post_id, true );
	}

	/**
	 * Delete the accounts the specs created, and nothing else.
	 *
	 * @return int Users deleted.
	 */
	private static function delete_test_users() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/user.php';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Development-only clean-up.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE user_login REGEXP %s",
				self::TEST_SIGNATURE
			)
		);

		$administrator = self::first_administrator();

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id === $administrator ) {
				continue;
			}

			wp_delete_user( $id, $administrator );
		}

		return count( $ids );
	}

	/**
	 * Delete the terms the specs created, and nothing else.
	 *
	 * @param string $taxonomy Taxonomy to clean.
	 * @return int Terms deleted.
	 */
	private static function delete_test_terms( $taxonomy ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Development-only clean-up.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
				WHERE t.name REGEXP %s",
				$taxonomy,
				self::TEST_SIGNATURE
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_term( (int) $id, $taxonomy );
		}

		return count( $ids );
	}

	/**
	 * The account that inherits whatever a deleted user authored.
	 *
	 * @return int Administrator ID, or 0 when the site has none.
	 */
	private static function first_administrator() {
		$admins = get_users(
			array(
				'role' => 'administrator',
				'number' => 1,
				'orderby' => 'ID',
				'fields' => 'ID',
			)
		);

		return empty( $admins ) ? 0 : (int) $admins[0];
	}
}
