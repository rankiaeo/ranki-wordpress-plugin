# Ranki WordPress Plugin — Handover

## The 30-second version
Single-file PHP WordPress plugin, currently **v1.10.0** (~1,800 lines). Installed on every client's WP site. Uses a **pull-queue** design: WP polls Ranki every 5 minutes for jobs instead of accepting inbound requests. Handles publish, update, image upload, lead tracking, health checks, and one-click admin login. **Now live on the official WordPress.org plugin directory**, which is where updates come from.

## The 7 things you must know

### 1. Pull-queue, not push — this is intentional
WP makes outbound requests to Ranki. Ranki doesn't push to WP. This works around host firewalls (SiteGround, etc.) that block inbound traffic. **Do not change this to a push model** — it'll break for half the clients.

Since 1.8.2 there are **two** ways the poll gets triggered, and you need to know both exist. WP-Cron is the first. Ordinary front-end page views are the second: a visitor hits the site, the plugin grabs a 5 minute transient lock (`ranki_poll_lock`), finishes serving the page via `fastcgi_finish_request()`, then polls Ranki on the `shutdown` hook. This was added because a client's host (ZAAAP) accepted cron events and silently ran none of them, stalling their queue for **12 days** while the site looked completely healthy. If you ever "clean up" the `init` hook that does this, low-traffic and broken-cron sites go quiet again with no error anywhere.

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

### 6. The plugin no longer updates itself (changed in 1.7.5)
This is the single most surprising thing in the current codebase if you're going by older notes. The plugin used to run its own update checker against `ranki.com.au/api/plugin/info`. WordPress.org's automated review flags a directory-hosted plugin that runs its own updater alongside WP's native one, so **it was removed entirely** (the `site_transient_update_plugins` / `pre_set_site_transient_update_plugins` hooks are gone).

What this means in practice:
- Sites installed from the WordPress.org directory update through the standard WordPress update notice. Nothing custom involved.
- Releasing a version means committing it to the **WordPress.org SVN repo**, not just bumping a number in the Ranki frontend. The local SVN checkout is at `ranki-workspace/ranki-publisher-svn/` (tags `1.7.5` through `1.10.0` are published).
- Do not re-add a self-updater. It would get the plugin pulled from the directory.

### 6b. Ranki features are gated on the plugin version, and the gate is a hard refusal
The backend refuses rather than half-applying when a site is behind:
- `local-seo/push` needs **1.8.8** or newer (`LOCAL_SEO_MIN_PLUGIN`)
- `seo-config/push` needs **1.10.0** or newer (`SEO_CONFIG_MIN_PLUGIN`)

If you add a job action to the plugin, add its minimum version to the backend route at the same time. A client on an old plugin should get a clear "ask them to update" message, not a silent partial write.

### 6c. One key, one site (1.8.7)
Every poll now sends `X-Ranki-Site` as well as `X-Ranki-Key` and `X-Ranki-Plugin-Version`. Ranki records the host on first contact and refuses that key from any other host, so a staging copy of a site can't take articles meant for the live one. Cloning a client site to a new domain therefore needs the recorded host cleared, or the copy sits there polling and getting nothing.

### 7. Image uploads validate real file bytes, not the filename (1.7.6)
`ranki_is_allowed_image()` is a shared helper checked by **both** upload paths (`ranki_handle_upload_image()` and the featured-image path inside `ranki_handle_publish()`) before anything is written to disk. It inspects the actual decoded bytes, not the client-supplied filename or claimed content type. Before this, one of the two paths wrote decoded base64 straight to disk with no content check at all.

The visitor-facing lead tracking endpoint (`ranki_handle_event`) also carries a **per-IP rate limit**. Its nonce is not single-use, so without the limit it could be replayed to flood a client's Leads dashboard with fake entries. Don't remove either guard.

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
| Change the traffic-triggered backup poll | The `init` hook near the top, search for `ranki_poll_lock` |
| Change what the queue poll sends Ranki | `ranki_process_queue()`, look at the headers array (`X-Ranki-Key`, `X-Ranki-Plugin-Version`) |
| Change image validation rules | `ranki_is_allowed_image()`, shared by both upload paths |
| Change lead / call tracking | `ranki_handle_event()` + `ranki-publisher/ranki-tracker.js` |
| Change one-click admin login | `ranki_sso_target_user()` + the `ranki_sso` query handler |
| Change backend URL | Top of file, look for `ranki-backend-production.up.railway.app` |

## Known gotchas

- **Default author fallback is user ID 1** (the admin). If no user is logged in when publishing, posts get authored by whoever is user 1. Usually fine, but weird if that account is disabled.
- **`mime_content_type()` is deprecated** in PHP 8.1+. Image validation may throw warnings on newer PHP versions. Consider `finfo_*` functions.
- **WP-Cron needs site traffic to trigger.** If a client's site has no visitors, the 5-minute poll won't run. Since 1.8.2 the front-end traffic poll covers most of this, but a site with genuinely zero visitors still needs real cron: `define('DISABLE_WP_CRON', true)` in `wp-config.php` + a real server cron hitting `wp-cron.php`. Ranki's backend also pings `wp-cron.php` every 5 minutes as a third safety net.
- **Some hosts accept cron events and run none of them.** This is worse than cron being off, because everything reports success. If a client's queue is stalled but their site is fine, check when the plugin last polled before assuming the backend is at fault.
- **Featured image failures are reported, not silent** (since 1.7.3). If `wp_upload_bits()` fails on disk quota or permissions, the post still publishes and still reports success, but a `media_error` field comes back on the publish response and through the pull-queue job-done report. Look there when a post lands with no image.
- **Default author fallback is user ID 1.** Unchanged, still worth knowing.

## Distribution & updates
- **Primary channel: the WordPress.org plugin directory.** The plugin is listed and live. Clients install it from WP admin search, and updates arrive through the standard WordPress update notice. There is no custom update checker in the plugin any more (removed in 1.7.5, see point 6 above).
- **Releasing a version means an SVN commit**, not just a version bump. The checkout lives at `ranki-workspace/ranki-publisher-svn/` with `trunk/` and `tags/`. Published tags: 1.7.5, 1.7.6, 1.8.0 through 1.8.9, 1.9.0 through 1.9.2, and 1.10.0.
- Bump the version in **four** places on release: the plugin header, the `RANKI_VERSION` constant, `Stable tag:` in `readme.txt`, and `CURRENT_PLUGIN_VERSION` in `ranki/src/lib/pluginVersion.ts` in the frontend repo. That last one is hand-synced and is the one people forget: if it is behind, the admin clients list quietly reports out-of-date sites as current.
- `ranki-publisher.zip` in the repo is still used for manually installed sites, and `CURRENT_PLUGIN_VERSION` in the frontend (`ranki/src/lib/pluginVersion.ts`) is what the admin clients list compares against to flag out-of-date installs. Keep it in sync by hand.
- Always test a new version on ONE client site before rolling out to all

## First day checklist
1. Get local WordPress running
2. Symlink the plugin folder into `wp-content/plugins/`
3. Activate it in WP admin
4. Copy the generated secret key from the plugin's settings page
5. In Ranki admin dashboard, create a test client pointing at your local WP URL + secret key
6. Trigger a publish from Ranki, watch it land in your local WP
7. Read `ranki-publisher/ranki-publisher.php` end to end, it's ~1,100 lines

## Who to ask
Daniel Dalal — product owner, has the full picture.
