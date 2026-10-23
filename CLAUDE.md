# Ranki WordPress Plugin

Installed on every client's WordPress site. It lets Ranki publish and update posts, upload images, write SEO meta and schema, and report leads. Product overview, deploy table and the never-break list live in the workspace `CLAUDE.md`.

- **One PHP file:** `ranki-publisher/ranki-publisher.php` (~3,000 lines) plus `ranki-publisher/ranki-tracker.js` and `readme.txt`. Read the part you are changing end to end first.
- **Version:** 1.16.3 on `origin/main`, and the same in the SVN `trunk/`. The shared checkout may be behind or carry unreleased edits, so check `git log origin/main` before saying what is live.
- **WP.org account:** username `rankiseo` (the readme `Contributors:` value and SVN commit author). Updates ship by SVN commit, never zip upload.
- **SVN checkout:** `ranki-workspace/ranki-publisher-svn/` (`trunk/`, `tags/`, `assets/`), not part of this git repo.
- `docs/` has longer notes but lags the code (HANDOVER still says v1.10.0). Trust the code over it.

## Releasing

1. Bump three things to the same number: plugin header `Version:`, the `RANKI_VERSION` constant, and `Stable tag:` in `readme.txt`. A mismatch makes WP.org serve the wrong version.
2. Add a `== Changelog ==` entry in `readme.txt`.
3. Test on ONE client site first.
4. Copy `ranki-publisher/` into `ranki-publisher-svn/trunk/` and commit, then copy trunk to `ranki-publisher-svn/tags/<version>/` and commit that too.
5. Sites installed from the directory get it through the normal WordPress update notice. The plugin also opts each site into WordPress auto-updates once (`ranki_auto_update_optin`, set in `admin_init`); if the owner switches it off, that sticks.

`ranki-publisher.zip` in this repo is stale (1.8.4). Only sites installed before the directory listing need a manual zip, so rebuild it from `ranki-publisher/` if you need one.

Directory assets (banner, icon) go in `ranki-publisher-svn/assets/`, a sibling of `trunk/`, no version bump or tag needed. Sources are `assets-src/banner.html` and `icon.html`; render with headless Chrome at the exact pixel size (`--window-size=1544,500 --screenshot=...`, `--force-device-scale-factor=1`), downscale with `sips -z 250 772` for the small banner, copy the PNGs across.

## Layout map

| Thing | Where (search by name) |
|-------|------------------------|
| Pull-queue poll and job runner | `ranki_process_queue()`, `ranki_process_single_job()`, `ranki_report_job_done()` |
| Cron hook and interval | `ranki_sync_cron`, `ranki_every_5min` (300s) via `cron_schedules` |
| REST routes | `register_rest_route( 'ranki/v1', ... )`: `publish`, `upload-image`, `ping`, `update-content`, `update-meta`, `set-schema`, `sso-token`, `event` |
| Auth | `ranki_check_auth()` |
| Publish | `ranki_handle_publish()`, `ranki_handle_update_content()`, `ranki_handle_update_meta()`, `ranki_handle_upload_image()` |
| SEO plugin writes | `ranki_apply_seo_config()` (Rank Math), `ranki_apply_yoast_config()`, local SEO and `ranki_active_seo_plugin()` |
| Schema | `ranki_handle_set_schema()`, output in the `wp_head` hook at the end of the file |
| Lead tracking | `ranki-tracker.js` (inlined via `wp_add_inline_script`), `ranki_handle_event()`, server-side hooks for Gravity, Ninja, Fluent and Formidable forms, `ranki_handle_export_leads()` |
| One-click admin login | `ranki_sso_target_user()`, `ranki_handle_sso_token()`, the `ranki_sso` handler on `template_redirect` |
| Settings page | `ranki_settings_page()` (Settings > Ranki Publisher) |
| Preferred Source button | `ranki_preferred_source_*()` and the `the_content` filter |

## Rules and gotchas

- **Pull-queue, never push.** WordPress polls `RANKI_API_BASE/wp-sync/poll` every 5 minutes and posts results to `/wp-sync/done`. All traffic is outbound, which is how it gets past host firewalls that block inbound calls. Do not change the interval without telling Daniel.
- **A second poller rides on front-end traffic** (`ranki_poll_lock` transient, `fastcgi_finish_request()`, runs on `shutdown`). A client host accepted cron events and ran none, stalling its queue for 12 days. Do not "clean up" that `init` hook, and keep the cron re-schedule hook too.
- **Auth is one shared secret** in `wp_options['ranki_secret_key']`, 40 chars from activation, checked with `hash_equals()` against the `X-Ranki-Key` header or `ranki_key` param. If it is regenerated, update it in Ranki admin too or publishing breaks. `/event` is the only open route (nonce plus per-IP rate limit).
- **Two ways in.** REST `/wp-json/ranki/v1/<action>` is preferred; `/?ranki_action=<action>` is the fallback for hosts that block `/wp-json/`. The fallback only allows `publish`, `upload-image`, `ping`, `update-content`, `set-schema`, `sso-token`, not `update-meta`. Keep both calling the same handler.
- **SEO meta is written twice** in `ranki_handle_publish()`: `meta_input` at insert, then `update_post_meta()` after. Some plugins clear meta on `save_post`. Do not collapse to one.
- **Schema goes in `<head>`, not the post body.** `wp_kses_post()` strips `<script>`. It is stored in `_ranki_schema_jsonld` and printed on `wp_head`. When Rank Math or Yoast is active only `FAQPage` and `VideoObject` are printed, so we never duplicate their graph. Store it through `ranki_schema_for_meta()`: `update_post_meta()` eats backslashes, which broke Hebrew FAQs and dropped whole blocks.
- **Tracker and the Preferred Source script are inlined, not `<script src>`.** JS optimizers (SiteGround Optimizer, WP Rocket, Autoptimize) drop external tags and zero out lead tracking.
- **Forms with server hooks are skipped in the browser** (`ranki_server_captured_form_types()`), otherwise leads count twice.
- **Never add a self-update checker.** It was removed in 1.7.5 because WP.org forbids a directory plugin running its own updater. Do not bring back the `site_transient_update_plugins` hooks.
- **One-click admin login** (`sso-token`): token is single-use, burned before login, 60 second life, administrators only, last 20 uses logged in `ranki_sso_log`. Keep all four.
- **Rank Math sitemap:** a post published over REST or the front-end poller does not clear Rank Math's cache, so the plugin clears it on `shutdown`. Leave that in.
- **New permalinks** need `flush_rewrite_rules( true )` after publish (`ranki_purge_cache()`), or Hebrew and Arabic slugs redirect to the homepage.
- **Author:** the post author set on the settings page, else user ID 1 (the `ranki_post_author` filter overrides). If that admin is disabled on a client site, authorship goes odd.
- **Low-traffic sites** may not run WP-Cron on time; the front-end poller covers that, and `DISABLE_WP_CRON` plus a real server cron is the fix on the host side.

## When a client's posts stop publishing

1. Railway logs: is the backend queuing jobs for this client, and has the site polled lately?
2. Is the client's site up?
3. Does the key in Ranki admin match `wp_options['ranki_secret_key']` on the site?
4. Did the host change its firewall or add a captcha? (See `ranki-wp-proxy/`; the pull queue normally gets around it.)
5. Is WP-Cron running? Visit `/wp-cron.php` on the site.
