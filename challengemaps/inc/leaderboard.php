<?php
/*
 * Leaderboard, built off the Submit a Score form entries.
 *
 * Each score entry gets:
 *   cm_approved  1 once it's been checked (only approved ones show)
 *   cm_sr        the map's star rating, looked up from the tier tables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_score_form() {
	return (int) cmaps_opt( 'score_form' );
}

add_filter( 'gform_entry_meta', function ( $meta, $form_id ) {
	if ( (int) $form_id !== cmaps_score_form() ) {
		return $meta;
	}
	$meta['cm_approved'] = array(
		'label'                      => 'Leaderboard',
		'is_numeric'                 => true,
		'is_default_column'          => true,
		'update_entry_meta_callback' => null,
		'filter'                     => array(
			'operators' => array( 'is', 'isnot' ),
			'choices'   => array(
				array( 'value' => '1', 'text' => 'Approved' ),
				array( 'value' => '0', 'text' => 'Pending' ),
			),
		),
	);
	$meta['cm_sr'] = array(
		'label'                      => 'SR',
		'is_numeric'                 => true,
		'is_default_column'          => true,
		'update_entry_meta_callback' => null,
		'filter'                     => array( 'operators' => array( 'is', '>', '<' ) ),
	);
	return $meta;
}, 10, 2 );

add_filter( 'gform_entries_field_value', function ( $value, $form_id, $field_id ) {
	if ( 'cm_approved' === $field_id && (int) $form_id === cmaps_score_form() ) {
		return $value ? 'Approved' : 'Pending';
	}
	return $value;
}, 10, 3 );

add_action( 'gform_after_submission', function ( $entry, $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() ) {
		return;
	}
	cmaps_match_sr( $entry );
	gform_update_meta( $entry['id'], 'cm_approved', cmaps_opt( 'approval' ) ? 0 : 1 );
	cmaps_lb_flush();
}, 10, 2 );

function cmaps_match_sr( $entry, $force = false ) {
	$id  = $entry['id'];
	$cur = gform_get_meta( $id, 'cm_sr' );
	if ( ! $force && gform_get_meta( $id, 'cm_sr_manual' ) ) {
		return $cur;
	}
	$map = cmaps_find_map( rgar( $entry, cmaps_opt( 'f_map' ) ), cmaps_tier_slug( rgar( $entry, cmaps_opt( 'f_tier' ) ) ) );
	if ( $map ) {
		gform_update_meta( $id, 'cm_sr', $map['sr'] );
		gform_update_meta( $id, 'cm_map_match', $map['key'] );
		return $map['sr'];
	}
	return $cur;
}

function cmaps_has_value( $v ) {
	return null !== $v && false !== $v && '' !== (string) $v;
}

/* --- approving entries --- */

add_filter( 'gform_entry_list_bulk_actions', function ( $actions, $form_id ) {
	if ( (int) $form_id === cmaps_score_form() ) {
		$actions['cm_approve']   = 'Approve for leaderboard';
		$actions['cm_unapprove'] = 'Remove from leaderboard';
		$actions['cm_rematch']   = 'Re-match SR';
	}
	return $actions;
}, 10, 2 );

add_action( 'gform_entry_list_action', function ( $action, $entries, $form_id ) {
	if ( (int) $form_id !== cmaps_score_form() || ! current_user_can( 'gravityforms_edit_entries' ) ) {
		return;
	}
	foreach ( (array) $entries as $id ) {
		$id = absint( $id );
		if ( 'cm_approve' === $action ) {
			gform_update_meta( $id, 'cm_approved', 1 );
			if ( ! cmaps_has_value( gform_get_meta( $id, 'cm_sr' ) ) ) {
				$e = GFAPI::get_entry( $id );
				if ( ! is_wp_error( $e ) ) {
					cmaps_match_sr( $e );
				}
			}
		} elseif ( 'cm_unapprove' === $action ) {
			gform_update_meta( $id, 'cm_approved', 0 );
		} elseif ( 'cm_rematch' === $action ) {
			$e = GFAPI::get_entry( $id );
			if ( ! is_wp_error( $e ) ) {
				gform_delete_meta( $id, 'cm_sr_manual' );
				cmaps_match_sr( $e, true );
			}
		}
	}
	cmaps_lb_flush();
}, 10, 3 );

add_filter( 'gform_entry_detail_meta_boxes', function ( $boxes, $entry, $form ) {
	if ( (int) $form['id'] === cmaps_score_form() ) {
		$boxes['cm_lb'] = array(
			'title'    => 'Leaderboard',
			'callback' => 'cmaps_entry_box',
			'context'  => 'side',
		);
	}
	return $boxes;
}, 10, 3 );

function cmaps_entry_box( $args ) {
	$id       = (int) $args['entry']['id'];
	$approved = (int) gform_get_meta( $id, 'cm_approved' );
	$sr       = gform_get_meta( $id, 'cm_sr' );
	$match    = gform_get_meta( $id, 'cm_map_match' );
	$url      = wp_nonce_url( admin_url( 'admin-post.php?action=cmaps_entry&entry=' . $id ), 'cmaps_entry_' . $id );

	echo '<p>' . ( $approved ? 'Showing on the leaderboard.' : 'Not approved yet.' ) . '</p>';
	echo '<p>SR: ' . ( cmaps_has_value( $sr ) ? esc_html( cmaps_fmt_sr( $sr ) ) : 'no match' );
	if ( $match ) {
		echo '<br><small>' . esc_html( $match ) . '</small>';
	}
	echo '</p><p>';
	if ( $approved ) {
		echo '<a class="button" href="' . esc_url( $url . '&do=unapprove' ) . '">Remove</a>';
	} else {
		echo '<a class="button button-primary" href="' . esc_url( $url . '&do=approve' ) . '">Approve</a>';
	}
	echo '</p><p><input id="cm-sr-in" type="number" step="0.1" min="1" max="99" style="width:70px" form="cm-none" value="' . esc_attr( (string) $sr ) . '"> ';
	echo '<a class="button" href="#" onclick="var v=document.getElementById(\'cm-sr-in\').value;if(!v)return false;this.href=\'' . esc_js( $url ) . '&do=sr&sr=\'+encodeURIComponent(v);">Set SR</a></p>';
}

add_action( 'admin_post_cmaps_entry', function () {
	$id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
	check_admin_referer( 'cmaps_entry_' . $id );
	if ( ! current_user_can( 'gravityforms_edit_entries' ) ) {
		wp_die( 'Not allowed' );
	}
	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

	if ( 'approve' === $do ) {
		gform_update_meta( $id, 'cm_approved', 1 );
		$e = GFAPI::get_entry( $id );
		if ( ! is_wp_error( $e ) && ! cmaps_has_value( gform_get_meta( $id, 'cm_sr' ) ) ) {
			cmaps_match_sr( $e );
		}
	} elseif ( 'unapprove' === $do ) {
		gform_update_meta( $id, 'cm_approved', 0 );
	} elseif ( 'sr' === $do && isset( $_GET['sr'] ) ) {
		$sr = (float) $_GET['sr'];
		if ( $sr > 0 && $sr < 100 ) {
			gform_update_meta( $id, 'cm_sr', $sr );
			gform_update_meta( $id, 'cm_sr_manual', 1 );
		}
	}
	cmaps_lb_flush();
	wp_safe_redirect( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . cmaps_score_form() . '&lid=' . $id ) );
	exit;
} );

add_action( 'gform_after_update_entry', function ( $form ) {
	if ( (int) $form['id'] === cmaps_score_form() ) {
		cmaps_lb_flush();
	}
} );
add_action( 'gform_update_status', 'cmaps_lb_flush' );
add_action( 'gform_delete_entry', 'cmaps_lb_flush' );

function cmaps_lb_flush() {
	delete_transient( 'cmaps_lb' );
	delete_transient( 'cmaps_rank' );
	delete_transient( 'cmaps_search_players' );
	delete_transient( 'cmaps_pending' );
	update_option( 'cmaps_lb_ver', time(), false );
	cmaps_clear_page_cache();
}

// clear the cached homepage + leaderboard so they pick up new scores
function cmaps_clear_page_cache() {
	$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'cmaps_lb_page' ) );
	$diff = cmaps_diff_page();
	if ( $diff ) {
		$ids[] = $diff->ID;
	}
	foreach ( cmaps_tier_pages() as $p ) {
		$ids[] = $p->ID; // best passes show on the tier tables
	}
	$ids = array_filter( array_unique( $ids ) );

	foreach ( $ids as $id ) {
		do_action( 'litespeed_purge_post', $id );        // LiteSpeed Cache
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $id );                  // WP Super Cache
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id );                     // WP Rocket
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $id );                       // W3 Total Cache
		}
	}
	if ( function_exists( 'rocket_clean_home' ) ) {
		rocket_clean_home();
	}
	do_action( 'litespeed_purge_url', home_url( '/' ) );
}

/* --- data --- */

function cmaps_speed_tag( $raw ) {
	$s = str_replace( ' ', '', trim( (string) $raw ) );
	if ( '' === $s ) {
		return '';
	}
	return preg_match( '/^[+\-]+$/', $s ) ? 'S' . $s : strtoupper( $s );
}

// for sorting: S--- = -3 ... NM = 0 ... S++++ = 4
function cmaps_speed_rank( $tag ) {
	if ( preg_match( '/^S([+\-]+)$/', $tag, $m ) ) {
		return ( '+' === $m[1][0] ? 1 : -1 ) * strlen( $m[1] );
	}
	return 0;
}

function cmaps_acc( $raw ) {
	if ( preg_match( '/\d+(?:[.,]\d+)?/', (string) $raw, $m ) ) {
		$v = (float) str_replace( ',', '.', $m[0] );
		return ( $v >= 0 && $v <= 100 ) ? $v : null;
	}
	return null;
}

function cmaps_fmt_acc( $v ) {
	return rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
}

function cmaps_video( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return '';
		}
		$url = 'https://' . ltrim( $url, '/' );
	}
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return ( $host && false !== strpos( $host, '.' ) ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
}

// all matching entries, 500 at a time so there's no upper limit
function cmaps_all_entries( $search ) {
	$all    = array();
	$offset = 0;
	do {
		$batch = GFAPI::get_entries( cmaps_score_form(), $search, array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => $offset, 'page_size' => 500 ) );
		if ( is_wp_error( $batch ) ) {
			break;
		}
		$all     = array_merge( $all, $batch );
		$offset += 500;
	} while ( count( $batch ) === 500 );
	return $all;
}

function cmaps_lb_rows() {
	$rows = get_transient( 'cmaps_lb' );
	if ( is_array( $rows ) ) {
		return $rows;
	}
	$rows = array();
	if ( ! cmaps_gf() || ! cmaps_score_form() ) {
		return $rows;
	}

	$search = array( 'status' => 'active' );
	if ( cmaps_opt( 'approval' ) ) {
		$search['field_filters'] = array( array( 'key' => 'cm_approved', 'value' => '1' ) );
	}
	$entries = cmaps_all_entries( $search );

	$by_key  = array();
	$by_song = array();
	foreach ( cmaps_get_maps() as $m ) {
		$by_key[ $m['key'] ]   = $m;
		$by_song[ $m['song'] ] = $m;
	}

	foreach ( $entries as $e ) {
		$sr = gform_get_meta( $e['id'], 'cm_sr' );
		if ( ! cmaps_has_value( $sr ) ) {
			$sr = cmaps_match_sr( $e );
		}
		$sr    = cmaps_has_value( $sr ) ? (float) $sr : null;
		$match = (string) gform_get_meta( $e['id'], 'cm_map_match' );
		$map   = isset( $by_key[ $match ] ) ? $by_key[ $match ] : ( isset( $by_song[ $match ] ) ? $by_song[ $match ] : null );
		$speed = cmaps_speed_tag( rgar( $e, cmaps_opt( 'f_speed' ) ) );
		$acc   = cmaps_acc( rgar( $e, cmaps_opt( 'f_acc' ) ) );
		$user  = (int) gform_get_meta( $e['id'], 'cm_user_id' );
		$who   = $user ? get_userdata( $user ) : null;
		if ( ! $who ) {
			$user = 0;
		}

		// use the table's own color when the SR hasn't been changed by hand
		$color = null;
		if ( null !== $sr ) {
			$color = ( $map && (float) $map['sr'] === $sr ) ? cmaps_map_color( $map ) : cmaps_sr_color( $sr );
		}

		$rows[] = array(
			'id'       => (int) $e['id'],
			'player'   => $who ? $who->display_name : wp_strip_all_tags( rgar( $e, cmaps_opt( 'f_player' ) ) ),
			'user'     => $user,
			'profile'  => $user ? cmaps_player_url( $user ) : '',
			'avatar'   => $user ? cmaps_avatar_url( $user, 64 ) : '',
			'map'      => $map ? $map['song'] : wp_strip_all_tags( rgar( $e, cmaps_opt( 'f_map' ) ) ),
			'tier'     => cmaps_tier_slug( rgar( $e, cmaps_opt( 'f_tier' ) ) ),
			'sr'       => $sr,
			'color'    => $color,
			'acc'      => $acc,
			'acc_raw'  => wp_strip_all_tags( rgar( $e, cmaps_opt( 'f_acc' ) ) ),
			'style'    => wp_strip_all_tags( rgar( $e, cmaps_opt( 'f_style' ) ) ),
			'speed'    => $speed,
			'speed_n'  => cmaps_speed_rank( $speed ),
			'video'    => cmaps_video( rgar( $e, cmaps_opt( 'f_video' ) ) ),
			'download' => $map ? $map['download'] : '',
			'key'      => $map ? $map['key'] : '',
			'url'      => $map ? cmaps_map_url( $map['key'] ) : '',
			'points'   => $map ? cmaps_points( $sr, $acc, cmaps_speed_rank( $speed ) ) : 0,
			'time'     => (int) strtotime( $e['date_created'] . ' UTC' ),
		);
	}

	set_transient( 'cmaps_lb', $rows, HOUR_IN_SECONDS );
	return $rows;
}

function cmaps_lb_query( $args ) {
	$args = wp_parse_args( $args, array( 'sort' => 'recent', 'tier' => '', 'style' => '', 'q' => '', 'page' => 1, 'per_page' => 25 ) );

	$tier  = cmaps_tier_slug( $args['tier'] );
	$style = str_replace( ' ', '', strtolower( (string) $args['style'] ) );
	// search is only ever compared as plain text (letters and numbers), never run or put in a query
	$q     = cmaps_norm( mb_substr( (string) $args['q'], 0, 60 ) );

	$rows = array_values( array_filter( cmaps_lb_rows(), function ( $r ) use ( $tier, $style, $q ) {
		if ( $tier && $r['tier'] !== $tier ) {
			return false;
		}
		if ( $style && str_replace( ' ', '', strtolower( $r['style'] ) ) !== $style ) {
			return false;
		}
		return ! $q || false !== strpos( cmaps_norm( $r['player'] . ' ' . $r['map'] ), $q );
	} ) );

	if ( 'top' === $args['sort'] ) {
		// map SR, then speed mod, then accuracy, then whoever got it first
		usort( $rows, function ( $a, $b ) {
			return array( $b['sr'] ?? -1, $b['speed_n'], $b['acc'] ?? -1, $a['time'] )
				<=> array( $a['sr'] ?? -1, $a['speed_n'], $a['acc'] ?? -1, $b['time'] );
		} );
		// only each player's best pass per map/playstyle
		$seen = array();
		$rows = array_values( array_filter( $rows, function ( $r ) use ( &$seen ) {
			$k = cmaps_player_key( $r ) . '|' . strtolower( $r['map'] . '|' . $r['style'] );
			if ( isset( $seen[ $k ] ) ) {
				return false;
			}
			$seen[ $k ] = true;
			return true;
		} ) );
	} else {
		usort( $rows, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
	}

	$per  = max( 1, min( 100, (int) $args['per_page'] ) );
	$page = max( 1, (int) $args['page'] );
	$out  = array_slice( $rows, ( $page - 1 ) * $per, $per );
	foreach ( $out as $i => $r ) {
		$out[ $i ]['rank'] = ( $page - 1 ) * $per + $i + 1;
		unset( $out[ $i ]['speed_n'] );
	}

	return array(
		'total'    => count( $rows ),
		'page'     => $page,
		'per_page' => $per,
		'ver'      => (int) get_option( 'cmaps_lb_ver', 0 ),
		'now'      => time(),
		'rows'     => $out,
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'cm/v1', '/leaderboard', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array(
			'sort'     => array( 'type' => 'string', 'default' => 'recent', 'enum' => array( 'recent', 'top' ) ),
			'tier'     => array( 'type' => 'string', 'default' => '', 'maxLength' => 40 ),
			'style'    => array( 'type' => 'string', 'default' => '', 'maxLength' => 40 ),
			'q'        => array( 'type' => 'string', 'default' => '', 'maxLength' => 60 ),
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
		),
		'callback'            => function ( $req ) {
			$res = rest_ensure_response( cmaps_lb_query( $req->get_params() ) );
			$res->header( 'Cache-Control', 'public, max-age=15' );
			$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' ); // litespeed caches the api by default
			return $res;
		},
	) );
} );

/* --- [cm_leaderboard] --- */

add_shortcode( 'cm_leaderboard', function ( $atts ) {
	$atts  = shortcode_atts( array( 'sort' => 'recent', 'per_page' => 25, 'style' => 'Cam Lock' ), $atts );
	$sort  = 'top' === $atts['sort'] ? 'top' : 'recent';
	$style = 'spin' === strtolower( $atts['style'] ) ? 'Spin' : 'Cam Lock'; // separate boards per playstyle
	$data  = cmaps_lb_query( array( 'sort' => $sort, 'style' => $style, 'per_page' => (int) $atts['per_page'] ) );

	wp_enqueue_script( 'cmaps-lb', CMAPS_URL . 'js/leaderboard.js', array(), cmaps_ver( 'js/leaderboard.js' ), true );

	$cfg = array(
		'url'     => rest_url( 'cm/v1/leaderboard' ),
		'sort'    => $sort,
		'style'   => $style,
		'perPage' => (int) $atts['per_page'],
		'data'    => $data,
		'stops'   => cmaps_stops(),
		'tiers'   => wp_list_pluck( cmaps_tiers(), 'name' ),
	);

	ob_start();
	?>
	<div class="cm-lb" data-cfg="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">
		<div class="cm-lb-bar">
			<div class="cm-lb-tabs" role="tablist">
				<button type="button" class="cm-lb-tab" role="tab" data-sort="recent" aria-selected="<?php echo 'recent' === $sort ? 'true' : 'false'; ?>">Recent</button>
				<button type="button" class="cm-lb-tab" role="tab" data-sort="top" aria-selected="<?php echo 'top' === $sort ? 'true' : 'false'; ?>">Highest Rated</button>
			</div>
			<span class="cm-lb-live">Live</span>
			<div class="cm-lb-filters">
				<select data-filter="tier" aria-label="Difficulty">
					<option value="">All difficulties</option>
					<?php foreach ( cmaps_tiers() as $slug => $t ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select data-filter="style" aria-label="Playstyle">
					<option value="Cam Lock"<?php selected( $style, 'Cam Lock' ); ?>>Cam Lock</option>
					<option value="Spin"<?php selected( $style, 'Spin' ); ?>>Spin</option>
				</select>
				<input type="search" data-filter="q" maxlength="60" placeholder="Search player or map" aria-label="Search player or map">
			</div>
		</div>

		<table class="cm-lb-table">
			<thead>
				<tr>
					<th>#</th>
					<th>Player</th>
					<th>Map</th>
					<th class="center">SR</th>
					<th class="num">Accuracy</th>
					<th>Playstyle</th>
					<th>Speed</th>
					<th>Video</th>
					<th>Date</th>
				</tr>
			</thead>
			<tbody><?php echo cmaps_lb_rows_html( $data['rows'] ); ?></tbody>
		</table>
		<p class="cm-lb-empty"<?php echo $data['rows'] ? ' hidden' : ''; ?>>No scores yet. <a href="<?php echo esc_url( cmaps_score_page_url() ); ?>">Submit a score</a></p>
		<button type="button" class="cm-lb-more"<?php echo $data['total'] > count( $data['rows'] ) ? '' : ' hidden'; ?>>Load more</button>
	</div>
	<?php
	return ob_get_clean();
} );

function cmaps_lb_rows_html( $rows ) {
	$html = '';
	foreach ( $rows as $r ) {
		$sr  = null !== $r['sr']
			? '<span class="cm-sr" style="background:' . esc_attr( $r['color'] ) . '">' . esc_html( cmaps_fmt_sr( $r['sr'] ) ) . '</span>'
			: '<span class="cm-chip">?</span>';
		$link = $r['url'] ? $r['url'] : $r['download'];
		$map  = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $r['map'] ) . '</a>' : esc_html( $r['map'] );
		$acc = null !== $r['acc'] ? cmaps_fmt_acc( $r['acc'] ) . '%' : $r['acc_raw'];

		$html .= '<tr>'
			. '<td class="cm-lb-rank' . ( $r['rank'] <= 3 ? ' r' . $r['rank'] : '' ) . '">' . (int) $r['rank'] . '</td>'
			. '<td class="cm-lb-player">' . cmaps_player_cell( $r['player'], $r['user'], $r['profile'] ) . '</td>'
			. '<td class="cm-lb-map">' . $map . ( $r['tier'] ? '<small>' . esc_html( cmaps_tier_name( $r['tier'] ) ) . '</small>' : '' ) . '</td>'
			. '<td class="cm-lb-sr center">' . $sr . '</td>'
			. '<td class="cm-lb-acc num">' . esc_html( $acc ) . '</td>'
			. '<td class="cm-lb-style">' . ( $r['style'] ? '<span class="cm-chip">' . esc_html( $r['style'] ) . '</span>' : '' ) . '</td>'
			. '<td class="cm-lb-speed">' . ( $r['speed'] ? '<span class="cm-chip">' . esc_html( $r['speed'] ) . '</span>' : '' ) . '</td>'
			. '<td class="cm-lb-video">' . ( $r['video'] ? '<a href="' . esc_url( $r['video'] ) . '" target="_blank" rel="noopener">Watch</a>' : '' ) . '</td>'
			. '<td class="cm-lb-date"><time datetime="' . esc_attr( gmdate( 'c', $r['time'] ) ) . '">' . esc_html( human_time_diff( $r['time'] ) ) . ' ago</time></td>'
			. '</tr>';
	}
	return $html;
}
