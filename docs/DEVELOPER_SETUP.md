# Ranki WordPress Plugin — Developer Setup

## Tech stack
- **Language:** PHP (uses PHP 8.0+ features — union types)
- **WordPress:** Tested on 5.6+
- **Dependencies:** None (no Composer, no npm)
- **Current version:** 1.6.0
- **Distribution:** `ranki-publisher.zip`

## Repo structure
```
ranki-publisher/            # The plugin itself (this is what gets zipped)
  └── ranki-publisher.php   # Single-file plugin, 727 lines
ranki-publisher.zip         # Packaged for upload to client WordPress sites
```

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

Upload the new zip to client sites via WP Admin → Plugins → Add New → Upload. Or use the auto-update mechanism (bump the version number in the plugin header, host the new zip at the URL the plugin checks).

### Version bumping
Edit the plugin header in `ranki-publisher/ranki-publisher.php`:
```php
/**
 * Plugin Name: Ranki Publisher
 * Version: 1.6.0    ← bump this
 */
```

## No environment variables
The plugin has no env vars. All config is either:
- Hardcoded in the PHP file (backend URL, update check URL)
- Stored in `wp_options` table on the client site:
  - `ranki_secret_key` — auth key (auto-generated on activation)

## External services it calls
| Service | Purpose |
|---------|---------|
| `https://ranki-backend-production.up.railway.app` | Pull-queue polling — asks for jobs every 5 min |
| `https://ranki.com.au/api/plugin/info` | Auto-update checks — sees if a newer plugin version exists |

## Authentication
The `ranki_secret_key` is generated once on plugin activation. The same key must be entered in the Ranki admin dashboard to link a client site. Verification uses `hash_equals()` (timing-safe comparison).

Expected header on incoming requests: `X-Ranki-Key: <secret>`, or query param: `?ranki_key=<secret>`.
