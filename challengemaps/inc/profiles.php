<?php
/*
 * Map pages (/map/<song>/) and player pages (/player/<name>/).
 *
 * Both are normal WordPress pages ("Map" and "Player", made by setup) with a
 * shortcode on them, so they get the site header and footer. The part after
 * the slash picks which map or player to show.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_route_base( $which ) {
	$id = (int) get_option( 'map' === $which ? 'cmaps_mappage_page' : 'cmaps_player_page' );
	$p  = $id ? get_post( $id ) : null;
	return $p ? $p->post_name : $which;
}

add_action( 'init', function () {
	add_rewrite_rule( '^' . cmaps_route_base( 'map' ) . '/([^/]+)/?$', 'index.php?pagename=' . cmaps_route_base( 'map' ) . '&cm_map=$matches[1]', 'top' );
	add_rewrite_rule( '^' . cmaps_route_base( 'player' ) . '/([^/]+)/?$', 'index.php?pagename=' . cmaps_route_base( 'player' ) . '&cm_player=$matches[1]', 'top' );
}, 20 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'cm_map';
	$vars[] = 'cm_player';
	return $vars;
} );

function cmaps_map_slug( $key ) {
	return sanitize_title( $key );
}

function cmaps_map_url( $key ) {
	return $key ? home_url( '/' . cmaps_route_base( 'map' ) . '/' . cmaps_map_slug( $key ) . '/' ) : '';
}

function cmaps_map_by_slug( $slug ) {
	$slug = sanitize_title( $slug );
	foreach ( cmaps_get_maps() as $m ) {
		if ( cmaps_map_slug( $m['key'] ) === $slug ) {
			return $m;
		}
	}
	return null;
}

function cmaps_player_url( $user_id ) {
	$u = get_userdata( $user_id );
	return $u && get_user_meta( $user_id, 'cm_discord_id', true ) ? home_url( '/' . cmaps_route_base( 'player' ) . '/' . $u->user_nicename . '/' ) : '';
}

// only Discord accounts have a public page, so staff usernames can't be looked up here
function cmaps_player_by_slug( $slug ) {
	$u = get_user_by( 'slug', sanitize_title( $slug ) );
	return $u && get_user_meta( $u->ID, 'cm_discord_id', true ) ? $u : null;
}

// tier tables link each song to its page (filter is in the child theme)
add_filter( 'cmaps_map_url', function ( $url, $row ) {
	return cmaps_map_url( cmaps_map_key( array( 'song' => $row['song'] ?? '', 'mapper' => $row['mapper'] ?? '' ) ) );
}, 10, 2 );

function cmaps_current_map() {
	static $map = false;
	if ( false === $map ) {
		$slug = get_query_var( 'cm_map' );
		$map  = $slug ? cmaps_map_by_slug( $slug ) : null;
	}
	return $map;
}

function cmaps_current_profile() {
	static $user = false;
	if ( false === $user ) {
		$slug = get_query_var( 'cm_player' );
		$user = $slug ? cmaps_player_by_slug( $slug ) : null;
	}
	return $user;
}

function cmaps_on_page( $option ) {
	$id = (int) get_option( $option );
	return $id && is_page( $id );
}

add_action( 'template_redirect', function () {
	$map    = cmaps_on_page( 'cmaps_mappage_page' );
	$player = cmaps_on_page( 'cmaps_player_page' );
	if ( ! $map && ! $player && ! cmaps_on_page( 'cmaps_rank_page' ) ) {
		return;
	}
	cmaps_no_cache(); // scores change these pages, and the data is already cached

	if ( $map && ! cmaps_current_map() ) {
		if ( ! get_query_var( 'cm_map' ) ) {
			$diff = cmaps_diff_page();
			wp_safe_redirect( $diff ? get_permalink( $diff ) : home_url( '/' ) );
			exit;
		}
		status_header( 404 );
	}
	if ( $player && ! cmaps_current_profile() ) {
		if ( ! get_query_var( 'cm_player' ) ) {
			$me = is_user_logged_in() ? cmaps_player_url( get_current_user_id() ) : '';
			wp_safe_redirect( $me ? $me : get_permalink( (int) get_option( 'cmaps_rank_page' ) ) );
			exit;
		}
		status_header( 404 );
	}
} );

function cmaps_page_heading() {
	if ( cmaps_on_page( 'cmaps_mappage_page' ) ) {
		$m = cmaps_current_map();
		return $m ? $m['song'] : 'Map not found';
	}
	if ( cmaps_on_page( 'cmaps_player_page' ) ) {
		$u = cmaps_current_profile();
		return $u ? $u->display_name : 'Player not found';
	}
	return null;
}

add_filter( 'the_title', function ( $title, $id = 0 ) {
	if ( ! is_admin() && in_the_loop() && (int) $id === get_queried_object_id() ) {
		$h = cmaps_page_heading();
		return null !== $h ? esc_html( $h ) : $title;
	}
	return $title;
}, 10, 2 );

// Divi builds its own <title>, so this one wins over it
add_filter( 'pre_get_document_title', function ( $title ) {
	$h = cmaps_page_heading();
	return null !== $h ? $h . ' | ' . get_bloginfo( 'name' ) : $title;
}, 99 );

add_filter( 'document_title_parts', function ( $parts ) {
	$h = cmaps_page_heading();
	if ( null !== $h ) {
		$parts['title'] = $h;
	}
	return $parts;
} );

add_filter( 'body_class', function ( $classes ) {
	foreach ( array( 'cmaps_mappage_page', 'cmaps_player_page', 'cmaps_rank_page' ) as $o ) {
		if ( cmaps_on_page( $o ) ) {
			$classes   = array_diff( $classes, array( 'et_right_sidebar', 'et_left_sidebar' ) );
			$classes[] = 'et_full_width_page';
			$classes[] = 'et_no_sidebar';
			$classes[] = 'cm-full';
		}
	}
	return $classes;
}, 20 );


/* --- bits shared by both pages --- */

function cmaps_pass_rows_html( $rows, $cols ) {
	$html = '';
	foreach ( $rows as $i => $r ) {
		$html .= '<tr><td class="cm-lb-rank' . ( $i < 3 ? ' r' . ( $i + 1 ) : '' ) . '">' . ( $i + 1 ) . '</td>';
		foreach ( $cols as $c ) {
			switch ( $c ) {
				case 'player':
					$html .= '<td class="cm-lb-player">' . cmaps_player_cell( $r['player'], $r['user'], $r['profile'] ) . '</td>';
					break;
				case 'map':
					$html .= '<td class="cm-lb-map">' . ( $r['url'] ? '<a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['map'] ) . '</a>' : esc_html( $r['map'] ) )
						. ( $r['tier'] ? '<small>' . esc_html( cmaps_tier_name( $r['tier'] ) ) . '</small>' : '' ) . '</td>';
					break;
				case 'sr':
					$html .= '<td class="cm-lb-sr center">' . ( null !== $r['sr'] ? '<span class="cm-sr" style="background:' . esc_attr( $r['color'] ) . '">' . esc_html( cmaps_fmt_sr( $r['sr'] ) ) . '</span>' : '<span class="cm-chip">?</span>' ) . '</td>';
					break;
				case 'style':
					$html .= '<td class="cm-lb-style">' . ( $r['style'] ? '<span class="cm-chip">' . esc_html( $r['style'] ) . '</span>' : '' ) . '</td>';
					break;
				case 'acc':
					$html .= '<td class="cm-lb-acc num">' . esc_html( null !== $r['acc'] ? cmaps_fmt_acc( $r['acc'] ) . '%' : $r['acc_raw'] ) . '</td>';
					break;
				case 'speed':
					$html .= '<td class="cm-lb-speed">' . ( $r['speed'] ? '<span class="cm-chip">' . esc_html( $r['speed'] ) . '</span>' : '' ) . '</td>';
					break;
				case 'points':
					$html .= '<td class="num cm-points">' . ( $r['points'] ? esc_html( cmaps_fmt_points( $r['points'] ) ) : '' ) . '</td>';
					break;
				case 'video':
					$html .= '<td class="cm-lb-video">' . ( $r['video'] ? '<a href="' . esc_url( $r['video'] ) . '" target="_blank" rel="noopener">Watch</a>' : '' ) . '</td>';
					break;
				case 'date':
					$html .= '<td class="cm-lb-date"><time datetime="' . esc_attr( gmdate( 'c', $r['time'] ) ) . '">' . esc_html( human_time_diff( $r['time'] ) ) . ' ago</time></td>';
					break;
			}
		}
		$html .= '</tr>';
	}
	return $html;
}

function cmaps_pass_table( $rows, $cols ) {
	$heads = array(
		'player' => '<th>Player</th>',
		'map'    => '<th>Map</th>',
		'sr'     => '<th class="center">SR</th>',
		'style'  => '<th>Playstyle</th>',
		'acc'    => '<th class="num">Accuracy</th>',
		'speed'  => '<th>Speed</th>',
		'points' => '<th class="num">Points</th>',
		'video'  => '<th>Video</th>',
		'date'   => '<th>Date</th>',
	);
	$th = '<th>#</th>';
	foreach ( $cols as $c ) {
		$th .= $heads[ $c ];
	}
	return '<table class="cm-lb-table"><thead><tr>' . $th . '</tr></thead><tbody>' . cmaps_pass_rows_html( $rows, $cols ) . '</tbody></table>';
}

// best first: speed mod, then accuracy, then whoever did it first
function cmaps_sort_passes( $rows ) {
	usort( $rows, function ( $a, $b ) {
		return cmaps_pass_beats( $a, $b ) ? -1 : ( cmaps_pass_beats( $b, $a ) ? 1 : 0 );
	} );
	return $rows;
}


/* --- [cm_map] --- */

add_shortcode( 'cm_map', function () {
	$m = cmaps_current_map();
	if ( ! $m ) {
		return '<p class="cm-lb-empty">That map isn\'t on the sheet. <a href="' . esc_url( ( $d = cmaps_diff_page() ) ? get_permalink( $d ) : home_url( '/' ) ) . '">Browse the difficulties</a></p>';
	}

	$passes = array_values( array_filter( cmaps_lb_rows(), function ( $r ) use ( $m ) {
		return $r['key'] === $m['key'];
	} ) );
	$passes = cmaps_sort_passes( $passes );

	$tier = cmaps_tiers()[ $m['tier'] ] ?? null;
	$out  = '<div class="cm-map">';
	$out .= '<div class="cm-map-meta">'
		. '<span class="cm-sr cm-sr-lg" style="background:' . esc_attr( cmaps_map_color( $m ) ) . '">' . esc_html( cmaps_fmt_sr( $m['sr'] ) ) . '★</span>'
		. ( $tier && ! empty( $tier['page'] ) ? '<a class="cm-chip" href="' . esc_url( get_permalink( $tier['page'] ) ) . '">' . esc_html( $tier['name'] ) . '</a>' : '' )
		. ( $m['bpm'] ? '<span class="cm-chip">' . esc_html( $m['bpm'] ) . ' BPM</span>' : '' )
		. ( $m['mapper'] ? '<span class="cm-map-by">mapped by <strong>' . esc_html( $m['mapper'] ) . '</strong></span>' : '' )
		. ( $m['download'] ? '<a class="cm-btn" href="' . esc_url( $m['download'] ) . '" target="_blank" rel="noopener noreferrer">Download</a>' : '' )
		. '</div>';

	$out .= '<div class="cm-podiums">';
	foreach ( array( 'camlock', 'spin' ) as $style ) {
		$seen = array();
		$top  = array();
		foreach ( $passes as $r ) {
			if ( cmaps_style_key( $r['style'] ) !== $style || isset( $seen[ cmaps_player_key( $r ) ] ) ) {
				continue;
			}
			$seen[ cmaps_player_key( $r ) ] = true;
			$top[]                          = $r;
			if ( 3 === count( $top ) ) {
				break;
			}
		}
		$out .= '<section class="cm-podium"><h2>' . esc_html( cmaps_style_label( $style ) ) . '</h2>';
		if ( ! $top ) {
			$out .= '<p class="cm-podium-none">No passes yet. <a href="' . esc_url( cmaps_score_page_url() ) . '">Be the first</a></p>';
		} else {
			$out .= '<ol>';
			foreach ( $top as $i => $r ) {
				$out .= '<li class="r' . ( $i + 1 ) . '">'
					. '<span class="cm-podium-place">' . ( $i + 1 ) . '</span>'
					. '<span class="cm-podium-player">' . cmaps_player_cell( $r['player'], $r['user'], $r['profile'], 28 ) . '</span>'
					. '<span class="cm-podium-score">' . esc_html( null !== $r['acc'] ? cmaps_fmt_acc( $r['acc'] ) . '%' : $r['acc_raw'] )
					. ( $r['speed'] ? ' <span class="cm-chip">' . esc_html( $r['speed'] ) . '</span>' : '' ) . '</span>'
					. ( $r['video'] ? '<a class="cm-podium-video" href="' . esc_url( $r['video'] ) . '" target="_blank" rel="noopener">Watch</a>' : '' )
					. '</li>';
			}
			$out .= '</ol>';
		}
		$out .= '</section>';
	}
	$out .= '</div>';

	$out .= '<h2 class="cm-h2">All passes <span>' . count( $passes ) . '</span></h2>';
	$out .= $passes
		? cmaps_pass_table( $passes, array( 'player', 'style', 'acc', 'speed', 'points', 'video', 'date' ) )
		: '<p class="cm-lb-empty">Nobody has passed this one yet.</p>';

	return $out . '</div>';
} );


/* --- [cm_player] --- */

add_shortcode( 'cm_player', function () {
	$u = cmaps_current_profile();
	if ( ! $u ) {
		return '<p class="cm-lb-empty">There\'s no player with that name. <a href="' . esc_url( get_permalink( (int) get_option( 'cmaps_rank_page' ) ) ) . '">See the rankings</a></p>';
	}
	$pk     = 'u' . $u->ID;
	$passes = array_values( array_filter( cmaps_lb_rows(), function ( $r ) use ( $u ) {
		return (int) $r['user'] === $u->ID;
	} ) );
	usort( $passes, function ( $a, $b ) {
		return array( $b['points'], $b['sr'] ?? -1, $a['time'] ) <=> array( $a['points'], $a['sr'] ?? -1, $b['time'] );
	} );

	$out = '<div class="cm-profile">';
	$out .= '<div class="cm-profile-head">'
		. '<img class="cm-avatar" src="' . esc_url( cmaps_avatar_url( $u->ID, 128 ) ) . '" alt="" width="72" height="72">'
		. '<div><span class="cm-profile-handle">@' . esc_html( get_user_meta( $u->ID, 'cm_discord_username', true ) ) . '</span>'
		. '<span class="cm-profile-since">Joined ' . esc_html( date_i18n( 'F Y', strtotime( $u->user_registered . ' UTC' ) ) ) . '</span></div>';
	if ( get_current_user_id() === $u->ID ) {
		$out .= '<a class="cm-btn cm-btn-quiet" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sign out</a>';
	}
	$out .= '</div>';

	$out .= '<div class="cm-profile-stats">';
	foreach ( array( 'camlock', 'spin' ) as $style ) {
		$rank = cmaps_player_rank( $pk, $style );
		$out .= '<div class="cm-stat"><span class="cm-stat-label">' . esc_html( cmaps_style_label( $style ) ) . '</span>'
			. ( $rank
				? '<span class="cm-stat-rank">#' . (int) $rank['rank'] . '</span><span class="cm-stat-sub">' . esc_html( cmaps_fmt_points( $rank['points'] ) ) . ' points · ' . (int) $rank['passes'] . ' maps</span>'
				: '<span class="cm-stat-rank">-</span><span class="cm-stat-sub">No passes yet</span>' )
			. '</div>';
	}
	$out .= '</div>';

	$out .= '<h2 class="cm-h2">Passes <span>' . count( $passes ) . '</span></h2>';
	$out .= $passes
		? cmaps_pass_table( $passes, array( 'map', 'sr', 'style', 'acc', 'speed', 'points', 'video', 'date' ) )
		: '<p class="cm-lb-empty">No approved passes yet. <a href="' . esc_url( cmaps_score_page_url() ) . '">Submit a score</a></p>';

	return $out . '</div>';
} );
