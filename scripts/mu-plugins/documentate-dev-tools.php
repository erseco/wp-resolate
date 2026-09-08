<?php
/**
 * Plugin Name: Documentate Dev Tools
 * Description: Herramientas de desarrollo de Documentate: cambiar de usuario de prueba desde la barra de administración y mostrar las cuentas de prueba en wp-login.php. Solo para wp-env y Playground, nunca se despliega.
 * Version: 1.0.0
 * Author: ATE — Área de Tecnología Educativa
 * License: GPL-2.0-or-later
 *
 * wp-env mounts scripts/mu-plugins as wp-content/mu-plugins (.wp-env.json) and
 * blueprint.json copies this file into the Playground mu-plugins directory.
 * The release ZIP never carries it: /scripts is listed in .distignore.
 *
 * The accounts listed here are the ones Documentate_Demo_Data seeds on
 * activation in non-production environments; keep both lists in step.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Deployment discipline is not a guard: /scripts is out of the release ZIP,
 * but this file does travel in the GitHub source ZIP that blueprint.json
 * installs, and the blueprint copies it into WPMU_PLUGIN_DIR by itself. A
 * site built from those steps must not end up with a login page advertising
 * working credentials, so the environment is checked here as well — the same
 * question Documentate_Demo_Data::should_allow_demo_seeding() asks before
 * creating those accounts in the first place.
 */
if (
	! ( defined( 'WORDPRESS_PLAYGROUND' ) && WORDPRESS_PLAYGROUND )
	&& function_exists( 'wp_get_environment_type' )
	&& 'production' === wp_get_environment_type()
) {
	return;
}

if ( ! function_exists( 'documentate_dev_demo_accounts' ) ) {
	/**
	 * Demo accounts used to try the application with each role.
	 *
	 * @return list<array{login:string,pass:string,label:string}>
	 */
	function documentate_dev_demo_accounts(): array {
		return array(
			array(
				'login' => 'admin',
				'pass'  => 'password',
				'label' => 'Administración (aprueba y publica)',
			),
			array(
				'login' => 'editor1',
				'pass'  => 'password',
				'label' => 'Gestión documental · Subdirección de Administración (revisa y completa)',
			),
			array(
				'login' => 'author1',
				'pass'  => 'password',
				'label' => 'Área · Departamento de Proyectos (crea y envía)',
			),
			array(
				'login' => 'subscriber1',
				'pass'  => 'password',
				'label' => 'Suscriptor · sin acceso a la app',
			),
		);
	}
}

if ( ! function_exists( 'documentate_dev_app_url' ) ) {
	/**
	 * Landing page after switching: the front-end application, or the site root.
	 *
	 * @return string
	 */
	function documentate_dev_app_url(): string {
		if ( class_exists( 'Documentate_App_Shell' ) ) {
			return Documentate_App_Shell::page_url();
		}

		$page = get_page_by_path( 'documentate' );

		return $page instanceof WP_Post ? (string) get_permalink( $page ) : home_url( '/' );
	}
}

if ( ! function_exists( 'documentate_dev_switch_to_user_url' ) ) {
	/**
	 * Build a switch-to-user URL (User Switching plugin) or null if unavailable.
	 *
	 * @param WP_User $user Target user.
	 * @return string|null
	 */
	function documentate_dev_switch_to_user_url( WP_User $user ): ?string {
		// User Switching declares a class, not a function.
		if ( ! class_exists( 'user_switching' ) ) {
			return null;
		}

		$target = documentate_dev_app_url();

		if ( method_exists( 'user_switching', 'maybe_switch_url' ) ) {
			$url = user_switching::maybe_switch_url( $user );
			if ( is_string( $url ) && '' !== $url ) {
				return add_query_arg( 'redirect_to', rawurlencode( $target ), $url );
			}
		}

		// Fallback: same query args the plugin handles on wp-login.php.
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'switch_to_user',
					'user_id'     => $user->ID,
					'nr'          => 1,
					'redirect_to' => rawurlencode( $target ),
				),
				wp_login_url()
			),
			"switch_to_user_{$user->ID}"
		);
	}
}

if ( ! function_exists( 'documentate_dev_admin_bar_node' ) ) {
	/**
	 * Add the "switch to demo user" menu (and "switch back") to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	function documentate_dev_admin_bar_node( $wp_admin_bar ) {
		if ( ! class_exists( 'user_switching' ) ) {
			return;
		}

		if ( function_exists( 'current_user_switched' ) ) {
			$old_user = current_user_switched();
			if ( $old_user instanceof WP_User && method_exists( 'user_switching', 'switch_back_url' ) ) {
				$wp_admin_bar->add_node(
					array(
						'id'    => 'documentate-dev-switch-back',
						'title' => sprintf( 'Volver a %s', $old_user->display_name ),
						'href'  => add_query_arg(
							'redirect_to',
							rawurlencode( documentate_dev_app_url() ),
							user_switching::switch_back_url( $old_user )
						),
					)
				);
			}
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'documentate-dev-switch-user',
				'title' => 'Probar como…',
				'href'  => false,
			)
		);

		$current_login = wp_get_current_user()->user_login;

		foreach ( documentate_dev_demo_accounts() as $account ) {
			$login = $account['login'];
			$node  = array(
				'id'     => 'documentate-dev-switch-' . sanitize_key( $login ),
				'parent' => 'documentate-dev-switch-user',
				'title'  => $account['label'],
				'href'   => false,
			);

			$user = get_user_by( 'login', $login );

			if ( ! $user instanceof WP_User ) {
				$node['title'] .= ' (no creado)';
			} elseif ( $login === $current_login ) {
				$node['title'] = '✓ ' . $node['title'];
			} else {
				$node['href'] = documentate_dev_switch_to_user_url( $user );
			}

			$wp_admin_bar->add_node( $node );
		}
	}
}
add_action( 'admin_bar_menu', 'documentate_dev_admin_bar_node', 110 );

if ( ! function_exists( 'documentate_dev_login_form_accounts' ) ) {
	/**
	 * Print the demo account list inside the login form.
	 *
	 * Hooked on `login_form` (after the password field); the inline script
	 * moves the box below the submit button.
	 *
	 * @return void
	 */
	function documentate_dev_login_form_accounts() {
		echo '<div class="documentate-dev-login-accounts">';
		echo '<p class="documentate-dev-login-accounts__title">Cuentas de prueba</p>';
		echo '<ul class="documentate-dev-login-accounts__list">';

		foreach ( documentate_dev_demo_accounts() as $account ) {
			echo '<li>';
			echo '<button type="button" class="documentate-dev-fill-login" data-login="' . esc_attr( $account['login'] ) . '" data-pass="' . esc_attr( $account['pass'] ) . '">';
			echo esc_html( $account['login'] );
			echo '</button>';
			echo ' / <code>' . esc_html( $account['pass'] ) . '</code>';
			echo '<span class="documentate-dev-login-accounts__role">' . esc_html( $account['label'] ) . '</span>';
			echo '</li>';
		}

		echo '</ul>';
		echo '<p class="documentate-dev-login-accounts__hint">Clic en el usuario para rellenar el formulario.</p>';
		echo '</div>';
	}
}
add_action( 'login_form', 'documentate_dev_login_form_accounts' );

if ( ! function_exists( 'documentate_dev_login_assets' ) ) {
	/**
	 * Styles and click-to-fill script for the demo account list on wp-login.php.
	 *
	 * @return void
	 */
	function documentate_dev_login_assets() {
		global $action;

		if ( ! isset( $action ) || 'login' !== $action ) {
			return;
		}

		$css = <<<'CSS'
#loginform {
	display: flex;
	flex-direction: column;
}
.documentate-dev-login-accounts {
	order: 20;
	margin-block-start: 1.25em;
	padding: 12px 14px;
	border: 1px solid #c3c4c7;
	background: #f6f7f7;
	box-sizing: border-box;
	font-size: 13px;
	line-height: 1.4;
}
.documentate-dev-login-accounts__title {
	margin: 0 0 8px;
	font-weight: 600;
}
.documentate-dev-login-accounts__list {
	margin: 0;
	padding: 0;
	list-style: none;
}
.documentate-dev-login-accounts__list li + li {
	margin-block-start: 8px;
}
.documentate-dev-fill-login {
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
	color: #2271b1;
	cursor: pointer;
	font: inherit;
	font-family: Consolas, Monaco, monospace;
	text-decoration: underline;
}
.documentate-dev-fill-login:focus-visible {
	outline: 2px solid #2271b1;
	outline-offset: 2px;
}
.documentate-dev-login-accounts__role {
	display: block;
	color: #50575e;
}
.documentate-dev-login-accounts__hint {
	margin: 8px 0 0;
	color: #646970;
}
CSS;

		wp_register_style( 'documentate-dev-login', false, array(), '1.0.0' );
		wp_enqueue_style( 'documentate-dev-login' );
		wp_add_inline_style( 'documentate-dev-login', $css );

		$js = <<<'JS'
(function () {
	var box = document.querySelector(".documentate-dev-login-accounts");
	var submit = document.querySelector("#loginform p.submit");
	if (box && submit && submit.parentNode) {
		submit.parentNode.insertBefore(box, submit.nextSibling);
	}
	document.querySelectorAll(".documentate-dev-fill-login").forEach(function (button) {
		button.addEventListener("click", function () {
			var login = document.getElementById("user_login");
			var pass = document.getElementById("user_pass");
			if (login) {
				login.value = button.getAttribute("data-login") || "";
			}
			if (pass) {
				pass.value = button.getAttribute("data-pass") || "";
			}
			if (login) {
				login.focus();
			}
		});
	});
})();
JS;

		wp_register_script( 'documentate-dev-login', false, array(), '1.0.0', true );
		wp_enqueue_script( 'documentate-dev-login' );
		wp_add_inline_script( 'documentate-dev-login', $js );
	}
}
add_action( 'login_enqueue_scripts', 'documentate_dev_login_assets' );
