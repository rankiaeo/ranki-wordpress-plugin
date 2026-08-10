=== Ranki Publisher ===
Contributors: rankiaeo
Tags: seo, content, publishing, automation, ai
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.8.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your WordPress site to Ranki for automated AI SEO content publishing — posts, images, and SEO metadata, hands-free.

== Description ==

**Ranki Publisher** is the WordPress bridge for the [Ranki](https://ranki.com.au) AI SEO platform. Once installed, Ranki can automatically publish SEO-optimised blog posts to your WordPress site — including the featured image, focus keyword, meta title, meta description, and schema JSON-LD markup — without any manual work.

**How it works**

1. Install and activate the plugin.
2. Go to **Settings → Ranki Publisher** and copy your Site URL and Secret Key.
3. Paste them into your Ranki admin panel.
4. Ranki will start publishing content to your site automatically.

**Key features**

* **Pull-queue cron** — WordPress polls Ranki every 5 minutes for pending jobs. All connections are outbound from your server, which bypasses hosting firewalls that block inbound REST API calls (SiteGround, Cloudflare, etc.).
* **REST API endpoint** — for hosts that allow inbound REST calls, Ranki can push content directly via `/wp-json/ranki/v1/publish`.
* **Featured image upload** — images are uploaded to your media library and set as the post featured image automatically.
* **SEO plugin support** — writes focus keyword, meta title, and meta description for both Rank Math and Yoast SEO.
* **Smart category assignment** — matches or creates a post category based on the focus keyword.
* **Schema JSON-LD** — injects structured data markup into `<head>` for posts that include it.

**Requirements**

* A [Ranki](https://ranki.com.au) account (the plugin alone does nothing without one).
* WordPress 5.6 or higher.
* PHP 7.4 or higher.

== Installation ==

= Automatic (recommended) =

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **Ranki Publisher**.
3. Click **Install Now**, then **Activate**.
4. Go to **Settings → Ranki Publisher** and copy your credentials into your Ranki admin panel.

= Manual =

1. Download the plugin ZIP.
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file and click **Install Now**, then **Activate**.
4. Go to **Settings → Ranki Publisher** and copy your credentials into your Ranki admin panel.

== Frequently Asked Questions ==

= Do I need a Ranki account? =

Yes. This plugin is a connector for the [Ranki](https://ranki.com.au) AI SEO platform. It does not function as a standalone plugin.

= Is my secret key stored securely? =

Your secret key is stored in your WordPress database (wp_options table) using WordPress's standard `update_option()` function — the same way WordPress stores all plugin settings. It is never exposed in page source, logs, or URLs.

= What happens if my hosting blocks external REST API requests? =

The plugin includes a pull-queue cron system as a fallback. WordPress polls the Ranki API every 5 minutes for pending jobs and processes them locally. All connections are outbound from your server, so firewall rules blocking inbound requests do not affect it.

= Which SEO plugins are supported? =

Rank Math and Yoast SEO. The plugin writes the standard meta fields used by each: focus keyword, meta title, and meta description.

= Can I change which author is used for published posts? =

Yes. By default, posts are attributed to user ID 1 (the first administrator). You can override this using the `ranki_post_author` filter:

`add_filter( 'ranki_post_author', function() { return 5; } );`

= How do I regenerate my secret key? =

Go to **Settings → Ranki Publisher** and click **Regenerate Secret Key**. Then update the key in your Ranki admin panel. The old key stops working immediately.

== Third-Party Services ==

This plugin communicates with the **Ranki** backend service to function. Specifically:

* **Polling for jobs** — every 5 minutes, the plugin calls `https://ranki-backend-production.up.railway.app/wp-sync/poll` to check for pending content publishing jobs. The request includes your site's secret key in a header (never in the URL).
* **Reporting job results** — after processing each job, the plugin calls `https://ranki-backend-production.up.railway.app/wp-sync/done` to report success or failure, including the published post ID and URL.

**Data transmitted:** your site's secret key (as an authentication header), the result of each publishing job (post ID, post URL, error message if applicable). No personal user data is transmitted.

* Ranki Terms of Service: https://ranki.com.au/terms
* Ranki Privacy Policy: https://ranki.com.au/privacy

== Screenshots ==

1. The Settings page showing your Site URL and Secret Key, ready to copy into Ranki.

== Changelog ==

= 1.8.1 =
* The plugin now reports its version when it checks in with Ranki, so the connection status shown in your Ranki dashboard reflects reality on hosts that block inbound requests.

= 1.8.0 =
* Site owners can now open their WordPress admin directly from their Ranki dashboard, without re-entering a password. Logins are single-use, expire after 60 seconds, and are recorded under Settings → Ranki Publisher.

= 1.7.6 =
* Security hardening: stricter file-content validation on image uploads. Recommended update for all sites.
* Added rate limiting to the visitor-facing lead tracking endpoint.

= 1.7.5 =
* Removed the plugin's own self-update checker. WordPress.org does not permit directory-hosted plugins to run a custom updater alongside its own update system. Updates now come through the standard WordPress.org update notice once this version is live in the directory.

= 1.7.4 =
* Removed an unneeded translation-loading call (not required for directory-hosted plugins since WP 4.6).

= 1.7.3 =
* Featured image upload failures (host disk quota, permissions) are now reported back to Ranki instead of silently publishing the post without an image.

= 1.7.2 =
* One-click auto-update is now active — new plugin versions appear in the standard WordPress update notice, no more manual zip uploads.
* Schema now defers to Rank Math or Yoast when present (keeps FAQ schema, avoids duplicate structured data).
* Removed self-reported review stars from post schema (ineligible in Google, avoids manual-action risk).

= 1.7.1 =
* Fixed Hebrew/Arabic post URLs redirecting to homepage — rewrite rules now hard-flushed after publish.

= 1.7.0 =
* Lead and call tracking: form submissions (CF7, WPForms, Gravity Forms, Elementor, HTML) and phone link clicks are now captured automatically and shown in the Ranki dashboard under Leads & Calls.
* No configuration needed - tracking activates as soon as this update is installed.

= 1.6.1 =
* Fixed API base URL — pull-queue poll was hitting the wrong path (missing /api prefix).
  Queue jobs now process correctly on all WordPress hosts.
* Bumped tested-up-to to WordPress 6.9.

= 1.6.0 =
* Added update-content endpoint for refreshing existing post content.
* Added direct endpoint fallback for hosts that block REST API calls.
* Improved category auto-detection from focus keyword.
* Added MIME type verification before image upload.
* Schema JSON-LD now stored in post meta and injected into `<head>` (prevents content stripping by wp_kses_post).

= 1.5.0 =
* Added schema JSON-LD support.
* Added Yoast SEO meta field support alongside Rank Math.

= 1.4.0 =
* Added pull-queue cron system as a fallback for restricted hosting environments.
* Added upload-image endpoint for separate image uploads.

= 1.3.0 =
* Added smart category assignment from focus keyword.
* Added featured image upload via base64.

= 1.2.0 =
* Added Rank Math SEO meta fields (focus keyword, title, description).

= 1.1.0 =
* Added ping endpoint for connection testing.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.7.6 =
Security update, please update as soon as possible.
