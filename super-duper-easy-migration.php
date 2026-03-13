<?php
/**
 * Plugin Name:       Super Duper Easy Migration
 * Plugin URI:        https://github.com/torsteino/super-duper-easy-migration
 * Description:       Migrate a WordPress site to another server via SSH and rsync — directly from the admin panel.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Torstein Opperud / Digilove
 * Author URI:        https://digilove.no/plugins/super-duper-easy-migration/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       super-duper-easy-migration
 * Domain Path:       /languages
 * Update URI:        https://github.com/torstein-digilove/super-duper-easy-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ====================================================================
 *  Automatic updates via GitHub (plugin-update-checker)
 *  Repository: https://github.com/torstein-digilove/super-duper-easy-migration
 * ==================================================================== */
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$sdem_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/torstein-digilove/super-duper-easy-migration/',
	__FILE__,
	'super-duper-easy-migration'
);
$sdem_update_checker->setBranch( 'main' );

define( 'SDEM_VERSION',    '1.0.0' );
define( 'SDEM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDEM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SDEM_LOG_DIR',    WP_CONTENT_DIR . '/sdem-logs/' );

define( 'SDEM_RSYNC_EXCLUDES', [
	'wp-content/cache/',
	'wp-content/w3tc-config/',
	'wp-content/litespeed/',
	'wp-content/et-cache/',
	'wp-content/uploads/cache/',
	'wp-content/advanced-cache.php',
	'wp-content/object-cache.php',
	'wp-content/wp-cache-config.php',
	'wp-content/updraft/',
	'wp-content/updraftplus/',
	'wp-content/infinitewp/',
	'wp-content/ai1wm-backups/',
	'wp-content/backups/',
	'wp-content/backup/',
	'wp-content/backupbuddy_backups/',
	'wp-content/pb_backupbuddy/',
	'wp-content/wpvivid_backup/',
	'wp-content/uploads/wpvivid/',
	'wp-content/uploads/backups/',
	'wp-content/uploads/backup/',
	'wp-content/Jeremys-Jeremys-BackupWordPress/',
	'wp-content/backupwordpress-*/',
	'wp-content/duplicator/',
	'wp-content/wflogs/',
	'wp-content/debug.log',
	'wp-content/uploads/wc-logs/',
	'wp-content/uploads/sucuri/',
	'wp-content/upgrade/',
	'wp-content/sdem-logs/',
	'wp-content/uploads/wpo-plugins-tables-list.json',
] );


/* ====================================================================
 *  Step definitions – labels are translated at runtime via get_steps()
 * ==================================================================== */

define( 'SDEM_STEP_KEYS', [
	'preflight',
	'db_dump',
	'rsync',
	'htaccess_cleanup',
	'wp_config',
	'db_import',
	'search_replace',
	'cleanup',
] );

// Step weights (must sum to 100)
define( 'SDEM_STEP_WEIGHTS', [
	'preflight'        => 5,
	'db_dump'          => 15,
	'rsync'            => 50,
	'htaccess_cleanup' => 2,
	'wp_config'        => 5,
	'db_import'        => 10,
	'search_replace'   => 10,
	'cleanup'          => 3,
] );


/* ====================================================================
 *  Plugin class
 * ==================================================================== */

class Super_Duper_Easy_Migration {

	public static function instance(): self {
		static $inst = null;
		if ( $inst === null ) {
			$inst = new self();
		}
		return $inst;
	}

	private function __construct() {
		add_action( 'plugins_loaded',   [ $this, 'load_textdomain' ] );
		add_action( 'admin_init',       [ $this, 'check_requirements' ] );
		add_action( 'admin_menu',       [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_sdem_check_sshpass',    [ $this, 'ajax_check_sshpass' ] );
		add_action( 'wp_ajax_sdem_test_connection',  [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_sdem_start_migration',  [ $this, 'ajax_start_migration' ] );
		add_action( 'wp_ajax_sdem_check_progress',   [ $this, 'ajax_check_progress' ] );
		add_action( 'wp_ajax_sdem_cancel_migration', [ $this, 'ajax_cancel_migration' ] );
		add_action( 'wp_ajax_sdem_reset_migration',  [ $this, 'ajax_reset_migration' ] );

		if ( ! file_exists( SDEM_LOG_DIR ) ) {
			wp_mkdir_p( SDEM_LOG_DIR );
			file_put_contents( SDEM_LOG_DIR . '.htaccess', "Order deny,allow\nDeny from all" );
			file_put_contents( SDEM_LOG_DIR . 'index.php', '<?php // Silence is golden' );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'super-duper-easy-migration',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	public function check_requirements(): void {
		if ( ! self::can_exec() ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>';
				printf(
					/* translators: 1: plugin name, 2: exec() function name */
					esc_html__( '%1$s requires the %2$s PHP function to be enabled. Please contact your hosting provider.', 'super-duper-easy-migration' ),
					'<strong>Super Duper Easy Migration</strong>',
					'<code>exec()</code>'
				);
				echo '</p></div>';
			} );
		}
	}

	public static function can_exec(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'Super Duper Easy Migration', 'super-duper-easy-migration' ),
			__( 'Migrate Site', 'super-duper-easy-migration' ),
			'manage_options',
			'super-duper-easy-migration',
			[ $this, 'render_admin_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_super-duper-easy-migration' !== $hook ) {
			return;
		}
		$css_ver = filemtime( SDEM_PLUGIN_DIR . 'assets/css/admin.css' );
		$js_ver  = filemtime( SDEM_PLUGIN_DIR . 'assets/js/admin.js' );
		wp_enqueue_style(  'sdem-admin', SDEM_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );
		wp_enqueue_script( 'sdem-admin', SDEM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], $js_ver, true );

		// Steps for JS (translated labels)
		$steps_for_js = [];
		foreach ( $this->get_steps() as $key => $label ) {
			$steps_for_js[ $key ] = $label;
		}

		wp_localize_script( 'sdem-admin', 'sdem_data', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'sdem_nonce' ),
			'active_job' => get_transient( 'sdem_active_job' ) ?: '',
			'steps'      => $steps_for_js,
			'i18n'       => [
				'testing'             => __( 'Testing…', 'super-duper-easy-migration' ),
				'test_connection'     => __( 'Test connection', 'super-duper-easy-migration' ),
				'starting'            => __( 'Starting…', 'super-duper-easy-migration' ),
				'start_migration'     => __( 'Start migration', 'super-duper-easy-migration' ),
				'cancelling'          => __( 'Cancelling…', 'super-duper-easy-migration' ),
				'cancel'              => __( 'Cancel', 'super-duper-easy-migration' ),
				'network_error'       => __( 'Network error — please try again.', 'super-duper-easy-migration' ),
				'confirm_start'       => __( 'Are you sure you want to start the migration? This will overwrite the target site.', 'super-duper-easy-migration' ),
				'confirm_cancel'      => __( 'Are you sure you want to cancel the running migration?', 'super-duper-easy-migration' ),
				'elapsed'             => __( 'Elapsed:', 'super-duper-easy-migration' ),
				'completed'           => __( 'complete', 'super-duper-easy-migration' ),
				'error_prefix'        => __( 'Error:', 'super-duper-easy-migration' ),
				'fill_db_name'        => __( 'Please fill in DB name and DB user under "Database info (manual)".', 'super-duper-easy-migration' ),
				'fill_target_url'     => __( 'Please fill in the target URL under "Database info (manual)".', 'super-duper-easy-migration' ),
				'status_pending'      => __( 'Pending', 'super-duper-easy-migration' ),
				'status_running'      => __( 'Running…', 'super-duper-easy-migration' ),
				'status_completed'    => __( 'Completed', 'super-duper-easy-migration' ),
				'status_skipped'      => __( 'Skipped', 'super-duper-easy-migration' ),
				'status_failed'       => __( 'Failed', 'super-duper-easy-migration' ),
				'migration_complete'  => __( 'Migration complete!', 'super-duper-easy-migration' ),
				'migration_failed'    => __( 'Migration failed', 'super-duper-easy-migration' ),
				'migration_running'   => __( 'Migration in progress', 'super-duper-easy-migration' ),
				'back'                => __( 'Back', 'super-duper-easy-migration' ),
				'back_to_form'        => __( 'Back to form', 'super-duper-easy-migration' ),
				'new_migration'       => __( 'New migration', 'super-duper-easy-migration' ),
				'checking'            => __( 'Checking…', 'super-duper-easy-migration' ),
				'open_site'           => __( 'Open new site ↗', 'super-duper-easy-migration' ),
				'wp_admin'            => __( 'WP Admin ↗', 'super-duper-easy-migration' ),
				'unknown_error'       => __( 'Unknown error.', 'super-duper-easy-migration' ),
				'select_folder'       => __( 'Select folder', 'super-duper-easy-migration' ),
				'no_folders_found'    => __( 'No standard web folders found. Enter a path manually.', 'super-duper-easy-migration' ),
				'wp_found'            => __( 'WordPress found', 'super-duper-easy-migration' ),
				'enter_path'          => __( 'Enter path manually', 'super-duper-easy-migration' ),
				'fill_target_path'    => __( 'Please select or enter a target folder.', 'super-duper-easy-migration' ),
				'invalid_target_path' => __( 'Invalid target path.', 'super-duper-easy-migration' ),
				'hour_abbr'           => _x( 'h', 'hour abbreviation', 'super-duper-easy-migration' ),
				'min_abbr'            => _x( 'm', 'minute abbreviation', 'super-duper-easy-migration' ),
				'sec_abbr'            => _x( 's', 'second abbreviation', 'super-duper-easy-migration' ),
			],
		] );
	}

	/**
	 * Returns translated step labels keyed by step ID.
	 */
	private function get_steps(): array {
		return [
			'preflight'        => __( 'Pre-flight check',   'super-duper-easy-migration' ),
			'db_dump'          => __( 'Database dump',      'super-duper-easy-migration' ),
			'rsync'            => __( 'File transfer',      'super-duper-easy-migration' ),
			'htaccess_cleanup' => __( '.htaccess cleanup',  'super-duper-easy-migration' ),
			'wp_config'        => __( 'wp-config',          'super-duper-easy-migration' ),
			'db_import'        => __( 'Database import',    'super-duper-easy-migration' ),
			'search_replace'   => __( 'Search & replace',   'super-duper-easy-migration' ),
			'cleanup'          => __( 'Cleanup',            'super-duper-easy-migration' ),
		];
	}


	/* ================================================================== */
	/*  Admin page                                                          */
	/* ================================================================== */

	public function render_admin_page(): void {
		$active_job  = get_transient( 'sdem_active_job' );
		$has_active  = ! empty( $active_job );
		$source_info = $this->get_source_info();
		?>
		<div class="wrap wsm-wrap">
			<h1><?php esc_html_e( 'Super Duper Easy Migration', 'super-duper-easy-migration' ); ?></h1>
			<p class="wsm-subtitle">
				<?php esc_html_e( 'Migrate this WordPress site to another server via SSH and rsync.', 'super-duper-easy-migration' ); ?>
			</p>

			<!-- ========== FORM ========== -->
			<div id="wsm-form-section" <?php echo $has_active ? 'style="display:none"' : ''; ?>>

				<div class="wsm-card">
					<h2><?php esc_html_e( 'This site (source)', 'super-duper-easy-migration' ); ?></h2>
					<table class="wsm-info-table">
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'Path:', 'super-duper-easy-migration' ); ?></td>
							<td><code><?php echo esc_html( $source_info['source_path'] ); ?></code></td>
						</tr>
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'URL:', 'super-duper-easy-migration' ); ?></td>
							<td><code><?php echo esc_html( $source_info['site_url'] ); ?></code></td>
						</tr>
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'Database:', 'super-duper-easy-migration' ); ?></td>
							<td><code><?php echo esc_html( $source_info['db_name'] ); ?></code></td>
						</tr>
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'Table prefix:', 'super-duper-easy-migration' ); ?></td>
							<td><code><?php echo esc_html( $source_info['table_prefix'] ); ?></code></td>
						</tr>
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'Size:', 'super-duper-easy-migration' ); ?></td>
							<td>~<?php echo esc_html( $source_info['size'] ); ?></td>
						</tr>
						<tr>
							<td class="wsm-info-label"><?php esc_html_e( 'SSH tools:', 'super-duper-easy-migration' ); ?></td>
							<td><span id="wsm-sshpass-status"><?php esc_html_e( 'Checking…', 'super-duper-easy-migration' ); ?></span></td>
						</tr>
					</table>
				</div>

				<div class="wsm-card">
					<h2><?php esc_html_e( 'Target server', 'super-duper-easy-migration' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="wsm-host"><?php esc_html_e( 'IP / hostname', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<input type="text" id="wsm-host" class="regular-text"
									   placeholder="<?php esc_attr_e( 'e.g. 192.168.1.100 or server.example.com', 'super-duper-easy-migration' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-user"><?php esc_html_e( 'SSH username', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<input type="text" id="wsm-user" class="regular-text"
									   placeholder="<?php esc_attr_e( 'username on target server', 'super-duper-easy-migration' ); ?>">
								<p class="description">
									<?php
									printf(
										/* translators: %s is replaced with the username placeholder in the path */
										esc_html__( 'Home directory: %s', 'super-duper-easy-migration' ),
										'<code>/home/<strong>&lt;username&gt;</strong>/</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-pass"><?php esc_html_e( 'SSH password', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<div class="wsm-inline-row">
									<input type="password" id="wsm-pass" class="regular-text"
										   placeholder="<?php esc_attr_e( 'password', 'super-duper-easy-migration' ); ?>">
									<button type="button" id="wsm-test-conn" class="button">
										<?php esc_html_e( 'Test connection', 'super-duper-easy-migration' ); ?>
									</button>
								</div>
								<div id="wsm-conn-status"></div>
							</td>
						</tr>
					</table>
				</div>

				<!-- Target folder picker (shown after successful connection test) -->
				<div id="wsm-path-card" class="wsm-card" style="display:none">
					<h2><?php esc_html_e( 'Target folder', 'super-duper-easy-migration' ); ?></h2>
					<p class="description" style="margin-bottom:12px">
						<?php esc_html_e( 'Select the folder on the target server where WordPress should be installed.', 'super-duper-easy-migration' ); ?>
					</p>
					<div id="wsm-path-options"></div>
					<div id="wsm-custom-path-row" style="display:none;margin-top:10px">
						<label for="wsm-custom-path" style="display:block;margin-bottom:4px">
							<strong><?php esc_html_e( 'Path:', 'super-duper-easy-migration' ); ?></strong>
						</label>
						<input type="text" id="wsm-custom-path" class="regular-text"
							   placeholder="/home/username/public_html">
						<p class="description"><?php esc_html_e( 'The folder will be created if it does not exist.', 'super-duper-easy-migration' ); ?></p>
					</div>
				</div>

				<!-- Manual DB card (shown when wp-config.php is missing on target) -->
				<div id="wsm-manual-db-card" class="wsm-card" style="display:none">
					<h2><?php esc_html_e( 'Database info (manual)', 'super-duper-easy-migration' ); ?></h2>
					<p class="description" style="margin-bottom:12px">
						⚠ <strong><?php esc_html_e( 'wp-config.php was not found on the target server.', 'super-duper-easy-migration' ); ?></strong>
						<?php esc_html_e( 'Please fill in the database details manually. You can find these in the control panel (e.g. RunCloud, cPanel or Plesk) for the target server account.', 'super-duper-easy-migration' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="wsm-manual-db-name"><?php esc_html_e( 'DB name', 'super-duper-easy-migration' ); ?></label></th>
							<td><input type="text" id="wsm-manual-db-name" class="regular-text" placeholder="<?php esc_attr_e( 'database name', 'super-duper-easy-migration' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-manual-db-user"><?php esc_html_e( 'DB user', 'super-duper-easy-migration' ); ?></label></th>
							<td><input type="text" id="wsm-manual-db-user" class="regular-text" placeholder="<?php esc_attr_e( 'database user', 'super-duper-easy-migration' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-manual-db-pass"><?php esc_html_e( 'DB password', 'super-duper-easy-migration' ); ?></label></th>
							<td><input type="password" id="wsm-manual-db-pass" class="regular-text" placeholder="<?php esc_attr_e( 'password', 'super-duper-easy-migration' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-manual-db-host"><?php esc_html_e( 'DB host', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<input type="text" id="wsm-manual-db-host" class="regular-text" value="localhost">
								<p class="description"><?php esc_html_e( 'Usually localhost or 127.0.0.1', 'super-duper-easy-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-manual-db-prefix"><?php esc_html_e( 'Table prefix', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<input type="text" id="wsm-manual-db-prefix" class="small-text" value="wp_">
								<p class="description"><?php esc_html_e( 'Usually wp_', 'super-duper-easy-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wsm-manual-target-url"><?php esc_html_e( 'Target URL', 'super-duper-easy-migration' ); ?></label></th>
							<td>
								<input type="url" id="wsm-manual-target-url" class="regular-text" placeholder="https://example.com">
								<p class="description"><?php esc_html_e( 'The URL of the site on the target server', 'super-duper-easy-migration' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wsm-actions">
					<button type="button" id="wsm-start"
							class="button button-primary button-hero" disabled>
						<?php esc_html_e( 'Start migration', 'super-duper-easy-migration' ); ?>
					</button>
				</div>
			</div>

			<!-- ========== PROGRESS ========== -->
			<div id="wsm-progress-section" <?php echo $has_active ? '' : 'style="display:none"'; ?>>
				<div class="wsm-card">
					<div class="wsm-progress-header">
						<h2><?php esc_html_e( 'Migration in progress', 'super-duper-easy-migration' ); ?></h2>
						<button type="button" id="wsm-cancel" class="button button-link-delete">
							<?php esc_html_e( 'Cancel', 'super-duper-easy-migration' ); ?>
						</button>
					</div>
					<div id="wsm-overall-progress">
						<div class="wsm-progress-bar">
							<div class="wsm-progress-fill" id="wsm-overall-fill"></div>
						</div>
						<div class="wsm-progress-meta">
							<span id="wsm-overall-text"><?php esc_html_e( 'Starting…', 'super-duper-easy-migration' ); ?></span>
							<span id="wsm-overall-time"></span>
						</div>
					</div>
					<div id="wsm-steps-display"></div>
				</div>

				<div class="wsm-card">
					<h3><?php esc_html_e( 'Log', 'super-duper-easy-migration' ); ?></h3>
					<div id="wsm-log"></div>
				</div>
			</div>

			<!-- ========== COMPLETE ========== -->
			<div id="wsm-completion" style="display:none">
				<div class="wsm-card wsm-status-card wsm-status-success">
					<div class="wsm-status-icon">✓</div>
					<h2><?php esc_html_e( 'Migration complete!', 'super-duper-easy-migration' ); ?></h2>
					<div id="wsm-completion-summary"></div>
					<button type="button" id="wsm-new-migration" class="button button-primary">
						<?php esc_html_e( 'New migration', 'super-duper-easy-migration' ); ?>
					</button>
				</div>
			</div>

			<!-- ========== ERROR ========== -->
			<div id="wsm-error" style="display:none">
				<div class="wsm-card wsm-status-card wsm-status-error">
					<div class="wsm-status-icon">✗</div>
					<h2><?php esc_html_e( 'Migration failed', 'super-duper-easy-migration' ); ?></h2>
					<div id="wsm-error-message"></div>
					<button type="button" id="wsm-error-reset" class="button">
						<?php esc_html_e( 'Back to form', 'super-duper-easy-migration' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}


	/* ================================================================== */
	/*  Source info                                                         */
	/* ================================================================== */

	private function get_source_info(): array {
		global $table_prefix;

		$source_path = rtrim( ABSPATH, '/' ) . '/';
		$db_name     = DB_NAME;
		$db_user     = DB_USER;
		$db_pass     = DB_PASSWORD;
		$db_host     = DB_HOST;
		$site_url    = get_option( 'siteurl' );
		$home_url    = get_option( 'home' );

		// Estimate directory size
		$size_output = [];
		exec( 'timeout 15 du -sh ' . escapeshellarg( $source_path ) . ' 2>/dev/null', $size_output );
		$size = isset( $size_output[0] ) ? explode( "\t", $size_output[0] )[0] : '?';

		return [
			'source_path'  => $source_path,
			'db_name'      => $db_name,
			'db_user'      => $db_user,
			'db_pass'      => $db_pass,
			'db_host'      => $db_host,
			'site_url'     => $site_url,
			'home_url'     => $home_url,
			'table_prefix' => $table_prefix,
			'size'         => $size,
		];
	}




	/* ================================================================== */
	/*  AJAX: Check sshpass                                                 */
	/* ================================================================== */

	public function ajax_check_sshpass(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		$result = [
			'sshpass'  => false,
			'ssh_keys' => false,
			'message'  => '',
		];

		exec( 'which sshpass 2>&1', $sshpass_out, $sshpass_code );
		$result['sshpass'] = ( $sshpass_code === 0 );

		$home          = getenv( 'HOME' ) ?: '/tmp';
		$ssh_key_paths = [
			$home . '/.ssh/id_rsa',
			$home . '/.ssh/id_ed25519',
			$home . '/.ssh/id_ecdsa',
		];
		foreach ( $ssh_key_paths as $key_path ) {
			if ( file_exists( $key_path ) ) {
				$result['ssh_keys']    = true;
				$result['ssh_key_path'] = $key_path;
				break;
			}
		}

		if ( $result['sshpass'] ) {
			$result['message'] = __( '✓ sshpass is available', 'super-duper-easy-migration' );
			$result['status']  = 'ok';
		} elseif ( $result['ssh_keys'] ) {
			$result['message'] = __( '⚠ sshpass not found, but an SSH key was found. You may use key-based authentication.', 'super-duper-easy-migration' );
			$result['status']  = 'warn';
		} else {
			$result['message'] = __( '✗ sshpass is not installed. Install it with: sudo apt install sshpass', 'super-duper-easy-migration' );
			$result['status']  = 'error';
		}

		wp_send_json_success( $result );
	}


	/* ================================================================== */
	/*  AJAX: Test connection                                               */
	/* ================================================================== */

	public function ajax_test_connection(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		$host = sanitize_text_field( $_POST['host'] ?? '' );
		$user = sanitize_text_field( $_POST['user'] ?? '' );
		$pass = $_POST['pass'] ?? '';

		if ( empty( $host ) || empty( $user ) || empty( $pass ) ) {
			wp_send_json_error( __( 'IP/hostname, username and password are required.', 'super-duper-easy-migration' ) );
		}

		exec( 'which sshpass 2>&1', $chk, $chk_code );
		if ( $chk_code !== 0 ) {
			wp_send_json_error( __( 'sshpass is not installed on this server.', 'super-duper-easy-migration' ) );
		}

		$ssh_base = sprintf(
			'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s',
			escapeshellarg( $pass ),
			escapeshellarg( $user ),
			escapeshellarg( $host )
		);

		// Basic connectivity check
		$out = [];
		exec( $ssh_base . ' "echo SDEM_OK" 2>&1', $out, $rc );
		$result = trim( implode( "\n", $out ) );

		if ( $rc !== 0 || strpos( $result, 'SDEM_OK' ) === false ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error output from SSH */
					__( 'SSH connection failed: %s', 'super-duper-easy-migration' ),
					$result
				)
			);
		}

		// Check WP-CLI
		$wpcli_out = [];
		exec( $ssh_base . ' "wp --version 2>&1" 2>&1', $wpcli_out );
		$has_wpcli = strpos( implode( '', $wpcli_out ), 'WP-CLI' ) !== false;

		// Scan for common web roots on target
		$scan_cmd = <<<'BASH'
sh -c '
h=$HOME
out=""
for d in public_html www html htdocs web; do
  if [ -d "$h/$d" ]; then
	wp=$([ -f "$h/$d/wp-config.php" ] && echo 1 || echo 0)
	out="${out}PATH:$h/$d:$wp\n"
  fi
done
if [ -d "$h/webapps" ]; then
  for p in "$h/webapps"/*/; do
	p="${p%/}"
	[ -d "$p" ] || continue
	wp=$([ -f "$p/wp-config.php" ] && echo 1 || echo 0)
	out="${out}PATH:$p:$wp\n"
  done
fi
if [ -d "$h/domains" ]; then
  for dom in "$h/domains"/*/; do
	p="${dom%/}/public_html"
	[ -d "$p" ] || continue
	wp=$([ -f "$p/wp-config.php" ] && echo 1 || echo 0)
	out="${out}PATH:$p:$wp\n"
  done
fi
printf "%b" "$out"
echo "HOME:$h"
'
BASH;

		$scan_out = [];
		exec( $ssh_base . ' ' . escapeshellarg( 'sh -c '
h=$HOME
for d in public_html www html htdocs web; do
  [ -d "$h/$d" ] && printf "PATH:%s:%s\n" "$h/$d" "$([ -f "$h/$d/wp-config.php" ] && echo 1 || echo 0)"
done
if [ -d "$h/webapps" ]; then
  for p in "$h"/webapps/*/; do p="${p%/}"; [ -d "$p" ] && printf "PATH:%s:%s\n" "$p" "$([ -f "$p/wp-config.php" ] && echo 1 || echo 0)"; done
fi
if [ -d "$h/domains" ]; then
  for dom in "$h"/domains/*/; do p="${dom%/}/public_html"; [ -d "$p" ] && printf "PATH:%s:%s\n" "$p" "$([ -f "$p/wp-config.php" ] && echo 1 || echo 0)"; done
fi
echo "HOME:$h"
'' ) . ' 2>/dev/null', $scan_out );

		$detected_paths = [];
		$home_dir       = '';

		foreach ( $scan_out as $line ) {
			$line = trim( $line );
			if ( strpos( $line, 'PATH:' ) === 0 ) {
				$parts = explode( ':', ltrim( $line, 'PATH:' ), 3 );
				// explode on PATH: gives us the remainder, re-split on colon
				$segments = explode( ':', substr( $line, 5 ) );
				if ( count( $segments ) >= 2 ) {
					// path may contain colons, so everything except last segment is the path
					$has_wp  = (int) array_pop( $segments );
					$path    = implode( ':', $segments );
					$path    = rtrim( $path, '/' ) . '/';
					$detected_paths[] = [
						'path'   => $path,
						'has_wp' => (bool) $has_wp,
						'label'  => basename( rtrim( $path, '/' ) ),
					];
				}
			} elseif ( strpos( $line, 'HOME:' ) === 0 ) {
				$home_dir = rtrim( substr( $line, 5 ), '/' ) . '/';
			}
		}

		if ( $has_wpcli ) {
			$msg = __( 'SSH connection OK! WP-CLI found.', 'super-duper-easy-migration' );
		} else {
			$msg = __( 'SSH connection OK! ⚠ WP-CLI not found — mysql/PHP fallback will be used.', 'super-duper-easy-migration' );
		}

		wp_send_json_success( [
			'message'         => $msg,
			'has_wpcli'       => $has_wpcli,
			'detected_paths'  => $detected_paths,
			'home_dir'        => $home_dir,
		] );
	}


	/* ================================================================== */
	/*  AJAX: Start migration                                               */
	/* ================================================================== */

	public function ajax_start_migration(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		if ( get_transient( 'sdem_active_job' ) ) {
			wp_send_json_error( __( 'A migration is already running.', 'super-duper-easy-migration' ) );
		}

		$host        = sanitize_text_field( $_POST['host']        ?? '' );
		$user        = sanitize_text_field( $_POST['user']        ?? '' );
		$pass        = $_POST['pass']                             ?? '';
		$target_path = sanitize_text_field( $_POST['target_path'] ?? '' );

		if ( empty( $host ) || empty( $user ) || empty( $pass ) || empty( $target_path ) ) {
			wp_send_json_error( __( 'All fields are required.', 'super-duper-easy-migration' ) );
		}

		// Basic path sanity check — must be absolute, no traversal
		if ( strpos( $target_path, '..' ) !== false || $target_path[0] !== '/' ) {
			wp_send_json_error( __( 'Invalid target path.', 'super-duper-easy-migration' ) );
		}

		$target_path = rtrim( $target_path, '/' ) . '/';

		// Create target directory if it does not exist
		$ssh_base = sprintf(
			'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s',
			escapeshellarg( $pass ),
			escapeshellarg( $user ),
			escapeshellarg( $host )
		);
		exec( $ssh_base . ' ' . escapeshellarg( 'mkdir -p ' . escapeshellarg( $target_path ) ) . ' 2>&1', $mkdir_out, $mkdir_rc );
		if ( $mkdir_rc !== 0 ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: path */
					__( 'Could not create target folder: %s', 'super-duper-easy-migration' ),
					$target_path
				)
			);
		}

		// Check wp-config.php on chosen path
		$wpconfig_check = [];
		exec(
			$ssh_base . ' ' . escapeshellarg( 'test -f ' . escapeshellarg( $target_path ) . 'wp-config.php && echo SDEM_WPCONFIG_OK || echo SDEM_WPCONFIG_MISSING' ) . ' 2>&1',
			$wpconfig_check
		);
		$has_wpconfig = strpos( implode( '', $wpconfig_check ), 'SDEM_WPCONFIG_OK' ) !== false;

		// Optional manual DB fields (used when target has no wp-config.php)
		$manual_db = [];
		if ( ! empty( $_POST['manual_db_name'] ) ) {
			$manual_db = [
				'DB_NAME'      => sanitize_text_field( $_POST['manual_db_name']   ?? '' ),
				'DB_USER'      => sanitize_text_field( $_POST['manual_db_user']   ?? '' ),
				'DB_PASSWORD'  => $_POST['manual_db_pass']                        ?? '',
				'DB_HOST'      => sanitize_text_field( $_POST['manual_db_host']   ?? 'localhost' ),
				'DB_CHARSET'   => 'utf8mb4',
				'table_prefix' => sanitize_text_field( $_POST['manual_db_prefix'] ?? 'wp_' ),
				'target_url'   => esc_url_raw( $_POST['manual_target_url']        ?? '' ),
			];
		}

		$source_info = $this->get_source_info();

		$job_id = 'sdem_' . time() . '_' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );

		$job_config = [
			'job_id'         => $job_id,
			'log_dir'        => SDEM_LOG_DIR,
			'rsync_excludes' => SDEM_RSYNC_EXCLUDES,
			'source'         => $source_info,
			'target'         => [
				'host'      => $host,
				'ssh_user'  => $user,
				'ssh_pass'  => $pass,
				'path'      => $target_path,
				'manual_db' => $manual_db,
			],
			'created_at'     => current_time( 'mysql' ),
		];

		$config_file = SDEM_LOG_DIR . $job_id . '_config.json';
		file_put_contents( $config_file, wp_json_encode( $job_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		chmod( $config_file, 0600 );

		$step_keys = SDEM_STEP_KEYS;
		$steps     = [];
		foreach ( $step_keys as $k ) {
			$steps[ $k ] = [ 'status' => 'pending', 'message' => '' ];
		}

		$progress = [
			'job_id'           => $job_id,
			'status'           => 'running',
			'started_at'       => current_time( 'mysql' ),
			'overall_progress' => 0,
			'current_step'     => null,
			'steps'            => $steps,
			'summary'          => [
				'source_url'  => $source_info['site_url'],
				'source_path' => $source_info['source_path'],
				'target_path' => $target_path,
			],
			'log' => [ [
				'time'    => current_time( 'H:i:s' ),
				'level'   => 'info',
				'message' => 'Migration started: ' . $source_info['site_url'] . ' → ' . $target_path,
			] ],
		];

		file_put_contents(
			SDEM_LOG_DIR . $job_id . '.json',
			wp_json_encode( $progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
		);

		set_transient( 'sdem_active_job', $job_id, DAY_IN_SECONDS );

		$runner      = SDEM_PLUGIN_DIR . 'includes/migration-runner.php';
		$runner_log  = SDEM_LOG_DIR . $job_id . '_runner.log';

		$run_cmd = sprintf(
			'nohup php %s %s > %s 2>&1 &',
			escapeshellarg( $runner ),
			escapeshellarg( $config_file ),
			escapeshellarg( $runner_log )
		);
		exec( $run_cmd );

		wp_send_json_success( [ 'job_id' => $job_id ] );
	}


	/* ================================================================== */
	/*  AJAX: Check progress                                                */
	/* ================================================================== */

	public function ajax_check_progress(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		$job_id = sanitize_text_field( $_POST['job_id'] ?? get_transient( 'sdem_active_job' ) );
		if ( empty( $job_id ) ) {
			wp_send_json_error( __( 'No active job found.', 'super-duper-easy-migration' ) );
		}

		$progress_file = SDEM_LOG_DIR . $job_id . '.json';
		if ( ! file_exists( $progress_file ) ) {
			wp_send_json_error( __( 'Progress file not found.', 'super-duper-easy-migration' ) );
		}

		$progress = json_decode( file_get_contents( $progress_file ), true );
		if ( ! $progress ) {
			wp_send_json_error( __( 'Could not read progress file.', 'super-duper-easy-migration' ) );
		}

		if ( in_array( $progress['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
			delete_transient( 'sdem_active_job' );
		}

		wp_send_json_success( $progress );
	}


	/* ================================================================== */
	/*  AJAX: Cancel migration                                              */
	/* ================================================================== */

	public function ajax_cancel_migration(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		$job_id = sanitize_text_field( $_POST['job_id'] ?? get_transient( 'sdem_active_job' ) );
		if ( empty( $job_id ) ) {
			wp_send_json_error( __( 'No active job found.', 'super-duper-easy-migration' ) );
		}

		file_put_contents( SDEM_LOG_DIR . $job_id . '_cancel', '1' );
		delete_transient( 'sdem_active_job' );
		wp_send_json_success();
	}


	/* ================================================================== */
	/*  AJAX: Reset migration                                               */
	/* ================================================================== */

	public function ajax_reset_migration(): void {
		check_ajax_referer( 'sdem_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No access.', 'super-duper-easy-migration' ) );
		}

		delete_transient( 'sdem_active_job' );
		wp_send_json_success();
	}


	/* ================================================================== */
	/*  Helpers: parse wp-config.php content                               */
	/* ================================================================== */

	private function parse_wp_config_content( string $content ): array {
		$result = [
			'DB_NAME'      => '',
			'DB_USER'      => '',
			'DB_PASSWORD'  => '',
			'DB_HOST'      => 'localhost',
			'DB_CHARSET'   => 'utf8mb4',
			'table_prefix' => 'wp_',
		];

		foreach ( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET' ] as $key ) {
			if ( preg_match( "/define\s*\(\s*['\"]" . $key . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\\s*\)/", $content, $m ) ) {
				$result[ $key ] = $m[1];
			}
		}
		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m ) ) {
			$result['table_prefix'] = $m[1];
		}

		return $result;
	}

}

// Bootstrap
add_action( 'plugins_loaded', function() {
	Super_Duper_Easy_Migration::instance();
}, 5 );

// Activation hook
register_activation_hook( __FILE__, function() {
	if ( ! file_exists( SDEM_LOG_DIR ) ) {
		wp_mkdir_p( SDEM_LOG_DIR );
		file_put_contents( SDEM_LOG_DIR . '.htaccess', "Order deny,allow\nDeny from all" );
		file_put_contents( SDEM_LOG_DIR . 'index.php', '<?php // Silence is golden' );
	}
} );
