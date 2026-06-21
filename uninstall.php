<?php
/**
 * Content Planner Pro — Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ─── Options ─────────────────────────────────────────────────────────────────

delete_option( 'cpp_settings' );
delete_option( 'cpp_license_key' );
delete_option( 'cpp_license_status' );
delete_option( 'cpp_license_domain' );
delete_option( 'cpp_license_expires_at' );
delete_option( 'cpp_premium_files' );

// ─── Transients ──────────────────────────────────────────────────────────────

delete_transient( 'cpp_license_valid' );
delete_transient( 'cpp_license_attempts' );
delete_transient( 'cpp_premium_fresh' );

// ─── Post Meta ───────────────────────────────────────────────────────────────

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_cpp_%'" );

// ─── Capability ──────────────────────────────────────────────────────────────

$roles = wp_roles();
foreach ( $roles->role_objects as $role ) {
    if ( $role->has_cap( 'manage_cpp' ) ) {
        $role->remove_cap( 'manage_cpp' );
    }
}

// ─── Crons ───────────────────────────────────────────────────────────────────

wp_clear_scheduled_hook( 'cpp_validate_license_cron' );
