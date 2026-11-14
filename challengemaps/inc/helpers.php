<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_defaults() {
	return array(
		// field ids on the Submit a Score form
		'score_form' => 1,
		'f_player'   => '1',
		'f_discord'  => '3',
		'f_tier'     => '4',
		'f_map'      => '5',
		'f_video'    => '14',
		'f_acc'      => '13',
		'f_style'    => '7',
		'f_speed'    => '8',

		'map_form'   => 0,
		'approval'   => 1,
		'ticker'     => 1,
		'logo'       => 1,
		'fonts'      => 1,
		'emoji'      => 1,
		'migrate'    => 0,
		'dropdown'   => 1, // map name on the score form becomes a list of ranked maps

		'diff_slug'  => 'difficulties',
		'score_slug' => 'submit-a-score',

		// rankings (see inc/rankings.php)
		'rank_curve'     => 2.2,
		'rank_decay'     => 0.95,
		'rank_acc_floor' => 70,
		'rank_speed'     => 0.1,
	);
}

// asset version = file's modified time, so browsers pick up edits right away
function cmaps_ver( $file ) {
	$path = CMAPS_DIR . $file;
	return file_exists( $path ) ? (string) filemtime( $path ) : CMAPS_VER;
}

function cmaps_settings( $refresh = false ) {
	static $s = null;
	if ( null === $s || $refresh ) {
		$saved = get_option( 'cmaps_settings', array() );
		$s     = array_merge( cmaps_defaults(), is_array( $saved ) ? $saved : array() );
	}
	return $s;
}

function cmaps_opt( $key ) {
	$s = cmaps_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

function cmaps_set( array $changes ) {
	$saved = get_option( 'cmaps_settings', array() );
	update_option( 'cmaps_settings', array_merge( is_array( $saved ) ? $saved : array(), $changes ) );
	cmaps_settings( true );
}

function cmaps_gf() {
	return class_exists( 'GFAPI' );
}

// carry over ids/pages from the old challengemaps-toolkit build so nothing gets duplicated
function cmaps_migrate_old_options() {
	$old = get_option( 'cmt_settings' );
	if ( is_array( $old ) && ! get_option( 'cmaps_settings' ) ) {
		$map = array(
			'score_form_id'    => 'score_form',
			'f_player'         => 'f_player',
			'f_discord'        => 'f_discord',
			'f_tier'           => 'f_tier',
			'f_map'            => 'f_map',
			'f_video'          => 'f_video',
			'f_accuracy'       => 'f_acc',
			'f_style'          => 'f_style',
			'f_speed'          => 'f_speed',
			'require_approval' => 'approval',
			'map_form_id'      => 'map_form',
			'perf_logo'        => 'logo',
			'perf_fonts'       => 'fonts',
			'perf_emoji'       => 'emoji',
			'perf_migrate'     => 'migrate',
		);
		$new = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $old[ $from ] ) ) {
				$new[ $to ] = $old[ $from ];
			}
		}
		update_option( 'cmaps_settings', $new );
		cmaps_settings( true );
	}
	foreach ( array( 'cmt_leaderboard_page' => 'cmaps_lb_page', 'cmt_submit_map_page' => 'cmaps_map_page', 'cmt_logo' => 'cmaps_logo', 'cmt_maps_index' => 'cmaps_maps' ) as $from => $to ) {
		$v = get_option( $from );
		if ( $v && ! get_option( $to ) ) {
			update_option( $to, $v, false );
		}
	}
}

/*
 * SR colors - same stops the tier tables use (10 green -> 20 gold -> 30 orange
 * -> 35 rust -> 40 magenta -> 50 purple -> 60 blue). Anything past 60 stays blue.
 */
function cmaps_stops() {
	if ( function_exists( 'cmaps_sr_stops' ) ) {
		$out = array();
		foreach ( cmaps_sr_stops() as $sr => $rgb ) {
			$out[] = array( $sr, $rgb );
		}
		return $out;
	}
	return array(
		array( 10, array( 142, 166, 141 ) ),
		array( 15, array( 125, 187, 116 ) ),
		array( 20, array( 196, 171, 89 ) ),
		array( 25, array( 224, 139, 60 ) ),
		array( 30, array( 195, 82, 26 ) ),
		array( 35, array( 125, 34, 15 ) ),
		array( 40, array( 141, 52, 114 ) ),
		array( 50, array( 106, 69, 245 ) ),
		array( 60, array( 61, 117, 223 ) ),
	);
}

function cmaps_sr_color( $sr ) {
	// the child theme's scale is the real one; this copy is only a fallback
	if ( function_exists( 'cmaps_get_sr_color' ) ) {
		return cmaps_get_sr_color( $sr );
	}
	$sr    = (float) $sr;
	$stops = cmaps_stops();
	$c     = $stops[0][1];

	if ( $sr >= $stops[ count( $stops ) - 1 ][0] ) {
		$c = $stops[ count( $stops ) - 1 ][1];
	} elseif ( $sr > $stops[0][0] ) {
		for ( $i = 0; $i < count( $stops ) - 1; $i++ ) {
			list( $a, $ca ) = $stops[ $i ];
			list( $b, $cb ) = $stops[ $i + 1 ];
			if ( $sr <= $b ) {
				$t = ( $sr - $a ) / ( $b - $a );
				for ( $k = 0; $k < 3; $k++ ) {
					$c[ $k ] = $ca[ $k ] + ( $cb[ $k ] - $ca[ $k ] ) * $t;
				}
				break;
			}
		}
	}
	return sprintf( 'rgb(%d, %d, %d)', round( $c[0] ), round( $c[1] ), round( $c[2] ) );
}

function cmaps_fmt_sr( $sr ) {
	$sr = round( (float) $sr, 2 );
	return ( floor( $sr ) == $sr ) ? (string) (int) $sr : (string) $sr;
}

// same colors + copy as the homepage tier cards
function cmaps_known_tiers() {
	return array(
		'beginner'     => array(
			'range' => '10★ – 20★',
			'color' => '#7dbb74',
			'desc'  => 'These are the most accessible charts, designed to build foundational reading',
		),
		'intermediate' => array(
			'range' => '21★ – 30★',
			'color' => '#e08b3c',
			'desc'  => 'Intermediate Charts are going to be a good bit more challenging, featuring higher speeds and harder patterns',
		),
		'advanced'     => array(
			'range' => '31★ – 40★',
			'color' => '#dc2626',
			'desc'  => 'Advanced charts are going to require very high levels of speed and reading, you will need to work your way up to these',
		),
		'expert'       => array(
			'range' => '41★+',
			'color' => '#6a45f5',
			'desc'  => 'Expert charts contain the sheets hardest maps, this is the pinnacle of difficulty',
		),
	);
}

function cmaps_diff_page() {
	return get_page_by_path( cmaps_opt( 'diff_slug' ) );
}

function cmaps_score_page_url() {
	$p = get_page_by_path( cmaps_opt( 'score_slug' ) );
	return $p ? get_permalink( $p ) : home_url( '/' . cmaps_opt( 'score_slug' ) . '/' );
}

/*
 * Tiers = the child pages of the Difficulties page, in menu order. Adding a new
 * tier is just adding another child page. Range/color/desc for a new tier come
 * from its maps and the page excerpt (see cmaps_tier_grid).
 */
function cmaps_tiers() {
	static $tiers = null;
	if ( null !== $tiers ) {
		return $tiers;
	}
	$known  = cmaps_known_tiers();
	$parent = cmaps_diff_page();
	$kids   = $parent ? get_pages( array( 'parent' => $parent->ID, 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish' ) ) : array();

	$tiers = array();
	foreach ( $kids as $p ) {
		$k                      = isset( $known[ $p->post_name ] ) ? $known[ $p->post_name ] : array();
		$tiers[ $p->post_name ] = array(
			'name'  => $p->post_title,
			'page'  => $p,
			'range' => isset( $k['range'] ) ? $k['range'] : '',
			'color' => isset( $k['color'] ) ? $k['color'] : '',
			'desc'  => $p->post_excerpt ? $p->post_excerpt : ( isset( $k['desc'] ) ? $k['desc'] : '' ),
		);
	}

	// known tiers in difficulty order, any new ones after them (by menu order)
	$order = array_flip( array_keys( $known ) );
	uksort( $tiers, function ( $a, $b ) use ( $order, $tiers ) {
		$x = isset( $order[ $a ] ) ? $order[ $a ] : 100 + $tiers[ $a ]['page']->menu_order;
		$y = isset( $order[ $b ] ) ? $order[ $b ] : 100 + $tiers[ $b ]['page']->menu_order;
		return $x <=> $y;
	} );

	// no child pages found - fall back to the four known tiers
	if ( ! $tiers ) {
		foreach ( $known as $slug => $k ) {
			$tiers[ $slug ] = $k + array( 'name' => ucfirst( $slug ), 'page' => get_page_by_path( $slug ) );
		}
	}
	return $tiers;
}

function cmaps_tier_slug( $name ) {
	$name = strtolower( trim( (string) $name ) );
	foreach ( cmaps_tiers() as $slug => $t ) {
		if ( $name === $slug || strtolower( $t['name'] ) === $name ) {
			return $slug;
		}
	}
	return '';
}

function cmaps_tier_name( $slug ) {
	$t = cmaps_tiers();
	return isset( $t[ $slug ] ) ? $t[ $slug ]['name'] : ucfirst( $slug );
}
