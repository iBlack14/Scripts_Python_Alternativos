<?php
/**
 * Panel de Administración del Plugin en WordPress
 * Proporciona una interfaz profesional de configuración, comparador de catálogo y monitoreo.
 *
 * @package  OdooWooConnector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Admin_Page {

    const MENU_SLUG = 'owc-odoo-connector';

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_b2b_status_action' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );

        // Acciones AJAX para pruebas y sincronización en tiempo real
        add_action( 'wp_ajax_owc_test_connection',      [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_owc_sync_products',        [ __CLASS__, 'ajax_sync_products' ] );
        add_action( 'wp_ajax_owc_sync_products_batch',  [ __CLASS__, 'ajax_sync_products_batch' ] );
        add_action( 'wp_ajax_owc_sync_stock',           [ __CLASS__, 'ajax_sync_stock' ] );

        // Acciones AJAX para diagnóstico y comparador de catálogo
        add_action( 'wp_ajax_owc_compare_catalog',      [ __CLASS__, 'ajax_compare_catalog' ] );
        add_action( 'wp_ajax_owc_import_single',        [ __CLASS__, 'ajax_import_single' ] );
        add_action( 'wp_ajax_owc_export_single',        [ __CLASS__, 'ajax_export_single' ] );
        add_action( 'wp_ajax_owc_trash_woo_only',       [ __CLASS__, 'ajax_trash_woo_only' ] );
    }

    private static function check_permission() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    public static function handle_b2b_status_action() {
        $allowed_pages = [ self::MENU_SLUG, self::MENU_SLUG . '-b2b' ];
        if ( empty( $_GET['page'] ) || ! in_array( $_GET['page'], $allowed_pages, true ) || empty( $_GET['owc_b2b_action'] ) || empty( $_GET['user_id'] ) ) {
            return;
        }

        if ( ! self::check_permission() ) {
            wp_die( 'Permisos insuficientes.' );
        }

        $user_id = absint( $_GET['user_id'] );
        $action = sanitize_key( $_GET['owc_b2b_action'] );

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'owc_b2b_status_' . $user_id . '_' . $action ) ) {
            wp_die( 'Solicitud no válida.' );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&owc_b2b_msg=missing_user' ) );
            exit;
        }

        if ( $action === 'approve' ) {
            $wp_user = new WP_User( $user_id );
            $wp_user->set_role( OWC_B2B_Catalog::ROLE_PROVIDER );
            update_user_meta( $user_id, 'owc_b2b_status', 'approved' );
            update_user_meta( $user_id, 'owc_email_verified', 'yes' );
            $message = 'approved';
        } elseif ( $action === 'reject' ) {
            update_user_meta( $user_id, 'owc_b2b_status', 'rejected' );
            $message = 'rejected';
        } elseif ( $action === 'customer' ) {
            $wp_user = new WP_User( $user_id );
            $wp_user->set_role( 'customer' );
            update_user_meta( $user_id, 'owc_b2b_status', 'customer' );
            $message = 'customer';
        } else {
            $message = 'invalid';
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-b2b&owc_b2b_msg=' . $message ) );
        exit;
    }

    private static function render_b2b_requests_panel() {
        $statuses = [ 'pending', 'approved', 'rejected' ];
        $users = get_users( [
            'meta_key'   => 'owc_b2b_status',
            'meta_value' => $statuses,
            'meta_compare' => 'IN',
            'number'     => 20,
            'orderby'    => 'registered',
            'order'      => 'DESC',
        ] );

        $message = sanitize_key( $_GET['owc_b2b_msg'] ?? '' );
        if ( $message ) {
            $messages = [
                'approved' => 'Solicitud ferretero/distribuidor aprobada.',
                'rejected' => 'Solicitud marcada como rechazada.',
                'customer' => 'Usuario convertido a cliente simple.',
                'missing_user' => 'No se encontró el usuario solicitado.',
                'invalid' => 'Acción B2B no válida.',
                'email_unverified' => 'No se puede aprobar: el usuario todavía no confirmó su correo.',
            ];
            if ( isset( $messages[ $message ] ) ) {
                printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $messages[ $message ] ) );
            }
        }
        ?>
        <div class="owc-b2b-review-box">
            <div class="owc-b2b-review-head">
                <div>
                    <strong>Solicitudes B2B</strong>
                    <span><?php echo esc_html( count( $users ) ); ?> pendientes de revisión</span>
                </div>
            </div>
            <?php if ( empty( $users ) ) : ?>
                <p class="owc-hint">No hay solicitudes B2B registradas por ahora.</p>
            <?php else : ?>
                <table class="widefat striped owc-b2b-review-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Empresa</th>
                            <th>RUC</th>
                            <th>Teléfono</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $users as $user ) :
                            $status = get_user_meta( $user->ID, 'owc_b2b_status', true ) ?: 'pending';
                            $company = get_user_meta( $user->ID, 'owc_company_name', true ) ?: get_user_meta( $user->ID, 'billing_company', true );
                            $tax_id = get_user_meta( $user->ID, 'owc_tax_id', true ) ?: get_user_meta( $user->ID, 'billing_tax_id', true );
                            $email_verified = get_user_meta( $user->ID, 'owc_email_verified', true ) === 'yes';
                            $phone = preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, 'owc_phone', true ) ) );
                            $approve_url = wp_nonce_url(
                                admin_url( 'admin.php?page=' . self::MENU_SLUG . '-b2b&owc_b2b_action=approve&user_id=' . $user->ID ),
                                'owc_b2b_status_' . $user->ID . '_approve'
                            );
                            $reject_url = wp_nonce_url(
                                admin_url( 'admin.php?page=' . self::MENU_SLUG . '-b2b&owc_b2b_action=reject&user_id=' . $user->ID ),
                                'owc_b2b_status_' . $user->ID . '_reject'
                            );
                            $customer_url = wp_nonce_url(
                                admin_url( 'admin.php?page=' . self::MENU_SLUG . '-b2b&owc_b2b_action=customer&user_id=' . $user->ID ),
                                'owc_b2b_status_' . $user->ID . '_customer'
                            );
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong><br>
                                    <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
                                </td>
                                <td><?php echo esc_html( $company ?: '—' ); ?></td>
                                <td>
                                    <?php if ( $tax_id ) : ?>
                                        <code><?php echo esc_html( $tax_id ); ?></code>
                                        <a class="owc-b2b-link" href="https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/FrameCriterioBusquedaWeb.jsp" target="_blank" rel="noopener">SUNAT</a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $phone ) : ?>
                                        <strong><?php echo esc_html( $phone ); ?></strong><br>
                                        <a class="owc-b2b-link" href="tel:+51<?php echo esc_attr( $phone ); ?>">Llamar</a>
                                        <a class="owc-b2b-link" href="https://wa.me/51<?php echo esc_attr( $phone ); ?>" target="_blank" rel="noopener">WhatsApp</a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><span class="owc-b2b-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
                                <td class="owc-b2b-actions">
                                    <a class="button button-primary" href="<?php echo esc_url( $approve_url ); ?>" <?php echo $email_verified ? '' : 'title="Correo sin confirmar (Se verificará automáticamente)"'; ?>>Aprobar</a>
                                    <a class="button owc-b2b-action-muted" href="<?php echo esc_url( $reject_url ); ?>">Rechazar</a>
                                    <a class="owc-b2b-action-link" href="<?php echo esc_url( $customer_url ); ?>">Cliente simple</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Menú en wp-admin ─────────────────────────────────────────────────────
    public static function add_menu() {
        $cap = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

        add_menu_page(
            'Odoo Connector',
            'Odoo Connector',
            $cap,
            self::MENU_SLUG,
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-update',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Configuración',
            'Configuración',
            $cap,
            self::MENU_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Catálogo y Diagnóstico',
            'Catálogo y Diagnóstico',
            $cap,
            self::MENU_SLUG . '-catalog',
            [ __CLASS__, 'render_catalog_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Solicitudes Ferretero / Distribuidor',
            'Solicitudes B2B',
            $cap,
            self::MENU_SLUG . '-b2b',
            [ __CLASS__, 'render_b2b_requests_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Registro de Actividad',
            'Registro de Actividad',
            $cap,
            self::MENU_SLUG . '-logs',
            [ __CLASS__, 'render_logs_page' ]
        );
    }

    // ─── CSS Embebido ─────────────────────────────────────────────────────────
    public static function enqueue_styles( $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) return;
        wp_enqueue_style( 'owc-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap', [], null );
        wp_add_inline_style( 'wp-admin', self::admin_css() );
        wp_enqueue_media(); // Needed for the image uploader (wp.media)
    }

    private static function admin_css() {
        return '
        :root {
            --owc-bg: #f8fafc;
            --owc-surface: #ffffff;
            --owc-surface-2: #f1f5f9;
            --owc-border: #e2e8f0;
            --owc-border-hover: #94a3b8;
            --owc-primary: #6366f1;
            --owc-primary-dim: rgba(99, 102, 241, 0.10);
            --owc-primary-glow: rgba(99, 102, 241, 0.20);
            --owc-text: #1e293b;
            --owc-text-dim: #475569;
            --owc-text-muted: #94a3b8;
            --owc-danger: #ef4444;
            --owc-warning: #f59e0b;
            --owc-success: #10b981;
            --owc-odoo: #714B67;
            --owc-radius: 12px;
        }

        .owc-wrap {
            max-width: 1100px;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            color: var(--owc-text);
            margin-top: 15px;
        }

        /* ── Header ─────────────────────────────────────── */
        .owc-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8faff 50%, #eff6ff 100%);
            border: 1px solid #e2e8f0;
            border-radius: var(--owc-radius);
            padding: 24px 30px;
            margin: 15px 0 22px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .owc-header::before {
            content: "";
            position: absolute;
            top: -40px; right: -40px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(99,102,241,0.05);
            pointer-events: none;
        }
        .owc-header-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
        .owc-header-left { display: flex; flex-direction: column; gap: 4px; }
        .owc-header h1 {
            margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.3px;
            color: #1e293b;
        }
        .owc-header p { margin: 4px 0 0; font-size: 13.5px; color: #64748b; font-weight: 400; position: relative; z-index: 1; }
        .owc-header .owc-version-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px;
            background: #f1f5f9; border: 1px solid #cbd5e1;
            font-size: 11.5px; font-weight: 600; color: #475569;
            letter-spacing: 0.3px;
        }

        /* ── Tabs Navigation Bar ────────────────────────── */
        .owc-tabs-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--owc-surface);
            border: 1px solid var(--owc-border);
            border-radius: var(--owc-radius);
            padding: 6px;
            margin: 0 0 22px 0;
            overflow-x: auto;
            scrollbar-width: thin;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .owc-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 8px;
            background: transparent;
            border: 1px solid transparent;
            color: var(--owc-text-dim);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            font-family: inherit;
        }
        .owc-tab-btn:hover {
            color: var(--owc-text);
            background: var(--owc-surface-2);
        }
        .owc-tab-btn.active {
            background: var(--owc-primary-dim);
            border-color: rgba(99,102,241,0.25);
            color: var(--owc-primary);
            font-weight: 700;
        }
        .owc-tab-btn .owc-tab-icon { font-size: 14px; line-height: 1; }

        /* ── Tab Panels ─────────────────────────────────── */
        .owc-tab-panel { display: none; }
        .owc-tab-panel.active {
            display: block;
            animation: owcTabFade 0.18s ease;
        }
        @keyframes owcTabFade {
            from { opacity: 0; transform: translateY(4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .owc-tab-panel-intro { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding: 0 4px; }
        .owc-tab-panel-intro h3 { margin: 0; font-size: 17px; font-weight: 700; color: var(--owc-text); display: flex; align-items: center; gap: 8px; }
        .owc-tab-panel-intro p  { margin: 4px 0 0; font-size: 12.5px; color: var(--owc-text-dim); }

        /* ── Sticky Bottom Action Bar ───────────────────── */
        .owc-sticky-bar {
            position: sticky; bottom: 20px; z-index: 999;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(14px);
            border: 1px solid var(--owc-border);
            border-radius: 14px; padding: 14px 24px; margin-top: 30px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 8px 25px rgba(0,0,0,0.10);
        }
        .owc-sticky-bar .owc-bar-info { font-size: 13px; color: var(--owc-text-dim); display: flex; align-items: center; gap: 10px; }
        .owc-sticky-bar .owc-save-btn { margin: 0 !important; padding: 10px 28px !important; font-size: 14px !important; font-weight: 700 !important; border-radius: 8px !important; }

        /* ── Dynamic Alert Container ─────────────────────── */
        #owc-alert-container { margin-bottom: 20px; }
        .owc-alert-box {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; font-weight: 500; line-height: 1.5;
            margin: 10px 0 16px;
            animation: owcSlideDown 0.25s ease;
            position: relative;
        }
        @keyframes owcSlideDown { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .owc-alert-box.success { background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #22c55e; color: #15803d; }
        .owc-alert-box.error   { background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #ef4444; color: #b91c1c; }
        .owc-alert-box.info    { background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; color: #1d4ed8; }
        .owc-alert-icon { font-size: 18px; flex-shrink: 0; line-height: 1.2; }
        .owc-alert-text { flex-grow: 1; }
        .owc-alert-close { background: none; border: none; color: inherit; opacity: 0.5; cursor: pointer; font-size: 16px; padding: 0 4px; line-height: 1; }
        .owc-alert-close:hover { opacity: 1; }

        /* WP Standard notice overrides inside owc */
        .owc-wrap .notice {
            border-radius: 8px !important; margin: 10px 0 !important;
            border-left-width: 4px !important; padding: 12px 16px !important;
            font-size: 13.5px !important; box-shadow: none !important;
        }
        .owc-wrap .notice-success { border-left-color: #22c55e !important; background: #f0fdf4 !important; border: 1px solid #bbf7d0 !important; color: #15803d !important; }
        .owc-wrap .notice-error   { border-left-color: #ef4444 !important; background: #fef2f2 !important; border: 1px solid #fecaca !important; color: #b91c1c !important; }
        .owc-wrap .notice p { margin: 0 !important; color: inherit !important; font-weight: 500 !important; }

        /* ── Cards ──────────────────────────────────────── */
        .owc-card {
            background: var(--owc-surface);
            border: 1px solid var(--owc-border);
            border-radius: var(--owc-radius);
            padding: 22px 26px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s ease;
        }
        .owc-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .owc-card h2 {
            margin: 0 0 14px; font-size: 15px; font-weight: 700; color: var(--owc-text);
            padding-bottom: 12px;
            border-bottom: 1px solid var(--owc-border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }

        /* ── Action Card ────────────────────────────────── */
        .owc-card.owc-actions-card {
            background: linear-gradient(135deg, #fafafa 0%, #f1f5f9 100%);
            border-left: 4px solid var(--owc-primary);
        }
        .owc-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
        .owc-action-btn {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px;
            border-radius: 10px; border: 1px solid var(--owc-border);
            background: var(--owc-surface);
            color: var(--owc-text); font-size: 13.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            font-family: "Inter", sans-serif; text-align: left; text-decoration: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .owc-action-btn:hover:not(:disabled) {
            border-color: var(--owc-primary);
            box-shadow: 0 4px 16px var(--owc-primary-glow);
            transform: translateY(-1px);
            color: var(--owc-primary);
        }
        .owc-action-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
        .owc-action-btn .owc-action-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 8px; font-size: 17px; flex-shrink: 0;
        }
        .owc-action-btn.owc-btn-test .owc-action-icon     { background: #ede9fe; }
        .owc-action-btn.owc-btn-products .owc-action-icon { background: #fce7f3; }
        .owc-action-btn.owc-btn-stock .owc-action-icon    { background: #d1fae5; }
        .owc-action-btn.owc-btn-compare .owc-action-icon  { background: #dbeafe; }

        @keyframes owcSpin { 100% { transform: rotate(360deg); } }
        .owc-spinning { display: inline-block; animation: owcSpin 1s linear infinite; }

        /* ── Metric Grid ────────────────────────────────── */
        .owc-metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .owc-metric-card {
            background: var(--owc-surface);
            border: 1px solid var(--owc-border);
            border-radius: var(--owc-radius);
            padding: 18px 20px;
            position: relative; overflow: hidden; transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .owc-metric-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); transform: translateY(-1px); }
        .owc-metric-card .label {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px;
            color: var(--owc-text-muted); font-weight: 700; margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .owc-metric-card .val { font-size: 28px; font-weight: 800; line-height: 1.1; }
        .owc-metric-card.m-odoo    { background: #faf5ff; border: 1px solid #e9d5ff; }
        .owc-metric-card.m-odoo    .val { color: #7e22ce; }
        .owc-metric-card.m-missing { background: #fffbeb; border: 1px solid #fef3c7; }
        .owc-metric-card.m-missing .val { color: #d97706; }
        .owc-metric-card.m-onlywoo { background: #eff6ff; border: 1px solid #dbeafe; }
        .owc-metric-card.m-onlywoo .val { color: #2563eb; }
        .owc-metric-card.m-synced  { background: #f0fdf4; border: 1px solid #dcfce7; }
        .owc-metric-card.m-synced  .val { color: #15803d; }

        /* ── Filter Pills & Search ──────────────────────── */
        .owc-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .owc-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .owc-pill {
            background: var(--owc-surface-2); border: 1px solid var(--owc-border);
            color: var(--owc-text-dim); padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.18s;
        }
        .owc-pill:hover, .owc-pill.active { background: var(--owc-primary-dim); border-color: rgba(99,102,241,0.3); color: var(--owc-primary); }
        .owc-search-box { position: relative; min-width: 240px; }
        .owc-search-box input {
            width: 100%; padding: 8px 14px 8px 32px;
            border-radius: 20px; border: 1px solid var(--owc-border);
            background: var(--owc-surface-2); color: var(--owc-text); font-size: 13px;
        }
        .owc-search-box .icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--owc-text-muted); font-size: 13px; pointer-events: none; }

        /* ── Catalog Table ──────────────────────────────── */
        .owc-catalog-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .owc-catalog-table th {
            background: var(--owc-surface-2); padding: 11px 14px; text-align: left;
            font-weight: 700; font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--owc-text-muted);
            border-bottom: 1px solid var(--owc-border);
        }
        .owc-catalog-table td { padding: 11px 14px; border-bottom: 1px solid var(--owc-border); vertical-align: middle; color: var(--owc-text-dim); }
        .owc-catalog-table tr:hover td { background: #fafafa; }
        .owc-catalog-table .owc-product-name { color: var(--owc-text); font-weight: 600; min-width: 220px; }
        .owc-catalog-table .stock-num { font-weight: 700; }
        .owc-catalog-table .stock-pos { color: #059669; }
        .owc-catalog-table .stock-zero { color: #94a3b8; }
        .owc-pagination { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 6px; padding: 18px 0 4px; }
        .owc-page-btn { min-width: 34px; height: 34px; padding: 0 10px; border: 1px solid var(--owc-border); border-radius: 6px; background: #fff; color: var(--owc-text-dim); font-size: 12px; font-weight: 700; cursor: pointer; }
        .owc-page-btn:hover:not(:disabled), .owc-page-btn.active { background: var(--owc-primary); border-color: var(--owc-primary); color: #fff; }
        .owc-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .owc-page-dots { padding: 0 3px; color: var(--owc-text-muted); }

        .owc-btn-sm { padding: 5px 11px; border-radius: 6px; font-size: 11.5px; font-weight: 700; border: 1px solid transparent; cursor: pointer; transition: all 0.18s; display: inline-flex; align-items: center; gap: 4px; font-family: "Inter", sans-serif; }
        .owc-btn-sm-import { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .owc-btn-sm-import:hover { background: #059669; color: #fff; }
        .owc-btn-sm-export { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .owc-btn-sm-export:hover { background: #2563eb; color: #fff; }

        /* ── Badges ─────────────────────────────────────── */
        .owc-badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .owc-badge.synced  { background: #d1fae5; color: #065f46; }
        .owc-badge.missing { background: #fef3c7; color: #92400e; }
        .owc-badge.onlywoo { background: #dbeafe; color: #1e40af; }

        .owc-b2b-review-box {
            margin: 0 0 16px;
            border: 1px solid var(--owc-border);
            border-radius: 10px;
            overflow: hidden;
            background: var(--owc-surface);
        }
        .owc-b2b-review-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            background: #f8fafc;
            border-bottom: 1px solid var(--owc-border);
        }
        .owc-b2b-review-head strong {
            color: var(--owc-text);
            font-size: 14px;
        }
        .owc-b2b-review-head span {
            color: var(--owc-text-muted);
            font-size: 12px;
            margin-left: 8px;
        }
        .owc-b2b-review-table { margin: 0; border: 0; box-shadow: none; }
        .owc-b2b-review-table th { padding: 9px 12px; font-size: 10px; color: var(--owc-text-muted); text-transform: uppercase; letter-spacing: .04em; }
        .owc-b2b-review-table td { padding: 10px 12px; vertical-align: middle; }
        .owc-b2b-review-table td strong { color: var(--owc-text); }
        .owc-b2b-review-table td a { text-decoration: none; }
        .owc-b2b-link { font-size: 11px; margin-right: 7px; }
        .owc-b2b-review-table code {
            display: inline-block;
            margin-right: 5px;
            padding: 2px 6px;
            border-radius: 6px;
            background: #eef2ff;
            color: #3730a3;
        }
        .owc-b2b-status {
            display: inline-flex;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .owc-b2b-status.is-pending { background: #fef3c7; color: #92400e; }
        .owc-b2b-status.is-approved { background: #d1fae5; color: #065f46; }
        .owc-b2b-status.is-rejected { background: #fee2e2; color: #991b1b; }
        .owc-b2b-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
        }
        .owc-b2b-actions .button { min-height: 29px; line-height: 27px; padding: 0 10px; font-size: 11px; }
        .owc-b2b-actions .owc-b2b-action-muted { color: #b91c1c; border-color: #fecaca; background: #fff; }
        .owc-b2b-action-link { font-size: 11px; color: var(--owc-text-dim); }
        .owc-b2b-card { max-width: 920px; }
        .owc-b2b-settings { display: grid; grid-template-columns: minmax(0, 1fr) minmax(190px, .9fr); gap: 10px 16px; align-items: end; }
        .owc-b2b-settings .owc-form-row { margin: 0; }
        .owc-b2b-toggle label:has(input[type=checkbox]) { min-height: 40px; box-sizing: border-box; }
        .owc-b2b-field label { margin-bottom: 6px; }
        .owc-b2b-igv-rate input[type=number] { max-width: 100% !important; }
        @media (max-width: 782px) {
            .owc-b2b-settings { grid-template-columns: 1fr; }
            .owc-b2b-review-table { display: block; overflow-x: auto; }
        }

        /* ── Form Controls ──────────────────────────────── */
        .owc-form-row { margin-bottom: 16px; }
        .owc-form-row label { display: block; font-weight: 600; margin-bottom: 5px; color: var(--owc-text-dim); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .owc-form-row input[type=text],
        .owc-form-row input[type=password],
        .owc-form-row input[type=url],
        .owc-form-row select {
            width: 100%; padding: 10px 13px;
            border: 1px solid var(--owc-border);
            border-radius: 8px; font-size: 13.5px;
            background: var(--owc-surface); color: var(--owc-text);
            font-family: "Inter", sans-serif; transition: all 0.18s ease;
            box-sizing: border-box;
        }
        .owc-form-row input:focus, .owc-form-row select:focus { border-color: var(--owc-primary); outline: none; box-shadow: 0 0 0 3px var(--owc-primary-dim); }
        .owc-form-row input::placeholder { color: var(--owc-text-muted); }
        .owc-form-row .owc-hint { color: var(--owc-text-muted); font-size: 11.5px; margin-top: 4px; font-weight: 400; }
        .owc-form-row input[type=checkbox] { accent-color: var(--owc-primary); width: 15px; height: 15px; margin-right: 8px; vertical-align: middle; }
        .owc-form-row label:has(input[type=checkbox]) {
            text-transform: none; letter-spacing: 0; font-size: 13.5px;
            display: flex; align-items: center; cursor: pointer;
            padding: 9px 13px; border-radius: 8px; border: 1px solid transparent; transition: all 0.18s;
        }
        .owc-form-row label:has(input[type=checkbox]):hover { background: var(--owc-primary-dim); border-color: rgba(99,102,241,0.2); }

        /* ── Save Button ────────────────────────────────── */
        .owc-save-btn {
            background: var(--owc-primary); color: #fff;
            border: none; padding: 11px 28px;
            border-radius: 8px; font-size: 14px; font-weight: 700;
            cursor: pointer; font-family: "Inter", sans-serif;
            letter-spacing: 0.2px; transition: all 0.2s ease;
            box-shadow: 0 2px 10px var(--owc-primary-glow);
        }
        .owc-save-btn:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 4px 18px var(--owc-primary-glow); color: #fff; }

        /* ── Progress Bar ───────────────────────────────── */
        .owc-progress-box {
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 10px; padding: 16px 20px; margin: 12px 0;
            animation: owcSlideDown 0.25s ease;
        }
        .owc-progress-head { display: flex; justify-content: space-between; align-items: center; font-size: 13.5px; font-weight: 600; color: #1e40af; margin-bottom: 10px; }
        .owc-progress-track { background: #dbeafe; height: 10px; border-radius: 5px; overflow: hidden; margin-bottom: 8px; }
        .owc-progress-bar { background: linear-gradient(90deg, #6366f1 0%, #8b5cf6 100%); height: 100%; width: 0%; border-radius: 5px; transition: width 0.3s ease; }
        .owc-progress-sub { font-size: 12px; color: #3b82f6; display: flex; justify-content: space-between; }

        /* ── Logs Table ─────────────────────────────────── */
        .owc-log-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .owc-log-table th { background: var(--owc-surface-2); padding: 11px 14px; text-align: left; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--owc-text-muted); border-bottom: 1px solid var(--owc-border); }
        .owc-log-table td { padding: 10px 14px; border-bottom: 1px solid var(--owc-border); vertical-align: top; color: var(--owc-text-dim); }
        .owc-log-table tr:hover td { background: var(--owc-surface-2); }
        .owc-log-table code { color: #6366f1; background: #ede9fe; padding: 2px 7px; border-radius: 4px; font-size: 12px; }

        /* ── Footer ─────────────────────────────────────── */
        .owc-footer { text-align: center; padding: 20px 0 12px; margin-top: 16px; border-top: 1px solid var(--owc-border); }
        .owc-footer span { font-size: 12px; color: var(--owc-text-muted); font-weight: 500; }
        .owc-footer a { color: var(--owc-primary); text-decoration: none; font-weight: 600; }
        .owc-footer a:hover { text-decoration: underline; }

        /* ── Template Cards ─────────────────────────────── */
        .owc-tpl-section-title { font-size: 14px; font-weight: 700; color: var(--owc-text); margin: 18px 0 10px 0; display: flex; align-items: center; gap: 8px; }
        .owc-tpl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin: 10px 0 20px 0; }
        .owc-tpl-card {
            background: var(--owc-surface);
            border: 2px solid var(--owc-border);
            border-radius: 12px; overflow: hidden; cursor: pointer; position: relative;
            transition: all 0.2s ease; display: flex; flex-direction: column;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .owc-tpl-card:hover { transform: translateY(-2px); border-color: rgba(99,102,241,0.4); box-shadow: 0 6px 20px rgba(0,0,0,0.10); }
        .owc-tpl-card.active { border-color: var(--owc-primary); box-shadow: 0 0 0 1px var(--owc-primary), 0 6px 20px var(--owc-primary-dim); }
        .owc-tpl-preview-mock {
            height: 110px; padding: 12px; display: flex; flex-direction: column;
            justify-content: center; align-items: center; position: relative; overflow: hidden;
            background: var(--owc-surface-2); border-bottom: 1px solid var(--owc-border);
        }
        .mock-split-wireframe {
            display: grid; grid-template-columns: 1fr 1.2fr; gap: 6px; width: 90%; height: 75px;
            background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px;
        }
        .mock-split-hero {
            background: linear-gradient(135deg, #ede9fe 0%, #dbeafe 100%);
            border-radius: 4px; border: 1px solid #c4b5fd;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            font-size: 8px; color: #6366f1; font-weight: 700;
        }
        .mock-split-form { background: #fff; border-radius: 4px; padding: 6px; display: flex; flex-direction: column; gap: 4px; justify-content: center; }
        .mock-centered-wireframe { width: 60%; height: 75px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; display: flex; flex-direction: column; gap: 5px; justify-content: center; }
        .mock-dual-wireframe { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; width: 90%; height: 75px; }
        .mock-dual-col { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; display: flex; flex-direction: column; gap: 4px; justify-content: center; }
        .mock-native-wireframe { width: 85%; height: 65px; background: #f1f5f9; border: 1px dashed #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--owc-text-muted); }
        .mock-line { height: 5px; border-radius: 3px; background: #e2e8f0; }
        .mock-line-pri { height: 5px; border-radius: 3px; background: var(--owc-primary); }

        /* Wireframes de Temas */
        .mock-theme-cyber { width: 88%; height: 70px; background: #0d1117; border: 1px solid rgba(0,212,170,0.4); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
        .mock-theme-minimal { width: 88%; height: 70px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
        .mock-theme-minimal .mock-line { background: #cbd5e1; }
        .mock-theme-minimal .mock-line-pri { background: #2563eb; }
        .mock-theme-slate { width: 88%; height: 70px; background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 8px; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
        .mock-theme-slate .mock-line-pri { background: #38bdf8; }
        .mock-theme-luxury { width: 88%; height: 70px; background: #18181b; border: 1px solid rgba(245,158,11,0.4); border-radius: 8px; padding: 8px; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
        .mock-theme-luxury .mock-line-pri { background: #f59e0b; }

        /* Cart wireframes */
        .mock-cart-split { display: grid; grid-template-columns: 1.4fr 1fr; gap: 6px; width: 90%; height: 70px; }
        .mock-cart-split-table { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; display: flex; flex-direction: column; gap: 3px; justify-content: center; }
        .mock-cart-split-totals { background: #ede9fe; border: 1px solid #c4b5fd; border-radius: 6px; padding: 6px; display: flex; flex-direction: column; gap: 3px; justify-content: center; }
        .mock-cart-stacked { width: 90%; height: 70px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; display: flex; flex-direction: column; gap: 4px; justify-content: center; }

        .owc-tpl-info { padding: 14px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .owc-tpl-name { font-size: 13.5px; font-weight: 700; color: var(--owc-text); margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; }
        .owc-tpl-desc { font-size: 11.5px; color: var(--owc-text-dim); line-height: 1.4; margin-bottom: 10px; }
        .owc-tpl-radio-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; color: var(--owc-text-muted); }
        .owc-tpl-card.active .owc-tpl-radio-wrap { color: var(--owc-primary); }

        /* ── Presets Rápidos de Color ── */
        .owc-preset-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0 16px 0; padding: 12px 16px; background: var(--owc-surface-2); border: 1px solid var(--owc-border); border-radius: 10px; }
        .owc-preset-bar-title { font-size: 12px; font-weight: 700; color: var(--owc-text-dim); margin-right: 4px; }
        .owc-preset-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; background: var(--owc-surface); border: 1px solid var(--owc-border); color: var(--owc-text); font-size: 11.5px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
        .owc-preset-btn:hover { border-color: var(--owc-primary); color: var(--owc-primary); transform: translateY(-1px); }
        .owc-preset-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

        /* ── Palette Pickers ── */
        .owc-palette-picker-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; background: var(--owc-surface-2); border: 1px solid var(--owc-border); border-radius: 10px; padding: 18px; margin: 12px 0 18px 0; }
        .owc-palette-field { display: flex; align-items: center; gap: 12px; background: var(--owc-surface); border: 1px solid var(--owc-border); border-radius: 9px; padding: 10px 13px; transition: border-color 0.18s; }
        .owc-palette-field:hover { border-color: var(--owc-primary); }
        .owc-palette-field input[type="color"] { -webkit-appearance: none; border: none; width: 36px; height: 36px; border-radius: 7px; cursor: pointer; background: transparent; flex-shrink: 0; padding: 0; }
        .owc-palette-field input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        .owc-palette-field input[type="color"]::-webkit-color-swatch { border: 1px solid rgba(0,0,0,0.12); border-radius: 7px; }
        .owc-palette-field label { font-size: 12.5px; font-weight: 700; color: var(--owc-text); display: block; margin-bottom: 2px; }
        .owc-palette-field span.field-desc { font-size: 10.5px; color: var(--owc-text-muted); display: block; line-height: 1.3; }

        /* ── Live Preview Box ── */
        .owc-live-preview-box {
            background: var(--prev-bg, #f8fafc);
            border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px; margin: 14px 0 22px 0;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; align-items: start; transition: all 0.25s ease;
        }
        .preview-sample-card { background: var(--prev-surface, #fff); border: 1px solid var(--prev-border, #e2e8f0); border-radius: 10px; padding: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
        .preview-sample-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(99,102,241,0.10); border: 1px solid rgba(99,102,241,0.20); color: var(--prev-pri, #6366f1); padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-bottom: 12px; }
        .preview-sample-input { width: 100%; padding: 10px 13px; background: var(--prev-surface2, #f1f5f9); border: 1px solid var(--prev-border, #e2e8f0); border-radius: 7px; color: var(--prev-text, #1e293b); font-size: 13px; box-sizing: border-box; margin-bottom: 10px; outline: none; }
        .preview-sample-btn { display: inline-block; padding: 10px 20px; border-radius: 7px; font-size: 13px; font-weight: 800; text-align: center; border: none; cursor: pointer; background: var(--prev-pri, #6366f1); color: #fff; width: 100%; box-sizing: border-box; transition: all 0.18s; }
        .preview-sample-cart-item { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; color: var(--prev-text, #1e293b); }
        .preview-sample-total { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; margin-top: 6px; font-size: 13.5px; font-weight: 800; color: var(--prev-text, #1e293b); }
        .preview-sample-total span.price { color: var(--prev-pri, #6366f1); }

        /* ── Cart Cards ── */
        .owc-cart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin: 14px 0 18px 0; }
        .owc-cart-card { background: var(--owc-surface); border: 2px solid var(--owc-border); border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .owc-cart-card:hover { border-color: rgba(99,102,241,0.35); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.08); }
        .owc-cart-card.active { border-color: var(--owc-primary); box-shadow: 0 0 0 1px var(--owc-primary), 0 4px 16px var(--owc-primary-dim); }
        .owc-cart-title { font-size: 14px; font-weight: 800; color: var(--owc-text); margin-bottom: 5px; display: flex; align-items: center; justify-content: space-between; }
        .owc-cart-desc { font-size: 12px; color: var(--owc-text-dim); line-height: 1.5; margin-bottom: 12px; }

        /* ── Status Grid ── */
        .owc-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .owc-stat { background: var(--owc-surface-2); border: 1px solid var(--owc-border); border-radius: 9px; padding: 14px 16px; }
        .owc-stat .label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--owc-text-muted); margin-bottom: 5px; }
        .owc-stat .value { font-size: 13px; font-weight: 600; color: var(--owc-text); }

        @media (max-width: 782px) {
            .owc-header { padding: 18px; }
            .owc-actions-grid { grid-template-columns: 1fr; }
            .owc-metric-grid { grid-template-columns: 1fr 1fr; }
            .owc-tpl-grid { grid-template-columns: 1fr; }
            .owc-palette-picker-grid { grid-template-columns: 1fr; }
            .owc-live-preview-box { grid-template-columns: 1fr; }
            .owc-cart-grid { grid-template-columns: 1fr; }
        }
        ';
    }

    // ─── Guardar Configuración y Procesar Acciones (Fallback POST) ─────────────
    public static function handle_save() {
        if ( ! self::check_permission() ) return;

        // 1. Probar Conexión vía POST
        if ( isset( $_POST['owc_test_connection'] ) ) {
            check_admin_referer( 'owc_save_nonce' );
            self::save_form_data();
            $res = OWC_Sync_Engine::test_connection();
            if ( ! empty( $res['success'] ) ) {
                add_settings_error( 'owc_messages', 'owc_message', "✔ Conexión Exitosa con Odoo 15! (Versión: {$res['server_version']} | UID: {$res['uid']})", 'success' );
            } else {
                add_settings_error( 'owc_messages', 'owc_message', "✘ Error de conexión: " . ( $res['error'] ?? 'Desconocido' ), 'error' );
            }
            return;
        }

        // 2. Sincronizar Productos Odoo -> WooCommerce
        if ( isset( $_POST['owc_sync_products_now'] ) ) {
            check_admin_referer( 'owc_save_nonce' );
            self::save_form_data();
            $res = OWC_Sync_Engine::sync_products();
            if ( ! empty( $res['success'] ) ) {
                add_settings_error( 'owc_messages', 'owc_message', $res['message'] ?? "✔ Sincronización de Catálogo completada!", 'success' );
            } else {
                add_settings_error( 'owc_messages', 'owc_message', "✘ Error sincronizando catálogo: " . ( $res['error'] ?? 'Desconocido' ), 'error' );
            }
            return;
        }

        // 3. Sincronizar Stock Odoo -> WooCommerce
        if ( isset( $_POST['owc_sync_stock_now'] ) ) {
            check_admin_referer( 'owc_save_nonce' );
            self::save_form_data();
            $res = OWC_Sync_Engine::sync_stock();
            if ( ! empty( $res['success'] ) ) {
                add_settings_error( 'owc_messages', 'owc_message', $res['message'] ?? "✔ Sincronización de Stock completada!", 'success' );
            } else {
                add_settings_error( 'owc_messages', 'owc_message', "✘ Error sincronizando stock: " . ( $res['error'] ?? 'Desconocido' ), 'error' );
            }
            return;
        }

        // 4. Guardar Configuración Estándar
        if ( isset( $_POST['owc_save_settings'] ) ) {
            check_admin_referer( 'owc_save_nonce' );
            self::save_form_data();
            add_settings_error( 'owc_messages', 'owc_message', '✔ Configuración guardada correctamente.', 'success' );
        }
    }

    private static function save_form_data() {
        $data = [
            'odoo_url'             => sanitize_url( $_POST['odoo_url'] ?? '' ),
            'odoo_db'              => sanitize_text_field( $_POST['odoo_db'] ?? '' ),
            'odoo_user'            => sanitize_text_field( $_POST['odoo_user'] ?? '' ),
            'sync_match_field'     => sanitize_text_field( $_POST['sync_match_field'] ?? 'default_code' ),
            'sync_interval'        => max( 1, (int) ( $_POST['sync_interval'] ?? 5 ) ),
            'sync_orders'          => isset( $_POST['sync_orders'] ) ? 'yes' : 'no',
            'auto_create_products' => isset( $_POST['auto_create_products'] ) ? 'yes' : 'no',
            'sync_images'          => isset( $_POST['sync_images'] ) ? 'yes' : 'no',
            'digital_card_url'     => sanitize_url( $_POST['digital_card_url'] ?? 'https://grupofloressa.com/tarjeta-digital/' ),
            'shipping_agencies_json'        => wp_unslash( $_POST['shipping_agencies_json'] ?? '[]' ),
            'checkout_advisors_json'        => wp_unslash( $_POST['checkout_advisors_json'] ?? '[]' ),
            'enable_internal_digital_card'  => isset( $_POST['enable_internal_digital_card'] ) ? 'yes' : 'no',
            'hide_prices_guest'            => isset( $_POST['hide_prices_guest'] ) ? 'yes' : 'no',
            'guest_price_text'         => sanitize_text_field( $_POST['guest_price_text'] ?? 'Iniciar sesión | Registrarse para ver precio' ),
            'enable_igv_display'       => isset( $_POST['enable_igv_display'] ) ? 'yes' : 'no',
            'igv_rate'                 => (float) ( $_POST['igv_rate'] ?? 18 ),
            'enable_role_registration' => isset( $_POST['enable_role_registration'] ) ? 'yes' : 'no',
            'design_theme'             => sanitize_text_field( $_POST['design_theme'] ?? 'cyber_glass' ),
            'auth_layout'              => sanitize_text_field( $_POST['auth_layout'] ?? 'split_screen' ),
            'auth_template'            => sanitize_text_field( $_POST['auth_layout'] ?? 'split_screen' ),
            'color_palette'            => sanitize_text_field( $_POST['color_palette'] ?? 'emerald' ),
            'custom_primary_color'     => sanitize_hex_color( $_POST['custom_primary_color'] ?? '#00d4aa' ),
            'custom_accent_color'      => sanitize_hex_color( $_POST['custom_accent_color'] ?? '#0284c7' ),
            'custom_bg_color'          => sanitize_hex_color( $_POST['custom_bg_color'] ?? '#090e17' ),
            'custom_surface_color'     => sanitize_hex_color( $_POST['custom_surface_color'] ?? '#111827' ),
            'custom_surface_2_color'   => sanitize_hex_color( $_POST['custom_surface_2_color'] ?? '#1a2332' ),
            'custom_text_color'        => sanitize_hex_color( $_POST['custom_text_color'] ?? '#ffffff' ),
            'custom_text_dim_color'    => sanitize_hex_color( $_POST['custom_text_dim_color'] ?? '#94a3b8' ),
            'custom_border_color'      => sanitize_text_field( $_POST['custom_border_color'] ?? 'rgba(0, 212, 170, 0.22)' ),
            'enable_cart_styling'      => isset( $_POST['enable_cart_styling'] ) ? 'yes' : 'no',
            'cart_layout'              => sanitize_text_field( $_POST['cart_layout'] ?? 'modern_split' ),
            'enable_checkout_styling'  => isset( $_POST['enable_checkout_styling'] ) ? 'yes' : 'no',
            'cart_b2b_badge'           => isset( $_POST['cart_b2b_badge'] ) ? 'yes' : 'no',
            'auth_title'               => sanitize_text_field( $_POST['auth_title'] ?? 'Portal Empresarial & Tienda B2B' ),
            'auth_subtitle'            => sanitize_text_field( $_POST['auth_subtitle'] ?? 'Accede a tu cuenta para ver precios exclusivos, existencias y pedidos.' ),
            'auth_hero_title'          => sanitize_text_field( $_POST['auth_hero_title'] ?? 'Acceso para Clientes y Ferreteros' ),
            'auth_hero_subtitle'       => sanitize_text_field( $_POST['auth_hero_subtitle'] ?? 'Gestiona tus compras mayoristas, cotizaciones y pedidos sincronizados en tiempo real con Odoo 15.' ),
        ];

        if ( isset( $_POST['odoo_password'] ) ) {
            $password = trim( wp_unslash( $_POST['odoo_password'] ) );
            if ( $password !== '' ) {
                $data['odoo_password'] = $password;
            }
        }

        if ( isset( $_POST['regenerate_token'] ) ) {
            $data['api_secret_token'] = OWC_Settings::generate_token();
        }

        OWC_Settings::save( $data );
        wp_clear_scheduled_hook( 'owc_cron_sync_stock' );
    }

    public static function render_b2b_requests_page() {
        ?>
        <div class="owc-wrap">
            <div class="owc-header">
                <div class="owc-header-inner">
                    <div class="owc-header-left">
                        <h1>Solicitudes Ferretero / Distribuidor</h1>
                        <p>Confirma el correo y valida el RUC antes de habilitar los precios B2B.</p>
                    </div>
                    <div class="owc-version-badge">v<?php echo esc_html( OWC_VERSION ); ?></div>
                </div>
            </div>
            <div class="owc-card">
                <?php self::render_b2b_requests_panel(); ?>
            </div>
        </div>
        <?php
    }

    // ─── Controladores AJAX (Feedback Inmediato) ──────────────────────────────
    public static function ajax_test_connection() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        if ( ! empty( $_POST['odoo_url'] ) ) {
            self::save_form_data();
        }

        $res = OWC_Sync_Engine::test_connection();
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( [
                'message'        => "✔ Conexión Exitosa con Odoo 15! (Versión: {$res['server_version']} | UID: {$res['uid']})",
                'server_version' => $res['server_version'],
                'uid'            => $res['uid'],
            ] );
        } else {
            wp_send_json_error( [
                'message' => "✘ Error de conexión: " . ( $res['error'] ?? 'Desconocido' ),
            ] );
        }
    }

    public static function ajax_sync_products() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        if ( ! empty( $_POST['odoo_url'] ) ) {
            self::save_form_data();
        }

        $res = OWC_Sync_Engine::sync_products();
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( [
                'message' => $res['message'] ?? "✔ Sincronización de Catálogo completada!",
                'created' => $res['created'] ?? 0,
                'updated' => $res['updated'] ?? 0,
                'time'    => current_time('Y-m-d H:i:s'),
            ] );
        } else {
            wp_send_json_error( [
                'message' => "✘ Error sincronizando catálogo: " . ( $res['error'] ?? 'Desconocido' ),
            ] );
        }
    }

    public static function ajax_sync_stock() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        if ( ! empty( $_POST['odoo_url'] ) ) {
            self::save_form_data();
        }

        $res = OWC_Sync_Engine::sync_stock();
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( [
                'message' => $res['message'] ?? "✔ Sincronización de Stock completada!",
                'updated' => $res['updated'] ?? 0,
                'created' => $res['created'] ?? 0,
                'missing' => $res['missing'] ?? 0,
                'time'    => current_time('Y-m-d H:i:s'),
            ] );
        } else {
            wp_send_json_error( [
                'message' => "✘ Error sincronizando stock: " . ( $res['error'] ?? 'Desconocido' ),
            ] );
        }
    }

    public static function ajax_sync_products_batch() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        // NOTA: No guardar configuración aquí — los checkboxes no marcados no se envían
        // en FormData, lo que causaba que auto_create_products se guardara como 'no'
        // incorrectamente durante la importación por lotes.

        $offset     = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 25 );
        // Permitir forzar creación desde el botón manual (ignora el setting auto_create)
        $force_create = ! empty( $_POST['force_create'] );

        $res = OWC_Sync_Engine::sync_products_batch( $offset, $batch_size, $force_create );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'Error en el lote de sincronización.' ] );
        }
    }

    // ─── Controladores AJAX para Comparación y Diagnóstico de Catálogo ────────
    public static function ajax_compare_catalog() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $force = ! empty( $_POST['force_refresh'] );
        $res = OWC_Sync_Engine::compare_catalogs( $force );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'Error comparando catálogos.' ] );
        }
    }

    public static function ajax_import_single() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $odoo_id = (int) ( $_POST['odoo_id'] ?? 0 );
        if ( ! $odoo_id ) {
            wp_send_json_error( [ 'message' => 'ID de Odoo no especificado.' ] );
        }

        $res = OWC_Sync_Engine::import_single_product( $odoo_id );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'Error importando producto.' ] );
        }
    }

    public static function ajax_export_single() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $wc_id = (int) ( $_POST['wc_id'] ?? 0 );
        if ( ! $wc_id ) {
            wp_send_json_error( [ 'message' => 'ID de WooCommerce no especificado.' ] );
        }

        $res = OWC_Sync_Engine::export_single_product( $wc_id );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'Error exportando producto a Odoo.' ] );
        }
    }

    public static function ajax_trash_woo_only() {
        check_ajax_referer( 'owc_save_nonce', 'nonce' );
        if ( ! self::check_permission() ) {
            wp_send_json_error( [ 'message' => 'Permisos insuficientes.' ] );
        }

        $wc_id = (int) ( $_POST['wc_id'] ?? 0 );
        if ( ! $wc_id ) {
            wp_send_json_error( [ 'message' => 'ID de WooCommerce no especificado.' ] );
        }

        $res = OWC_Sync_Engine::trash_woo_only_product( $wc_id );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'Error enviando producto a papelera.' ] );
        }
    }

    // ─── Página 1: Configuración ──────────────────────────────────────────────
    public static function render_settings_page() {
        $s = OWC_Settings::get_all();
        $nonce = wp_create_nonce('owc_save_nonce');
        ?>
        <div class="owc-wrap">
            <!-- Header -->
            <div class="owc-header">
                <div class="owc-header-inner">
                    <div class="owc-header-left">
                        <h1>Odoo ↔ WooCommerce</h1>
                        <p>Sistema profesional de sincronización integral bidireccional entre Odoo 15 y WooCommerce.</p>
                    </div>
                    <div class="owc-version-badge">
                        ● v<?php echo esc_html( OWC_VERSION ); ?> — Activo
                    </div>
                </div>
            </div>

            <!-- Contenedor dinámico de alertas y mensajes -->
            <div id="owc-alert-container">
                <?php settings_errors( 'owc_messages' ); ?>
            </div>

            <!-- ── Barra de Navegación por Pestañas ──────────────── -->
            <div class="owc-tabs-nav">
                <button type="button" class="owc-tab-btn active" data-tab="tab-connection" onclick="switchTab('tab-connection')">
                    <span class="owc-tab-icon">🔌</span>
                    <span>Conexión & Sync</span>
                </button>
                <button type="button" class="owc-tab-btn" data-tab="tab-b2b" onclick="switchTab('tab-b2b')">
                    <span class="owc-tab-icon">🛡️</span>
                    <span>Catálogo B2B</span>
                </button>
                <button type="button" class="owc-tab-btn" data-tab="tab-templates" onclick="switchTab('tab-templates')">
                    <span class="owc-tab-icon">🎨</span>
                    <span>Diseño & Plantillas</span>
                </button>
                <button type="button" class="owc-tab-btn" data-tab="tab-colors" onclick="switchTab('tab-colors')">
                    <span class="owc-tab-icon">🎛️</span>
                    <span>Paleta de Colores</span>
                </button>
                <button type="button" class="owc-tab-btn" data-tab="tab-cart" onclick="switchTab('tab-cart')">
                    <span class="owc-tab-icon">🛒</span>
                    <span>Carrito & Checkout</span>
                </button>
                <button type="button" class="owc-tab-btn" data-tab="tab-texts" onclick="switchTab('tab-texts')">
                    <span class="owc-tab-icon">✍</span>
                    <span>Textos del Portal</span>
                </button>
            </div>

            <form id="owc-settings-form" method="post">
                <?php wp_nonce_field( 'owc_save_nonce' ); ?>

                <!-- ══════════════════════════════════════════════════════════════
                     PESTAÑA 1: CONEXIÓN & SINCRONIZACIÓN
                     ══════════════════════════════════════════════════════════════ -->
                <div id="tab-connection" class="owc-tab-panel active">
                <div id="tab-connection" class="owc-tab-panel active">
                    <div class="owc-tab-panel-intro">
                        <div>
                            <h3>🔌 Conexión con Odoo 15 y Sincronización</h3>
                            <p>Vincula las credenciales de tu servidor ERP Odoo, ejecuta pruebas en vivo y gestiona productos y existencias.</p>
                        </div>
                    </div>

                    <!-- Credenciales Odoo -->
                    <div class="owc-card">
                        <h2>🟣 Conexión con Odoo 15</h2>
                        <div class="owc-form-row">
                            <label for="owc_odoo_url">URL del Servidor Odoo</label>
                            <input type="url" id="owc_odoo_url" name="odoo_url" value="<?php echo esc_attr( $s['odoo_url'] ); ?>" placeholder="https://grupoflores.oz-solutions.com" required>
                            <div class="owc-hint">URL base del servidor Odoo (ejemplo: https://tu-odoo.com sin barra final).</div>
                        </div>
                        <div class="owc-form-row">
                            <label for="owc_odoo_db">Base de Datos</label>
                            <input type="text" id="owc_odoo_db" name="odoo_db" value="<?php echo esc_attr( $s['odoo_db'] ); ?>" placeholder="grupo_flores" required>
                            <div class="owc-hint">Nombre exacto de la base de datos de Odoo.</div>
                        </div>
                        <div class="owc-form-row">
                            <label for="owc_odoo_user">Usuario / Email</label>
                            <input type="text" id="owc_odoo_user" name="odoo_user" value="<?php echo esc_attr( $s['odoo_user'] ); ?>" placeholder="admin@tuempresa.com" required>
                            <div class="owc-hint">Correo electrónico o usuario con permisos en Odoo.</div>
                        </div>
                        <div class="owc-form-row">
                            <label for="owc_odoo_password">Contraseña o Clave API (API Key)</label>
                            <input type="password" id="owc_odoo_password" name="odoo_password" value="<?php echo esc_attr( $s['odoo_password'] ); ?>" placeholder="••••••••••••" autocomplete="new-password">
                            <div class="owc-hint">Contraseña de usuario o API Key generada en Preferencias de Odoo.</div>
                        </div>
                    </div>

                    <!-- API Plugin -->
                    <div class="owc-card">
                        <h2>🔑 Token de API (Odoo → WooCommerce)</h2>
                        <div class="owc-form-row">
                            <label for="owc_api_secret_token">Token Secreto de API (Solo lectura)</label>
                            <input type="text" id="owc_api_secret_token" value="<?php echo esc_attr( $s['api_secret_token'] ); ?>" readonly style="background-color: #f1f5f9; cursor: text; user-select: all; padding: 10px; font-family: monospace; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; margin-top: 5px;" onclick="this.select();">
                            <div class="owc-hint">Copia este token y pégalo en el archivo <code>.env</code> (como <code>WOO_API_TOKEN</code>) de tu script en Python. Esto permite subir imágenes sin errores 401.</div>
                        </div>
                    </div>
                    
                    <!-- Agencias de Envío y Asesores -->
                    <div class="owc-card">
                        <h2>📦 Agencias de Envío y Asesores de Venta</h2>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 15px; align-items: start;">

                            <!-- Agencias: Repeater visual -->
                            <div class="owc-form-row">
                                <label>Agencias de Envío:</label>
                                <div class="owc-hint" style="margin-top:4px;margin-bottom:12px;">Agrega cada agencia de transporte. Aparecerán en el checkout como opciones de envío.</div>

                                <!-- Campo oculto que guarda los datos como JSON -->
                                <input type="hidden" name="shipping_agencies_json" id="owc_agencies_json"
                                       value="<?php echo esc_attr( $s['shipping_agencies_json'] ?? '[]' ); ?>">

                                <!-- Textarea legacy (oculto, por compatibilidad) -->
                                <input type="hidden" name="shipping_agencies" value="">

                                <div id="owc-agencies-repeater" style="display:flex; flex-direction:column; gap:8px;">
                                    <!-- Generado por JS -->
                                </div>

                                <button type="button" id="owc-add-agency"
                                        style="margin-top:10px; display:flex; align-items:center; gap:6px; padding:8px 14px; background:#0d4f30; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; width:100%; justify-content:center;">
                                    <span style="font-size:18px; line-height:1;">＋</span> Añadir agencia
                                </button>
                            </div>


                            <!-- Asesores: Repeater visual -->
                            <div class="owc-form-row">
                                <label>Asesores / Sucursales:</label>
                                <div class="owc-hint" style="margin-top:4px;margin-bottom:12px;">Agrega cada asesor con su foto, nombre y número de WhatsApp. La foto se sube desde tu biblioteca de medios.</div>

                                <!-- Campo oculto que guarda los datos como JSON -->
                                <input type="hidden" name="checkout_advisors_json" id="owc_advisors_json"
                                       value="<?php echo esc_attr( $s['checkout_advisors_json'] ?? '[]' ); ?>">

                                <div id="owc-advisors-repeater" style="display:flex; flex-direction:column; gap:10px;">
                                    <!-- Cards generadas por JS -->
                                </div>

                                <button type="button" id="owc-add-advisor"
                                        style="margin-top:12px; display:flex; align-items:center; gap:6px; padding:8px 16px; background:#0047ba; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; width:100%; justify-content:center;">
                                    <span style="font-size:18px; line-height:1;">＋</span> Añadir asesor
                                </button>
                            </div>
                        </div>

                        <div class="owc-form-row" style="margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                            <label>
                                <input type="checkbox" name="enable_internal_digital_card" value="yes" <?php checked( $s['enable_internal_digital_card'] ?? 'yes', 'yes' ); ?>>
                                <strong>Habilitar Tarjeta Digital Interna</strong>
                            </label>
                            <div class="owc-hint">Si activas esto, el enlace del banner abrirá una tarjeta digital generada automáticamente con los Asesores listados arriba. No necesitas alojar un HTML en otro sitio.</div>
                        </div>

                        <div class="owc-form-row" style="margin-top: 15px;">
                            <label for="owc_digital_card_url">Enlace de Tarjeta Digital Externa (Solo si la opción anterior está desactivada):</label>
                            <input type="url" id="owc_digital_card_url" name="digital_card_url" value="<?php echo esc_attr( $s['digital_card_url'] ?? 'https://grupofloressa.com/tarjeta-digital/' ); ?>" placeholder="https://..." style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #d7dee8; margin-top: 6px;">
                            <div class="owc-hint">Si usas una tarjeta externa, coloca el enlace aquí.</div>
                        </div>
                    </div>

                    <!-- JS del Repeater de Agencias -->
                    <script>
                    (function(){
                        var agencies   = [];
                        var jsonField  = document.getElementById('owc_agencies_json');
                        var repeater   = document.getElementById('owc-agencies-repeater');

                        try { agencies = JSON.parse(jsonField.value) || []; } catch(e){ agencies = []; }
                        if (!Array.isArray(agencies)) agencies = [];

                        // Seed defaults if empty
                        if (agencies.length === 0) {
                            agencies = [
                                {name:'AREQUIPA EXPRESO MARVISUR EIRL'},
                                {name:'EMP. DE TRANS. FLORES HNOS. SRL.'},
                                {name:'SHALOM EMPRESARIAL S.A.C.'}
                            ];
                            jsonField.value = JSON.stringify(agencies);
                        }

                        function sync() { jsonField.value = JSON.stringify(agencies); }

                        function renderRow(i) {
                            var div = document.createElement('div');
                            div.style.cssText = 'display:flex;align-items:center;gap:8px;';

                            var icon = document.createElement('span');
                            icon.textContent = '🚚';
                            icon.style.cssText = 'font-size:18px;flex-shrink:0;';

                            var inp = document.createElement('input');
                            inp.type = 'text';
                            inp.placeholder = 'Nombre de la agencia';
                            inp.value = agencies[i].name || '';
                            inp.style.cssText = 'flex:1;padding:7px 10px;border:1px solid #d7dee8;border-radius:8px;font-size:13px;';
                            inp.addEventListener('input', function(){ agencies[i].name = this.value; sync(); });

                            var del = document.createElement('button');
                            del.type = 'button'; del.textContent = '✕';
                            del.style.cssText = 'background:#fee2e2;color:#dc2626;border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:13px;font-weight:700;flex-shrink:0;';
                            del.addEventListener('click', function(){ agencies.splice(i,1); renderAll(); sync(); });

                            div.appendChild(icon);
                            div.appendChild(inp);
                            div.appendChild(del);
                            return div;
                        }

                        function renderAll() {
                            repeater.innerHTML = '';
                            agencies.forEach(function(_,i){ repeater.appendChild(renderRow(i)); });
                            if (agencies.length === 0) {
                                var p = document.createElement('p');
                                p.textContent = 'Sin agencias. Haz clic en "+ Añadir agencia".';
                                p.style.cssText = 'color:#94a3b8;font-size:13px;text-align:center;padding:8px 0;';
                                repeater.appendChild(p);
                            }
                        }

                        document.getElementById('owc-add-agency').addEventListener('click', function(){
                            agencies.push({name:''});
                            renderAll(); sync();
                        });

                        renderAll();
                    })();
                    </script>

                    <!-- JS del Repeater de Asesores -->
                    <script>
                    (function(){
                        var advisors = [];
                        var jsonField = document.getElementById('owc_advisors_json');
                        var repeater  = document.getElementById('owc-advisors-repeater');

                        // Cargar datos guardados
                        try { advisors = JSON.parse(jsonField.value) || []; } catch(e){ advisors = []; }
                        if (!Array.isArray(advisors)) advisors = [];

                        function sync() {
                            jsonField.value = JSON.stringify(advisors);
                        }

                        function avatarHtml(img) {
                            return img
                                ? '<img src="'+img+'" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">'
                                : '<span style="font-size:22px;font-weight:800;color:#0047ba;">👤</span>';
                        }

                        function renderCard(i) {
                            var a   = advisors[i];
                            var div = document.createElement('div');
                            div.dataset.idx = i;
                            div.style.cssText = 'display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;';

                            // Avatar clickable
                            var avBox = document.createElement('div');
                            avBox.title = 'Click para cambiar foto';
                            avBox.style.cssText = 'width:52px;height:52px;border-radius:50%;background:#eef5ff;border:2px dashed #b3c8f0;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;overflow:hidden;';
                            avBox.innerHTML = avatarHtml(a.img);
                            avBox.addEventListener('click', function(){
                                var frame = wp.media({ title:'Elegir foto de asesor', button:{text:'Usar esta foto'}, multiple:false });
                                frame.on('select', function(){
                                    var att = frame.state().get('selection').first().toJSON();
                                    advisors[i].img = att.url;
                                    avBox.innerHTML = avatarHtml(att.url);
                                    sync();
                                });
                                frame.open();
                            });

                            // Campos
                            var fields = document.createElement('div');
                            fields.style.cssText = 'flex:1;display:flex;flex-direction:column;gap:6px;min-width:0;';

                            var inpName = document.createElement('input');
                            inpName.type = 'text'; inpName.placeholder = 'Nombre del asesor';
                            inpName.value = a.name || '';
                            inpName.style.cssText = 'width:100%;padding:6px 8px;border:1px solid #d7dee8;border-radius:6px;font-size:13px;';
                            inpName.addEventListener('input', function(){ advisors[i].name = this.value; sync(); });

                            var inpPhone = document.createElement('input');
                            inpPhone.type = 'tel'; inpPhone.placeholder = 'WhatsApp (ej: 51924730111)';
                            inpPhone.value = a.phone || '';
                            inpPhone.style.cssText = 'width:100%;padding:6px 8px;border:1px solid #d7dee8;border-radius:6px;font-size:13px;';
                            inpPhone.addEventListener('input', function(){ advisors[i].phone = this.value; sync(); });

                            fields.appendChild(inpName);
                            fields.appendChild(inpPhone);

                            // Botón eliminar
                            var del = document.createElement('button');
                            del.type = 'button'; del.textContent = '✕';
                            del.title = 'Eliminar';
                            del.style.cssText = 'background:#fee2e2;color:#dc2626;border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:14px;font-weight:700;flex-shrink:0;align-self:center;';
                            del.addEventListener('click', function(){
                                advisors.splice(i, 1);
                                renderAll();
                                sync();
                            });

                            div.appendChild(avBox);
                            div.appendChild(fields);
                            div.appendChild(del);
                            return div;
                        }

                        function renderAll() {
                            repeater.innerHTML = '';
                            advisors.forEach(function(_, i){ repeater.appendChild(renderCard(i)); });
                            if (advisors.length === 0) {
                                var empty = document.createElement('p');
                                empty.textContent = 'No hay asesores. Haz clic en "Añadir asesor" para comenzar.';
                                empty.style.cssText = 'color:#94a3b8;font-size:13px;text-align:center;padding:12px 0;';
                                repeater.appendChild(empty);
                            }
                        }

                        document.getElementById('owc-add-advisor').addEventListener('click', function(){
                            advisors.push({ name:'', phone:'', img:'' });
                            renderAll();
                            sync();
                        });

                        renderAll();
                    })();
                    </script>


                    <!-- Panel de Sincronización -->
                    <div class="owc-card owc-actions-card">
                        <h2>⚡ Panel de Sincronización</h2>
                        <p style="color:var(--owc-text-dim);font-size:13px;margin-bottom:16px;">
                            Comienza importando el catálogo de Odoo a tu WooCommerce. Los productos faltantes se crearán de inmediato con sus existencias.
                        </p>
                        <div class="owc-actions-grid">
                            <button type="button" id="owc-btn-test" class="owc-action-btn owc-btn-test">
                                <span class="owc-action-icon">🔌</span>
                                <span class="owc-btn-label">Probar Conexión</span>
                            </button>
                            <button type="button" id="owc-btn-products" class="owc-action-btn owc-btn-products">
                                <span class="owc-action-icon">🛍</span>
                                <span class="owc-btn-label">Importar Catálogo (Odoo → Woo)</span>
                            </button>
                            <button type="button" id="owc-btn-stock" class="owc-action-btn owc-btn-stock">
                                <span class="owc-action-icon">📦</span>
                                <span class="owc-btn-label">Actualizar Solo Stock</span>
                            </button>
                            <a href="<?php echo esc_url( admin_url('admin.php?page=' . self::MENU_SLUG . '-catalog') ); ?>" class="owc-action-btn owc-btn-compare">
                                <span class="owc-action-icon">🔍</span>
                                <span class="owc-btn-label">Ver Comparativa Odoo / Woo</span>
                            </a>
                        </div>
                    </div>

                    <!-- Estado de Última Sincronización -->
                    <div class="owc-card">
                        <div class="owc-status-grid">
                            <div class="owc-stat">
                                <div class="label">Última Sync Productos</div>
                                <div class="value" id="owc-stat-products"><?php echo esc_html( $s['last_sync_products'] ?: '—' ); ?></div>
                            </div>
                            <div class="owc-stat">
                                <div class="label">Última Sync Stock</div>
                                <div class="value" id="owc-stat-stock"><?php echo esc_html( $s['last_sync_stock'] ?: '—' ); ?></div>
                            </div>
                            <div class="owc-stat">
                                <div class="label">Última Sync Pedidos</div>
                                <div class="value" id="owc-stat-orders"><?php echo esc_html( $s['last_sync_orders'] ?: '—' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Configuración de Sync Automática -->
                    <div class="owc-card">
                        <h2>🔄 Configuración de Sincronización</h2>
                        <div class="owc-form-row">
                            <label for="owc_sync_match_field">Campo de Emparejamiento (SKU en Odoo)</label>
                            <select id="owc_sync_match_field" name="sync_match_field">
                                <option value="default_code" <?php selected( $s['sync_match_field'], 'default_code' ); ?>>Referencia Interna (default_code) — Recomendado</option>
                                <option value="barcode"      <?php selected( $s['sync_match_field'], 'barcode' ); ?>>Código de Barras (barcode)</option>
                            </select>
                            <div class="owc-hint">Se usará el campo elegido y, si está vacío, el conector probará automáticamente el otro.</div>
                        </div>
                        <div class="owc-form-row">
                            <label for="owc_sync_interval">Intervalo automático de stock “A Mano”</label>
                            <input type="number"
                                   id="owc_sync_interval"
                                   name="sync_interval"
                                   min="1"
                                   step="1"
                                   value="<?php echo esc_attr( max( 1, (int) ( $s['sync_interval'] ?? 5 ) ) ); ?>"
                                   style="max-width:140px;">
                            <div class="owc-hint">WooCommerce recibirá la cantidad “A Mano” de Odoo cada este número de minutos.</div>
                        </div>
                        <div class="owc-form-row">
                            <label>
                                <input type="checkbox" name="sync_images" value="yes" <?php checked( $s['sync_images'] ?? 'yes', 'yes' ); ?>>
                                Sincronizar imágenes de Odoo
                            </label>
                            <div class="owc-hint">Importa la imagen principal cuando el producto aún no tiene una en WooCommerce.</div>
                        </div>
                        <div class="owc-form-row">
                            <label>
                                <input type="checkbox" name="sync_orders" value="yes" <?php checked( $s['sync_orders'], 'yes' ); ?>>
                                Enviar pedidos de WooCommerce a Odoo automáticamente al completarse
                            </label>
                        </div>
                        <div class="owc-form-row">
                            <label>
                                <input type="checkbox" name="auto_create_products" value="yes" <?php checked( $s['auto_create_products'], 'yes' ); ?>>
                                Crear productos almacenables en WooCommerce al importar el catálogo
                            </label>
                        </div>
                    </div>
                </div> <!-- /#tab-connection -->
                </div>

                <!-- Agencias de Envío -->

            <!-- ══════════════════════════════════════════════════════════════
                 PESTAÑA 2: CATÁLOGO B2B & PRECIOS
                 ══════════════════════════════════════════════════════════════ -->
            <div id="tab-b2b" class="owc-tab-panel">
                <!-- Modo B2B, Precios a Invitados y Roles -->
                <div class="owc-card owc-b2b-card">
                    <h2>Catálogo B2B</h2>
                    <?php self::render_b2b_requests_panel(); ?>
                    <div class="owc-b2b-settings">
                    <div class="owc-form-row owc-b2b-toggle">
                        <label>
                            <input type="checkbox" name="hide_prices_guest" value="yes" <?php checked( $s['hide_prices_guest'] ?? 'yes', 'yes' ); ?>>
                            Ocultar precios a visitantes
                        </label>
                    </div>
                    <div class="owc-form-row owc-b2b-field">
                        <label for="owc_guest_price_text">Texto de acceso</label>
                        <input type="text" id="owc_guest_price_text" name="guest_price_text" value="<?php echo esc_attr( $s['guest_price_text'] ?? 'Iniciar sesión | Registrarse para ver precio' ); ?>" placeholder="Iniciar sesión | Registrarse para ver precio">
                    </div>
                    <div class="owc-form-row owc-b2b-toggle">
                        <label>
                            <input type="checkbox" name="enable_igv_display" value="yes" <?php checked( $s['enable_igv_display'] ?? 'yes', 'yes' ); ?>>
                            Mostrar IGV
                        </label>
                    </div>
                    <div class="owc-form-row owc-b2b-field owc-b2b-igv-rate">
                        <label for="owc_igv_rate">IGV (%)</label>
                        <input type="number" id="owc_igv_rate" name="igv_rate" value="<?php echo esc_attr( $s['igv_rate'] ?? 18 ); ?>" min="0" max="100" step="0.5" style="max-width:140px;">
                    </div>
                    <div class="owc-form-row owc-b2b-toggle">
                        <label>
                            <input type="checkbox" name="enable_role_registration" value="yes" <?php checked( $s['enable_role_registration'] ?? 'yes', 'yes' ); ?>>
                            Habilitar registro B2B
                        </label>
                    </div>
                    </div>
                </div>
            </div> <!-- /#tab-b2b -->

            <!-- ══════════════════════════════════════════════════════════════
                 PESTAÑA 3: DISEÑO & PLANTILLAS
                 ══════════════════════════════════════════════════════════════ -->
            <div id="tab-templates" class="owc-tab-panel">
                <div class="owc-tab-panel-intro">
                    <div>
                        <h3>🎨 Plantillas de Diseño & Arquitectura Visual</h3>
                        <p>Elige el arquetipo estético tipo tema y la estructura de maquetación para Login, Registro y Mi Cuenta.</p>
                    </div>
                </div>

                <!-- CARD 1: Plantillas de Diseño & Estilos Visuales (Temas) y Maquetación -->
                <div class="owc-card">
                    <h2>🎨 Plantillas de Diseño & Arquitectura Visual (Login, Registro y Mi Cuenta)</h2>
                    <p style="color:var(--owc-text-dim);font-size:13px;margin-bottom:16px;">
                        Selecciona el arquetipo visual tipo tema y la disposición de columnas para el portal de clientes y mayoristas en <code>/mi-cuenta</code>. Todos los diseños son responsivos y están optimizados para conversión B2B.
                    </p>

                    <!-- SECCIÓN 1: ESTILOS VISUALES / TIPO TEMAS -->
                    <div class="owc-tpl-section-title">
                        <span>🎭 1. Selecciona el Estilo Visual / Tipo Tema</span>
                    </div>

                    <?php 
                    $cur_theme = $s['design_theme'] ?? 'cyber_glass';
                    ?>
                    <div class="owc-tpl-grid owc-theme-grid">
                        <!-- Theme 1: Cyber Glassmorphism -->
                        <div class="owc-tpl-card <?php echo $cur_theme === 'cyber_glass' ? 'active' : ''; ?>" onclick="selectTheme('cyber_glass', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-theme-cyber">
                                    <div class="mock-line-pri" style="width:50%;"></div>
                                    <div class="mock-line" style="width:85%;"></div>
                                    <div class="mock-line" style="width:70%;"></div>
                                    <div class="mock-line-pri" style="width:40%;height:8px;margin-top:2px;"></div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Cyber Glassmorphism</span>
                                        <span style="font-size:10px;color:var(--owc-primary);font-weight:700;">★ RECOMENDADO</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Estética High-Tech con fondo oscuro, tarjetas translúcidas con desenfoque de cristal, bordes luminosos y badges interactivos.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="design_theme" value="cyber_glass" <?php checked( $cur_theme, 'cyber_glass' ); ?>>
                                    <span><?php echo $cur_theme === 'cyber_glass' ? '✔ Tema Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Theme 2: Minimalist Clean Light -->
                        <div class="owc-tpl-card <?php echo $cur_theme === 'minimal_light' ? 'active' : ''; ?>" onclick="selectTheme('minimal_light', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-theme-minimal">
                                    <div class="mock-line-pri" style="width:50%;"></div>
                                    <div class="mock-line" style="width:80%;"></div>
                                    <div class="mock-line" style="width:65%;"></div>
                                    <div class="mock-line-pri" style="width:45%;height:8px;margin-top:2px;"></div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Minimalist Clean Light</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Diseño SaaS contemporáneo claro, tarjetas blancas elevadas sobre fondo sutil, sombras tenues y máxima claridad tipográfica.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="design_theme" value="minimal_light" <?php checked( $cur_theme, 'minimal_light' ); ?>>
                                    <span><?php echo $cur_theme === 'minimal_light' ? '✔ Tema Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Theme 3: Executive Slate Corporate -->
                        <div class="owc-tpl-card <?php echo $cur_theme === 'corp_slate' ? 'active' : ''; ?>" onclick="selectTheme('corp_slate', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-theme-slate">
                                    <div class="mock-line-pri" style="width:55%;"></div>
                                    <div class="mock-line" style="width:75%;"></div>
                                    <div class="mock-line" style="width:60%;"></div>
                                    <div class="mock-line-pri" style="width:40%;height:8px;margin-top:2px;"></div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Executive Slate</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Look corporativo serio y profesional en tonos azul pizarra y grafito estructurado, ideal para distribuidores y empresas mayoristas.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="design_theme" value="corp_slate" <?php checked( $cur_theme, 'corp_slate' ); ?>>
                                    <span><?php echo $cur_theme === 'corp_slate' ? '✔ Tema Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Theme 4: Luxury Noir & Gold -->
                        <div class="owc-tpl-card <?php echo $cur_theme === 'luxury_noir' ? 'active' : ''; ?>" onclick="selectTheme('luxury_noir', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-theme-luxury">
                                    <div class="mock-line-pri" style="width:50%;"></div>
                                    <div class="mock-line" style="width:80%;"></div>
                                    <div class="mock-line" style="width:65%;"></div>
                                    <div class="mock-line-pri" style="width:40%;height:8px;margin-top:2px;"></div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Luxury Noir Gold</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Alta distinción boutique con fondo negro obsidiana, bordes finos en oro ámbar y acentos refinados de lujo.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="design_theme" value="luxury_noir" <?php checked( $cur_theme, 'luxury_noir' ); ?>>
                                    <span><?php echo $cur_theme === 'luxury_noir' ? '✔ Tema Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Theme 5: Nativo WooCommerce -->
                        <div class="owc-tpl-card <?php echo $cur_theme === 'native' ? 'active' : ''; ?>" onclick="selectTheme('native', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-native-wireframe">
                                    <span>⚙ Tema Activo WP</span>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Tema Nativo WooCommerce</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Mantiene intacta la estética de tu tema activo de WordPress, inyectando de forma limpia las validaciones B2B y campos de RUC/Empresa.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="design_theme" value="native" <?php checked( $cur_theme, 'native' ); ?>>
                                    <span><?php echo $cur_theme === 'native' ? '✔ Tema Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 2: ARQUITECTURA / MAQUETACIÓN -->
                    <div class="owc-tpl-section-title" style="margin-top:24px;">
                        <span>📐 2. Selecciona la Arquitectura y Maquetación (Layout de Login / Registro)</span>
                    </div>

                    <?php 
                    $cur_layout = $s['auth_layout'] ?? 'split_screen';
                    if ( $cur_layout === 'glassmorphism' ) $cur_layout = 'split_screen';
                    if ( $cur_layout === 'corporate' )    $cur_layout = 'side_by_side';
                    if ( $cur_layout === 'luxe' )         $cur_layout = 'centered_card';
                    if ( $cur_layout === 'none' )         $cur_layout = 'native';
                    ?>
                    <div class="owc-tpl-grid owc-layout-grid">
                        <!-- Layout 1: Split Screen -->
                        <div class="owc-tpl-card <?php echo $cur_layout === 'split_screen' ? 'active' : ''; ?>" onclick="selectLayout('split_screen', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-split-wireframe">
                                    <div class="mock-split-hero">
                                        <span>⚡ ODOO 15</span>
                                        <div style="font-size:7px;opacity:0.8;margin-top:2px;">B2B LIVE</div>
                                    </div>
                                    <div class="mock-split-form">
                                        <div class="mock-line" style="width:70%;"></div>
                                        <div class="mock-line"></div>
                                        <div class="mock-line"></div>
                                        <div class="mock-line-pri" style="margin-top:2px;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Split-Screen High-Tech</span>
                                        <span style="font-size:10px;color:var(--owc-primary);font-weight:700;">★ TOP B2B</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Doble columna con Panel Hero corporativo a la izquierda (beneficios B2B y sync Odoo) y formulario interactivo a la derecha.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="auth_layout" value="split_screen" <?php checked( $cur_layout, 'split_screen' ); ?>>
                                    <span><?php echo $cur_layout === 'split_screen' ? '✔ Layout Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Layout 2: Centered Floating Card -->
                        <div class="owc-tpl-card <?php echo $cur_layout === 'centered_card' ? 'active' : ''; ?>" onclick="selectLayout('centered_card', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-centered-wireframe">
                                    <div class="mock-line" style="width:50%;margin:0 auto;"></div>
                                    <div class="mock-line"></div>
                                    <div class="mock-line"></div>
                                    <div class="mock-line-pri" style="margin-top:2px;"></div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Floating Centered Card</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Tarjeta flotante centrada, ultra limpia y moderna con pestañas interactivas Login / Registro. Carga rápida y minimalismo.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="auth_layout" value="centered_card" <?php checked( $cur_layout, 'centered_card' ); ?>>
                                    <span><?php echo $cur_layout === 'centered_card' ? '✔ Layout Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Layout 3: Side by Side (Dual Portal) -->
                        <div class="owc-tpl-card <?php echo $cur_layout === 'side_by_side' ? 'active' : ''; ?>" onclick="selectLayout('side_by_side', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-dual-wireframe">
                                    <div class="mock-dual-col">
                                        <div class="mock-line" style="width:60%;"></div>
                                        <div class="mock-line"></div>
                                        <div class="mock-line-pri"></div>
                                    </div>
                                    <div class="mock-dual-col">
                                        <div class="mock-line" style="width:60%;"></div>
                                        <div class="mock-line"></div>
                                        <div class="mock-line-pri"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Executive Dual Portal</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Dos columnas comparativas simultáneas: Iniciar Sesión a la izquierda y Registro B2B a la derecha en una misma vista.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="auth_layout" value="side_by_side" <?php checked( $cur_layout, 'side_by_side' ); ?>>
                                    <span><?php echo $cur_layout === 'side_by_side' ? '✔ Layout Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- Layout 4: Nativa del Tema -->
                        <div class="owc-tpl-card <?php echo $cur_layout === 'native' ? 'active' : ''; ?>" onclick="selectLayout('native', this)">
                            <div class="owc-tpl-preview-mock">
                                <div class="mock-native-wireframe">
                                    <span>⚙ Tema Estándar WP</span>
                                </div>
                            </div>
                            <div class="owc-tpl-info">
                                <div>
                                    <div class="owc-tpl-name">
                                        <span>Plantilla Nativa (WooCommerce)</span>
                                    </div>
                                    <div class="owc-tpl-desc">
                                        Conserva los contenedores y tipografía original de tu tema activo sin encapsular en tarjetas adicionales.
                                    </div>
                                </div>
                                <label class="owc-tpl-radio-wrap">
                                    <input type="radio" name="auth_layout" value="native" <?php checked( $cur_layout, 'native' ); ?>>
                                    <span><?php echo $cur_layout === 'native' ? '✔ Layout Activo' : 'Seleccionar'; ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div> <!-- /#tab-templates -->

            <!-- ══════════════════════════════════════════════════════════════
                 PESTAÑA 4: PALETA DE COLORES
                 ══════════════════════════════════════════════════════════════ -->
            <div id="tab-colors" class="owc-tab-panel">
                <div class="owc-tab-panel-intro">
                    <div>
                        <h3>🎛️ Control Total de la Paleta de Colores</h3>
                        <p>Personaliza cada color de la interfaz. Aplica presets en 1 clic y observa el resultado en la vista previa interactiva en tiempo real.</p>
                    </div>
                </div>

                <!-- CARD 2: Control Total de la Paleta de Colores (Manejada por Ti) -->
                <div class="owc-card">
                    <h2>🎛️ Control Total de la Paleta de Colores (Manejada por Ti)</h2>
                    <p style="color:var(--owc-text-dim);font-size:13px;margin-bottom:14px;">
                        Tú tienes el control absoluto de cada color. Puedes hacer clic en cualquiera de los <strong>Presets Rápidos</strong> para cargar combinaciones armoniosas y luego afinar cada selector individual a tu gusto. Todos los cambios se aplican automáticamente a la vista previa interactiva.
                    </p>

                    <!-- Campo oculto para guardar la paleta seleccionada -->
                    <input type="hidden" name="color_palette" id="owc_color_palette" value="<?php echo esc_attr( $s['color_palette'] ?? 'emerald' ); ?>">

                    <!-- Barra de Presets Rápidos en 1 Clic -->
                    <div class="owc-preset-bar">
                        <span class="owc-preset-bar-title">⚡ Cargar Preset Rápido en 1 Clic:</span>
                        <button type="button" class="owc-preset-btn" style="border: 2px solid #0047ba; background: rgba(0,71,186,0.08); font-weight: 800; color: #0047ba;" onclick="applyColorPreset('grupo_flores')">
                            <span class="owc-preset-dot" style="background:#0047ba; box-shadow: 0 0 6px rgba(0,71,186,0.6);"></span>
                            <span class="owc-preset-dot" style="background:#f37021; box-shadow: 0 0 6px rgba(243,112,33,0.6); margin-left: -5px;"></span>
                            <span>⭐ Grupo Flores (Oficial)</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('cyber_neon')">
                            <span class="owc-preset-dot" style="background:#00d4aa;"></span>
                            <span>Cyber Neon</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('corp_slate')">
                            <span class="owc-preset-dot" style="background:#2563eb;"></span>
                            <span>Corporate Blue</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('luxury_gold')">
                            <span class="owc-preset-dot" style="background:#f59e0b;"></span>
                            <span>Luxury Gold</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('synth_violet')">
                            <span class="owc-preset-dot" style="background:#a855f7;"></span>
                            <span>Synthwave Violet</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('minimal_light')">
                            <span class="owc-preset-dot" style="background:#0284c7;"></span>
                            <span>Minimalist Light</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('elegant_cream')">
                            <span class="owc-preset-dot" style="background:#e67e22;"></span>
                            <span>Elegant Cream</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('ocean_breeze')">
                            <span class="owc-preset-dot" style="background:#0891b2;"></span>
                            <span>Ocean Breeze</span>
                        </button>
                        <button type="button" class="owc-preset-btn" onclick="applyColorPreset('crimson_red')">
                            <span class="owc-preset-dot" style="background:#ef4444;"></span>
                            <span>Industrial Red</span>
                        </button>
                    </div>

                    <!-- Grid de 8 Selectores de Color Granulares -->
                    <div class="owc-palette-picker-grid">
                        <!-- 1. Primario -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_primary_color" name="custom_primary_color" value="<?php echo esc_attr( $s['custom_primary_color'] ?? '#00d4aa' ); ?>">
                            <div>
                                <label for="owc_custom_primary_color">Color Primario / Neón</label>
                                <span class="field-desc">Botones principales, acentos destacados y badges activos.</span>
                            </div>
                        </div>

                        <!-- 2. Acento Secundario -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_accent_color" name="custom_accent_color" value="<?php echo esc_attr( $s['custom_accent_color'] ?? '#0284c7' ); ?>">
                            <div>
                                <label for="owc_custom_accent_color">Color de Acento Secundario</label>
                                <span class="field-desc">Degradados de fondo, bordes activos y elementos de apoyo.</span>
                            </div>
                        </div>

                        <!-- 3. Fondo General -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_bg_color" name="custom_bg_color" value="<?php echo esc_attr( $s['custom_bg_color'] ?? '#090e17' ); ?>">
                            <div>
                                <label for="owc_custom_bg_color">Fondo General (Background)</label>
                                <span class="field-desc">Lienzo principal de la página de Mi Cuenta y Carrito.</span>
                            </div>
                        </div>

                        <!-- 4. Superficie 1 (Tarjetas) -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_surface_color" name="custom_surface_color" value="<?php echo esc_attr( $s['custom_surface_color'] ?? '#111827' ); ?>">
                            <div>
                                <label for="owc_custom_surface_color">Fondo de Tarjetas (Surface 1)</label>
                                <span class="field-desc">Contenedores de login, tarjetas de totales y tablas.</span>
                            </div>
                        </div>

                        <!-- 5. Superficie 2 (Inputs/Campos) -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_surface_2_color" name="custom_surface_2_color" value="<?php echo esc_attr( $s['custom_surface_2_color'] ?? '#1a2332' ); ?>">
                            <div>
                                <label for="owc_custom_surface_2_color">Fondo de Inputs (Surface 2)</label>
                                <span class="field-desc">Cajas de texto, filas de productos y selects.</span>
                            </div>
                        </div>

                        <!-- 6. Texto Principal -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_text_color" name="custom_text_color" value="<?php echo esc_attr( $s['custom_text_color'] ?? '#ffffff' ); ?>">
                            <div>
                                <label for="owc_custom_text_color">Color de Texto Principal</label>
                                <span class="field-desc">Títulos, nombres de producto y precios.</span>
                            </div>
                        </div>

                        <!-- 7. Texto Secundario -->
                        <div class="owc-palette-field">
                            <input type="color" id="owc_custom_text_dim_color" name="custom_text_dim_color" value="<?php echo esc_attr( $s['custom_text_dim_color'] ?? '#94a3b8' ); ?>">
                            <div>
                                <label for="owc_custom_text_dim_color">Color de Texto Secundario</label>
                                <span class="field-desc">Etiquetas descriptivas, placeholders y subtítulos.</span>
                            </div>
                        </div>

                        <!-- 8. Bordes y Separadores -->
                        <div class="owc-palette-field">
                            <div style="flex-grow:1;">
                                <label for="owc_custom_border_color">Color de Bordes / Separadores</label>
                                <input type="text" id="owc_custom_border_color" name="custom_border_color" value="<?php echo esc_attr( $s['custom_border_color'] ?? 'rgba(0, 212, 170, 0.22)' ); ?>" placeholder="rgba(...) o #hex" style="width:100%;margin-top:4px;">
                                <span class="field-desc" style="margin-top:2px;">Bordes perimetrales de tarjetas e inputs (admite Hex o RGBA).</span>
                            </div>
                        </div>
                    </div>

                    <!-- Vista Previa Interactiva en Tiempo Real -->
                    <div style="margin-top:14px;">
                        <span style="font-size:12.5px;font-weight:700;color:var(--owc-primary);display:flex;align-items:center;gap:6px;">
                            <span>👁 Vista Previa Interactiva en Vivo (Se actualiza al instante con tus colores):</span>
                        </span>
                    </div>

                    <div id="owc-live-preview" class="owc-live-preview-box">
                        <!-- Muestra de Componentes de Acceso / Login -->
                        <div class="preview-sample-card">
                            <div class="preview-sample-badge">
                                <span class="owc-live-dot"></span>
                                <span>ODOO 15 CONECTADO</span>
                            </div>
                            <div style="font-size:14px;font-weight:800;color:var(--prev-text, #fff);margin-bottom:8px;">Iniciar Sesión B2B</div>
                            <input type="text" class="preview-sample-input" value="usuario@empresa.com" readonly>
                            <input type="password" class="preview-sample-input" value="••••••••••••" readonly>
                            <button type="button" class="preview-sample-btn">Ingresar al Portal Empresarial ➔</button>
                        </div>

                        <!-- Muestra de Fila de Carrito & Totales -->
                        <div class="preview-sample-card">
                            <div style="font-size:13px;font-weight:800;color:var(--prev-text, #fff);margin-bottom:10px;display:flex;justify-content:space-between;">
                                <span>🛒 Muestra de Carrito</span>
                                <span style="font-size:11px;color:var(--prev-dim, #94a3b8);">2 Artículos</span>
                            </div>
                            <div class="preview-sample-cart-item">
                                <div>
                                    <div style="font-weight:700;">Válvula Industrial B2B</div>
                                    <div style="font-size:10.5px;color:var(--prev-dim, #94a3b8);">Cant: 10 unds</div>
                                </div>
                                <div style="font-weight:800;color:var(--prev-pri, #00d4aa);">S/ 1,450.00</div>
                            </div>
                            <div class="preview-sample-total">
                                <span>Total del Pedido:</span>
                                <span class="price">S/ 1,711.00 <span style="font-size:10px;font-weight:400;color:var(--prev-dim, #94a3b8);">(con IGV)</span></span>
                            </div>
                            <div style="margin-top:12px;">
                                <button type="button" class="preview-sample-btn" style="padding:9px 16px;font-size:12px;">Finalizar Pedido Ahora ✔</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div> <!-- /#tab-colors -->

            <!-- ══════════════════════════════════════════════════════════════
                 PESTAÑA 5: CARRITO & CHECKOUT
                 ══════════════════════════════════════════════════════════════ -->
            <div id="tab-cart" class="owc-tab-panel">
                <div class="owc-tab-panel-intro">
                    <div>
                        <h3>🛒 Carrito de Compras & Finalizar Compra</h3>
                        <p>Moderniza la presentación en el Carrito (<code>/carrito</code>) y en la Pantalla de Pago (<code>/finalizar-compra</code>).</p>
                    </div>
                </div>

                <!-- CARD 3: Diseño y Personalización del Carrito & Checkout -->
                <div class="owc-card">
                    <h2>🛒 Forma de Cómo se Ve el Carrito & Finalizar Compra (WooCommerce)</h2>
                    <p style="color:var(--owc-text-dim);font-size:13px;margin-bottom:16px;">
                        Controla el diseño, disposición de columnas y elementos de confianza del Carrito (<code>/carrito</code>) y de la Pantalla de Pago (<code>/finalizar-compra</code>).
                    </p>

                    <div class="owc-form-row">
                        <label>
                            <input type="checkbox" name="enable_cart_styling" value="yes" <?php checked( $s['enable_cart_styling'] ?? 'yes', 'yes' ); ?>>
                            <strong>Activar Rediseño Moderno para la Página del Carrito (/carrito)</strong>
                        </label>
                        <div class="owc-hint">Aplica la paleta de colores personalizada, tarjetas elevadas, botones de cantidad rediseñados y tabla limpia de productos.</div>
                    </div>

                    <!-- Selector de Maquetación del Carrito -->
                    <div class="owc-tpl-section-title" style="margin-top:16px;">
                        <span>📐 Disposición y Maquetación del Carrito de Compras</span>
                    </div>

                    <?php $cur_cart_layout = $s['cart_layout'] ?? 'modern_split'; ?>
                    <div class="owc-cart-grid">
                        <!-- Carrito Split Screen (2 Columnas) -->
                        <div class="owc-cart-card <?php echo $cur_cart_layout === 'modern_split' ? 'active' : ''; ?>" onclick="selectCartLayout('modern_split', this)">
                            <div class="owc-tpl-preview-mock" style="height:95px;margin:-18px -18px 14px -18px;border-radius:12px 12px 0 0;">
                                <div class="mock-cart-split">
                                    <div class="mock-cart-split-table">
                                        <div class="mock-line" style="width:70%;"></div>
                                        <div class="mock-line"></div>
                                        <div class="mock-line"></div>
                                    </div>
                                    <div class="mock-cart-split-totals">
                                        <div class="mock-line-pri" style="width:80%;"></div>
                                        <div class="mock-line" style="margin-top:3px;"></div>
                                        <div class="mock-line-pri" style="height:7px;margin-top:4px;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="owc-cart-title">
                                <span>Modern Split Layout (2 Columnas)</span>
                                <span style="font-size:10px;color:var(--owc-primary);font-weight:700;">★ RECOMENDADO</span>
                            </div>
                            <div class="owc-cart-desc">
                                Productos en tarjeta a la izquierda y resumen de totales fijado de forma flotante (sticky) a la derecha con botón de compra de alta visibilidad.
                            </div>
                            <label class="owc-tpl-radio-wrap">
                                <input type="radio" name="cart_layout" value="modern_split" <?php checked( $cur_cart_layout, 'modern_split' ); ?>>
                                <span><?php echo $cur_cart_layout === 'modern_split' ? '✔ Maquetación Activa' : 'Seleccionar'; ?></span>
                            </label>
                        </div>

                        <!-- Carrito Clásico Apilado -->
                        <div class="owc-cart-card <?php echo $cur_cart_layout === 'classic_stacked' ? 'active' : ''; ?>" onclick="selectCartLayout('classic_stacked', this)">
                            <div class="owc-tpl-preview-mock" style="height:95px;margin:-18px -18px 14px -18px;border-radius:12px 12px 0 0;">
                                <div class="mock-cart-stacked">
                                    <div class="mock-line" style="width:60%;"></div>
                                    <div class="mock-line"></div>
                                    <div class="mock-line-pri" style="height:7px;margin-top:4px;"></div>
                                </div>
                            </div>
                            <div class="owc-cart-title">
                                <span>Clean Stacked (1 Columna Apilada)</span>
                            </div>
                            <div class="owc-cart-desc">
                                Disposición tradicional apilada de arriba hacia abajo con la estética, tipografía y colores de la paleta personalizada.
                            </div>
                            <label class="owc-tpl-radio-wrap">
                                <input type="radio" name="cart_layout" value="classic_stacked" <?php checked( $cur_cart_layout, 'classic_stacked' ); ?>>
                                <span><?php echo $cur_cart_layout === 'classic_stacked' ? '✔ Maquetación Activa' : 'Seleccionar'; ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="owc-form-row" style="margin-top:16px;">
                        <label>
                            <input type="checkbox" name="cart_b2b_badge" value="yes" <?php checked( $s['cart_b2b_badge'] ?? 'yes', 'yes' ); ?>>
                            <strong>Mostrar Badge de Confianza B2B sincronizado con Odoo 15 debajo del Total</strong>
                        </label>
                        <div class="owc-hint">Inyecta un distintivo de garantía: <em>'Precios y existencias sincronizados en vivo con Odoo 15'</em> con punto de pulsación verde.</div>
                    </div>

                    <div class="owc-form-row">
                        <label>
                            <input type="checkbox" name="enable_checkout_styling" value="yes" <?php checked( $s['enable_checkout_styling'] ?? 'yes', 'yes' ); ?>>
                            <strong>Estilizar también la Página de Checkout / Finalizar Compra (/checkout)</strong>
                        </label>
                        <div class="owc-hint">Aplica la disposición en dos columnas, tarjetas de métodos de pago redondeadas y botón de confirmación de pedido con tu paleta.</div>
                    </div>
                </div>
            </div> <!-- /#tab-cart -->

            <!-- ══════════════════════════════════════════════════════════════
                 PESTAÑA 6: TEXTOS DEL PORTAL
                 ══════════════════════════════════════════════════════════════ -->
            <div id="tab-texts" class="owc-tab-panel">
                <div class="owc-tab-panel-intro">
                    <div>
                        <h3>✍ Textos y Mensajes de Bienvenida</h3>
                        <p>Adapta los títulos, subtítulos y llamados a la acción visibles en la pantalla de acceso de tus clientes.</p>
                    </div>
                </div>

                <!-- CARD 4: Personalización de Textos y Mensajes de Bienvenida -->
                <div class="owc-card">
                    <h2>✍ Textos y Mensajes de Bienvenida (Portal B2B & Carrito)</h2>
                    <p style="color:var(--owc-text-dim);font-size:13px;margin-bottom:14px;">
                        Adapta los mensajes de bienvenida y títulos que verán tus clientes y distribuidores en la pantalla de acceso.
                    </p>

                    <div class="owc-form-row">
                        <label for="owc_auth_title">Título Principal en Pantalla de Acceso</label>
                        <input type="text" id="owc_auth_title" name="auth_title" value="<?php echo esc_attr( $s['auth_title'] ?? 'Portal Empresarial & Tienda B2B' ); ?>" placeholder="Portal Empresarial & Tienda B2B">
                    </div>
                    <div class="owc-form-row">
                        <label for="owc_auth_subtitle">Subtítulo / Mensaje de Bienvenida</label>
                        <input type="text" id="owc_auth_subtitle" name="auth_subtitle" value="<?php echo esc_attr( $s['auth_subtitle'] ?? 'Accede a tu cuenta para ver precios exclusivos, existencias y pedidos.' ); ?>" placeholder="Accede a tu cuenta para ver precios exclusivos, existencias y pedidos.">
                    </div>
                    <div class="owc-form-row">
                        <label for="owc_auth_hero_title">Título en Panel Hero Lateral (Diseño Split-Screen)</label>
                        <input type="text" id="owc_auth_hero_title" name="auth_hero_title" value="<?php echo esc_attr( $s['auth_hero_title'] ?? 'Acceso para Clientes y Ferreteros' ); ?>" placeholder="Acceso para Clientes y Ferreteros">
                    </div>
                    <div class="owc-form-row">
                        <label for="owc_auth_hero_subtitle">Subtítulo / Descripción en Panel Hero Lateral</label>
                        <input type="text" id="owc_auth_hero_subtitle" name="auth_hero_subtitle" value="<?php echo esc_attr( $s['auth_hero_subtitle'] ?? 'Gestiona tus compras mayoristas, cotizaciones y pedidos sincronizados en tiempo real con Odoo 15.' ); ?>" placeholder="Gestiona tus compras mayoristas...">
                    </div>
                </div>
            </div> <!-- /#tab-texts -->

            <!-- ── Barra Fija Inferior para Guardar Cambios ─────── -->
            <div class="owc-sticky-bar">
                <div class="owc-bar-info">
                    <span class="owc-live-dot"></span>
                    <span>Configuración de <strong>Odoo 15 ↔ WooCommerce</strong> lista para guardar</span>
                </div>
                <div class="owc-bar-actions">
                    <button type="submit" name="owc_save_settings" value="1" class="owc-save-btn">
                        💾 Guardar Todos los Cambios
                    </button>
                </div>
            </div>
        </form>

            <!-- Footer -->
            <div class="owc-footer">
                <span>Odoo WooCommerce Connector — Sincronización de Catálogo, Stock y Pedidos</span>
            </div>
        </div>

        <script>
        (function() {
            const form = document.getElementById('owc-settings-form');
            const alertContainer = document.getElementById('owc-alert-container');
            const ajaxNonce = '<?php echo esc_js( $nonce ); ?>';

            function showAlert(type, message) {
                const icon = type === 'success' ? '✔' : (type === 'error' ? '✘' : 'ℹ');
                alertContainer.innerHTML = `
                    <div class="owc-alert-box ${type}">
                        <span class="owc-alert-icon">${icon}</span>
                        <div class="owc-alert-text">${message}</div>
                        <button type="button" class="owc-alert-close" onclick="this.parentElement.remove()">✕</button>
                    </div>
                `;
                alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function getFormData(actionName) {
                const data = new FormData(form);
                data.append('action', actionName);
                data.append('nonce', ajaxNonce);
                return data;
            }

            function setBtnLoading(btn, isLoading, loadingText) {
                const label = btn.querySelector('.owc-btn-label');
                if (isLoading) {
                    btn.disabled = true;
                    btn.dataset.origText = label.textContent;
                    label.innerHTML = `<span class="owc-spinning">⏳</span> ${loadingText}`;
                } else {
                    btn.disabled = false;
                    label.textContent = btn.dataset.origText || 'Acción';
                }
            }

            // ── Navegación por Pestañas Organizadas ──
            window.switchTab = function(tabId) {
                document.querySelectorAll('.owc-tabs-nav .owc-tab-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.tab === tabId);
                });
                document.querySelectorAll('.owc-tab-panel').forEach(panel => {
                    panel.classList.toggle('active', panel.id === tabId);
                });
                try {
                    localStorage.setItem('owc_active_tab', tabId);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, null, '#' + tabId);
                    }
                } catch(e) {}
            };

            // Restaurar pestaña activa guardada o por URL Hash al cargar
            const hashTab = window.location.hash ? window.location.hash.substring(1) : null;
            const savedTab = localStorage.getItem('owc_active_tab');
            const initialTab = hashTab || savedTab || 'tab-connection';
            if (document.getElementById(initialTab)) {
                switchTab(initialTab);
            }

            // ── Selector visual interactivo de Temas de Diseño (Design Archetypes) ──
            window.selectTheme = function(themeKey, cardEl) {
                document.querySelectorAll('.owc-theme-grid .owc-tpl-card').forEach(c => {
                    c.classList.remove('active');
                    const span = c.querySelector('.owc-tpl-radio-wrap span');
                    if (span) span.textContent = 'Seleccionar';
                });
                cardEl.classList.add('active');
                const radio = cardEl.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                const activeSpan = cardEl.querySelector('.owc-tpl-radio-wrap span');
                if (activeSpan) activeSpan.textContent = '✔ Tema Activo';
            };

            // ── Selector visual interactivo de Maquetación / Layout (Login / Registro) ──
            window.selectLayout = function(layoutKey, cardEl) {
                document.querySelectorAll('.owc-layout-grid .owc-tpl-card').forEach(c => {
                    c.classList.remove('active');
                    const span = c.querySelector('.owc-tpl-radio-wrap span');
                    if (span) span.textContent = 'Seleccionar';
                });
                cardEl.classList.add('active');
                const radio = cardEl.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                const activeSpan = cardEl.querySelector('.owc-tpl-radio-wrap span');
                if (activeSpan) activeSpan.textContent = '✔ Layout Activo';
            };

            // ── Selector visual interactivo de Maquetación del Carrito ──
            window.selectCartLayout = function(layoutKey, cardEl) {
                document.querySelectorAll('.owc-cart-grid .owc-cart-card').forEach(c => {
                    c.classList.remove('active');
                    const span = c.querySelector('.owc-tpl-radio-wrap span');
                    if (span) span.textContent = 'Seleccionar';
                });
                cardEl.classList.add('active');
                const radio = cardEl.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                const activeSpan = cardEl.querySelector('.owc-tpl-radio-wrap span');
                if (activeSpan) activeSpan.textContent = '✔ Maquetación Activa';
            };

            // ── Presets Rápidos de Color (Control total sin bloquear ajustes manuales) ──
            const colorPresets = {
                cyber_neon: {
                    pri: '#00d4aa',
                    acc: '#0284c7',
                    bg: '#090e17',
                    s1: '#111827',
                    s2: '#1a2332',
                    txt: '#ffffff',
                    txt_dim: '#94a3b8',
                    border: 'rgba(0, 212, 170, 0.22)'
                },
                corp_slate: {
                    pri: '#2563eb',
                    acc: '#1d4ed8',
                    bg: '#0f172a',
                    s1: '#1e293b',
                    s2: '#334155',
                    txt: '#ffffff',
                    txt_dim: '#94a3b8',
                    border: 'rgba(37, 99, 235, 0.28)'
                },
                luxury_gold: {
                    pri: '#f59e0b',
                    acc: '#d97706',
                    bg: '#18181b',
                    s1: '#27272a',
                    s2: '#3f3f46',
                    txt: '#ffffff',
                    txt_dim: '#a1a1aa',
                    border: 'rgba(245, 158, 11, 0.30)'
                },
                synth_violet: {
                    pri: '#a855f7',
                    acc: '#6366f1',
                    bg: '#0c0a1a',
                    s1: '#18132e',
                    s2: '#241c45',
                    txt: '#ffffff',
                    txt_dim: '#a5b4fc',
                    border: 'rgba(168, 85, 247, 0.25)'
                },
                grupo_flores: {
                    pri: '#0047ba',
                    acc: '#f37021',
                    bg: '#f4f6fa',
                    s1: '#ffffff',
                    s2: '#ffffff',
                    txt: '#0f172a',
                    txt_dim: '#475569',
                    border: '#cbd5e1'
                },
                minimal_light: {
                    pri: '#0047ba',
                    acc: '#f37021',
                    bg: '#f8fafc',
                    s1: '#ffffff',
                    s2: '#ffffff',
                    txt: '#0f172a',
                    txt_dim: '#475569',
                    border: '#cbd5e1'
                },
                elegant_cream: {
                    pri: '#e67e22',
                    acc: '#d35400',
                    bg: '#faf8f5',
                    s1: '#ffffff',
                    s2: '#f3ede4',
                    txt: '#2c1810',
                    txt_dim: '#8b7355',
                    border: '#e0d5c5'
                },
                ocean_breeze: {
                    pri: '#0047ba',
                    acc: '#f37021',
                    bg: '#f4f6fa',
                    s1: '#ffffff',
                    s2: '#ffffff',
                    txt: '#0f172a',
                    txt_dim: '#475569',
                    border: '#cbd5e1'
                },
                crimson_red: {
                    pri: '#ef4444',
                    acc: '#dc2626',
                    bg: '#150909',
                    s1: '#261010',
                    s2: '#3b1818',
                    txt: '#ffffff',
                    txt_dim: '#fca5a5',
                    border: 'rgba(239, 68, 68, 0.25)'
                }
            };

            // Mapeo de presets JS → nombres de paleta PHP en get_color_tokens()
            const presetToPalette = {
                'grupo_flores':   'grupo_flores',
                'cyber_neon':     'emerald',
                'corp_slate':     'royal_blue',
                'luxury_gold':    'amber_gold',
                'synth_violet':   'cyber_violet',
                'minimal_light':  'minimal_light',
                'elegant_cream':  'elegant_cream',
                'ocean_breeze':   'ocean_breeze',
                'crimson_red':    'crimson_red'
            };

            window.applyColorPreset = function(presetKey) {
                const p = colorPresets[presetKey];
                if (!p) return;

                const setVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.value = val;
                };

                setVal('owc_custom_primary_color', p.pri);
                setVal('owc_custom_accent_color', p.acc);
                setVal('owc_custom_bg_color', p.bg);
                setVal('owc_custom_surface_color', p.s1);
                setVal('owc_custom_surface_2_color', p.s2);
                setVal('owc_custom_text_color', p.txt);
                setVal('owc_custom_text_dim_color', p.txt_dim);
                setVal('owc_custom_border_color', p.border);

                // Actualizar también el campo de paleta oculto
                const paletteName = presetToPalette[presetKey] || 'emerald';
                setVal('owc_color_palette', paletteName);

                updateLivePreview();
            };

            // ── Función para evaluar si un color es claro (Luminancia W3C) ──
            function isLightHex(hex) {
                if (!hex) return false;
                hex = hex.replace('#', '');
                if (hex.length === 3) hex = hex[0]+hex[0] + hex[1]+hex[1] + hex[2]+hex[2];
                if (hex.length !== 6) return false;
                const r = parseInt(hex.substr(0, 2), 16) / 255;
                const g = parseInt(hex.substr(2, 2), 16) / 255;
                const b = parseInt(hex.substr(4, 2), 16) / 255;
                return (0.2126 * r + 0.7152 * g + 0.0722 * b) > 0.55;
            }

            // ── Actualizador Dinámico de la Vista Previa Interactiva ──
            function updateLivePreview() {
                const preview = document.getElementById('owc-live-preview');
                if (!preview) return;

                const getVal = (id, fallback) => {
                    const el = document.getElementById(id);
                    return el && el.value ? el.value : fallback;
                };

                const pri    = getVal('owc_custom_primary_color', '#00d4aa');
                const acc    = getVal('owc_custom_accent_color', '#0284c7');
                const bg     = getVal('owc_custom_bg_color', '#090e17');
                const s1     = getVal('owc_custom_surface_color', '#111827');
                const s2     = getVal('owc_custom_surface_2_color', '#1a2332');
                const txt    = getVal('owc_custom_text_color', '#ffffff');
                const txtDim = getVal('owc_custom_text_dim_color', '#94a3b8');
                const border = getVal('owc_custom_border_color', 'rgba(0, 212, 170, 0.22)');
                const btnTxt = isLightHex(pri) ? '#050b14' : '#ffffff';

                preview.style.setProperty('--prev-pri', pri);
                preview.style.setProperty('--prev-acc', acc);
                preview.style.setProperty('--prev-bg', bg);
                preview.style.setProperty('--prev-surface', s1);
                preview.style.setProperty('--prev-surface2', s2);
                preview.style.setProperty('--prev-text', txt);
                preview.style.setProperty('--prev-dim', txtDim);
                preview.style.setProperty('--prev-border', border);
                preview.style.setProperty('--prev-btn-txt', btnTxt);
            }
            window.updateLivePreview = updateLivePreview;

            // Escuchadores de eventos para todos los campos de color
            [
                'owc_custom_primary_color',
                'owc_custom_accent_color',
                'owc_custom_bg_color',
                'owc_custom_surface_color',
                'owc_custom_surface_2_color',
                'owc_custom_text_color',
                'owc_custom_text_dim_color',
                'owc_custom_border_color'
            ].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', updateLivePreview);
                    el.addEventListener('change', updateLivePreview);
                }
            });

            // Inicializar vista previa al cargar la página
            updateLivePreview();

            // Compatibilidad retroactiva
            window.selectTemplate = window.selectLayout;
            window.selectPalette = function() {};

            // 1. Probar Conexión AJAX
            const btnTest = document.getElementById('owc-btn-test');
            if (btnTest) {
                btnTest.addEventListener('click', function() {
                    setBtnLoading(btnTest, true, 'Probando conexión con Odoo...');
                    fetch(ajaxurl, {
                        method: 'POST',
                        body: getFormData('owc_test_connection')
                    })
                    .then(res => res.json())
                    .then(json => {
                        setBtnLoading(btnTest, false);
                        if (json && json.success) {
                            showAlert('success', json.data && json.data.message ? json.data.message : '✔ Conexión exitosa con Odoo!');
                        } else {
                            const err = (json && json.data && json.data.message) ? json.data.message : 'Error desconocido al probar conexión.';
                            showAlert('error', err);
                        }
                    })
                    .catch(err => {
                        setBtnLoading(btnTest, false);
                        showAlert('error', 'Error de comunicación de red: ' + err.message);
                    });
                });
            }

            // Función auxiliar para importar por lotes y proteger contra caídas
            function runBatchImport(container, btn, onComplete, onError) {
                let offset = 0;
                const batchSize = 25;
                let totalCreated = 0;
                let totalUpdated = 0;

                container.innerHTML = `
                    <div class="owc-progress-box" id="owc-batch-prog">
                        <div class="owc-progress-head">
                            <span id="owc-batch-status"><span class="owc-spinning">⏳</span> Importando productos de Odoo por lotes seguros...</span>
                            <span id="owc-batch-pct">0%</span>
                        </div>
                        <div class="owc-progress-track">
                            <div class="owc-progress-bar" id="owc-batch-bar" style="width: 0%"></div>
                        </div>
                        <div class="owc-progress-sub">
                            <span id="owc-batch-counts">Iniciando proceso por bloques de 25 productos...</span>
                            <span>⚡ Protegido contra caídas y timeouts</span>
                        </div>
                    </div>
                `;
                container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                function processNext() {
                    const fd = new FormData(); // No enviar el formulario completo (evita sobreescribir settings)
                    fd.append('action', 'owc_sync_products_batch');
                    fd.append('offset', offset);
                    fd.append('batch_size', batchSize);
                    fd.append('nonce', ajaxNonce);
                    fd.append('force_create', '1'); // Siempre crear productos nuevos en importación manual

                    fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.success && res.data) {
                            const d = res.data;
                            totalCreated += (d.batch_created || 0);
                            totalUpdated += (d.batch_updated || 0);
                            const total = d.total || 0;
                            const current = d.offset || (offset + batchSize);
                            const pct = d.percent || Math.min(100, Math.round((current / (total || 1)) * 100));

                            const bar = document.getElementById('owc-batch-bar');
                            const pctEl = document.getElementById('owc-batch-pct');
                            const countsEl = document.getElementById('owc-batch-counts');

                            if (bar) bar.style.width = pct + '%';
                            if (pctEl) pctEl.textContent = pct + '%';
                            if (countsEl) countsEl.textContent = `Procesados: ${Math.min(current, total)} de ${total} (Nuevos: ${totalCreated} | Actualizados: ${totalUpdated})`;

                            if (d.done || current >= total) {
                                if (btn) {
                                    btn.disabled = false;
                                    const l = btn.querySelector('.owc-btn-label');
                                    if (l && btn.dataset.origText) l.textContent = btn.dataset.origText;
                                }
                                if (onComplete) onComplete(total, totalCreated, totalUpdated);
                            } else {
                                offset = d.offset;
                                setTimeout(processNext, 200); // 200ms de respiro al servidor entre lotes
                            }
                        } else {
                            if (btn) btn.disabled = false;
                            const err = (res && res.data && res.data.message) ? res.data.message : 'Error en lote de importación.';
                            if (onError) onError(err);
                        }
                    })
                    .catch(e => {
                        if (btn) btn.disabled = false;
                        if (onError) onError('Error de conexión en lote: ' + e.message);
                    });
                }

                processNext();
            }

            // 2. Sincronizar Catálogo AJAX (Por lotes protegidos)
            const btnProducts = document.getElementById('owc-btn-products');
            if (btnProducts) {
                btnProducts.addEventListener('click', function() {
                    setBtnLoading(btnProducts, true, 'Importando...');
                    runBatchImport(
                        alertContainer,
                        btnProducts,
                        function onComplete(total, created, updated) {
                            showAlert('success', `✔ ¡Importación finalizada con éxito! Total procesados: ${total} (Creados: ${created} | Actualizados con stock: ${updated}).`);
                            const stat = document.getElementById('owc-stat-products');
                            if (stat) stat.textContent = new Date().toLocaleString();
                        },
                        function onError(msg) {
                            showAlert('error', msg);
                        }
                    );
                });
            }

            // 3. Sincronizar Stock AJAX
            const btnStock = document.getElementById('owc-btn-stock');
            if (btnStock) {
                btnStock.addEventListener('click', function() {
                    setBtnLoading(btnStock, true, 'Actualizando existencias...');
                    fetch(ajaxurl, {
                        method: 'POST',
                        body: getFormData('owc_sync_stock')
                    })
                    .then(res => res.json())
                    .then(json => {
                        setBtnLoading(btnStock, false);
                        if (json && json.success) {
                            showAlert('success', json.data && json.data.message ? json.data.message : '✔ Stock actualizado.');
                            if (json.data && json.data.time) {
                                const stat = document.getElementById('owc-stat-stock');
                                if (stat) stat.textContent = json.data.time;
                            }
                        } else {
                            const err = (json && json.data && json.data.message) ? json.data.message : 'Error al actualizar existencias.';
                            showAlert('error', err);
                        }
                    })
                    .catch(err => {
                        setBtnLoading(btnStock, false);
                        showAlert('error', 'Error de red: ' + err.message);
                    });
                });
            }
        })();
        </script>
        <?php
    }

    // ─── Página 2: Catálogo y Diagnóstico Comparativo ─────────────────────────
    public static function render_catalog_page() {
        $nonce = wp_create_nonce('owc_save_nonce');
        ?>
        <div class="owc-wrap">
            <div class="owc-header">
                <div class="owc-header-inner">
                    <div class="owc-header-left">
                        <h1>Comparativa de Catálogo: Odoo ↔ WooCommerce</h1>
                        <p>Analiza qué productos están en Odoo, cuáles faltan en tu tienda WooCommerce y sus existencias de stock.</p>
                    </div>
                    <div class="owc-version-badge">
                        🔍 Diagnóstico en Vivo
                    </div>
                </div>
            </div>

            <!-- Contenedor dinámico de alertas -->
            <div id="owc-catalog-alerts"></div>

            <!-- Acciones de Diagnóstico -->
            <div class="owc-card owc-actions-card">
                <h2>⚡ Acciones Rápidas del Catálogo</h2>
                <div class="owc-actions-grid">
                    <button type="button" id="owc-btn-scan" class="owc-action-btn owc-btn-compare">
                        <span class="owc-action-icon">🔄</span>
                        <span class="owc-btn-label">Escanear y Comparar Catálogos</span>
                    </button>
                    <button type="button" id="owc-btn-import-all" class="owc-action-btn owc-btn-products">
                        <span class="owc-action-icon">📥</span>
                        <span class="owc-btn-label">Importar Todo a WooCommerce</span>
                    </button>
                    <button type="button" id="owc-btn-sync-stock-cat" class="owc-action-btn owc-btn-stock">
                        <span class="owc-action-icon">📦</span>
                        <span class="owc-btn-label">Actualizar Stock de Todos</span>
                    </button>
                </div>
            </div>

            <!-- Métricas Resumen -->
            <div class="owc-metric-grid">
                <div class="owc-metric-card m-odoo">
                    <div class="label">🟣 Total en Odoo</div>
                    <div class="val" id="owc-m-odoo">—</div>
                </div>
                <div class="owc-metric-card m-missing">
                    <div class="label">📥 Faltan en WooCommerce</div>
                    <div class="val" id="owc-m-missing">—</div>
                </div>
                <div class="owc-metric-card m-synced">
                    <div class="label">✔ Sincronizados</div>
                    <div class="val" id="owc-m-synced">—</div>
                </div>
                <div class="owc-metric-card m-onlywoo">
                    <div class="label">🛍 Solo en WooCommerce</div>
                    <div class="val" id="owc-m-onlywoo">—</div>
                </div>
            </div>

            <!-- Tabla de Diagnóstico -->
            <div class="owc-card">
                <h2>
                    <span>📋 Listado Comparativo de Productos</span>
                    <span id="owc-table-count" style="font-size:12px;color:var(--owc-text-muted);font-weight:400;"></span>
                </h2>

                <div class="owc-toolbar">
                    <div class="owc-pills">
                        <button type="button" class="owc-pill active" data-filter="all">Todos</button>
                        <button type="button" class="owc-pill" data-filter="missing_in_woo">📥 Faltan en Woo</button>
                        <button type="button" class="owc-pill" data-filter="synced">✔ Sincronizados</button>
                        <button type="button" class="owc-pill" data-filter="only_in_woo">🛍 Solo en Woo</button>
                    </div>
                    <div class="owc-search-box">
                        <span class="icon">🔍</span>
                        <input type="text" id="owc-catalog-search" placeholder="Buscar por nombre o SKU...">
                    </div>
                </div>

                <div id="owc-table-loading" style="text-align:center;padding:50px 0;color:var(--owc-text-muted);">
                    <p style="font-size:15px;margin-bottom:12px;">Haz clic en <strong>"Escanear y Comparar Catálogos"</strong> para consultar Odoo y WooCommerce en tiempo real.</p>
                </div>

                <div id="owc-table-wrap" style="display:none;overflow-x:auto;">
                    <table class="owc-catalog-table">
                        <thead>
                            <tr>
                                <th>SKU / Ref</th>
                                <th>Producto</th>
                                <th>Stock Odoo</th>
                                <th>Stock Woo</th>
                                <th>Precio Odoo</th>
                                <th>Estado</th>
                                <th style="text-align:right;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="owc-catalog-tbody"></tbody>
                    </table>
                </div>
                <div id="owc-pagination" class="owc-pagination" style="display:none;"></div>
            </div>

            <!-- Footer -->
            <div class="owc-footer">
                <span>Odoo WooCommerce Connector — Sincronización de Catálogo, Stock y Pedidos</span>
            </div>
        </div>

        <script>
        (function() {
            const nonce = '<?php echo esc_js($nonce); ?>';
            const alertBox = document.getElementById('owc-catalog-alerts');
            let catalogData = [];
            let currentFilter = 'all';
            let currentPage = 1;
            const pageSize = 10;

            function showAlert(type, message) {
                const icon = type === 'success' ? '✔' : (type === 'error' ? '✘' : 'ℹ');
                alertBox.innerHTML = `
                    <div class="owc-alert-box ${type}">
                        <span class="owc-alert-icon">${icon}</span>
                        <div class="owc-alert-text">${message}</div>
                        <button type="button" class="owc-alert-close" onclick="this.parentElement.remove()">✕</button>
                    </div>
                `;
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function loadComparison(force) {
                const btn = document.getElementById('owc-btn-scan');
                const label = btn.querySelector('.owc-btn-label');
                btn.disabled = true;
                label.innerHTML = '<span class="owc-spinning">⏳</span> Consultando Odoo y WooCommerce...';

                const fd = new FormData();
                fd.append('action', 'owc_compare_catalog');
                fd.append('force_refresh', force ? 1 : 0);
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    label.textContent = 'Escanear y Comparar Catálogos';

                    if (res && res.success && res.data) {
                        const d = res.data;
                        document.getElementById('owc-m-odoo').textContent = d.count_in_odoo || 0;
                        document.getElementById('owc-m-missing').textContent = d.count_missing_in_woo || 0;
                        document.getElementById('owc-m-synced').textContent = d.count_synced || 0;
                        document.getElementById('owc-m-onlywoo').textContent = d.count_only_in_woo || 0;

                        catalogData = d.items || [];
                        currentPage = 1;
                        renderTable();
                        document.getElementById('owc-table-loading').style.display = 'none';
                        document.getElementById('owc-table-wrap').style.display = 'block';

                        if (d.count_missing_in_woo > 0) {
                            showAlert('info', `Se encontraron <strong>${d.count_missing_in_woo} productos en Odoo que faltan en WooCommerce</strong>. Puedes importarlos individualmente con el botón verde o todos juntos con "Importar Todo a WooCommerce".`);
                        } else {
                            showAlert('success', '✔ Todos los productos de Odoo se encuentran actualmente enlazados con WooCommerce.');
                        }
                    } else {
                        showAlert('error', (res && res.data && res.data.message) ? res.data.message : 'Error al conectar con Odoo.');
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    label.textContent = 'Escanear y Comparar Catálogos';
                    showAlert('error', 'Error de red: ' + e.message);
                });
            }

            function renderTable() {
                const tbody = document.getElementById('owc-catalog-tbody');
                const pagination = document.getElementById('owc-pagination');
                const query = document.getElementById('owc-catalog-search').value.toLowerCase().trim();

                const filtered = catalogData.filter(item => {
                    const matchFilter = (currentFilter === 'all') || (item.status === currentFilter);
                    const productName = String(item.name || '').toLowerCase();
                    const productSku = String(item.sku || '').toLowerCase();
                    const matchQuery = !query || productName.includes(query) || productSku.includes(query);
                    return matchFilter && matchQuery;
                });

                if (filtered.length === 0) {
                    document.getElementById('owc-table-count').textContent = `Mostrando 0 de ${catalogData.length} productos`;
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--owc-text-muted);">No hay productos en esta vista.</td></tr>';
                    pagination.innerHTML = '';
                    pagination.style.display = 'none';
                    return;
                }

                const totalPages = Math.ceil(filtered.length / pageSize);
                currentPage = Math.min(Math.max(currentPage, 1), totalPages);
                const start = (currentPage - 1) * pageSize;
                const end = Math.min(start + pageSize, filtered.length);
                const visibleItems = filtered.slice(start, end);
                document.getElementById('owc-table-count').textContent = `Mostrando ${start + 1}-${end} de ${filtered.length} productos`;

                let html = '';
                visibleItems.forEach(item => {
                    let badgeClass = 'synced';
                    let actionHtml = '';

                    if (item.status === 'missing_in_woo') {
                        badgeClass = 'missing';
                        actionHtml = `<button type="button" class="owc-btn-sm owc-btn-sm-import" onclick="importSingle(${item.odoo_id}, this)">📥 Traer a Woo</button>`;
                    } else if (item.status === 'only_in_woo') {
                        badgeClass = 'onlywoo';
                        actionHtml = `
                            <button type="button" class="owc-btn-sm owc-btn-sm-export" onclick="exportSingle(${item.wc_id}, this)">📤 Crear en Odoo</button>
                            <button type="button" class="owc-btn-sm" style="margin-left:6px;background:#ef4444;color:#fff;border-color:#dc2626;" onclick="trashWooOnly(${item.wc_id}, this)">🗑 Papelera</button>
                        `;
                    } else {
                        badgeClass = 'synced';
                        actionHtml = `<span style="color:var(--owc-success);font-weight:700;font-size:12px;">✔ Enlazado</span>`;
                    }

                    const odooStockNum = typeof item.odoo_stock === 'number' ? item.odoo_stock : 0;
                    const stockClass = odooStockNum > 0 ? 'stock-pos' : 'stock-zero';

                    html += `
                        <tr data-status="${item.status}">
                            <td><code>${item.sku}</code></td>
                            <td class="owc-product-name">${item.name || 'Producto sin nombre'}</td>
                            <td class="stock-num ${stockClass}">${item.odoo_stock}</td>
                            <td>${item.wc_stock}</td>
                            <td>${typeof item.odoo_price === 'number' ? ('$' + item.odoo_price.toFixed(2)) : item.odoo_price}${item.odoo_ferretero != null ? '<br><small style="color:var(--owc-primary);">Ferretero: $' + Number(item.odoo_ferretero).toFixed(2) + '</small>' : ''}</td>
                            <td><span class="owc-badge ${badgeClass}">${item.status_label}</span></td>
                            <td style="text-align:right;">${actionHtml}</td>
                        </tr>
                    `;
                });

                tbody.innerHTML = html;
                renderPagination(totalPages);
            }

            function renderPagination(totalPages) {
                const pagination = document.getElementById('owc-pagination');
                if (totalPages <= 1) {
                    pagination.innerHTML = '';
                    pagination.style.display = 'none';
                    return;
                }

                const pages = [];
                for (let page = 1; page <= totalPages; page++) {
                    if (page === 1 || page === totalPages || Math.abs(page - currentPage) <= 2) {
                        pages.push(page);
                    }
                }

                let controls = `<button type="button" class="owc-page-btn" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>Anterior</button>`;
                let previousPage = 0;
                pages.forEach(page => {
                    if (previousPage && page - previousPage > 1) controls += '<span class="owc-page-dots">...</span>';
                    controls += `<button type="button" class="owc-page-btn ${page === currentPage ? 'active' : ''}" data-page="${page}">${page}</button>`;
                    previousPage = page;
                });
                controls += `<button type="button" class="owc-page-btn" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>Siguiente</button>`;

                pagination.innerHTML = controls;
                pagination.style.display = 'flex';
                pagination.querySelectorAll('button[data-page]').forEach(button => {
                    button.addEventListener('click', function() {
                        if (this.disabled) return;
                        currentPage = parseInt(this.dataset.page, 10);
                        renderTable();
                        document.getElementById('owc-table-wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                });
            }

            // Sistema de Importación Segura por Lotes (Anti-Cuelgues)
            function runBatchImport(container, btn, onComplete, onError) {
                let offset = 0;
                const batchSize = 25;
                let totalCreated = 0;
                let totalUpdated = 0;

                container.innerHTML = `
                    <div class="owc-progress-box" id="owc-batch-prog">
                        <div class="owc-progress-head">
                            <span id="owc-batch-status"><span class="owc-spinning">⏳</span> Importando productos de Odoo por lotes seguros...</span>
                            <span id="owc-batch-pct">0%</span>
                        </div>
                        <div class="owc-progress-track">
                            <div class="owc-progress-bar" id="owc-batch-bar" style="width: 0%"></div>
                        </div>
                        <div class="owc-progress-sub">
                            <span id="owc-batch-counts">Iniciando proceso por bloques de 25 productos...</span>
                            <span>⚡ Protegido contra caídas de servidor y cuelgues</span>
                        </div>
                    </div>
                `;
                container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                function processNext() {
                    const fd = new FormData();
                    fd.append('action', 'owc_sync_products_batch');
                    fd.append('offset', offset);
                    fd.append('batch_size', batchSize);
                    fd.append('nonce', nonce);
                    fd.append('force_create', '1'); // Siempre crear productos nuevos al importar todo

                    fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.success && res.data) {
                            const d = res.data;
                            totalCreated += (d.batch_created || 0);
                            totalUpdated += (d.batch_updated || 0);
                            const total = d.total || 0;
                            const current = d.offset || (offset + batchSize);
                            const pct = d.percent || Math.min(100, Math.round((current / (total || 1)) * 100));

                            const bar = document.getElementById('owc-batch-bar');
                            const pctEl = document.getElementById('owc-batch-pct');
                            const countsEl = document.getElementById('owc-batch-counts');

                            if (bar) bar.style.width = pct + '%';
                            if (pctEl) pctEl.textContent = pct + '%';
                            if (countsEl) countsEl.textContent = `Procesados: ${Math.min(current, total)} de ${total} (Nuevos: ${totalCreated} | Actualizados: ${totalUpdated})`;

                            if (d.done || current >= total) {
                                if (btn) {
                                    btn.disabled = false;
                                    const l = btn.querySelector('.owc-btn-label');
                                    if (l) l.textContent = 'Importar Todo a WooCommerce';
                                }
                                if (onComplete) onComplete(total, totalCreated, totalUpdated);
                            } else {
                                offset = d.offset;
                                setTimeout(processNext, 200); // 200ms de respiro al servidor entre lotes
                            }
                        } else {
                            if (btn) {
                                btn.disabled = false;
                                const l = btn.querySelector('.owc-btn-label');
                                if (l) l.textContent = 'Importar Todo a WooCommerce';
                            }
                            const err = (res && res.data && res.data.message) ? res.data.message : 'Error en lote de importación.';
                            if (onError) onError(err);
                        }
                    })
                    .catch(e => {
                        if (btn) {
                            btn.disabled = false;
                            const l = btn.querySelector('.owc-btn-label');
                            if (l) l.textContent = 'Importar Todo a WooCommerce';
                        }
                        if (onError) onError('Error de conexión en lote: ' + e.message);
                    });
                }

                processNext();
            }

            // Filtros de pestañas
            document.querySelectorAll('.owc-pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    document.querySelectorAll('.owc-pill').forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    currentPage = 1;
                    renderTable();
                });
            });

            // Buscador en vivo
            document.getElementById('owc-catalog-search').addEventListener('input', function() {
                currentPage = 1;
                renderTable();
            });

            // Botón Escanear (Fuerza refresco contra Odoo)
            document.getElementById('owc-btn-scan').addEventListener('click', function() {
                loadComparison(true);
            });

            // Botón Importar Todos (Protegido por Lotes)
            document.getElementById('owc-btn-import-all').addEventListener('click', function() {
                const btn = this;
                const label = btn.querySelector('.owc-btn-label');
                btn.disabled = true;
                label.innerHTML = '<span class="owc-spinning">⏳</span> Importando por lotes...';

                runBatchImport(
                    alertBox,
                    btn,
                    function onComplete(total, created, updated) {
                        showAlert('success', `✔ ¡Importación exitosa! Se procesaron ${total} productos de Odoo (Nuevos creados: ${created} | Actualizados con stock: ${updated}).`);
                        loadComparison(true);
                    },
                    function onError(msg) {
                        showAlert('error', msg);
                    }
                );
            });

            // Botón Actualizar Stock de Todos
            document.getElementById('owc-btn-sync-stock-cat').addEventListener('click', function() {
                const btn = this;
                const label = btn.querySelector('.owc-btn-label');
                btn.disabled = true;
                label.innerHTML = '<span class="owc-spinning">⏳</span> Actualizando existencias...';

                const fd = new FormData();
                fd.append('action', 'owc_sync_stock');
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    label.textContent = 'Actualizar Stock de Todos';
                    if (res && res.success) {
                        showAlert('success', res.data && res.data.message ? res.data.message : '✔ Stock actualizado.');
                        loadComparison(true);
                    } else {
                        showAlert('error', (res && res.data && res.data.message) ? res.data.message : 'Error al sincronizar stock.');
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    label.textContent = 'Actualizar Stock de Todos';
                    showAlert('error', 'Error: ' + e.message);
                });
            });

            // Funciones para botones individuales de fila (Actualización DOM optimista)
            window.importSingle = function(odooId, btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="owc-spinning">⏳</span> Trayendo...';

                const fd = new FormData();
                fd.append('action', 'owc_import_single');
                fd.append('odoo_id', odooId);
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        showAlert('success', res.data && res.data.message ? res.data.message : '✔ Producto importado con éxito.');
                        const tr = btn.closest('tr');
                        if (tr) {
                            tr.dataset.status = 'synced';
                            const badge = tr.querySelector('.owc-badge');
                            if (badge) {
                                badge.className = 'owc-badge synced';
                                badge.textContent = 'Sincronizado';
                            }
                            const tdAction = tr.querySelector('td:last-child');
                            if (tdAction) {
                                tdAction.innerHTML = '<span style="color:var(--owc-success);font-weight:700;font-size:12px;">✔ Enlazado</span>';
                            }
                            const mMissing = document.getElementById('owc-m-missing');
                            const mSynced = document.getElementById('owc-m-synced');
                            if (mMissing) {
                                const c = Math.max(0, parseInt(mMissing.textContent || '0', 10) - 1);
                                mMissing.textContent = c;
                            }
                            if (mSynced) {
                                const c = parseInt(mSynced.textContent || '0', 10) + 1;
                                mSynced.textContent = c;
                            }
                        }
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '📥 Traer a Woo';
                        showAlert('error', (res && res.data && res.data.message) ? res.data.message : 'Error importando.');
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    btn.innerHTML = '📥 Traer a Woo';
                    showAlert('error', 'Error: ' + e.message);
                });
            };

            window.exportSingle = function(wcId, btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="owc-spinning">⏳</span> Creando...';

                const fd = new FormData();
                fd.append('action', 'owc_export_single');
                fd.append('wc_id', wcId);
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        showAlert('success', res.data && res.data.message ? res.data.message : '✔ Creado en Odoo.');
                        const tr = btn.closest('tr');
                        if (tr) {
                            tr.dataset.status = 'synced';
                            const badge = tr.querySelector('.owc-badge');
                            if (badge) {
                                badge.className = 'owc-badge synced';
                                badge.textContent = 'Sincronizado';
                            }
                            const tdAction = tr.querySelector('td:last-child');
                            if (tdAction) {
                                tdAction.innerHTML = '<span style="color:var(--owc-success);font-weight:700;font-size:12px;">✔ Enlazado</span>';
                            }
                            const mOnlyWoo = document.getElementById('owc-m-onlywoo');
                            const mSynced = document.getElementById('owc-m-synced');
                            if (mOnlyWoo) {
                                const c = Math.max(0, parseInt(mOnlyWoo.textContent || '0', 10) - 1);
                                mOnlyWoo.textContent = c;
                            }
                            if (mSynced) {
                                const c = parseInt(mSynced.textContent || '0', 10) + 1;
                                mSynced.textContent = c;
                            }
                        }
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '📤 Crear en Odoo';
                        showAlert('error', (res && res.data && res.data.message) ? res.data.message : 'Error creando en Odoo.');
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    btn.innerHTML = '📤 Crear en Odoo';
                    showAlert('error', 'Error: ' + e.message);
                });
            };

            window.trashWooOnly = function(wcId, btn) {
                if (!confirm('Este producto existe solo en WooCommerce y será enviado a la papelera. ¿Continuar?')) {
                    return;
                }

                btn.disabled = true;
                btn.innerHTML = '<span class="owc-spinning">⏳</span> Enviando...';

                const fd = new FormData();
                fd.append('action', 'owc_trash_woo_only');
                fd.append('wc_id', wcId);
                fd.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        showAlert('success', res.data && res.data.message ? res.data.message : '✔ Producto enviado a papelera.');
                        catalogData = catalogData.filter(item => parseInt(item.wc_id || 0, 10) !== parseInt(wcId, 10));
                        renderTable();

                        const mOnlyWoo = document.getElementById('owc-m-onlywoo');
                        if (mOnlyWoo) {
                            const c = Math.max(0, parseInt(mOnlyWoo.textContent || '0', 10) - 1);
                            mOnlyWoo.textContent = c;
                        }
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '🗑 Papelera';
                        showAlert('error', (res && res.data && res.data.message) ? res.data.message : 'Error enviando a papelera.');
                    }
                })
                .catch(e => {
                    btn.disabled = false;
                    btn.innerHTML = '🗑 Papelera';
                    showAlert('error', 'Error: ' + e.message);
                });
            };

            // Cargar automáticamente al entrar a la pestaña usando caché inteligente
            loadComparison(false);
        })();
        </script>
        <?php
    }

    // ─── Página 3: Registro de Actividad ──────────────────────────────────────
    public static function render_logs_page() {
        $logs = OWC_Sync_Logger::get_recent( 100 );
        ?>
        <div class="owc-wrap">
            <div class="owc-header">
                <div class="owc-header-inner">
                    <div class="owc-header-left">
                        <h1>Registro de Actividad</h1>
                        <p>Últimos 100 eventos de sincronización y conexión entre Odoo 15 y WooCommerce.</p>
                    </div>
                    <div class="owc-version-badge">
                        📋 <?php echo count( $logs ); ?> eventos
                    </div>
                </div>
            </div>
            <div class="owc-card">
                <?php if ( empty( $logs ) ) : ?>
                    <p style="color:var(--owc-text-muted);text-align:center;padding:40px 0;font-size:14px;">No hay registros de actividad todavía.</p>
                <?php else : ?>
                    <table class="owc-log-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha / Hora</th>
                                <th>Evento</th>
                                <th>Estado</th>
                                <th>Mensaje</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log['id'] ); ?></td>
                                <td style="white-space:nowrap"><?php echo esc_html( $log['created_at'] ); ?></td>
                                <td><code><?php echo esc_html( $log['event_type'] ); ?></code></td>
                                <td><span class="owc-badge <?php echo esc_attr( $log['status'] ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
                                <td><?php echo esc_html( $log['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="owc-footer">
                <span>Odoo WooCommerce Connector — Sincronización de Catálogo, Stock y Pedidos</span>
            </div>
        </div>
        <?php
    }
}

