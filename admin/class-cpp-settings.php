<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPP_Settings {

    const OPTION_KEY = 'cpp_settings';

    /** @var string Settings page hook suffix */
    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 25 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* --- Menu --- */

    public function add_menu() {
        // When licensed, premium code adds the calendar page at position 25.
        // Settings becomes a submenu under it (premium handles that).
        // When NOT licensed, settings IS the top-level menu.
        if ( ! cpp_is_licensed() ) {
            $this->hook = add_menu_page(
                'Content Planner Pro',
                'Content Planner',
                CPP_CAPABILITY,
                'cpp-settings',
                [ $this, 'render' ],
                'dashicons-calendar-alt',
                4
            );
        } else {
            // When licensed, premium adds the calendar as top-level menu.
            // Settings becomes a submenu under it.
            $this->hook = add_submenu_page(
                'cpp-calendar',
                __( 'Settings', 'content-planner-pro' ),
                __( 'Settings', 'content-planner-pro' ),
                CPP_CAPABILITY,
                'cpp-settings',
                [ $this, 'render' ]
            );
        }
    }

    /* --- Register --- */

    public function register_settings() {
        register_setting( 'cpp_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize' ],
        ] );

        add_filter( 'allowed_options', function ( $allowed ) {
            $allowed['cpp_settings_group'] = [ 'cpp_settings' ];
            return $allowed;
        } );
    }

    /* --- Sanitize --- */

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];
        $clean = [];
        $defaults = cpp_settings_defaults();

        // Post types
        $clean['post_types'] = [];
        if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
            foreach ( $input['post_types'] as $pt ) {
                $clean['post_types'][] = sanitize_key( $pt );
            }
        }
        if ( empty( $clean['post_types'] ) ) {
            $clean['post_types'] = [ 'post' ];
        }

        // Default view
        $clean['default_view'] = in_array( $input['default_view'] ?? '', [ 'calendar', 'board' ], true )
            ? $input['default_view']
            : 'calendar';

        // First day
        $clean['first_day'] = in_array( $input['first_day'] ?? '', [ '0', '1' ], true )
            ? $input['first_day']
            : '1';

        // Show published
        $clean['show_published'] = empty( $input['show_published'] ) ? '0' : '1';

        // Statuses — keep defaults for v1
        $clean['statuses'] = $defaults['statuses'];

        return $clean;
    }

    /* --- Assets --- */

    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_style( 'cpp-admin', CPP_URL . 'admin/css/cpp-admin.css', [], CPP_VERSION );
        wp_enqueue_script( 'cpp-admin', CPP_URL . 'admin/js/cpp-admin.js', [ 'jquery' ], CPP_VERSION, true );
    }

    /* --- Render --- */

    public function render() {
        if ( ! current_user_can( CPP_CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'content-planner-pro' ) );
        }

        $licensed      = cpp_is_licensed();
        $license_key   = get_option( 'cpp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = cpp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );

        $tabs = [
            'license'  => [ 'label' => __( 'License', 'content-planner-pro' ),       'icon' => 'dashicons-lock' ],
            'general'  => [ 'label' => __( 'General', 'content-planner-pro' ),       'icon' => 'dashicons-admin-settings' ],
            'statuses' => [ 'label' => __( 'Statuses', 'content-planner-pro' ),       'icon' => 'dashicons-tag' ],
            'docs'     => [ 'label' => __( 'Documentation', 'content-planner-pro' ), 'icon' => 'dashicons-book' ],
        ];

        // Only show non-license tabs when licensed
        if ( ! $licensed ) {
            $tabs = [ 'license' => $tabs['license'] ];
        }

        $nonce = wp_create_nonce( 'cpp_license_nonce' );

        // Get all public post types for checkboxes
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $post_types['attachment'] );
        ?>
        <style>
        /* -- Layout -- */
        #cpp-settings-wrap { max-width: 1140px; margin-top: 20px; }
        .cpp-settings-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .cpp-settings-header h1 { margin: 0; font-size: 1.6rem; font-weight: 800; color: #1d2327; }
        .cpp-settings-version { background: #f0f0f1; color: #787c82; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .cpp-settings-layout { display: grid; grid-template-columns: 220px 1fr; gap: 0; min-height: 600px; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; background: #f6f7f7; }

        /* -- Sidebar -- */
        .cpp-settings-sidebar { background: #1d2327; padding: 12px 0; display: flex; flex-direction: column; }
        .cpp-sidebar-item { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: #bbc8d4; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 120ms; border-left: 3px solid transparent; cursor: pointer; }
        .cpp-sidebar-item:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .cpp-sidebar-item:focus { color: #fff; box-shadow: none; outline: none; }
        .cpp-sidebar-item.is-active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #ffc45e; }
        .cpp-sidebar-item .dashicons { font-size: 16px; width: 16px; height: 16px; opacity: 0.65; }
        .cpp-sidebar-item.is-active .dashicons { opacity: 1; color: #ffc45e; }

        /* -- Panel -- */
        .cpp-settings-panel { background: #fff; padding: 28px 32px; overflow-y: auto; }
        .cpp-tab-content { display: none; }
        .cpp-tab-content.is-active { display: block; animation: cppFadeIn 200ms ease; }
        @keyframes cppFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* -- Sections -- */
        .cpp-admin-section { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px 28px; margin: 0 0 20px; }
        .cpp-admin-section h2 { margin: 0 0 16px; padding: 0 0 12px; border-bottom: 1px solid #e5e7eb; font-size: 1.05em; font-weight: 700; color: #1d2327; }
        .cpp-admin-section .form-table th { font-weight: 600; color: #374151; padding-top: 16px; }
        .cpp-admin-section .form-table td { padding-top: 12px; }

        /* -- Submit button -- */
        .cpp-settings-panel .submit { margin-top: 8px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .cpp-settings-panel #submit { background: #1d2327; border-color: #1d2327; color: #fff; border-radius: 6px; padding: 6px 24px; font-weight: 600; transition: background 120ms; }
        .cpp-settings-panel #submit:hover { background: #2c3338; }

        /* -- License card -- */
        .cpp-license-card { max-width: 600px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 30px; }
        .cpp-license-active { display: inline-block; background: #00a32a; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }
        .cpp-license-inactive { display: inline-block; background: #dba617; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }

        /* -- Responsive -- */
        @media (max-width: 960px) {
            .cpp-settings-layout { grid-template-columns: 1fr; }
            .cpp-settings-sidebar { flex-direction: row; flex-wrap: wrap; padding: 8px; gap: 4px; }
            .cpp-sidebar-item { padding: 8px 12px; border-left: none; border-bottom: 2px solid transparent; font-size: 12px; }
            .cpp-sidebar-item.is-active { border-left: none; border-bottom-color: #ffc45e; }
            .cpp-sidebar-item .dashicons { display: none; }
            .cpp-settings-panel { padding: 20px 16px; }
        }
        </style>

        <div id="cpp-settings-wrap" class="wrap">

            <!-- Header -->
            <div class="cpp-settings-header">
                <h1>Content Planner Pro</h1>
                <span class="cpp-settings-version">v<?php echo esc_html( CPP_VERSION ); ?></span>
            </div>

            <div class="cpp-settings-layout">

                <!-- Sidebar -->
                <nav class="cpp-settings-sidebar">
                    <?php foreach ( $tabs as $slug => $tab ) : ?>
                        <a href="#<?php echo esc_attr( $slug ); ?>" class="cpp-sidebar-item" data-tab="<?php echo esc_attr( $slug ); ?>">
                            <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                            <?php echo esc_html( $tab['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Panel -->
                <div class="cpp-settings-panel">

                    <!-- License Tab -->
                    <div id="cpp-tab-license" class="cpp-tab-content">
                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'License', 'content-planner-pro' ); ?></h2>
                            <div class="cpp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="cpp-license-active">&#10003; <?php esc_html_e( 'License Active', 'content-planner-pro' ); ?></span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th><?php esc_html_e( 'License key', 'content-planner-pro' ); ?></th>
                                            <td><?php
$masked = substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4);
?><code style="font-size:14px;"><?php echo esc_html($masked); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Domain', 'content-planner-pro' ); ?></th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Expiration', 'content-planner-pro' ); ?></th>
                                            <td>
                                                <?php
                                                $expires = get_option( 'cpp_license_expires_at', '' );
                                                if ( $expires ) {
                                                    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
                                                    $date_formatted = wp_date( 'd F Y', strtotime( $expires ) );
                                                    if ( $days <= 0 ) {
                                                        echo '<span style="color:#dc2626;font-weight:600;">';
                                                        /* translators: %s: formatted expiration date */
                                                        printf( esc_html__( 'Expired on %s', 'content-planner-pro' ), esc_html( $date_formatted ) );
                                                        echo '</span>';
                                                    } elseif ( $days <= 30 ) {
                                                        echo '<span style="color:#d97706;font-weight:600;">';
                                                        /* translators: 1: formatted date, 2: number of days remaining */
                                                        printf(
                                                            esc_html( _n( '%1$s (%2$d day remaining)', '%1$s (%2$d days remaining)', $days, 'content-planner-pro' ) ),
                                                            esc_html( $date_formatted ),
                                                            $days
                                                        );
                                                        echo '</span>';
                                                    } else {
                                                        echo '<span style="color:#16a34a;">';
                                                        /* translators: 1: formatted date, 2: number of days remaining */
                                                        printf(
                                                            esc_html( _n( '%1$s (%2$d day remaining)', '%1$s (%2$d days remaining)', $days, 'content-planner-pro' ) ),
                                                            esc_html( $date_formatted ),
                                                            $days
                                                        );
                                                        echo '</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#16a34a;">' . esc_html__( 'Lifetime (no expiration)', 'content-planner-pro' ) . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="cpp-deactivate-btn" class="button button-secondary" style="color:#d63638;"><?php esc_html_e( 'Deactivate license', 'content-planner-pro' ); ?></button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;"><?php esc_html_e( 'Activate your license', 'content-planner-pro' ); ?></h2>
                                    <p><?php esc_html_e( 'Enter your license key to activate Content Planner Pro.', 'content-planner-pro' ); ?></p>
                                    <p>
                                        <input type="text" id="cpp-license-key" placeholder="CPP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="cpp-activate-btn" class="button button-primary button-hero" style="width:100%;"><?php esc_html_e( 'Activate license', 'content-planner-pro' ); ?></button>
                                    </p>
                                    <div id="cpp-license-message" style="margin-top:15px;display:none;"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ( $licensed ) : ?>

                    <!-- Form wraps General + Statuts -->
                    <form method="post" action="options.php" id="cpp-settings-form">
                        <?php settings_fields( 'cpp_settings_group' ); ?>
                        <input type="hidden" id="cpp_active_tab" name="cpp_active_tab" value="">

                        <!-- General Tab -->
                        <div id="cpp-tab-general" class="cpp-tab-content">
                            <div class="cpp-admin-section">
                                <h2><?php esc_html_e( 'General settings', 'content-planner-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Content types', 'content-planner-pro' ); ?></th>
                                        <td>
                                            <?php foreach ( $post_types as $pt_slug => $pt_obj ) : ?>
                                                <label style="display:block;margin-bottom:6px;">
                                                    <input type="checkbox" name="cpp_settings[post_types][]" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( in_array( $pt_slug, (array) $s['post_types'], true ) ); ?>>
                                                    <?php echo esc_html( $pt_obj->labels->name ); ?> <code style="font-size:11px;color:#9ca3af;">(<?php echo esc_html( $pt_slug ); ?>)</code>
                                                </label>
                                            <?php endforeach; ?>
                                            <p class="description"><?php esc_html_e( 'Select the content types to display in the calendar.', 'content-planner-pro' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Default view', 'content-planner-pro' ); ?></th>
                                        <td>
                                            <label style="display:block;margin-bottom:6px;">
                                                <input type="radio" name="cpp_settings[default_view]" value="calendar" <?php checked( $s['default_view'], 'calendar' ); ?>>
                                                <?php esc_html_e( 'Calendar', 'content-planner-pro' ); ?>
                                            </label>
                                            <label style="display:block;">
                                                <input type="radio" name="cpp_settings[default_view]" value="board" <?php checked( $s['default_view'], 'board' ); ?>>
                                                <?php esc_html_e( 'Board (Kanban)', 'content-planner-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'First day of the week', 'content-planner-pro' ); ?></th>
                                        <td>
                                            <select name="cpp_settings[first_day]">
                                                <option value="0" <?php selected( $s['first_day'], '0' ); ?>><?php esc_html_e( 'Sunday', 'content-planner-pro' ); ?></option>
                                                <option value="1" <?php selected( $s['first_day'], '1' ); ?>><?php esc_html_e( 'Monday', 'content-planner-pro' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Show published', 'content-planner-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="cpp_settings[show_published]" value="1" <?php checked( $s['show_published'], '1' ); ?>>
                                                <?php esc_html_e( 'Show already published content in the calendar', 'content-planner-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( __( 'Save', 'content-planner-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Statuses Tab -->
                        <div id="cpp-tab-statuses" class="cpp-tab-content">
                            <div class="cpp-admin-section">
                                <h2><?php esc_html_e( 'Editorial statuses', 'content-planner-pro' ); ?></h2>
                                <p style="color:#6b7280;margin-bottom:16px;"><?php esc_html_e( 'Editorial statuses allow you to track the progress of your content. Each status maps to a native WordPress status.', 'content-planner-pro' ); ?></p>

                                <table class="widefat striped cpp-statuses-table" style="max-width:700px;">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;"></th>
                                            <th><?php esc_html_e( 'Icon', 'content-planner-pro' ); ?></th>
                                            <th><?php esc_html_e( 'Label', 'content-planner-pro' ); ?></th>
                                            <th><?php esc_html_e( 'Slug', 'content-planner-pro' ); ?></th>
                                            <th><?php esc_html_e( 'WordPress status', 'content-planner-pro' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $s['statuses'] as $status ) : ?>
                                            <tr>
                                                <td>
                                                    <span class="cpp-status-dot" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $status['color'] ); ?>;"></span>
                                                </td>
                                                <td><?php echo esc_html( $status['icon'] ); ?></td>
                                                <td><strong><?php echo esc_html( $status['label'] ); ?></strong></td>
                                                <td><code><?php echo esc_html( $status['slug'] ); ?></code></td>
                                                <td><code><?php echo esc_html( $status['wp_status'] ); ?></code></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <p style="color:#9ca3af;margin-top:16px;font-size:13px;">
                                    <?php esc_html_e( 'Statuses are not editable in this version. A future update will allow customizing statuses, colors, and icons.', 'content-planner-pro' ); ?>
                                </p>
                            </div>
                        </div>

                    </form>

                    <!-- Documentation tab (outside the form, no save needed) -->
                    <div id="cpp-tab-docs" class="cpp-tab-content">

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Calendar View', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;line-height:1.8;"><?php esc_html_e( 'The calendar displays your content month by month. Each piece of content appears on the date it is scheduled.', 'content-planner-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php
                                /* translators: %s: navigation arrows symbols */
                                printf( esc_html__( 'Navigate between months using the %s arrows', 'content-planner-pro' ), '<strong>&laquo; / &raquo;</strong>' ); ?></li>
                                <li><?php esc_html_e( 'Click on an empty date to create new content', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Click on content to open the detail / quick edit panel', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Content is color-coded by editorial status', 'content-planner-pro' ); ?></li>
                                <li><?php printf( esc_html__( 'Overdue content (past deadline) is marked in %s', 'content-planner-pro' ), '<span style="color:#dc2626;font-weight:600;">' . esc_html__( 'red', 'content-planner-pro' ) . '</span>' ); ?></li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Board View (Kanban)', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;line-height:1.8;"><?php esc_html_e( 'The board organizes your content into columns by editorial status.', 'content-planner-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php esc_html_e( 'Each column represents a status (Idea, Drafting, Review, Scheduled, Published)', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Drag and drop content between columns to change its status', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'The number of items per column is shown in the header', 'content-planner-pro' ); ?></li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Drag & Drop', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;line-height:1.8;"><?php esc_html_e( 'Drag and drop works in both views:', 'content-planner-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php esc_html_e( 'Calendar: move content to another date to change its publication date', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Board: move content to another column to change its status', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Changes are saved automatically via AJAX', 'content-planner-pro' ); ?></li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Quick Edit', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;line-height:1.8;"><?php esc_html_e( 'Click on content to open the quick edit panel. You can edit:', 'content-planner-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php esc_html_e( 'Content title', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Editorial status (Idea > Drafting > Review > Scheduled > Published)', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Scheduled publication date', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Deadline (writing due date)', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Assigned to (responsible author)', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Category', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Priority (low, normal, high, urgent)', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'Internal notes', 'content-planner-pro' ); ?></li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Content Creation', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;line-height:1.8;"><?php esc_html_e( 'Two ways to create content:', 'content-planner-pro' ); ?></p>
                            <ol style="line-height:2;font-size:14px;color:#374151;">
                                <li><?php esc_html_e( 'From the calendar: click on an empty date. A new draft is created with that date.', 'content-planner-pro' ); ?></li>
                                <li><?php esc_html_e( 'From the board: click the + button in a status column.', 'content-planner-pro' ); ?></li>
                            </ol>
                            <p style="color:#374151;"><?php esc_html_e( 'Content is created as a WordPress draft with the "Idea" editorial status by default.', 'content-planner-pro' ); ?></p>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Editorial Statuses', 'content-planner-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Status', 'content-planner-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Description', 'content-planner-pro' ); ?></th>
                                        <th><?php esc_html_e( 'WordPress status', 'content-planner-pro' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span style="color:#94a3b8;">&#9679;</span> <?php esc_html_e( 'Idea', 'content-planner-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Content in the ideation phase, not yet started', 'content-planner-pro' ); ?></td>
                                        <td><code>draft</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#f59e0b;">&#9679;</span> <?php esc_html_e( 'Drafting', 'content-planner-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Content currently being written', 'content-planner-pro' ); ?></td>
                                        <td><code>draft</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#f97316;">&#9679;</span> <?php esc_html_e( 'Review', 'content-planner-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Content finished, awaiting approval', 'content-planner-pro' ); ?></td>
                                        <td><code>pending</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#3b82f6;">&#9679;</span> <?php esc_html_e( 'Scheduled', 'content-planner-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Content approved and scheduled for publication', 'content-planner-pro' ); ?></td>
                                        <td><code>future</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#22c55e;">&#9679;</span> <?php esc_html_e( 'Published', 'content-planner-pro' ); ?></td>
                                        <td><?php esc_html_e( 'Content published and visible on the site', 'content-planner-pro' ); ?></td>
                                        <td><code>publish</code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cpp-admin-section">
                            <h2><?php esc_html_e( 'Keyboard Shortcuts', 'content-planner-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:500px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Shortcut', 'content-planner-pro' ); ?></th>
                                        <th><?php esc_html_e( 'Action', 'content-planner-pro' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>&larr;</kbd></td><td><?php esc_html_e( 'Previous month', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>&rarr;</kbd></td><td><?php esc_html_e( 'Next month', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>T</kbd></td><td><?php esc_html_e( 'Go to current month (Today)', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>N</kbd></td><td><?php esc_html_e( 'New content', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>Esc</kbd></td><td><?php esc_html_e( 'Close edit panel', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>1</kbd></td><td><?php esc_html_e( 'Calendar view', 'content-planner-pro' ); ?></td></tr>
                                    <tr><td><kbd>2</kbd></td><td><?php esc_html_e( 'Board view', 'content-planner-pro' ); ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cpp-admin-section" style="background:#fefce8;border-color:#fde68a;">
                            <h2 style="border-color:#fde68a;"><?php esc_html_e( 'Support', 'content-planner-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'For any questions or issues:', 'content-planner-pro' ); ?></p>
                            <ul style="list-style:none;padding:0;line-height:2.2;">
                                <li><?php /* translators: %s: support email link */ printf( esc_html__( 'Email: %s', 'content-planner-pro' ), '<a href="mailto:contact@khalid.digital">contact@khalid.digital</a>' ); ?></li>
                            </ul>
                        </div>

                    </div>

                    <?php endif; ?>

                </div><!-- .cpp-settings-panel -->
            </div><!-- .cpp-settings-layout -->
        </div><!-- #cpp-settings-wrap -->

        <script>
        jQuery(function($) {
            /* -- Tab switching -- */
            var $items = $('.cpp-sidebar-item');
            var $tabs  = $('.cpp-tab-content');

            function activateTab(slug) {
                $items.removeClass('is-active');
                $tabs.removeClass('is-active');
                $items.filter('[data-tab="' + slug + '"]').addClass('is-active');
                $('#cpp-tab-' + slug).addClass('is-active');
                $('#cpp_active_tab').val(slug);
                if (history.replaceState) {
                    history.replaceState(null, null, '#' + slug);
                }
            }

            $items.on('click', function(e) {
                e.preventDefault();
                activateTab($(this).data('tab'));
            });

            // Determine initial tab
            var hash = window.location.hash.replace('#', '');
            var validTabs = [];
            $items.each(function() { validTabs.push($(this).data('tab')); });

            if (hash && validTabs.indexOf(hash) !== -1) {
                activateTab(hash);
            } else {
                activateTab(validTabs[0] || 'license');
            }

            /* -- License AJAX -- */
            var licenseNonce = '<?php echo esc_js( $nonce ); ?>';

            $('#cpp-activate-btn').on('click', function() {
                var btn = $(this);
                var key = $('#cpp-license-key').val().trim();
                if (!key) return;

                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Activating...', 'content-planner-pro' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'cpp_activate_license',
                    nonce: licenseNonce,
                    license_key: key
                }, function(response) {
                    if (response.success) {
                        $('#cpp-license-message').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>').show();
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        $('#cpp-license-message').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>').show();
                        btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate license', 'content-planner-pro' ) ); ?>');
                    }
                }).fail(function() {
                    $('#cpp-license-message').html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Connection error.', 'content-planner-pro' ) ); ?></p></div>').show();
                    btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate license', 'content-planner-pro' ) ); ?>');
                });
            });

            $('#cpp-deactivate-btn').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Deactivate the license on this domain?', 'content-planner-pro' ) ); ?>')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Deactivating...', 'content-planner-pro' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'cpp_deactivate_license',
                    nonce: licenseNonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
    }
}

/* --- Defaults --- */

function cpp_settings_defaults() {
    return [
        'post_types'       => ['post'],
        'default_view'     => 'calendar',
        'first_day'        => '1',
        'show_published'   => '1',
        'statuses'         => [
            ['slug' => 'idea',      'label' => __( 'Idea', 'content-planner-pro' ),      'color' => '#94a3b8', 'icon' => "\xF0\x9F\x92\xA1", 'wp_status' => 'draft'],
            ['slug' => 'drafting',  'label' => __( 'Drafting', 'content-planner-pro' ),  'color' => '#f59e0b', 'icon' => "\xE2\x9C\x8F\xEF\xB8\x8F",  'wp_status' => 'draft'],
            ['slug' => 'review',    'label' => __( 'Review', 'content-planner-pro' ),    'color' => '#f97316', 'icon' => "\xF0\x9F\x91\x81\xEF\xB8\x8F",  'wp_status' => 'pending'],
            ['slug' => 'scheduled', 'label' => __( 'Scheduled', 'content-planner-pro' ), 'color' => '#3b82f6', 'icon' => "\xF0\x9F\x93\x85", 'wp_status' => 'future'],
            ['slug' => 'published', 'label' => __( 'Published', 'content-planner-pro' ), 'color' => '#22c55e', 'icon' => "\xE2\x9C\x85", 'wp_status' => 'publish'],
        ],
    ];
}

/* --- Helper --- */

function cpp_get_setting( $key ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( CPP_Settings::OPTION_KEY, [] );
    }
    $defaults = cpp_settings_defaults();
    return isset( $settings[ $key ] ) && $settings[ $key ] !== '' ? $settings[ $key ] : ( $defaults[ $key ] ?? '' );
}
