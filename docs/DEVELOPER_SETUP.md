# Ranki WordPress Plugin — Developer Setup

## Tech stack
- **Language:** PHP (requires PHP 7.4+, uses 8.0 features such as union types in newer helpers)
- **WordPress:** Requires 5.6+, tested up to 7.0
- **Dependencies:** None (no Composer, no npm)
- **Current version:** 1.10.0
- **Distribution:** WordPress.org plugin directory (primary), `ranki-publisher.zip` (manual installs)

## Repo structure
```
ranki-publisher/            # The plugin itself (this is what gets zipped / committed to SVN)
  ├── ranki-publisher.php   # Single-file plugin, ~1,100 lines
  ├── ranki-tracker.js      # Front-end lead + call tracking script
  └── readme.txt            # WordPress.org readme, Stable tag lives here
ranki-publisher.zip         # Packaged for manual upload to client WordPress sites
```

The WordPress.org SVN checkout is a **separate folder**, not part of this repo: `ranki-workspace/ranki-publisher-svn/` (`trunk/`, `tags/`, `assets/`).

## Development

### Local dev setup
1. Get a local WordPress running (Local by Flywheel, XAMPP, or a Docker WP image)
2. Symlink `ranki-publisher/` into your local WP's `wp-content/plugins/` folder:
   ```bash
   ln -s /Users/daniel/Documents/GitHub/ranki-wordpress-plugin/ranki-publisher /path/to/local-wp/wp-content/plugins/ranki-publisher
   ```
3. Activate the plugin in WP admin
4. Edit `ranki-publisher.php` — changes take effect on page reload

### Building a new zip for distribution
```bash
cd /Users/daniel/Documents/GitHub/ranki-wordpress-plugin
rm ranki-publisher.zip
zip -r ranki-publisher.zip ranki-publisher/
```

Upload the new zip to client sites via WP Admin → Plugins → Add New → Upload. This is only for sites installed before the directory listing. Everyone else updates from WordPress.org.

### Releasing to WordPress.org
The plugin is live on the directory, so a release is an SVN commit:

1. Copy the updated `ranki-publisher/` files into `ranki-publisher-svn/trunk/`
2. Commit trunk
3. Copy trunk into `ranki-publisher-svn/tags/<version>/` and commit that too

WordPress serves the version named by `Stable tag:` in `readme.txt`, so a release that doesn't update that line ships nothing to anybody.

**There is no self-update code in the plugin.** It was removed in 1.7.5 because WordPress.org does not permit a directory-hosted plugin to run its own update checker. Do not add it back.

### Version bumping
Three places, all of them required:

1. The plugin header in `ranki-publisher/ranki-publisher.php`:
```php
/**
 * Plugin Name: Ranki Publisher
 * Version: 1.10.0   ← bump this
 */
```
2. The `RANKI_VERSION` constant just below it
3. `Stable tag:` in `ranki-publisher/readme.txt`

Also update `CURRENT_PLUGIN_VERSION` in `ranki/src/lib/pluginVersion.ts` in the frontend repo. That is what the admin clients list compares each site's reported version against to show the Plugin health column, and it is kept in sync by hand. **It drifts.** As of this refresh the plugin is on 1.10.0 and that constant still reads 1.8.6, so every site between those two versions looks up to date when it isn't.

## No environment variables
The plugin has no env vars. All config is either:
- Hardcoded in the PHP file (backend URL, update check URL)
- Stored in `wp_options` table on the client site:
  - `ranki_secret_key` — auth key (auto-generated on activation)

## External services it calls
| Service | Purpose |
|---------|---------|
| `https://ranki-backend-production.up.railway.app` | Pull-queue polling: asks for jobs every 5 min, and reports its own version in the `X-Ranki-Plugin-Version` header |

That's the only outbound call now. The plugin used to also hit `https://ranki.com.au/api/plugin/info` for update checks, but that was removed in 1.7.5. The frontend route still exists and still serves version info, it just no longer has a caller in the plugin.

## Authentication
The `ranki_secret_key` is generated once on plugin activation. The same key must be entered in the Ranki admin dashboard to link a client site. Verification uses `hash_equals()` (timing-safe comparison).

Expected header on incoming requests: `X-Ranki-Key: <secret>`, or query param: `?ranki_key=<secret>`.
