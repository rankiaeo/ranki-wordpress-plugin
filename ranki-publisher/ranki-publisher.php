<?php
/**
 * Plugin Name: Ranki Publisher
 * Plugin URI:  https://ranki.com.au
 * Description: Connects your WordPress site to Ranki for automated SEO content publishing. Install this plugin so Ranki can publish articles, upload images, and set SEO metadata automatically.
 * Version:     1.6.0
 * Author:      Ranki
 * Author URI:  https://ranki.com.au
 * License:     GPL-2.0+
 * Text Domain: ranki-publisher
 */

defined('ABSPATH') || exit;

define('RANKI_VERSION', '1.6.0');
define('RANKI_OPTION_KEY', 'ranki_secret_key');
define('RANKI_API_BASE', 'https://ranki-backend-production.up.railway.app');

// ─────────────────────────────────────────────────────────────────────────────
// Activation: generate a secret key
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    if (!get_option(RANKI_OPTION_KEY)) {
        update_option(RANKI_OPTION_KEY, wp_generate_password(40, false));
    }
    ranki_update_htaccess_whitelist();
    // Schedule the pull-queue cron if not already scheduled
    if (!wp_next_scheduled('ranki_sync_cron')) {
        wp_schedule_event(time(), 'ranki_every_5min', 'ranki_sync_cron');
    }
});

function ranki_update_htaccess_whitelist() {
    $htaccess = ABSPATH . '.htaccess';
    if (!file_exists($htaccess) || !is_writable($htaccess)) return;

    $content = file_get_contents($htaccess);
    $marker = '# BEGIN Ranki Publisher';
    if (strpos($content, $marker) !== false) return; // already added

    $rules = <<<HTACCESS

# BEGIN Ranki Publisher
# Whitelist Cloudflare Worker IPs used by Ranki for content publishing
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP:X-Ranki-Key} !^$
  RewriteRule .* - [E=NOCONNTIMEOUT:1]
</IfModule>
<IfModule mod_setenvif.c>
  SetEnvIf X-Ranki-Key ".+" RANKI_REQUEST
</IfModule>
<IfModule mod_authz_core.c>
  <If "env('RANKI_REQUEST') == '1'">
    Require all granted
  </If>
</IfModule>
# END Ranki Publisher
HTACCESS;

    file_put_contents($htaccess, $rules . "\n" . $content);
}

// Also add on plugin update
add_action('upgrader_process_complete', function ($upgrader, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        $plugins = $options['plugins'] ?? [];
        if (in_array(plugin_basename(__FILE__), $plugins)) {
            ranki_update_htaccess_whitelist();
        }
    }
}, 10, 2);

// Remove on deactivation
register_deactivation_hook(__FILE__, function () {
    // Remove .htaccess rules
    $htaccess = ABSPATH . '.htaccess';
    if (file_exists($htaccess) && is_writable($htaccess)) {
        $content = file_get_contents($htaccess);
        $content = preg_replace('/\n?# BEGIN Ranki Publisher.*?# END Ranki Publisher\n?/s', '', $content);
        file_put_contents($htaccess, $content);
    }
    // Remove cron schedule
    $timestamp = wp_next_scheduled('ranki_sync_cron');
    if ($timestamp) wp_unschedule_event($timestamp, 'ranki_sync_cron');
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin settings page — shows the secret key + connect URL
// ─────────────────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_options_page(
        'Ranki Publisher',
        'Ranki Publisher',
        'manage_options',
        'ranki-publisher',
        'ranki_settings_page'
    );
});

function ranki_settings_page() {
    $key      = get_option(RANKI_OPTION_KEY, '');
    $site_url = get_site_url();
    $endpoint = $site_url . '/wp-json/ranki/v1/publish';
    ?>
    <div class="wrap">
        <h1>Ranki Publisher</h1>
        <p>Copy the details below into your Ranki admin panel to connect this WordPress site.</p>

        <table class="form-table">
            <tr>
                <th>Site URL</th>
                <td>
                    <code><?php echo esc_html($site_url); ?></code>
                    <button class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($site_url); ?>');this.textContent='Copied!'">Copy</button>
                </td>
            </tr>
            <tr>
                <th>Ranki Secret Key</th>
                <td>
                    <code><?php echo esc_html($key); ?></code>
                    <button class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($key); ?>');this.textContent='Copied!'">Copy</button>
                    <p class="description">Keep this secret. It authorises Ranki to publish content on your behalf.</p>
                </td>
            </tr>
        </table>

        <h2>Regenerate Key</h2>
        <p>If you think your key has been compromised, regenerate it and update it in Ranki.</p>
        <form method="post">
            <?php wp_nonce_field('ranki_regen'); ?>
            <input type="hidden" name="ranki_action" value="regen_key">
            <button type="submit" class="button button-secondary">Regenerate Secret Key</button>
        </form>
    </div>
    <?php
}

// Handle key regeneration
add_action('admin_init', function () {
    if (
        isset($_POST['ranki_action']) &&
        $_POST['ranki_action'] === 'regen_key' &&
        check_admin_referer('ranki_regen')
    ) {
        update_option(RANKI_OPTION_KEY, wp_generate_password(40, false));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success"><p>Ranki secret key regenerated.</p></div>';
        });
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Pull Queue — cron poller (runs every 5 min via WP-Cron)
// WordPress calls Ranki, gets pending jobs, publishes locally, reports back.
// This completely bypasses host firewalls — all requests are outbound from WP.
// ─────────────────────────────────────────────────────────────────────────────

// Register custom 5-minute schedule
add_filter('cron_schedules', function ($schedules) {
    $schedules['ranki_every_5min'] = [
        'interval' => 300,
        'display'  => 'Every 5 Minutes (Ranki)',
    ];
    return $schedules;
});

// Hook cron action
add_action('ranki_sync_cron', 'ranki_process_queue');

// Ensure cron stays scheduled (re-registers if somehow cleared)
add_action('init', function () {
    if (!wp_next_scheduled('ranki_sync_cron')) {
        wp_schedule_event(time(), 'ranki_every_5min', 'ranki_sync_cron');
    }
});

/**
 * Main pull-queue processor. Called every 5 minutes via WP-Cron.
 * Fetches pending jobs from Ranki, processes them locally, reports back.
 */
function ranki_process_queue() {
    $key = get_option(RANKI_OPTION_KEY, '');
    if (!$key) return;

    $api_base = defined('RANKI_API_BASE') ? RANKI_API_BASE : 'https://ranki-backend-production.up.railway.app';

    // Fetch pending jobs — key sent in header, never in URL (avoids log exposure)
    $response = wp_remote_get("{$api_base}/wp-sync/poll", [
        'timeout'   => 15,
        'headers'   => [
            'Accept'      => 'application/json',
            'X-Ranki-Key' => $key,
        ],
        'sslverify' => true,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return; // silent fail — will retry in 5 min
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $jobs = $body['jobs'] ?? [];
    if (empty($jobs)) return;

    foreach ($jobs as $job) {
        ranki_process_single_job($job, $api_base, $key);
    }
}

/**
 * Process a single queue job: publish or upload-image.
 */
function ranki_process_single_job(array $job, string $api_base, string $key): void {
    $job_id  = $job['id'];
    $payload = $job['payload'] ?? [];

    if (empty($payload)) {
        ranki_report_job_done($api_base, $key, $job_id, false, 0, '', 'Empty payload');
        return;
    }

    // Build a WP_REST_Request from the payload
    $raw     = json_encode($payload);
    $request = new WP_REST_Request('POST');
    $request->set_body($raw);
    $request->set_header('Content-Type', 'application/json');

    $action = $payload['action'] ?? 'publish'; // default to publish

    try {
        if ($action === 'upload-image') {
            $result = ranki_handle_upload_image($request);
            if (is_wp_error($result)) {
                ranki_report_job_done($api_base, $key, $job_id, false, 0, '', $result->get_error_message());
            } else {
                $data = $result->get_data();
                ranki_report_job_done($api_base, $key, $job_id, true, $data['media_id'] ?? 0, '');
            }
        } elseif ($action === 'update-content') {
            $result = ranki_handle_update_content($request);
            if (is_wp_error($result)) {
                ranki_report_job_done($api_base, $key, $job_id, false, 0, '', $result->get_error_message());
            } else {
                ranki_report_job_done($api_base, $key, $job_id, true, $payload['post_id'] ?? 0, '');
            }
        } else {
            // Default: publish
            $result = ranki_handle_publish($request);
            if (is_wp_error($result)) {
                ranki_report_job_done($api_base, $key, $job_id, false, 0, '', $result->get_error_message());
            } else {
                $data = $result->get_data();
                ranki_report_job_done($api_base, $key, $job_id, true, $data['post_id'] ?? 0, $data['post_url'] ?? '');
            }
        }
    } catch (Exception $e) {
        ranki_report_job_done($api_base, $key, $job_id, false, 0, '', $e->getMessage());
    }
}

/**
 * Report job outcome back to Ranki API.
 */
function ranki_report_job_done(string $api_base, string $key, string $job_id, bool $success, int $post_id, string $post_url, string $error = ''): void {
    wp_remote_post("{$api_base}/wp-sync/done", [
        'timeout'   => 10,
        'headers'   => [
            'Content-Type' => 'application/json',
            'X-Ranki-Key'  => $key,
        ],
        'body'      => json_encode([
            'job_id'   => $job_id,
            'success'  => $success,
            'post_id'  => $post_id,
            'post_url' => $post_url,
            'error'    => $error,
        ]),
        'sslverify' => true,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Auto-update — hooks into WordPress's native plugin update system.
// When a new version is published at ranki.com.au, every client's WP admin
// sees the standard "Plugin Update Available" notice and can one-click update.
// ─────────────────────────────────────────────────────────────────────────────

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_file = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_file] ?? RANKI_VERSION;

    // Check for updates (cache result for 12 hours)
    $cached = get_transient('ranki_plugin_update_info');
    if (!$cached) {
        $resp = wp_remote_get('https://ranki.com.au/api/plugin/info', [
            'timeout'  => 5,
            'sslverify' => true,
        ]);
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $cached = json_decode(wp_remote_retrieve_body($resp), true);
            if ($cached) set_transient('ranki_plugin_update_info', $cached, 12 * HOUR_IN_SECONDS);
        }
    }

    if ($cached && version_compare($cached['version'] ?? '0', $current_version, '>')) {
        $transient->response[$plugin_file] = (object) [
            'slug'        => 'ranki-publisher',
            'plugin'      => $plugin_file,
            'new_version' => $cached['version'],
            'url'         => 'https://ranki.com.au',
            'package'     => $cached['download_url'] ?? '',
            'tested'      => $cached['tested'] ?? '6.5',
            'requires'    => $cached['requires'] ?? '5.6',
        ];
    }

    return $transient;
});

// Provide plugin info for the "View version details" popup
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'ranki-publisher') {
        return $result;
    }
    $cached = get_transient('ranki_plugin_update_info');
    if (!$cached) return $result;

    return (object) [
        'name'          => 'Ranki Publisher',
        'slug'          => 'ranki-publisher',
        'version'       => $cached['version'] ?? RANKI_VERSION,
        'author'        => '<a href="https://ranki.com.au">Ranki</a>',
        'homepage'      => 'https://ranki.com.au',
        'short_description' => 'Connects your WordPress site to Ranki for automated SEO content publishing.',
        'download_link' => $cached['download_url'] ?? '',
        'requires'      => $cached['requires'] ?? '5.6',
        'tested'        => $cached['tested'] ?? '6.5',
        'sections'      => [
            'description' => 'Connects your WordPress site to Ranki for automated SEO content publishing, including the pull-queue cron system that bypasses host firewalls.',
            'changelog'   => $cached['changelog'] ?? '',
        ],
    ];
}, 10, 3);

// ─────────────────────────────────────────────────────────────────────────────
// REST API endpoints (kept for non-blocked hosts)
// ─────────────────────────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('ranki/v1', '/publish', [
        'methods'             => 'POST',
        'callback'            => 'ranki_handle_publish',
        'permission_callback' => 'ranki_check_auth',
    ]);
    register_rest_route('ranki/v1', '/upload-image', [
        'methods'             => 'POST',
        'callback'            => 'ranki_handle_upload_image',
        'permission_callback' => 'ranki_check_auth',
    ]);
    register_rest_route('ranki/v1', '/ping', [
        'methods'             => 'GET',
        'callback'            => function () {
            return rest_ensure_response([
                'ok'      => true,
                'version' => RANKI_VERSION,
                'site'    => get_site_url(),
            ]);
        },
        'permission_callback' => 'ranki_check_auth',
    ]);
    register_rest_route('ranki/v1', '/update-content', [
        'methods'             => 'POST',
        'callback'            => 'ranki_handle_update_content',
        'permission_callback' => 'ranki_check_auth',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// DIRECT ENDPOINT — bypasses /wp-json/ entirely (for hosts like SiteGround
// that block all REST API requests from external IPs)
// URL: https://yoursite.com/?ranki_action=publish|upload-image|ping
// ─────────────────────────────────────────────────────────────────────────────
add_action('template_redirect', function () {
    if (!isset($_GET['ranki_action'])) return;

    $action = sanitize_text_field($_GET['ranki_action']);
    $allowed = ['publish', 'upload-image', 'ping', 'update-content'];
    if (!in_array($action, $allowed, true)) return;

    // Auth check — key from header or query param
    $provided = $_SERVER['HTTP_X_RANKI_KEY'] ?? ($_GET['ranki_key'] ?? '');
    $stored = get_option(RANKI_OPTION_KEY, '');
    if (!$stored || !hash_equals($stored, (string) $provided)) {
        wp_send_json(['error' => 'Unauthorized'], 401);
        exit;
    }

    if ($action === 'ping') {
        wp_send_json(['ok' => true, 'version' => RANKI_VERSION, 'site' => get_site_url()]);
        exit;
    }

    // Read JSON body
    $raw = file_get_contents('php://input');
    $params = json_decode($raw, true);
    if (!$params) {
        wp_send_json(['error' => 'Invalid JSON body'], 400);
        exit;
    }

    // Build a fake WP_REST_Request so we can reuse the same handlers
    $request = new WP_REST_Request('POST');
    $request->set_body($raw);
    $request->set_header('Content-Type', 'application/json');

    if ($action === 'publish') {
        $result = ranki_handle_publish($request);
    } elseif ($action === 'upload-image') {
        $result = ranki_handle_upload_image($request);
    } elseif ($action === 'update-content') {
        $result = ranki_handle_update_content($request);
    }

    if (is_wp_error($result)) {
        wp_send_json([
            'error' => $result->get_error_message(),
            'code'  => $result->get_error_code(),
        ], $result->get_error_data()['status'] ?? 500);
    } else {
        wp_send_json($result->get_data());
    }
    exit;
});

function ranki_check_auth(WP_REST_Request $request): bool {
    $provided = $request->get_header('X-Ranki-Key');
    if (!$provided) {
        $provided = $request->get_param('ranki_key');
    }
    $stored = get_option(RANKI_OPTION_KEY, '');
    return $stored && hash_equals($stored, (string) $provided);
}

function ranki_handle_upload_image(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $params = $request->get_json_params();
    $image_b64 = $params['image_base64'] ?? '';
    $image_name = sanitize_file_name($params['image_filename'] ?? 'image.png');
    $image_alt = sanitize_text_field($params['image_alt'] ?? '');

    if (!$image_b64) {
        return new WP_Error('missing_image', 'image_base64 is required', ['status' => 400]);
    }

    $image_bytes = base64_decode($image_b64);
    if (!$image_bytes) {
        return new WP_Error('invalid_image', 'Could not decode base64 image', ['status' => 400]);
    }

    $upload = wp_upload_bits($image_name, null, $image_bytes);
    if ($upload['error']) {
        return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
    }

    $wp_filetype = wp_check_filetype($image_name);
    $attachment = [
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => $image_alt ?: $image_name,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $media_id = wp_insert_attachment($attachment, $upload['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($media_id, $upload['file']);
    wp_update_attachment_metadata($media_id, $metadata);
    if ($image_alt) {
        update_post_meta($media_id, '_wp_attachment_image_alt', $image_alt);
    }

    return rest_ensure_response([
        'ok'        => true,
        'media_id'  => $media_id,
        'media_url' => $upload['url'],
    ]);
}

function ranki_handle_publish(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $params = $request->get_json_params();

    $title       = sanitize_text_field($params['title'] ?? '');
    $content     = wp_kses_post($params['content'] ?? '');
    $slug        = sanitize_title($params['slug'] ?? '');
    $excerpt     = sanitize_text_field($params['excerpt'] ?? '');
    $status      = in_array($params['status'] ?? 'publish', ['publish', 'draft', 'pending']) ? $params['status'] : 'publish';
    $image_b64   = $params['image_base64'] ?? '';
    $image_name  = sanitize_file_name($params['image_filename'] ?? 'featured.jpg');
    $image_alt   = sanitize_text_field($params['image_alt'] ?? $title);
    $schema      = $params['schema_jsonld'] ?? '';

    // Focus keyword + SEO meta
    $focus_kw    = sanitize_text_field($params['focus_keyword'] ?? '');
    $seo_title   = sanitize_text_field($params['seo_title'] ?? $title);
    $meta_desc   = sanitize_text_field($params['meta_description'] ?? '');
    $seo_plugin  = sanitize_text_field($params['seo_plugin'] ?? 'rankmath');
    $category    = sanitize_text_field($params['category'] ?? '');  // optional: name or ID

    if (!$title || !$content) {
        return new WP_Error('missing_fields', 'title and content are required', ['status' => 400]);
    }

    // ── 1. Upload featured image ──────────────────────────────────────────────
    $media_id = 0;
    $media_url = '';
    if ($image_b64) {
        $image_bytes = base64_decode($image_b64);
        if ($image_bytes) {
            // Verify actual bytes are a real image before uploading
            $tmp = tmpfile();
            fwrite($tmp, $image_bytes);
            $tmp_path = stream_get_meta_data($tmp)['uri'];
            $real_mime = mime_content_type($tmp_path);
            fclose($tmp);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($real_mime, $allowed_mimes, true)) {
                return new WP_Error('invalid_image', 'Image must be jpeg, png, gif or webp', ['status' => 400]);
            }
            $upload = wp_upload_bits($image_name, null, $image_bytes);
            if (!$upload['error']) {
                $wp_filetype = wp_check_filetype($image_name);
                $attachment  = [
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title'     => $image_alt,
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ];
                $media_id  = wp_insert_attachment($attachment, $upload['file']);
                $media_url = $upload['url'];
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $metadata = wp_generate_attachment_metadata($media_id, $upload['file']);
                wp_update_attachment_metadata($media_id, $metadata);
                update_post_meta($media_id, '_wp_attachment_image_alt', $image_alt);
            }
        } // end allowed mime check
    }

    // ── 2. Schema JSON-LD — store as post meta, output via wp_head ───────────
    // NOTE: WordPress strips <script> tags from post content via wp_kses_post.
    // We store the schema in post meta and hook it into <head> instead.
    // This is the correct WordPress approach and avoids it showing as visible text.

    // ── 3. Build meta_input (set SEO fields atomically at insert time) ───────
    // Schema is stored in meta and output via wp_head hook below
    // Setting Rank Math / Yoast meta inside meta_input ensures the values are
    // written BEFORE any save_post hooks run — preventing SEO plugins from
    // overwriting them with empty values on first save.
    $meta_input = [];
    if ($media_id) {
        $meta_input['_thumbnail_id'] = $media_id;
    }
    if ($schema) {
        $meta_input['_ranki_schema_jsonld'] = $schema;
    }
    if ($seo_plugin === 'rankmath') {
        if ($focus_kw)  $meta_input['rank_math_focus_keyword'] = $focus_kw;
        if ($seo_title) $meta_input['rank_math_title']         = $seo_title;
        if ($meta_desc) $meta_input['rank_math_description']   = $meta_desc;
    } elseif ($seo_plugin === 'yoast') {
        if ($focus_kw)  $meta_input['_yoast_wpseo_focuskw']   = $focus_kw;
        if ($seo_title) $meta_input['_yoast_wpseo_title']     = $seo_title;
        if ($meta_desc) $meta_input['_yoast_wpseo_metadesc']  = $meta_desc;
    }

    // ── 4. Resolve category ──────────────────────────────────────────────────
    // If a category name is provided, find or create it. If not provided,
    // auto-detect from the focus keyword by matching existing categories.
    $category_ids = [];
    if ($category) {
        // Explicit category provided — find by name/slug or create
        if (is_numeric($category)) {
            $category_ids = [(int) $category];
        } else {
            $term = get_term_by('name', $category, 'category');
            if (!$term) $term = get_term_by('slug', sanitize_title($category), 'category');
            if ($term) {
                $category_ids = [$term->term_id];
            } else {
                // Create the category
                $new_term = wp_insert_term($category, 'category');
                if (!is_wp_error($new_term)) {
                    $category_ids = [$new_term['term_id']];
                }
            }
        }
    } elseif ($focus_kw) {
        // Auto-detect: find the best matching existing category for this keyword
        $all_cats = get_categories(['hide_empty' => false, 'exclude' => [1]]); // exclude Uncategorized
        $best_match = null;
        $best_score = 0;
        $kw_words = explode(' ', strtolower($focus_kw));

        foreach ($all_cats as $cat) {
            $cat_words = explode(' ', strtolower($cat->name));
            // Count matching words between keyword and category name
            $matches = count(array_intersect($kw_words, $cat_words));
            // Also check if category name appears in keyword or vice versa
            if (stripos($focus_kw, $cat->name) !== false) $matches += 3;
            if (stripos($cat->name, $focus_kw) !== false) $matches += 3;
            // Check slug match
            if (stripos($focus_kw, str_replace('-', ' ', $cat->slug)) !== false) $matches += 2;

            if ($matches > $best_score) {
                $best_score = $matches;
                $best_match = $cat;
            }
        }

        if ($best_match && $best_score >= 1) {
            $category_ids = [$best_match->term_id];
        } else {
            // No match — create a category from the first 2 words of the keyword
            $cat_name = ucwords(implode(' ', array_slice($kw_words, 0, 2)));
            if (strlen($cat_name) >= 3) {
                $new_term = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($new_term)) {
                    $category_ids = [$new_term['term_id']];
                }
            }
        }
    }

    // ── 5. Create post ────────────────────────────────────────────────────────
    $post_data = [
        'post_title'     => $title,
        'post_content'   => $content,
        'post_excerpt'   => $excerpt,
        'post_name'      => $slug,
        'post_status'    => $status,
        'post_author'    => get_current_user_id() ?: 1,
        'meta_input'     => $meta_input,
    ];
    if (!empty($category_ids)) {
        $post_data['post_category'] = $category_ids;
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if ($media_id) {
        set_post_thumbnail($post_id, $media_id);
    }

    // ── 5. Re-apply SEO meta after insert (belt-and-suspenders) ──────────────
    // Some security/caching plugins can clear meta on save_post. Writing again
    // after insert guarantees the values survive.
    if ($seo_plugin === 'rankmath') {
        if ($focus_kw)  update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
        if ($seo_title) update_post_meta($post_id, 'rank_math_title',         $seo_title);
        if ($meta_desc) update_post_meta($post_id, 'rank_math_description',   $meta_desc);
    } elseif ($seo_plugin === 'yoast') {
        if ($focus_kw)  update_post_meta($post_id, '_yoast_wpseo_focuskw',  $focus_kw);
        if ($seo_title) update_post_meta($post_id, '_yoast_wpseo_title',    $seo_title);
        if ($meta_desc) update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
    }

    $post_url = get_permalink($post_id);

    // Get the assigned category name for logging
    $assigned_cats = wp_get_post_categories($post_id, ['fields' => 'names']);
    $cat_name = !empty($assigned_cats) ? $assigned_cats[0] : 'Uncategorized';

    return rest_ensure_response([
        'ok'        => true,
        'post_id'   => $post_id,
        'post_url'  => $post_url,
        'media_id'  => $media_id,
        'media_url' => $media_url,
        'category'  => $cat_name,
    ]);
}

function ranki_handle_update_content(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $params  = $request->get_json_params();
    $post_id = intval($params['post_id'] ?? 0);
    $content = $params['content'] ?? '';

    if (!$post_id) {
        return new WP_Error('missing_post_id', 'post_id is required', ['status' => 400]);
    }
    if ($content === '') {
        return new WP_Error('missing_content', 'content is required', ['status' => 400]);
    }

    // Verify post exists
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('not_found', "Post {$post_id} not found", ['status' => 404]);
    }

    $result = wp_update_post([
        'ID'           => $post_id,
        'post_content' => wp_kses_post($content),
    ], true);

    if (is_wp_error($result)) {
        return $result;
    }

    return rest_ensure_response([
        'ok'      => true,
        'post_id' => $post_id,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Output schema JSON-LD in <head> for posts published by Ranki
// ─────────────────────────────────────────────────────────────────────────────
add_action('wp_head', function () {
    if (!is_singular()) return;
    $post_id = get_the_ID();
    if (!$post_id) return;
    $schema = get_post_meta($post_id, '_ranki_schema_jsonld', true);
    if (!$schema) return;
    echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
});
