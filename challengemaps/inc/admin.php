<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_options_page( 'Challenge Maps', 'Challenge Maps', 'manage_options', 'challengemaps', 'cmaps_settings_page' );
} );

add_action( 'admin_post_cmaps_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'cmaps_save' );
	$in  = isset( $_POST['cm'] ) ? wp_unslash( $_POST['cm'] ) : array();
	$new = array();

	foreach ( array( 'approval', 'ticker', 'logo', 'fonts', 'emoji', 'migrate', 'dropdown' ) as $k ) {
		$new[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
	}
	foreach ( array( 'diff_slug', 'score_slug' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$new[ $k ] = sanitize_title( $in[ $k ] );
		}
	}
	foreach ( array( 'score_form', 'f_player', 'f_discord', 'f_tier', 'f_map', 'f_video', 'f_acc', 'f_style', 'f_speed' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$new[ $k ] = preg_replace( '/[^0-9.]/', '', $in[ $k ] );
		}
	}
	foreach ( array( 'rank_curve', 'rank_decay', 'rank_acc_floor', 'rank_speed' ) as $k ) {
		if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
			$new[ $k ] = (float) $in[ $k ];
		}
	}
	cmaps_set( $new );
	cmaps_sync_map_field();
	cmaps_lb_flush();
	wp_safe_redirect( admin_url( 'options-general.php?page=challengemaps&saved=1' ) );
	exit;
} );

add_action( 'admin_post_cmaps_tool', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'cmaps_tool' );
	$msg = '';

	switch ( isset( $_POST['tool'] ) ? $_POST['tool'] : '' ) {
		case 'setup':
			set_transient( 'cmaps_setup_msgs', cmaps_setup(), 300 );
			break;
		case 'maps':
			$r   = cmaps_rebuild_maps();
			$msg = is_wp_error( $r ) ? $r->get_error_message() : "Found $r maps.";
			break;
		case 'rematch':
			$n = 0;
			if ( cmaps_gf() ) {
				foreach ( cmaps_all_entries( array( 'status' => 'active' ) ) as $e ) {
					if ( ! gform_get_meta( $e['id'], 'cm_sr_manual' ) && cmaps_match_sr( $e, true ) ) {
						$n++;
					}
				}
			}
			cmaps_lb_flush();
			$msg = "Matched $n scores.";
			break;
		case 'logo':
			$r   = cmaps_make_logo();
			$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Logo done (' . size_format( $r['before'] ) . ' to ' . size_format( $r['after'] ) . ').';
			break;
	}
	if ( $msg ) {
		set_transient( 'cmaps_tool_msg', $msg, 60 );
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=challengemaps' ) );
	exit;
} );

function cmaps_settings_page() {
	$s   = cmaps_settings( true );
	$msg = get_transient( 'cmaps_tool_msg' );
	delete_transient( 'cmaps_tool_msg' );

	$maps    = get_option( 'cmaps_maps' );
	$box = function ( $key, $label ) use ( $s ) {
		echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="cm[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	};
	?>
	<div class="wrap">
		<h1>Challenge Maps</h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
		<?php endif; ?>
		<?php if ( $msg ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>

		<?php foreach ( cmaps_check_score_form() as $problem ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $problem ); ?> Fix the field numbers below.</p></div>
		<?php endforeach; ?>

		<p>
			<?php $pc = cmaps_pending_counts(); ?>
			<?php echo (int) $pc['scores']; ?> score(s) and <?php echo (int) $pc['maps']; ?> map(s) waiting -
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cm-review' ) ); ?>">open Review</a>.
			Give someone the "Map Reviewer" role (Users) to let them review without access to anything else.
		</p>
		<p><?php echo ! empty( $maps['maps'] ) ? count( $maps['maps'] ) . ' maps loaded from the tier pages.' : 'Maps not loaded yet.'; ?></p>
		<p>
			<?php
			$csv_status = array();
			foreach ( array_keys( cmaps_tiers() ) as $slug ) {
				$path         = cmaps_csv_path_for_tier( $slug );
				$csv_status[] = cmaps_tier_name( $slug ) . ': ' . ( is_wp_error( $path ) ? $path->get_error_message() : basename( $path ) . ' ok' );
			}
			echo 'New maps get added to: ' . esc_html( implode( ' / ', $csv_status ) );
			?>
		</p>

		<h2>Discord sign in</h2>
		<?php if ( cmaps_discord_ready() ) : ?>
			<p>Set up. Players sign in with Discord, and scores need an account.</p>
		<?php else : ?>
			<p><strong>Not set up yet</strong>, so the score form works without an account for now. To turn it on:</p>
			<ol>
				<li>Make an application at discord.com/developers/applications, then under OAuth2 add this redirect: <code><?php echo esc_html( cmaps_discord_redirect_uri() ); ?></code></li>
				<li>Put its Client ID and Client Secret in <code>wp-config.php</code> (above "That's all, stop editing"), or as environment variables with the same names in your host's panel:
					<pre>define( 'CMAPS_DISCORD_CLIENT_ID', '...' );
define( 'CMAPS_DISCORD_CLIENT_SECRET', '...' );</pre>
				</li>
			</ol>
			<p>They're only read from there, never saved in the database or shown on any page.</p>
		<?php endif; ?>
		<p>Login limit: 5 tries per 10 minutes per IP. The site sees your IP as <code><?php echo esc_html( cmaps_ip() ); ?></code> (it should match your real IP, if it doesn't the limit is shared by everyone).</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmaps_tool' ); ?>
			<input type="hidden" name="action" value="cmaps_tool">
			<button class="button" name="tool" value="setup">Run setup again</button>
			<button class="button" name="tool" value="maps">Reload maps</button>
			<button class="button" name="tool" value="rematch">Re-match SR on all scores</button>
			<button class="button" name="tool" value="logo">Redo logo</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmaps_save' ); ?>
			<input type="hidden" name="action" value="cmaps_save">

			<h2>Options</h2>
			<?php
			$box( 'approval', 'Scores need approving before they show' );
			$box( 'dropdown', 'Map Name on the score form is a dropdown of ranked maps' );
			$box( 'ticker', 'Keep the Live Clears bar up to date' );
			$box( 'logo', 'Use the small logo' );
			$box( 'fonts', 'Trim Google Fonts' );
			$box( 'emoji', 'Remove emoji script' );
			$box( 'migrate', 'Remove jQuery Migrate (test the site after turning on)' );
			?>

			<h2>Score form</h2>
			<table class="form-table">
				<?php
				$fields = array(
					'diff_slug'  => 'Difficulties page slug',
					'score_slug' => 'Submit a Score page slug',
					'score_form' => 'Form ID',
					'f_player'   => 'Player Name',
					'f_discord'  => 'Discord',
					'f_tier'     => 'Difficulty',
					'f_map'      => 'Map Name',
					'f_video'    => 'Youtube Link',
					'f_acc'      => 'Accuracy',
					'f_style'    => 'Playstyle',
					'f_speed'    => 'Speed',
				);
				foreach ( $fields as $k => $label ) {
					echo '<tr><th>' . esc_html( $label ) . '</th><td><input class="regular-text" style="max-width:200px" name="cm[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '"></td></tr>';
				}
				?>
			</table>

			<h2>Rankings</h2>
			<p>Points = 10 &times; (SR / 10)<sup>curve</sup> &times; accuracy (0.5 at the floor, 1 at 100%) &times; (1 + speed step &times; per step). Totals add each player's best pass per map, best first, each one multiplied by the falloff once more than the one before.</p>
			<table class="form-table">
				<?php
				$rank = array(
					'rank_curve'     => array( 'Curve', 'How much more harder maps are worth. 2.2 = a 40★ is about 21x a 10★.' ),
					'rank_acc_floor' => array( 'Accuracy floor (%)', 'At or under this, a pass keeps half its points.' ),
					'rank_speed'     => array( 'Per speed step', '0.1 = S+ is worth 10% more, S- 10% less.' ),
					'rank_decay'     => array( 'Falloff', '0.95 = each pass after your best counts 5% less than the one before.' ),
				);
				foreach ( $rank as $k => $r ) {
					echo '<tr><th>' . esc_html( $r[0] ) . '</th><td><input type="number" step="0.01" style="width:100px" name="cm[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '"><p class="description">' . esc_html( $r[1] ) . '</p></td></tr>';
				}
				?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
