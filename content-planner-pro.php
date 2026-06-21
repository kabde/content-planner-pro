<?php
/**
 * Plugin Name: Content Planner Pro
 * Description: Editorial calendar, content board, and workflow management for WordPress.
 * Version:     2.0.0
 * Author:      Abderrahim KHALID
 * Text Domain: content-planner-pro
 * Network:     true
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CPP_VERSION', '2.0.0' );
define( 'CPP_FILE', __FILE__ );
define( 'CPP_BASENAME', plugin_basename( __FILE__ ) );
define( 'CPP_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPP_URL',  plugin_dir_url( __FILE__ ) );
define( 'CPP_CAPABILITY', 'manage_cpp' );
define( 'CPP_API_URL', 'https://dp-starter.khalid.digital' );

// License system FIRST
require_once CPP_PATH . 'inc/license.php';

// Settings page (always loaded — includes license tab)
require_once CPP_PATH . 'admin/class-cpp-settings.php';
new CPP_Settings();

// AJAX handlers (always loaded — local code)
require_once CPP_PATH . 'admin/class-cpp-ajax.php';
new CPP_Ajax();

// Only load premium code if licensed
if ( cpp_is_licensed() ) {
    cpp_load_premium_code();
}

// ─── Sync editorial status → WP post_status on every save ───────────────────

function cpp_sync_status_on_save( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $editorial = get_post_meta( $post_id, '_cpp_editorial_status', true );
    if ( ! $editorial ) return;

    $wp_status = function_exists( 'cpp_editorial_to_wp_status' ) ? cpp_editorial_to_wp_status( $editorial ) : '';
    if ( ! $wp_status ) return;

    $current = get_post_status( $post_id );
    if ( $current !== $wp_status && $current !== 'trash' ) {
        remove_action( 'save_post', 'cpp_sync_status_on_save' );
        wp_update_post( [ 'ID' => $post_id, 'post_status' => $wp_status ] );
        add_action( 'save_post', 'cpp_sync_status_on_save' );
    }
}
add_action( 'save_post', 'cpp_sync_status_on_save' );

// ─── Activation ──────────────────────────────────────────────────────────────

function cpp_add_caps_for_blog() {
    $role = get_role( 'administrator' );
    if ( ! $role ) return;
    $role->add_cap( CPP_CAPABILITY );
}

function cpp_activate( $network_wide = false ) {
    if ( is_multisite() && $network_wide ) {
        $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            cpp_add_caps_for_blog();
            restore_current_blog();
        }
    } else {
        cpp_add_caps_for_blog();
    }

    // Initialize settings defaults if not set
    if ( function_exists( 'cpp_settings_defaults' ) ) {
        $defaults = cpp_settings_defaults();
        $current  = get_option( 'cpp_settings', [] );
        if ( empty( $current ) ) {
            update_option( 'cpp_settings', $defaults );
        }
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cpp_activate' );

function cpp_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cpp_deactivate' );

function cpp_add_caps_on_new_blog( $blog_id ) {
    if ( ! is_multisite() ) return;
    switch_to_blog( $blog_id );
    cpp_add_caps_for_blog();
    restore_current_blog();
}
add_action( 'wpmu_new_blog', 'cpp_add_caps_on_new_blog' );

function cpp_maybe_add_caps() {
    $role = get_role( 'administrator' );
    if ( $role && ! $role->has_cap( CPP_CAPABILITY ) ) {
        $role->add_cap( CPP_CAPABILITY );
    }
}
add_action( 'admin_init', 'cpp_maybe_add_caps' );
