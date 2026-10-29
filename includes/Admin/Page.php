<?php
/**
 * Tools → Backups: run backups, list and download archives, edit profiles,
 * read logs and change the settings.
 *
 * @package RMD\BackupWordpress
 */

namespace RMD\BackupWordpress\Admin;

use DateTimeImmutable;
use RMD\BackupWordpress\Backup\Encryptor;
use RMD\BackupWordpress\Backup\Job;
use RMD\BackupWordpress\Backup\Runner;
use RMD\BackupWordpress\Destination\Destinations;
use RMD\BackupWordpress\Destination\SftpDestination;
use RMD\BackupWordpress\Destination\SftpSettings;
use RMD\BackupWordpress\Domain\ArchiveName;
use RMD\BackupWordpress\Domain\Profile;
use RMD\BackupWordpress\Domain\SshKey;
use RMD\BackupWordpress\Log\LogStore;
use RMD\BackupWordpress\Notifier;
use RMD\BackupWordpress\Plugin;
use RMD\BackupWordpress\Rest\BackupController;
use RMD\BackupWordpress\Settings;
use RMD\BackupWordpress\Storage;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'rmd-backup-wordpress';

	private const ERRORS_TRANSIENT = 'rmd_bwp_form_errors_';

	/** Larger logs are shown from the end. */
	private const LOG_VIEW_BYTES = 2 * 1024 * 1024;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_post_rmd_bwp_save_profile', [ self::class, 'save_profile' ] );
		add_action( 'admin_post_rmd_bwp_copy_profile', [ self::class, 'copy_profile' ] );
		add_action( 'admin_post_rmd_bwp_delete_profile', [ self::class, 'delete_profile' ] );
		add_action( 'admin_post_rmd_bwp_download', [ self::class, 'download' ] );
		add_action( 'admin_post_rmd_bwp_delete_backup', [ self::class, 'delete_backup' ] );
		add_action( 'admin_post_rmd_bwp_delete_log', [ self::class, 'delete_log' ] );
		add_action( 'admin_post_rmd_bwp_save_settings', [ self::class, 'save_settings' ] );
		add_action( 'admin_post_rmd_bwp_save_sftp', [ self::class, 'save_sftp' ] );
		add_action( 'admin_post_rmd_bwp_test_sftp', [ self::class, 'test_sftp' ] );
		add_action( 'admin_post_rmd_bwp_reset_hostkey', [ self::class, 'reset_hostkey' ] );
		add_action( 'admin_post_rmd_bwp_generate_key', [ self::class, 'generate_key' ] );
		add_action( 'admin_post_rmd_bwp_download_pubkey', [ self::class, 'download_pubkey' ] );
		add_filter( 'plugin_action_links_' . RMD_BWP_BASENAME, [ self::class, 'action_links' ] );
	}

	public static function menu(): void {
		add_management_page(
			__( 'Backups', 'rmd-backup-wordpress' ),
			__( 'Backups', 'rmd-backup-wordpress' ),
			Plugin::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return [ 'rmd-bwp' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Backups', 'rmd-backup-wordpress' ) . '</a>' ] + $links;
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => self::SLUG ] + $args, admin_url( 'tools.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rmd-bwp-admin', RMD_BWP_URL . 'assets/css/admin.css', [], RMD_BWP_VERSION );
		wp_enqueue_script( 'rmd-bwp-admin', RMD_BWP_URL . 'assets/js/admin.js', [ 'wp-api-fetch' ], RMD_BWP_VERSION, true );

		$job = Job::current();
		wp_localize_script(
			'rmd-bwp-admin',
			'rmdBwpAdmin',
			[
				'path'    => '/' . BackupController::NAMESPACE . '/backup',
				'status'  => null === $job ? null : Job::status( $job ),
				'logUrl'  => self::url( [ 'tab' => 'logs' ] ),
				'strings' => [
					'confirmCancel' => __( 'Cancel the running backup?', 'rmd-backup-wordpress' ),
					'confirmDelete' => __( 'Delete this permanently?', 'rmd-backup-wordpress' ),
					'starting'      => __( 'Starting backup …', 'rmd-backup-wordpress' ),
					'failed'        => __( 'The request failed:', 'rmd-backup-wordpress' ),
					'retry'         => __( 'Continue', 'rmd-backup-wordpress' ),
					'cancel'        => __( 'Cancel backup', 'rmd-backup-wordpress' ),
					'cancelling'    => __( 'The backup stops after the current step …', 'rmd-backup-wordpress' ),
					'close'         => __( 'Close', 'rmd-backup-wordpress' ),
					'viewLog'       => __( 'View log', 'rmd-backup-wordpress' ),
					'warnings'      => __( 'Warnings', 'rmd-backup-wordpress' ),
					'background'    => __( 'You can leave this page; the backup continues in the background.', 'rmd-backup-wordpress' ),
					'status'        => [
						'success'   => __( 'Backup finished.', 'rmd-backup-wordpress' ),
						'warning'   => __( 'Backup finished with warnings.', 'rmd-backup-wordpress' ),
						'error'     => __( 'Backup failed:', 'rmd-backup-wordpress' ),
						'cancelled' => __( 'Backup cancelled.', 'rmd-backup-wordpress' ),
					],
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'rmd-backup-wordpress' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backups';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$log    = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap rmd-bwp">';
		echo '<h1>' . esc_html__( 'Backups', 'rmd-backup-wordpress' ) . '</h1>';
		self::render_tabs( $tab );
		self::render_notices();

		if ( 'profiles' === $tab && 'edit' === $action ) {
			self::render_edit();
		} elseif ( 'profiles' === $tab ) {
			self::render_profiles();
		} elseif ( 'logs' === $tab && '' !== $log ) {
			self::render_log( $log );
		} elseif ( 'logs' === $tab ) {
			self::render_logs();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_backups();
		}
		echo '</div>';
	}

	private static function render_tabs( string $current ): void {
		$tabs = [
			'backups'  => __( 'Backups', 'rmd-backup-wordpress' ),
			'profiles' => __( 'Profiles', 'rmd-backup-wordpress' ),
			'logs'     => __( 'Logs', 'rmd-backup-wordpress' ),
			'settings' => __( 'Settings', 'rmd-backup-wordpress' ),
		];
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( 'backups' === $tab ? [] : [ 'tab' => $tab ] ) ),
				$tab === $current || ( 'backups' === $tab && ! isset( $tabs[ $current ] ) ) ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	private static function render_backups(): void {
		$profiles = Settings::profiles();

		try {
			Storage::prepare();
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ) . '</p></div>';
		}

		echo '<div class="rmd-bwp-run">';
		if ( [] === $profiles ) {
			printf( '<p>%s <a href="%s">%s</a></p>', esc_html__( 'There are no profiles.', 'rmd-backup-wordpress' ), esc_url( self::url( [ 'tab' => 'profiles' ] ) ), esc_html__( 'Add a profile', 'rmd-backup-wordpress' ) );
		} else {
			echo '<label for="rmd-bwp-profile">' . esc_html__( 'Profile', 'rmd-backup-wordpress' ) . '</label> ';
			echo '<select id="rmd-bwp-profile">';
			foreach ( $profiles as $profile ) {
				printf( '<option value="%s">%s</option>', esc_attr( $profile->id ), esc_html( $profile->name ) );
			}
			echo '</select> ';
			echo '<button type="button" class="button button-primary rmd-bwp-start">' . esc_html__( 'Back up now', 'rmd-backup-wordpress' ) . '</button>';
		}
		echo '</div>';
		echo '<div id="rmd-bwp-progress" class="rmd-bwp-progress" hidden></div>';

		$backups = Destinations::local()->backups();
		echo '<h2>' . esc_html__( 'Stored backups', 'rmd-backup-wordpress' ) . '</h2>';
		if ( [] === $backups ) {
			echo '<p>' . esc_html__( 'No backups yet.', 'rmd-backup-wordpress' ) . '</p>';
		} else {
			$total = array_sum( array_column( $backups, 'size' ) );
			echo '<table class="widefat striped rmd-bwp-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Created', 'rmd-backup-wordpress' ) . '</th>';
			echo '<th>' . esc_html__( 'Profile', 'rmd-backup-wordpress' ) . '</th>';
			echo '<th>' . esc_html__( 'File', 'rmd-backup-wordpress' ) . '</th>';
			echo '<th class="num">' . esc_html__( 'Size', 'rmd-backup-wordpress' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
			foreach ( $backups as $backup ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td class="num">%s</td><td class="rmd-bwp-actions"><a class="button" href="%s">%s</a> <a class="button button-link-delete rmd-bwp-confirm" href="%s">%s</a></td></tr>',
					esc_html( self::date( $backup['created'] ) ),
					esc_html( isset( $profiles[ $backup['profile'] ] ) ? $profiles[ $backup['profile'] ]->name : $backup['profile'] ),
					esc_html( $backup['name'] ),
					esc_html( size_format( $backup['size'] ) ),
					esc_url( self::action_url( 'rmd_bwp_download', [ 'file' => $backup['name'] ] ) ),
					esc_html__( 'Download', 'rmd-backup-wordpress' ),
					esc_url( self::action_url( 'rmd_bwp_delete_backup', [ 'file' => $backup['name'] ] ) ),
					esc_html__( 'Delete', 'rmd-backup-wordpress' )
				);
			}
			echo '</tbody><tfoot><tr><th colspan="3">' . esc_html(
				sprintf(
					/* translators: %d: number of backups */
					_n( '%d backup', '%d backups', count( $backups ), 'rmd-backup-wordpress' ),
					count( $backups )
				)
			) . '</th><th class="num">' . esc_html( size_format( $total ) ) . '</th><th></th></tr></tfoot></table>';
		}

		$runs = array_slice( LogStore::runs(), 0, 10 );
		echo '<h2>' . esc_html__( 'Recent runs', 'rmd-backup-wordpress' ) . '</h2>';
		if ( [] === $runs ) {
			echo '<p>' . esc_html__( 'No runs yet.', 'rmd-backup-wordpress' ) . '</p>';
			return;
		}
		self::runs_table( $runs, false );
	}

	/**
	 * @param list<array<string, mixed>> $runs
	 */
	private static function runs_table( array $runs, bool $actions ): void {
		echo '<table class="widefat striped rmd-bwp-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Started', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Profile', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Trigger', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Size', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			$log    = (string) $run['id'] . '.log';
			$exists = null !== LogStore::path( $log );
			$links  = [];
			if ( $exists ) {
				$links[] = sprintf(
					'<a class="button" href="%s">%s</a>',
					esc_url(
						self::url(
							[
								'tab' => 'logs',
								'log' => $log,
							]
						)
					),
					esc_html__( 'Log', 'rmd-backup-wordpress' )
				);
			}
			if ( $exists && $actions ) {
				$links[] = sprintf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'rmd_bwp_download', [ 'file' => $log ] ) ), esc_html__( 'Download', 'rmd-backup-wordpress' ) );
				$links[] = sprintf( '<a class="button button-link-delete rmd-bwp-confirm" href="%s">%s</a>', esc_url( self::action_url( 'rmd_bwp_delete_log', [ 'file' => $log ] ) ), esc_html__( 'Delete', 'rmd-backup-wordpress' ) );
			}
			$message = '' === (string) $run['message'] ? '' : '<div class="rmd-bwp-message">' . esc_html( (string) $run['message'] ) . '</div>';
			if ( (int) $run['warnings'] > 0 ) {
				$message .= '<div class="rmd-bwp-message">' . esc_html(
					sprintf(
						/* translators: %d: number of warnings */
						_n( '%d warning', '%d warnings', (int) $run['warnings'], 'rmd-backup-wordpress' ),
						(int) $run['warnings']
					)
				) . '</div>';
			}
			$remote = (string) ( $run['remote'] ?? '' );
			if ( '' !== $remote ) {
				$message .= sprintf(
					' <span class="rmd-bwp-badge rmd-bwp-badge-%s">%s</span>',
					'uploaded' === $remote ? 'success' : 'error',
					esc_html( 'uploaded' === $remote ? __( 'uploaded', 'rmd-backup-wordpress' ) : __( 'upload failed', 'rmd-backup-wordpress' ) )
				);
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><span class="rmd-bwp-badge rmd-bwp-badge-%s">%s</span>%s</td><td>%s</td><td class="num">%s</td><td class="rmd-bwp-actions">%s</td></tr>',
				esc_html( self::date( (int) $run['started'] ) ),
				esc_html( (string) $run['profile_name'] ),
				esc_html( self::trigger_label( (string) $run['trigger'] ) ),
				esc_attr( (string) $run['status'] ),
				esc_html( Notifier::status_label( (string) $run['status'] ) ),
				$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( human_time_diff( (int) $run['started'], max( (int) $run['started'] + 1, (int) $run['finished'] ) ) ),
				esc_html( (int) $run['size'] > 0 ? size_format( (int) $run['size'] ) : '–' ),
				implode( ' ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
		echo '</tbody></table>';
	}

	private static function render_profiles(): void {
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url(
				self::url(
					[
						'tab'    => 'profiles',
						'action' => 'edit',
					]
				)
			),
			esc_html__( 'Add profile', 'rmd-backup-wordpress' )
		);

		$profiles = Settings::profiles();
		if ( [] === $profiles ) {
			echo '<p>' . esc_html__( 'There are no profiles.', 'rmd-backup-wordpress' ) . '</p>';
			return;
		}

		$labels = self::part_labels();
		echo '<table class="widefat striped rmd-bwp-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Profile', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Content', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Schedule', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Keep', 'rmd-backup-wordpress' ) . '</th>';
		echo '<th>' . esc_html__( 'Last run', 'rmd-backup-wordpress' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $profiles as $profile ) {
			$edit = self::url(
				[
					'tab'     => 'profiles',
					'action'  => 'edit',
					'profile' => $profile->id,
				]
			);
			$next = Runner::next_run( $profile->id );
			$last = LogStore::last( $profile->id );
			printf(
				'<tr><td><strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><a href="%1$s">%3$s</a> | <a href="%4$s">%5$s</a> | <span class="trash"><a href="%6$s" class="rmd-bwp-confirm">%7$s</a></span></div></td><td>%8$s</td><td>%9$s%10$s</td><td>%11$s</td><td>%12$s</td></tr>',
				esc_url( $edit ),
				esc_html( $profile->name ),
				esc_html__( 'Edit', 'rmd-backup-wordpress' ),
				esc_url( self::action_url( 'rmd_bwp_copy_profile', [ 'profile' => $profile->id ] ) ),
				esc_html__( 'Duplicate', 'rmd-backup-wordpress' ),
				esc_url( self::action_url( 'rmd_bwp_delete_profile', [ 'profile' => $profile->id ] ) ),
				esc_html__( 'Delete', 'rmd-backup-wordpress' ),
				esc_html( implode( ', ', array_map( static fn( string $part ): string => $labels[ $part ] ?? $part, $profile->parts ) ) ),
				esc_html( self::schedule_label( $profile ) ),
				null === $next ? '' : '<div class="rmd-bwp-message">' . esc_html(
					sprintf(
						/* translators: %s: date and time */
						__( 'Next: %s', 'rmd-backup-wordpress' ),
						self::date( $next )
					)
				) . '</div>',
				esc_html( self::keep_label( $profile ) ),
				null === $last ? '–' : sprintf( '<span class="rmd-bwp-badge rmd-bwp-badge-%s">%s</span> %s', esc_attr( (string) $last['status'] ), esc_html( Notifier::status_label( (string) $last['status'] ) ), esc_html( self::date( (int) $last['started'] ) ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_edit(): void {
		$id      = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile = '' === $id ? null : Settings::profile( $id );
		$values  = null === $profile ? Profile::defaults() : $profile->to_array();

		$draft = get_transient( self::ERRORS_TRANSIENT . get_current_user_id() );
		if ( is_array( $draft ) && ( $draft['id'] ?? '' ) === $id ) {
			delete_transient( self::ERRORS_TRANSIENT . get_current_user_id() );
			$values = array_merge( $values, (array) $draft['values'] );
			echo '<div class="notice notice-error"><ul class="ul-disc">';
			foreach ( (array) $draft['errors'] as $code ) {
				echo '<li>' . esc_html( self::error_message( (string) $code ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<h2>' . esc_html( null === $profile ? __( 'Add profile', 'rmd-backup-wordpress' ) : __( 'Edit profile', 'rmd-backup-wordpress' ) ) . '</h2>';
		printf( '<p><a href="%s">← %s</a></p>', esc_url( self::url( [ 'tab' => 'profiles' ] ) ), esc_html__( 'Back to profiles', 'rmd-backup-wordpress' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rmd-bwp-form">';
		echo '<input type="hidden" name="action" value="rmd_bwp_save_profile">';
		printf( '<input type="hidden" name="profile[id]" value="%s">', esc_attr( $id ) );
		wp_nonce_field( 'rmd_bwp_save_profile' );

		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_field( 'name', __( 'Name', 'rmd-backup-wordpress' ), (string) $values['name'], '', 'regular-text', true );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Content', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Back up', 'rmd-backup-wordpress' ) . '</th><td><fieldset><input type="hidden" name="profile[parts][]" value="">';
		foreach ( self::part_labels() as $part => $label ) {
			printf(
				'<label class="rmd-bwp-check"><input type="checkbox" name="profile[parts][]" value="%s" %s> %s</label>',
				esc_attr( $part ),
				checked( in_array( $part, (array) $values['parts'], true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'The backup folder of this plugin is never included.', 'rmd-backup-wordpress' ) . '</p></fieldset></td></tr>';
		self::textarea_field( 'exclude_patterns', __( 'Leave out', 'rmd-backup-wordpress' ), (array) $values['exclude_patterns'], __( 'One rule per line, like .gitignore, matched against the path in the archive: "name" matches everywhere, "/name" only in the WordPress folder, a trailing "/" only folders, "*" is a wildcard. Example: /wp-content/uploads/2019/', 'rmd-backup-wordpress' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Database', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_field( 'db_all_tables', __( 'Other tables', 'rmd-backup-wordpress' ), (bool) $values['db_all_tables'], __( 'Also back up tables without the table prefix of this site (e.g. of other applications in the same database).', 'rmd-backup-wordpress' ) );
		self::textarea_field( 'db_exclude_tables', __( 'Leave out tables', 'rmd-backup-wordpress' ), (array) $values['db_exclude_tables'], __( 'Table names with or without prefix, one per line.', 'rmd-backup-wordpress' ), 4 );
		self::checkbox_field( 'db_skip_transients', __( 'Transients', 'rmd-backup-wordpress' ), (bool) $values['db_skip_transients'], __( 'Leave out transients (temporary cache entries that WordPress rebuilds).', 'rmd-backup-wordpress' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Schedule', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::select_field( 'schedule', __( 'Run', 'rmd-backup-wordpress' ), (string) $values['schedule'], self::schedule_labels(), '' );
		self::text_field( 'time', __( 'Time', 'rmd-backup-wordpress' ), (string) $values['time'], __( 'HH:MM in the time zone of the site. For hourly runs only the minute counts.', 'rmd-backup-wordpress' ), 'small-text', false, '03:00', 'rmd-bwp-when rmd-bwp-when-hourly rmd-bwp-when-daily rmd-bwp-when-weekly rmd-bwp-when-monthly' );
		self::select_field( 'weekday', __( 'Day of the week', 'rmd-backup-wordpress' ), (string) $values['weekday'], self::weekday_labels(), '', 'rmd-bwp-when rmd-bwp-when-weekly' );
		self::number_field( 'monthday', __( 'Day of the month', 'rmd-backup-wordpress' ), (int) $values['monthday'], 1, 28, __( '1 to 28, so every month has this day.', 'rmd-backup-wordpress' ), 'rmd-bwp-when rmd-bwp-when-monthly' );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Scheduled runs use WP-Cron, which only runs when the site has visitors. For exact times set DISABLE_WP_CRON and call wp-cron.php from a server cron job, or use: wp rmd-backup run <profile>', 'rmd-backup-wordpress' ) . '</p>';

		echo '<h3>' . esc_html__( 'Retention', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::number_field( 'keep_count', __( 'Keep backups', 'rmd-backup-wordpress' ), (int) $values['keep_count'], 0, 1000, __( 'Number of newest backups of this profile to keep. 0 = no limit.', 'rmd-backup-wordpress' ) );
		self::number_field( 'keep_days', __( 'Keep for days', 'rmd-backup-wordpress' ), (int) $values['keep_days'], 0, 3650, __( 'Delete backups of this profile that are older. 0 = no limit. The newest backup is always kept.', 'rmd-backup-wordpress' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Off-site copy', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$remote_ready = '' !== Settings::recipient() && Destinations::remote()->is_configured();
		self::checkbox_field(
			'remote',
			__( 'Upload to SFTP', 'rmd-backup-wordpress' ),
			(bool) $values['remote'],
			$remote_ready
				? __( 'Also upload the encrypted backup to the SFTP server from the settings. The retention rules above also apply there.', 'rmd-backup-wordpress' )
				: __( 'Needs an encryption key and a tested SFTP connection in the settings first.', 'rmd-backup-wordpress' )
		);
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Notification', 'rmd-backup-wordpress' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::select_field( 'notify', __( 'Send email', 'rmd-backup-wordpress' ), (string) $values['notify'], self::notify_labels(), __( 'The log of the run is attached.', 'rmd-backup-wordpress' ) );
		self::text_field( 'notify_email', __( 'Recipient', 'rmd-backup-wordpress' ), (string) $values['notify_email'], __( 'Empty = recipient from the settings, otherwise the administration email address.', 'rmd-backup-wordpress' ), 'regular-text', false, (string) get_option( 'admin_email' ) );
		echo '</tbody></table>';

		submit_button( null === $profile ? __( 'Add profile', 'rmd-backup-wordpress' ) : __( 'Save profile', 'rmd-backup-wordpress' ) );
		echo '</form>';
	}

	private static function render_logs(): void {
		$logs = LogStore::logs();
		if ( [] === $logs ) {
			echo '<p>' . esc_html__( 'No logs yet.', 'rmd-backup-wordpress' ) . '</p>';
			return;
		}

		$profiles = Settings::profiles();
		$runs     = [];
		foreach ( $logs as $log ) {
			$runs[] = LogStore::run( $log['id'] ) ?? [
				'id'           => $log['id'],
				'profile'      => $log['profile'],
				'profile_name' => isset( $profiles[ $log['profile'] ] ) ? $profiles[ $log['profile'] ]->name : $log['profile'],
				'trigger'      => '',
				'started'      => $log['created'],
				'finished'     => $log['created'],
				'status'       => '',
				'message'      => '',
				'size'         => 0,
				'warnings'     => 0,
			];
		}
		self::runs_table( $runs, true );
	}

	private static function render_log( string $name ): void {
		printf( '<p><a href="%s">← %s</a></p>', esc_url( self::url( [ 'tab' => 'logs' ] ) ), esc_html__( 'Back to logs', 'rmd-backup-wordpress' ) );
		$path = LogStore::path( $name );
		if ( null === $path ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This log does not exist.', 'rmd-backup-wordpress' ) . '</p></div>';
			return;
		}

		$size    = (int) filesize( $path );
		$offset  = max( 0, $size - self::LOG_VIEW_BYTES );
		$content = (string) file_get_contents( $path, false, null, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		echo '<h2><code>' . esc_html( $name ) . '</code></h2>';
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( self::action_url( 'rmd_bwp_download', [ 'file' => $name ] ) ), esc_html__( 'Download', 'rmd-backup-wordpress' ) );
		if ( $offset > 0 ) {
			echo '<p class="description">' . esc_html__( 'The log is long; only its end is shown. Download it to see everything.', 'rmd-backup-wordpress' ) . '</p>';
		}
		echo '<pre class="rmd-bwp-log">';
		foreach ( preg_split( '/\R/', $content ) ?: [] as $line ) {
			$level = str_contains( $line, '] ERROR ' ) ? 'error' : ( str_contains( $line, '] WARNING ' ) ? 'warning' : '' );
			echo '' === $level ? esc_html( $line ) . "\n" : '<span class="rmd-bwp-log-' . esc_attr( $level ) . '">' . esc_html( $line ) . "</span>\n";
		}
		echo '</pre>';
	}

	private static function render_settings(): void {
		$settings = Settings::get();
		$free     = Storage::free_space();

		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code><p class="description">%s</p></td></tr>',
			esc_html__( 'Backup folder', 'rmd-backup-wordpress' ),
			esc_html( Storage::root() ),
			esc_html__( 'Contains backups/, logs/ and tmp/. To move it, e.g. outside the web root, define RMD_BWP_STORAGE_DIR in wp-config.php.', 'rmd-backup-wordpress' )
		);
		if ( null !== $free ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Free disk space', 'rmd-backup-wordpress' ), esc_html( size_format( $free ) ) );
		}
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'WP-Cron', 'rmd-backup-wordpress' ),
			esc_html(
				defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
					? __( 'Disabled (DISABLE_WP_CRON). Make sure a server cron job calls wp-cron.php regularly, otherwise scheduled backups do not run.', 'rmd-backup-wordpress' )
					: __( 'Active. Scheduled backups start with the first visit after the planned time.', 'rmd-backup-wordpress' )
			)
		);
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rmd_bwp_save_settings">';
		wp_nonce_field( 'rmd_bwp_save_settings' );
		echo '<h2>' . esc_html__( 'Archives', 'rmd-backup-wordpress' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row">%1$s</th><td><input type="hidden" name="settings[filename_site]" value="0"><label><input type="checkbox" name="settings[filename_site]" value="1" %2$s> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'File name', 'rmd-backup-wordpress' ),
			checked( $settings['filename_site'], true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html__( 'Include the domain of the site in file names', 'rmd-backup-wordpress' ),
			esc_html(
				sprintf(
					/* translators: %s: example file name */
					__( 'Example: %s', 'rmd-backup-wordpress' ),
					ArchiveName::id( new DateTimeImmutable( 'now', wp_timezone() ), 'full', 'a1b2c3', $settings['filename_site'] ? ArchiveName::site( home_url() ) : '' ) . '.zip'
				)
			)
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Encryption', 'rmd-backup-wordpress' ) . '</h2>';
		echo '<p>' . esc_html__( 'Backups are encrypted with age for a public key. Only the public key is stored here; the private key stays with you and is needed to restore. Without it, encrypted backups cannot be opened.', 'rmd-backup-wordpress' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( ! Encryptor::available() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The PHP extension sodium is not active on this server, so backups cannot be encrypted. Ask the host to enable it.', 'rmd-backup-wordpress' ) . '</p></div>';
		}
		if ( Settings::recipient_locked() ) {
			printf(
				'<tr><th scope="row">%s</th><td><code class="rmd-bwp-key">%s</code><input type="hidden" name="settings[age_recipient]" value="%s"><p class="description">%s</p></td></tr>',
				esc_html__( 'Public key', 'rmd-backup-wordpress' ),
				esc_html( Settings::recipient() ),
				esc_attr( $settings['age_recipient'] ),
				esc_html__( 'Set by RMD_BWP_AGE_RECIPIENT in wp-config.php.', 'rmd-backup-wordpress' )
			);
		} else {
			self::text_field( 'age_recipient', __( 'Public key', 'rmd-backup-wordpress' ), $settings['age_recipient'], __( 'age public key (age1…), e.g. from "age-keygen -o key.txt". Empty = backups are not encrypted. Uploads to SFTP require encryption. More robust: define RMD_BWP_AGE_RECIPIENT in wp-config.php, then it cannot be changed here.', 'rmd-backup-wordpress' ), 'large-text code', false, 'age1…', '', 'settings' );
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Logs', 'rmd-backup-wordpress' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::number_field( 'log_keep_count', __( 'Keep logs', 'rmd-backup-wordpress' ), $settings['log_keep_count'], 0, 10000, __( 'Number of newest logs to keep. 0 = no limit.', 'rmd-backup-wordpress' ), '', 'settings' );
		self::number_field( 'log_keep_days', __( 'Keep logs for days', 'rmd-backup-wordpress' ), $settings['log_keep_days'], 0, 3650, __( '0 = no limit.', 'rmd-backup-wordpress' ), '', 'settings' );
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Notification', 'rmd-backup-wordpress' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_field( 'notify_email', __( 'Default recipient', 'rmd-backup-wordpress' ), $settings['notify_email'], __( 'Used by profiles without their own recipient. Empty = administration email address.', 'rmd-backup-wordpress' ), 'regular-text', false, (string) get_option( 'admin_email' ), '', 'settings' );
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'rmd-backup-wordpress' ) );
		echo '</form>';

		self::render_sftp_settings();
	}

	private static function render_sftp_settings(): void {
		$sftp = SftpSettings::get();

		echo '<hr><h2 id="sftp">' . esc_html__( 'Upload to SFTP (e.g. HiDrive)', 'rmd-backup-wordpress' ) . '</h2>';
		echo '<p>' . esc_html__( 'Profiles with "Upload to SFTP" copy their encrypted backups to this server, into a folder for this site. Use an account that can only reach its own folder and has no other protocols.', 'rmd-backup-wordpress' ) . '</p>';
		if ( SftpSettings::password_unreadable() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The stored password can no longer be decrypted (the security keys in wp-config.php changed). Enter it again.', 'rmd-backup-wordpress' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="rmd_bwp_save_sftp">';
		wp_nonce_field( 'rmd_bwp_save_sftp' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::sftp_field( 'host', __( 'Server', 'rmd-backup-wordpress' ), $sftp['host'], 'regular-text code', SftpSettings::DEFAULT_HOST );
		self::sftp_field( 'port', __( 'Port', 'rmd-backup-wordpress' ), (string) $sftp['port'], 'small-text', '22' );
		self::sftp_field( 'user', __( 'User name', 'rmd-backup-wordpress' ), $sftp['user'], 'regular-text code', '' );
		if ( SftpSettings::locked( 'auth' ) ) {
			printf( '<tr><th scope="row">%s</th><td><code>%s</code><input type="hidden" id="rmd-bwp-auth" value="%s"></td></tr>', esc_html__( 'Login', 'rmd-backup-wordpress' ), esc_html( $sftp['auth'] ), esc_attr( $sftp['auth'] ) );
		} else {
			printf(
				'<tr><th scope="row"><label for="rmd-bwp-auth">%1$s</label></th><td><select id="rmd-bwp-auth" name="sftp[auth]"><option value="key" %2$s>%3$s</option><option value="password" %4$s>%5$s</option></select></td></tr>',
				esc_html__( 'Login', 'rmd-backup-wordpress' ),
				selected( $sftp['auth'], SftpSettings::AUTH_KEY, false ),
				esc_html__( 'SSH key (recommended)', 'rmd-backup-wordpress' ),
				selected( $sftp['auth'], SftpSettings::AUTH_PASSWORD, false ),
				esc_html__( 'Password', 'rmd-backup-wordpress' )
			);
		}
		self::render_ssh_key_row();
		if ( SftpSettings::locked( 'password' ) ) {
			printf( '<tr class="rmd-bwp-auth-row rmd-bwp-auth-password"><th scope="row">%s</th><td><em>%s</em></td></tr>', esc_html__( 'Password', 'rmd-backup-wordpress' ), esc_html__( 'Set in wp-config.php', 'rmd-backup-wordpress' ) );
		} else {
			printf(
				'<tr class="rmd-bwp-auth-row rmd-bwp-auth-password"><th scope="row"><label for="rmd-bwp-sftp-password">%1$s</label></th><td><input type="password" id="rmd-bwp-sftp-password" name="sftp[password]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"><p class="description">%3$s</p>%4$s</td></tr>',
				esc_html__( 'Password', 'rmd-backup-wordpress' ),
				esc_attr( '' === $sftp['password'] ? '' : __( 'unchanged', 'rmd-backup-wordpress' ) ),
				esc_html__( 'Only for servers without SSH key login. With two-factor authentication on HiDrive the password login does not work. Stored encrypted with the security keys of wp-config.php; empty = keep the stored one.', 'rmd-backup-wordpress' ),
				'' === $sftp['password'] ? '' : '<label><input type="checkbox" name="sftp[forget_password]" value="1"> ' . esc_html__( 'Delete stored password', 'rmd-backup-wordpress' ) . '</label>'
			);
		}
		self::sftp_field( 'path', __( 'Base folder', 'rmd-backup-wordpress' ), $sftp['path'], 'regular-text code', '', __( 'Empty = the own folder of the account (HiDrive: /users/<name>), otherwise the folder after login. Relative paths start at the folder after login.', 'rmd-backup-wordpress' ) );
		self::sftp_field( 'folder', __( 'Folder for this site', 'rmd-backup-wordpress' ), $sftp['folder'], 'regular-text code', ArchiveName::site( home_url() ), __( 'Letters, digits, dots, hyphens. Default: the domain of the site.', 'rmd-backup-wordpress' ) );
		printf(
			'<tr><th scope="row">%s</th><td>%s<p class="description">%s</p></td></tr>',
			esc_html__( 'Host key', 'rmd-backup-wordpress' ),
			'' === $sftp['fingerprint'] ? '<em>' . esc_html__( 'not known yet', 'rmd-backup-wordpress' ) . '</em>' : '<code>' . esc_html( $sftp['fingerprint'] ) . '</code>',
			esc_html__( 'Stored at the first connection test. Every upload checks that the server still presents this key, so a fake server cannot receive the backups.', 'rmd-backup-wordpress' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save SFTP settings', 'rmd-backup-wordpress' ), 'secondary' );
		echo '</form>';

		echo '<p class="rmd-bwp-sftp-actions">';
		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="rmd_bwp_test_sftp">%2$s<button type="submit" class="button">%3$s</button></form> ',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'rmd_bwp_test_sftp', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core nonce markup.
			esc_html__( 'Test connection', 'rmd-backup-wordpress' )
		);
		if ( '' !== $sftp['fingerprint'] && ! SftpSettings::locked( 'fingerprint' ) ) {
			printf(
				'<a class="button button-link-delete rmd-bwp-confirm" href="%s">%s</a>',
				esc_url( self::action_url( 'rmd_bwp_reset_hostkey', [] ) ),
				esc_html__( 'Reset host key', 'rmd-backup-wordpress' )
			);
		}
		echo '</p>';
	}

	/**
	 * Public key of this site with download and (re)generate links.
	 */
	private static function render_ssh_key_row(): void {
		$public = SftpSettings::public_key();
		echo '<tr class="rmd-bwp-auth-row rmd-bwp-auth-key"><th scope="row">' . esc_html__( 'SSH key of this site', 'rmd-backup-wordpress' ) . '</th><td>';

		if ( SftpSettings::private_key_unreadable() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The stored SSH key can no longer be decrypted (the security keys in wp-config.php changed). Create a new key pair and add its public key to the account.', 'rmd-backup-wordpress' ) . '</p></div>';
		}

		if ( '' === $public ) {
			if ( SftpSettings::locked( 'private_key' ) ) {
				echo '<p>' . esc_html__( 'RMD_BWP_SFTP_PRIVATE_KEY in wp-config.php is not a valid private key.', 'rmd-backup-wordpress' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This site has no key pair yet. The private key is created here and never leaves this site; only the public key is added to the account.', 'rmd-backup-wordpress' ) . '</p>';
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( self::action_url( 'rmd_bwp_generate_key', [] ) ), esc_html__( 'Create key pair', 'rmd-backup-wordpress' ) );
			}
			echo '</td></tr>';
			return;
		}

		$fingerprints = SshKey::fingerprints( $public );
		printf( '<textarea class="large-text code rmd-bwp-pubkey" rows="2" readonly>%s</textarea>', esc_textarea( $public ) );
		printf(
			'<p class="description">%s<br><code>%s</code><br><code>MD5:%s</code></p>',
			esc_html__( 'Fingerprints (to compare with the key list of the account):', 'rmd-backup-wordpress' ),
			esc_html( $fingerprints['sha256'] ),
			esc_html( $fingerprints['md5'] )
		);
		printf(
			'<p><a class="button" href="%s">%s</a>',
			esc_url( self::action_url( 'rmd_bwp_download_pubkey', [] ) ),
			esc_html__( 'Download public key', 'rmd-backup-wordpress' )
		);
		if ( ! SftpSettings::locked( 'private_key' ) ) {
			printf(
				' <a class="button rmd-bwp-confirm" data-confirm="%s" href="%s">%s</a>',
				esc_attr__( 'Create a new key pair? The current key stops working for this site. Remove its public key from the account afterwards.', 'rmd-backup-wordpress' ),
				esc_url( self::action_url( 'rmd_bwp_generate_key', [] ) ),
				esc_html__( 'Create new key pair', 'rmd-backup-wordpress' )
			);
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'HiDrive: log in as owner, Settings → Account → choose the backup account → Open-SSH → "SSH Schlüssel hinterlegen", upload the downloaded .pub file. Each site has its own key; to lock out a site, delete only its key there. The key login also works with two-factor authentication.', 'rmd-backup-wordpress' ) . '</p>';
		echo '</td></tr>';
	}

	private static function sftp_field( string $key, string $label, string $value, string $css_class, string $placeholder, string $description = '' ): void {
		if ( SftpSettings::locked( $key ) ) {
			printf(
				'<tr><th scope="row">%s</th><td><code>%s</code><p class="description">%s</p></td></tr>',
				esc_html( $label ),
				esc_html( $value ),
				esc_html__( 'Set in wp-config.php', 'rmd-backup-wordpress' )
			);
			return;
		}
		$stored = get_option( SftpSettings::OPTION, [] );
		$raw    = is_array( $stored ) && isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		self::text_field( $key, $label, $raw, $description, $css_class, false, '' === $placeholder ? $value : $placeholder, '', 'sftp' );
	}

	private static function text_field( string $key, string $label, string $value, string $description, string $css_class, bool $required = false, string $placeholder = '', string $row_class = '', string $group = 'profile' ): void {
		printf(
			'<tr class="%8$s"><th scope="row"><label for="rmd-bwp-%1$s">%2$s</label></th><td><input type="text" id="rmd-bwp-%1$s" name="%9$s[%1$s]" value="%3$s" class="%4$s" placeholder="%5$s" %6$s>%7$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $css_class ),
			esc_attr( $placeholder ),
			$required ? 'required' : '',
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>',
			esc_attr( $row_class ),
			esc_attr( $group )
		);
	}

	private static function number_field( string $key, string $label, int $value, int $min, int $max, string $description, string $row_class = '', string $group = 'profile' ): void {
		printf(
			'<tr class="%7$s"><th scope="row"><label for="rmd-bwp-%1$s">%2$s</label></th><td><input type="number" id="rmd-bwp-%1$s" name="%8$s[%1$s]" value="%3$d" min="%4$d" max="%5$d" class="small-text"><p class="description">%6$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $value,
			(int) $min,
			(int) $max,
			esc_html( $description ),
			esc_attr( $row_class ),
			esc_attr( $group )
		);
	}

	private static function checkbox_field( string $key, string $label, bool $checked, string $description ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><input type="hidden" name="profile[%1$s]" value="0"><label><input type="checkbox" name="profile[%1$s]" value="1" %3$s> %4$s</label></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html( $description )
		);
	}

	/**
	 * @param array<string, string> $options
	 */
	private static function select_field( string $key, string $label, string $value, array $options, string $description, string $row_class = '' ): void {
		printf( '<tr class="%3$s"><th scope="row"><label for="rmd-bwp-%1$s">%2$s</label></th><td><select id="rmd-bwp-%1$s" name="profile[%1$s]">', esc_attr( $key ), esc_html( $label ), esc_attr( $row_class ) );
		foreach ( $options as $option => $option_label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $option_label ) );
		}
		echo '</select>' . ( '' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>' ) . '</td></tr>';
	}

	/**
	 * @param list<string> $lines
	 */
	private static function textarea_field( string $key, string $label, array $lines, string $description, int $rows = 8 ): void {
		printf(
			'<tr><th scope="row"><label for="rmd-bwp-%1$s">%2$s</label></th><td><textarea id="rmd-bwp-%1$s" name="profile[%1$s]" rows="%3$d" class="large-text code">%4$s</textarea><p class="description">%5$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $rows,
			esc_textarea( implode( "\n", $lines ) ),
			esc_html( $description )
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function part_labels(): array {
		return [
			'database' => __( 'Database', 'rmd-backup-wordpress' ),
			'core'     => __( 'WordPress core (wp-admin, wp-includes, files in the main folder)', 'rmd-backup-wordpress' ),
			'config'   => __( 'wp-config.php', 'rmd-backup-wordpress' ),
			'plugins'  => __( 'Plugins', 'rmd-backup-wordpress' ),
			'themes'   => __( 'Themes', 'rmd-backup-wordpress' ),
			'uploads'  => __( 'Uploads (media library)', 'rmd-backup-wordpress' ),
			'content'  => __( 'Rest of wp-content (mu-plugins, languages, …)', 'rmd-backup-wordpress' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function schedule_labels(): array {
		return [
			'manual'  => __( 'Only manually', 'rmd-backup-wordpress' ),
			'hourly'  => __( 'Hourly', 'rmd-backup-wordpress' ),
			'daily'   => __( 'Daily', 'rmd-backup-wordpress' ),
			'weekly'  => __( 'Weekly', 'rmd-backup-wordpress' ),
			'monthly' => __( 'Monthly', 'rmd-backup-wordpress' ),
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function weekday_labels(): array {
		global $wp_locale;
		$labels = [];
		for ( $day = 1; $day <= 7; $day++ ) {
			$labels[ $day ] = $wp_locale->get_weekday( $day % 7 );
		}
		return $labels;
	}

	/**
	 * @return array<string, string>
	 */
	private static function notify_labels(): array {
		return [
			'off'      => __( 'Never', 'rmd-backup-wordpress' ),
			'problems' => __( 'Only after warnings or errors', 'rmd-backup-wordpress' ),
			'always'   => __( 'After every run', 'rmd-backup-wordpress' ),
		];
	}

	private static function schedule_label( Profile $profile ): string {
		$weekday = self::weekday_labels()[ $profile->weekday ] ?? '';
		return match ( $profile->schedule ) {
			/* translators: %s: minute */
			'hourly'  => sprintf( __( 'Hourly at minute %s', 'rmd-backup-wordpress' ), substr( $profile->time, 3 ) ),
			/* translators: %s: time */
			'daily'   => sprintf( __( 'Daily at %s', 'rmd-backup-wordpress' ), $profile->time ),
			/* translators: 1: day of the week, 2: time */
			'weekly'  => sprintf( __( 'Weekly on %1$s at %2$s', 'rmd-backup-wordpress' ), $weekday, $profile->time ),
			/* translators: 1: day of the month, 2: time */
			'monthly' => sprintf( __( 'Monthly on day %1$d at %2$s', 'rmd-backup-wordpress' ), $profile->monthday, $profile->time ),
			default   => __( 'Only manually', 'rmd-backup-wordpress' ),
		};
	}

	private static function keep_label( Profile $profile ): string {
		$parts = [];
		if ( $profile->keep_count > 0 ) {
			/* translators: %d: number of backups */
			$parts[] = sprintf( _n( 'newest %d', 'newest %d', $profile->keep_count, 'rmd-backup-wordpress' ), $profile->keep_count );
		}
		if ( $profile->keep_days > 0 ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( _n( '%d day', '%d days', $profile->keep_days, 'rmd-backup-wordpress' ), $profile->keep_days );
		}
		return [] === $parts ? __( 'all', 'rmd-backup-wordpress' ) : implode( ', ', $parts );
	}

	private static function trigger_label( string $trigger ): string {
		return match ( $trigger ) {
			'manual'   => __( 'Manual', 'rmd-backup-wordpress' ),
			'schedule' => __( 'Schedule', 'rmd-backup-wordpress' ),
			'cli'      => __( 'WP-CLI', 'rmd-backup-wordpress' ),
			default    => '–',
		};
	}

	private static function date( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private static function error_message( string $code ): string {
		$messages = [
			'name_required'     => __( 'Enter a name.', 'rmd-backup-wordpress' ),
			'parts_required'    => __( 'Select at least one part to back up.', 'rmd-backup-wordpress' ),
			'invalid_time'      => __( 'Enter the time as HH:MM, e.g. 03:00.', 'rmd-backup-wordpress' ),
			'invalid_email'     => __( 'The email address is not valid.', 'rmd-backup-wordpress' ),
			'invalid_recipient' => __( 'The public key is not a valid age key (age1…).', 'rmd-backup-wordpress' ),
			'pq_recipient'      => __( 'Post-quantum keys (age1pq…) are not supported yet. Create the key with age-keygen without -pq.', 'rmd-backup-wordpress' ),
			'invalid_host'      => __( 'The server name is not valid.', 'rmd-backup-wordpress' ),
			'invalid_port'      => __( 'The port must be a number between 1 and 65535.', 'rmd-backup-wordpress' ),
			'invalid_user'      => __( 'The user name contains invalid characters.', 'rmd-backup-wordpress' ),
			'invalid_path'      => __( 'The base folder must not contain "..".', 'rmd-backup-wordpress' ),
			'invalid_folder'    => __( 'The folder name may only contain letters, digits, dots, hyphens and underscores.', 'rmd-backup-wordpress' ),
		];
		return $messages[ $code ] ?? $code;
	}

	private static function render_notices(): void {
		$notice = isset( $_GET['rmd_bwp_notice'] ) ? sanitize_key( wp_unslash( $_GET['rmd_bwp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = get_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
		$success = get_transient( self::ERRORS_TRANSIENT . 'success_' . get_current_user_id() );
		if ( is_string( $success ) && '' !== $success ) {
			delete_transient( self::ERRORS_TRANSIENT . 'success_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success ) . '</p></div>';
		}

		$messages = [
			'sftp_saved'      => __( 'SFTP settings saved. Run "Test connection" to check them.', 'rmd-backup-wordpress' ),
			'hostkey_reset'   => __( 'Host key reset. The next connection test stores the key the server presents.', 'rmd-backup-wordpress' ),
			'key_generated'   => __( 'Key pair created. Download the public key, add it to the SFTP account and run "Test connection".', 'rmd-backup-wordpress' ),
			'profile_saved'   => __( 'Profile saved.', 'rmd-backup-wordpress' ),
			'profile_copied'  => __( 'Profile duplicated.', 'rmd-backup-wordpress' ),
			'profile_deleted' => __( 'Profile deleted.', 'rmd-backup-wordpress' ),
			'backup_deleted'  => __( 'Backup deleted.', 'rmd-backup-wordpress' ),
			'log_deleted'     => __( 'Log deleted.', 'rmd-backup-wordpress' ),
			'settings_saved'  => __( 'Settings saved.', 'rmd-backup-wordpress' ),
		];
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	public static function save_profile(): void {
		self::guard( 'rmd_bwp_save_profile' );

		$raw    = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Normalized by Profile::parse(), nonce checked in guard().
		$id     = sanitize_key( (string) ( $raw['id'] ?? '' ) );
		$is_new = '' === $id || null === Settings::profile( $id );
		$result = Profile::parse( [ 'id' => $is_new ? '' : $id ] + $raw );

		if ( [] !== $result['errors'] ) {
			set_transient(
				self::ERRORS_TRANSIENT . get_current_user_id(),
				[
					'id'     => $is_new ? '' : $id,
					'errors' => $result['errors'],
					'values' => array_intersect_key( $raw, Profile::defaults() ),
				],
				5 * MINUTE_IN_SECONDS
			);
			self::redirect(
				self::url(
					array_filter(
						[
							'tab'     => 'profiles',
							'action'  => 'edit',
							'profile' => $is_new ? '' : $id,
						]
					)
				)
			);
		}

		Runner::sync( Settings::save_profile( $result['profile'], $is_new ) );
		self::redirect(
			self::url(
				[
					'tab'            => 'profiles',
					'rmd_bwp_notice' => 'profile_saved',
				]
			)
		);
	}

	public static function copy_profile(): void {
		$id = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'rmd_bwp_copy_profile' );
		$profile = Settings::profile( $id );
		if ( null !== $profile ) {
			$copy = Profile::parse(
				[
					'id'       => '',
					/* translators: %s: profile name */
					'name'     => sprintf( __( '%s (copy)', 'rmd-backup-wordpress' ), $profile->name ),
					'schedule' => 'manual',
				] + $profile->to_array()
			)['profile'];
			Settings::save_profile( $copy, true );
		}
		self::redirect(
			self::url(
				[
					'tab'            => 'profiles',
					'rmd_bwp_notice' => 'profile_copied',
				]
			)
		);
	}

	public static function delete_profile(): void {
		$id = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		self::guard( 'rmd_bwp_delete_profile' );
		Settings::delete_profile( $id );
		Runner::unschedule( $id );
		self::redirect(
			self::url(
				[
					'tab'            => 'profiles',
					'rmd_bwp_notice' => 'profile_deleted',
				]
			)
		);
	}

	public static function download(): void {
		$file = self::file_param();
		self::guard( 'rmd_bwp_download' );
		if ( str_ends_with( $file, '.log' ) ) {
			$path = LogStore::path( $file );
			$type = 'text/plain; charset=utf-8';
		} else {
			$path = Destinations::local()->path( $file );
			$type = str_ends_with( $file, '.age' ) ? 'application/octet-stream' : 'application/zip';
		}
		if ( null === $path ) {
			wp_die( esc_html__( 'This file does not exist.', 'rmd-backup-wordpress' ), 404 );
		}
		Storage::send( $path, $type );
	}

	public static function delete_backup(): void {
		$file = self::file_param();
		self::guard( 'rmd_bwp_delete_backup' );
		try {
			Destinations::local()->delete( $file );
		} catch ( Throwable $e ) {
			self::fail( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		self::redirect( self::url( [ 'rmd_bwp_notice' => 'backup_deleted' ] ) );
	}

	public static function delete_log(): void {
		$file = self::file_param();
		self::guard( 'rmd_bwp_delete_log' );
		try {
			LogStore::delete( $file );
		} catch ( Throwable $e ) {
			self::fail( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}
		self::redirect(
			self::url(
				[
					'tab'            => 'logs',
					'rmd_bwp_notice' => 'log_deleted',
				]
			)
		);
	}

	public static function save_settings(): void {
		self::guard( 'rmd_bwp_save_settings' );
		$raw    = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Normalized by Settings::parse(), nonce checked in guard().
		$errors = Settings::save( $raw );
		if ( [] !== $errors ) {
			self::fail( self::error_message( $errors[0] ), 'settings' );
		}
		self::redirect(
			self::url(
				[
					'tab'            => 'settings',
					'rmd_bwp_notice' => 'settings_saved',
				]
			)
		);
	}

	public static function save_sftp(): void {
		self::guard( 'rmd_bwp_save_sftp' );
		$raw    = isset( $_POST['sftp'] ) && is_array( $_POST['sftp'] ) ? wp_unslash( $_POST['sftp'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Validated by SftpSettings::save(), nonce checked in guard().
		$errors = SftpSettings::save( $raw );
		if ( [] !== $errors ) {
			self::fail( self::error_message( $errors[0] ), 'settings' );
		}
		self::redirect(
			self::url(
				[
					'tab'            => 'settings',
					'rmd_bwp_notice' => 'sftp_saved',
				]
			) . '#sftp'
		);
	}

	/**
	 * Connects, checks write access and stores the host key if none is pinned yet.
	 */
	public static function test_sftp(): void {
		self::guard( 'rmd_bwp_test_sftp' );
		$settings = SftpSettings::get();
		try {
			$result = ( new SftpDestination( $settings ) )->test();
		} catch ( Throwable $e ) {
			self::fail( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), 'settings' );
		}

		if ( '' === $settings['fingerprint'] ) {
			SftpSettings::save_fingerprint( $result['fingerprint'] );
		}
		set_transient(
			self::ERRORS_TRANSIENT . 'success_' . get_current_user_id(),
			sprintf(
				/* translators: 1: folder, 2: number of backups, 3: host key fingerprint */
				__( 'Connection works. Folder %1$s is writable and contains %2$d backups of this site. Host key: %3$s', 'rmd-backup-wordpress' ),
				$result['directory'],
				$result['backups'],
				$result['fingerprint']
			),
			MINUTE_IN_SECONDS
		);
		self::redirect( self::url( [ 'tab' => 'settings' ] ) . '#sftp' );
	}

	public static function generate_key(): void {
		self::guard( 'rmd_bwp_generate_key' );
		if ( SftpSettings::locked( 'private_key' ) ) {
			self::fail( __( 'The SSH key is set in wp-config.php.', 'rmd-backup-wordpress' ), 'settings' );
		}
		try {
			SftpSettings::generate_key();
		} catch ( Throwable $e ) {
			self::fail( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), 'settings' );
		}
		self::redirect(
			self::url(
				[
					'tab'            => 'settings',
					'rmd_bwp_notice' => 'key_generated',
				]
			) . '#sftp'
		);
	}

	public static function download_pubkey(): void {
		self::guard( 'rmd_bwp_download_pubkey' );
		$public = SftpSettings::public_key();
		if ( '' === $public ) {
			wp_die( esc_html__( 'This site has no SSH key yet.', 'rmd-backup-wordpress' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rmd-backup_' . ArchiveName::site( home_url() ) . '.pub"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $public . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text file download.
		exit;
	}

	public static function reset_hostkey(): void {
		self::guard( 'rmd_bwp_reset_hostkey' );
		SftpSettings::save_fingerprint( '' );
		self::redirect(
			self::url(
				[
					'tab'            => 'settings',
					'rmd_bwp_notice' => 'hostkey_reset',
				]
			) . '#sftp'
		);
	}

	/**
	 * Link to an admin-post action with a nonce.
	 *
	 * @param array<string, string> $args
	 */
	private static function action_url( string $action, array $args ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => $action ] + $args, admin_url( 'admin-post.php' ) ), $action );
	}

	private static function file_param(): string {
		return isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard(); the name is validated by ArchiveName.
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rmd-backup-wordpress' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function fail( string $message, string $tab = '' ): never {
		set_transient( self::ERRORS_TRANSIENT . 'message_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		self::redirect( self::url( '' === $tab ? [] : [ 'tab' => $tab ] ) );
	}

	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
