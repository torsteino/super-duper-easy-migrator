# super-duper-easy-migrator
=== Super Duper Easy Migration ===
Contributors: digilove
Tags: migration, migrate, clone, rsync, ssh
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a WordPress site to another server via SSH and rsync — directly from the admin panel. No FTP, no manual steps.

== Description ==

**Super Duper Easy Migration** lets you migrate any WordPress site to another server in one click, directly from the WordPress admin panel. It uses SSH and rsync to transfer files and handles the entire migration process automatically.

= Features =

* One-click migration from WordPress admin — no FTP or terminal required
* Transfers files via rsync (fast, efficient, only copies changes)
* Exports and imports the database automatically
* Runs search-and-replace on all URLs and file paths
* Handles serialised data correctly (no broken PHP serialisation)
* Cleans up invalid `.htaccess` directives (e.g. `php_value` under PHP-FPM)
* Real-time progress display with step-by-step log
* Runs as a background process — safe to close the browser tab
* Supports servers without WP-CLI (falls back to `mysql` CLI and a PHP script)
* Supports servers without `wp-config.php` (manual DB entry form)
* Excludes cache folders, log files and backup directories automatically
* Desktop notifications when migration completes

= How it works =

1. Install the plugin on the **source** site (the site you want to migrate).
2. Go to **Tools → Migrate Site**.
3. Enter the SSH credentials for the target server.
4. Click **Test connection** to verify SSH access and check for WP-CLI.
5. Click **Start migration** — the plugin does the rest.

The migration runs these steps in order:

1. **Pre-flight check** — verifies SSH access, reads target database credentials
2. **Database dump** — exports the source database with `mysqldump`
3. **File transfer** — rsyncs all WordPress files to the target server
4. **.htaccess cleanup** — removes directives invalid under PHP-FPM
5. **wp-config update** — writes correct database credentials to target wp-config.php
6. **Database import** — imports the dump on the target server
7. **Search & replace** — updates all URLs and paths in the database
8. **Cleanup** — removes temporary files and flushes caches

= Requirements =

The following tools must be installed on the **source** server (where the plugin runs):

* `sshpass` — for password-based SSH authentication
* `rsync` — for file transfer
* `mysqldump` — for database export
* PHP `exec()` function must be enabled

On the **target** server, the plugin works best with WP-CLI installed, but falls back to `mysql` CLI and a temporary PHP script if WP-CLI is not available.

= Security =

* SSH credentials are stored in a temporary JSON file on disk, protected with `chmod 0600` and an `.htaccess` deny rule. They are deleted after the migration completes.
* Log files are stored in `wp-content/sdem-logs/`, protected from direct browser access.
* All AJAX endpoints require `manage_options` capability and nonce verification.

= Important notes =

* This plugin uses PHP's `exec()` function to run SSH, rsync and mysqldump commands. Some shared hosting environments disable `exec()`. Check with your hosting provider if you are unsure.
* The migration will **overwrite** the target site's database and files. Make sure you have a backup of the target site before running a migration.
* Only migrate to servers you own and control.

== Installation ==

1. Upload the `super-duper-easy-migration` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Tools → Migrate Site**.

= Server requirements =

Install required tools on Ubuntu/Debian:

`sudo apt install sshpass rsync`

On CentOS/RHEL:

`sudo yum install sshpass rsync`

`mysqldump` is usually installed as part of the MySQL/MariaDB client package.

== Frequently Asked Questions ==

= Does this work with shared hosting? =

It depends. The plugin requires `exec()` to be enabled and `sshpass`/`rsync` to be available. Many managed hosting providers (e.g. Kinsta, WP Engine) restrict these tools. It works best on VPS or dedicated servers where you have shell access.

= Does the target site need to be a WordPress installation? =

No — you can migrate to an empty web root. If no `wp-config.php` is found on the target server, the plugin will ask you to enter the database credentials manually.

= Does WP-CLI need to be installed on the target server? =

No. If WP-CLI is not found, the plugin automatically falls back to using the `mysql` command-line client for database import, and a temporary PHP script for search-and-replace.

= Is it safe to close the browser during migration? =

Yes. The migration runs as a background process (`nohup`). You can close the browser tab and reopen the admin page later to check progress.

= Will it migrate the wp-config.php file? =

The plugin reads the database credentials from the target's existing `wp-config.php` (if present) and updates it with the source site's credentials after migration. The existing file is modified in place rather than overwritten.

= What files are excluded from the rsync transfer? =

Cache folders, object cache files, log files, and backup directories from common plugins (UpdraftPlus, BackupBuddy, Duplicator, etc.) are excluded automatically. The migration log directory (`sdem-logs/`) is also excluded.

= I get "exec() is disabled" — what do I do? =

Contact your hosting provider and ask them to enable `exec()` for your PHP installation, or use a VPS where you control the PHP configuration.

== Screenshots ==

1. The migration form — enter target server credentials and click Test connection.
2. Real-time migration progress with step indicators and a live log.
3. Migration complete screen with a link to the migrated site.

== Changelog ==

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
