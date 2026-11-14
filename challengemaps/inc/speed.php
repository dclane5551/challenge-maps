<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Logo: dcm8.png is 1000x1000 (~1.3mb) but only ever shown at 64px.
 * Make a 256px webp next to it and serve that on the front end.
 */
function cmaps_logo_url() {
	// the real setting, not the swapped one from the filter below
	$GLOBALS['cmaps_raw_logo'] = true;
	$o = get_option( 'et_divi' );
	unset( $GLOBALS['cmaps_raw_logo'] );
	return ! empty( $o['divi_logo'] ) ? $o['divi_logo'] : '';
}

function cmaps_make_logo() {
	$url = cmaps_logo_url();
	if ( ! $url ) {
		return new WP_Error( 'cmaps', 'no logo set in Divi' );
	}

	$up   = wp_get_upload_dir();
	$rel  = str_replace( preg_replace( '#^https?:#', '', $up['baseurl'] ), '', preg_replace( '#^https?:#', '', $url ) );
	$path = $up['basedir'] . strtok( $rel, '?' );
	if ( $rel === preg_replace( '#^https?:#', '', $url ) || ! file_exists( $path ) ) {
		return new WP_Error( 'cmaps', 'logo isn\'t in the uploads folder' );
	}

	$img = wp_get_image_editor( $path );
	if ( is_wp_error( $img ) ) {
		return $img;
	}
	$size = $img->get_size();
	if ( $size['width'] <= 300 ) {
		delete_option( 'cmaps_logo' );
		return new WP_Error( 'cmaps', 'logo is already small' );
	}

	$img->resize( 256, 256 );
	$webp  = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	$info  = pathinfo( $path );
	$dest  = $info['dirname'] . '/' . $info['filename'] . '-256.' . ( $webp ? 'webp' : 'png' );
	$saved = $img->save( $dest, $webp ? 'image/webp' : 'image/png' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	$data = array(
		'orig'   => $url,
		'small'  => $up['baseurl'] . str_replace( wp_normalize_path( $up['basedir'] ), '', wp_normalize_path( $saved['path'] ) ),
		'before' => filesize( $path ),
		'after'  => filesize( $saved['path'] ),
	);
	update_option( 'cmaps_logo', $data );
	return $data;
}

// redo it if the logo gets changed in theme options
add_action( 'update_option_et_divi', function ( $old, $new ) {
	$a = isset( $old['divi_logo'] ) ? $old['divi_logo'] : '';
	$b = isset( $new['divi_logo'] ) ? $new['divi_logo'] : '';
	if ( $a !== $b ) {
		cmaps_make_logo();
	}
}, 10, 2 );


// serve the small logo on the front end (theme options still show the original)
add_filter( 'option_et_divi', function ( $opt ) {
	if ( is_admin() || ! empty( $GLOBALS['cmaps_raw_logo'] ) || ! cmaps_opt( 'logo' ) || ! is_array( $opt ) || empty( $opt['divi_logo'] ) ) {
		return $opt;
	}
	$logo = get_option( 'cmaps_logo' );
	if ( ! empty( $logo['small'] ) && $logo['orig'] === $opt['divi_logo'] ) {
		$opt['divi_logo'] = $logo['small'];
	}
	return $opt;
} );

// Divi loads Inter with the italic axis too, which nothing on the site uses
add_filter( 'style_loader_src', function ( $src, $handle ) {
	if ( cmaps_opt( 'fonts' ) && false !== strpos( $src, 'fonts.googleapis.com' ) ) {
		$src = preg_replace( '/family=Inter:ital,opsz,wght@[^&]+/', 'family=Inter:opsz,wght@14..32,100..900', $src );
	}
	return $src;
}, 10, 2 );


/*
 * Live Clears ticker. [cm_live_clears] goes inside the homepage's
 * cm-ticker-track div in place of the old hardcoded items. Items are printed
 * twice because the scroll animation loops on the second copy.
 */
add_shortcode( 'cm_live_clears', function () {
	$data  = cmaps_lb_query( array( 'sort' => 'recent', 'per_page' => 8 ) );
	$items = '';
	foreach ( $data['rows'] as $r ) {
		$items .= cmaps_ticker_item( $r );
	}
	if ( ! $items ) {
		return '<div class="cm-ticker-item cm-ticker-empty">No clears yet, <a href="' . esc_url( cmaps_score_page_url() ) . '"><span>submit yours</span></a></div>';
	}
	return $items . $items;
} );

function cmaps_ticker_item( $r ) {
	$map = $r['map'] . ( $r['tier'] ? ' (' . cmaps_tier_name( $r['tier'] ) . ')' : '' );
	$end = '';
	if ( null !== $r['acc'] ) {
		$end = $r['acc'] >= 100 ? ' · FC' : ' · ' . cmaps_fmt_acc( $r['acc'] ) . '% accuracy';
	}
	return '<div class="cm-ticker-item"><strong>' . esc_html( $r['player'] ) . '</strong> cleared <span>' . esc_html( $map ) . '</span>' . esc_html( $end ) . '</div>';
}

add_action( 'wp_enqueue_scripts', function () {
	if ( cmaps_opt( 'ticker' ) && is_front_page() ) {
		wp_enqueue_script( 'cmaps-ticker', CMAPS_URL . 'js/ticker.js', array(), cmaps_ver( 'js/ticker.js' ), true );
		wp_localize_script( 'cmaps-ticker', 'cmapsTicker', array(
			'url'   => rest_url( 'cm/v1/leaderboard' ),
			'tiers' => wp_list_pluck( cmaps_tiers(), 'name' ),
		) );
	}
} );

add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	if ( 'preconnect' === $type && cmaps_opt( 'fonts' ) ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );

add_action( 'init', function () {
	if ( cmaps_opt( 'emoji' ) ) {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}
} );

add_action( 'wp_default_scripts', function ( $scripts ) {
	if ( ! is_admin() && cmaps_opt( 'migrate' ) && isset( $scripts->registered['jquery'] ) ) {
		$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
	}
} );
