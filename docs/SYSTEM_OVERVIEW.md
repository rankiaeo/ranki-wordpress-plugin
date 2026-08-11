# Ranki WordPress Plugin — System Overview

## What this is
A WordPress plugin installed on **every client's WordPress site**. It lets the Ranki backend publish blog posts, upload images, update content, and set SEO metadata — automatically, without manual WordPress access.

## Why it's designed this way (pull-queue architecture)
Many WordPress hosts block inbound requests from external services (Railway, etc). So instead of Ranki pushing to WordPress, **WordPress pulls from Ranki**:

1. Every 5 minutes, the plugin's WP-Cron job runs
2. The plugin calls the Ranki backend: "any jobs for me?"
3. If yes, the plugin executes them locally (publish, update, upload)
4. Plugin reports results back to Ranki

This means: **only outbound requests from WordPress, no inbound**. Works on every host including restrictive ones.

### The traffic-powered backup poll (added in 1.8.2)
WP-Cron is not a real cron. It only fires when someone visits the site, and on some hosts it is broken outright: the host's scheduler accepts the event, says yes, and then never runs anything. This happened to a live client whose queue sat still for 12 days while their website was perfectly healthy, which is the worst kind of failure because nothing looks wrong from the outside.

So the plugin no longer relies on WP-Cron alone. It also rides on ordinary page views:

1. A normal visitor loads a page on the client's site
2. The plugin takes a 5 minute lock (`ranki_poll_lock` transient) so only one visit in any 5 minute window does this
3. It finishes sending the page to the visitor first (`fastcgi_finish_request`), then polls Ranki on the `shutdown` hook

The visitor waits for nothing, and the queue keeps moving on any site with even light traffic, regardless of whether WP-Cron works. Admin pages, AJAX, REST requests, and cron runs are all skipped so this only ever piggybacks on real front-end visits.

Three things now keep the queue moving, and they are deliberately redundant: WP-Cron every 5 minutes, front-end traffic every 5 minutes, and Ranki's own backend pinging `wp-cron.php` from the outside.

## What it does
- **Publishes new posts** with featured image, SEO meta (Rank Math / Yoast), JSON-LD schema
- **Updates existing posts** (edit content, meta)
- **Uploads images** to the WordPress media library
- **Tracks leads and calls** on the client's site (form submissions, phone link clicks) via `ranki-tracker.js`, which is what fills the Leads dashboard in Ranki
- **Health check** (`/ping`) for Ranki to verify the plugin is alive
- **Reports its own version** to Ranki on every queue poll, so the admin dashboard can flag out-of-date installs
- **Issues one-click admin login tokens** so Daniel can jump straight into a client's WP admin from the Ranki dashboard

## How it's installed
Two channels, and they matter:

- **WordPress.org directory** (now live): clients install "Ranki Publisher" from WP admin → Plugins → Add New → Search. Updates arrive through the normal WordPress update notice.
- **Manual zip**: Daniel uploads `ranki-publisher.zip` via WP admin → Plugins → Add New → Upload. Used for sites onboarded before the directory listing.

On activation:
1. Generates a 40-character secret key, stores in `wp_options['ranki_secret_key']`
2. Schedules a 5-minute WP-Cron event
3. Shows "Ranki Publisher" under WP Settings, where Daniel copies the site URL + secret key into the Ranki dashboard

Note: earlier versions also wrote `.htaccess` rules on activation to whitelist Ranki's Cloudflare Worker IPs. That no longer happens, the proxy handles host security itself now.

## Endpoints it creates

### REST API (preferred)
- `POST /wp-json/ranki/v1/publish` — new post
- `POST /wp-json/ranki/v1/upload-image` — upload image
- `POST /wp-json/ranki/v1/ping` — health check
- `POST /wp-json/ranki/v1/update-content` — update existing post
- `POST /wp-json/ranki/v1/set-schema`: write JSON-LD schema onto an existing post
- `POST /wp-json/ranki/v1/event`: visitor-facing lead / call tracking (called by `ranki-tracker.js`, rate limited per IP)
- `POST /wp-json/ranki/v1/sso-token`: mints a single-use one-click admin login link

### Direct query-string fallback (for hosts that block `/wp-json/`)
- `/?ranki_action=publish`
- `/?ranki_action=upload-image`
- `/?ranki_action=ping`
- `/?ranki_action=update-content`

Both work identically. Some restrictive hosts block `/wp-json/` so the query-string fallback exists.

## SEO metadata it writes
When publishing, the plugin writes:
- Post title, content, featured image
- Rank Math fields: `rank_math_focus_keyword`, `rank_math_title`, `rank_math_description`
- Yoast fields (as fallback): `_yoast_wpseo_focuskw`, `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
- Custom meta: `_ranki_schema_jsonld` (JSON-LD schema, output via `wp_head`)
- Category (auto-detected by matching focus keyword to existing categories, or creates new)

## One-click admin login (added in 1.8.0)
Daniel can open a client's WordPress admin from the Ranki dashboard without knowing or storing their password. The flow:

1. Ranki asks the plugin for a token (`/sso-token`, authenticated with the usual secret key)
2. The plugin mints a random single-use token, valid for **60 seconds**, and returns a login URL
3. Opening that URL burns the token first, then logs the browser in

Guardrails worth knowing before touching this: the token is single-use and burned **before** the login happens (so a leaked or replayed link is dead), it only ever logs in an **administrator** account, and every use is recorded in the `ranki_sso_log` option (last 20 entries) so the site owner can see exactly when someone logged in this way.

## Version
Current: **1.8.2** (single file, ~1,100 lines of PHP, plus `ranki-tracker.js` for lead tracking, no external deps)

Live on the WordPress.org plugin directory. Versions 1.7.5 through 1.8.2 are published there.
