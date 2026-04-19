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

## What it does
- **Publishes new posts** with featured image, SEO meta (Rank Math / Yoast), JSON-LD schema
- **Updates existing posts** (edit content, meta)
- **Uploads images** to the WordPress media library
- **Health check** (`/ping`) for Ranki to verify the plugin is alive
- **Auto-updates** itself when Ranki releases a new plugin version

## How it's installed
Current distribution: Daniel uploads `ranki-publisher.zip` to the client's WordPress admin → Plugins → Add New → Upload.

On activation:
1. Generates a 40-character secret key, stores in `wp_options['ranki_secret_key']`
2. Adds `.htaccess` rules to whitelist Ranki's Cloudflare Worker IPs
3. Schedules a 5-minute WP-Cron event
4. Shows "Ranki Publisher" in the WP admin sidebar where Daniel copies the site URL + secret key into the Ranki dashboard

## Endpoints it creates

### REST API (preferred)
- `POST /wp-json/ranki/v1/publish` — new post
- `POST /wp-json/ranki/v1/upload-image` — upload image
- `POST /wp-json/ranki/v1/ping` — health check
- `POST /wp-json/ranki/v1/update-content` — update existing post

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

## Version
Current: 1.6.0 (single file, 727 lines of PHP, no external deps)
