<?php
/**
 * Values the demo data gives a scalar field, chosen by what its slug says.
 *
 * A table of its own: it is data, and it is long enough to push
 * `Documentate_Demo_Data` past the class length the ruleset allows.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Keyword matchers for demo scalar values.
 */
class Documentate_Demo_Field_Values {

	/**
	 * Every matcher, in the order they are tried.
	 *
	 * Each entry is [ needles[], callable($index): string ].
	 *
	 * @return array<int, array{0: string[], 1: callable}>
	 */
	public static function all() {
		return array(
			array(
				array( 'email' ),
				static function ( $i ) {
					return 'demo' . $i . '@ejemplo.es';
				},
			),
			array(
				array( 'phone', 'tel' ),
				static function ( $i ) {
					return '+3460000000' . $i;
				},
			),
			array(
				array( 'dni' ),
				static function ( $i ) {
					return '1234567' . $i . 'A';
				},
			),
			array(
				array( 'url', 'sitio', 'web' ),
				static function ( $i ) {
					return 'https://ejemplo.es/recurso-' . $i;
				},
			),
			array(
				array( 'nombre', 'name' ),
				static function ( $i ) {
					return 1 === $i ? 'Jane Doe' : 'John Smith';
				},
			),
			array(
				array( 'summary', 'resumen' ),
				static function ( $i ) {
					return sprintf( 'Resumen de demo %d con información breve.', $i );
				},
			),
			array(
				array( 'objeto' ),
				static function () {
					return 'Asunto de la resolución de ejemplo para ilustrar el flujo de trabajo.';
				},
			),
			array(
				array( 'antecedentes' ),
				static function () {
					return 'Hechos de antecedentes escritos con contenido de prueba.';
				},
			),
			array(
				array( 'fundamentos' ),
				static function () {
					return 'Fundamentos legales para pruebas con referencias genéricas.';
				},
			),
			array(
				array( 'resuelv' ),
				static function () {
					return (
						'<p>'
						. 'Primero. Aprobar la acción de demostración.'
						. '</p><p>'
						. 'Segundo. Notificar a los interesados.'
						. '</p>'
					);
				},
			),
			array(
				array( 'observaciones' ),
				static function () {
					return 'Observaciones adicionales para completar la plantilla.';
				},
			),
			array(
				array( 'proveedor' ),
				static function ( $i ) {
					return 1 === $i ? 'Suministros Ejemplo S.L.' : 'Servicios Demo S.A.';
				},
			),
			array(
				array( 'factura' ),
				static function ( $i ) {
					return sprintf( '%03d/2025', 100 + $i );
				},
			),
			array(
				array( 'importe' ),
				static function ( $i ) {
					return 1 === $i ? '1250' : '3475.50';
				},
			),
			array(
				array( 'lugar' ),
				static function () {
					return 'Madrid';
				},
			),
			array(
				array( 'invitante' ),
				static function () {
					return 'Ministerio de Educación';
				},
			),
			array(
				array( 'temas' ),
				static function () {
					return 'Discusión de programas de innovación educativa y coordinación interterritorial.';
				},
			),
			array(
				array( 'pagador' ),
				static function () {
					return 'Consejería de Educación del Gobierno de Canarias';
				},
			),
			array(
				array( 'apellido1' ),
				static function ( $i ) {
					return 1 === $i ? 'García' : 'Rodríguez';
				},
			),
			array(
				array( 'apellido2' ),
				static function ( $i ) {
					return 1 === $i ? 'López' : 'Martínez';
				},
			),
			array(
				array( 'iban' ),
				static function () {
					return 'ES9121000418450200051332';
				},
			),
			array(
				array( 'nombre_completo' ),
				static function ( $i ) {
					return 1 === $i ? 'María García López' : 'Juan Rodríguez Martínez';
				},
			),
			array(
				array( 'keywords', 'palabras' ),
				static function () {
					return 'palabras clave, etiquetas, demo';
				},
			),
		);
	}
}
