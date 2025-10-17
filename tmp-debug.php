<?php
require '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/resolate/resolate.php';

register_post_type( 'resolate_document', array( 'public' => false ) );
register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );

$term = wp_insert_term( 'Tipo Merge', 'resolate_doc_type' );
$term_id = intval( $term['term_id'] );
$schema  = array(
	array(
		'slug'        => 'annexes',
		'label'       => 'Anexos',
		'type'        => 'array',
		'placeholder' => 'annexes',
		'data_type'   => 'array',
		'item_schema' => array(
			'number'  => array(
				'label'     => 'Número',
				'type'      => 'single',
				'data_type' => 'text',
			),
			'content' => array(
				'label'     => 'Contenido',
				'type'      => 'rich',
				'data_type' => 'text',
			),
		),
	),
	array(
		'slug'        => 'resolution_title',
		'label'       => 'Título',
		'type'        => 'textarea',
		'placeholder' => 'resolution_title',
		'data_type'   => 'text',
	),
	array(
		'slug'        => 'resolution_body',
		'label'       => 'Cuerpo',
		'type'        => 'rich',
		'placeholder' => 'resolution_body',
		'data_type'   => 'text',
	),
);
update_term_meta( $term_id, 'schema', $schema );
update_term_meta( $term_id, 'resolate_type_fields', $schema );

$post_id = wp_insert_post(
	array(
		'post_type'   => 'resolate_document',
		'post_title'  => 'Documento Merge',
		'post_status' => 'draft',
	)
);
wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

$doc = new Resolate_Documents();
$_POST['resolate_doc_type']               = (string) $term_id;
$_POST['tpl_fields']                      = wp_slash(
	array(
		'annexes' => array(
			array(
				'number'  => 'I',
				'content' => '<p>Detalle I</p>',
			),
			array(
				'number'  => 'II',
				'content' => '<p>Detalle II</p>',
			),
		),
	)
);
$_POST['resolate_field_resolution_title'] = '  Título base  ';
$_POST['resolate_field_resolution_body']  = '<p><strong>Detalle</strong> con formato.</p>';

$data    = array( 'post_type' => 'resolate_document' );
$postarr = array( 'ID' => $post_id );
$result  = $doc->filter_post_data_compose_content( $data, $postarr );

$_POST = array();
wp_update_post(
	array(
		'ID'           => $post_id,
		'post_content' => $result['post_content'],
	)
);

$ref     = new ReflectionClass( Resolate_Document_Generator::class );
$method  = $ref->getMethod( 'build_merge_fields' );
$method->setAccessible( true );
$fields  = $method->invoke( null, $post_id );

var_export( $fields );
