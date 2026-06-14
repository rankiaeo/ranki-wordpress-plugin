<?php
/**
 * Plugin Name:       Ranki Publisher
 * Plugin URI:        https://github.com/rankiaeo/ranki-wordpress-plugin
 * Description:       Connects your WordPress site to Ranki for automated AI SEO content publishing. Install this plugin, then copy your secret key from Settings → Ranki Publisher into your Ranki admin panel.
 * Version:           1.7.2
 * Author:            Ranki
 * Author URI:        https://ranki.com.au
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ranki-publisher
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Tested up to:      6.9
 */

defined( 'ABSPATH' ) || exit;

define( 'RANKI_VERSION',    '1.7.2' );
define( 'RANKI_OPTION_KEY', 'ranki_secret_key' );
define( 'RANKI_API_BASE',   'https://ranki-backend-production.up.railway.app/api' );

// Load translations.
add_action( 'init', function () {
	load_plugin_textdomain( 'ranki-publisher', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// Enqueue lead + call tracker on all public pages.
add_action( 'wp_enqueue_scripts', function () {
	$key = get_option( RANKI_OPTION_KEY, '' );
	if ( ! $key ) {
		return;
	}
	wp_enqueue_script(
		'ranki-tracker',
		plugin_dir_url( __FILE__ ) . 'ranki-tracker.js',
		array(),
		RANKI_VERSION,
		true
	);
	wp_localize_script( 'ranki-tracker', 'rankiTracker', array(
		'eventUrl' => rest_url( 'ranki/v1/event' ),
		'nonce'    => wp_create_nonce( 'ranki_tracker' ),
	) );
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
	<div class="wrap">
		<h1><?php esc_html_e( 'Ranki Publisher', 'ranki-publisher' ); ?></h1>
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

// Handle key regeneration form submission.
add_action( 'admin_init', function () {
	if (
		isset( $_POST['ranki_action'] ) &&
		'regen_key' === $_POST['ranki_action'] &&
		check_admin_referer( 'ranki_regen' ) &&
		current_user_can( 'manage_options' )
	) {
		update_option( RANKI_OPTION_KEY, wp_generate_password( 40, false ) );
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
				'Accept'      => 'application/json',
				'X-Ranki-Key' => $key,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return; // Silent fail — will retry in 5 minutes.
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
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
		} else {
			$result = ranki_handle_publish( $request );
			if ( is_wp_error( $result ) ) {
				ranki_report_job_done( $api_base, $key, $job_id, false, 0, '', $result->get_error_message() );
			} else {
				$data = $result->get_data();
				ranki_report_job_done( $api_base, $key, $job_id, true, $data['post_id'] ?? 0, $data['post_url'] ?? '' );
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
	$allowed = array( 'publish', 'upload-image', 'ping', 'update-content', 'set-schema' );
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
 * REST callback: publish a new post with SEO metadata and optional featured image.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function ranki_handle_publish( WP_REST_Request $request ) {
	$params = $request->get_json_params();

	$title      = sanitize_text_field( $params['title'] ?? '' );
	$content    = wp_kses_post( $params['content'] ?? '' );
	// Slug: preserve non-ASCII scripts (Hebrew, Arabic, etc.) that sanitize_title() strips.
	$raw_slug = trim( $params['slug'] ?? '' );
	if ( $raw_slug && preg_match( '/[^\x00-\x7F]/u', $raw_slug ) ) {
		// Unicode slug — WordPress supports non-ASCII post_name natively.
		// Lower-case, collapse whitespace to hyphens, strip anything that isn't
		// a Unicode letter/number or a hyphen.
		$slug = mb_strtolower( $raw_slug, 'UTF-8' );
		$slug = preg_replace( '/\s+/u', '-', $slug );
		$slug = preg_replace( '/[^\p{L}\p{N}\-]/u', '', $slug );
		$slug = trim( $slug, '-' );
	} else {
		$slug = sanitize_title( $raw_slug );
	}
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
	$media_id  = 0;
	$media_url = '';
	if ( $image_b64 ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$image_bytes = base64_decode( $image_b64, true );
		if ( false !== $image_bytes ) {
			// Verify MIME type from actual file bytes before uploading.
			$tmp      = tmpfile();
			fwrite( $tmp, $image_bytes );
			$tmp_meta = stream_get_meta_data( $tmp );
			$real_mime = mime_content_type( $tmp_meta['uri'] );
			fclose( $tmp );

			$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
			if ( ! in_array( $real_mime, $allowed_mimes, true ) ) {
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
			}
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
	// user. We default to user ID 1 (the first admin). Site owners can
	// override this via the ranki_post_author filter.
	$post_author = absint( apply_filters( 'ranki_post_author', 1 ) );

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

	$post_id = wp_insert_post( $post_data, true );
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
		'ok'        => true,
		'post_id'   => $post_id,
		'post_url'  => $post_url,
		'media_id'  => $media_id,
		'media_url' => $media_url,
		'category'  => $cat_name,
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

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}
	if ( '' === $content ) {
		return new WP_Error( 'missing_content', __( 'content is required', 'ranki-publisher' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		/* translators: %d: WordPress post ID */
		return new WP_Error( 'not_found', sprintf( __( 'Post %d not found', 'ranki-publisher' ), $post_id ), array( 'status' => 404 ) );
	}

	$result = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => wp_kses_post( $content ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array(
		'ok'      => true,
		'post_id' => $post_id,
	) );
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
function ranki_handle_event( WP_REST_Request $request ) {
	$params     = $request->get_json_params();
	$nonce      = sanitize_text_field( $params['nonce'] ?? '' );
	$event_type = sanitize_text_field( $params['type'] ?? '' );
	$page_url   = esc_url_raw( $params['page_url'] ?? '' );
	$form_type  = sanitize_text_field( $params['form_type'] ?? '' );
	$phone      = sanitize_text_field( $params['phone_number'] ?? '' );
	$timestamp  = sanitize_text_field( $params['timestamp'] ?? '' );

	if ( ! wp_verify_nonce( $nonce, 'ranki_tracker' ) ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}

	if ( ! in_array( $event_type, array( 'form_lead', 'phone_click' ), true ) ) {
		return rest_ensure_response( array( 'ok' => false ) );
	}

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
