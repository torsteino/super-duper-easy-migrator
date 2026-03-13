#!/usr/bin/env php
<?php
/**
 * WP Site Migrator – Background Migration Runner
 *
 * Runs as a background process via nohup.
 * Usage: php migration-runner.php <config_file_path>
 *
 * @package WP_Site_Migrator
 */

// Kun CLI
if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

if ( empty( $argv[1] ) || ! file_exists( $argv[1] ) ) {
	die( 'Usage: php migration-runner.php <config_file>' . PHP_EOL );
}

ini_set( 'memory_limit', '512M' );
set_time_limit( 0 );
error_reporting( E_ALL );


class SDEM_Runner {

	private $config;
	private $progress;
	private $progress_file;
	private $log_dir;
	private $job_id;

	// Target info collected during preflight
	private $target_db     = [];
	private $target_url    = '';
	private $target_home   = '';
	private $has_wpcli     = true;

	// Steg-vekter for fremdriftsberegning (total = 100)
	private $step_weights = [
		'preflight'        => 5,
		'db_dump'          => 15,
		'rsync'            => 50,
		'htaccess_cleanup' => 2,
		'wp_config'        => 5,
		'db_import'        => 10,
		'search_replace'   => 10,
		'cleanup'          => 3,
	];

	private $completed_weight = 0;
	private $dump_file        = '';


	/* ================================================================== */
	/*  CONSTRUCTOR                                                        */
	/* ================================================================== */

	public function __construct( $config_file ) {
		$raw = file_get_contents( $config_file );
		$this->config = json_decode( $raw, true );
		if ( ! $this->config ) {
			die( 'Invalid config JSON.' . PHP_EOL );
		}

		$this->job_id        = $this->config['job_id'];
		$this->log_dir       = $this->config['log_dir'];
		$this->progress_file = $this->log_dir . $this->job_id . '.json';

		if ( ! file_exists( $this->progress_file ) ) {
			die( 'Finner ikke fremdriftsfil: ' . $this->progress_file . PHP_EOL );
		}

		$this->progress = json_decode( file_get_contents( $this->progress_file ), true );
	}


	/* ================================================================== */
	/*  RUN                                                               */
	/* ================================================================== */

	public function run() {
		try {
			$this->step_preflight();
			$this->step_db_dump();
			$this->step_rsync();
			$this->step_htaccess_cleanup();
			$this->step_wp_config();
			$this->step_db_import();
			$this->step_search_replace();
			$this->step_cleanup();
			$this->complete();
		} catch ( Exception $e ) {
			$this->fail( $e->getMessage() );
		}
	}


	/* ================================================================== */
	/*  STEP 1: PRE-FLIGHT CHECK                                             */
	/* ================================================================== */

	private function step_preflight() {
		$this->set_step( 'preflight', 'running' );
		$this->log( 'info', 'Starting pre-flight check …' );

		$target = $this->config['target'];

		// 1. Test SSH
		$out = [];
		if ( ! $this->ssh_exec( 'echo SDEM_OK', $out ) || strpos( implode( '', $out ), 'SDEM_OK' ) === false ) {
			throw new Exception( 'SSH connection failed: ' . implode( "\n", $out ) );
		}
		$this->log( 'info', 'SSH connection OK.' );

		// 2. Check if wp-cli is available on target
		$out = [];
		$this->ssh_exec( 'wp --version 2>&1', $out );
		$this->has_wpcli = strpos( implode( '', $out ), 'WP-CLI' ) !== false;
		if ( $this->has_wpcli ) {
			$this->log( 'info', 'WP-CLI found on target server.' );
		} else {
			$this->log( 'warning', 'WP-CLI not found — using mysql/PHP fallback for import and search-replace.' );
		}

		// 3. Hent DB-info fra wp-config.php eller manuelt oppgitte verdier
		$out = [];
		$this->ssh_exec( 'cat ' . escapeshellarg( rtrim( $target['path'], '/' ) . '/wp-config.php' ) . ' 2>/dev/null', $out );
		$config_content = implode( "\n", $out );
		$parsed = $this->parse_wp_config_content( $config_content );

		if ( ! empty( $parsed['DB_NAME'] ) ) {
			$this->target_db = $parsed;
			$this->log( 'info', 'wp-config.php read on target (DB: ' . $this->target_db['DB_NAME'] . ').' );
		} else {
			// Fallback: bruk manuelt oppgitt DB-info fra job-konfigen
			$manual = $this->config['target']['manual_db'] ?? [];
			if ( empty( $manual['DB_NAME'] ) || empty( $manual['DB_USER'] ) ) {
				throw new Exception(
					'wp-config.php missing or unreadable on target, and no manual DB info was provided. ' .
					'Please fill in the DB fields in the form and try again.'
				);
			}
			$this->target_db = [
				'DB_NAME'      => $manual['DB_NAME'],
				'DB_USER'      => $manual['DB_USER'],
				'DB_PASSWORD'  => $manual['DB_PASSWORD'] ?? '',
				'DB_HOST'      => $manual['DB_HOST']     ?? 'localhost',
				'DB_CHARSET'   => $manual['DB_CHARSET']  ?? 'utf8mb4',
				'table_prefix' => $manual['table_prefix'] ?? 'wp_',
			];
			$this->log( 'warning', 'wp-config.php not found — using manually supplied DB info.' );
			$this->log( 'info', 'Target DB (manual): ' . $this->target_db['DB_NAME'] );
		}

		// 4. Fetch target URL
		if ( $this->has_wpcli ) {
			$out = [];
			$this->ssh_exec(
				'wp option get siteurl --quiet --skip-plugins --skip-themes --path=' . escapeshellarg( $target['path'] ),
				$out
			);
			$this->target_url = $this->extract_url_from_output( $out );

			if ( ! empty( $this->target_url ) ) {
				$out = [];
				$this->ssh_exec(
					'wp option get home --quiet --skip-plugins --skip-themes --path=' . escapeshellarg( $target['path'] ),
					$out
				);
				$this->target_home = $this->extract_url_from_output( $out ) ?: $this->target_url;
				$this->log( 'info', 'Target URL retrieved via WP-CLI: ' . $this->target_url );
			} else {
				$this->log( 'warning', 'WP-CLI did not return a URL — falling back to manual URL.' );
			}
		}

		// Fallback: bruk manuell URL hvis wp-cli feilet eller mangler
		if ( empty( $this->target_url ) ) {
			$manual_url = $this->config['target']['manual_db']['target_url'] ?? '';
			if ( empty( $manual_url ) ) {
				throw new Exception(
					'Unable to retrieve target URL (WP-CLI unavailable or failed), and no manual URL was provided. ' .
					'Please fill in the URL field in the form and try again.'
				);
			}
			$this->target_url  = rtrim( $manual_url, '/' );
			$this->target_home = $this->target_url;
			$this->log( 'warning', 'Using manually supplied target URL: ' . $this->target_url );
		}

		// 5. Logg sammendrag
		$source = $this->config['source'];
		$this->log( 'info', 'Source URL:    ' . $source['site_url'] );
		$this->log( 'info', 'Target URL:    ' . $this->target_url );
		$this->log( 'info', 'Source path:   ' . $source['source_path'] );
		$this->log( 'info', 'Target path:   ' . $target['path'] );
		$this->log( 'info', 'Source prefix: ' . $source['table_prefix'] );
		$this->log( 'info', 'Target prefix: ' . $this->target_db['table_prefix'] );

		if ( $source['table_prefix'] !== $this->target_db['table_prefix'] ) {
			$this->log( 'warning', 'Table prefix differs — source prefix (' .
			                        $source['table_prefix'] . ') will be used.' );
		}

		$this->check_cancelled();
		$this->set_step( 'preflight', 'completed', 'OK' );
		$this->log( 'success', 'Pre-flight check complete.' );
	}


	/* ================================================================== */
	/*  STEG 2: DATABASE-DUMP                                              */
	/* ================================================================== */

	private function step_db_dump() {
		$this->set_step( 'db_dump', 'running', 'Dumping …' );
		$this->log( 'info', 'Starting database dump …' );

		$source    = $this->config['source'];
		$this->dump_file = $this->log_dir . $this->job_id . '_dump.sql';

		// Create temporary MySQL config (avoids password on command line)
		$mycnf_file = $this->log_dir . $this->job_id . '_my.cnf';
		$mycnf  = "[client]\n";
		$mycnf .= 'user='     . $source['db_user'] . "\n";
		$mycnf .= 'password=' . $source['db_pass'] . "\n";
		$mycnf .= 'host='     . $source['db_host'] . "\n";
		file_put_contents( $mycnf_file, $mycnf );
		chmod( $mycnf_file, 0600 );

		// Run mysqldump
		$cmd = sprintf(
			'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s > %s 2>&1',
			escapeshellarg( $mycnf_file ),
			escapeshellarg( $source['db_name'] ),
			escapeshellarg( $this->dump_file )
		);

		$output = [];
		$rc     = 0;
		exec( $cmd, $output, $rc );

		// Rydd opp mycnf uansett
		@unlink( $mycnf_file );

		if ( $rc !== 0 ) {
			throw new Exception( 'mysqldump feilet (kode ' . $rc . '): ' . implode( "\n", $output ) );
		}

		if ( ! file_exists( $this->dump_file ) || filesize( $this->dump_file ) < 100 ) {
			throw new Exception( 'Dump-filen er tom eller ugyldig.' );
		}

		$size = $this->human_filesize( filesize( $this->dump_file ) );

		$this->check_cancelled();
		$this->set_step( 'db_dump', 'completed', $size );
		$this->log( 'success', 'Database dump complete (' . $size . ').' );
	}


	/* ================================================================== */
	/*  STEG 3: RSYNC                                                      */
	/* ================================================================== */

	private function step_rsync() {
		$this->set_step( 'rsync', 'running', 'Copying files …' );
		$this->log( 'info', 'Starting file transfer (rsync) …' );

		$source   = $this->config['source'];
		$target   = $this->config['target'];
		$excludes = $this->config['rsync_excludes'] ?? [];

		// Bygg exclude-argumenter
		$exclude_args = '';
		foreach ( $excludes as $exc ) {
			$exclude_args .= ' --exclude=' . escapeshellarg( $exc );
		}

		// rsync-kommando
		$cmd = sprintf(
			'sshpass -p %s rsync -az --delete --partial -e %s %s %s %s@%s:%s 2>&1',
			escapeshellarg( $target['ssh_pass'] ),
			escapeshellarg( 'ssh -o StrictHostKeyChecking=no' ),
			$exclude_args,
			escapeshellarg( $source['source_path'] ),
			escapeshellarg( $target['ssh_user'] ),
			escapeshellarg( $target['host'] ),
			escapeshellarg( $target['path'] )
		);

		$this->log( 'info', 'rsync running — this may take a while for large sites …' );

		$output = [];
		$rc     = 0;
		exec( $cmd, $output, $rc );

		// rc 24 = some files vanished during transfer (normal for live sites)
		if ( $rc !== 0 && $rc !== 24 ) {
			$tail = array_slice( $output, -10 );
			throw new Exception( 'rsync feilet (kode ' . $rc . '): ' . implode( "\n", $tail ) );
		}

		if ( $rc === 24 ) {
			$this->log( 'warning', 'Some files disappeared during transfer (normal for live sites).' );
		}

		$this->check_cancelled();
		$this->set_step( 'rsync', 'completed', 'Ferdig' );
		$this->log( 'success', 'File transfer complete.' );
	}


	/* ================================================================== */
	/*  STEG 3b: .HTACCESS-OPPRYDDING                                     */
	/* ================================================================== */

	/**
	 * Removes php_value and php_flag directives from .htaccess on the target server.
	 * Disse er gyldige med mod_php, men krasjer under PHP-FPM (proxy_fcgi).
	 */
	private function step_htaccess_cleanup() {
		$this->set_step( 'htaccess_cleanup', 'running', 'Cleaning .htaccess …' );
		$this->log( 'info', 'Checking .htaccess on target for PHP-FPM-incompatible directives …' );

		$target   = $this->config['target'];
		$htaccess = rtrim( $target['path'], '/' ) . '/.htaccess';

		// Les innholdet
		$out = [];
		$rc  = $this->ssh_exec( 'cat ' . escapeshellarg( $htaccess ) . ' 2>/dev/null', $out );
		$content = implode( "\n", $out );

		if ( empty( trim( $content ) ) ) {
			$this->log( 'info', '.htaccess not found or empty — nothing to clean up.' );
			$this->set_step( 'htaccess_cleanup', 'completed', 'Ingen endringer' );
			return;
		}

		$original_lines = explode( "\n", $content );
		$cleaned_lines  = [];
		$removed        = [];

		foreach ( $original_lines as $line ) {
			// Fjern php_value og php_flag (mod_php-direktiver, ugyldige under PHP-FPM)
			if ( preg_match( '/^\s*php_(value|flag)\s+/i', $line ) ) {
				$removed[] = trim( $line );
				$this->log( 'warning', 'Fjerner fra .htaccess: ' . trim( $line ) );
			} else {
				$cleaned_lines[] = $line;
			}
		}

		if ( empty( $removed ) ) {
			$this->log( 'info', 'Ingen php_value/php_flag-linjer funnet i .htaccess.' );
			$this->set_step( 'htaccess_cleanup', 'completed', 'No changes needed' );
			return;
		}

		// Skriv tilbake renset innhold via SSH
		$cleaned_content = implode( "\n", $cleaned_lines );

		// Use printf to avoid issues with special characters in here-doc over SSH
		$tmp_file = '/tmp/wsm_htaccess_' . $this->job_id;
		$write_cmd = sprintf(
			'printf %%s %s > %s && mv %s %s',
			escapeshellarg( $cleaned_content ),
			escapeshellarg( $tmp_file ),
			escapeshellarg( $tmp_file ),
			escapeshellarg( $htaccess )
		);

		$out = [];
		$rc  = $this->ssh_exec( $write_cmd, $out );

		if ( $rc !== 0 ) {
			throw new Exception( 'Kunne ikke skrive renset .htaccess: ' . implode( ' ', $out ) );
		}

		$count = count( $removed );
		$this->log( 'success', sprintf( 'Fjernet %d php_value/php_flag-linje(r) fra .htaccess.', $count ) );
		$this->set_step( 'htaccess_cleanup', 'completed', sprintf( '%d linjer fjernet', $count ) );
	}


	/* ================================================================== */
	/*  STEG 4: WP-CONFIG                                                  */
	/* ================================================================== */

	private function step_wp_config() {
		$this->set_step( 'wp_config', 'running', 'Updating …' );
		$this->log( 'info', 'Updating wp-config.php on target …' );

		$source = $this->config['source'];
		$target = $this->config['target'];

		// Les kilde-wp-config (lokal fil)
		$source_config_path = rtrim( $source['source_path'], '/' ) . '/wp-config.php';
		$content = @file_get_contents( $source_config_path );
		if ( ! $content ) {
			throw new Exception( 'Kunne ikke lese kilde wp-config.php: ' . $source_config_path );
		}

		// Erstatt stier gjennom hele filen (fanger WP_CONTENT_DIR, UPLOADS osv.)
		$source_path_trimmed = rtrim( $source['source_path'], '/' );
		$target_path_trimmed = rtrim( $target['path'], '/' );
		$content = str_replace( $source_path_trimmed, $target_path_trimmed, $content );

		// Erstatt URL-er gjennom hele filen (fanger WP_SITEURL, WP_HOME defines)
		$source_url  = rtrim( $source['site_url'], '/' );
		$new_url     = rtrim( $this->target_url, '/' );
		if ( $source_url !== $new_url ) {
			$content = str_replace( $source_url, $new_url, $content );
		}

		$source_home = rtrim( $source['home_url'], '/' );
		$new_home    = rtrim( $this->target_home, '/' );
		if ( $source_home !== $new_home && $source_home !== $source_url ) {
			$content = str_replace( $source_home, $new_home, $content );
		}

		// Replace DB credentials (line by line, safe handling)
		$content = $this->replace_define_in_content( $content, 'DB_NAME',     $this->target_db['DB_NAME'] );
		$content = $this->replace_define_in_content( $content, 'DB_USER',     $this->target_db['DB_USER'] );
		$content = $this->replace_define_in_content( $content, 'DB_PASSWORD', $this->target_db['DB_PASSWORD'] );
		$content = $this->replace_define_in_content( $content, 'DB_HOST',     $this->target_db['DB_HOST'] );

		if ( ! empty( $this->target_db['DB_CHARSET'] ) ) {
			$content = $this->replace_define_in_content( $content, 'DB_CHARSET', $this->target_db['DB_CHARSET'] );
		}

		// table_prefix beholdes fra kilde (matcher importert DB)

		// Skriv til midlertidig fil og last opp
		$temp_config = $this->log_dir . $this->job_id . '_wp-config.php';
		file_put_contents( $temp_config, $content );

		if ( ! $this->scp_to_target( $temp_config, $target['path'] . 'wp-config.php' ) ) {
			@unlink( $temp_config );
			throw new Exception( 'Could not upload wp-config.php to target.' );
		}

		@unlink( $temp_config );

		// Sett riktige filrettigheter
		$this->ssh_exec( 'chmod 644 ' . $target['path'] . 'wp-config.php' );

		$this->check_cancelled();
		$this->set_step( 'wp_config', 'completed', 'OK' );
		$this->log( 'success', 'wp-config.php updated on target.' );
	}


	/* ================================================================== */
	/*  STEG 5: DATABASE-IMPORT                                            */
	/* ================================================================== */

	private function step_db_import() {
		$this->set_step( 'db_import', 'running', 'Importing …' );
		$this->log( 'info', 'Importing database on target …' );

		$target      = $this->config['target'];
		$remote_dump = '/tmp/sdem_' . $this->job_id . '_dump.sql';

		// Transfer dump file to target
		$this->log( 'info', 'Transferring dump file to target server …' );
		if ( ! $this->scp_to_target( $this->dump_file, $remote_dump ) ) {
			throw new Exception( 'Could not transfer dump file to target.' );
		}

		$out = [];
		$rc  = 0;

		if ( $this->has_wpcli ) {
			// Primary: wp db import
			$this->log( 'info', 'Running wp db import …' );
			$this->ssh_exec(
				'wp db import ' . escapeshellarg( $remote_dump ) . ' --path=' . escapeshellarg( $target['path'] ),
				$out, $rc
			);
		} else {
			// Fallback: mysql CLI with temporary .my.cnf to avoid password on command line
			$this->log( 'info', 'WP-CLI unavailable — using mysql CLI …' );
			$db     = $this->target_db;
			$mycnf  = '/tmp/sdem_' . $this->job_id . '_my.cnf';
			$mycnf_content = sprintf(
				"[client]\nhost=%s\nuser=%s\npassword=%s\n",
				addslashes( $db['DB_HOST'] ),
				addslashes( $db['DB_USER'] ),
				addslashes( $db['DB_PASSWORD'] )
			);

			// Write temporary .my.cnf on target server
			$this->ssh_exec(
				sprintf( 'printf %%s %s > %s && chmod 600 %s',
					escapeshellarg( $mycnf_content ),
					escapeshellarg( $mycnf ),
					escapeshellarg( $mycnf )
				)
			);

			// Run import
			$this->ssh_exec(
				sprintf( 'mysql --defaults-file=%s %s < %s 2>&1',
					escapeshellarg( $mycnf ),
					escapeshellarg( $db['DB_NAME'] ),
					escapeshellarg( $remote_dump )
				),
				$out, $rc
			);

			// Slett .my.cnf umiddelbart
			$this->ssh_exec( 'rm -f ' . escapeshellarg( $mycnf ) );
		}

		// Delete dump file on target regardless
		$this->ssh_exec( 'rm -f ' . escapeshellarg( $remote_dump ) );

		if ( $rc !== 0 ) {
			throw new Exception( 'Database import failed: ' . implode( "\n", $out ) );
		}

		$this->check_cancelled();
		$this->set_step( 'db_import', 'completed', 'OK' );
		$this->log( 'success', 'Database imported.' );
	}


	/* ================================================================== */
	/*  STEP 6: SEARCH AND REPLACE                                            */
	/* ================================================================== */

	private function step_search_replace() {
		$this->set_step( 'search_replace', 'running', 'Replacing …' );
		$this->log( 'info', 'Starting search and replace …' );

		$source  = $this->config['source'];
		$target  = $this->config['target'];
		$wp_path = $target['path'];

		$replacements = [];

		// URL-erstatninger
		$source_url = rtrim( $source['site_url'], '/' );
		$target_url = rtrim( $this->target_url, '/' );
		if ( $source_url !== $target_url ) {
			$replacements[] = [ $source_url, $target_url, 'siteurl' ];
		}

		$source_home = rtrim( $source['home_url'], '/' );
		$target_home = rtrim( $this->target_home, '/' );
		if ( $source_home !== $target_home && $source_home !== $source_url ) {
			$replacements[] = [ $source_home, $target_home, 'home' ];
		}

		// Sti-erstatninger
		$source_path = rtrim( $source['source_path'], '/' );
		$target_path = rtrim( $target['path'], '/' );
		if ( $source_path !== $target_path ) {
			$replacements[] = [ $source_path, $target_path, 'sti' ];
		}

		if ( empty( $replacements ) ) {
			$this->log( 'info', 'No replacements needed (URLs and paths are identical).' );
			$this->set_step( 'search_replace', 'completed', 'Ingen endringer' );
			return;
		}

		if ( $this->has_wpcli ) {
			$this->search_replace_wpcli( $replacements, $wp_path );
		} else {
			$this->search_replace_php( $replacements );
		}

		$this->check_cancelled();
		$this->set_step( 'search_replace', 'completed', count( $replacements ) . ' run' );
		$this->log( 'success', 'Search and replace complete.' );
	}

	private function search_replace_wpcli( array $replacements, string $wp_path ) {
		foreach ( $replacements as $r ) {
			list( $old, $new, $label ) = $r;
			$this->log( 'info', $label . ': ' . $old . ' → ' . $new );

			$remote_cmd = sprintf(
				'wp search-replace %s %s --all-tables-with-prefix --recurse-objects --skip-plugins --skip-themes --path=%s',
				escapeshellarg( $old ),
				escapeshellarg( $new ),
				escapeshellarg( $wp_path )
			);

			$out = [];
			$rc  = 0;
			$this->ssh_exec( $remote_cmd, $out, $rc );

			if ( $rc !== 0 ) {
				$this->log( 'warning', 'search-replace for ' . $label .
				                        ' returned code ' . $rc . ': ' . implode( "\n", $out ) );
			} else {
				$summary = end( $out );
				if ( $summary ) {
					$this->log( 'info', '  → ' . trim( $summary ) );
				}
			}
		}
	}

	private function search_replace_php( array $replacements ) {
		$this->log( 'info', 'WP-CLI unavailable — using PHP script for search and replace …' );

		$db     = $this->target_db;
		$prefix = $db['table_prefix'];

		// Build PHP script that does search-replace directly against the database
		$script = '<?php' . "\n"
			. 'error_reporting(0);' . "\n"
			. '$db = new mysqli(' . "\n"
			. '    ' . var_export( $db['DB_HOST'],    true ) . ',' . "\n"
			. '    ' . var_export( $db['DB_USER'],    true ) . ',' . "\n"
			. '    ' . var_export( $db['DB_PASSWORD'], true ) . ',' . "\n"
			. '    ' . var_export( $db['DB_NAME'],    true ) . "\n"
			. ');' . "\n"
			. 'if ($db->connect_error) { echo "CONN_ERR:" . $db->connect_error; exit(1); }' . "\n"
			. '$db->set_charset(' . var_export( $db['DB_CHARSET'] ?? 'utf8mb4', true ) . ');' . "\n"
			. '$prefix = ' . var_export( $prefix, true ) . ';' . "\n"
			. '$replacements = ' . var_export( $replacements, true ) . ';' . "\n"
			. '
$tables = [];
$res = $db->query("SHOW TABLES LIKE \'" . $db->real_escape_string($prefix) . "%\'");
while ($row = $res->fetch_row()) { $tables[] = $row[0]; }

$total = 0;
foreach ($tables as $table) {
    $cols_res = $db->query("SHOW COLUMNS FROM `" . $table . "`");
    $cols = [];
    while ($col = $cols_res->fetch_assoc()) {
        $t = strtolower($col["Type"]);
        if (strpos($t,"char")!==false||strpos($t,"text")!==false||strpos($t,"blob")!==false||strpos($t,"json")!==false) {
            $cols[] = $col["Field"];
        }
    }
    if (empty($cols)) continue;

    // Fetch primary key
    $pk = null;
    $pk_res = $db->query("SHOW KEYS FROM `" . $table . "` WHERE Key_name=\'PRIMARY\'");
    if ($pk_row = $pk_res->fetch_assoc()) { $pk = $pk_row["Column_name"]; }
    if (!$pk) continue;

    $rows = $db->query("SELECT `" . $pk . "`, `" . implode("`,`", $cols) . "` FROM `" . $table . "`");
    while ($row = $rows->fetch_assoc()) {
        $id   = $row[$pk];
        $sets = [];
        $changed = false;
        foreach ($cols as $col) {
            $val = $row[$col];
            if ($val === null) continue;
            $new_val = $val;
            foreach ($replacements as $rep) {
                // Handle serialised strings
                $new_val = wsm_replace_in_value($new_val, $rep[0], $rep[1]);
            }
            if ($new_val !== $val) {
                $sets[] = "`" . $col . "`=\'" . $db->real_escape_string($new_val) . "\'";
                $changed = true;
            }
        }
        if ($changed) {
            $db->query("UPDATE `" . $table . "` SET " . implode(",", $sets) . " WHERE `" . $pk . "`=\'" . $db->real_escape_string($id) . "\'");
            $total++;
        }
    }
}

function wsm_replace_in_value($val, $search, $replace) {
    // Attempt deserialisation
    $unserialized = @unserialize($val);
    if ($unserialized !== false || $val === serialize(false)) {
        $replaced = wsm_recursive_replace($unserialized, $search, $replace);
        $reserialized = serialize($replaced);
        return $reserialized;
    }
    return str_replace($search, $replace, $val);
}

function wsm_recursive_replace($data, $search, $replace) {
    if (is_array($data)) {
        $result = [];
        foreach ($data as $k => $v) {
            $result[str_replace($search, $replace, $k)] = wsm_recursive_replace($v, $search, $replace);
        }
        return $result;
    } elseif (is_object($data)) {
        foreach (get_object_vars($data) as $k => $v) {
            $data->$k = wsm_recursive_replace($v, $search, $replace);
        }
        return $data;
    } elseif (is_string($data)) {
        return str_replace($search, $replace, $data);
    }
    return $data;
}

echo "WSM_SR_OK:" . $total;
' . "\n";

		// Upload the PHP script to the target server
		$remote_script = '/tmp/sdem_sr_' . $this->job_id . '.php';
		$local_script  = $this->log_dir . 'sdem_sr_' . $this->job_id . '.php';

		file_put_contents( $local_script, $script );

		if ( ! $this->scp_to_target( $local_script, $remote_script ) ) {
			@unlink( $local_script );
			throw new Exception( 'Could not upload search-replace script to target.' );
		}
		@unlink( $local_script );

		// Run the script
		$out = [];
		$rc  = 0;
		$this->ssh_exec( 'php ' . escapeshellarg( $remote_script ) . ' 2>&1', $out, $rc );
		$this->ssh_exec( 'rm -f ' . escapeshellarg( $remote_script ) );

		$output = trim( implode( "\n", $out ) );

		if ( $rc !== 0 || strpos( $output, 'CONN_ERR' ) !== false ) {
			throw new Exception( 'PHP search-replace failed: ' . $output );
		}

		if ( preg_match( '/WSM_SR_OK:(\d+)/', $output, $m ) ) {
			$this->log( 'info', '  → ' . $m[1] . ' rows updated via PHP.' );
		} else {
			$this->log( 'warning', 'Unknown output from search-replace script: ' . $output );
		}
	}


	/* ================================================================== */
	/*  STEG 7: OPPRYDDING                                                */
	/* ================================================================== */

	private function step_cleanup() {
		$this->set_step( 'cleanup', 'running', 'Cleaning up …' );
		$this->log( 'info', 'Cleaning up …' );

		$target = $this->config['target'];

		// Slett lokal dump-fil
		if ( $this->dump_file && file_exists( $this->dump_file ) ) {
			@unlink( $this->dump_file );
			$this->log( 'info', 'Local dump file deleted.' );
		}

		// Flush cache on target
		$out = [];
		$this->ssh_exec( 'wp cache flush --quiet --skip-plugins --skip-themes --path=' .
		                  escapeshellarg( $target['path'] ), $out );
		$this->log( 'info', 'Cache flushed on target.' );

		// Flush permalenker
		$out = [];
		$this->ssh_exec( 'wp rewrite flush --quiet --skip-plugins --skip-themes --path=' .
		                  escapeshellarg( $target['path'] ), $out );
		$this->log( 'info', 'Permalinks flushed on target.' );

		// Notat om forskjellig table prefix
		$source_prefix = $this->config['source']['table_prefix'];
		$target_prefix = $this->target_db['table_prefix'] ?? 'wp_';

		if ( $source_prefix !== $target_prefix ) {
			$this->log( 'warning',
				'Table prefix endret fra "' . $target_prefix . '" til "' . $source_prefix . '". ' .
				'Ubrukte tabeller med prefix "' . $target_prefix . '" kan ligge igjen i databasen.' );
		}

		// Delete sdem-logs folder on target server (copied over via rsync)
		$this->ssh_exec( 'rm -rf ' . escapeshellarg( $target['path'] . 'wp-content/wsm-logs/' ) );
		$this->log( 'info', 'sdem-logs removed from target server.' );

		// Deactivate migration plugin on target (not needed there)
		$out = [];
		$this->ssh_exec(
			'wp plugin deactivate wp-site-migrator --skip-plugins --skip-themes --path=' .
			escapeshellarg( $target['path'] ) . ' 2>/dev/null', $out );
		$this->ssh_exec(
			'rm -rf ' . escapeshellarg( $target['path'] . 'wp-content/plugins/wp-site-migrator/' ) );
		$this->log( 'info', 'Migration plugin removed from target server.' );

		$this->set_step( 'cleanup', 'completed', 'OK' );
		$this->log( 'success', 'Cleanup complete.' );
	}


	/* ================================================================== */
	/*  COMPLETED / FAILED                                                  */
	/* ================================================================== */

	private function complete() {
		$this->progress['status']           = 'completed';
		$this->progress['overall_progress'] = 100;
		$this->progress['completed_at']     = date( 'Y-m-d H:i:s' );
		$this->progress['target_url']       = $this->target_url;
		$this->progress['target_admin_url'] = rtrim( $this->target_url, '/' ) . '/wp-admin/';

		$elapsed = time() - strtotime( $this->progress['started_at'] );
		$this->log( 'success', 'Migration complete: ' . $this->human_duration( $elapsed ) . '.' );

		$this->log( 'info', 'Ny side:     ' . $this->target_url );
		$this->log( 'info', 'WP-admin:    ' . rtrim( $this->target_url, '/' ) . '/wp-admin/' );

		$this->save_progress();
	}

	private function fail( $message ) {
		$this->log( 'error', $message );

		$current_step = $this->progress['current_step'] ?? null;
		if ( $current_step && isset( $this->progress['steps'][ $current_step ] ) ) {
			$this->progress['steps'][ $current_step ]['status']  = 'failed';
			$this->progress['steps'][ $current_step ]['message'] = 'Feilet';
		}

		$this->progress['status'] = 'failed';
		$this->save_progress();

		// Rydd opp dump-fil ved feil
		if ( $this->dump_file && file_exists( $this->dump_file ) ) {
			@unlink( $this->dump_file );
		}

		exit( 1 );
	}


	/* ================================================================== */
	/*  HJELPEFUNKSJONER: Fremdrift                                        */
	/* ================================================================== */

	private function save_progress() {
		$json = json_encode( $this->progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$temp = $this->progress_file . '.tmp';
		file_put_contents( $temp, $json );
		rename( $temp, $this->progress_file ); // Atomisk skrive
	}

	private function log( $level, $message ) {
		$this->progress['log'][] = [
			'time'    => date( 'H:i:s' ),
			'level'   => $level,
			'message' => $message,
		];
		$this->save_progress();
	}

	private function set_step( $step, $status, $message = '' ) {
		$this->progress['steps'][ $step ] = [
			'status'  => $status,
			'message' => $message,
		];
		$this->progress['current_step'] = $step;

		if ( $status === 'completed' ) {
			$this->completed_weight += $this->step_weights[ $step ] ?? 0;
			$this->progress['overall_progress'] = min( 99, $this->completed_weight );
		}

		$this->save_progress();
	}

	private function check_cancelled() {
		$cancel_file = $this->log_dir . $this->job_id . '_cancel';
		if ( file_exists( $cancel_file ) ) {
			$this->log( 'warning', 'Migration cancelled by user.' );
			$this->progress['status'] = 'cancelled';
			$this->save_progress();

			// Rydd opp
			if ( $this->dump_file && file_exists( $this->dump_file ) ) {
				@unlink( $this->dump_file );
			}

			exit( 0 );
		}
	}


	/* ================================================================== */
	/*  HJELPEFUNKSJONER: SSH / SCP                                        */
	/* ================================================================== */

	private function ssh_cmd( $remote_cmd ) {
		$target = $this->config['target'];
		return sprintf(
			'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 %s@%s %s',
			escapeshellarg( $target['ssh_pass'] ),
			escapeshellarg( $target['ssh_user'] ),
			escapeshellarg( $target['host'] ),
			escapeshellarg( $remote_cmd )
		);
	}

	private function ssh_exec( $remote_cmd, &$output = [], &$return_code = 0 ) {
		$output = [];
		$cmd    = $this->ssh_cmd( $remote_cmd );
		exec( $cmd . ' 2>&1', $output, $return_code );
		return $return_code === 0;
	}

	private function scp_to_target( $local_file, $remote_path ) {
		$target = $this->config['target'];
		$cmd = sprintf(
			'sshpass -p %s scp -o StrictHostKeyChecking=no %s %s@%s:%s 2>&1',
			escapeshellarg( $target['ssh_pass'] ),
			escapeshellarg( $local_file ),
			escapeshellarg( $target['ssh_user'] ),
			escapeshellarg( $target['host'] ),
			escapeshellarg( $remote_path )
		);
		$output = [];
		$rc     = 0;
		exec( $cmd, $output, $rc );
		return $rc === 0;
	}


	/* ================================================================== */
	/*  HJELPEFUNKSJONER: wp-config parsing                                */
	/* ================================================================== */

	private function parse_wp_config_content( $content ) {
		$config = [];

		$keys = [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE' ];
		foreach ( $keys as $key ) {
			$pattern = "/define\s*\(\s*['\"]" . preg_quote( $key, '/' ) . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/";
			if ( preg_match( $pattern, $content, $m ) ) {
				$config[ $key ] = $m[1];
			}
		}

		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^"\']+)[\'"]\s*;/', $content, $m ) ) {
			$config['table_prefix'] = $m[1];
		} else {
			$config['table_prefix'] = 'wp_';
		}

		return $config;
	}

	/**
	 * Erstatt en define()-linje i wp-config-innhold.
	 * Safe handling of special characters in values.
	 */
	private function replace_define_in_content( $content, $key, $new_value ) {
		$lines   = explode( "\n", $content );
		$pattern = "/define\s*\(\s*['\"]" . preg_quote( $key, '/' ) . "['\"]/";
		$escaped = addcslashes( $new_value, "'\\" );

		foreach ( $lines as &$line ) {
			if ( preg_match( $pattern, $line ) ) {
				$line = "define( '" . $key . "', '" . $escaped . "' );";
				break;
			}
		}
		unset( $line );

		return implode( "\n", $lines );
	}


	/* ================================================================== */
	/*  HJELPEFUNKSJONER: Diverse                                          */
	/* ================================================================== */

	/**
	 * Filtrer bort PHP warnings/notices fra wp-cli output og finn URL.
	 */
	private function extract_url_from_output( $output_lines ) {
		foreach ( $output_lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) continue;
			if ( stripos( $line, 'PHP Warning' ) !== false ) continue;
			if ( stripos( $line, 'PHP Notice' ) !== false ) continue;
			if ( stripos( $line, 'PHP Deprecated' ) !== false ) continue;
			if ( stripos( $line, 'Warning:' ) !== false ) continue;
			if ( stripos( $line, 'Notice:' ) !== false ) continue;

			if ( preg_match( '#^https?://.+#', $line ) ) {
				return $line;
			}
		}
		return '';
	}

	private function human_filesize( $bytes ) {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, 1 ) . ' ' . $units[ $i ];
	}

	private function human_duration( $seconds ) {
		$h = floor( $seconds / 3600 );
		$m = floor( ( $seconds % 3600 ) / 60 );
		$s = $seconds % 60;

		$parts = [];
		if ( $h > 0 ) $parts[] = $h . 't';
		if ( $m > 0 ) $parts[] = $m . 'm';
		$parts[] = $s . 's';

		return implode( ' ', $parts );
	}
}


/* ====================================================================== */
/*  START                                                                  */
/* ====================================================================== */

$runner = new SDEM_Runner( $argv[1] );
$runner->run();

