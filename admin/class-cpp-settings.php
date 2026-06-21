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
                'Settings',
                'Settings',
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
            wp_die( 'You do not have sufficient permissions.' );
        }

        $licensed      = cpp_is_licensed();
        $license_key   = get_option( 'cpp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = cpp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );

        $tabs = [
            'license'  => [ 'label' => 'Licence',       'icon' => 'dashicons-lock' ],
            'general'  => [ 'label' => 'Général',       'icon' => 'dashicons-admin-settings' ],
            'statuses' => [ 'label' => 'Statuts',       'icon' => 'dashicons-tag' ],
            'docs'     => [ 'label' => 'Documentation', 'icon' => 'dashicons-book' ],
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
                            <h2>Licence</h2>
                            <div class="cpp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="cpp-license-active">&#10003; Licence Active</span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th>Cl&eacute; de licence</th>
                                            <td><?php
$masked = substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4);
?><code style="font-size:14px;"><?php echo esc_html($masked); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th>Domaine</th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Expiration</th>
                                            <td>
                                                <?php
                                                $expires = get_option( 'cpp_license_expires_at', '' );
                                                if ( $expires ) {
                                                    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
                                                    $date_formatted = wp_date( 'd F Y', strtotime( $expires ) );
                                                    if ( $days <= 0 ) {
                                                        echo '<span style="color:#dc2626;font-weight:600;">Expirée le ' . esc_html( $date_formatted ) . '</span>';
                                                    } elseif ( $days <= 30 ) {
                                                        echo '<span style="color:#d97706;font-weight:600;">' . esc_html( $date_formatted ) . ' (' . $days . ' jour' . ($days > 1 ? 's' : '') . ' restants)</span>';
                                                    } else {
                                                        echo '<span style="color:#16a34a;">' . esc_html( $date_formatted ) . ' (' . $days . ' jours restants)</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#16a34a;">Lifetime (pas d\'expiration)</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="cpp-deactivate-btn" class="button button-secondary" style="color:#d63638;">D&eacute;sactiver la licence</button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;">Activez votre licence</h2>
                                    <p>Entrez votre cl&eacute; de licence pour activer Content Planner Pro.</p>
                                    <p>
                                        <input type="text" id="cpp-license-key" placeholder="CPP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="cpp-activate-btn" class="button button-primary button-hero" style="width:100%;">Activer la licence</button>
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
                                <h2>Param&egrave;tres g&eacute;n&eacute;raux</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Types de contenu</th>
                                        <td>
                                            <?php foreach ( $post_types as $pt_slug => $pt_obj ) : ?>
                                                <label style="display:block;margin-bottom:6px;">
                                                    <input type="checkbox" name="cpp_settings[post_types][]" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( in_array( $pt_slug, (array) $s['post_types'], true ) ); ?>>
                                                    <?php echo esc_html( $pt_obj->labels->name ); ?> <code style="font-size:11px;color:#9ca3af;">(<?php echo esc_html( $pt_slug ); ?>)</code>
                                                </label>
                                            <?php endforeach; ?>
                                            <p class="description">S&eacute;lectionnez les types de contenu &agrave; afficher dans le calendrier.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Vue par d&eacute;faut</th>
                                        <td>
                                            <label style="display:block;margin-bottom:6px;">
                                                <input type="radio" name="cpp_settings[default_view]" value="calendar" <?php checked( $s['default_view'], 'calendar' ); ?>>
                                                Calendrier
                                            </label>
                                            <label style="display:block;">
                                                <input type="radio" name="cpp_settings[default_view]" value="board" <?php checked( $s['default_view'], 'board' ); ?>>
                                                Board (Kanban)
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Premier jour de la semaine</th>
                                        <td>
                                            <select name="cpp_settings[first_day]">
                                                <option value="0" <?php selected( $s['first_day'], '0' ); ?>>Dimanche</option>
                                                <option value="1" <?php selected( $s['first_day'], '1' ); ?>>Lundi</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Afficher les publi&eacute;s</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="cpp_settings[show_published]" value="1" <?php checked( $s['show_published'], '1' ); ?>>
                                                Afficher les contenus d&eacute;j&agrave; publi&eacute;s dans le calendrier
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Statuts Tab -->
                        <div id="cpp-tab-statuses" class="cpp-tab-content">
                            <div class="cpp-admin-section">
                                <h2>Statuts &eacute;ditoriaux</h2>
                                <p style="color:#6b7280;margin-bottom:16px;">Les statuts &eacute;ditoriaux permettent de suivre l'avancement de vos contenus. Chaque statut est mapp&eacute; vers un statut WordPress natif.</p>

                                <table class="widefat striped cpp-statuses-table" style="max-width:700px;">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;"></th>
                                            <th>Ic&ocirc;ne</th>
                                            <th>Label</th>
                                            <th>Slug</th>
                                            <th>Statut WordPress</th>
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
                                    Les statuts ne sont pas modifiables dans cette version. Une future mise &agrave; jour permettra de personnaliser les statuts, couleurs et ic&ocirc;nes.
                                </p>
                            </div>
                        </div>

                    </form>

                    <!-- Documentation tab (outside the form, no save needed) -->
                    <div id="cpp-tab-docs" class="cpp-tab-content">

                        <div class="cpp-admin-section">
                            <h2>Vue Calendrier</h2>
                            <p style="color:#374151;line-height:1.8;">Le calendrier affiche vos contenus mois par mois. Chaque contenu appara&icirc;t sur la date &agrave; laquelle il est planifi&eacute;.</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li>Naviguez entre les mois avec les fl&egrave;ches <strong>&laquo; / &raquo;</strong></li>
                                <li>Cliquez sur une date vide pour <strong>cr&eacute;er un nouveau contenu</strong></li>
                                <li>Cliquez sur un contenu pour ouvrir le <strong>panneau de d&eacute;tail / &eacute;dition rapide</strong></li>
                                <li>Les contenus sont color&eacute;s selon leur <strong>statut &eacute;ditorial</strong></li>
                                <li>Les contenus en retard (deadline d&eacute;pass&eacute;e) sont marqu&eacute;s en <span style="color:#dc2626;font-weight:600;">rouge</span></li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>Vue Board (Kanban)</h2>
                            <p style="color:#374151;line-height:1.8;">Le board organise vos contenus en colonnes selon leur statut &eacute;ditorial.</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li>Chaque colonne repr&eacute;sente un <strong>statut</strong> (Id&eacute;e, R&eacute;daction, Relecture, Planifi&eacute;, Publi&eacute;)</li>
                                <li>Glissez-d&eacute;posez un contenu entre les colonnes pour <strong>changer son statut</strong></li>
                                <li>Le nombre de contenus par colonne est affich&eacute; dans l'en-t&ecirc;te</li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>Drag &amp; Drop</h2>
                            <p style="color:#374151;line-height:1.8;">Le glisser-d&eacute;poser fonctionne dans les deux vues :</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><strong>Calendrier :</strong> d&eacute;placez un contenu vers une autre date pour changer sa date de publication</li>
                                <li><strong>Board :</strong> d&eacute;placez un contenu vers une autre colonne pour changer son statut</li>
                                <li>Les modifications sont <strong>sauvegard&eacute;es automatiquement</strong> via AJAX</li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>&Eacute;dition rapide</h2>
                            <p style="color:#374151;line-height:1.8;">Cliquez sur un contenu pour ouvrir le panneau d'&eacute;dition rapide. Vous pouvez modifier :</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><strong>Titre</strong> du contenu</li>
                                <li><strong>Statut &eacute;ditorial</strong> (Id&eacute;e &rarr; R&eacute;daction &rarr; Relecture &rarr; Planifi&eacute; &rarr; Publi&eacute;)</li>
                                <li><strong>Date planifi&eacute;e</strong> de publication</li>
                                <li><strong>Deadline</strong> (date limite de r&eacute;daction)</li>
                                <li><strong>Assign&eacute;</strong> &agrave; (auteur responsable)</li>
                                <li><strong>Cat&eacute;gorie</strong></li>
                                <li><strong>Priorit&eacute;</strong> (basse, normale, haute, urgente)</li>
                                <li><strong>Notes</strong> internes</li>
                            </ul>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>Cr&eacute;ation de contenu</h2>
                            <p style="color:#374151;line-height:1.8;">Deux fa&ccedil;ons de cr&eacute;er du contenu :</p>
                            <ol style="line-height:2;font-size:14px;color:#374151;">
                                <li><strong>Depuis le calendrier :</strong> cliquez sur une date vide. Un nouveau brouillon est cr&eacute;&eacute; avec cette date.</li>
                                <li><strong>Depuis le board :</strong> cliquez sur le bouton <strong>+</strong> dans une colonne de statut.</li>
                            </ol>
                            <p style="color:#374151;">Le contenu est cr&eacute;&eacute; comme <strong>brouillon WordPress</strong> avec le statut &eacute;ditorial &laquo; Id&eacute;e &raquo; par d&eacute;faut.</p>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>Statuts &eacute;ditoriaux</h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead>
                                    <tr><th>Statut</th><th>Description</th><th>Statut WordPress</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span style="color:#94a3b8;">&#9679;</span> Id&eacute;e</td>
                                        <td>Contenu en phase d'id&eacute;ation, pas encore commenc&eacute;</td>
                                        <td><code>draft</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#f59e0b;">&#9679;</span> R&eacute;daction</td>
                                        <td>Contenu en cours de r&eacute;daction</td>
                                        <td><code>draft</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#f97316;">&#9679;</span> Relecture</td>
                                        <td>Contenu termin&eacute;, en attente de validation</td>
                                        <td><code>pending</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#3b82f6;">&#9679;</span> Planifi&eacute;</td>
                                        <td>Contenu valid&eacute; et planifi&eacute; pour publication</td>
                                        <td><code>future</code></td>
                                    </tr>
                                    <tr>
                                        <td><span style="color:#22c55e;">&#9679;</span> Publi&eacute;</td>
                                        <td>Contenu publi&eacute; et visible sur le site</td>
                                        <td><code>publish</code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cpp-admin-section">
                            <h2>Raccourcis clavier</h2>
                            <table class="widefat striped" style="max-width:500px;">
                                <thead>
                                    <tr><th>Raccourci</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><kbd>&larr;</kbd></td><td>Mois pr&eacute;c&eacute;dent</td></tr>
                                    <tr><td><kbd>&rarr;</kbd></td><td>Mois suivant</td></tr>
                                    <tr><td><kbd>T</kbd></td><td>Revenir au mois en cours (Today)</td></tr>
                                    <tr><td><kbd>N</kbd></td><td>Nouveau contenu</td></tr>
                                    <tr><td><kbd>Esc</kbd></td><td>Fermer le panneau d'&eacute;dition</td></tr>
                                    <tr><td><kbd>1</kbd></td><td>Vue Calendrier</td></tr>
                                    <tr><td><kbd>2</kbd></td><td>Vue Board</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cpp-admin-section" style="background:#fefce8;border-color:#fde68a;">
                            <h2 style="border-color:#fde68a;">Support</h2>
                            <p style="color:#374151;">Pour toute question ou probl&egrave;me :</p>
                            <ul style="list-style:none;padding:0;line-height:2.2;">
                                <li>Email : <a href="mailto:contact@khalid.digital">contact@khalid.digital</a></li>
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

                btn.prop('disabled', true).text('Activation...');

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
                        btn.prop('disabled', false).text('Activer la licence');
                    }
                }).fail(function() {
                    $('#cpp-license-message').html('<div class="notice notice-error inline"><p>Erreur de connexion.</p></div>').show();
                    btn.prop('disabled', false).text('Activer la licence');
                });
            });

            $('#cpp-deactivate-btn').on('click', function() {
                if (!confirm('Désactiver la licence sur ce domaine ?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('Désactivation...');

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
            ['slug' => 'idea',      'label' => 'Idée',      'color' => '#94a3b8', 'icon' => "\xF0\x9F\x92\xA1", 'wp_status' => 'draft'],
            ['slug' => 'drafting',  'label' => 'Rédaction',  'color' => '#f59e0b', 'icon' => "\xE2\x9C\x8F\xEF\xB8\x8F",  'wp_status' => 'draft'],
            ['slug' => 'review',    'label' => 'Relecture',  'color' => '#f97316', 'icon' => "\xF0\x9F\x91\x81\xEF\xB8\x8F",  'wp_status' => 'pending'],
            ['slug' => 'scheduled', 'label' => 'Planifié',   'color' => '#3b82f6', 'icon' => "\xF0\x9F\x93\x85", 'wp_status' => 'future'],
            ['slug' => 'published', 'label' => 'Publié',     'color' => '#22c55e', 'icon' => "\xE2\x9C\x85", 'wp_status' => 'publish'],
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
