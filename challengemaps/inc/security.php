<?php
/*
 * Locking things down.
 *
 * - 5 tries per 10 minutes on logins, password resets and Discord sign in
 * - same message for a wrong username and a wrong password
 * - no listing usernames through the REST api, ?author=1 or the sitemap
 * - no version numbers in the page source
 * - a few response headers browsers use to block common attacks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- rate limiting --- */

// only REMOTE_ADDR - headers like X-Forwarded-For can be made up by whoever is sending the request
function cmaps_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

function cmaps_rate_key( $bucket ) {
	return 'cmaps_rl_' . $bucket . '_' . md5( cmaps_ip() . wp_salt( 'nonce' ) );
}

function cmaps_rate_limited( $bucket, $limit = 5 ) {
	$hits = get_transient( cmaps_rate_key( $bucket ) );
	return is_array( $hits ) && count( $hits ) >= $limit;
}

// counts one try. returns false once the limit is hit
function cmaps_rate_hit( $bucket, $limit = 5, $window = 600 ) {
	$key  = cmaps_rate_key( $bucket );
	$now  = time();
	$hits = get_transient( $key );
	$hits = array_values( array_filter( is_array( $hits ) ? $hits : array(), function ( $t ) use ( $now, $window ) {
		return $t > $now - $window;
	} ) );
	$hits[] = $now;
	set_transient( $key, $hits, $window );
	return count( $hits ) <= $limit;
}

function cmaps_rate_reset( $bucket ) {
	delete_transient( cmaps_rate_key( $bucket ) );
}

function cmaps_wait_message() {
	return 'Too many tries. Wait 10 minutes and try again.';
}

// logins: blocked after 5 failures, and the error never says which part was wrong
add_filter( 'authenticate', function ( $user ) {
	if ( cmaps_rate_limited( 'login' ) ) {
		return new WP_Error( 'cm_too_many', cmaps_wait_message() );
	}
	if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return new WP_Error( 'cm_bad_login', 'Wrong username or password.' );
	}
	return $user;
}, 99 );

add_action( 'wp_login_failed', function () {
	cmaps_rate_hit( 'login' );
} );

add_action( 'wp_login', function () {
	cmaps_rate_reset( 'login' );
} );

// password resets: 5 requests per 10 minutes, and the same answer whether the account exists or not
add_filter( 'lostpassword_user_data', function ( $user, $errors ) {
	if ( ! cmaps_rate_hit( 'lostpw' ) ) {
		$errors->add( 'cm_too_many', cmaps_wait_message() );
		return $user;
	}
	if ( ! $user ) {
		$errors->remove( 'invalid_email' );
		$errors->remove( 'invalidcombo' );
		if ( ! $errors->has_errors() && isset( $_POST['user_login'] ) && '' !== trim( (string) $_POST['user_login'] ) ) {
			wp_safe_redirect( wp_login_url() . '?checkemail=confirm' );
			exit;
		}
	}
	return $user;
}, 10, 2 );

// nothing on this site uses xml-rpc or application passwords
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter( 'wp_is_application_passwords_available', '__return_false' );


/* --- usernames --- */

add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! current_user_can( 'list_users' ) ) {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
	}
	return $endpoints;
} );

// ?author=1 would otherwise redirect to /author/<username>/
add_action( 'parse_request', function () {
	if ( ! is_admin() && isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}, 1 );

add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );


/* --- page source --- */

remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'rest_output_link_wp_head' );
add_filter( 'the_generator', '__return_empty_string' );

// Divi prints its own "Divi Child v.x" generator tag in the middle of other head output
add_action( 'wp_head', function () {
	ob_start();
}, 6 );
add_action( 'wp_head', function () {
	echo preg_replace( '#<meta[^>]+name="generator"[^>]*>\s*#i', '', ob_get_clean() );
}, 8 );

// readme.html and license.txt show the WordPress version. updates put them back, so check daily
function cmaps_remove_version_files() {
	foreach ( array( 'readme.html', 'license.txt' ) as $f ) {
		if ( is_file( ABSPATH . $f ) && is_writable( ABSPATH . $f ) ) {
			unlink( ABSPATH . $f );
		}
	}
}
add_action( 'cmaps_daily', 'cmaps_remove_version_files' );
add_action( 'upgrader_process_complete', 'cmaps_remove_version_files', 20 );


/* --- headers --- */

function cmaps_security_headers() {
	if ( headers_sent() ) {
		return;
	}
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
	header( 'X-Content-Type-Options: nosniff' );
	// scripts aren't restricted here because Divi relies on inline ones
	header( "Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; upgrade-insecure-requests" );
}
add_action( 'send_headers', 'cmaps_security_headers' );
add_action( 'login_init', 'cmaps_security_headers' );
header_remove( 'X-Powered-By' );

// map submissions don't need an account, so they're capped per visitor instead
add_filter( 'gform_validation', function ( $result ) {
	if ( (int) $result['form']['id'] === (int) cmaps_opt( 'map_form' ) && ! current_user_can( CMAPS_CAP ) && ! cmaps_rate_hit( 'mapform', 5 ) ) {
		$result['is_valid'] = false;
		foreach ( $result['form']['fields'] as $field ) {
			if ( 3 === (int) $field->id ) {
				$field->failed_validation  = true;
				$field->validation_message = cmaps_wait_message();
			}
		}
	}
	return $result;
}, 5 );
