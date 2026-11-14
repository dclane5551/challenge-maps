<?php
/*
 * Review page - one place to approve scores and new maps.
 *
 * Scores:  approve -> leaderboard, Live Clears and the Camlock/Spin Pass
 *          columns on the tier tables pick it up straight away.
 * Maps:    approve -> a row is added to that tier's CSV, so it shows in the
 *          table (and everywhere that counts maps).
 *
 * Anyone with the "Map Reviewer" role (or an admin) can use it. Reviewers
 * can't see anything else in wp-admin.
 *
 * Status meta on entries:
 *   scores  cm_approved   0/missing = pending, 1 = approved, 2 = rejected
 *   maps    cm_status     0/missing = pending, 1 = added,    2 = rejected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CMAPS_CAP = 'cmaps_review';

function cmaps_setup_roles() {
	if ( ! get_role( 'cm_reviewer' ) ) {
		add_role( 'cm_reviewer', 'Map Reviewer', array( 'read' => true, CMAPS_CAP => true ) );
	}
	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( CMAPS_CAP ) ) {
		$admin->add_cap( CMAPS_CAP );
	}
}

// reviewers land on the review page after logging in
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( $user instanceof WP_User && $user->has_cap( CMAPS_CAP ) && ! $user->has_cap( 'edit_posts' ) ) {
		return admin_url( 'admin.php?page=cm-review' );
	}
	return $to;
}, 10, 3 );


/* --- pending lists --- */

// filtered here rather than in the query so entries with no status saved yet still count as pending
function cmaps_pending_scores() {
	if ( ! cmaps_gf() ) {
		return array();
	}
	return array_values( array_filter( cmaps_all_entries( array( 'status' => 'active' ) ), function ( $e ) {
		return ! in_array( (int) gform_get_meta( $e['id'], 'cm_approved' ), array( 1, 2 ), true );
	} ) );
}

function cmaps_pending_maps() {
	$id = (int) cmaps_opt( 'map_form' );
	if ( ! cmaps_gf() || ! $id ) {
		return array();
	}
	$out    = array();
	$offset = 0;
	do {
		$batch = GFAPI::get_entries( $id, array( 'status' => 'active' ), array( 'key' => 'date_created', 'direction' => 'ASC' ), array( 'offset' => $offset, 'page_size' => 200 ) );
		if ( is_wp_error( $batch ) ) {
			break;
		}
		$out     = array_merge( $out, $batch );
		$offset += 200;
	} while ( count( $batch ) === 200 );

	return array_values( array_filter( $out, function ( $e ) {
		return ! in_array( (int) gform_get_meta( $e['id'], 'cm_status' ), array( 1, 2 ), true );
	} ) );
}

function cmaps_pending_counts() {
	$c = get_transient( 'cmaps_pending' );
	if ( ! is_array( $c ) ) {
		$c = array( 'scores' => count( cmaps_pending_scores() ), 'maps' => count( cmaps_pending_maps() ) );
		set_transient( 'cmaps_pending', $c, 5 * MINUTE_IN_SECONDS );
	}
	return $c;
}

function cmaps_pending_flush() {
	delete_transient( 'cmaps_pending' );
}
add_action( 'gform_after_submission', 'cmaps_pending_flush' );

// status column on the map form's entries
add_filter( 'gform_entry_meta', function ( $meta, $form_id ) {
	if ( (int) $form_id === (int) cmaps_opt( 'map_form' ) ) {
		$meta['cm_status'] = array(
			'label'                      => 'Status',
			'is_numeric'                 => true,
			'is_default_column'          => true,
			'update_entry_meta_callback' => null,
			'filter'                     => array( 'operators' => array( 'is', 'isnot' ) ),
		);
	}
	return $meta;
}, 10, 2 );

add_filter( 'gform_entries_field_value', function ( $value, $form_id, $field_id ) {
	if ( (int) $form_id === (int) cmaps_opt( 'map_form' ) && 'cm_status' === $field_id ) {
		return 1 === (int) $value ? 'Added' : ( 2 === (int) $value ? 'Rejected' : 'Pending' );
	}
	if ( (int) $form_id === cmaps_score_form() && 'cm_approved' === $field_id && 2 === (int) $value ) {
		return 'Rejected';
	}
	return $value;
}, 20, 3 );


/* --- menu + admin bar --- */

add_action( 'admin_menu', function () {
	$c     = cmaps_pending_counts();
	$n     = $c['scores'] + $c['maps'];
	$title = 'Review' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
	add_menu_page( 'Review', $title, CMAPS_CAP, 'cm-review', 'cmaps_review_page', 'dashicons-yes-alt', 3 );
} );

add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! current_user_can( CMAPS_CAP ) ) {
		return;
	}
	$c = cmaps_pending_counts();
	$n = $c['scores'] + $c['maps'];
	$bar->add_node( array(
		'id'    => 'cm-review',
		'title' => 'Review' . ( $n ? ' (' . (int) $n . ')' : '' ),
		'href'  => admin_url( 'admin.php?page=cm-review' ),
	) );
}, 80 );


/* --- the page --- */

function cmaps_youtube_id( $url ) {
	if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) {
		return $m[1];
	}
	return '';
}

function cmaps_video_html( $url ) {
	$url = cmaps_video( $url );
	if ( ! $url ) {
		return '<p class="cmr-novid">No video link.</p>';
	}
	$id = cmaps_youtube_id( $url );
	if ( ! $id ) {
		return '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Open video</a></p>';
	}
	return '<div class="cmr-video"><iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '" loading="lazy" allowfullscreen title="Video"></iframe></div>'
		. '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Open on YouTube</a></p>';
}

function cmaps_map_select( $name, $selected, $tier_first = '' ) {
	$maps = cmaps_get_maps();
	$by   = array();
	foreach ( $maps as $m ) {
		$by[ $m['tier'] ][] = $m;
	}
	$html = '<select name="' . esc_attr( $name ) . '" class="cmr-map-select" required><option value="">Pick the map...</option>';
	$tiers = cmaps_tiers();
	if ( $tier_first && isset( $tiers[ $tier_first ] ) ) {
		$tiers = array( $tier_first => $tiers[ $tier_first ] ) + $tiers;
	}
	foreach ( $tiers as $slug => $t ) {
		if ( empty( $by[ $slug ] ) ) {
			continue;
		}
		usort( $by[ $slug ], function ( $a, $b ) {
			return strcasecmp( $a['song'], $b['song'] );
		} );
		$html .= '<optgroup label="' . esc_attr( $t['name'] ) . '">';
		foreach ( $by[ $slug ] as $m ) {
			$html .= '<option value="' . esc_attr( $m['key'] ) . '"' . selected( $selected, $m['key'], false ) . '>' . esc_html( $m['key'] . ' - ' . cmaps_fmt_sr( $m['sr'] ) . '★' ) . '</option>';
		}
		$html .= '</optgroup>';
	}
	return $html . '</select>';
}

function cmaps_review_page() {
	if ( ! current_user_can( CMAPS_CAP ) ) {
		wp_die( 'Not allowed' );
	}
	$tab = isset( $_GET['tab'] ) && 'maps' === $_GET['tab'] ? 'maps' : 'scores';
	$c   = cmaps_pending_counts();
	$msg = get_transient( 'cmaps_review_msg_' . get_current_user_id() );
	delete_transient( 'cmaps_review_msg_' . get_current_user_id() );
	?>
	<div class="wrap cmr">
		<h1>Review</h1>
		<?php if ( $msg ) : ?>
			<div class="notice notice-<?php echo esc_attr( $msg[0] ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper">
			<a class="nav-tab<?php echo 'scores' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=cm-review' ) ); ?>">Scores (<?php echo (int) $c['scores']; ?>)</a>
			<a class="nav-tab<?php echo 'maps' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=cm-review&tab=maps' ) ); ?>">Maps (<?php echo (int) $c['maps']; ?>)</a>
		</nav>

		<?php
		if ( 'maps' === $tab ) {
			cmaps_review_maps();
		} else {
			cmaps_review_scores();
		}
		?>
	</div>
	<?php
}

function cmaps_review_form_open( $type, $entry_id ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cmr-form">';
	echo '<input type="hidden" name="action" value="cmaps_review">';
	echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
	echo '<input type="hidden" name="entry" value="' . (int) $entry_id . '">';
	wp_nonce_field( 'cmaps_review_' . $type . '_' . $entry_id );
}

function cmaps_review_buttons() {
	echo '<div class="cmr-actions">';
	echo '<button class="button button-primary button-large" name="do" value="approve">Approve</button>';
	echo '<details class="cmr-reject"><summary class="button button-large">Reject...</summary>';
	echo '<input type="text" name="reason" placeholder="Reason (optional)"> <button class="button" name="do" value="reject" formnovalidate>Reject</button>';
	echo '</details></div></form>';
}

function cmaps_review_scores() {
	$entries = cmaps_pending_scores();
	if ( ! $entries ) {
		echo '<p class="cmr-empty">No scores waiting.</p>';
		return;
	}
	foreach ( array_reverse( $entries ) as $e ) {
		$tier  = cmaps_tier_slug( rgar( $e, cmaps_opt( 'f_tier' ) ) );
		$match = gform_get_meta( $e['id'], 'cm_map_match' );
		if ( ! $match ) {
			$found = cmaps_find_map( rgar( $e, cmaps_opt( 'f_map' ) ), $tier );
			$match = $found ? $found['key'] : '';
		}
		$speed = cmaps_speed_tag( rgar( $e, cmaps_opt( 'f_speed' ) ) );
		?>
		<div class="cmr-card">
			<div class="cmr-info">
				<h2><?php echo esc_html( rgar( $e, cmaps_opt( 'f_player' ) ) ); ?></h2>
				<p class="cmr-sub"><?php echo esc_html( rgar( $e, cmaps_opt( 'f_discord' ) ) ); ?> &middot; <?php echo esc_html( human_time_diff( strtotime( $e['date_created'] . ' UTC' ) ) ); ?> ago</p>
				<table class="cmr-facts">
					<tr><th>Typed map</th><td><?php echo esc_html( rgar( $e, cmaps_opt( 'f_map' ) ) ); ?> <span class="cmr-muted">(<?php echo esc_html( rgar( $e, cmaps_opt( 'f_tier' ) ) ); ?>)</span></td></tr>
					<tr><th>Accuracy</th><td><?php echo esc_html( rgar( $e, cmaps_opt( 'f_acc' ) ) ); ?></td></tr>
					<tr><th>Playstyle</th><td><?php echo esc_html( rgar( $e, cmaps_opt( 'f_style' ) ) ); ?></td></tr>
					<tr><th>Speed</th><td><?php echo esc_html( $speed ? $speed : '-' ); ?></td></tr>
				</table>
				<?php cmaps_review_form_open( 'score', $e['id'] ); ?>
					<label class="cmr-label">Ranked map
						<?php echo cmaps_map_select( 'map_key', $match, $tier ); ?>
					</label>
					<?php if ( ! $match ) : ?>
						<p class="cmr-warn">Couldn't match the typed name to a ranked map, pick it above.</p>
					<?php endif; ?>
				<?php cmaps_review_buttons(); ?>
			</div>
			<div class="cmr-media"><?php echo cmaps_video_html( rgar( $e, cmaps_opt( 'f_video' ) ) ); ?></div>
		</div>
		<?php
	}
}

function cmaps_map_entry_values( $e ) {
	$patterns = array();
	foreach ( $e as $k => $v ) {
		if ( preg_match( '/^8\.\d+$/', (string) $k ) && '' !== $v ) {
			$patterns[] = $v;
		}
	}
	$file = rgar( $e, '9' );
	if ( $file && '[' === substr( $file, 0, 1 ) ) {
		$list = json_decode( $file, true );
		$file = is_array( $list ) && $list ? $list[0] : '';
	}
	return array(
		'mapper'   => rgar( $e, '1' ),
		'discord'  => rgar( $e, '2' ),
		'song'     => rgar( $e, '3' ),
		'tier'     => cmaps_tier_slug( rgar( $e, '4' ) ),
		'sr'       => rgar( $e, '5' ),
		'bpm'      => rgar( $e, '6' ),
		'length'   => rgar( $e, '7' ),
		'patterns' => implode( ', ', $patterns ),
		'download' => rgar( $e, '10' ) ? rgar( $e, '10' ) : $file,
		'file'     => $file,
		'video'    => rgar( $e, '11' ),
		'notes'    => rgar( $e, '12' ),
	);
}

function cmaps_review_maps() {
	$entries = cmaps_pending_maps();
	if ( ! $entries ) {
		echo '<p class="cmr-empty">No maps waiting.</p>';
		return;
	}
	foreach ( $entries as $e ) {
		$v = cmaps_map_entry_values( $e );
		?>
		<div class="cmr-card">
			<div class="cmr-info">
				<h2><?php echo esc_html( $v['song'] ); ?></h2>
				<p class="cmr-sub">by <?php echo esc_html( $v['mapper'] ); ?> &middot; <?php echo esc_html( $v['discord'] ); ?> &middot; <?php echo esc_html( human_time_diff( strtotime( $e['date_created'] . ' UTC' ) ) ); ?> ago</p>
				<?php if ( $v['notes'] ) : ?>
					<p class="cmr-notes"><?php echo nl2br( esc_html( $v['notes'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $v['file'] ) : ?>
					<p><a class="button" href="<?php echo esc_url( $v['file'] ); ?>">Download uploaded .sspm</a></p>
				<?php endif; ?>

				<?php cmaps_review_form_open( 'map', $e['id'] ); ?>
					<div class="cmr-grid">
						<label class="cmr-label cmr-wide">Song <input type="text" name="song" value="<?php echo esc_attr( $v['song'] ); ?>" required></label>
						<label class="cmr-label">Mapper <input type="text" name="mapper" value="<?php echo esc_attr( $v['mapper'] ); ?>" required></label>
						<label class="cmr-label">Tier
							<select name="tier" required>
								<?php foreach ( cmaps_tiers() as $slug => $t ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $v['tier'], $slug ); ?>><?php echo esc_html( $t['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="cmr-label">SR (final) <input type="number" name="sr" step="0.1" min="1" max="99" value="<?php echo esc_attr( $v['sr'] ); ?>" required></label>
						<label class="cmr-label">BPM <input type="text" name="bpm" value="<?php echo esc_attr( $v['bpm'] ); ?>"></label>
						<label class="cmr-label">Length <input type="text" name="length" value="<?php echo esc_attr( $v['length'] ); ?>"></label>
						<label class="cmr-label cmr-wide">Patterns <input type="text" name="patterns" value="<?php echo esc_attr( $v['patterns'] ); ?>"></label>
						<label class="cmr-label cmr-wide">Download URL <input type="url" name="download" value="<?php echo esc_attr( $v['download'] ); ?>" required></label>
					</div>
					<p class="cmr-muted">Approving adds it to the CSV for the tier picked above, so it shows in that table right away.</p>
				<?php cmaps_review_buttons(); ?>
			</div>
			<div class="cmr-media"><?php echo cmaps_video_html( $v['video'] ); ?></div>
		</div>
		<?php
	}
}


/* --- handling approve / reject --- */

add_action( 'admin_post_cmaps_review', function () {
	$type  = isset( $_POST['type'] ) && 'map' === $_POST['type'] ? 'map' : 'score';
	$entry = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
	check_admin_referer( 'cmaps_review_' . $type . '_' . $entry );
	if ( ! current_user_can( CMAPS_CAP ) ) {
		wp_die( 'Not allowed' );
	}
	$do     = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
	$e      = GFAPI::get_entry( $entry );
	$msg    = array( 'error', 'Something went wrong.' );

	if ( is_wp_error( $e ) ) {
		$msg = array( 'error', 'That entry no longer exists.' );
	} elseif ( 'score' === $type ) {
		if ( 'approve' === $do ) {
			$map = cmaps_map_by_key( isset( $_POST['map_key'] ) ? wp_unslash( $_POST['map_key'] ) : '' );
			if ( ! $map ) {
				$msg = array( 'error', 'Pick which ranked map the score is on.' );
			} else {
				gform_update_meta( $entry, 'cm_map_match', $map['key'] );
				gform_update_meta( $entry, 'cm_sr', $map['sr'] );
				gform_update_meta( $entry, 'cm_approved', 1 );
				gform_update_meta( $entry, 'cm_reviewed_by', get_current_user_id() );
				$msg = array( 'success', 'Approved ' . rgar( $e, cmaps_opt( 'f_player' ) ) . ' on ' . $map['song'] . '.' );
			}
		} elseif ( 'reject' === $do ) {
			gform_update_meta( $entry, 'cm_approved', 2 );
			gform_update_meta( $entry, 'cm_reject_reason', $reason );
			gform_update_meta( $entry, 'cm_reviewed_by', get_current_user_id() );
			$msg = array( 'success', 'Rejected.' );
		}
	} else {
		if ( 'approve' === $do ) {
			$row = array(
				'song'     => sanitize_text_field( wp_unslash( $_POST['song'] ?? '' ) ),
				'mapper'   => sanitize_text_field( wp_unslash( $_POST['mapper'] ?? '' ) ),
				'sr'       => (float) ( $_POST['sr'] ?? 0 ),
				'bpm'      => sanitize_text_field( wp_unslash( $_POST['bpm'] ?? '' ) ),
				'length'   => sanitize_text_field( wp_unslash( $_POST['length'] ?? '' ) ),
				'patterns' => sanitize_text_field( wp_unslash( $_POST['patterns'] ?? '' ) ),
				'download' => esc_url_raw( wp_unslash( $_POST['download'] ?? '' ) ),
			);
			$tier = cmaps_tier_slug( sanitize_key( $_POST['tier'] ?? '' ) );
			$res  = cmaps_add_map_to_csv( $tier, $row );
			if ( is_wp_error( $res ) ) {
				$msg = array( 'error', $res->get_error_message() );
			} else {
				gform_update_meta( $entry, 'cm_status', 1 );
				gform_update_meta( $entry, 'cm_added_key', $res );
				gform_update_meta( $entry, 'cm_reviewed_by', get_current_user_id() );
				$msg = array( 'success', 'Added ' . $row['song'] . ' to ' . cmaps_tier_name( $tier ) . '.' );
			}
		} elseif ( 'reject' === $do ) {
			gform_update_meta( $entry, 'cm_status', 2 );
			gform_update_meta( $entry, 'cm_reject_reason', $reason );
			gform_update_meta( $entry, 'cm_reviewed_by', get_current_user_id() );
			$msg = array( 'success', 'Rejected.' );
		}
	}

	cmaps_pending_flush();
	cmaps_lb_flush(); // also clears the page cache
	set_transient( 'cmaps_review_msg_' . get_current_user_id(), $msg, 60 );
	wp_safe_redirect( admin_url( 'admin.php?page=cm-review' . ( 'map' === $type ? '&tab=maps' : '' ) ) );
	exit;
} );


/* --- adding a map to a tier CSV --- */

function cmaps_csv_path_for_tier( $tier ) {
	$pages = cmaps_tier_pages();
	if ( empty( $pages[ $tier ] ) ) {
		return new WP_Error( 'cmaps', 'No page found for that tier.' );
	}
	$url = cmaps_tier_csv_url( $pages[ $tier ] );
	if ( ! $url ) {
		return new WP_Error( 'cmaps', 'That tier page doesn\'t use a [challenge_maps] CSV.' );
	}
	$up   = wp_get_upload_dir();
	$base = preg_replace( '#^https?:#', '', $up['baseurl'] );
	$rel  = preg_replace( '#^https?:#', '', $url );
	if ( 0 !== strpos( $rel, $base ) ) {
		return new WP_Error( 'cmaps', 'The CSV isn\'t in the media library, so it can\'t be edited from here.' );
	}
	$path = wp_normalize_path( urldecode( $up['basedir'] . substr( $rel, strlen( $base ) ) ) );
	if ( ! is_file( $path ) || ! is_writable( $path ) ) {
		return new WP_Error( 'cmaps', 'Can\'t write to ' . basename( $path ) . '.' );
	}
	return $path;
}

// a cell starting with = + - @ runs as a formula if the CSV is opened in Excel or Sheets.
// a ' in front stops that (the child theme hides it again on the site)
function cmaps_csv_safe( $v ) {
	$v = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $v );
	return preg_match( '/^[=+\-@]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
}

// returns the new map's key, or WP_Error
function cmaps_add_map_to_csv( $tier, $row ) {
	if ( ! $tier || '' === $row['song'] || $row['sr'] <= 0 || ! $row['download'] ) {
		return new WP_Error( 'cmaps', 'Song, tier, SR and download link are all needed.' );
	}
	$path = cmaps_csv_path_for_tier( $tier );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	$key = cmaps_map_key( $row );
	foreach ( cmaps_get_maps() as $m ) {
		if ( $m['key'] === $key ) {
			return new WP_Error( 'cmaps', 'That map is already on the sheet (' . cmaps_tier_name( $m['tier'] ) . ').' );
		}
	}

	$csv = file_get_contents( $path );
	$eol = false !== strpos( $csv, "\r\n" ) ? "\r\n" : "\n";
	$fh  = fopen( 'php://temp', 'r+' );
	fwrite( $fh, preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) );
	rewind( $fh );
	$header = fgetcsv( $fh, 0, ',', '"', '\\' );
	fclose( $fh );
	if ( ! $header ) {
		return new WP_Error( 'cmaps', 'Couldn\'t read the CSV header.' );
	}

	$values = array(
		'category'          => cmaps_tier_name( $tier ),
		'song'              => $row['song'],
		'bpm'               => $row['bpm'],
		'mapper'            => $row['mapper'],
		'sr'                => cmaps_fmt_sr( $row['sr'] ),
		'patterns'          => $row['patterns'],
		'download_url'      => $row['download'],
		'length'            => $row['length'],
		'best_pass_camlock' => 'NONE',
		'best_pass_spin'    => 'NONE',
	);
	$line = array();
	foreach ( $header as $h ) {
		$h      = strtolower( trim( $h ) );
		$line[] = cmaps_csv_safe( isset( $values[ $h ] ) ? $values[ $h ] : '' );
	}

	$fh = fopen( 'php://temp', 'r+' );
	fputcsv( $fh, $line, ',', '"', '\\' );
	rewind( $fh );
	$out = rtrim( stream_get_contents( $fh ), "\r\n" ) . $eol;
	fclose( $fh );

	// keep the previous version next to it, just in case
	copy( $path, preg_replace( '/\.csv$/i', '-previous.csv', $path ) );

	if ( '' !== $csv && ! preg_match( '/\R$/', $csv ) ) {
		$out = $eol . $out;
	}
	if ( false === file_put_contents( $path, $out, FILE_APPEND | LOCK_EX ) ) {
		return new WP_Error( 'cmaps', 'Couldn\'t save the CSV.' );
	}
	clearstatcache( true, $path );

	cmaps_rebuild_maps();
	return $key;
}


/* --- best passes on the tier tables --- */

/*
 * The theme's [challenge_maps] table runs each Camlock/Spin Pass cell through
 * the cmaps_best_pass filter. The best approved score for that map + playstyle
 * goes in, unless the CSV already has a better one.
 */
function cmaps_best_passes() {
	static $best = null;
	if ( null !== $best ) {
		return $best;
	}
	$best = array();
	foreach ( cmaps_lb_rows() as $r ) {
		if ( empty( $r['key'] ) ) {
			continue;
		}
		$style = false !== stripos( $r['style'], 'spin' ) ? 'spin' : 'camlock';
		$cur   = isset( $best[ $r['key'] ][ $style ] ) ? $best[ $r['key'] ][ $style ] : null;
		if ( ! $cur || cmaps_pass_beats( $r, $cur ) ) {
			$best[ $r['key'] ][ $style ] = $r;
		}
	}
	return $best;
}

// a beats b: harder speed mod, then accuracy, then whoever did it first
function cmaps_pass_beats( $a, $b ) {
	$sa = cmaps_speed_rank( $a['speed'] );
	$sb = cmaps_speed_rank( $b['speed'] );
	if ( $sa !== $sb ) {
		return $sa > $sb;
	}
	if ( (float) $a['acc'] !== (float) $b['acc'] ) {
		return (float) $a['acc'] > (float) $b['acc'];
	}
	return $a['time'] < $b['time'];
}

function cmaps_format_pass( $r ) {
	return $r['player'] . ' ' . cmaps_fmt_acc( $r['acc'] ) . '%' . ( $r['speed'] ? ' (' . $r['speed'] . ')' : '' );
}

// reads "Name 99.4% (S+)" style values from the CSV
function cmaps_parse_pass( $text ) {
	if ( ! preg_match( '/^(.*?)\s*(\d+(?:\.\d+)?)\s*%\s*(?:\(([^)]*)\))?/', trim( (string) $text ), $m ) ) {
		return null;
	}
	return array(
		'player' => trim( $m[1] ),
		'acc'    => (float) $m[2],
		'speed'  => isset( $m[3] ) ? cmaps_speed_tag( $m[3] ) : '',
		'time'   => PHP_INT_MAX,
	);
}

add_filter( 'cmaps_best_pass', function ( $value, $row, $style ) {
	$key  = cmaps_map_key( array( 'song' => $row['song'] ?? '', 'mapper' => $row['mapper'] ?? '' ) );
	$best = cmaps_best_passes();
	if ( empty( $best[ $key ][ $style ] ) ) {
		return $value;
	}
	$ours = $best[ $key ][ $style ];
	$csv  = ( '' === trim( (string) $value ) || 'NONE' === strtoupper( trim( (string) $value ) ) ) ? null : cmaps_parse_pass( $value );
	if ( trim( (string) $value ) !== '' && 'NONE' !== strtoupper( trim( (string) $value ) ) && ! $csv ) {
		return $value; // something hand-written we can't compare, leave it
	}
	return ( ! $csv || cmaps_pass_beats( $ours, $csv ) ) ? cmaps_format_pass( $ours ) : $value;
}, 10, 3 );


/* --- styles for the review page --- */

add_action( 'admin_head', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'cm-review' ) ) {
		return;
	}
	?>
	<style>
		.cmr-card { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px; margin: 16px 0; max-width: 1200px; }
		.cmr-card h2 { margin: 0 0 4px; font-size: 20px; }
		.cmr-sub, .cmr-muted { color: #646970; margin: 0 0 12px; }
		.cmr-facts { margin-bottom: 12px; }
		.cmr-facts th { text-align: left; padding: 3px 16px 3px 0; color: #646970; font-weight: 600; }
		.cmr-label { display: block; font-weight: 600; margin: 8px 0; }
		.cmr-label input, .cmr-label select { display: block; width: 100%; max-width: none; margin-top: 4px; font-weight: 400; }
		.cmr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
		.cmr-wide { grid-column: 1 / -1; }
		.cmr-actions { display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap; margin-top: 12px; }
		.cmr-reject summary { list-style: none; }
		.cmr-reject[open] { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
		.cmr-reject input { min-width: 220px; }
		.cmr-warn { color: #b32d2e; font-weight: 600; }
		.cmr-notes { background: #f6f7f7; padding: 10px; border-radius: 4px; }
		.cmr-video { position: relative; aspect-ratio: 16 / 9; background: #000; border-radius: 6px; overflow: hidden; }
		.cmr-video iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
		.cmr-empty { font-size: 15px; margin-top: 24px; }
		@media (max-width: 900px) {
			.cmr-card { grid-template-columns: 1fr; }
			.cmr-media { order: -1; }
		}
	</style>
	<?php
} );
