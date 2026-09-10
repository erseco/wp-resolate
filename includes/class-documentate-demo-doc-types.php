<?php
/**
 * The demo document types, keyed by the fixture file each one is built from.
 *
 * A table of its own, for the same reason as
 * `Documentate_Demo_Field_Values`: it is data, and it is long enough to push
 * `Documentate_Demo_Data` past the class length the ruleset allows.
 *
 * Declaration order is the seeding order. The fixture key of every entry is
 * its slug, so it is derived rather than repeated. "prefijo" precedes the
 * internal name in the lists; "has_management" sends the type through gestión
 * documental.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Document types the seeder creates from the bundled templates.
 */
class Documentate_Demo_Doc_Types {

	/**
	 * Every demo document type, in seeding order.
	 *
	 * @return array<string,array{slug:string,name:string,description:string,color:string,pdf_layout:string,prefix?:string,has_management?:bool}>
	 */
	public static function all() {
		return array(
			'resolucion.odt' => array(
				'slug' => 'resolucion-administrativa',
				'name' => 'Resolución Administrativa',
				'description' => 'Plantilla para resoluciones administrativas con antecedentes, fundamentos de derecho, resuelvo y anexos.',
				'color' => '#37517e',
				'pdf_layout' => 'resolucion',
				'prefix' => 'RES',
			),
			'demo-wp-documentate.odt' => array(
				'slug' => 'documentate-demo-wp-documentate-odt',
				'name' => 'Tipo de documento de prueba avanzado (ODT)',
				'description' => 'Ejemplo creado automáticamente con la plantilla demo-wp-documentate.odt incluida.',
				'color' => '#6c5ce7',
				'pdf_layout' => 'generic',
			),
			'autorizacionviaje.odt' => array(
				'slug' => 'autorizacion-viaje',
				'name' => 'Autorización de viaje',
				'description' => 'Plantilla para autorizaciones de viaje con listado de asistentes.',
				'color' => '#e67e22',
				'pdf_layout' => 'autorizacionviaje',
				'prefix' => 'AV',
			),
			'gastossuplidos.odt' => array(
				'slug' => 'gastos-suplidos',
				'name' => 'Solicitud de gastos suplidos',
				'description' => 'Plantilla para solicitud de reembolso de gastos con listado de facturas.',
				'color' => '#27ae60',
				'pdf_layout' => 'gastossuplidos',
				'prefix' => 'GS',
			),
			'propuestagasto.odt' => array(
				'slug' => 'propuesta-gasto',
				'name' => 'Propuesta de gasto (Documento 0)',
				'description' => 'Plantilla para propuestas de gasto con libramientos, servicios, suministros y expertos.',
				'color' => '#9b59b6',
				'pdf_layout' => 'propuestagasto',
				'prefix' => 'PG',
				'has_management' => true,
			),
			'solicitud_desplazamiento_dg.odt' => array(
				'slug' => 'solicitud-desplazamiento-dg',
				'name' => 'Solicitud de desplazamiento (personal de la DG)',
				'description' => 'Solicitud de medios de desplazamiento y alojamiento para el personal de la Dirección General: billetes de ida y vuelta, coche de alquiler y hotel.',
				'color' => '#00838f',
				'pdf_layout' => 'solicitud_desplazamiento_dg',
				'prefix' => 'SD',
			),
			'solicitud_desplazamiento_externo.odt' => array(
				'slug' => 'solicitud-desplazamiento-externo',
				'name' => 'Solicitud de desplazamiento (personal externo)',
				'description' => 'Solicitud de desplazamiento y alojamiento para el personal externo que participa en una acción, con un bloque por persona.',
				'color' => '#0277bd',
				'pdf_layout' => 'solicitud_desplazamiento_externo',
				'prefix' => 'SDE',
			),
			'respuesta_parlamentaria.odt' => array(
				'slug' => 'respuesta-parlamentaria',
				'name' => 'Respuesta a pregunta parlamentaria',
				'description' => 'Respuesta a una pregunta oral en comisión del Parlamento de Canarias, con su número POC y el texto de la pregunta.',
				'color' => '#8e44ad',
				'pdf_layout' => 'respuesta_parlamentaria',
				'prefix' => 'RPP',
			),
			'correo_masivo.odt' => array(
				'slug' => 'correo-masivo',
				'name' => 'Solicitud de envío de correo masivo',
				'description' => 'Solicitud para que salga un correo a los centros: a quién se envía, con qué categoría, antes de qué día y con qué texto.',
				'color' => '#c2185b',
				'pdf_layout' => 'correo_masivo',
				'prefix' => 'CM',
			),
			'memoria_previa_resolucion.odt' => array(
				'slug' => 'memoria-previa-resolucion',
				'name' => 'Memoria previa de resolución',
				'description' => 'Memoria con la que el Servicio propone a la Dirección General la tramitación y aprobación de un proyecto de resolución.',
				'color' => '#00695c',
				'pdf_layout' => 'memoria_previa_resolucion',
				'prefix' => 'MPR',
			),
			'libramiento_ceps.odt' => array(
				'slug' => 'libramiento-ceps',
				'name' => 'Libramiento extraordinario a CEP',
				'description' => 'Propuesta de resolución que asigna dotaciones económicas extraordinarias a los Centros del Profesorado, con un anexo por provincia.',
				'color' => '#5d4037',
				'pdf_layout' => 'libramiento_ceps',
				'prefix' => 'LCEP',
			),
			'libramiento_centros.odt' => array(
				'slug' => 'libramiento-centros',
				'name' => 'Libramiento extraordinario a centros',
				'description' => 'Resolución que asigna dotaciones económicas extraordinarias a los centros educativos, con un anexo por provincia.',
				'color' => '#455a64',
				'pdf_layout' => 'libramiento_centros',
				'prefix' => 'LCEN',
			),
			'convocatoriareunion.odt' => array(
				'slug' => 'convocatoria-reunion',
				'name' => 'Convocatoria de reunión',
				'description' => 'Plantilla para convocatorias de reuniones con lugar, fecha, horario y orden del día.',
				'color' => '#3498db',
				'pdf_layout' => 'convocatoriareunion',
				'prefix' => 'CONV',
			),
			'memoria_pago_cep.odt' => array(
				'slug' => 'memoria-pago',
				'name' => 'Memoria justificativa de pago',
				'description' => 'Plantilla para memorias justificativas de pago con listado de facturas y datos del CEP.',
				'color' => '#d35400',
				'pdf_layout' => 'memoria_pago_cep',
				'prefix' => 'MP',
			),
			'respuesta_escrito.odt' => array(
				'slug' => 'respuesta-escrito',
				'name' => 'Respuesta a escrito',
				'description' => 'Plantilla para respuestas a escritos y solicitudes con destinatario, asunto y texto de respuesta.',
				'color' => '#2c3e50',
				'pdf_layout' => 'respuesta_escrito',
				'prefix' => 'RE',
			),
			'modelo_informe.odt' => array(
				'slug' => 'modelo-informe',
				'name' => 'Modelo de informe',
				'description' => 'Plantilla para informes con asunto, texto del informe y cargo firmante.',
				'color' => '#16a085',
				'pdf_layout' => 'modelo_informe',
				'prefix' => 'INF',
			),
			'haceconstar.odt' => array(
				'slug' => 'hace-constar',
				'name' => 'Hace constar',
				'description' => 'Plantilla de certificado «Hace constar» que acredita la participación de una persona en determinadas actividades.',
				'color' => '#c0392b',
				'pdf_layout' => 'haceconstar',
				'prefix' => 'HC',
			),
		);
	}
}
