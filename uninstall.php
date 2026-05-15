<?php
/**
 * Uninstall — removes every trace of the plugin from the database.
 *
 * Fired by WordPress when the user clicks "Delete" on the plugin row in wp-admin.
 *
 * @package Aladdin_Evergreen_Grid
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop every transient we created (DB-backed + timeouts).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_aeg_grid_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_aeg_grid_' ) . '%',
		$wpdb->esc_like( '_transient_aeg_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_aeg_rl_' ) . '%'
	)
);

// Clean up other options we set.
delete_option( 'aeg_cache_gen' );
delete_option( 'aeg_demo_seeded' );

// Flush persistent object cache for our group (Redis/Memcached on Kinsta).
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'aeg_grid' );
}
