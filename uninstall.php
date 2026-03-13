<?php
/**
 * Uninstall routine for Super Duper Easy Migration.
 *
 * Removes:
 *  - The sdem-logs directory and all its contents
 *  - The sdem_active_job transient
 *
 * Called automatically by WordPress when the plugin is deleted.
 */

// Bail if not called by WordPress uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove active-job transient
delete_transient( 'sdem_active_job' );

// Remove log directory
$log_dir = WP_CONTENT_DIR . '/sdem-logs/';
if ( is_dir( $log_dir ) ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $log_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			@rmdir( $file->getRealPath() );
		} else {
			@unlink( $file->getRealPath() );
		}
	}
	@rmdir( $log_dir );
}
