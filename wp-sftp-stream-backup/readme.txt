=== WP SFTP Stream Backup ===
Contributors: wpssb
Tags: backup, sftp, restore, migration, database
Requires at least: 5.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress backups streamed to SFTP, S3 and Nextcloud, with automatic replicas and no local backup archives.

== Description ==

Coded by Radici Soluzioni Informatiche — https://radici.biz

The backup dashboard, repository settings and standalone restore share a responsive
Radici-branded interface. The original logo is included locally and embedded in
restore.php: no external images, fonts or libraries are loaded.

The backup is generated and uploaded at the same time: only small in-memory buffers are used, so the site never needs free disk space for the archive.

= Multiple repositories (1.1) =

Open Tools > Backup repositories to add and enable SFTP, S3-compatible storage
(Amazon, Hetzner, DigitalOcean Spaces, Aruba or a custom endpoint), authenticated
Nextcloud WebDAV, or a public Nextcloud File Drop link (Nextcloud 29+).
Choose an enabled readable primary repository. One backup is generated there
and automatically copied to every other enabled repository, through PHP memory.
This uses incoming/outgoing bandwidth and remote storage, not local disk.

New multi-repository backups use format 2: numbered parts up to 8 MiB,
paged catalogs (256 parts/page), SHA-256 checksums and a final manifest.
The restore reads the parts without joining them first. Legacy single-SFTP
mode and format 1 remain supported until repository settings are saved.
Existing credentials and WPSSB_SFTP_* constants are preserved for legacy-sftp.

S3 requires an existing private bucket, credentials, prefix and signing region.
Provider + Location presets can fill a blank endpoint for Amazon, Hetzner and
Spaces. Explicit endpoints override presets. For Aruba use the assigned
endpoint and signing region; no endpoint is guessed. No public ACL is applied.
Authenticated Nextcloud uses the instance URL, username, app password and Path.
Public URL selects File Drop instead; the complete share link is encrypted.
Connection tests use saved values, not unsaved form fields.

Public File Drop is replica-only. It cannot read, list, verify or delete uploads,
and retention is unavailable. An accepted upload is not a verified copy.
The connection probe requires consent because it cannot be deleted by the plugin.
After an ambiguous upload acknowledgement the replica restarts with a new
unique filename prefix; the owner must clean abandoned parts manually.

Each repository has independent progress/retries. Partial completion preserves
successful copies and pins the primary. Retry or explicitly abandon failed
copies before another backup (including scheduled backups) can start.
Cancellation preserves complete copies and may leave partial objects for cleanup.
Orphan parts inside completed backups are removed with their backup's retention.
Multi-repository listings show plugin history, not a full remote inventory.
WebDAV listing is explicitly limited to 10,000 entries / 8 MiB response;
use S3 paginated listings for very large repositories.
Versioning/Object Lock may retain billed storage after deletion.
Transport encryption is not end-to-end archive encryption.

New CLI: wp stream-backup run|status|repositories|test|list|retry|abandon|cancel.
The old wp sftp-backup alias remains. test/list accept --repository=ID;
public test requires --allow-public-probe.
An array WPSSB_REPOSITORIES constant can override fields by repository ID.

The following append/truncate description applies to legacy single-SFTP mode:

* **Zero local space**: the SQL dump and the tar archive are compressed on the fly and appended to files on the SFTP server.
* **Works on shared hosting**: pure PHP (phpseclib 3, bundled and namespaced), no `ssh2` extension, no `exec()`.
* **Resumable**: the work is split in short steps (default: 60% of `max_execution_time`, max 25 s). Each step appends a complete gzip member to the remote file; after an interruption the remote file is truncated back to the last committed position and the backup continues from there.
* **Secure**: SFTP password or private key, encrypted in the database (libsodium) or defined in `wp-config.php`; the server host key fingerprint must be confirmed and is checked before any credential is sent.
* **Scheduling** via WP-Cron, **retention** (keep the last N backups), **excluded paths and tables**, e-mail notifications.
* **WP-CLI**: `wp sftp-backup run|status|test|list|cancel`.
* **Standalone restore script** (`restore.php`) uploaded with every backup: browser wizard or CLI, resumable, rewrites URLs and paths (serialization-safe).

= Remote layout =

Each backup is stored in its own folder: `<remote folder>/<site>-<YYYYmmdd-HHMMSS>-<id>/`

* `database.sql.gz` – SQL dump (multi-member gzip)
* `files.tar.gz` – WordPress root as POSIX tar (multi-member gzip)
* `restore.php` – restore script
* `manifest.json` – written last: its presence means the backup is complete

Both archives are standard: `tar xzf files.tar.gz` and `gunzip < database.sql.gz | mysql …` work as well.

== Installation ==

Requirements: WordPress 5.4+, PHP 7.4+ with zlib and mysqli, outbound access
to the destination (SFTP port or HTTPS 443) and an existing remote destination.
Download wp-sftp-stream-backup.zip from the GitHub release, not the source code ZIP.

1. Upload the plugin zip from *Plugins → Add New Plugin → Upload Plugin*, click *Install Now* and activate it.
   Alternatively extract it to `wp-content/plugins/` or run `wp plugin install wp-sftp-stream-backup.zip --activate`.
2. Open *Tools → Backup repositories*, choose a type in *Add repository* and click *Save repositories*.
3. Fill in the destination, save, click *Test saved connection*, enable it and choose a readable primary repository.
   For SFTP verify the fingerprint independently (e.g. `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server).
4. Open *Tools → SFTP Backup* and click *Back up now*. Check that `manifest.json` exists remotely.
5. Enable the schedule, or use a system cron for large/low-traffic sites.

Legacy single-SFTP installations keep using *Tools → SFTP Backup* until repository settings are saved.
Deactivation clears schedules. Deleting the plugin removes its settings but never deletes remote backups.

= Credentials in wp-config.php (optional) =

    define( 'WPSSB_SFTP_HOST', 'backup.example.com' );
    define( 'WPSSB_SFTP_PORT', 22 );
    define( 'WPSSB_SFTP_USERNAME', 'user' );
    define( 'WPSSB_SFTP_AUTH', 'key' );                 // or 'password'
    define( 'WPSSB_SFTP_PRIVATE_KEY_FILE', '/home/user/.ssh/backup_key' ); // or WPSSB_SFTP_PRIVATE_KEY / WPSSB_SFTP_PASSWORD
    define( 'WPSSB_SFTP_PASSPHRASE', '' );
    define( 'WPSSB_SFTP_FINGERPRINT', 'SHA256:…' );
    define( 'WPSSB_SFTP_PATH', 'backups/mysite' );
    define( 'WPSSB_ENCRYPTION_KEY', '…' );              // optional key for credentials stored in the database

= Large sites =

WP-Cron needs site traffic. For large sites run the backup from a system cron, which also gives a consistent database snapshot (single transaction):

    0 3 * * * cd /path/to/wordpress && wp sftp-backup run --quiet

== Restore ==

For format 2, download ALL parts, catalogs, descriptors, manifest and restore
script of one generation. The File Drop owner downloads files from their account;
upload-only links cannot retrieve them. Keep prefixed data filenames; the script
and manifest can be renamed to restore.php and manifest.json. The restore detects
format 2 and checks integrity without joining archives. It still needs room for
the restored site and database, and does not download remote objects itself.

1. Download `files.tar.gz`, `database.sql.gz`, `manifest.json` and `restore.php` from the backup folder.
2. Upload them into the empty web root of the destination and create an empty database.
3. Browser: edit `restore.php`, set `WPSSB_RESTORE_PASSWORD` (8+ characters) and open `https://your-site/restore.php`.
   Shell: `php restore.php --db-host=localhost --db-name=NAME --db-user=USER --db-pass=PASS --url=https://new-site`
4. The script extracts the files, imports the database, rewrites the old URL/path (also inside serialized and JSON data, GUIDs excluded), updates `wp-config.php` and finally deletes itself.

== Frequently Asked Questions ==

= Is any temporary file created? =

No. Backup payload flows from the database/filesystem through zlib and bounded memory buffers to remote storage. Only settings, job metadata and history are stored in wp_options, never archive payload.

= Is the database dump consistent? =

With WP-CLI the whole dump runs in a single `START TRANSACTION WITH CONSISTENT SNAPSHOT` (InnoDB). From the browser/WP-Cron the dump is split in several requests and is not a point-in-time snapshot. Rows are paged on the primary key (or a unique NOT NULL index); tables without any such key are paged with LIMIT/OFFSET and may miss or duplicate rows if they change during a web backup.

= What if outbound SFTP connections are blocked? =

*Test connection* reports it immediately. Some shared hosts block outgoing port 22: ask your provider or use a different SFTP port.

= Limitations =

* Single site only (no Multisite).
* File parts are bounded to 8 MiB, but database rows are read into memory. Very large records or BLOBs may require a higher PHP memory_limit; they are not staged in temporary files.
* `wp-content` outside the WordPress root is not included (a `wp-config.php` one level above the root is).
* Database views, triggers and stored procedures are not exported.
* The restore keeps the original table prefix.

== Changelog ==

= 1.1.1 =
* Radici Soluzioni Informatiche logo and linked credits.
* Responsive backup and repository screens with clearer status cards and keyboard focus.
* Matching standalone restore interface with an embedded, offline-ready logo.

= 1.1.0 =
* Multiple repositories, S3 Signature V4 and authenticated/public Nextcloud.
* Automatic replicas from one readable primary, independent retry and status.
* Bounded numbered parts with paged checksums and backward-compatible restore.

= 1.0.0 =
* First release.
