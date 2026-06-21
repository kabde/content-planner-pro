<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPP_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_cpp_get_posts',      [ $this, 'get_posts' ] );
        add_action( 'wp_ajax_cpp_create_post',     [ $this, 'create_post' ] );
        add_action( 'wp_ajax_cpp_update_post',     [ $this, 'update_post' ] );
        add_action( 'wp_ajax_cpp_move_post',       [ $this, 'move_post' ] );
        add_action( 'wp_ajax_cpp_change_status',   [ $this, 'change_status' ] );
    }

    /* ─── Get Posts ──────────────────────────────────────────────────────────── */

    public function get_posts() {
        check_ajax_referer( 'cpp_nonce', 'nonce' );
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_send_json_error( 'Permission refusée.' );
        }

        $year  = absint( $_GET['year']  ?? date( 'Y' ) );
        $month = absint( $_GET['month'] ?? date( 'n' ) );

        $settings   = get_option( 'cpp_settings', [] );
        $defaults   = cpp_settings_defaults();
        $s          = wp_parse_args( $settings, $defaults );
        $post_types = ! empty( $_GET['post_type'] )
            ? [ sanitize_key( $_GET['post_type'] ) ]
            : (array) $s['post_types'];

        $statuses = [ 'draft', 'pending', 'future', 'publish' ];

        $args = [
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'date_query'     => [
                [
                    'year'  => $year,
                    'month' => $month,
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'ASC',
        ];

        $posts  = get_posts( $args );
        $result = [];

        foreach ( $posts as $post ) {
            $result[] = cpp_format_post( $post );
        }

        wp_send_json_success( $result );
    }

    /* ─── Create Post ────────────────────────────────────────────────────────── */

    public function create_post() {
        check_ajax_referer( 'cpp_nonce', 'nonce' );
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_send_json_error( 'Permission refusée.' );
        }

        $title            = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $date             = sanitize_text_field( $_POST['date'] ?? '' );
        $editorial_status = sanitize_key( $_POST['editorial_status'] ?? 'idea' );

        if ( empty( $title ) ) {
            wp_send_json_error( 'Le titre est requis.' );
        }

        // Validate date format
        if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
            wp_send_json_error( 'Format de date invalide.' );
        }

        // Ensure we have a full datetime
        if ( $date && strlen( $date ) === 10 ) {
            $date .= ' 09:00:00';
        }

        $post_data = [
            'post_title'  => $title,
            'post_status' => 'draft',
            'post_type'   => 'post',
        ];

        if ( $date ) {
            $post_data['post_date']     = $date;
            $post_data['post_date_gmt'] = get_gmt_from_date( $date );
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( $post_id->get_error_message() );
        }

        update_post_meta( $post_id, '_cpp_editorial_status', $editorial_status );
        if ( $date ) {
            update_post_meta( $post_id, '_cpp_planned_date', substr( $date, 0, 10 ) );
        }

        $post = get_post( $post_id );
        wp_send_json_success( cpp_format_post( $post ) );
    }

    /* ─── Update Post ────────────────────────────────────────────────────────── */

    public function update_post() {
        check_ajax_referer( 'cpp_nonce', 'nonce' );
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_send_json_error( 'Permission refusée.' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( 'Contenu introuvable.' );
        }

        $post_data = [ 'ID' => $post_id ];

        // Title
        if ( isset( $_POST['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
        }

        // Editorial status
        if ( isset( $_POST['editorial_status'] ) ) {
            $editorial_status = sanitize_key( $_POST['editorial_status'] );
            update_post_meta( $post_id, '_cpp_editorial_status', $editorial_status );

            // Sync to WP post_status
            $wp_status = cpp_editorial_to_wp_status( $editorial_status );
            if ( $wp_status ) {
                $post_data['post_status'] = $wp_status;
            }
        }

        // Planned date
        if ( isset( $_POST['planned_date'] ) ) {
            $planned_date = sanitize_text_field( $_POST['planned_date'] );
            update_post_meta( $post_id, '_cpp_planned_date', $planned_date );

            if ( $planned_date ) {
                $datetime = $planned_date;
                if ( strlen( $datetime ) === 10 ) {
                    // Preserve existing time
                    $existing = get_post( $post_id );
                    $existing_time = date( 'H:i:s', strtotime( $existing->post_date ) );
                    $datetime .= ' ' . $existing_time;
                }
                $post_data['post_date']     = $datetime;
                $post_data['post_date_gmt'] = get_gmt_from_date( $datetime );
            }
        }

        // Deadline
        if ( isset( $_POST['deadline'] ) ) {
            $deadline = sanitize_text_field( $_POST['deadline'] );
            update_post_meta( $post_id, '_cpp_deadline', $deadline );
        }

        // Assignee
        if ( isset( $_POST['assignee'] ) ) {
            $assignee = absint( $_POST['assignee'] );
            if ( $assignee ) {
                update_post_meta( $post_id, '_cpp_assignee', $assignee );
                $post_data['post_author'] = $assignee;
            } else {
                delete_post_meta( $post_id, '_cpp_assignee' );
            }
        }

        // Category
        if ( isset( $_POST['category_id'] ) ) {
            $cat_id = absint( $_POST['category_id'] );
            if ( $cat_id ) {
                wp_set_post_categories( $post_id, [ $cat_id ] );
            }
        }

        // Notes
        if ( isset( $_POST['notes'] ) ) {
            $notes = sanitize_textarea_field( wp_unslash( $_POST['notes'] ) );
            update_post_meta( $post_id, '_cpp_notes', $notes );
        }

        // Priority
        if ( isset( $_POST['priority'] ) ) {
            $priority = sanitize_key( $_POST['priority'] );
            if ( in_array( $priority, [ 'low', 'normal', 'high', 'urgent' ], true ) ) {
                update_post_meta( $post_id, '_cpp_priority', $priority );
            }
        }

        wp_update_post( $post_data );

        $post = get_post( $post_id );
        wp_send_json_success( cpp_format_post( $post ) );
    }

    /* ─── Move Post (drag & drop in calendar) ────────────────────────────────── */

    public function move_post() {
        check_ajax_referer( 'cpp_nonce', 'nonce' );
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_send_json_error( 'Permission refusée.' );
        }

        $post_id  = absint( $_POST['post_id'] ?? 0 );
        $new_date = sanitize_text_field( $_POST['new_date'] ?? '' );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( 'Contenu introuvable.' );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
            wp_send_json_error( 'Format de date invalide.' );
        }

        // Preserve existing time
        $existing      = get_post( $post_id );
        $existing_time = date( 'H:i:s', strtotime( $existing->post_date ) );
        $datetime      = $new_date . ' ' . $existing_time;

        wp_update_post( [
            'ID'            => $post_id,
            'post_date'     => $datetime,
            'post_date_gmt' => get_gmt_from_date( $datetime ),
        ] );

        update_post_meta( $post_id, '_cpp_planned_date', $new_date );

        wp_send_json_success( [ 'post_id' => $post_id, 'new_date' => $new_date ] );
    }

    /* ─── Change Status ──────────────────────────────────────────────────────── */

    public function change_status() {
        check_ajax_referer( 'cpp_nonce', 'nonce' );
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_send_json_error( 'Permission refusée.' );
        }

        $post_id    = absint( $_POST['post_id'] ?? 0 );
        $new_status = sanitize_key( $_POST['new_status'] ?? '' );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( 'Contenu introuvable.' );
        }

        if ( empty( $new_status ) ) {
            wp_send_json_error( 'Statut requis.' );
        }

        update_post_meta( $post_id, '_cpp_editorial_status', $new_status );

        // Sync to WP post_status
        $wp_status = cpp_editorial_to_wp_status( $new_status );
        if ( $wp_status ) {
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => $wp_status,
            ] );
        }

        $post = get_post( $post_id );
        wp_send_json_success( cpp_format_post( $post ) );
    }
}

/* ─── Helpers ────────────────────────────────────────────────────────────────── */

/**
 * Format a WP_Post into a clean JSON-ready array for the calendar/board UI.
 *
 * @param  WP_Post $post
 * @return array
 */
function cpp_format_post( $post ) {
    $post_id          = $post->ID;
    $editorial_status = get_post_meta( $post_id, '_cpp_editorial_status', true ) ?: 'idea';
    $planned_date     = get_post_meta( $post_id, '_cpp_planned_date', true ) ?: date( 'Y-m-d', strtotime( $post->post_date ) );
    $deadline         = get_post_meta( $post_id, '_cpp_deadline', true ) ?: '';
    $assignee_id      = get_post_meta( $post_id, '_cpp_assignee', true ) ?: $post->post_author;
    $notes            = get_post_meta( $post_id, '_cpp_notes', true ) ?: '';
    $priority         = get_post_meta( $post_id, '_cpp_priority', true ) ?: 'normal';

    // Assignee info
    $assignee_data = [
        'id'         => 0,
        'name'       => '',
        'avatar_url' => '',
    ];
    if ( $assignee_id ) {
        $user = get_userdata( $assignee_id );
        if ( $user ) {
            $assignee_data = [
                'id'         => (int) $user->ID,
                'name'       => $user->display_name,
                'avatar_url' => get_avatar_url( $user->ID, [ 'size' => 32 ] ),
            ];
        }
    }

    // Categories
    $categories = [];
    $terms = get_the_terms( $post_id, 'category' );
    if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            $categories[] = [
                'id'   => $term->term_id,
                'name' => $term->name,
            ];
        }
    }

    // Color from editorial status
    $color    = '#94a3b8'; // default grey
    $settings = get_option( 'cpp_settings', [] );
    $defaults = cpp_settings_defaults();
    $s        = wp_parse_args( $settings, $defaults );
    foreach ( $s['statuses'] as $status ) {
        if ( $status['slug'] === $editorial_status ) {
            $color = $status['color'];
            break;
        }
    }

    // Is overdue?
    $is_overdue = false;
    if ( $deadline && $editorial_status !== 'published' ) {
        $is_overdue = strtotime( $deadline ) < time();
    }

    return [
        'id'                => $post_id,
        'title'             => get_the_title( $post_id ),
        'wp_status'         => $post->post_status,
        'editorial_status'  => $editorial_status,
        'planned_date'      => $planned_date,
        'deadline'          => $deadline,
        'assignee'          => $assignee_data,
        'categories'        => $categories,
        'priority'          => $priority,
        'notes'             => $notes,
        'color'             => $color,
        'edit_url'          => get_edit_post_link( $post_id, 'raw' ),
        'is_overdue'        => $is_overdue,
    ];
}

/**
 * Map editorial status slug to WordPress post_status.
 *
 * @param  string $editorial_status
 * @return string|null
 */
function cpp_editorial_to_wp_status( $editorial_status ) {
    $settings = get_option( 'cpp_settings', [] );
    $defaults = cpp_settings_defaults();
    $s        = wp_parse_args( $settings, $defaults );

    foreach ( $s['statuses'] as $status ) {
        if ( $status['slug'] === $editorial_status ) {
            return $status['wp_status'];
        }
    }

    return null;
}
