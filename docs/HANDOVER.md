# Ranki WordPress Plugin — Handover

## The 30-second version
Single-file PHP WordPress plugin, currently **v1.6.1**. Installed on every client's WP site. Uses a **pull-queue** design: WP polls Ranki every 5 minutes for jobs instead of accepting inbound requests. Handles publish, update, image upload, and health checks. Auto-updates itself. Submitted to the official WordPress.org plugin directory in April 2026.

## The 5 things you must know

### 1. Pull-queue, not push — this is intentional
WP makes outbound requests to Ranki. Ranki doesn't push to WP. This works around host firewalls (SiteGround, etc.) that block inbound traffic. **Do not change this to a push model** — it'll break for half the clients.

### 2. Secret key is the only auth
A 40-char key generated on plugin activation, stored in `wp_options['ranki_secret_key']`. Verified via `hash_equals()` (timing-safe) against the `X-Ranki-Key` header or `ranki_key` query param. Regenerate from the plugin settings page if compromised — but remember to update the key in Ranki's admin too or publishing breaks.

### 3. SEO meta is written twice (belt + suspenders)
Post meta (Rank Math / Yoast focus keyword, title, description) is written in `meta_input` at `wp_insert_post()` time **and** again with `update_post_meta()` afterward. This is intentional — some WP plugins clear meta on the `save_post` hook. Don't simplify this.

### 4. JSON-LD schema is output via `wp_head`, not inline
WordPress's `wp_kses_post()` strips `<script>` tags from post content, so JSON-LD schema can't live in the post body. Instead, the plugin stores the schema in post meta (`_ranki_schema_jsonld`) and outputs it in `<head>` via the `wp_head` hook. Correct WP approach.

### 5. Two endpoint paths: REST and direct query-string
- Preferred: `/wp-json/ranki/v1/publish`
- Fallback: `/?ranki_action=publish`

Some hosts block `/wp-json/` entirely. The fallback exists for those. Both paths execute the same handler code — if you change logic, change it once (it's in a shared handler function).

## Common tasks — where to look

Everything lives in `ranki-publisher/ranki-publisher.php`. Search by function name:
| Task | Function |
|------|----------|
| Change publish logic | `ranki_handle_publish()` |
| Change update logic | `ranki_handle_update_content()` |
| Change image upload | `ranki_handle_upload_image()` |
| Change SEO meta written | Inside `ranki_handle_publish()`, look for `meta_input` and `update_post_meta` calls |
| Change category auto-detection | Search for `_get_or_create_category` or similar |
| Change cron interval | Search for `ranki_sync_cron` + `cron_schedules` filter |
| Change backend URL | Top of file, look for `ranki-backend-production.up.railway.app` |
| Change auto-update check URL | Look for `ranki.com.au/api/plugin/info` |

## Known gotchas

- **Default author fallback is user ID 1** (the admin). If no user is logged in when publishing, posts get authored by whoever is user 1. Usually fine, but weird if that account is disabled.
- **`mime_content_type()` is deprecated** in PHP 8.1+. Image validation may throw warnings on newer PHP versions. Consider `finfo_*` functions.
- **WP-Cron needs site traffic to trigger.** If a client's site has no visitors, the 5-minute poll won't run. For low-traffic sites, recommend real cron: `define('DISABLE_WP_CRON', true)` in `wp-config.php` + a real server cron hitting `wp-cron.php`.
- **`.htaccess` modification fails silently** if the file isn't writable. Plugin won't warn the admin.

## Distribution & updates
- Plugin is distributed as `ranki-publisher.zip` — uploaded manually to each client site, or pushed via auto-update
- Auto-update works by bumping the version in the plugin header and updating the response at `https://ranki.com.au/api/plugin/info`
- Plugin has also been submitted to the **WordPress.org plugin directory** (April 2026) — if/when approved, clients can install directly from WP admin search
- Always test a new version on ONE client site before rolling out to all

## First day checklist
1. Get local WordPress running
2. Symlink the plugin folder into `wp-content/plugins/`
3. Activate it in WP admin
4. Copy the generated secret key from the plugin's settings page
5. In Ranki admin dashboard, create a test client pointing at your local WP URL + secret key
6. Trigger a publish from Ranki, watch it land in your local WP
7. Read `ranki-publisher/ranki-publisher.php` end to end — it's only 727 lines

## Who to ask
Daniel Dalal — product owner, has the full picture.
