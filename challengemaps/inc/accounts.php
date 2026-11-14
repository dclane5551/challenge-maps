<?php
/*
 * Player accounts, signed in with Discord.
 *
 * The Discord app id and secret only ever come from the server: an
 * environment variable or a constant in wp-config.php, never the database.
 *
 *   CMAPS_DISCORD_CLIENT_ID
 *   CMAPS_DISCORD_CLIENT_SECRET
 *
 * Players get the "Player" role (can't do anything in wp-admin), and their
 * scores are tied to the account with the cm_user_id entry meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cmaps_secret( $name ) {
	$v = getenv( $name );
	if ( false === $v || '' === $v ) {
		$v = defined( $name ) ? constant( $name ) : '';
	}
	return trim( (string) $v );
}

function cmaps_discord_ready() {
	return '' !== cmaps_secret( 'CMAPS_DISCORD_CLIENT_ID' ) && '' !== cmaps_secret( 'CMAPS_DISCORD_CLIENT_SECRET' );
}

function cmaps_discord_redirect_uri() {
	return home_url( '/discord-callback/' );
}

function cmaps_login_url( $back = '' ) {
	$back = $back ? $back : home_url( add_query_arg( array() ) );
	return add_query_arg( 'redirect_to', rawurlencode( $back ), home_url( '/discord-login/' ) );
}

function cmaps_is_player( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	return $user && $user->exists() && in_array( 'cm_player', (array) $user->roles, true ) && ! $user->has_cap( 'edit_posts' ) && ! $user->has_cap( CMAPS_CAP );
}

function cmaps_setup_player_role() {
	if ( ! get_role( 'cm_player' ) ) {
		add_role( 'cm_player', 'Player', array( 'read' => true ) );
	}
}


/* --- routes --- */

add_action( 'init', function () {
	add_rewrite_rule( '^discord-login/?$', 'index.php?cm_auth=login', 'top' );
	add_rewrite_rule( '^discord-callback/?$', 'index.php?cm_auth=callback', 'top' );
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'cm_auth';
	return $vars;
} );

add_action( 'template_redirect', function () {
	$what = get_query_var( 'cm_auth' );
	if ( ! $what ) {
		return;
	}
	cmaps_no_cache();
	if ( ! cmaps_discord_ready() ) {
		cmaps_auth_fail( 'Discord sign in isn\'t set up yet.' );
	}
	if ( 'login' === $what ) {
		cmaps_discord_start();
	}
	cmaps_discord_callback();
}, 1 );

function cmaps_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'challenge maps dynamic page' );
	nocache_headers();
}

function cmaps_auth_fail( $msg ) {
	wp_die( esc_html( $msg ), 'Sign in', array(
		'response'  => 400,
		'link_url'  => home_url( '/' ),
		'link_text' => 'Back to the site',
	) );
}

function cmaps_discord_start() {
	if ( ! cmaps_rate_hit( 'discord' ) ) {
		cmaps_auth_fail( cmaps_wait_message() );
	}
	$back  = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_GET['redirect_to'] ), home_url( '/' ) ) : home_url( '/' );
	$state = wp_generate_password( 40, false, false );

	set_transient( 'cmaps_oauth_' . hash( 'sha256', $state ), $back, 10 * MINUTE_IN_SECONDS );
	setcookie( 'cm_oauth', $state, array(
		'expires'  => time() + 10 * MINUTE_IN_SECONDS,
		'path'     => '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );

	wp_redirect( 'https://discord.com/oauth2/authorize?' . http_build_query( array(
		'response_type' => 'code',
		'client_id'     => cmaps_secret( 'CMAPS_DISCORD_CLIENT_ID' ),
		'scope'         => 'identify',
		'state'         => $state,
		'redirect_uri'  => cmaps_discord_redirect_uri(),
		'prompt'        => 'none',
	), '', '&', PHP_QUERY_RFC3986 ) );
	exit;
}

function cmaps_discord_callback() {
	if ( cmaps_rate_limited( 'discord', 10 ) ) {
		cmaps_auth_fail( cmaps_wait_message() );
	}
	$state  = isset( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : '';
	$cookie = isset( $_COOKIE['cm_oauth'] ) ? (string) wp_unslash( $_COOKIE['cm_oauth'] ) : '';
	$key    = 'cmaps_oauth_' . hash( 'sha256', $state );
	$back   = get_transient( $key );

	setcookie( 'cm_oauth', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	delete_transient( $key );

	// the state has to match the cookie set when they clicked Sign in, so nobody can be signed in from someone else's link
	if ( '' === $state || '' === $cookie || ! hash_equals( $cookie, $state ) || false === $back ) {
		cmaps_rate_hit( 'discord', 10 );
		cmaps_auth_fail( 'That sign in link expired. Try again.' );
	}
	if ( isset( $_GET['error'] ) ) {
		wp_safe_redirect( $back );
		exit;
	}
	$code = isset( $_GET['code'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $_GET['code'] ) : '';
	if ( '' === $code ) {
		cmaps_auth_fail( 'Discord didn\'t send a sign in code. Try again.' );
	}

	$res = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
		'timeout' => 10,
		'body'    => array(
			'client_id'     => cmaps_secret( 'CMAPS_DISCORD_CLIENT_ID' ),
			'client_secret' => cmaps_secret( 'CMAPS_DISCORD_CLIENT_SECRET' ),
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => cmaps_discord_redirect_uri(),
		),
	) );
	$token = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $token['access_token'] ) ) {
		cmaps_rate_hit( 'discord', 10 );
		cmaps_auth_fail( 'Couldn\'t sign in with Discord. Try again.' );
	}

	$res = wp_remote_get( 'https://discord.com/api/users/@me', array(
		'timeout' => 10,
		'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
	) );
	$me = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $me['id'] ) || ! preg_match( '/^\d{5,25}$/', (string) $me['id'] ) || empty( $me['username'] ) ) {
		cmaps_auth_fail( 'Couldn\'t read your Discord account. Try again.' );
	}

	$user = cmaps_player_from_discord( $me );
	if ( is_wp_error( $user ) ) {
		cmaps_auth_fail( $user->get_error_message() );
	}

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( $back );
	exit;
}

// finds or makes the account for a Discord user
function cmaps_player_from_discord( $me ) {
	$id       = (string) $me['id'];
	$username = sanitize_user( (string) $me['username'], true );
	$name     = sanitize_text_field( ! empty( $me['global_name'] ) ? $me['global_name'] : $me['username'] );
	$avatar   = ! empty( $me['avatar'] ) && preg_match( '/^(a_)?[a-f0-9]{32}$/', $me['avatar'] ) ? $me['avatar'] : '';
	$name     = '' !== $name ? $name : $username;

	$found = get_users( array( 'meta_key' => 'cm_discord_id', 'meta_value' => $id, 'number' => 1 ) );
	$user  = $found ? $found[0] : null;

	if ( $user ) {
		// staff log in with their password. a Discord account can't be a way into wp-admin
		if ( ! cmaps_is_player( $user ) ) {
			return new WP_Error( 'cmaps', 'This account has to sign in on the normal login page.' );
		}
		$old = get_user_meta( $user->ID, 'cm_discord_username', true );
		if ( $user->display_name !== $name ) {
			wp_update_user( array( 'ID' => $user->ID, 'display_name' => $name, 'nickname' => $name ) );
		}
	} else {
		cmaps_setup_player_role();
		$uid = wp_insert_user( array(
			'user_login'    => 'discord_' . $id,
			'user_pass'     => wp_generate_password( 40, true, true ),
			'user_nicename' => cmaps_player_slug( $username ),
			'display_name'  => $name,
			'nickname'      => $name,
			'role'          => 'cm_player',
		) );
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'cmaps', 'Couldn\'t make your account. Try again.' );
		}
		update_user_meta( $uid, 'cm_discord_id', $id );
		update_user_meta( $uid, 'show_admin_bar_front', 'false' );
		delete_transient( 'cmaps_search_players' );
		$user = get_userdata( $uid );
		$old  = '';
	}

	update_user_meta( $user->ID, 'cm_discord_username', $username );
	update_user_meta( $user->ID, 'cm_discord_avatar', $avatar );

	if ( $old !== $username ) {
		cmaps_claim_scores( $user->ID, $username );
	}
	return $user;
}

function cmaps_player_slug( $username ) {
	$slug = sanitize_title( $username );
	return '' !== $slug ? $slug : 'player';
}

// scores sent before accounts existed, with this Discord @ on them
function cmaps_clean_discord( $s ) {
	$s = strtolower( trim( (string) $s ) );
	$s = preg_replace( '/#\d{1,4}$/', '', ltrim( $s, '@' ) );
	return trim( $s );
}

function cmaps_claim_scores( $user_id, $username ) {
	if ( ! cmaps_gf() || '' === cmaps_clean_discord( $username ) ) {
		return 0;
	}
	$n = 0;
	foreach ( cmaps_all_entries( array( 'status' => 'active' ) ) as $e ) {
		if ( gform_get_meta( $e['id'], 'cm_user_id' ) ) {
			continue;
		}
		if ( cmaps_clean_discord( rgar( $e, cmaps_opt( 'f_discord' ) ) ) === cmaps_clean_discord( $username ) ) {
			gform_update_meta( $e['id'], 'cm_user_id', (int) $user_id );
			$n++;
		}
	}
	if ( $n ) {
		cmaps_lb_flush();
	}
	return $n;
}

function cmaps_avatar_url( $user_id, $size = 64 ) {
	$id   = (string) get_user_meta( $user_id, 'cm_discord_id', true );
	$hash = (string) get_user_meta( $user_id, 'cm_discord_avatar', true );
	if ( $id && $hash ) {
		return 'https://cdn.discordapp.com/avatars/' . rawurlencode( $id ) . '/' . rawurlencode( $hash ) . '.png?size=' . (int) $size;
	}
	$n = $id ? ( (int) $id >> 22 ) % 6 : 0;
	return 'https://cdn.discordapp.com/embed/avatars/' . $n . '.png';
}


/* --- players stay out of wp-admin --- */

add_action( 'admin_init', function () {
	if ( cmaps_is_player() && ! wp_doing_ajax() && 'admin-post.php' !== $GLOBALS['pagenow'] ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
} );

add_filter( 'show_admin_bar', function ( $show ) {
	return cmaps_is_player() ? false : $show;
} );


/* --- nav: Profile --- */

// signed in with Discord: their profile. signed out: Discord sign in. staff: their wp-admin profile
function cmaps_profile_link() {
	$user = wp_get_current_user();
	if ( $user->exists() && get_user_meta( $user->ID, 'cm_discord_id', true ) ) {
		return array( cmaps_player_url( $user->ID ), cmaps_avatar_url( $user->ID, 32 ) );
	}
	if ( $user->exists() ) {
		return array( admin_url( 'profile.php' ), '' );
	}
	return array( cmaps_login_url(), '' );
}

add_filter( 'wp_nav_menu_items', function ( $items, $args ) {
	if ( ! in_array( $args->theme_location, array( 'primary-menu', 'primary' ), true ) ) {
		return $items;
	}
	list( $url, $avatar ) = cmaps_profile_link();
	$img    = $avatar ? '<img src="' . esc_url( $avatar ) . '" alt="" width="22" height="22" loading="lazy"> ' : '';
	$items .= '<li class="menu-item cm-nav-account"><a href="' . esc_url( $url ) . '"' . ( $avatar ? '' : ' rel="nofollow"' ) . '>' . $img . 'Profile</a></li>';
	return $items;
}, 10, 2 );


/* --- score form needs an account --- */

function cmaps_scores_need_login() {
	return cmaps_discord_ready();
}

function cmaps_current_player() {
	$user = wp_get_current_user();
	if ( ! $user->exists() || ! get_user_meta( $user->ID, 'cm_discord_id', true ) ) {
		return null;
	}
	return array(
		'id'      => $user->ID,
		'name'    => $user->display_name,
		'discord' => (string) get_user_meta( $user->ID, 'cm_discord_username', true ),
	);
}

add_filter( 'gform_get_form_filter', function ( $html, $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() || ! cmaps_scores_need_login() || cmaps_current_player() ) {
		return $html;
	}
	cmaps_no_cache();
	if ( is_user_logged_in() ) {
		return '<div class="cm-signin"><p>Scores are tied to a Discord account. Sign out and sign back in with Discord to submit one.</p></div>';
	}
	return '<div class="cm-signin">'
		. '<p>Sign in with Discord to submit a score. Your passes show up on your profile and count towards the rankings.</p>'
		. '<a class="cm-btn cm-btn-discord" href="' . esc_url( cmaps_login_url( get_permalink() ) ) . '" rel="nofollow">Sign in with Discord</a>'
		. '</div>';
}, 10, 2 );

// name + Discord come from the account, whatever gets posted
function cmaps_force_player_fields( $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() || ! cmaps_scores_need_login() ) {
		return $form;
	}
	$p = cmaps_current_player();
	if ( $p ) {
		$_POST[ 'input_' . str_replace( '.', '_', cmaps_opt( 'f_player' ) ) ]  = $p['name'];
		$_POST[ 'input_' . str_replace( '.', '_', cmaps_opt( 'f_discord' ) ) ] = $p['discord'];
	}
	return $form;
}
add_filter( 'gform_pre_validation', 'cmaps_force_player_fields', 5 );
add_filter( 'gform_pre_submission_filter', 'cmaps_force_player_fields', 5 );

add_filter( 'gform_pre_render', function ( $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() || ! cmaps_scores_need_login() ) {
		return $form;
	}
	$p = cmaps_current_player();
	if ( ! $p ) {
		return $form;
	}
	foreach ( $form['fields'] as $field ) {
		$fid = (string) $field->id;
		if ( $fid === (string) cmaps_opt( 'f_player' ) || $fid === (string) cmaps_opt( 'f_discord' ) ) {
			$field->defaultValue = $fid === (string) cmaps_opt( 'f_player' ) ? $p['name'] : $p['discord'];
			$field->cssClass     = trim( $field->cssClass . ' cm-locked' );
			$field->description  = 'From your Discord account.';
		}
	}
	return $form;
}, 20 );

// signed in, and no more than 10 scores an hour
add_filter( 'gform_validation', function ( $result ) {
	if ( (int) $result['form']['id'] !== cmaps_score_form() || ! cmaps_scores_need_login() ) {
		return $result;
	}
	$p   = cmaps_current_player();
	$msg = '';
	if ( ! $p ) {
		$msg = 'Sign in with Discord to submit a score.';
	} else {
		$times = array_filter( (array) get_user_meta( $p['id'], 'cm_score_times', true ), function ( $t ) {
			return (int) $t > time() - HOUR_IN_SECONDS;
		} );
		if ( count( $times ) >= (int) apply_filters( 'cmaps_scores_per_hour', 10 ) ) {
			$msg = 'That\'s a lot of scores in one hour. Wait a bit and send the rest.';
		}
	}
	if ( $msg ) {
		$result['is_valid'] = false;
		foreach ( $result['form']['fields'] as $field ) {
			if ( (string) $field->id === (string) cmaps_opt( 'f_map' ) ) {
				$field->failed_validation  = true;
				$field->validation_message = $msg;
			}
		}
	}
	return $result;
}, 20 );

add_action( 'gform_after_submission', function ( $entry, $form ) {
	if ( (int) $form['id'] !== cmaps_score_form() ) {
		return;
	}
	$p = cmaps_current_player();
	if ( ! $p ) {
		return;
	}
	gform_update_meta( $entry['id'], 'cm_user_id', $p['id'] );
	$times   = array_filter( (array) get_user_meta( $p['id'], 'cm_score_times', true ), function ( $t ) {
		return (int) $t > time() - HOUR_IN_SECONDS;
	} );
	$times[] = time();
	update_user_meta( $p['id'], 'cm_score_times', array_values( $times ) );
	cmaps_lb_flush();
}, 20, 2 );
