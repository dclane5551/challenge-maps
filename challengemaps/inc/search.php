<?php
/*
 * Search button in the nav. Finds maps (song or mapper) and players.
 *
 * Players with an account link to their profile. Names that only appear on
 * older scores link to the leaderboard, filtered to that name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'cm/v1', '/search', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array(
			'q' => array( 'type' => 'string', 'required' => true, 'maxLength' => 60 ),
		),
		'callback'            => function ( $req ) {
			return rest_ensure_response( cmaps_search( $req['q'] ) );
		},
	) );
} );

// every player name the search knows about, cached with the leaderboard
function cmaps_search_players() {
	$list = get_transient( 'cmaps_search_players' );
	if ( is_array( $list ) ) {
		return $list;
	}
	$list = array();
	foreach ( get_users( array( 'meta_key' => 'cm_discord_id', 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
		$list[ 'u' . $u->ID ] = array(
			'name'   => $u->display_name,
			'extra'  => (string) get_user_meta( $u->ID, 'cm_discord_username', true ),
			'user'   => (int) $u->ID,
			'passes' => 0,
		);
	}
	foreach ( cmaps_lb_rows() as $r ) {
		$pk = cmaps_player_key( $r );
		if ( ! isset( $list[ $pk ] ) ) {
			$list[ $pk ] = array( 'name' => $r['player'], 'extra' => '', 'user' => 0, 'passes' => 0 );
		}
		$list[ $pk ]['passes']++;
	}
	set_transient( 'cmaps_search_players', $list, DAY_IN_SECONDS );
	return $list;
}

function cmaps_search( $q ) {
	// plain text match only, nothing typed here reaches the database or gets run
	$q   = cmaps_norm( mb_substr( (string) $q, 0, 60 ) );
	$out = array( 'maps' => array(), 'players' => array() );
	if ( mb_strlen( $q ) < 2 ) {
		return $out;
	}

	$maps = array();
	foreach ( cmaps_get_maps() as $m ) {
		$song = cmaps_norm( $m['song'] );
		$pos  = strpos( $song, $q );
		if ( false === $pos && false === strpos( cmaps_norm( $m['mapper'] ), $q ) ) {
			continue;
		}
		$maps[] = array( false === $pos ? 2 : ( 0 === $pos ? 0 : 1 ), $m );
	}
	usort( $maps, function ( $a, $b ) {
		return array( $a[0], -$a[1]['sr'] ) <=> array( $b[0], -$b[1]['sr'] );
	} );
	foreach ( array_slice( $maps, 0, 6 ) as $hit ) {
		$m              = $hit[1];
		$out['maps'][] = array(
			'name'  => $m['song'],
			'sub'   => trim( $m['mapper'] . ' · ' . cmaps_tier_name( $m['tier'] ), ' ·' ),
			'sr'    => cmaps_fmt_sr( $m['sr'] ),
			'color' => cmaps_map_color( $m ),
			'url'   => cmaps_map_url( $m['key'] ),
		);
	}

	$players = array();
	foreach ( cmaps_search_players() as $p ) {
		$name = cmaps_norm( $p['name'] );
		$pos  = strpos( $name, $q );
		if ( false === $pos && ( '' === $p['extra'] || false === strpos( cmaps_norm( $p['extra'] ), $q ) ) ) {
			continue;
		}
		$players[] = array( false === $pos ? 2 : ( 0 === $pos ? 0 : 1 ), $p );
	}
	usort( $players, function ( $a, $b ) {
		return array( $a[0], -$a[1]['passes'] ) <=> array( $b[0], -$b[1]['passes'] );
	} );
	$lb = (int) get_option( 'cmaps_lb_page' );
	foreach ( array_slice( $players, 0, 6 ) as $hit ) {
		$p                 = $hit[1];
		$out['players'][] = array(
			'name'   => $p['name'],
			'sub'    => ( $p['extra'] ? '@' . $p['extra'] . ' · ' : '' ) . $p['passes'] . ' ' . ( 1 === $p['passes'] ? 'pass' : 'passes' ),
			'avatar' => $p['user'] ? cmaps_avatar_url( $p['user'], 64 ) : '',
			'url'    => $p['user'] ? cmaps_player_url( $p['user'] ) : ( $lb ? add_query_arg( 'q', rawurlencode( $p['name'] ), get_permalink( $lb ) ) : '' ),
		);
	}
	return $out;
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'cmaps-search', CMAPS_URL . 'js/search.js', array(), cmaps_ver( 'js/search.js' ), true );
	wp_localize_script( 'cmaps-search', 'cmapsSearch', array( 'url' => rest_url( 'cm/v1/search' ) ) );
} );
