<?php
/*
 * Map list for the plugin. Read from the same CSV files the tier tables use
 * ([challenge_maps url="..."] on each tier page), so there's only ever one
 * list to update. Rebuilt when a tier page is saved and once a day.
 * If a tier page doesn't use the shortcode, its table is read off the page instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_tier_pages() {
	$pages = array();
	foreach ( cmaps_tiers() as $slug => $t ) {
		if ( ! empty( $t['page'] ) && 'publish' === $t['page']->post_status ) {
			$pages[ $slug ] = $t['page'];
		}
	}
	return $pages;
}

function cmaps_get_maps() {
	$data = get_option( 'cmaps_maps' );
	if ( empty( $data['maps'] ) ) {
		cmaps_queue_rebuild();
		return array();
	}
	if ( ! isset( $data['maps'][0]['key'] ) || time() - (int) $data['built'] > 12 * HOUR_IN_SECONDS ) {
		cmaps_queue_rebuild();
	}
	return $data['maps'];
}

function cmaps_queue_rebuild( $delay = 5 ) {
	if ( ! wp_next_scheduled( 'cmaps_rebuild_maps' ) ) {
		wp_schedule_single_event( time() + $delay, 'cmaps_rebuild_maps' );
	}
}

add_action( 'cmaps_rebuild_maps', 'cmaps_rebuild_maps' );
add_action( 'cmaps_daily', 'cmaps_rebuild_maps' );

function cmaps_rebuild_maps() {
	$maps   = array();
	$failed = array();

	foreach ( cmaps_tier_pages() as $tier => $page ) {
		$csv = cmaps_tier_csv_url( $page );
		if ( $csv ) {
			$rows = cmaps_read_tier_csv( $csv );
			if ( false !== $rows ) {
				foreach ( $rows as $row ) {
					$row['tier'] = $tier;
					$row['key']  = cmaps_map_key( $row );
					$maps[]      = $row;
				}
				continue;
			}
		}

		$html = cmaps_tier_html( $page );
		if ( ! $html ) {
			$failed[] = $tier;
			continue;
		}
		foreach ( cmaps_parse_table( $html ) as $row ) {
			$row['tier'] = $tier;
			$row['key']  = cmaps_map_key( $row );
			$maps[]      = $row;
		}
	}

	if ( ! $maps ) {
		return new WP_Error( 'cmaps', 'couldn\'t read the tier tables (' . implode( ', ', $failed ) . ')' );
	}
	update_option( 'cmaps_maps', array( 'built' => time(), 'maps' => $maps ), false );
	delete_transient( 'cmaps_lb' );
	return count( $maps );
}

function cmaps_tier_csv_url( $page ) {
	// Divi stores module content JSON-escaped, so quotes may show up as \u0022
	$content = str_replace( array( '\\u0022', '\\/' ), array( '"', '/' ), $page->post_content );
	if ( preg_match( '/\[challenge_maps[^\]]*url=["\']([^"\']+)["\']/', $content, $m ) ) {
		return esc_url_raw( $m[1] );
	}
	return '';
}

function cmaps_read_tier_csv( $url ) {
	if ( function_exists( 'cmaps_csv_rows' ) ) {
		$rows = cmaps_csv_rows( $url ); // the theme's reader (cached)
	} else {
		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$lines = preg_split( '/\r\n|\n|\r/', preg_replace( '/^\xEF\xBB\xBF/', '', wp_remote_retrieve_body( $res ) ) );
		$head  = array_map( 'strtolower', array_map( 'trim', str_getcsv( array_shift( $lines ) ) ) );
		$rows  = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols   = str_getcsv( $line );
			$rows[] = array_combine( $head, array_pad( array_map( 'trim', array_slice( $cols, 0, count( $head ) ) ), count( $head ), '' ) );
		}
	}
	if ( ! is_array( $rows ) ) {
		return false;
	}

	$out = array();
	foreach ( $rows as $r ) {
		$sr = isset( $r['sr'] ) ? (float) preg_replace( '/[^0-9.]/', '', $r['sr'] ) : 0;
		if ( empty( $r['song'] ) || $sr <= 0 ) {
			continue;
		}
		$out[] = array(
			'song'     => $r['song'],
			'sr'       => $sr,
			'color'    => cmaps_sr_color( $sr ),
			'bpm'      => isset( $r['bpm'] ) ? $r['bpm'] : '',
			'mapper'   => isset( $r['mapper'] ) ? $r['mapper'] : '',
			'download' => isset( $r['download_url'] ) ? $r['download_url'] : '',
		);
	}
	return $out;
}

function cmaps_tier_html( $page ) {
	$res = wp_remote_get( get_permalink( $page ), array(
		'timeout'   => 20,
		'sslverify' => false,
	) );
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$body = wp_remote_retrieve_body( $res );
		if ( false !== strpos( $body, 'challenge-table' ) ) {
			return $body;
		}
	}

	// some hosts block requests to themselves, so render it here instead
	global $post;
	$prev = $post;
	$post = $page;
	setup_postdata( $page );
	$html = apply_filters( 'the_content', $page->post_content );
	$post = $prev;
	wp_reset_postdata();

	return false !== strpos( $html, '<table' ) ? $html : '';
}

function cmaps_parse_table( $html ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return array();
	}
	libxml_use_internal_errors( true );
	$dom = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();

	$xp    = new DOMXPath( $dom );
	$table = $xp->query( '//table[contains(@class, "challenge-table")]' )->item( 0 );
	if ( ! $table ) {
		$table = $xp->query( '//table' )->item( 0 );
	}
	if ( ! $table ) {
		return array();
	}

	// find columns by header text in case the order ever changes
	$col = array( 'sr' => 0, 'song' => 1, 'bpm' => 2, 'mapper' => 3 );
	foreach ( $xp->query( './/thead//th', $table ) as $i => $th ) {
		$label = strtolower( trim( $th->textContent ) );
		if ( isset( $col[ $label ] ) ) {
			$col[ $label ] = $i;
		}
	}

	$rows = array();
	foreach ( $xp->query( './/tbody/tr', $table ) as $tr ) {
		$tds = $xp->query( './td', $tr );
		if ( $tds->length < 2 ) {
			continue;
		}
		$text = function ( $key ) use ( $tds, $col ) {
			$n = $tds->item( $col[ $key ] );
			return $n ? trim( preg_replace( '/\s+/u', ' ', $n->textContent ) ) : '';
		};

		$srcell = $tds->item( $col['sr'] );
		$sr     = ( $srcell && $srcell->hasAttribute( 'data-order' ) ) ? $srcell->getAttribute( 'data-order' ) : $text( 'sr' );
		$sr     = (float) preg_replace( '/[^0-9.]/', '', $sr );
		$song   = $text( 'song' );
		if ( ! $song || $sr <= 0 ) {
			continue;
		}

		$dl = '';
		foreach ( $xp->query( './/a[@href]', $tr ) as $a ) {
			if ( preg_match( '/\.sspm|\/raw\//i', $a->getAttribute( 'href' ) ) ) {
				$dl = $a->getAttribute( 'href' );
			}
		}

		// keep the exact color the theme gave this row, so the plugin always matches the tables
		$color = '';
		if ( $srcell && preg_match( '/background(?:-color)?:\s*(rgba?\([^)]+\)|#[0-9a-f]{3,8})/i', $srcell->getAttribute( 'style' ), $cm ) ) {
			$color = $cm[1];
		}

		$rows[] = array(
			'song'     => $song,
			'sr'       => $sr,
			'color'    => $color,
			'bpm'      => $text( 'bpm' ),
			'mapper'   => $text( 'mapper' ),
			'download' => $dl,
		);
	}
	return $rows;
}

add_action( 'save_post_page', function ( $id, $post ) {
	if ( 'publish' === $post->post_status && isset( cmaps_tiers()[ $post->post_name ] ) ) {
		wp_clear_scheduled_hook( 'cmaps_rebuild_maps' );
		cmaps_queue_rebuild( 10 );
	}
}, 10, 2 );

// what gets stored on a score when picked from the dropdown, e.g. "you - Melancholia (Kouko)"
function cmaps_map_key( $m ) {
	return $m['song'] . ( $m['mapper'] ? ' (' . $m['mapper'] . ')' : '' );
}

function cmaps_map_by_key( $key ) {
	foreach ( cmaps_get_maps() as $m ) {
		if ( isset( $m['key'] ) && $m['key'] === $key ) {
			return $m;
		}
	}
	return null;
}

function cmaps_map_color( $m ) {
	return ! empty( $m['color'] ) ? $m['color'] : cmaps_sr_color( $m['sr'] );
}

function cmaps_norm( $s ) {
	$s = remove_accents( wp_strip_all_tags( (string) $s ) );
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
	return trim( preg_replace( '/\s+/', ' ', $s ) );
}

// best guess at which ranked map someone meant when they typed the name
function cmaps_find_map( $name, $tier = '' ) {
	$exact = cmaps_map_by_key( trim( (string) $name ) );
	if ( $exact ) {
		return $exact;
	}
	$q = cmaps_norm( $name );
	if ( '' === $q ) {
		return null;
	}
	$best  = null;
	$score = 0;

	foreach ( cmaps_get_maps() as $m ) {
		$full = cmaps_norm( $m['song'] );
		$s    = 0;

		if ( $full === $q ) {
			$s = 100;
		} else {
			$parts = preg_split( '/\s[-–—]\s/u', $m['song'], 2 );
			$title = isset( $parts[1] ) ? cmaps_norm( $parts[1] ) : '';
			if ( $title && $title === $q ) {
				$s = 90;
			} elseif ( strlen( $q ) >= 4 && false !== strpos( $full, $q ) ) {
				$s = 60 + min( 25, (int) ( 25 * strlen( $q ) / max( 1, strlen( $full ) ) ) );
			} else {
				similar_text( $full, $q, $pct );
				$s = $pct >= 82 ? (int) $pct - 25 : 0;
			}
		}
		if ( $s && $tier && $m['tier'] === $tier ) {
			$s += 15;
		}
		if ( $s > $score ) {
			$score = $s;
			$best  = $m;
		}
	}
	return $score >= 60 ? $best : null;
}
