<?php
/**
 * Plugin Name: Challenge Maps
 * Description: Difficulties page, map submissions, score leaderboard, player profiles, rankings and site tweaks.
 * Version: 1.3.1
 * Author: govector
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMAPS_VER', '1.3.1' );
define( 'CMAPS_FILE', __FILE__ );
define( 'CMAPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMAPS_URL', plugin_dir_url( __FILE__ ) );

require CMAPS_DIR . 'inc/helpers.php';
require CMAPS_DIR . 'inc/security.php';
require CMAPS_DIR . 'inc/maps.php';
require CMAPS_DIR . 'inc/speed.php';
require CMAPS_DIR . 'inc/pages.php';
require CMAPS_DIR . 'inc/map-form.php';
require CMAPS_DIR . 'inc/leaderboard.php';
require CMAPS_DIR . 'inc/score-form.php';
require CMAPS_DIR . 'inc/review.php';
require CMAPS_DIR . 'inc/accounts.php';
require CMAPS_DIR . 'inc/rankings.php';
require CMAPS_DIR . 'inc/profiles.php';
require CMAPS_DIR . 'inc/search.php';
require CMAPS_DIR . 'inc/admin.php';

register_activation_hook( __FILE__, 'cmaps_activate' );
register_deactivation_hook( __FILE__, 'cmaps_deactivate' );

function cmaps_activate() {
	// older build of this plugin used a different folder
	$old = 'challengemaps-toolkit/challengemaps-toolkit.php';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_plugin_active( $old ) ) {
		deactivate_plugins( $old, true );
	}
	cmaps_migrate_old_options();

	if ( ! wp_next_scheduled( 'cmaps_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cmaps_daily' );
	}
	update_option( 'cmaps_needs_setup', 1 );
}

function cmaps_deactivate() {
	wp_clear_scheduled_hook( 'cmaps_daily' );
	wp_clear_scheduled_hook( 'cmaps_rebuild_maps' );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'cmaps', CMAPS_URL . 'css/style.css', array(), cmaps_ver( 'css/style.css' ) );
}, 20 );
