<?php
/*
 * Points and player rankings.
 *
 * Every approved pass is worth points:
 *
 *   points = 10 x (SR / 10) ^ curve        harder maps are worth a lot more
 *          x accuracy multiplier           0.5 at or under the floor (70%), up to 1 at 100%
 *          x speed multiplier              +10% per speed step (S+ 1.1, S++ 1.2, S- 0.9)
 *
 * A player's total counts only their best pass on each map, sorted best
 * first, with each one after the top worth a bit less (x 0.95 each step),
 * so a few hard passes beat a pile of easy ones. Cam Lock and Spin are
 * ranked separately. The numbers can be changed in Settings > Challenge Maps.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_rank_opts() {
	return array(
		'curve' => max( 1, min( 4, (float) cmaps_opt( 'rank_curve' ) ) ),
		'decay' => max( 0.5, min( 1, (float) cmaps_opt( 'rank_decay' ) ) ),
		'floor' => max( 0, min( 99, (float) cmaps_opt( 'rank_acc_floor' ) ) ),
		'speed' => max( 0, min( 0.5, (float) cmaps_opt( 'rank_speed' ) ) ),
	);
}

function cmaps_points( $sr, $acc, $speed_n ) {
	if ( null === $sr || $sr <= 0 ) {
		return 0;
	}
	$o    = cmaps_rank_opts();
	$base = 10 * pow( $sr / 10, $o['curve'] );
	$t    = null === $acc ? 0 : max( 0, min( 1, ( $acc - $o['floor'] ) / ( 100 - $o['floor'] ) ) );
	$accm = 0.5 + 0.5 * $t * $t;
	$spdm = max( 0.5, 1 + $o['speed'] * (int) $speed_n );
	return round( $base * $accm * $spdm, 2 );
}

function cmaps_style_key( $style ) {
	return false !== stripos( (string) $style, 'spin' ) ? 'spin' : 'camlock';
}

function cmaps_style_label( $key ) {
	return 'spin' === $key ? 'Spin' : 'Cam Lock';
}

// one id per player: their account if the score is linked, otherwise the name on it
function cmaps_player_key( $r ) {
	return ! empty( $r['user'] ) ? 'u' . (int) $r['user'] : 'n:' . cmaps_norm( $r['player'] );
}

function cmaps_rankings( $style = 'camlock' ) {
	$all = get_transient( 'cmaps_rank' );
	if ( ! is_array( $all ) ) {
		$all = array(
			'camlock' => cmaps_build_rankings( 'camlock' ),
			'spin'    => cmaps_build_rankings( 'spin' ),
		);
		set_transient( 'cmaps_rank', $all, DAY_IN_SECONDS );
	}
	return isset( $all[ $style ] ) ? $all[ $style ] : array();
}

function cmaps_build_rankings( $style ) {
	$players = array();
	foreach ( cmaps_lb_rows() as $r ) {
		if ( empty( $r['key'] ) || null === $r['sr'] || cmaps_style_key( $r['style'] ) !== $style ) {
			continue; // only passes on ranked maps count
		}
		$pk = cmaps_player_key( $r );
		if ( ! isset( $players[ $pk ] ) ) {
			$players[ $pk ] = array(
				'name'    => $r['player'],
				'user'    => (int) $r['user'],
				'profile' => $r['profile'],
				'best'    => array(),
			);
		}
		$cur = isset( $players[ $pk ]['best'][ $r['key'] ] ) ? $players[ $pk ]['best'][ $r['key'] ] : null;
		if ( ! $cur || $r['points'] > $cur['points'] || ( $r['points'] == $cur['points'] && $r['time'] < $cur['time'] ) ) {
			$players[ $pk ]['best'][ $r['key'] ] = $r;
		}
	}

	$decay = cmaps_rank_opts()['decay'];
	$out   = array();
	foreach ( $players as $pk => $p ) {
		$best = array_values( $p['best'] );
		usort( $best, function ( $a, $b ) {
			return $b['points'] <=> $a['points'];
		} );
		$total = 0;
		foreach ( $best as $i => $r ) {
			$total += $r['points'] * pow( $decay, $i );
		}
		$top   = $best[0];
		$out[] = array(
			'pk'      => $pk,
			'name'    => $p['name'],
			'user'    => $p['user'],
			'profile' => $p['profile'],
			'points'  => round( $total, 2 ),
			'passes'  => count( $best ),
			'top'     => array(
				'map'   => $top['map'],
				'url'   => $top['url'],
				'acc'   => $top['acc'],
				'speed' => $top['speed'],
				'sr'    => $top['sr'],
				'color' => $top['color'],
			),
		);
	}
	usort( $out, function ( $a, $b ) {
		return array( $b['points'], $b['passes'] ) <=> array( $a['points'], $a['passes'] );
	} );
	foreach ( $out as $i => $p ) {
		$out[ $i ]['rank'] = $i + 1;
	}
	return $out;
}

function cmaps_player_rank( $pk, $style ) {
	foreach ( cmaps_rankings( $style ) as $p ) {
		if ( $p['pk'] === $pk ) {
			return $p;
		}
	}
	return null;
}

function cmaps_fmt_points( $n ) {
	return number_format_i18n( (float) $n, 2 );
}

function cmaps_player_cell( $name, $user, $profile, $size = 24 ) {
	$img = $user ? '<img class="cm-avatar" src="' . esc_url( cmaps_avatar_url( $user, 64 ) ) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy"> ' : '';
	return $profile ? '<a href="' . esc_url( $profile ) . '">' . $img . esc_html( $name ) . '</a>' : $img . esc_html( $name );
}


/* --- [cm_rankings] --- */

add_shortcode( 'cm_rankings', function ( $atts ) {
	$atts = shortcode_atts( array( 'limit' => 100 ), $atts );
	wp_enqueue_script( 'cmaps-tabs', CMAPS_URL . 'js/tabs.js', array(), cmaps_ver( 'js/tabs.js' ), true );

	$o   = cmaps_rank_opts();
	$out = '<div class="cm-rankings" data-cm-tabs>'
		. '<div class="cm-lb-bar"><div class="cm-lb-tabs" role="tablist">'
		. '<button type="button" class="cm-lb-tab" role="tab" data-tab="camlock" aria-selected="true">Cam Lock</button>'
		. '<button type="button" class="cm-lb-tab" role="tab" data-tab="spin" aria-selected="false">Spin</button>'
		. '</div></div>';

	foreach ( array( 'camlock', 'spin' ) as $style ) {
		$rows = array_slice( cmaps_rankings( $style ), 0, max( 1, (int) $atts['limit'] ) );
		$out .= '<div data-panel="' . $style . '"' . ( 'spin' === $style ? ' hidden' : '' ) . '>';
		if ( ! $rows ) {
			$out .= '<p class="cm-lb-empty">No ' . esc_html( cmaps_style_label( $style ) ) . ' passes yet.</p></div>';
			continue;
		}
		$out .= '<table class="cm-lb-table cm-rank-table"><thead><tr><th>#</th><th>Player</th><th class="num">Points</th><th class="num">Passes</th><th>Best pass</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			$t    = $p['top'];
			$best = ( $t['url'] ? '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['map'] ) . '</a>' : esc_html( $t['map'] ) )
				. ' <span class="cm-sr" style="background:' . esc_attr( $t['color'] ) . '">' . esc_html( cmaps_fmt_sr( $t['sr'] ) ) . '</span>'
				. ( null !== $t['acc'] ? ' <small>' . esc_html( cmaps_fmt_acc( $t['acc'] ) ) . '%' . ( $t['speed'] ? ' ' . esc_html( $t['speed'] ) : '' ) . '</small>' : '' );
			$out .= '<tr>'
				. '<td class="cm-lb-rank' . ( $p['rank'] <= 3 ? ' r' . $p['rank'] : '' ) . '">' . (int) $p['rank'] . '</td>'
				. '<td class="cm-lb-player">' . cmaps_player_cell( $p['name'], $p['user'], $p['profile'] ) . '</td>'
				. '<td class="num cm-points">' . esc_html( cmaps_fmt_points( $p['points'] ) ) . '</td>'
				. '<td class="num">' . (int) $p['passes'] . '</td>'
				. '<td class="cm-rank-best">' . $best . '</td>'
				. '</tr>';
		}
		$out .= '</tbody></table></div>';
	}

	$out .= '<details class="cm-how"><summary>How points work</summary>'
		. '<p>Every approved pass on a ranked map is worth points:</p>'
		. '<ul>'
		. '<li><strong>Map:</strong> 10 &times; (SR &divide; 10)<sup>' . esc_html( cmaps_fmt_sr( $o['curve'] ) ) . '</sup>. A 20★ is worth about ' . esc_html( round( 10 * pow( 2, $o['curve'] ) ) ) . ', a 40★ about ' . esc_html( round( 10 * pow( 4, $o['curve'] ) ) ) . '.</li>'
		. '<li><strong>Accuracy:</strong> 100% keeps all of it, ' . esc_html( cmaps_fmt_sr( $o['floor'] ) ) . '% or lower keeps half, and it climbs faster the closer you get to 100%.</li>'
		. '<li><strong>Speed:</strong> ' . esc_html( round( $o['speed'] * 100 ) ) . '% more per speed step up (S+, S++...), ' . esc_html( round( $o['speed'] * 100 ) ) . '% less per step down.</li>'
		. '</ul>'
		. '<p>Only your best pass on each map counts. Your passes are sorted best first and each one after the top is worth ' . esc_html( round( ( 1 - $o['decay'] ) * 100 ) ) . '% less than the one before, so hard passes matter more than lots of easy ones. Cam Lock and Spin are ranked separately.</p>'
		. '</details>';

	return $out . '</div>';
} );
