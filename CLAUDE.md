# Ranki WordPress Plugin — Claude Code Briefing

## What this repo is

The WordPress plugin installed on every client's WordPress site. It connects their site to Ranki so posts can be published automatically. Current version: **1.6.3**.

**Owner:** Daniel Dalal, CEO Theme Press, Australia
**Single file:** `ranki-publisher/ranki-publisher.php` (~727 lines — read it end to end before touching anything)
**Distribution:** `ranki-publisher.zip` — uploaded manually to client WP sites, or auto-updated
**Plugin directory:** Submitted to WordPress.org in April 2026 (not yet approved)

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
- **Version bump requires updating both** the plugin header AND `RANKI_VERSION` constant at the top of the file. Also update `ranki.com.au/api/plugin/info` (in the `ranki` frontend repo) so auto-update works.

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
| `RANKI_VERSION` | `1.6.3` | Plugin version — bump on every release |
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

## Distribution and auto-update

- Plugin is distributed as `ranki-publisher.zip`
- Auto-update: plugin checks `ranki.com.au/api/plugin/info` for new versions
- To release a new version:
  1. Bump `RANKI_VERSION` constant + plugin header Version field
  2. Zip the `ranki-publisher/` folder → `ranki-publisher.zip`
  3. Update `src/app/api/plugin/info/route.ts` in the `ranki` frontend repo with the new version + download URL
  4. Test on ONE client site before rolling out
- WordPress.org submission (April 2026): if approved, clients can install directly from WP admin search

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

- No em dashes — ever.
- No over-engineering. The plugin is intentionally simple and self-contained.
- No unnecessary comments.
- Test on ONE client before rolling any change to all clients.
