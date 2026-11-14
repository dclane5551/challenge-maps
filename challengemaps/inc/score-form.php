<?php
/*
 * Score form tweaks.
 *
 * Map Name becomes a dropdown of the ranked maps (filtered by the difficulty
 * picked), so every score points at an exact map and SR - no guessing from
 * typed names. The list is filled in when the form loads, so it always
 * matches the tier tables. Turning the option off puts the text box back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_field_index( $form, $id ) {
	foreach ( $form['fields'] as $i => $f ) {
		if ( (string) $f->id === (string) $id ) {
			return $i;
		}
	}
	return -1;
}

function cmaps_field_to_array( $field ) {
	return is_object( $field ) ? get_object_vars( $field ) : (array) $field;
}

function cmaps_make_field( $arr ) {
	return class_exists( 'GF_Fields' ) ? GF_Fields::create( $arr ) : $arr;
}

// switch the Map Name field between dropdown and text box to match the setting
function cmaps_sync_map_field() {
	if ( ! cmaps_gf() ) {
		return;
	}
	$form = GFAPI::get_form( cmaps_score_form() );
	if ( ! $form ) {
		return;
	}
	$i = cmaps_field_index( $form, cmaps_opt( 'f_map' ) );
	if ( $i < 0 ) {
		return;
	}
	$field = cmaps_field_to_array( $form['fields'][ $i ] );

	if ( cmaps_opt( 'dropdown' ) && 'select' !== $field['type'] ) {
		update_option( 'cmaps_map_field_backup', $field, false );
		$field['type']        = 'select';
		$field['inputType']   = 'select';
		$field['choices']     = array();
		$field['placeholder'] = 'Pick a map';
	} elseif ( ! cmaps_opt( 'dropdown' ) && 'select' === $field['type'] ) {
		$backup = get_option( 'cmaps_map_field_backup' );
		$field  = $backup ? $backup : array_merge( $field, array( 'type' => 'text', 'inputType' => '', 'choices' => '' ) );
	} else {
		return;
	}
	$form['fields'][ $i ] = cmaps_make_field( $field );
	GFAPI::update_form( $form );
}

function cmaps_fill_map_choices( $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() || ! cmaps_opt( 'dropdown' ) ) {
		return $form;
	}
	foreach ( $form['fields'] as $field ) {
		if ( (string) $field->id !== (string) cmaps_opt( 'f_map' ) || 'select' !== $field->type ) {
			continue;
		}
		$maps = cmaps_get_maps();
		usort( $maps, function ( $a, $b ) {
			return strcasecmp( $a['song'], $b['song'] );
		} );
		$choices = array();
		foreach ( $maps as $m ) {
			$choices[] = array(
				'text'  => $m['key'] . ' - ' . cmaps_fmt_sr( $m['sr'] ) . '★',
				'value' => $m['key'],
			);
		}
		$field->choices = $choices;
	}
	return $form;
}
foreach ( array( 'gform_pre_render', 'gform_pre_validation', 'gform_pre_submission_filter', 'gform_admin_pre_render' ) as $hook ) {
	add_filter( $hook, 'cmaps_fill_map_choices' );
}

// map list for the page (used to filter the dropdown by difficulty)
add_action( 'gform_enqueue_scripts', function ( $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() ) {
		return;
	}
	$maps = array();
	foreach ( cmaps_get_maps() as $m ) {
		$maps[] = array( $m['key'], $m['tier'], $m['sr'], $m['mapper'], $m['song'] );
	}
	$names = array();
	foreach ( cmaps_tiers() as $slug => $t ) {
		$names[ strtolower( $t['name'] ) ] = $slug;
	}
	wp_enqueue_script( 'cmaps-score', CMAPS_URL . 'js/score-form.js', array(), cmaps_ver( 'js/score-form.js' ), true );
	wp_localize_script( 'cmaps-score', 'cmapsScore', array(
		'form'  => (int) $form['id'],
		'tier'  => cmaps_opt( 'f_tier' ),
		'map'   => cmaps_opt( 'f_map' ),
		'maps'  => $maps,
		'tiers' => $names,
	) );
} );

/*
 * Checks the field ids in settings still line up with the form, so a rebuilt
 * form doesn't silently break the leaderboard. Returns a list of problems.
 */
function cmaps_check_score_form() {
	if ( ! cmaps_gf() ) {
		return array();
	}
	$form = GFAPI::get_form( cmaps_score_form() );
	if ( ! $form ) {
		return array( 'Score form #' . cmaps_score_form() . ' doesn\'t exist.' );
	}
	$labels = array(
		'f_player' => 'Player Name',
		'f_tier'   => 'Difficulty',
		'f_map'    => 'Map Name',
		'f_video'  => 'Youtube Link',
		'f_acc'    => 'Accuracy',
		'f_style'  => 'Playstyle',
		'f_speed'  => 'Speed',
	);
	$problems = array();
	foreach ( $labels as $key => $label ) {
		if ( cmaps_field_index( $form, cmaps_opt( $key ) ) < 0 ) {
			$problems[] = $label . ' is set to field ' . cmaps_opt( $key ) . ', which isn\'t on the score form anymore.';
		}
	}
	return $problems;
}

/*
 * The score form had an HTML field holding nothing but a <style> tag for the
 * terms box. That CSS lives in the child theme now, so drop the field (it was
 * also adding an empty gap above the Submit button). Only runs if the theme
 * really has the styles.
 */
function cmaps_remove_style_only_field() {
	if ( ! cmaps_gf() ) {
		return;
	}
	$css = get_stylesheet_directory() . '/style.css';
	if ( ! is_readable( $css ) || false === strpos( file_get_contents( $css ), 'gptos_terms_container' ) ) {
		return;
	}
	$form = GFAPI::get_form( cmaps_score_form() );
	if ( ! $form ) {
		return;
	}
	$changed = false;
	foreach ( $form['fields'] as $i => $field ) {
		$content = trim( (string) ( isset( $field->content ) ? $field->content : '' ) );
		$type    = isset( $field->type ) ? $field->type : '';
		if ( 'html' === $type && preg_match( '#^<style[^>]*>.*</style>$#s', $content ) ) {
			unset( $form['fields'][ $i ] );
			$changed = true;
		}
	}
	if ( $changed ) {
		$form['fields'] = array_values( $form['fields'] );
		GFAPI::update_form( $form );
	}
}
