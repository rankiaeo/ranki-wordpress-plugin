<?php
/**
 * Plugin Name:       Ranki Publisher
 * Plugin URI:        https://github.com/rankiaeo/ranki-wordpress-plugin
 * Description:       Connects your WordPress site to Ranki for automated AI SEO content publishing. Install this plugin, then copy your secret key from Settings → Ranki Publisher into your Ranki admin panel.
 * Version:           1.14.4
 * Author:            Ranki
 * Author URI:        https://ranki.com.au
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ranki-publisher
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Tested up to:      7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RANKI_VERSION', '1.14.4' );
define( 'RANKI_OPTION_KEY', 'ranki_secret_key' );
define( 'RANKI_OPTION_STATUS',   'ranki_connection_status' );
define( 'RANKI_OPTION_AUTHOR',   'ranki_post_author_id' );
define( 'RANKI_OPTION_CATEGORY', 'ranki_default_category' );
define( 'RANKI_OPTION_PREF_SRC', 'ranki_preferred_source' );
define( 'RANKI_OPTION_PREF_SRC_OK', 'ranki_preferred_source_eligible' );
define( 'RANKI_API_BASE',   'https://ranki-backend-production.up.railway.app/api' );

// Enqueue lead + call tracker on all public pages.
add_action( 'wp_enqueue_scripts', function () {
	$key = get_option( RANKI_OPTION_KEY, '' );
	if ( ! $key ) {
		return;
	}
	// Registered with no src, then attached as inline JS below. JS-optimizer
	// plugins (SiteGround Optimizer, WP Rocket, Autoptimize) combine/minify
	// external <script src> files and can silently drop this one from the
	// rendered page, which zeroes out form-lead tracking on affected sites.
	// Inline output isn't touched by that class of optimization.
	wp_register_script( 'ranki-tracker', false, array(), RANKI_VERSION, true );
	wp_enqueue_script( 'ranki-tracker' );
	wp_localize_script( 'ranki-tracker', 'rankiTracker', array(
		'eventUrl' => rest_url( 'ranki/v1/event' ),
		'nonce'    => wp_create_nonce( 'ranki_tracker' ),
	) );
	wp_add_inline_script( 'ranki-tracker', file_get_contents( plugin_dir_path( __FILE__ ) . 'ranki-tracker.js' ) );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Google Preferred Sources button
// ─────────────────────────────────────────────────────────────────────────────
function ranki_preferred_source_eligible(): bool {
	return '1' === (string) get_option( RANKI_OPTION_PREF_SRC_OK, '0' );
}

/**
 * The button renders on any domain, so showing it on a site Google does not
 * carry gives readers a control that leads nowhere. Ranki checks which domains
 * Google lists and reports it on the check-in below, and the button waits for
 * that rather than trusting a switch on this site.
 */
function ranki_preferred_source_enabled(): bool {
	return ranki_preferred_source_eligible()
		&& '1' === (string) get_option( RANKI_OPTION_PREF_SRC, '1' );
}

function ranki_preferred_source_button(): string {
	if ( ! ranki_preferred_source_enabled() ) {
		return '';
	}
	// Google matches the primary subtag only, so a he-IL site has to send "he".
	$lang = strtolower( substr( (string) get_bloginfo( 'language' ), 0, 2 ) );
	return '<div class="ranki-preferred-source" style="margin:28px 0;"><div google-add-preferred-source-btn'
		. ( $lang ? ' data-lang="' . esc_attr( $lang ) . '"' : '' ) . '></div></div>';
}

add_shortcode( 'ranki_preferred_source', 'ranki_preferred_source_button' );

// Google's library is what turns the empty div into a rendered button.
add_action( 'wp_head', function () {
	if ( ! ranki_preferred_source_enabled() ) {
		return;
	}
	// Attached from inline JS instead of written as a <script src> tag. Host JS
	// optimizers rewrite external script tags they do not recognise, and SiteGround
	// Optimizer dropped this one from the page entirely, which left the button div
	// on screen with nothing to render it. Inline output survives that, the same
	// reason the tracker above is inlined rather than enqueued with a src.
	echo '<script>(function(){var s=document.createElement("script");s.async=true;'
		. 's.src="https://news.google.com/swg/js/v1/publisher.js";'
		. 'document.head.appendChild(s);})();</script>' . "\n";
} );

// Auto-place the button at the end of every article.
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( has_shortcode( $content, 'ranki_preferred_source' ) ) {
		return $content;
	}
	return $content . ranki_preferred_source_button();
} );

// ─────────────────────────────────────────────────────────────────────────────
// Activation: generate a secret key + schedule the pull-queue cron
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
	if ( ! get_option( RANKI_OPTION_KEY ) ) {
		update_option( RANKI_OPTION_KEY, wp_generate_password( 40, false ) );
	}
	if ( ! wp_next_scheduled( 'ranki_sync_cron' ) ) {
		wp_schedule_event( time(), 'ranki_every_5min', 'ranki_sync_cron' );
	}
} );

// Deactivation: clean up cron.
register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'ranki_sync_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'ranki_sync_cron' );
	}
} );

// ─────────────────────────────────────────────────────────────────────────────
// Auto-updates
// ─────────────────────────────────────────────────────────────────────────────
// WordPress leaves plugin auto-updates off unless the site owner turns them on
// per plugin, so a released fix sits behind a manual click on every site. Opt in
// on behalf of the site once, using core's own mechanism, then never touch it
// again: if the owner later switches it off from the Plugins screen, the flag
// below is already set, so their choice stands.
add_action( 'admin_init', function () {
	if ( get_option( 'ranki_auto_update_optin', '' ) ) {
		return;
	}
	update_option( 'ranki_auto_update_optin', RANKI_VERSION );
	$file    = plugin_basename( __FILE__ );
	$enabled = (array) get_site_option( 'auto_update_plugins', array() );
	if ( ! in_array( $file, $enabled, true ) ) {
		$enabled[] = $file;
		update_site_option( 'auto_update_plugins', $enabled );
	}
} );

// ─────────────────────────────────────────────────────────────────────────────
// Admin settings page — shows site URL + secret key for copy-paste into Ranki
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_menu', function () {
	add_options_page(
		esc_html__( 'Ranki Publisher', 'ranki-publisher' ),
		esc_html__( 'Ranki Publisher', 'ranki-publisher' ),
		'manage_options',
		'ranki-publisher',
		'ranki_settings_page'
	);
} );

function ranki_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key      = get_option( RANKI_OPTION_KEY, '' );
	$site_url = get_site_url();
	?>
	<?php
	$status     = get_option( RANKI_OPTION_STATUS, array() );
	$state      = is_array( $status ) ? ( $status['state'] ?? '' ) : '';
	$checked_at = is_array( $status ) ? (int) ( $status['time'] ?? 0 ) : 0;

	if ( ! $key ) {
		$badge_colour = '#64748b';
		$badge_text   = __( 'Not set up', 'ranki-publisher' );
		$badge_help   = __( 'No key on this site yet. Reactivate the plugin to generate one.', 'ranki-publisher' );
	} elseif ( 'connected' === $state ) {
		$badge_colour = '#16a34a';
		$badge_text   = __( 'Connected', 'ranki-publisher' );
		$badge_help   = sprintf(
			/* translators: %s: human readable time difference, e.g. "3 mins". */
			__( 'Ranki recognised this site %s ago.', 'ranki-publisher' ),
			human_time_diff( $checked_at, time() )
		);
	} elseif ( 'unknown_key' === $state ) {
		$badge_colour = '#d97706';
		$badge_text   = __( 'Waiting to be connected', 'ranki-publisher' );
		$badge_help   = __( 'This site has a key, but no Ranki account is using it yet. Paste the key below into Ranki to finish connecting.', 'ranki-publisher' );
	} elseif ( 'error' === $state ) {
		$badge_colour = '#dc2626';
		$badge_text   = __( 'Cannot reach Ranki', 'ranki-publisher' );
		$badge_help   = __( 'This site could not reach Ranki on its last try. It will keep retrying every few minutes.', 'ranki-publisher' );
	} else {
		$badge_colour = '#d97706';
		$badge_text   = __( 'Ready to connect', 'ranki-publisher' );
		$badge_help   = __( 'Paste the key below into Ranki. This site checks in every few minutes, so the status updates shortly after.', 'ranki-publisher' );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ranki Publisher', 'ranki-publisher' ); ?></h1>

		<div class="card" style="max-width:600px;padding:12px 16px;margin-bottom:16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Status', 'ranki-publisher' ); ?></h2>
			<p style="margin:0 0 6px;">
				<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?php echo esc_attr( $badge_colour ); ?>;margin-inline-end:8px;"></span>
				<strong><?php echo esc_html( $badge_text ); ?></strong>
			</p>
			<p class="description" style="margin:0;"><?php echo esc_html( $badge_help ); ?></p>
		</div>

		<p><?php esc_html_e( 'Copy the details below into your Ranki admin panel to connect this WordPress site.', 'ranki-publisher' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Site URL', 'ranki-publisher' ); ?></th>
				<td>
					<code><?php echo esc_html( $site_url ); ?></code>
					<button type="button" class="button"
						onclick="navigator.clipboard.writeText('<?php echo esc_js( $site_url ); ?>');this.textContent='<?php echo esc_js( __( 'Copied!', 'ranki-publisher' ) ); ?>'">
						<?php esc_html_e( 'Copy', 'ranki-publisher' ); ?>
					</button>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ranki Secret Key', 'ranki-publisher' ); ?></th>
				<td>
					<code><?php echo esc_html( $key ); ?></code>
					<button type="button" class="button"
						onclick="navigator.clipboard.writeText('<?php echo esc_js( $key ); ?>');this.textContent='<?php echo esc_js( __( 'Copied!', 'ranki-publisher' ) ); ?>'">
						<?php esc_html_e( 'Copy', 'ranki-publisher' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Keep this secret. It authorises Ranki to publish content on your behalf.', 'ranki-publisher' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Publishing Options', 'ranki-publisher' ); ?></h2>
		<p><?php esc_html_e( 'Choose how articles published by Ranki appear on this site.', 'ranki-publisher' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'ranki_save_publishing' ); ?>
			<input type="hidden" name="ranki_action" value="save_publishing">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ranki_post_author_id"><?php esc_html_e( 'Post Author', 'ranki-publisher' ); ?></label>
					</th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'              => 'ranki_post_author_id',
								'id'                => 'ranki_post_author_id',
								'selected'          => absint( get_option( RANKI_OPTION_AUTHOR, 0 ) ),
								'show_option_none'  => __( '— Default (first admin) —', 'ranki-publisher' ),
								'option_none_value' => 0,
								// Only users who may actually own a post.
								'capability'        => array( 'edit_posts' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Which user is shown as the author of articles Ranki publishes.', 'ranki-publisher' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ranki_default_category"><?php esc_html_e( 'Post Category', 'ranki-publisher' ); ?></label>
					</th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'name'             => 'ranki_default_category',
								'id'               => 'ranki_default_category',
								'selected'         => absint( get_option( RANKI_OPTION_CATEGORY, 0 ) ),
								'show_option_none' => __( '— Let Ranki choose —', 'ranki-publisher' ),
								'option_none_value' => 0,
								'hide_empty'       => false,
								'hierarchical'     => true,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Where articles are filed. Leave as "Let Ranki choose" and Ranki matches a category to the topic, creating one if nothing fits.', 'ranki-publisher' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Preferred Source Button', 'ranki-publisher' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ranki_preferred_source" value="1" <?php checked( ranki_preferred_source_enabled() ); ?>>
							<?php esc_html_e( 'Show Google\'s "add as preferred source" button at the end of each article', 'ranki-publisher' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Readers who tap it tell Google to favour this site in Top Stories, AI Overviews and AI Mode. Use the [ranki_preferred_source] shortcode to place it anywhere else, such as your footer or sidebar.', 'ranki-publisher' ); ?></p>
						<p class="description">
							<?php
							if ( ranki_preferred_source_eligible() ) {
								esc_html_e( 'Google lists this site as a source, so the button is live.', 'ranki-publisher' );
							} else {
								esc_html_e( 'Google does not list this site as a source yet, so the button stays hidden even when this is ticked. Ranki checks this and turns it on by itself.', 'ranki-publisher' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Options', 'ranki-publisher' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Regenerate Key', 'ranki-publisher' ); ?></h2>
		<p><?php esc_html_e( 'If you think your key has been compromised, regenerate it and update it in Ranki.', 'ranki-publisher' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'ranki_regen' ); ?>
			<input type="hidden" name="ranki_action" value="regen_key">
			<button type="submit" class="button button-secondary">
				<?php esc_html_e( 'Regenerate Secret Key', 'ranki-publisher' ); ?>
			</button>
		</form>
	</div>
	<?php
}

// Handle the publishing options form.
add_action( 'admin_init', function () {
	if (
		! isset( $_POST['ranki_action'] ) ||
		'save_publishing' !== $_POST['ranki_action'] ||
		! check_admin_referer( 'ranki_save_publishing' ) ||
		! current_user_can( 'manage_options' )
	) {
		return;
	}

	$author = isset( $_POST['ranki_post_author_id'] ) ? absint( wp_unslash( $_POST['ranki_post_author_id'] ) ) : 0;
	// Store 0 rather than a user who cannot own a post, so publishing falls back
	// to the admin instead of failing on a stale id.
	if ( $author && ! get_userdata( $author ) ) {
		$author = 0;
	}
	update_option( RANKI_OPTION_AUTHOR, $author );

	$category = isset( $_POST['ranki_default_category'] ) ? absint( wp_unslash( $_POST['ranki_default_category'] ) ) : 0;
	if ( $category && ! term_exists( $category, 'category' ) ) {
		$category = 0;
	}
	update_option( RANKI_OPTION_CATEGORY, $category );

	update_option( RANKI_OPTION_PREF_SRC, isset( $_POST['ranki_preferred_source'] ) ? '1' : '0' );

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( 'Publishing options saved.', 'ranki-publisher' ) .
			'</p></div>';
	} );
} );

// Handle key regeneration form submission.
add_action( 'admin_init', function () {
	if (
		isset( $_POST['ranki_action'] ) &&
		'regen_key' === $_POST['ranki_action'] &&
		check_admin_referer( 'ranki_regen' ) &&
		current_user_can( 'manage_options' )
	) {
		update_option( RANKI_OPTION_KEY, wp_generate_password( 40, false ) );
		// The old key is what Ranki matched on, so the previous status is now a lie.
		delete_option( RANKI_OPTION_STATUS );
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Ranki secret key regenerated. Update it in your Ranki admin panel.', 'ranki-publisher' ) .
				'</p></div>';
		} );
	}
} );

// ─────────────────────────────────────────────────────────────────────────────
// Pull Queue — WP-Cron poller (runs every 5 minutes)
//
// WordPress polls the Ranki backend for pending jobs, processes them locally,
// and reports the result back. All connections are outbound from WordPress,
// which bypasses host firewalls that block inbound REST API calls.
// ─────────────────────────────────────────────────────────────────────────────

// Register custom 5-minute interval.
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['ranki_every_5min'] = array(
		'interval' => 300,
		'display'  => esc_html__( 'Every 5 Minutes (Ranki)', 'ranki-publisher' ),
	);
	return $schedules;
} );

add_action( 'ranki_sync_cron', 'ranki_process_queue' );

// Re-schedule cron if it was somehow cleared.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ranki_sync_cron' ) ) {
		wp_schedule_event( time(), 'ranki_every_5min', 'ranki_sync_cron' );
	}
} );

/**
 * Fallback poller for sites where WP-Cron never fires.
 *
 * Some hosts disable WP-Cron without providing a replacement, which leaves the
 * pull queue permanently stalled and the site silently publishing nothing. The
 * scheduler can be dead while ordinary requests still work fine, so ride on
 * front-end traffic instead: take a lock, finish the response, then poll. The
 * visitor waits on nothing, and the lock matches the cron interval so a healthy
 * site does no meaningful extra work.
 */
add_action( 'init', function () {
	if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( ! get_option( RANKI_OPTION_KEY, '' ) || get_transient( 'ranki_poll_lock' ) ) {
		return;
	}

	set_transient( 'ranki_poll_lock', 1, 300 );

	add_action( 'shutdown', function () {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		ranki_process_queue();
	}, 99 );
} );

/**
 * Main pull-queue processor — fetches pending jobs from Ranki and runs them.
 */
function ranki_process_queue() {
	$key = get_option( RANKI_OPTION_KEY, '' );
	if ( ! $key ) {
		return;
	}

	$response = wp_remote_get(
		RANKI_API_BASE . '/wp-sync/poll',
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Accept'                  => 'application/json',
				'X-Ranki-Key'             => $key,
				'X-Ranki-Plugin-Version'  => RANKI_VERSION,
				// Lets Ranki tie this key to one site. A cloned or restored copy carries
				// the same key, and would otherwise claim articles meant for the original.
				'X-Ranki-Site'            => get_site_url(),
				// Which SEO plugin is really here. Ranki's own record is set at onboarding
				// and goes stale the moment someone migrates Yoast to Rank Math, and a stale
				// record means every article gets the other plugin's meta keys and publishes
				// with an empty SEO title that nothing reports. Sent on every check-in so a
				// mismatch surfaces on its own instead of being found weeks later.
				'X-Ranki-Seo-Plugin'      => ranki_active_seo_plugin() ?: 'none',
			),
		)
	);

	// Record the outcome so the settings screen can show whether Ranki recognises
	// this site. Without it the only confirmation lives in the Ranki dashboard, and
	// the site owner pasting the key has no way to tell here that it worked.
	$code = wp_remote_retrieve_response_code( $response );
	if ( is_wp_error( $response ) ) {
		update_option( RANKI_OPTION_STATUS, array(
			'state'   => 'error',
			'message' => $response->get_error_message(),
			'time'    => time(),
		) );
		return; // Silent fail — will retry in 5 minutes.
	}
	if ( 200 !== $code ) {
		update_option( RANKI_OPTION_STATUS, array(
			// 404 is the backend saying no account holds this key.
			'state'   => ( 404 === $code ) ? 'unknown_key' : 'error',
			'message' => sprintf( 'HTTP %d', $code ),
			'time'    => time(),
		) );
		return; // Silent fail — will retry in 5 minutes.
	}

	update_option( RANKI_OPTION_STATUS, array(
		'state'   => 'connected',
		'message' => '',
		'time'    => time(),
	) );

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	// Only trust an answer that is actually present. An older Ranki that says
	// nothing about this must not be read as "not eligible", or the button would
	// vanish everywhere the moment this site updated ahead of the backend.
	$settings = $body['site_settings'] ?? array();
	if ( is_array( $settings ) && array_key_exists( 'preferred_source', $settings ) ) {
		update_option( RANKI_OPTION_PREF_SRC_OK, empty( $settings['preferred_source'] ) ? '0' : '1' );
	}

	$jobs = $body['jobs'] ?? array();
	if ( empty( $jobs ) ) {
		return;
	}

	foreach ( $jobs as $job ) {
		ranki_process_single_job( $job, RANKI_API_BASE, $key );
	}
}

/**
 * Process a single queue job (publish, upload-image, or update-content).
 *
 * @param array  $job      Job data from the Ranki API.
 * @param string $api_base Ranki API base URL.
 * @param string $key      Ranki secret key.
 */
function ranki_process_single_job( array $job, string $api_base, string $key ) {
	$job_id  = $job['id'];
	$payload = $job['payload'] ?? array();

	if ( empty( $payload ) ) {
		ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', 'Empty payload' );
		return;
	}

	$raw     = wp_json_encode( $payload );
	$request = new WP_REST_Request( 'POST' );
	$request->set_body( $raw );
	$request->set_header( 'Content-Type', 'application/json' );

	$action = $payload['action'] ?? 'publish';

	try {
		if ( 'upload-image' === $action ) {
			$result = ranki_handle_upload_image( $request );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				$data = $result->get_data();
				ranki_report_job_done( $api_base, $key, $job_id, true, $data['media_id'] ?? 0, '' );
			}
		} elseif ( 'update-content' === $action ) {
			$result = ranki_handle_update_content( $request );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				ranki_report_job_done( $api_base, $key, $job_id, true, $payload['post_id'] ?? 0, '' );
			}
		} elseif ( 'update-meta' === $action ) {
			$result = ranki_handle_update_meta( $request );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				ranki_report_job_done( $api_base, $key, $job_id, true, $payload['post_id'] ?? 0, '' );
			}
		} elseif ( 'seo-config' === $action ) {
			$result = ranki_apply_seo_config( $payload );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				ranki_report_job_done( $api_base, $key, $job_id, true, 0, wp_json_encode( $result ), '' );
			}
		} elseif ( 'local-seo' === $action ) {
			$result = ranki_apply_local_seo( $payload );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				// Reported rather than discarded, because on Yoast the answer includes the
				// facts it has nowhere to put. A silent success there reads as "written".
				ranki_report_job_done( $api_base, $key, $job_id, true, 0, wp_json_encode( $result ), '' );
			}
		} elseif ( 'export-leads' === $action ) {
			// Reports its own job status to /wp-sync/leads-export (which stashes a
			// count/sample summary on the job row), not via ranki_report_job_done.
			ranki_handle_export_leads( $payload, $job_id, $api_base, $key );
		} else {
			$result = ranki_handle_publish( $request );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				$data = $result->get_data();
				// Job still succeeded (post published) even if the image failed — pass
				// media_error through so Ranki can surface it, without failing the job.
				ranki_report_job_done( $api_base, $key, $job_id, true, $data['post_id'] ?? 0, $data['post_url'] ?? '', $data['media_error'] ?? '' );
			}
		}
	} catch ( Exception $e ) {
		ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $e->getMessage() );
	}
}

/**
 * Report job outcome back to the Ranki API.
 *
 * @param string $api_base Ranki API base URL.
 * @param string $key      Ranki secret key.
 * @param string $job_id   Job identifier.
 * @param bool   $success  Whether the job succeeded.
 * @param int    $post_id  WordPress post ID (0 if not applicable).
 * @param string $post_url URL of the published post.
 * @param string $error    Error message if failed.
 */
function ranki_report_job_done( string $api_base, string $key, string $job_id, bool $success, int $post_id, string $post_url, string $error = '' ) {
	wp_remote_post(
		$api_base . '/wp-sync/done',
		array(
			'timeout'   => 10,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'X-Ranki-Key'  => $key,
			),
			'body'      => wp_json_encode(
				array(
					'job_id'   => $job_id,
					'success'  => $success,
					'post_id'  => $post_id,
					'post_url' => $post_url,
					'error'    => $error,
				)
			),
		)
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// REST API endpoints (for hosts that allow external REST API calls)
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	register_rest_route( 'ranki/v1', '/publish', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_publish',
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/upload-image', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_upload_image',
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/ping', array(
		'methods'             => 'GET',
		'callback'            => function () {
			return rest_ensure_response( array(
				'ok'      => true,
				'version' => RANKI_VERSION,
				'site'    => get_site_url(),
			) );
		},
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/update-content', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_update_content',
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/update-meta', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_update_meta',
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/set-schema', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_set_schema',
		'permission_callback' => 'ranki_check_auth',
	) );
	register_rest_route( 'ranki/v1', '/event', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_event',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( 'ranki/v1', '/sso-token', array(
		'methods'             => 'POST',
		'callback'            => 'ranki_handle_sso_token',
		'permission_callback' => 'ranki_check_auth',
	) );
} );

// ─────────────────────────────────────────────────────────────────────────────
// One-click admin login from the Ranki dashboard.
//
// Ranki mints a token over the authenticated REST route, then sends the
// browser to /?ranki_sso=<token>. The token is single-use, expires in 60
// seconds, and only ever logs in an existing administrator on this site.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pick the administrator to log in as. Prefers the account whose credentials
 * Ranki already publishes with, so the audit trail matches the posts.
 */
function ranki_sso_target_user() {
	$stored = (int) get_option( 'ranki_sso_user_id', 0 );
	if ( $stored ) {
		$user = get_user_by( 'id', $stored );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
	}

	$admins = get_users( array(
		'role'    => 'administrator',
		'number'  => 1,
		'orderby' => 'ID',
		'order'   => 'ASC',
	) );

	return $admins ? $admins[0] : null;
}

/**
 * Mint a short-lived single-use login token.
 */
function ranki_handle_sso_token( WP_REST_Request $request ) {
	$user = ranki_sso_target_user();
	if ( ! $user ) {
		return new WP_Error( 'ranki_no_admin', __( 'No administrator account found', 'ranki-publisher' ), array( 'status' => 500 ) );
	}

	$token = bin2hex( random_bytes( 32 ) );
	set_transient( 'ranki_sso_' . hash( 'sha256', $token ), $user->ID, 60 );

	return rest_ensure_response( array(
		'ok'         => true,
		'login_url'  => add_query_arg( 'ranki_sso', $token, home_url( '/' ) ),
		'expires_in' => 60,
		'user_login' => $user->user_login,
	) );
}

add_action( 'template_redirect', function () {
	if ( empty( $_GET['ranki_sso'] ) ) {
		return;
	}

	$token = sanitize_text_field( wp_unslash( $_GET['ranki_sso'] ) );
	$key   = 'ranki_sso_' . hash( 'sha256', $token );
	$user_id = get_transient( $key );

	// Single use — burn it before logging anyone in, so a replayed URL fails
	// even if the redirect below is interrupted.
	delete_transient( $key );

	if ( ! $user_id ) {
		wp_die( esc_html__( 'This Ranki login link has expired. Open it again from your Ranki dashboard.', 'ranki-publisher' ), 403 );
	}

	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
		wp_die( esc_html__( 'This Ranki login link is no longer valid.', 'ranki-publisher' ), 403 );
	}

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, false, is_ssl() );

	// Leave a trail the site owner can see in Settings → Ranki Publisher.
	$log = get_option( 'ranki_sso_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, array(
		'at'   => current_time( 'mysql' ),
		'user' => $user->user_login,
		'ip'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
	) );
	update_option( 'ranki_sso_log', array_slice( $log, 0, 20 ), false );

	wp_safe_redirect( admin_url() );
	exit;
} );

// ─────────────────────────────────────────────────────────────────────────────
// Direct endpoint — bypasses /wp-json/ for hosts that block REST API calls
// from external IPs (e.g. SiteGround Security, Cloudflare firewall rules).
// URL: https://example.com/?ranki_action=publish|upload-image|ping|update-content
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['ranki_action'] ) ) {
		return;
	}

	$action  = sanitize_text_field( wp_unslash( $_GET['ranki_action'] ) );
	$allowed = array( 'publish', 'upload-image', 'ping', 'update-content', 'set-schema', 'sso-token' );
	if ( ! in_array( $action, $allowed, true ) ) {
		return;
	}

	// Authenticate: key from HTTP header or query param.
	$provided = isset( $_SERVER['HTTP_X_RANKI_KEY'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RANKI_KEY'] ) )
		: ( isset( $_GET['ranki_key'] ) ? sanitize_text_field( wp_unslash( $_GET['ranki_key'] ) ) : '' );

	$stored = get_option( RANKI_OPTION_KEY, '' );
	if ( ! $stored || ! hash_equals( $stored, $provided ) ) {
		wp_send_json( array( 'error' => __( 'Unauthorized', 'ranki-publisher' ) ), 401 );
		exit;
	}

	if ( 'ping' === $action ) {
		wp_send_json( array( 'ok' => true, 'version' => RANKI_VERSION, 'site' => get_site_url() ) );
		exit;
	}

	if ( 'sso-token' === $action ) {
		$result = ranki_handle_sso_token( new WP_REST_Request( 'POST' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json( array( 'error' => $result->get_error_message() ), 500 );
		}
		wp_send_json( $result->get_data() );
		exit;
	}

	// Read the raw JSON body. file_get_contents( 'php://input' ) is the only
	// reliable way to access the raw request body outside of the REST API
	// context — WordPress core itself uses this pattern in WP_REST_Server.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$raw    = file_get_contents( 'php://input' );
	$params = json_decode( $raw, true );
	if ( ! $params ) {
		wp_send_json( array( 'error' => __( 'Invalid JSON body', 'ranki-publisher' ) ), 400 );
		exit;
	}

	$request = new WP_REST_Request( 'POST' );
	$request->set_body( $raw );
	$request->set_header( 'Content-Type', 'application/json' );

	if ( 'publish' === $action ) {
		$result = ranki_handle_publish( $request );
	} elseif ( 'upload-image' === $action ) {
		$result = ranki_handle_upload_image( $request );
	} elseif ( 'update-content' === $action ) {
		$result = ranki_handle_update_content( $request );
	} elseif ( 'set-schema' === $action ) {
		$result = ranki_handle_set_schema( $request );
	} else {
		wp_send_json( array( 'error' => __( 'Unknown action', 'ranki-publisher' ) ), 400 );
		exit;
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json(
			array(
				'error' => $result->get_error_message(),
				'code'  => $result->get_error_code(),
			),
			$result->get_error_data()['status'] ?? 500
		);
	} else {
		wp_send_json( $result->get_data() );
	}
	exit;
} );

/**
 * Permission callback for all Ranki REST routes.
 *
 * This is a server-to-server API: all requests originate from the Ranki
 * backend service, not a human browser session. There is no WordPress user
 * context, so current_user_can() is intentionally absent — the shared secret
 * key is the proof of authorisation. The key is a 40-character random string
 * generated at activation and stored in wp_options.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return bool True if the supplied key matches the stored secret.
 */
function ranki_check_auth( WP_REST_Request $request ) {
	$provided = $request->get_header( 'X-Ranki-Key' );
	if ( ! $provided ) {
		$provided = $request->get_param( 'ranki_key' );
	}
	$stored = get_option( RANKI_OPTION_KEY, '' );
	return $stored && hash_equals( $stored, (string) $provided );
}

/**
 * Verify a byte string is actually one of the allowed image types by reading
 * its real content (not the filename or any client-supplied claim), before
 * anything is written to disk. wp_upload_bits() does not do this itself.
 *
 * @param string $bytes Raw file content.
 * @return bool True if the bytes are a genuine jpeg/png/gif/webp image.
 */
function ranki_is_allowed_image( string $bytes ): bool {
	$tmp = tmpfile();
	fwrite( $tmp, $bytes );
	$tmp_meta  = stream_get_meta_data( $tmp );
	$real_mime = mime_content_type( $tmp_meta['uri'] );
	fclose( $tmp );

	$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	return in_array( $real_mime, $allowed_mimes, true );
}

/**
 * REST callback: upload an image to the WordPress media library.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_upload_image( WP_REST_Request $request ) {
	$params     = $request->get_json_params();
	$image_b64  = $params['image_base64'] ?? '';
	$image_name = sanitize_file_name( $params['image_filename'] ?? 'image.png' );
	$image_alt  = sanitize_text_field( $params['image_alt'] ?? '' );

	if ( ! $image_b64 ) {
		return new WP_Error( 'missing_image', __( 'image_base64 is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$image_bytes = base64_decode( $image_b64, true );
	if ( false === $image_bytes ) {
		return new WP_Error( 'invalid_image', __( 'Could not decode base64 image', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	// Verify the real file content before it ever touches disk — a filename
	// alone (e.g. "photo.jpg") proves nothing about what bytes follow it.
	if ( ! ranki_is_allowed_image( $image_bytes ) ) {
		return new WP_Error( 'invalid_image', __( 'Image must be jpeg, png, gif or webp', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$upload = wp_upload_bits( $image_name, null, $image_bytes );
	if ( $upload['error'] ) {
		return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
	}

	$wp_filetype = wp_check_filetype( $image_name );
	$attachment  = array(
		'post_mime_type' => $wp_filetype['type'],
		'post_title'     => $image_alt ?: $image_name,
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$media_id = wp_insert_attachment( $attachment, $upload['file'] );
	if ( is_wp_error( $media_id ) ) {
		return $media_id;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $media_id, $upload['file'] );
	wp_update_attachment_metadata( $media_id, $metadata );
	if ( $image_alt ) {
		update_post_meta( $media_id, '_wp_attachment_image_alt', $image_alt );
	}

	return rest_ensure_response( array(
		'ok'        => true,
		'media_id'  => $media_id,
		'media_url' => $upload['url'],
	) );
}

/**
 * Sanitize post content, allowing video embeds from trusted providers.
 *
 * wp_kses_post() strips <iframe> outright, which silently deleted every
 * article video Ranki embeds. Allow iframes only when the src points at a
 * known video host.
 *
 * @param string $content Raw HTML.
 * @return string Sanitized HTML.
 */
function ranki_kses_content( $content ) {
	$allowed = wp_kses_allowed_html( 'post' );

	$allowed['iframe'] = array(
		'src'             => true,
		'title'           => true,
		'width'           => true,
		'height'          => true,
		'style'           => true,
		'class'           => true,
		'loading'         => true,
		'frameborder'     => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'referrerpolicy'  => true,
	);

	// Default protocol list: restricting it here would strip mailto: and tel: links.
	$clean = wp_kses( $content, $allowed );

	// Drop any iframe whose src is not a trusted video host.
	return preg_replace_callback(
		'#<iframe\b[^>]*>.*?</iframe>#is',
		function ( $m ) {
			if ( preg_match( '#\ssrc=["\']https://(?:www\.)?(?:youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/)#i', $m[0] ) ) {
				return $m[0];
			}
			return '';
		},
		$clean
	);
}

/**
 * Allow trusted video iframes through WordPress's own save-time KSES pass.
 *
 * ranki_kses_content() is not the last word: wp_insert_post()/wp_update_post()
 * run core's wp_filter_post_kses on post_content, and because these REST calls
 * authenticate with the Ranki key rather than a WordPress user, the request has
 * no unfiltered_html capability. Core therefore stripped the <iframe> again,
 * after our sanitizer had already approved it, which silently deleted every
 * article video. Enabled only around our own write, then removed.
 *
 * @param array  $tags    Allowed tags.
 * @param string $context KSES context.
 * @return array
 */
function ranki_allow_video_iframe( $tags, $context ) {
	if ( 'post' === $context ) {
		$tags['iframe'] = array(
			'src'             => true,
			'title'           => true,
			'width'           => true,
			'height'          => true,
			'style'           => true,
			'class'           => true,
			'loading'         => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'referrerpolicy'  => true,
		);
	}
	return $tags;
}

/**
 * Return a slug that no category, tag, or top-level page already owns.
 *
 * wp_insert_post() only de-duplicates a post slug against other posts, so a term
 * or page archive sharing the slug silently shadows the new article's permalink.
 *
 * @param string $slug  Desired slug.
 * @param string $title Article title, used to build a readable alternative.
 * @return string A slug free of term/page collisions.
 */
function ranki_free_slug( $slug, $title = '' ) {
	if ( '' === $slug ) {
		return $slug;
	}

	$taken = function ( $candidate ) {
		if ( '' === $candidate ) {
			return true;
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			if ( get_term_by( 'slug', $candidate, $taxonomy ) ) {
				return true;
			}
		}
		return (bool) get_page_by_path( $candidate, OBJECT, 'page' );
	};

	if ( ! $taken( $slug ) ) {
		return $slug;
	}

	// Prefer a slug built from the article's own title: it stays in the article's
	// language and reads like a real URL, where "-2" reads like a mistake.
	$from_title = ranki_slugify( $title );
	if ( $from_title && $from_title !== $slug && ! $taken( $from_title ) ) {
		return $from_title;
	}

	for ( $i = 2; $i <= 20; $i++ ) {
		$candidate = $slug . '-' . $i;
		if ( ! $taken( $candidate ) ) {
			return $candidate;
		}
	}

	return $slug;
}

/**
 * Slugify a string, preserving non-ASCII scripts that sanitize_title() strips.
 *
 * @param string $text Raw text.
 * @return string Slug.
 */
function ranki_slugify( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return '';
	}
	if ( ! preg_match( '/[^\x00-\x7F]/u', $text ) ) {
		return ranki_trim_slug( sanitize_title( $text ) );
	}
	$text = mb_strtolower( $text, 'UTF-8' );
	$text = preg_replace( '/\s+/u', '-', $text );
	$text = preg_replace( '/[^\p{L}\p{N}\-]/u', '', $text );
	$text = preg_replace( '/-{2,}/u', '-', $text );
	return ranki_trim_slug( trim( $text, '-' ) );
}

/**
 * Keep a slug inside the length WordPress stores, cutting on a word boundary.
 *
 * post_name holds the percent-encoded slug in a 200 character column and WordPress
 * chops the overflow wherever it lands. One Hebrew letter costs six characters
 * there, so an ordinary Hebrew title overflows and the address ends on half a word.
 *
 * @param string $slug Slug to shorten.
 * @return string Slug that survives the save intact.
 */
function ranki_trim_slug( $slug ) {
	$max = 190;
	if ( '' === $slug || strlen( rawurlencode( $slug ) ) <= $max ) {
		return $slug;
	}
	$kept = array();
	foreach ( explode( '-', $slug ) as $word ) {
		$candidate = implode( '-', array_merge( $kept, array( $word ) ) );
		if ( strlen( rawurlencode( $candidate ) ) > $max ) {
			break;
		}
		$kept[] = $word;
	}
	if ( empty( $kept ) ) {
		// A single word longer than the whole budget still has to be cut somewhere.
		while ( '' !== $slug && strlen( rawurlencode( $slug ) ) > $max ) {
			$slug = mb_substr( $slug, 0, mb_strlen( $slug ) - 1, 'UTF-8' );
		}
		return trim( $slug, '-' );
	}
	return trim( implode( '-', $kept ), '-' );
}

/**
 * REST callback: publish a new post with SEO metadata and optional featured image.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_publish( WP_REST_Request $request ) {
	$params = $request->get_json_params();

	$title      = sanitize_text_field( $params['title'] ?? '' );
	$content    = ranki_kses_content( $params['content'] ?? '' );
	// ranki_slugify() preserves non-ASCII scripts (Hebrew, Arabic, etc.) that
	// sanitize_title() strips. WordPress supports non-ASCII post_name natively.
	$slug = ranki_slugify( $params['slug'] ?? '' );
	// WordPress only keeps post slugs unique against other posts. A category, tag, or
	// page owning the same slug wins the URL, so the article publishes "successfully"
	// and is then unreachable at its own permalink. Move off a taken slug before insert.
	$slug = ranki_free_slug( $slug, $title );
	$excerpt    = sanitize_text_field( $params['excerpt'] ?? '' );
	$status     = in_array( $params['status'] ?? 'publish', array( 'publish', 'draft', 'pending' ), true )
					? $params['status'] : 'publish';
	$image_b64  = $params['image_base64'] ?? '';
	$image_name = sanitize_file_name( $params['image_filename'] ?? 'featured.jpg' );
	$image_alt  = sanitize_text_field( $params['image_alt'] ?? $title );

	// Schema JSON-LD: round-trip through json_decode/json_encode to validate
	// and sanitize. An invalid JSON value is silently discarded.
	$schema_raw = $params['schema_jsonld'] ?? '';
	$schema     = '';
	if ( $schema_raw ) {
		$decoded = json_decode( $schema_raw, true );
		if ( null !== $decoded ) {
			$schema = wp_json_encode( $decoded );
		}
	}

	$focus_kw   = sanitize_text_field( $params['focus_keyword'] ?? '' );
	$seo_title  = sanitize_text_field( $params['seo_title'] ?? $title );
	$meta_desc  = sanitize_text_field( $params['meta_description'] ?? '' );
	$seo_plugin = sanitize_text_field( $params['seo_plugin'] ?? 'rankmath' );
	$category   = sanitize_text_field( $params['category'] ?? '' );

	if ( ! $title || ! $content ) {
		return new WP_Error( 'missing_fields', __( 'title and content are required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	// ── 1. Upload featured image ──────────────────────────────────────────────
	$media_id    = 0;
	$media_url   = '';
	$media_error = '';
	if ( $image_b64 ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$image_bytes = base64_decode( $image_b64, true );
		if ( false !== $image_bytes ) {
			// Verify the real file content before it ever touches disk.
			if ( ! ranki_is_allowed_image( $image_bytes ) ) {
				return new WP_Error( 'invalid_image', __( 'Image must be jpeg, png, gif or webp', 'ranki-publisher' ), array( 'status' => 400 ) );
			}

			$upload = wp_upload_bits( $image_name, null, $image_bytes );
			if ( ! $upload['error'] ) {
				$wp_filetype = wp_check_filetype( $image_name );
				$attachment  = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => $image_alt,
					'post_content'   => '',
					'post_status'    => 'inherit',
				);
				$media_id  = wp_insert_attachment( $attachment, $upload['file'] );
				$media_url = $upload['url'];
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$metadata = wp_generate_attachment_metadata( $media_id, $upload['file'] );
				wp_update_attachment_metadata( $media_id, $metadata );
				update_post_meta( $media_id, '_wp_attachment_image_alt', $image_alt );
			} else {
				$media_error = 'Image upload failed: ' . $upload['error'];
			}
		} else {
			$media_error = 'Image data could not be decoded (invalid base64)';
		}
	}

	// ── 2. Build meta_input ───────────────────────────────────────────────────
	$meta_input = array();
	if ( $media_id ) {
		$meta_input['_thumbnail_id'] = $media_id;
	}
	if ( $schema ) {
		$meta_input['_ranki_schema_jsonld'] = $schema;
	}
	if ( 'rankmath' === $seo_plugin ) {
		if ( $focus_kw )  $meta_input['rank_math_focus_keyword'] = $focus_kw;
		if ( $seo_title ) $meta_input['rank_math_title']         = $seo_title;
		if ( $meta_desc ) $meta_input['rank_math_description']   = $meta_desc;
	} elseif ( 'yoast' === $seo_plugin ) {
		if ( $focus_kw )  $meta_input['_yoast_wpseo_focuskw']  = $focus_kw;
		if ( $seo_title ) $meta_input['_yoast_wpseo_title']    = $seo_title;
		if ( $meta_desc ) $meta_input['_yoast_wpseo_metadesc'] = $meta_desc;
	}

	// ── 3. Resolve category ───────────────────────────────────────────────────
	$category_ids = array();
	if ( $category ) {
		if ( is_numeric( $category ) ) {
			$category_ids = array( (int) $category );
		} else {
			$term = get_term_by( 'name', $category, 'category' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $category ), 'category' );
			}
			if ( $term ) {
				$category_ids = array( $term->term_id );
			} else {
				$new_term = wp_insert_term( $category, 'category' );
				if ( ! is_wp_error( $new_term ) ) {
					$category_ids = array( $new_term['term_id'] );
				}
			}
		}
	} elseif ( ( $default_cat = absint( get_option( RANKI_OPTION_CATEGORY, 0 ) ) ) && term_exists( $default_cat, 'category' ) ) {
		// A category picked on the settings screen is a deliberate choice, so it wins
		// over the guesser below, which invents new categories from the keyword.
		$category_ids = array( $default_cat );
	} elseif ( $focus_kw ) {
		// Auto-detect the best matching existing category for this keyword.
		$all_cats   = get_categories( array( 'hide_empty' => false, 'exclude' => array( 1 ) ) );
		$best_match = null;
		$best_score = 0;
		$kw_words   = explode( ' ', strtolower( $focus_kw ) );

		foreach ( $all_cats as $cat ) {
			$cat_words = explode( ' ', strtolower( $cat->name ) );
			$matches   = count( array_intersect( $kw_words, $cat_words ) );
			if ( false !== stripos( $focus_kw, $cat->name ) ) $matches += 3;
			if ( false !== stripos( $cat->name, $focus_kw ) ) $matches += 3;
			if ( false !== stripos( $focus_kw, str_replace( '-', ' ', $cat->slug ) ) ) $matches += 2;
			if ( $matches > $best_score ) {
				$best_score = $matches;
				$best_match = $cat;
			}
		}

		if ( $best_match && $best_score >= 1 ) {
			$category_ids = array( $best_match->term_id );
		} else {
			$cat_name = ucwords( implode( ' ', array_slice( $kw_words, 0, 2 ) ) );
			if ( strlen( $cat_name ) >= 3 ) {
				$new_term = wp_insert_term( $cat_name, 'category' );
				if ( ! is_wp_error( $new_term ) ) {
					$category_ids = array( $new_term['term_id'] );
				}
			}
		}
	}

	// ── 4. Determine post author ──────────────────────────────────────────────
	// Requests come from the Ranki backend — there is no logged-in WordPress
	// user. Use the author chosen on the settings screen, falling back to user
	// ID 1 (the first admin). The ranki_post_author filter still has the last
	// word so existing code overrides keep working.
	$saved_author = absint( get_option( RANKI_OPTION_AUTHOR, 0 ) );
	if ( ! $saved_author || ! get_userdata( $saved_author ) ) {
		$saved_author = 1;
	}
	$post_author = absint( apply_filters( 'ranki_post_author', $saved_author ) );

	// ── 5. Create post ────────────────────────────────────────────────────────
	$raw_post_type = sanitize_key( $params['post_type'] ?? 'post' );
	$post_type_obj = get_post_type_object( $raw_post_type );
	$post_type_val = ( $post_type_obj && $raw_post_type !== 'attachment' ) ? $raw_post_type : 'post';

	$post_data = array(
		'post_title'    => $title,
		'post_content'  => $content,
		'post_excerpt'  => $excerpt,
		'post_name'     => $slug,
		'post_status'   => $status,
		'post_type'     => $post_type_val,
		'post_author'   => $post_author,
		'meta_input'    => $meta_input,
	);
	if ( ! empty( $category_ids ) ) {
		$post_data['post_category'] = $category_ids;
	}

	add_filter( 'wp_kses_allowed_html', 'ranki_allow_video_iframe', 10, 2 );
	$post_id = wp_insert_post( $post_data, true );
	remove_filter( 'wp_kses_allowed_html', 'ranki_allow_video_iframe', 10 );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( $media_id ) {
		set_post_thumbnail( $post_id, $media_id );
	}

	// ── 6. Re-apply SEO meta after insert ─────────────────────────────────────
	// Belt-and-suspenders: some caching/security plugins clear meta on save_post.
	if ( 'rankmath' === $seo_plugin ) {
		if ( $focus_kw )  update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
		if ( $seo_title ) update_post_meta( $post_id, 'rank_math_title',         $seo_title );
		if ( $meta_desc ) update_post_meta( $post_id, 'rank_math_description',   $meta_desc );
	} elseif ( 'yoast' === $seo_plugin ) {
		if ( $focus_kw )  update_post_meta( $post_id, '_yoast_wpseo_focuskw',  $focus_kw );
		if ( $seo_title ) update_post_meta( $post_id, '_yoast_wpseo_title',    $seo_title );
		if ( $meta_desc ) update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
	}
	// Re-apply schema explicitly — some security plugins wipe private meta during save_post.
	if ( $schema ) {
		update_post_meta( $post_id, '_ranki_schema_jsonld', $schema );
	}

	$post_url      = get_permalink( $post_id );
	$assigned_cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
	$cat_name      = ! empty( $assigned_cats ) ? $assigned_cats[0] : 'Uncategorized';

	// ── 7. Purge page cache ───────────────────────────────────────────────────
	// SiteGround and other hosts cache 404s aggressively. Purge after publish
	// so the new permalink is immediately accessible.
	ranki_purge_cache( $post_id, $post_url );

	return rest_ensure_response( array(
		'ok'          => true,
		'post_id'     => $post_id,
		'post_url'    => $post_url,
		'media_id'    => $media_id,
		'media_url'   => $media_url,
		'media_error' => $media_error,
		'category'    => $cat_name,
	) );
}

/**
 * Purge page cache after publishing a post.
 * Supports SiteGround Optimizer, WP Rocket, W3 Total Cache, LiteSpeed,
 * WP Super Cache, and WordPress's own object cache. Silently skips any
 * plugin that is not active.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $post_url Full permalink URL.
 */
function ranki_purge_cache( int $post_id, string $post_url ): void {
	// SiteGround Optimizer (sg-cachepress) — both old and new API
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}
	if ( function_exists( 'sgo_purge_cache' ) ) {
		sgo_purge_cache();
	}
	// SG Optimizer also responds to this action (plugin v7+)
	do_action( 'siteground_optimizer_flush_cache' );
	do_action( 'siteground_optimizer_purge_by_url', $post_url );

	// WP Rocket
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}

	// W3 Total Cache
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $post_id );
	}

	// LiteSpeed Cache
	do_action( 'litespeed_purge_post', $post_id );

	// WP Super Cache
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $post_id );
	}

	// Cloudflare (via Cloudflare plugin)
	do_action( 'cloudflare_purge_by_url', array( $post_url ) );

	// Flush WordPress object cache and rewrite rules so the permalink resolves.
	// Hard flush (true) rewrites .htaccess — needed for non-Latin slugs (Hebrew, Arabic)
	// where a soft flush leaves stale rewrite rules and the URL redirects to the homepage.
	wp_cache_flush();
	flush_rewrite_rules( true );
}

/**
 * REST callback: update the content of an existing post.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_update_content( WP_REST_Request $request ) {
	$params  = $request->get_json_params();
	$post_id = absint( $params['post_id'] ?? 0 );
	$content = $params['content'] ?? '';
	// Optional. Lets a permalink be repaired remotely when a category, tag, or page
	// turns out to own the article's slug and shadow it.
	$new_slug = ranki_slugify( $params['slug'] ?? '' );

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( '' === $content && '' === $new_slug ) {
		return new WP_Error( 'missing_content', __( 'content or slug is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		/* translators: %d: WordPress post ID */
		return new WP_Error( 'not_found', sprintf( __( 'Post %d not found', 'ranki-publisher' ), $post_id ), array( 'status' => 404 ) );
	}

	$update = array( 'ID' => $post_id );
	if ( '' !== $content ) {
		$update['post_content'] = ranki_kses_content( $content );
	}
	if ( '' !== $new_slug ) {
		$update['post_name'] = ranki_free_slug( $new_slug, $post->post_title );
	}

	add_filter( 'wp_kses_allowed_html', 'ranki_allow_video_iframe', 10, 2 );
	$result = wp_update_post( $update, true );
	remove_filter( 'wp_kses_allowed_html', 'ranki_allow_video_iframe', 10 );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$post_url = get_permalink( $post_id );
	ranki_purge_cache( $post_id, $post_url );

	return rest_ensure_response( array(
		'ok'       => true,
		'post_id'  => $post_id,
		'slug'     => get_post_field( 'post_name', $post_id ),
		'post_url' => $post_url,
	) );
}

/**
 * REST callback: update the SEO meta description of an existing post.
 *
 * Accepts {post_id, meta_description, seo_plugin}. Deliberately narrow: it writes
 * the description and nothing else, so a meta correction can never disturb the
 * focus keyword, the SEO title or the post content. Used by Ranki's backfill,
 * which reaches sites whose host blocks inbound REST calls by travelling through
 * the pull queue instead.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_update_meta( WP_REST_Request $request ) {
	$params     = $request->get_json_params();
	$post_id    = absint( $params['post_id'] ?? 0 );
	$meta_desc  = sanitize_text_field( $params['meta_description'] ?? '' );
	$seo_plugin = sanitize_text_field( $params['seo_plugin'] ?? 'rankmath' );

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( '' === $meta_desc ) {
		return new WP_Error( 'missing_meta_description', __( 'meta_description is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		/* translators: %d: WordPress post ID */
		return new WP_Error( 'not_found', sprintf( __( 'Post %d not found', 'ranki-publisher' ), $post_id ), array( 'status' => 404 ) );
	}

	if ( 'yoast' === $seo_plugin ) {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
	} else {
		update_post_meta( $post_id, 'rank_math_description', $meta_desc );
	}

	// The description is rendered into <head>, so a cached page keeps serving the
	// old one until the cache is dropped.
	ranki_purge_cache( $post_id, get_permalink( $post_id ) );

	return rest_ensure_response( array(
		'ok'      => true,
		'post_id' => $post_id,
	) );
}

/**
 * Rank Math Local SEO keys Ranki is allowed to write.
 *
 * An allowlist rather than "whatever was sent", because this option also holds
 * every title template, separator and archive setting on the site. Ranki knows
 * the business facts and nothing else, so it may only write the business facts.
 *
 * @return array
 */

/**
 * Rank Math settings Ranki may write, grouped by the option they live in.
 *
 * An allowlist per group rather than a free write. These options also carry the
 * site's own title templates, separators and archive choices, and a client's SEO
 * config is not a thing to replace wholesale: a wrong value here deindexes pages.
 *
 * @return array<string, string[]>
 */
function ranki_seo_config_writable_keys(): array {
	return array(
		// Indexing. What Google is allowed to keep.
		'rank_math_options_titles' => array(
			'pt_attachment_custom_robots', 'pt_attachment_robots',
			'tax_category_custom_robots', 'tax_category_robots',
			'tax_post_tag_custom_robots', 'tax_post_tag_robots',
			'noindex_empty_taxonomies',
			'disable_author_archives', 'disable_date_archives',
			'noindex_paginated_pages',
		),
		// Structure. Breadcrumbs carry schema, attachment pages are thin duplicates.
		'rank_math_options_general' => array(
			'attachment_redirect_urls', 'attachment_redirect_default',
			'breadcrumbs', 'breadcrumbs_separator', 'breadcrumbs_home',
			'nofollow_external_links', 'new_window_external_links',
			'redirections', 'redirections_debug', 'url_strip_stopwords',
			// Fills in missing image alt text, and stops Rank Math emailing the client
			// its own monthly SEO report alongside Ranki's.
			'add_img_alt', 'img_alt_format', 'console_email_reports',
		),
		// What gets submitted to Google, and what has no business being there.
		'rank_math_options_sitemap' => array(
			'pt_attachment_sitemap',
			'tax_category_sitemap', 'tax_post_tag_sitemap',
			'include_images', 'items_per_page', 'exclude_posts', 'exclude_terms',
		),
	);
}

/**
 * Custom post types are per site, so their indexing keys cannot be a fixed list.
 * A key like pt_testimonial_robots is allowed only when that post type exists here.
 *
 * @return string[]
 */
function ranki_dynamic_post_type_keys(): array {
	$keys = array();
	foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
		$keys[] = "pt_{$pt}_custom_robots";
		$keys[] = "pt_{$pt}_robots";
		$keys[] = "pt_{$pt}_sitemap";
		// What schema a page of this type carries. Ranki has been sending these since
		// 1.10.0 and this list quietly dropped every one, so a service page kept the
		// default article schema and the admin screen said otherwise.
		$keys[] = "pt_{$pt}_default_rich_snippet";
	}
	return $keys;
}

/**
 * Which SEO plugin this site actually runs.
 *
 * Ranki holds its own record of this, set at onboarding, and that record goes stale the
 * day a client switches plugins. Writing Rank Math options to a Yoast site does not fail,
 * it writes an option nothing reads, and the admin screen reports a change that had no
 * effect. So the site itself is asked, and a mismatch is an error rather than a silent no-op.
 *
 * @return string 'rankmath', 'yoast' or '' when neither is active.
 */
function ranki_active_seo_plugin(): string {
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	if ( defined( 'WPSEO_VERSION' ) || defined( 'WPSEO_FILE' ) ) {
		return 'yoast';
	}
	return '';
}

/**
 * Yoast settings Ranki may write, grouped by the option they live in.
 *
 * Same rule as the Rank Math list: an allowlist, because these options also carry the
 * site's own title templates, separators and archive choices.
 *
 * Two differences from Rank Math worth knowing, because they change what can be offered.
 * Yoast has no per-post-type sitemap toggle, its sitemap carries whatever is indexable, so
 * a noindex here removes the type from the sitemap as well. And Yoast keeps its indexing
 * settings as real booleans, not the "on"/"off" strings Rank Math stores.
 *
 * @return array<string, string[]>
 */
function ranki_yoast_seo_config_writable_keys(): array {
	return array(
		// Indexing and structure. Yoast keeps both in wpseo_titles.
		'wpseo_titles' => array(
			// disable-* turns the archive off entirely and redirects it, which is what
			// Rank Math's disable_date_archives does. Stronger than a noindex and the
			// right call on a business site with one author.
			'disable-attachment', 'disable-author', 'disable-date', 'disable-post_format',
			'noindex-author-wpseo', 'noindex-author-noposts-wpseo', 'noindex-archive-wpseo',
			'noindex-tax-category', 'noindex-tax-post_tag', 'noindex-tax-post_format',
			'breadcrumbs-enable', 'breadcrumbs-home', 'breadcrumbs-sep',
			// The most visible line a business has in search. Ranki only ever proposes one
			// when what is there now is clearly a Yoast default, never when someone wrote it.
			'title-home-wpseo', 'metadesc-home-wpseo',
		),
		// Site-wide switches.
		'wpseo'        => array(
			'enable_xml_sitemap', 'enable_index_now', 'enable_llms_txt', 'enable_schema',
			'enable_cornerstone_content', 'enable_text_link_counter',
			// Yoast can add its own Disallow lines for the AI crawlers. Ranki is sold on
			// being readable by assistants, so these stay off. deny_wp_json_crawling is in
			// the same family for a different reason: it blocks the endpoint Ranki reads
			// the site's post types through.
			'deny_gptbot_crawling', 'deny_ccbot_crawling', 'deny_google_extended_crawling',
			'deny_wp_json_crawling', 'deny_search_crawling',
		),
		// Whether the site emits Open Graph and Twitter cards at all.
		'wpseo_social' => array(
			'opengraph', 'twitter',
		),
	);
}

/**
 * Yoast keys that only exist once a site registers the post type or taxonomy.
 *
 * @return string[]
 */
function ranki_yoast_dynamic_keys(): array {
	$keys = array();
	foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
		$keys[] = "noindex-{$pt}";
		$keys[] = "display-metabox-pt-{$pt}";
		// What schema a page of this type carries. Yoast splits it in two: every page gets
		// a page type, and only Article-ish types get an article type.
		$keys[] = "schema-page-type-{$pt}";
		$keys[] = "schema-article-type-{$pt}";
		// The archive that lists a post type is a separate page and a separate setting from
		// the single. Noindexing testimonials while leaving /testimonials/ indexed is the
		// same thin content one URL further out.
		$keys[] = "noindex-ptarchive-{$pt}";
	}
	foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
		$keys[] = "noindex-tax-{$tax}";
	}
	return $keys;
}

/**
 * Business facts Ranki may write into Yoast.
 *
 * Deliberately shorter than the Rank Math list. Yoast free has no address, opening hours,
 * price range or LocalBusiness subtype, those live in the paid Yoast Local SEO addon and
 * in a different option again. Writing them here would write keys nothing reads, so they
 * are not offered rather than offered and quietly dropped.
 *
 * @return array<string, string[]>
 */
function ranki_yoast_local_seo_writable_keys(): array {
	return array(
		'wpseo_titles' => array(
			'company_or_person', 'company_name', 'company_alternate_name',
			'company_logo', 'company_logo_id',
			'website_name', 'alternate_website_name',
			'org-description', 'org-email', 'org-phone', 'org-legal-name',
		),
		'wpseo_social' => array(
			'facebook_site', 'instagram_url', 'linkedin_url', 'youtube_url',
			'twitter_site', 'pinterest_url', 'wikipedia_url', 'other_social_urls',
			'og_default_image', 'og_default_image_id',
		),
	);
}

/**
 * Read one Yoast setting, defaults included.
 *
 * Yoast only stores a key once it differs from its default, so reading the raw option
 * reports "nothing set" for a value the site is very much using. That turns a diff into a
 * list of changes that are not changes.
 *
 * @param string $key The Yoast option key.
 * @return mixed
 */
function ranki_yoast_get( string $key ) {
	if ( class_exists( 'WPSEO_Options' ) && method_exists( 'WPSEO_Options', 'get' ) ) {
		return WPSEO_Options::get( $key, null );
	}
	return null;
}

/**
 * Write one Yoast setting through Yoast's own API.
 *
 * WPSEO_Options::set() finds the option group the key belongs to and runs Yoast's own
 * save on it, which validates, persists and clears the cache. A raw update_option would
 * skip all three.
 *
 * It also has a trap worth naming. A key this Yoast version has never heard of matches
 * neither its lookup table nor its key patterns, so set() stores the value in a static
 * array for the rest of the request and returns null. Nothing is written and nothing
 * complains. enable_llms_txt is exactly that on any Yoast older than 25.x. So the return
 * is checked rather than assumed, and an unknown key is reported as skipped instead of
 * counted as a change that landed.
 *
 * @param string $key   The Yoast option key.
 * @param mixed  $value The value to store.
 * @return string 'saved', 'unknown' when this Yoast has no such setting, or 'failed'.
 */
function ranki_yoast_set( string $key, $value ): string {
	if ( ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'set' ) ) {
		return 'failed';
	}
	$result = WPSEO_Options::set( $key, $value );
	if ( null === $result ) {
		return 'unknown';
	}
	return $result ? 'saved' : 'failed';
}

/**
 * Apply a Yoast configuration, returning what each value was beforehand.
 *
 * @param array $payload Job payload with a `groups` map of option => fields.
 * @return array|WP_Error
 */
function ranki_apply_yoast_config( array $payload ) {
	$groups = $payload['groups'] ?? array();

	if ( ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'set' ) ) {
		return new WP_Error(
			'yoast_too_old',
			__( 'This Yoast SEO version is too old for Ranki to configure it. Update Yoast SEO.', 'ranki-publisher' ),
			array( 'status' => 400 )
		);
	}

	$allowed_by_group = ranki_yoast_seo_config_writable_keys();
	$dynamic          = ranki_yoast_dynamic_keys();
	$dry_run          = ! empty( $payload['preview'] );
	$changes          = array();
	$skipped          = array();

	foreach ( $groups as $option => $fields ) {
		if ( ! isset( $allowed_by_group[ $option ] ) || ! is_array( $fields ) ) {
			continue;
		}
		$allowed = array_merge( $allowed_by_group[ $option ], $dynamic );

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$skipped[] = $key;
				continue;
			}
			$before = ranki_yoast_get( $key );
			// Loose comparison on purpose. Yoast stores booleans, and a value that arrived
			// over JSON as true against a stored true is the same setting whatever PHP
			// thinks of the types.
			if ( null !== $before && $before == $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
				continue;
			}
			if ( ! $dry_run ) {
				$status = ranki_yoast_set( $key, $value );
				if ( 'saved' !== $status ) {
					$skipped[] = $key;
					continue;
				}
			}
			$changes[] = array(
				'option' => $option,
				'key'    => $key,
				'before' => $before,
				'after'  => $value,
			);
		}
	}

	if ( ! $dry_run && ! empty( $changes ) && method_exists( 'WPSEO_Options', 'clear_cache' ) ) {
		WPSEO_Options::clear_cache();
	}

	return array(
		'plugin'     => 'yoast',
		'preview'    => $dry_run,
		'changes'    => $changes,
		'changed'    => count( $changes ),
		// Keys this Yoast version does not have, or refused. Reported rather than swallowed:
		// a setting that did not land and one that was never sent look the same otherwise.
		'skipped'    => array_values( array_unique( $skipped ) ),
		'post_types' => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
		'taxonomies' => array_values( get_taxonomies( array( 'public' => true ), 'names' ) ),
	);
}

/**
 * Write a client's business facts into Yoast's site representation.
 *
 * Yoast free publishes an Organization, not a LocalBusiness, so there is nowhere for an
 * address, opening hours or a price range to go. Those keys are reported back as unsupported
 * rather than dropped in silence, because "we wrote it" and "Yoast cannot hold it" look
 * identical from Ranki's side otherwise.
 *
 * @param array $payload Job payload with `fields` and optional `social`.
 * @return array|WP_Error
 */
function ranki_apply_yoast_local_seo( array $payload ) {
	$fields = $payload['fields'] ?? array();
	$social = $payload['social'] ?? array();

	if ( ! is_array( $fields ) ) {
		$fields = array();
	}
	if ( ! is_array( $social ) ) {
		$social = array();
	}
	if ( empty( $fields ) && empty( $social ) ) {
		return new WP_Error( 'missing_fields', __( 'fields is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( ! class_exists( 'WPSEO_Options' ) || ! method_exists( 'WPSEO_Options', 'set' ) ) {
		return new WP_Error(
			'yoast_too_old',
			__( 'This Yoast SEO version is too old for Ranki to configure it. Update Yoast SEO.', 'ranki-publisher' ),
			array( 'status' => 400 )
		);
	}

	$allowed_by_group = ranki_yoast_local_seo_writable_keys();
	$written          = array();
	$unsupported      = array();
	$not_stored       = array();

	foreach ( array( 'wpseo_titles' => $fields, 'wpseo_social' => $social ) as $option => $values ) {
		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, $allowed_by_group[ $option ], true ) ) {
				$unsupported[] = $key;
				continue;
			}
			// An empty value means Ranki does not hold that fact. Leaving whatever is
			// already on the site is always better than blanking it.
			if ( '' === $value || null === $value || array() === $value ) {
				continue;
			}
			$status = ranki_yoast_set(
				$key,
				is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : sanitize_text_field( $value )
			);
			if ( 'saved' !== $status ) {
				// Not the same as "Ranki is not allowed to write this". This Yoast version
				// has no such setting, which is a different problem with a different fix.
				$not_stored[] = $key;
				continue;
			}
			$written[] = $key;
		}
	}

	if ( empty( $written ) ) {
		return new WP_Error( 'nothing_to_write', __( 'No writable fields had a value', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	if ( method_exists( 'WPSEO_Options', 'clear_cache' ) ) {
		WPSEO_Options::clear_cache();
	}

	// The schema is rendered into <head> on the front page, so a cached homepage keeps
	// serving the old markup until the cache is dropped.
	$front_id = (int) get_option( 'page_on_front' );
	if ( $front_id ) {
		ranki_purge_cache( $front_id, get_permalink( $front_id ) );
	}

	return array(
		'ok'          => true,
		'plugin'      => 'yoast',
		'written'     => $written,
		'unsupported' => array_values( array_unique( $unsupported ) ),
		'not_stored'  => array_values( array_unique( $not_stored ) ),
	);
}

/**
 * Apply a Rank Math configuration, returning what each value was beforehand.
 *
 * The before values are the point: Ranki shows them as a diff so a human approves
 * the actual change rather than a promise of one.
 *
 * @param array $payload Job payload with a `groups` map of option => fields.
 * @return array|WP_Error
 */
function ranki_apply_seo_config( array $payload ) {
	$groups = $payload['groups'] ?? array();
	if ( ! is_array( $groups ) || empty( $groups ) ) {
		return new WP_Error( 'missing_groups', __( 'groups is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	// Ranki names the plugin it built the payload for. The site is still asked, because
	// Ranki's record of which plugin a client runs is set at onboarding and goes stale the
	// day they switch. Writing Rank Math keys to a Yoast site is not an error anything
	// notices, it just writes options nothing reads.
	$active = ranki_active_seo_plugin();
	$wanted = $payload['plugin'] ?? $active;

	if ( '' === $active ) {
		return new WP_Error( 'no_seo_plugin', __( 'Neither Rank Math nor Yoast SEO is active on this site', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( $wanted !== $active ) {
		return new WP_Error(
			'seo_plugin_mismatch',
			/* translators: 1: SEO plugin Ranki expected, 2: SEO plugin actually active. */
			sprintf( __( 'Ranki has this site down as %1$s, but %2$s is what is active. Fix the SEO plugin on the client record first.', 'ranki-publisher' ), $wanted, $active ),
			array( 'status' => 400 )
		);
	}

	if ( 'yoast' === $active ) {
		return ranki_apply_yoast_config( $payload );
	}

	$allowed_by_group = ranki_seo_config_writable_keys();
	$dynamic          = ranki_dynamic_post_type_keys();
	$dry_run          = ! empty( $payload['preview'] );
	$changes          = array();

	foreach ( $groups as $option => $fields ) {
		if ( ! isset( $allowed_by_group[ $option ] ) || ! is_array( $fields ) ) {
			continue;
		}
		$existing = get_option( $option, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$allowed = array_merge( $allowed_by_group[ $option ], $dynamic );
		$touched = false;

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$before = $existing[ $key ] ?? null;
			if ( $before === $value ) {
				continue;
			}
			$changes[] = array(
				'option' => $option,
				'key'    => $key,
				'before' => $before,
				'after'  => $value,
			);
			$existing[ $key ] = $value;
			$touched          = true;
		}

		if ( $touched && ! $dry_run ) {
			update_option( $option, $existing );
		}
	}

	if ( ! $dry_run && ! empty( $changes ) && function_exists( 'rank_math' ) ) {
		// Rank Math caches its options, so a write without this shows the old values
		// until something else clears it.
		wp_cache_delete( 'rank_math_options_titles', 'options' );
		wp_cache_delete( 'rank_math_options_general', 'options' );
		wp_cache_delete( 'rank_math_options_sitemap', 'options' );
	}

	return array(
		'plugin'       => 'rankmath',
		'preview'      => $dry_run,
		'changes'      => $changes,
		'changed'      => count( $changes ),
		'post_types'   => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
	);
}

function ranki_local_seo_writable_keys(): array {
	return array(
		'knowledgegraph_name',
		'knowledgegraph_type',
		'local_business_type',
		'url',
		'email',
		'phone',
		'phone_numbers',
		'local_address',
		'price_range',
		'opening_hours',
		'social_url_facebook',
		'social_url_linkedin',
		'social_url_instagram',
		'social_url_youtube',
		'knowledgegraph_logo',
		'knowledgegraph_logo_id',
		// Confirmed against real client exports. description and homepage_description were
		// in this list and are not where Rank Math keeps a business description, so anything
		// written to them went nowhere.
		'website_name',
		'website_alternate_name',
		'organization_description',
		'additional_info',
		'geo',
	);
}

/**
 * The rest of a client's SEO foundation: robots.txt and the llms.txt settings.
 *
 * Kept apart from Local SEO because they live in the general option rather than titles, and
 * because robots.txt deserves its own line in a review. One wrong Disallow removes a site
 * from search, which is not a risk any other setting here carries.
 *
 * @return string[]
 */
function ranki_general_writable_keys(): array {
	return array(
		'robots_txt_content',
		'llms_post_types',
		'llms_taxonomies',
		'llms_limit',
		'llms_extra_content',
	);
}

/**
 * Write the Local SEO half of Rank Math's settings from the facts Ranki holds.
 *
 * Merges into rank_math_options_titles. It never replaces the option, because
 * that array also carries the site's title templates and archive settings, and
 * the previous approach (exporting a whole settings file for a human to import)
 * overwrote all of it.
 *
 * @param array $payload Job payload with a `fields` map.
 * @return array|WP_Error Summary of what was written.
 */
function ranki_apply_local_seo( array $payload ) {
	$fields = $payload['fields'] ?? array();

	$active = ranki_active_seo_plugin();
	$wanted = $payload['plugin'] ?? $active;

	if ( '' === $active ) {
		return new WP_Error( 'no_seo_plugin', __( 'Neither Rank Math nor Yoast SEO is active on this site', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( $wanted !== $active ) {
		return new WP_Error(
			'seo_plugin_mismatch',
			/* translators: 1: SEO plugin Ranki expected, 2: SEO plugin actually active. */
			sprintf( __( 'Ranki has this site down as %1$s, but %2$s is what is active. Fix the SEO plugin on the client record first.', 'ranki-publisher' ), $wanted, $active ),
			array( 'status' => 400 )
		);
	}

	if ( 'yoast' === $active ) {
		return ranki_apply_yoast_local_seo( $payload );
	}

	if ( ! is_array( $fields ) || empty( $fields ) ) {
		return new WP_Error( 'missing_fields', __( 'fields is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$existing = get_option( 'rank_math_options_titles', array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	// A payload may also carry general-option settings (robots.txt, llms.txt). They are
	// allowlisted separately and written to their own option, so one job can set up a
	// client's whole foundation instead of needing three.
	$general_fields = $payload['general'] ?? array();
	if ( is_array( $general_fields ) && ! empty( $general_fields ) ) {
		$general_existing = get_option( 'rank_math_options_general', array() );
		if ( ! is_array( $general_existing ) ) {
			$general_existing = array();
		}
		$general_allowed = ranki_general_writable_keys();
		$general_touched = false;
		foreach ( $general_fields as $gkey => $gvalue ) {
			if ( ! in_array( $gkey, $general_allowed, true ) ) {
				continue;
			}
			if ( '' === $gvalue || null === $gvalue || array() === $gvalue ) {
				continue;
			}
			$general_existing[ $gkey ] = $gvalue;
			$general_touched            = true;
		}
		if ( $general_touched ) {
			update_option( 'rank_math_options_general', $general_existing );
			wp_cache_delete( 'rank_math_options_general', 'options' );
		}
	}

	$allowed = ranki_local_seo_writable_keys();
	$written = array();

	foreach ( $fields as $key => $value ) {
		if ( ! in_array( $key, $allowed, true ) ) {
			continue;
		}
		// An empty value means Ranki does not hold that fact. Leaving whatever is
		// already on the site is always better than blanking it.
		if ( '' === $value || null === $value || array() === $value ) {
			continue;
		}
		$existing[ $key ] = is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : sanitize_text_field( $value );
		$written[]        = $key;
	}

	if ( empty( $written ) ) {
		return new WP_Error( 'nothing_to_write', __( 'No writable fields had a value', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	update_option( 'rank_math_options_titles', $existing );

	// The schema is rendered into <head> on the front page, so a cached homepage
	// keeps serving the old markup until the cache is dropped.
	$front_id = (int) get_option( 'page_on_front' );
	if ( $front_id ) {
		ranki_purge_cache( $front_id, get_permalink( $front_id ) );
	}

	return array(
		'ok'      => true,
		'written' => $written,
	);
}

/**
 * REST callback: set or replace the schema JSON-LD for an existing post.
 * Accepts {post_id, schema_jsonld} and stores validated JSON in _ranki_schema_jsonld.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_set_schema( WP_REST_Request $request ) {
	$params  = $request->get_json_params();
	$post_id = absint( $params['post_id'] ?? 0 );
	$raw     = $params['schema_jsonld'] ?? '';

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( '' === $raw ) {
		return new WP_Error( 'missing_schema', __( 'schema_jsonld is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		/* translators: %d: WordPress post ID */
		return new WP_Error( 'not_found', sprintf( __( 'Post %d not found', 'ranki-publisher' ), $post_id ), array( 'status' => 404 ) );
	}

	$decoded = json_decode( $raw, true );
	if ( null === $decoded ) {
		return new WP_Error( 'invalid_json', __( 'schema_jsonld must be valid JSON', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$schema = wp_json_encode( $decoded );
	update_post_meta( $post_id, '_ranki_schema_jsonld', $schema );
	ranki_purge_cache( $post_id, get_permalink( $post_id ) );

	return rest_ensure_response( array(
		'ok'      => true,
		'post_id' => $post_id,
	) );
}

/**
 * REST callback: receive a browser-side conversion event (form lead or phone click)
 * and forward it to the Ranki backend for storage.
 *
 * Authenticated by nonce rather than X-Ranki-Key because this endpoint is
 * called from visitor browsers, not the Ranki server.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
/**
 * Recover historical form submissions from a client's own form-plugin database
 * and report them back to Ranki so past leads that pre-date (or were missed by)
 * the JS tracker still count. Only Elementor Pro's submissions table is read
 * today, since it's a persistent DB table on the site itself, not something
 * Ranki can reconstruct after the fact.
 *
 * dry_run just probes the table (row count + a few raw sample rows) so Ranki
 * can confirm the count and column names match before requesting a real pull.
 */
function ranki_handle_export_leads( array $payload, string $job_id, string $api_base, string $key ) {
	global $wpdb;

	$dry_run = ! empty( $payload['dry_run'] );

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Which form plugins are installed matters more than what we can see from
	// outside the site (SiteGround and similar hosts block that kind of check
	// entirely). Only Elementor's table is actually read below, this list is
	// diagnostic so Ranki knows which clients need a different reader built.
	$plugin_files = array(
		'elementor'      => 'elementor-pro/elementor-pro.php',
		'cf7'            => 'contact-form-7/wp-contact-form-7.php',
		'flamingo'       => 'flamingo/flamingo.php',
		'wpforms'        => 'wpforms/wpforms.php',
		'wpforms_lite'   => 'wpforms-lite/wpforms.php',
		'gravity'        => 'gravityforms/gravityforms.php',
		'ninja'          => 'ninja-forms/ninja-forms.php',
		'fluentform'     => 'fluentform/fluentform.php',
		'fluentform_pro' => 'fluentformpro/fluentformpro.php',
		'formidable'     => 'formidable/formidable.php',
	);
	$active_plugins = array();
	foreach ( $plugin_files as $label => $path ) {
		if ( is_plugin_active( $path ) ) {
			$active_plugins[] = $label;
		}
	}

	$table_checks = array(
		'elementor'      => $wpdb->prefix . 'e_submissions',
		'wpforms'        => $wpdb->prefix . 'wpforms_entries',
		'gravity'        => $wpdb->prefix . 'gf_entry',
		'gravity_legacy' => $wpdb->prefix . 'rg_lead',
		'fluentform'     => $wpdb->prefix . 'fluentform_submissions',
		'formidable'     => $wpdb->prefix . 'frm_items',
	);
	$tables = array();
	foreach ( $table_checks as $label => $table ) {
		$exists           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		$tables[ $label ] = array(
			'exists' => $exists,
			'count'  => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0,
		);
	}
	// Ninja Forms and Flamingo (CF7's storage add-on) keep entries as custom
	// post types rather than their own tables.
	foreach ( array( 'ninja' => 'nf_sub', 'flamingo' => 'flamingo_inbound' ) as $label => $post_type ) {
		$count            = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
		$tables[ $label ] = array( 'exists' => $count > 0, 'count' => $count );
	}

	$result = array(
		'job_id'         => $job_id,
		'dry_run'        => $dry_run,
		'active_plugins' => $active_plugins,
		'tables'         => $tables,
		'count'          => $tables['elementor']['count'],
		'sample'         => array(),
		'leads'          => array(),
	);

	if ( $tables['elementor']['exists'] ) {
		if ( $dry_run ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}e_submissions ORDER BY id DESC LIMIT 3", ARRAY_A );
			// Cap field lengths - some columns (actions_data, user_agent) can be long.
			foreach ( $rows as &$row ) {
				foreach ( $row as $field => $value ) {
					$row[ $field ] = is_string( $value ) ? mb_substr( $value, 0, 200 ) : $value;
				}
			}
			unset( $row );
			$result['sample'] = $rows;
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}e_submissions ORDER BY id ASC", ARRAY_A );
			foreach ( $rows as $row ) {
				$created = $row['created_at'] ?? $row['date'] ?? $row['submitted_at'] ?? null;
				if ( ! $created ) {
					continue;
				}
				$result['leads'][] = array(
					'occurred_at' => gmdate( 'c', strtotime( $created ) ),
					'page_url'    => $row['referer'] ?? $row['current_url'] ?? $row['page_url'] ?? '',
					'form_type'   => 'elementor',
				);
			}
		}
	} else {
		$result['note'] = 'no e_submissions table found';
	}

	wp_remote_post(
		$api_base . '/wp-sync/leads-export',
		array(
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'X-Ranki-Key'  => $key,
			),
			'body'      => wp_json_encode( $result ),
		)
	);
}

function ranki_handle_event( WP_REST_Request $request ) {
	$params     = $request->get_json_params();
	$nonce      = sanitize_text_field( $params['nonce'] ?? '' );
	$event_type = sanitize_text_field( $params['type'] ?? '' );
	$page_url   = esc_url_raw( $params['page_url'] ?? '' );
	$landing    = esc_url_raw( $params['landing_url'] ?? '' );
	$form_type  = sanitize_text_field( $params['form_type'] ?? '' );
	$phone      = sanitize_text_field( $params['phone_number'] ?? '' );
	$timestamp  = sanitize_text_field( $params['timestamp'] ?? '' );

	if ( ! wp_verify_nonce( $nonce, 'ranki_tracker' ) ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}

	if ( ! in_array( $event_type, array( 'form_lead', 'phone_click' ), true ) ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}

	// This endpoint is reachable by any visitor and a nonce isn't single-use,
	// so cap how many events one IP can send in a short window — otherwise a
	// scraped nonce could be replayed to flood the client's Leads dashboard
	// with fake entries.
	$ip           = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
	$rate_key     = 'ranki_evt_' . md5( $ip );
	$recent_count = (int) get_transient( $rate_key );
	if ( $recent_count >= 20 ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}
	set_transient( $rate_key, $recent_count + 1, MINUTE_IN_SECONDS );

	$key = get_option( RANKI_OPTION_KEY, '' );
	if ( ! $key ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}

	// Fire-and-forget — blocking=false so the visitor's request returns immediately.
	wp_remote_post(
		RANKI_API_BASE . '/wp-sync/event',
		array(
			'timeout'   => 5,
			'sslverify' => true,
			'blocking'  => false,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'X-Ranki-Key'  => $key,
			),
			'body'      => wp_json_encode( array(
				'type'         => $event_type,
				'page_url'     => $page_url,
				'landing_url'  => $landing ?: null,
				'form_type'    => $form_type ?: null,
				'phone_number' => $phone ?: null,
				'timestamp'    => $timestamp,
			) ),
		)
	);

	return rest_ensure_response( array( 'ok' => true ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Output schema JSON-LD in <head> for posts published by Ranki
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}
	$schema = get_post_meta( $post_id, '_ranki_schema_jsonld', true );
	if ( ! $schema ) {
		return;
	}

	$decoded = json_decode( $schema, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}

	// If a dedicated SEO plugin already builds the page schema, don't emit a
	// competing graph. Keep only FAQPage (which those plugins don't generate and
	// which still feeds AI engines) and let the SEO plugin own everything else.
	$seo_plugin_active = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' )
		|| defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' );
	if ( $seo_plugin_active && isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
		$faq_nodes = array_values( array_filter(
			$decoded['@graph'],
			function ( $node ) {
				return is_array( $node ) && isset( $node['@type'] ) && 'FAQPage' === $node['@type'];
			}
		) );
		if ( empty( $faq_nodes ) ) {
			return;
		}
		$decoded['@graph'] = $faq_nodes;
	}

	// Re-encode through PHP's JSON encoder to neutralise any injection attempts.
	$safe = wp_json_encode( $decoded );
	if ( ! $safe || 'null' === $safe ) {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe: output is wp_json_encode of decoded JSON
	echo '<script type="application/ld+json">' . $safe . '</script>' . "\n";
} );
