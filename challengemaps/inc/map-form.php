<?php
/*
 * Submit a Map - a normal Gravity Form, made once and then editable in
 * Forms like the score form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_map_rules() {
	$rules = array(
		'You must be the mapper, or have permission from the mapper to submit the map.',
		'Maps must work on the latest version of the game.',
		'Maps should be 10★ or higher. Final star ratings are decided when the map gets ranked.',
		'Check the sheet before submitting, maps that are already on it will not be added again.',
		'No stolen or auto-generated maps.',
		'A video of the map being played is recommended, it makes reviewing a lot faster.',
	);
	$html = '<p>By submitting a map to Challenge Maps, you agree to the following.</p>';
	foreach ( $rules as $i => $r ) {
		$html .= '<p><strong>' . ( $i + 1 ) . '. ' . $r . '</strong></p>';
	}
	return $html;
}

function cmaps_patterns() {
	return array( 'Jumps', 'Streams', 'Slides', 'Stacks', 'Spins', 'Tech', 'Vibros', 'Bursts', 'Stamina', 'Reading', 'Timing', 'Quantum', 'Offgrid', 'Walls', 'Consistency', 'Unconventional' );
}

function cmaps_map_form_fields() {
	$tiers = array();
	foreach ( cmaps_tiers() as $t ) {
		$tiers[] = array( 'text' => $t['name'], 'value' => $t['name'] );
	}
	$pat    = array();
	$inputs = array();
	foreach ( cmaps_patterns() as $i => $p ) {
		$pat[]    = array( 'text' => $p, 'value' => $p );
		$inputs[] = array( 'id' => '8.' . ( $i + 1 ), 'label' => $p );
	}

	return array(
		array( 'id' => 1, 'type' => 'text', 'label' => 'Mapper Name', 'isRequired' => true ),
		array( 'id' => 2, 'type' => 'text', 'label' => 'Discord @', 'isRequired' => true ),
		array( 'id' => 3, 'type' => 'text', 'label' => 'Song (Artist - Title)', 'isRequired' => true ),
		array( 'id' => 4, 'type' => 'select', 'label' => 'Difficulty Category', 'isRequired' => true, 'choices' => $tiers ),
		array( 'id' => 5, 'type' => 'number', 'label' => 'Star Rating (your guess)', 'numberFormat' => 'decimal_dot' ),
		array( 'id' => 6, 'type' => 'text', 'label' => 'BPM', 'isRequired' => true ),
		array( 'id' => 7, 'type' => 'text', 'label' => 'Length', 'isRequired' => true, 'placeholder' => '0:00' ),
		array( 'id' => 8, 'type' => 'checkbox', 'label' => 'Patterns', 'isRequired' => true, 'choices' => $pat, 'inputs' => $inputs ),
		array( 'id' => 9, 'type' => 'fileupload', 'label' => 'Map File (.sspm)', 'allowedExtensions' => 'sspm', 'maxFileSize' => 50 ),
		array( 'id' => 10, 'type' => 'website', 'label' => 'Download Link', 'description' => 'Only needed if you didn\'t upload the file.' ),
		array( 'id' => 11, 'type' => 'website', 'label' => 'Youtube Link' ),
		array( 'id' => 12, 'type' => 'textarea', 'label' => 'Notes', 'size' => 'small' ),
		array( 'id' => 13, 'type' => 'html', 'label' => 'Rules', 'content' => cmaps_map_rules() ),
		array(
			'id'         => 14,
			'type'       => 'checkbox',
			'label'      => 'Rule Agreement & Acknowledgement',
			'isRequired' => true,
			'choices'    => array( array( 'text' => 'I agree to the rules', 'value' => 'I agree' ) ),
			'inputs'     => array( array( 'id' => '14.1', 'label' => 'I agree to the rules' ) ),
		),
	);
}

function cmaps_map_form() {
	if ( ! cmaps_gf() ) {
		return new WP_Error( 'cmaps', 'Gravity Forms isn\'t active' );
	}

	$id = (int) cmaps_opt( 'map_form' );
	if ( $id ) {
		$form = GFAPI::get_form( $id );
		if ( $form && empty( $form['is_trash'] ) ) {
			cmaps_update_old_map_form( $form );
			return $id;
		}
	}

	$id = GFAPI::add_form( array(
		'title'          => 'Submit a Map',
		'labelPlacement' => 'top_label',
		'button'         => array( 'type' => 'text', 'text' => 'Submit' ),
		'fields'         => cmaps_map_form_fields(),
		'confirmations'  => array(
			'c1' => array(
				'id'        => 'c1',
				'name'      => 'Default Confirmation',
				'isDefault' => true,
				'type'      => 'message',
				'message'   => 'Thanks! Your map has been submitted for review.',
			),
		),
		'notifications'  => array(
			'n1' => array(
				'id'       => 'n1',
				'name'     => 'Admin Notification',
				'isActive' => true,
				'event'    => 'form_submission',
				'to'       => '{admin_email}',
				'toType'   => 'email',
				'subject'  => 'New map submission',
				'message'  => '{all_fields}',
			),
		),
		'is_active'      => true,
	) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	cmaps_set( array( 'map_form' => (int) $id ) );
	return (int) $id;
}

// the first build of the plugin made this form with different fields/wording;
// if it still looks like that one, swap the fields for the current set (same ids,
// so existing entries keep lining up)
function cmaps_update_old_map_form( $form ) {
	$old = false;
	foreach ( $form['fields'] as $field ) {
		$desc  = isset( $field->description ) ? (string) $field->description : '';
		$html  = isset( $field->content ) ? (string) $field->content : '';
		$label = isset( $field->label ) ? (string) $field->label : '';
		if ( false !== strpos( $desc, 'reach you about the map' ) || false !== strpos( $html, 'cm-rules' ) || 'Suggested Difficulty' === $label ) {
			$old = true;
		}
	}
	if ( ! $old ) {
		return;
	}
	$form['fields'] = array_map( 'cmaps_make_field', cmaps_map_form_fields() );
	$form['button'] = array( 'type' => 'text', 'text' => 'Submit' );
	foreach ( (array) $form['confirmations'] as $k => $c ) {
		if ( isset( $c['message'] ) && false !== strpos( $c['message'], 'review queue' ) ) {
			$form['confirmations'][ $k ]['message'] = 'Thanks! Your map has been submitted for review.';
		}
	}
	GFAPI::update_form( $form );
}

// need either the file or a link
add_filter( 'gform_validation', function ( $result ) {
	$form = $result['form'];
	if ( (int) $form['id'] !== (int) cmaps_opt( 'map_form' ) ) {
		return $result;
	}
	$link = trim( (string) rgpost( 'input_10' ) );
	$file = ! empty( $_FILES['input_9']['name'] ) || ! empty( GFFormsModel::$uploaded_files[ $form['id'] ]['input_9'] );
	if ( '' === $link && ! $file ) {
		foreach ( $form['fields'] as $field ) {
			if ( 10 === (int) $field->id ) {
				$field->failed_validation  = true;
				$field->validation_message = 'Upload the .sspm or add a download link.';
			}
		}
		$result['is_valid'] = false;
		$result['form']     = $form;
	}
	return $result;
} );

add_shortcode( 'cm_submit_map', function () {
	$id = (int) cmaps_opt( 'map_form' );
	if ( ! $id || ! cmaps_gf() ) {
		return '';
	}
	return '<p class="cm-form-note">Liveplay or showcase video is recommended. Maps are play-tested and rated by hand before they are added to the sheet.</p>'
		. do_shortcode( '[gravityform id="' . $id . '" title="false" description="false" ajax="true"]' );
} );
