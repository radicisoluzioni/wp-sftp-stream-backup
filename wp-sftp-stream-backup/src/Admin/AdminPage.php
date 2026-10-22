<?php
/**
 * Admin screen (Tools > SFTP Backup) and settings form handling.
 *
 * @package WPSSB
 */

namespace WPSSB\Admin;

use WPSSB\Job\Driver;
use WPSSB\Job\State;
use WPSSB\Settings;

class AdminPage {

	const SLUG = 'wpssb';
	const CAP  = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpssb_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPSSB_FILE ), array( __CLASS__, 'actionLinks' ) );
	}

	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_management_page(
			__( 'SFTP Stream Backup', 'wp-sftp-stream-backup' ),
			__( 'SFTP Backup', 'wp-sftp-stream-backup' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function actionLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'wp-sftp-stream-backup' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'tools_page_' . self::SLUG, 'tools_page_wpssb-repositories' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wpssb-admin', plugins_url( 'assets/admin.css', WPSSB_FILE ), array(), WPSSB_VERSION );
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'wpssb-admin', plugins_url( 'assets/admin.js', WPSSB_FILE ), array(), WPSSB_VERSION, true );
		wp_localize_script(
			'wpssb-admin',
			'WPSSB',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpssb' ),
				'state'   => State::summary( State::load() ),
				'i18n'    => array(
					'testing'       => __( 'Connecting…', 'wp-sftp-stream-backup' ),
					'unknownHost'   => __( 'The server presented this host key fingerprint. Verify it with your hosting provider (e.g. "ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub" on the server) before trusting it:', 'wp-sftp-stream-backup' ),
					'mismatch'      => __( 'WARNING: the host key fingerprint does not match the saved one! Do not trust it unless you know the server key was changed.', 'wp-sftp-stream-backup' ),
					'trust'         => __( 'Trust this fingerprint', 'wp-sftp-stream-backup' ),
					'trusted'       => __( 'Fingerprint copied into the form. Testing again…', 'wp-sftp-stream-backup' ),
					'saveReminder'  => __( 'Remember to save the settings.', 'wp-sftp-stream-backup' ),
					'confirmCancel' => \WPSSB\Job\Replication::configured()
						? __( 'Cancel the running backup? Completed copies are preserved; partial uploads may require manual cleanup.', 'wp-sftp-stream-backup' )
						: __( 'Cancel the running backup? Its partial files on the server will be deleted.', 'wp-sftp-stream-backup' ),
					'starting'      => __( 'Starting…', 'wp-sftp-stream-backup' ),
					'error'         => __( 'Error', 'wp-sftp-stream-backup' ),
					'loading'       => __( 'Loading…', 'wp-sftp-stream-backup' ),
					'none'          => __( 'No backups found.', 'wp-sftp-stream-backup' ),
					'ready'         => __( 'Ready when you are. Start a backup or configure an automatic schedule below.', 'wp-sftp-stream-backup' ),
					'backupList'    => __( 'Remote backups', 'wp-sftp-stream-backup' ),
					'complete'      => __( 'complete', 'wp-sftp-stream-backup' ),
					'incomplete'    => __( 'incomplete', 'wp-sftp-stream-backup' ),
					'phases'        => array(
						'init'      => __( 'Preparing', 'wp-sftp-stream-backup' ),
						'db'        => __( 'Dumping database', 'wp-sftp-stream-backup' ),
						'files'     => __( 'Archiving files', 'wp-sftp-stream-backup' ),
						'finalize'  => __( 'Writing manifest', 'wp-sftp-stream-backup' ),
						'retention' => __( 'Applying retention', 'wp-sftp-stream-backup' ),
						'replicate' => __( 'Replicating and verifying repositories', 'wp-sftp-stream-backup' ),
						'done'      => __( 'Completed', 'wp-sftp-stream-backup' ),
					),
					'statuses'      => array(
						'running'   => __( 'Running', 'wp-sftp-stream-backup' ),
						'done'      => __( 'Completed', 'wp-sftp-stream-backup' ),
						'failed'    => __( 'Failed', 'wp-sftp-stream-backup' ),
						'cancelled' => __( 'Cancelled', 'wp-sftp-stream-backup' ),
						'partial'   => __( 'Partially completed: retry or abandon pending copies', 'wp-sftp-stream-backup' ),
					),
				),
			)
		);
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-sftp-stream-backup' ), 403 );
		}
		check_admin_referer( 'wpssb_save' );
		$input = isset( $_POST['wpssb'] ) && is_array( $_POST['wpssb'] ) ? wp_unslash( $_POST['wpssb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Settings::sanitize().
		$new   = Settings::sanitize( $input );
		Settings::save( $new );
		Driver::reschedule( $new );
		wp_safe_redirect( add_query_arg( 'updated', '1', self::url() ) );
		exit;
	}

	private static function constantNote( string $key ): string {
		if ( ! Settings::isConstant( $key ) ) {
			return '';
		}
		return ' <span class="description">' . esc_html(
			sprintf(
				/* translators: %s: constant name */
				__( '(defined by %s in wp-config.php)', 'wp-sftp-stream-backup' ),
				Settings::CONSTANTS[ $key ]
			)
		) . '</span>';
	}

	private static function disabled( string $key ): string {
		return Settings::isConstant( $key ) ? ' disabled' : '';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		global $wpdb;
		$raw      = Settings::raw();
		$s        = Settings::get();
		$broken   = Settings::brokenSecrets();
		$history  = State::history();
		$tables   = $wpdb->get_col( 'SHOW TABLES' );
		$schedule = wp_next_scheduled( Driver::CRON_SCHEDULE );
		$labels   = array(
			'off'        => __( 'Disabled', 'wp-sftp-stream-backup' ),
			'hourly'     => __( 'Hourly', 'wp-sftp-stream-backup' ),
			'twicedaily' => __( 'Twice daily', 'wp-sftp-stream-backup' ),
			'daily'      => __( 'Daily', 'wp-sftp-stream-backup' ),
			'weekly'     => __( 'Weekly', 'wp-sftp-stream-backup' ),
		);
		?>
		<div class="wrap wpssb">
			<?php Branding::header( __( 'SFTP Stream Backup', 'wp-sftp-stream-backup' ), 'backup' ); ?>
			<p class="wpssb-intro"><?php esc_html_e( 'Full backups (database + files) streamed to SFTP, S3 and Nextcloud repositories: no backup archive is written to this site\'s disk.', 'wp-sftp-stream-backup' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wp-sftp-stream-backup' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $broken ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Some stored SFTP credentials can no longer be decrypted (the WordPress security keys probably changed). Please enter them again.', 'wp-sftp-stream-backup' ); ?></p></div>
			<?php endif; ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'DISABLE_WP_CRON is set: make sure a system cron calls wp-cron.php, otherwise scheduled backups will not run.', 'wp-sftp-stream-backup' ); ?></p></div>
			<?php endif; ?>

			<div class="wpssb-card" id="wpssb-run">
				<h2><?php esc_html_e( 'Backup', 'wp-sftp-stream-backup' ); ?></h2>
				<p>
					<button type="button" class="button button-primary" id="wpssb-start"><?php esc_html_e( 'Back up now', 'wp-sftp-stream-backup' ); ?></button>
					<button type="button" class="button" id="wpssb-cancel" hidden><?php esc_html_e( 'Cancel', 'wp-sftp-stream-backup' ); ?></button>
					<?php if ( $schedule ) : ?>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: date and time */
									__( 'Next scheduled backup: %s', 'wp-sftp-stream-backup' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $schedule )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</p>
				<div id="wpssb-status" class="wpssb-status" aria-live="polite"></div>
				<details id="wpssb-log-wrap">
					<summary><?php esc_html_e( 'Log', 'wp-sftp-stream-backup' ); ?></summary>
					<pre id="wpssb-log" class="wpssb-log"></pre>
				</details>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpssb-form" autocomplete="off">
				<input type="hidden" name="action" value="wpssb_save">
				<?php wp_nonce_field( 'wpssb_save' ); ?>

				<div class="wpssb-card">
					<details <?php echo \WPSSB\Job\Replication::configured() ? '' : 'open'; ?>>
					<summary><?php esc_html_e( 'Legacy SFTP connection settings', 'wp-sftp-stream-backup' ); ?></summary>
					<p><?php esc_html_e( 'After saving repository settings, manage connections and retention on the Backup repositories page. The fields below only control legacy single-SFTP mode.', 'wp-sftp-stream-backup' ); ?></p>
					<h2><?php esc_html_e( 'SFTP connection', 'wp-sftp-stream-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpssb-host"><?php esc_html_e( 'Host', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="wpssb-host" name="wpssb[host]" value="<?php echo esc_attr( $s['host'] ); ?>"<?php echo self::disabled( 'host' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<label for="wpssb-port"><?php esc_html_e( 'Port', 'wp-sftp-stream-backup' ); ?></label>
								<input type="number" class="small-text" id="wpssb-port" name="wpssb[port]" min="1" max="65535" value="<?php echo esc_attr( (string) $s['port'] ); ?>"<?php echo self::disabled( 'port' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php echo self::constantNote( 'host' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-username"><?php esc_html_e( 'Username', 'wp-sftp-stream-backup' ); ?></label></th>
							<td><input type="text" class="regular-text" id="wpssb-username" name="wpssb[username]" value="<?php echo esc_attr( $s['username'] ); ?>"<?php echo self::disabled( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo self::constantNote( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'wp-sftp-stream-backup' ); ?></th>
							<td>
								<fieldset<?php echo self::disabled( 'auth' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<label><input type="radio" name="wpssb[auth]" value="password" <?php checked( 'password', $s['auth'] ); ?>> <?php esc_html_e( 'Password', 'wp-sftp-stream-backup' ); ?></label>
									&nbsp;
									<label><input type="radio" name="wpssb[auth]" value="key" <?php checked( 'key', $s['auth'] ); ?>> <?php esc_html_e( 'SSH private key', 'wp-sftp-stream-backup' ); ?></label>
								</fieldset>
								<?php echo self::constantNote( 'auth' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						</tr>
						<tr class="wpssb-auth-password">
							<th scope="row"><label for="wpssb-password"><?php esc_html_e( 'Password', 'wp-sftp-stream-backup' ); ?></label></th>
							<td><?php self::secretField( 'password', 'password', $raw ); ?></td>
						</tr>
						<tr class="wpssb-auth-key">
							<th scope="row"><label for="wpssb-private_key"><?php esc_html_e( 'Private key', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<?php self::secretField( 'private_key', 'textarea', $raw ); ?>
								<p class="description"><?php esc_html_e( 'OpenSSH, PuTTY (PPK) or PKCS#1/#8 format. Alternatively define WPSSB_SFTP_PRIVATE_KEY_FILE in wp-config.php with the path of a key file stored outside the web root.', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
						<tr class="wpssb-auth-key">
							<th scope="row"><label for="wpssb-passphrase"><?php esc_html_e( 'Key passphrase', 'wp-sftp-stream-backup' ); ?></label></th>
							<td><?php self::secretField( 'passphrase', 'password', $raw ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-fingerprint"><?php esc_html_e( 'Host key fingerprint', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="wpssb-fingerprint" name="wpssb[fingerprint]" value="<?php echo esc_attr( $s['fingerprint'] ); ?>" placeholder="SHA256:…"<?php echo self::disabled( 'fingerprint' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php echo self::constantNote( 'fingerprint' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<p class="description"><?php esc_html_e( 'Protects against man-in-the-middle attacks: credentials are never sent to a server whose key does not match.', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-remote_path"><?php esc_html_e( 'Remote folder', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="wpssb-remote_path" name="wpssb[remote_path]" value="<?php echo esc_attr( $s['remote_path'] ); ?>"<?php echo self::disabled( 'remote_path' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php echo self::constantNote( 'remote_path' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<p class="description"><?php esc_html_e( 'Absolute or relative to the SFTP home directory. Each backup is stored in its own sub-folder.', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"></th>
							<td>
								<button type="button" class="button" id="wpssb-test"><?php esc_html_e( 'Test connection', 'wp-sftp-stream-backup' ); ?></button>
								<div id="wpssb-test-result" class="wpssb-test-result" aria-live="polite"></div>
							</td>
						</tr>
					</table>
					</details>
				</div>

				<div class="wpssb-card">
					<h2><?php esc_html_e( 'Schedule and retention', 'wp-sftp-stream-backup' ); ?></h2>
					<p><?php esc_html_e( 'In multi-repository mode, retention is configured separately on the Backup repositories page; the retention field here is legacy-only.', 'wp-sftp-stream-backup' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpssb-schedule"><?php esc_html_e( 'Automatic backup', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<select id="wpssb-schedule" name="wpssb[schedule]">
									<?php foreach ( $labels as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $s['schedule'] ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<label for="wpssb-schedule_time"><?php esc_html_e( 'at', 'wp-sftp-stream-backup' ); ?></label>
								<input type="time" id="wpssb-schedule_time" name="wpssb[schedule_time]" value="<?php echo esc_attr( $s['schedule_time'] ); ?>">
								<p class="description"><?php esc_html_e( 'Uses WP-Cron (site timezone). For large sites prefer a system cron running "wp sftp-backup run".', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-retention"><?php esc_html_e( 'Backups to keep', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="wpssb-retention" name="wpssb[retention]" min="0" max="1000" value="<?php echo esc_attr( (string) $s['retention'] ); ?>">
								<p class="description"><?php esc_html_e( 'Older complete backups of this site are deleted from the server. 0 = keep all. Incomplete backups older than 2 days are always removed.', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpssb-card">
					<h2><?php esc_html_e( 'Content', 'wp-sftp-stream-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpssb-exclude_paths"><?php esc_html_e( 'Excluded paths', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="8" id="wpssb-exclude_paths" name="wpssb[exclude_paths]"><?php echo esc_textarea( $s['exclude_paths'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One per line, relative to the WordPress root. Wildcards (* ? [ ]) are allowed, e.g. "wp-content/cache" or "*.log".', 'wp-sftp-stream-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Database tables', 'wp-sftp-stream-backup' ); ?></th>
							<td>
								<label><input type="checkbox" name="wpssb[all_tables]" value="1" <?php checked( $s['all_tables'] ); ?>> <?php echo esc_html( sprintf( /* translators: %s: table prefix */ __( 'Also include tables not starting with "%s"', 'wp-sftp-stream-backup' ), $wpdb->prefix ) ); ?></label>
								<details class="wpssb-tables">
									<summary><?php esc_html_e( 'Exclude tables', 'wp-sftp-stream-backup' ); ?> (<?php echo (int) count( (array) $s['exclude_tables'] ); ?>)</summary>
									<?php foreach ( (array) $tables as $table ) : ?>
										<label><input type="checkbox" name="wpssb[exclude_tables][]" value="<?php echo esc_attr( $table ); ?>" <?php checked( in_array( $table, (array) $s['exclude_tables'], true ) ); ?>> <code><?php echo esc_html( $table ); ?></code></label><br>
									<?php endforeach; ?>
								</details>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpssb-card">
					<h2><?php esc_html_e( 'Notifications and advanced', 'wp-sftp-stream-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpssb-notify_on"><?php esc_html_e( 'E-mail notification', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<select id="wpssb-notify_on" name="wpssb[notify_on]">
									<option value="never" <?php selected( 'never', $s['notify_on'] ); ?>><?php esc_html_e( 'Never', 'wp-sftp-stream-backup' ); ?></option>
									<option value="failure" <?php selected( 'failure', $s['notify_on'] ); ?>><?php esc_html_e( 'On failure', 'wp-sftp-stream-backup' ); ?></option>
									<option value="always" <?php selected( 'always', $s['notify_on'] ); ?>><?php esc_html_e( 'Always', 'wp-sftp-stream-backup' ); ?></option>
								</select>
								<input type="email" class="regular-text" name="wpssb[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-compression"><?php esc_html_e( 'Compression level', 'wp-sftp-stream-backup' ); ?></label></th>
							<td><input type="number" class="small-text" id="wpssb-compression" name="wpssb[compression]" min="1" max="9" value="<?php echo esc_attr( (string) $s['compression'] ); ?>"> <span class="description"><?php esc_html_e( '1 = fastest, 9 = smallest.', 'wp-sftp-stream-backup' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpssb-step_time"><?php esc_html_e( 'Seconds per step', 'wp-sftp-stream-backup' ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="wpssb-step_time" name="wpssb[step_time]" min="0" max="600" value="<?php echo esc_attr( (string) $s['step_time'] ); ?>">
								<span class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: seconds */
											__( '0 = automatic (currently %d s, based on max_execution_time).', 'wp-sftp-stream-backup' ),
											(int) Driver::budget()
										)
									);
									?>
								</span>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save settings', 'wp-sftp-stream-backup' ) ); ?>
			</form>

			<div class="wpssb-card">
				<h2><?php esc_html_e( 'Backups on the server', 'wp-sftp-stream-backup' ); ?></h2>
				<p>
					<?php if ( \WPSSB\Job\Replication::configured() ) : $repos = \WPSSB\Repositories::raw(); ?>
						<label>Known plugin history:
							<select id="wpssb-list-repository">
								<?php foreach ( $repos['items'] as $repo_id => $repository ) : ?>
									<option value="<?php echo esc_attr( $repo_id ); ?>" <?php selected( $repos['primary'], $repo_id ); ?>><?php echo esc_html( $repository['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<button type="button" class="button" id="wpssb-list"><?php esc_html_e( 'Load list', 'wp-sftp-stream-backup' ); ?></button>
				</p>
				<div id="wpssb-remote-list"></div>
				<p class="description"><?php esc_html_e( 'Download all files of the selected backup, including its manifest, catalogs, numbered parts and restore.php. File Drop history records uploads accepted, not remote verification. Legacy SFTP backups contain two complete archives.', 'wp-sftp-stream-backup' ); ?></p>
			</div>

			<div class="wpssb-card">
				<h2><?php esc_html_e( 'History', 'wp-sftp-stream-backup' ); ?></h2>
				<?php if ( ! $history ) : ?>
					<p><?php esc_html_e( 'No backups yet.', 'wp-sftp-stream-backup' ); ?></p>
				<?php else : ?>
					<div class="wpssb-table-scroll" role="region" aria-label="<?php esc_attr_e( 'Backup history', 'wp-sftp-stream-backup' ); ?>" tabindex="0">
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Started', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Database', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Files', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Remote folder', 'wp-sftp-stream-backup' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'wp-sftp-stream-backup' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $history as $h ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $h['started_at'] ) ); ?></td>
								<td><span class="wpssb-badge wpssb-<?php echo esc_attr( $h['status'] ); ?>"><?php echo esc_html( $h['status'] ); ?></span></td>
								<td><?php echo esc_html( $h['trigger'] ); ?></td>
								<td><?php echo esc_html( size_format( (int) $h['db_bytes'], 1 ) ); ?></td>
								<td><?php echo esc_html( sprintf( '%s (%d)', size_format( (int) $h['files_bytes'], 1 ), (int) $h['files_count'] ) ); ?></td>
								<td><code><?php echo esc_html( $h['remote_dir'] ); ?></code></td>
								<td>
									<?php
									$notes = array();
									if ( (int) $h['warnings'] > 0 ) {
										/* translators: %d: number of warnings */
										$notes[] = sprintf( _n( '%d warning', '%d warnings', (int) $h['warnings'], 'wp-sftp-stream-backup' ), (int) $h['warnings'] );
									}
									if ( '' !== $h['error'] && 'done' !== $h['status'] ) {
										$notes[] = $h['error'];
									}
									echo esc_html( implode( ' — ', $notes ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
			<?php Branding::footer(); ?>
		</div>
		<?php
	}

	private static function secretField( string $key, string $type, array $raw ): void {
		$id = 'wpssb-' . $key;
		if ( Settings::isConstant( $key ) ) {
			echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" value="••••••••" disabled>' . self::constantNote( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$has         = '' !== (string) $raw[ $key ];
		$placeholder = $has ? __( '•••••••• (saved, leave empty to keep)', 'wp-sftp-stream-backup' ) : '';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text code" rows="5" id="' . esc_attr( $id ) . '" name="wpssb[' . esc_attr( $key ) . ']" placeholder="' . esc_attr( $placeholder ) . '" spellcheck="false"></textarea>';
		} else {
			echo '<input type="password" class="regular-text" id="' . esc_attr( $id ) . '" name="wpssb[' . esc_attr( $key ) . ']" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password">';
		}
		if ( $has ) {
			echo ' <label><input type="checkbox" name="wpssb[clear_' . esc_attr( $key ) . ']" value="1"> ' . esc_html__( 'Remove', 'wp-sftp-stream-backup' ) . '</label>';
		}
	}
}
