# Ranki WordPress Plugin — Claude Code Briefing

## What this repo is

The WordPress plugin installed on every client's WordPress site. It connects their site to Ranki so posts can be published automatically. Current version: **1.8.2**.

**Owner:** Daniel Dalal, CEO Theme Press, Australia
**Single file:** `ranki-publisher/ranki-publisher.php` (~1,117 lines, read it end to end before touching anything)
**Distribution:** Live on the WordPress.org plugin directory. Updates ship via SVN commit, not zip uploads.
**WP.org username:** `rankiseo` (this is the `Contributors:` value in readme.txt and the SVN commit author)
**SVN checkout:** `ranki-workspace/ranki-publisher-svn/` (`trunk/`, `tags/`, `assets/`). Not part of this git repo.

The `docs/` folder in this repo is the current source of truth. `docs/HANDOVER.md` has the most detail.

---

## How it works (pull-queue design)

WordPress polls Ranki every 5 minutes — Ranki does NOT push to WordPress. This is intentional and critical.

```
WordPress cron (every 5 min) → GET Ranki backend → "any jobs for me?" → execute job → POST result back
```

This design works around host firewalls (SiteGround etc.) that block inbound traffic. **Do not change this to a push model** — it would break publishing for at least half the client base.

---

## Critical rules — never break these

- **Pull-queue is intentional.** WP polls Ranki, not the other way around. See above.
- **SEO meta is written twice.** `meta_input` at `wp_insert_post()` time AND again with `update_post_meta()` afterward. This is belt-and-suspenders — some WP plugins clear meta on the `save_post` hook. Do not simplify this to one call.
- **JSON-LD schema goes in `<head>` via `wp_head` hook**, not in post body. WordPress's `wp_kses_post()` strips `<script>` tags from post content. Schema is stored in `_ranki_schema_jsonld` post meta and output via the hook. Correct approach — do not move it into the post body.
- **Two endpoint paths must both work.** REST (`/wp-json/ranki/v1/publish`) is preferred. Query-string (`/?ranki_action=publish`) is the fallback for hosts that block `/wp-json/`. Both execute the same handler. If you change logic, change it once in the shared handler function.
- **Never change the pull interval without telling Daniel.** The 5-min cron is relied on by Railway's monitoring.
- **Version bump requires updating three things:** the plugin header Version field, the `RANKI_VERSION` constant, and `Stable tag:` in `readme.txt`. All three must match or WP.org serves the wrong version.
- **Never add a self-update checker.** One existed until 1.7.5 and was removed because WordPress.org does not permit a directory-hosted plugin to run its own updater alongside WP's native one. The `site_transient_update_plugins` / `pre_set_site_transient_update_plugins` hooks are gone. Do not bring them back. `ranki.com.au/api/plugin/info` is no longer part of the update path.
- **Ranki cannot push plugin updates to clients.** Sites installed from the directory update through the standard WordPress update notice. Sites installed before the listing went live need a manual zip upload.

---

## Authentication

- On activation, the plugin generates a 40-char secret key: `wp_generate_password(40, false)`
- Stored in: `wp_options['ranki_secret_key']`
- Verified via `hash_equals()` (timing-safe) on every request
- Daniel copies this key from WP Settings → Ranki Publisher into the Ranki admin panel
- If the key is regenerated, it must also be updated in Ranki admin or publishing breaks

---

## Key constants (top of `ranki-publisher.php`)

| Constant | Value | Purpose |
|----------|-------|---------|
| `RANKI_VERSION` | `1.8.4` | Plugin version, bump on every release |
| `RANKI_OPTION_KEY` | `ranki_secret_key` | wp_options key for the secret |
| `RANKI_API_BASE` | `https://ranki-backend-production.up.railway.app/api` | Backend URL — update if Railway URL changes |

---

## Key functions (search by name in the file)

| Function | Purpose |
|----------|---------|
| `ranki_handle_publish()` | Main publish handler — creates WP post, sets SEO meta, outputs schema |
| `ranki_handle_update_content()` | Updates existing post content |
| `ranki_handle_upload_image()` | Downloads + attaches image to post |
| `ranki_settings_page()` | WP admin settings page (shows URL + secret key) |
| `ranki_sync_cron` | The scheduled cron hook (fires every 5 min) |

---

## Distribution and releasing

The plugin is live on the WordPress.org directory. Published tags: 1.7.5 through 1.8.3. A release is an SVN commit, not a zip upload.

To release a new version:
1. Bump `RANKI_VERSION`, the plugin header Version field, and `Stable tag:` in `readme.txt`. All three must match.
2. Add a `== Changelog ==` entry in `readme.txt`.
3. Test on ONE client site before releasing.
4. Copy `ranki-publisher/` files into `ranki-publisher-svn/trunk/` and commit.
5. Copy trunk into `ranki-publisher-svn/tags/<version>/` and commit that too.

Clients installed from the directory update through the standard WordPress update notice. Sites installed before the listing went live still need a manual zip upload from `ranki-publisher.zip`.

### Directory assets (banner, icon, screenshots)

Assets live in `ranki-publisher-svn/assets/` at the SVN root, a sibling of `trunk/` and `tags/`, not inside the plugin folder. Committing them needs no version bump and no tag; the listing page updates within minutes.

| File | Purpose |
|------|---------|
| `banner-1544x500.png` / `banner-772x250.png` | Header image on the listing page |
| `icon-256x256.png` / `icon-128x128.png` | Icon in search results and wp-admin |
| `screenshot-N.png` | Numbered to match the `== Screenshots ==` list in readme.txt |

Banner and icon are generated from `assets-src/banner.html` and `assets-src/icon.html` in this repo, rendered with headless Chrome:

```
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu \
  --hide-scrollbars --force-device-scale-factor=1 --virtual-time-budget=12000 \
  --window-size=1544,500 --screenshot=banner-1544x500.png "file://$PWD/banner.html"
```

Then downscale with `sips -z 250 772`. Edit the HTML, re-render, copy the PNGs into the SVN `assets/` folder.

---

## Known gotchas

- **Default author is user ID 1** (WP admin). If that account is disabled on a client site, posts get weird authorship.
- **`mime_content_type()` is deprecated in PHP 8.1+.** Image validation may throw warnings. Consider `finfo_*` functions when fixing.
- **WP-Cron needs site traffic to trigger.** Low-traffic client sites may not run the 5-min poll reliably. Recommendation: `define('DISABLE_WP_CRON', true)` in `wp-config.php` + a real server cron. Railway pings `wp-cron.php` every 5 min as a backup.
- **`.htaccess` modification fails silently** if the file isn't writable.

---

## Debugging a client whose posts stopped publishing

1. Check Railway logs — is the backend trying to send jobs to this client?
2. Check the client's WP site is up
3. Check the secret key in Ranki admin matches `wp_options['ranki_secret_key']` on the client's WP site
4. Check if the client's host recently updated their firewall (may need Cloudflare proxy path)
5. Check WP-Cron is running — visit `yoursite.com/wp-cron.php` directly

---

## Daniel's preferences

Single source of truth: `~/.claude/CLAUDE.md`. Read and follow that, do not restate it here.
Reply style, working style and the no-em-dash rule all live there.

