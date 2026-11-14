<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_our_pages() {
	return array_filter( array( (int) get_option( 'cmaps_lb_page' ), (int) get_option( 'cmaps_map_page' ) ) );
}

// new pages or routes need WordPress to rebuild its url rules once
add_action( 'init', function () {
	if ( get_option( 'cmaps_flush_rules' ) ) {
		delete_option( 'cmaps_flush_rules' );
		flush_rewrite_rules( false );
	}
}, 99 );

// difficulties + our pages don't need the sidebar
add_filter( 'body_class', function ( $classes ) {
	if ( is_page( cmaps_opt( 'diff_slug' ) ) || ( cmaps_our_pages() && is_page( cmaps_our_pages() ) ) ) {
		$classes   = array_diff( $classes, array( 'et_right_sidebar', 'et_left_sidebar' ) );
		$classes[] = 'et_full_width_page';
		$classes[] = 'et_no_sidebar';
		$classes[] = 'cm-full';
	}
	return $classes;
}, 20 );

/*
 * Difficulties page was empty. If it still has no content of its own, show the
 * tier cards there.
 */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_page( cmaps_opt( 'diff_slug' ) ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( trim( wp_strip_all_tags( $content ) ) !== '' ) {
		return $content;
	}
	return $content . cmaps_tier_grid();
}, 20 );

add_shortcode( 'cm_difficulties', 'cmaps_tier_grid' );

function cmaps_tier_grid() {
	$stats = array();
	foreach ( cmaps_get_maps() as $m ) {
		$t = $m['tier'];
		if ( ! isset( $stats[ $t ] ) ) {
			$stats[ $t ] = array( 'count' => 0, 'min' => $m['sr'], 'max' => $m['sr'], 'sum' => 0, 'top' => $m );
		}
		$stats[ $t ]['count']++;
		$stats[ $t ]['sum'] += $m['sr'];
		$stats[ $t ]['min']  = min( $stats[ $t ]['min'], $m['sr'] );
		if ( $m['sr'] > $stats[ $t ]['max'] ) {
			$stats[ $t ]['max'] = $m['sr'];
			$stats[ $t ]['top'] = $m;
		}
	}

	$out = '<div class="cm-tier-grid cm-tier-grid--page">';
	foreach ( cmaps_tiers() as $slug => $t ) {
		$s   = isset( $stats[ $slug ] ) ? $stats[ $slug ] : null;
		$url = ! empty( $t['page'] ) ? get_permalink( $t['page'] ) : home_url( '/' . cmaps_opt( 'diff_slug' ) . '/' . $slug . '/' );

		// new tiers without preset copy get it from their maps
		$color = $t['color'] ? $t['color'] : ( $s ? cmaps_sr_color( $s['sum'] / $s['count'] ) : '#8b949e' );
		$range = $t['range'] ? $t['range'] : ( $s ? cmaps_fmt_sr( $s['min'] ) . '★ – ' . cmaps_fmt_sr( $s['max'] ) . '★' : '' );

		$out .= '<a href="' . esc_url( $url ) . '" class="cm-tier-card" style="border-top: 4px solid ' . esc_attr( $color ) . ';">';
		$out .= '<h3 style="color: ' . esc_attr( $color ) . ' !important;">' . esc_html( $t['name'] ) . '</h3>';
		if ( $range ) {
			$out .= '<div class="cm-tier-stars">' . esc_html( $range ) . '</div>';
		}
		if ( $t['desc'] ) {
			$out .= '<p>' . esc_html( $t['desc'] ) . '</p>';
		}
		if ( $s ) {
			$out .= '<div class="cm-tier-meta"><span>' . (int) $s['count'] . ' maps</span>';
			$out .= '<span>Hardest <span class="cm-sr" style="background:' . esc_attr( cmaps_map_color( $s['top'] ) ) . '">' . esc_html( cmaps_fmt_sr( $s['max'] ) ) . '★</span></span></div>';
		}
		$out .= '</a>';
	}
	return $out . '</div>';
}


/*
 * Setup - runs on the first admin page load after activating (so Gravity
 * Forms is definitely loaded). Safe to run again.
 */
add_action( 'admin_init', function () {
	// also runs after uploading a new version over the old one (that skips activation)
	$updated = get_option( 'cmaps_setup_ver' ) !== CMAPS_VER;
	if ( ( get_option( 'cmaps_needs_setup' ) || $updated ) && current_user_can( 'manage_options' ) ) {
		delete_option( 'cmaps_needs_setup' );
		update_option( 'cmaps_setup_ver', CMAPS_VER );
		set_transient( 'cmaps_setup_msgs', cmaps_setup(), 300 );
	}
} );

function cmaps_setup() {
	$problems = array();

	cmaps_setup_roles();
	cmaps_setup_player_role();
	cmaps_remove_version_files();

	$logo = cmaps_make_logo();
	if ( is_wp_error( $logo ) && 'logo is already small' !== $logo->get_error_message() ) {
		$problems[] = 'Logo: ' . $logo->get_error_message();
	}

	$maps = cmaps_rebuild_maps();
	if ( is_wp_error( $maps ) ) {
		$problems[] = 'Maps: ' . $maps->get_error_message();
	}

	$diff = cmaps_diff_page();
	if ( $diff ) {
		update_post_meta( $diff->ID, '_et_pb_page_layout', 'et_full_width_page' );
	}

	$form = cmaps_map_form();
	if ( is_wp_error( $form ) ) {
		$problems[] = 'Map form: ' . $form->get_error_message();
	}

	cmaps_sync_map_field();
	cmaps_remove_style_only_field();
	$problems = array_merge( $problems, cmaps_check_score_form() );

	$lb = cmaps_page( 'cmaps_lb_page', 'Leaderboard', 'leaderboard', '[cm_leaderboard]' );
	$mp = cmaps_page( 'cmaps_map_page', 'Submit a Map', 'submit-a-map', '[cm_submit_map]' );
	$rk = cmaps_page( 'cmaps_rank_page', 'Rankings', 'rankings', '[cm_rankings]' );
	cmaps_menu( array_filter( array( $lb, $rk, $mp ) ) );

	// pages behind /map/<song>/ and /player/<name>/ (not in the menu)
	if ( ! cmaps_page( 'cmaps_mappage_page', 'Map', 'map', '[cm_map]' ) ) {
		$problems[] = 'Couldn\'t make the Map page, something else already uses /map/.';
	}
	if ( ! cmaps_page( 'cmaps_player_page', 'Player', 'player', '[cm_player]' ) ) {
		$problems[] = 'Couldn\'t make the Player page, something else already uses /player/.';
	}
	update_option( 'cmaps_flush_rules', 1 );

	if ( ! cmaps_discord_ready() ) {
		$problems[] = 'Discord sign in isn\'t set up yet, so score submissions don\'t need an account. See Settings > Challenge Maps.';
	}

	return $problems;
}

function cmaps_page( $option, $title, $slug, $shortcode ) {
	$id = (int) get_option( $option );
	if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
		return $id;
	}
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		if ( false === strpos( $existing->post_content, $shortcode ) ) {
			return 0; // slug is used by something else, leave it alone
		}
		update_option( $option, $existing->ID );
		return $existing->ID;
	}
	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $shortcode,
		'meta_input'   => array( '_et_pb_page_layout' => 'et_full_width_page' ),
	) );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_option( $option, $id );
	return $id;
}

// adds the pages to the main menu after Submit a Score (skips ones already there)
function cmaps_menu( array $ids ) {
	$locs = get_nav_menu_locations();
	$menu = 0;
	foreach ( array( 'primary-menu', 'primary' ) as $l ) {
		if ( ! empty( $locs[ $l ] ) ) {
			$menu = (int) $locs[ $l ];
			break;
		}
	}
	if ( ! $menu ) {
		return;
	}

	$items = (array) wp_get_nav_menu_items( $menu );
	$have  = array();
	$pos   = 0;
	$score = get_page_by_path( cmaps_opt( 'score_slug' ) );
	foreach ( $items as $item ) {
		$have[ (int) $item->object_id ] = true;
		if ( $score && (int) $item->object_id === $score->ID ) {
			$pos = (int) $item->menu_order;
		}
		$last = (int) $item->menu_order;
	}
	if ( ! $pos ) {
		$pos = isset( $last ) ? $last : 0;
	}

	foreach ( $ids as $id ) {
		if ( isset( $have[ $id ] ) ) {
			continue;
		}
		$pos++;
		foreach ( $items as $item ) {
			if ( (int) $item->menu_order >= $pos ) {
				$item->menu_order++;
				wp_update_post( array( 'ID' => $item->ID, 'menu_order' => $item->menu_order ) );
			}
		}
		wp_update_nav_menu_item( $menu, 0, array(
			'menu-item-object-id' => $id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $pos,
		) );
	}
}

add_action( 'admin_notices', function () {
	$msgs = get_transient( 'cmaps_setup_msgs' );
	if ( false === $msgs ) {
		return;
	}
	delete_transient( 'cmaps_setup_msgs' );
	if ( ! $msgs ) {
		echo '<div class="notice notice-success is-dismissible"><p>Challenge Maps is set up. <a href="' . esc_url( admin_url( 'options-general.php?page=challengemaps' ) ) . '">Settings</a></p></div>';
		return;
	}
	echo '<div class="notice notice-warning is-dismissible"><p>Challenge Maps is set up, but:</p><p>' . implode( '<br>', array_map( 'esc_html', $msgs ) ) . '</p></div>';
} );

// [cm_map_count] - total ranked maps, for the homepage stat
add_shortcode( 'cm_map_count', function () {
	return number_format_i18n( count( cmaps_get_maps() ) );
} );
