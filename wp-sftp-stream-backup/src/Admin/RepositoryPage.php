<?php
namespace WPSSB\Admin;

use WPSSB\Repositories;
use WPSSB\Job\Driver;
use WPSSB\Job\Replication;
use WPSSB\Job\State;

class RepositoryPage {
	public static function register(): void {
		add_action( 'admin_menu', function () {
			add_management_page( 'Backup repositories', 'Backup repositories', AdminPage::CAP, 'wpssb-repositories', array( __CLASS__, 'render' ) );
		} );
		add_action( 'admin_post_wpssb_repositories', array( __CLASS__, 'submit' ) );
	}

	public static function submit(): void {
		if ( ! current_user_can( AdminPage::CAP ) ) {
			wp_die( 'Not authorized.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpssb_repositories' );
		$message = '';
		try {
			if ( isset( $_POST['retry'] ) ) {
				Driver::kick( Replication::retry() );
				Driver::ensureFallback( 120 );
				$message = 'Replica retries started.';
			} elseif ( isset( $_POST['abandon'] ) ) {
				Replication::abandon();
				$message = 'Pending replicas abandoned. Completed copies preserved.';
			} elseif ( isset( $_POST['test'] ) ) {
				$message = Repositories::test( sanitize_key( wp_unslash( $_POST['test'] ) ), ! empty( $_POST['allow_probe'] ) );
			} else {
				$input = isset( $_POST['repos'] ) && is_array( $_POST['repos'] ) ? wp_unslash( $_POST['repos'] ) : array();
				Repositories::save( $input );
				$message = 'Repositories saved. Connection tests use the saved settings.';
			}
		} catch ( \WPSSB\Remote\HostKeyException $e ) {
			$message = 'SFTP host key: ' . $e->fingerprint() . '. Verify this fingerprint independently, then enter it and save.';
		} catch ( \Throwable $e ) {
			$message = 'Error: ' . $e->getMessage();
		}
		set_transient( 'wpssb_repo_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'tools.php?page=wpssb-repositories' ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( AdminPage::CAP ) ) {
			return;
		}
		$all = Repositories::raw();
		$notice = get_transient( 'wpssb_repo_notice_' . get_current_user_id() );
		delete_transient( 'wpssb_repo_notice_' . get_current_user_id() );
		?>
		<div class="wrap wpssb">
			<?php Branding::header( __( 'Backup repositories', 'wp-sftp-stream-backup' ), 'repositories' ); ?>
			<div class="wpssb-intro">
				<p>Enable all destinations that should receive a copy. Select one readable primary. No backup payload is stored on this site's disk.</p>
				<p>New backups use numbered parts (maximum 8 MiB). Public File Drop is replica-only, requires Nextcloud 29+, and cannot verify, list or delete uploaded files.</p>
			</div>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( Repositories::locked() ) : ?>
				<div class="notice notice-warning"><p>Repository settings are locked while a backup or pending replica needs them. Retry or abandon pending replicas before starting another backup.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="wpssb_repositories">
				<?php wp_nonce_field( 'wpssb_repositories' ); ?>
				<?php foreach ( $all['items'] as $id => $item ) :
					$item = array_merge( Repositories::blank(), $item );
					$prefix = 'repos[items][' . $id . ']';
					$fields = array( 'name', 'base', 'retention' );
					if ( 'sftp' === $item['type'] ) {
						$fields = array_merge( $fields, array( 'host', 'port', 'username', 'auth', 'password', 'private_key', 'passphrase', 'fingerprint' ) );
					} elseif ( 's3' === $item['type'] ) {
						$fields = array_merge( $fields, array( 'provider', 'location', 'endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'session_token', 'path_style' ) );
					} else {
						$fields = array_merge( $fields, array( 'url', 'username', 'password', 'path', 'public_url', 'public_password' ) );
					}
					?>
					<section class="wpssb-card wpssb-repository" aria-labelledby="<?php echo esc_attr( 'wpssb-title-' . $id ); ?>">
						<div class="wpssb-repository-heading">
							<h2 id="<?php echo esc_attr( 'wpssb-title-' . $id ); ?>"><?php echo esc_html( $item['name'] ); ?></h2>
							<span class="wpssb-type"><?php echo esc_html( $item['type'] ); ?></span>
							<code><?php echo esc_html( $id ); ?></code>
						</div>
						<input type="hidden" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="<?php echo esc_attr( $item['type'] ); ?>">
						<div class="wpssb-repository-toggles">
							<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[enabled]' ); ?>" value="1" <?php checked( $item['enabled'] ); ?>> Enabled</label>
							<label><input type="radio" name="repos[primary]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $all['primary'], $id ); ?>> Primary</label>
						</div>
						<table class="form-table">
							<?php foreach ( $fields as $field ) :
								$secret = in_array( $field, Repositories::SECRETS, true );
								$name = $prefix . '[' . $field . ']';
								$element = 'wpssb-repo-' . $id . '-' . $field;
								?>
								<tr><th><label for="<?php echo esc_attr( $element ); ?>"><?php echo esc_html( str_replace( '_', ' ', ucfirst( $field ) ) ); ?></label></th><td>
								<?php if ( 'path_style' === $field ) : ?>
									<input id="<?php echo esc_attr( $element ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $item[ $field ] ); ?>>
								<?php elseif ( 'auth' === $field || 'provider' === $field ) :
									$choices = 'auth' === $field ? array( 'password', 'key' ) : array( 'custom', 'amazon', 'hetzner', 'digitalocean', 'aruba' );
									?>
									<select id="<?php echo esc_attr( $element ); ?>" name="<?php echo esc_attr( $name ); ?>">
										<?php foreach ( $choices as $choice ) : ?>
											<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $item[ $field ], $choice ); ?>><?php echo esc_html( $choice ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'private_key' === $field ) : ?>
									<textarea id="<?php echo esc_attr( $element ); ?>" class="large-text code" rows="4" name="<?php echo esc_attr( $name ); ?>" placeholder="Leave empty to keep saved key"></textarea>
								<?php else : ?>
									<input id="<?php echo esc_attr( $element ); ?>" class="regular-text" type="<?php echo $secret ? 'password' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $secret ? '' : (string) $item[ $field ] ); ?>" autocomplete="off">
								<?php endif; ?>
								<?php if ( $secret ) : ?>
									<span class="description"><?php echo '' !== $item[ $field ] ? 'Saved; leave empty to keep.' : 'Not saved.'; ?></span>
									<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[clear_' . $field . ']' ); ?>" value="1"> Clear</label>
								<?php endif; ?>
								</td></tr>
							<?php endforeach; ?>
						</table>
						<?php if ( 's3' === $item['type'] ) : ?>
							<p>Set Provider and Location to fill a blank endpoint automatically for Amazon, Hetzner or Spaces. Region is the signing region (leave blank for the preset default). An explicit endpoint overrides the preset. Aruba requires the endpoint and signing region assigned by the provider. Bucket must already exist. No public ACL is applied.</p>
						<?php elseif ( 'nextcloud' === $item['type'] ) : ?>
							<p>Use an app password for authenticated WebDAV. For public mode, enter the complete /s/TOKEN link in Public URL; authenticated URL/user/password are then ignored. Base is a backup namespace; Path is the authenticated Nextcloud folder.</p>
						<?php endif; ?>
						<button class="button" name="test" value="<?php echo esc_attr( $id ); ?>">Test saved connection</button>
						<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[remove]' ); ?>" value="1"> Remove on save</label>
					</section>
				<?php endforeach; ?>
				<p><label><input type="checkbox" name="allow_probe" value="1"> Allow a small permanent test upload to public File Drop (cannot be deleted by the plugin).</label></p>
				<p><label for="wpssb-add-repository">Add repository:</label>
					<select id="wpssb-add-repository" name="repos[add_type]"><option value="">None</option><option value="sftp">SFTP</option><option value="s3">S3 compatible</option><option value="nextcloud">Nextcloud</option></select>
				</p>
				<?php submit_button( 'Save repositories' ); ?>
				<?php $state = State::load(); if ( $state && 'partial' === $state['status'] ) : ?>
					<button class="button button-primary" name="retry" value="1">Retry failed copies</button>
					<button class="button" name="abandon" value="1">Abandon pending copies; keep completed backups</button>
				<?php endif; ?>
			</form>
			<?php Branding::footer(); ?>
		</div>
		<?php
	}
}
