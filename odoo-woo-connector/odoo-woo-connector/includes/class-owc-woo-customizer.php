<?php
/**
 * Personalización de la interfaz de WooCommerce
 * Agrega columnas en la lista de productos y campos en la edición.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWC_Woo_Customizer {

    public static function init() {
        // 1. Agregar columna a la lista de productos
        add_filter( 'manage_edit-product_columns', [ __CLASS__, 'add_ferretero_column' ], 15 );
        add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_ferretero_column' ], 10, 2 );

        // 2. Agregar campo en la pestaña "General" de edición de producto
        add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'add_ferretero_price_field' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_ferretero_price_field' ] );

        // 3. Mejorar el diseño del Checkout y Pedido Recibido
        add_action( 'wp_head', [ __CLASS__, 'inject_premium_checkout_styles' ] );
        add_action( 'wp_loaded', [ __CLASS__, 'remove_duplicate_ubigeo_sections' ], 100 );

        // 4. Arreglar el responsive del admin de WooCommerce (columna Precio Ferretero)
        add_action( 'admin_head', [ __CLASS__, 'inject_admin_styles' ] );

        // 5. Ocultar "Enviar a otra dirección" y "Notas del pedido" nativamente
        add_filter( 'wc_ship_to_billing_address_only', '__return_true' );
        add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );
    }

    public static function inject_admin_styles() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-product' ) return;
        ?>
        <style>
            /* ─── ARREGLO COLUMNA PRECIO FERRETERO EN ADMIN ─── */
            .column-owc_ferretero_price {
                width: 110px !important;
                white-space: nowrap !important;
                text-align: right !important;
            }
            .column-owc_ferretero_price .amount {
                font-weight: 700;
                color: #0047ba;
            }
            /* Encabezado de columna sin texto vertical */
            .manage-column.column-owc_ferretero_price {
                overflow: visible !important;
                writing-mode: horizontal-tb !important;
                word-break: keep-all !important;
                white-space: nowrap !important;
                font-size: 12px;
                padding: 8px 10px !important;
            }
            /* Ajuste general de la tabla de productos para que no se desborde */
            table.wp-list-table.posts {
                table-layout: auto !important;
            }
            table.wp-list-table.posts .column-name {
                min-width: 200px;
            }
            table.wp-list-table.posts .column-sku {
                width: 100px !important;
                white-space: nowrap;
            }
            table.wp-list-table.posts .column-price {
                width: 90px !important;
                white-space: nowrap;
                text-align: right;
            }
            table.wp-list-table.posts .column-product_cat,
            table.wp-list-table.posts .column-product_tag {
                width: 120px !important;
            }
            /* Responsive: en pantallas pequeñas ocultar columnas secundarias */
            @media screen and (max-width: 1200px) {
                table.wp-list-table.posts .column-product_cat,
                table.wp-list-table.posts .column-product_tag,
                table.wp-list-table.posts .column-featured {
                    display: none !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Ubigeo Perú ya copia departamento/provincia/distrito a las direcciones
     * estándar. Sus bloques adicionales duplican esa información al finalizar
     * la compra y en Mi cuenta, por lo que se retiran solo en el frontend.
     */
    public static function remove_duplicate_ubigeo_sections() {
        if ( function_exists( 'rt_show_custom_fields_thankyou' ) ) {
            remove_action( 'woocommerce_thankyou', 'rt_show_custom_fields_thankyou', 20 );
        }
        if ( function_exists( 'rt_show_custom_view_order' ) ) {
            remove_action( 'woocommerce_view_order', 'rt_show_custom_view_order', 20 );
        }
    }

    public static function inject_premium_checkout_styles() {

        if ( ! is_checkout() ) return;
        ?>
        <style>
            /* Recibo de compra: estilos aislados del tema y de Elementor. */
            .woocommerce-order {
                --owc-blue: #0649b8;
                --owc-blue-dark: #033685;
                --owc-orange: #f36f21;
                --owc-ink: #172033;
                --owc-muted: #64748b;
                --owc-line: #dbe3ee;
                --owc-soft: #f6f8fb;
                box-sizing: border-box;
                position: relative;
                left: 50%;
                width: min(920px, calc(100vw - 40px));
                max-width: none !important;
                transform: translateX(-50%);
                margin: 32px auto 48px !important;
                padding: 32px !important;
                overflow: hidden;
                border: 1px solid var(--owc-line);
                border-radius: 18px;
                background: #fff;
                box-shadow: 0 14px 40px rgba(15, 42, 82, .09);
                color: var(--owc-ink);
                font-family: Inter, Arial, sans-serif;
                font-size: 15px;
                line-height: 1.5;
            }
            .woocommerce-order *,
            .woocommerce-order *::before,
            .woocommerce-order *::after { box-sizing: border-box; }
            .woocommerce-order > * { width: 100%; }

            .woocommerce-order .woocommerce-notice--success {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 11px;
                min-height: 62px;
                margin: 0 0 22px !important;
                padding: 16px 22px !important;
                border: 0 !important;
                border-radius: 12px;
                background: linear-gradient(135deg, var(--owc-blue), var(--owc-blue-dark));
                box-shadow: 0 6px 18px rgba(6, 73, 184, .22);
                color: #fff !important;
                font-size: 16px !important;
                font-weight: 700;
                text-align: center;
            }
            .woocommerce-order .woocommerce-notice--success::before {
                content: '✓';
                position: static;
                display: inline-grid;
                flex: 0 0 24px;
                width: 24px;
                height: 24px;
                margin: 0;
                place-items: center;
                border-radius: 50%;
                background: #fff;
                color: var(--owc-blue);
                font-size: 14px;
                font-weight: 900;
            }

            .woocommerce-order .woocommerce-order-overview {
                display: grid !important;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 10px;
                margin: 0 0 26px !important;
                padding: 12px !important;
                border: 1px solid var(--owc-line);
                border-radius: 12px;
                background: var(--owc-soft);
                list-style: none !important;
            }
            .woocommerce-order .woocommerce-order-overview::before,
            .woocommerce-order .woocommerce-order-overview::after { display: none !important; }
            .woocommerce-order .woocommerce-order-overview li {
                float: none !important;
                width: auto !important;
                min-width: 0;
                min-height: 76px;
                margin: 0 !important;
                padding: 14px 15px !important;
                border: 0 !important;
                border-radius: 9px;
                background: #fff;
                color: var(--owc-muted) !important;
                font-size: 10px !important;
                font-weight: 700;
                letter-spacing: .04em;
                line-height: 1.3;
                text-align: left;
                text-transform: uppercase;
            }
            .woocommerce-order .woocommerce-order-overview li strong {
                display: block;
                margin-top: 7px;
                overflow-wrap: anywhere;
                color: var(--owc-ink);
                font-size: 14px !important;
                font-weight: 750;
                letter-spacing: 0;
                line-height: 1.35;
                text-transform: none;
            }
            .woocommerce-order .woocommerce-order-overview li.order { color: var(--owc-orange) !important; }

            .woocommerce-order .woocommerce-bacs-bank-details,
            .woocommerce-order .woocommerce-order-details,
            .woocommerce-order .woocommerce-customer-details {
                clear: both;
                margin: 22px 0 0 !important;
                padding: 24px !important;
                border: 1px solid var(--owc-line);
                border-radius: 14px;
                background: #fff;
                box-shadow: none;
            }
            .woocommerce-order .woocommerce-bacs-bank-details > h2,
            .woocommerce-order .woocommerce-order-details__title,
            .woocommerce-order .woocommerce-column__title {
                margin: 0 0 20px !important;
                padding: 0 0 13px !important;
                border-bottom: 1px solid var(--owc-line);
                color: var(--owc-ink);
                font-size: 20px !important;
                font-weight: 800;
                letter-spacing: -.02em;
                line-height: 1.25;
            }
            .woocommerce-order .woocommerce-bacs-bank-details h3 {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 20px 0 10px !important;
                color: var(--owc-blue);
                font-size: 13px !important;
                font-weight: 800;
                letter-spacing: .02em;
                line-height: 1.35;
                text-transform: uppercase;
            }
            .woocommerce-order .woocommerce-bacs-bank-details h3:first-of-type { margin-top: 0 !important; }
            .woocommerce-order .woocommerce-bacs-bank-details h3::before {
                content: '';
                flex: 0 0 6px;
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--owc-orange);
            }
            .woocommerce-order .woocommerce-bacs-bank-details ul {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 10px;
                margin: 0 !important;
                padding: 0 !important;
                list-style: none !important;
            }
            .woocommerce-order .woocommerce-bacs-bank-details ul::before,
            .woocommerce-order .woocommerce-bacs-bank-details ul::after { display: none !important; }
            .woocommerce-order .woocommerce-bacs-bank-details ul li {
                float: none !important;
                width: auto !important;
                min-width: 0;
                min-height: 70px;
                margin: 0 !important;
                padding: 13px 14px !important;
                border: 1px solid #e6ebf2 !important;
                border-radius: 9px;
                background: var(--owc-soft);
                color: var(--owc-muted) !important;
                font-size: 9px !important;
                font-weight: 700;
                letter-spacing: .04em;
                line-height: 1.3;
                text-transform: uppercase;
            }
            .woocommerce-order .woocommerce-bacs-bank-details ul li strong {
                display: block;
                margin-top: 5px;
                overflow-wrap: anywhere;
                color: var(--owc-ink);
                font-size: 13px !important;
                font-weight: 750;
                letter-spacing: 0;
                line-height: 1.35;
                text-transform: none;
            }

            .woocommerce-order .woocommerce-table--order-details {
                width: 100%;
                margin: 0 !important;
                overflow: hidden;
                border: 1px solid var(--owc-line) !important;
                border-collapse: separate;
                border-radius: 10px;
                border-spacing: 0;
                table-layout: fixed;
            }
            .woocommerce-order .woocommerce-table--order-details th,
            .woocommerce-order .woocommerce-table--order-details td {
                height: auto;
                padding: 12px 14px !important;
                border: 0 !important;
                border-bottom: 1px solid var(--owc-line) !important;
                color: var(--owc-ink);
                font-size: 13px !important;
                line-height: 1.4;
                text-align: left;
                vertical-align: middle;
            }
            .woocommerce-order .woocommerce-table--order-details thead th {
                background: #eef3f9;
                color: #4b5e77;
                font-size: 10px !important;
                font-weight: 800;
                letter-spacing: .05em;
                text-transform: uppercase;
            }
            .woocommerce-order .woocommerce-table--order-details tr > * + * { border-left: 1px solid var(--owc-line) !important; }
            .woocommerce-order .woocommerce-table--order-details tr:last-child > * { border-bottom: 0 !important; }
            .woocommerce-order .woocommerce-table--order-details tfoot th {
                width: 45%;
                background: #fff;
                color: var(--owc-muted);
                font-size: 11px !important;
                font-weight: 700;
                text-align: right;
                text-transform: uppercase;
            }
            .woocommerce-order .woocommerce-table--order-details tfoot tr:last-child th,
            .woocommerce-order .woocommerce-table--order-details tfoot tr:last-child td {
                background: #f2f6fc;
                color: var(--owc-blue);
                font-weight: 800;
            }

            .woocommerce-order .woocommerce-customer-details {
                height: auto !important;
                overflow: visible !important;
            }
            .woocommerce-order .woocommerce-customer-details .woocommerce-columns,
            .woocommerce-order .woocommerce-customer-details .woocommerce-columns--addresses,
            .woocommerce-order .woocommerce-customer-details .col2-set {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
                width: 100% !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }
            /* WooCommerce clearfix pseudo-elements otherwise occupy grid cells. */
            .woocommerce-order .woocommerce-customer-details .woocommerce-columns::before,
            .woocommerce-order .woocommerce-customer-details .woocommerce-columns::after,
            .woocommerce-order .woocommerce-customer-details .col2-set::before,
            .woocommerce-order .woocommerce-customer-details .col2-set::after {
                content: none !important;
                display: none !important;
            }
            .woocommerce-order .woocommerce-customer-details .woocommerce-column {
                display: flex !important;
                flex-direction: column;
                align-self: stretch;
                grid-column: auto !important;
                grid-row: auto !important;
                clear: none !important;
                height: auto !important;
                float: none !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                position: static !important;
            }
            .woocommerce-order .woocommerce-customer-details .woocommerce-column__title {
                display: flex;
                align-items: center;
                min-height: 46px;
                margin-bottom: 12px !important;
                font-size: 16px !important;
            }
            .woocommerce-order .woocommerce-customer-details address {
                min-height: 0 !important;
                height: auto !important;
                width: 100% !important;
                box-sizing: border-box;
                flex: 1;
                overflow-wrap: anywhere;
                margin: 0;
                padding: 16px !important;
                border: 1px solid var(--owc-line) !important;
                border-radius: 9px;
                background: var(--owc-soft);
                color: #42526a;
                font-size: 13px !important;
                font-style: normal;
                line-height: 1.75;
            }
            .woocommerce-order .woocommerce-customer-details address p { margin: 8px 0 0 !important; }

            @media (max-width: 760px) {
                .woocommerce-order {
                    left: auto;
                    width: 100%;
                    transform: none;
                    margin: 20px auto 32px !important;
                    padding: 18px !important;
                    border-radius: 14px;
                }
                .woocommerce-order .woocommerce-order-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .woocommerce-order .woocommerce-bacs-bank-details,
                .woocommerce-order .woocommerce-order-details,
                .woocommerce-order .woocommerce-customer-details { padding: 18px !important; }
                .woocommerce-order .woocommerce-bacs-bank-details ul { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .woocommerce-order .woocommerce-bacs-bank-details ul li:last-child:nth-child(odd) { grid-column: 1 / -1; }
                .woocommerce-order .woocommerce-customer-details .woocommerce-columns,
                .woocommerce-order .woocommerce-customer-details .woocommerce-columns--addresses,
                .woocommerce-order .woocommerce-customer-details .col2-set { grid-template-columns: 1fr !important; }
            }
            @media (max-width: 430px) {
                .woocommerce-order { padding: 12px !important; }
                .woocommerce-order .woocommerce-notice--success { font-size: 14px !important; }
                .woocommerce-order .woocommerce-order-overview { grid-template-columns: 1fr; }
                .woocommerce-order .woocommerce-bacs-bank-details ul { grid-template-columns: 1fr; }
                .woocommerce-order .woocommerce-bacs-bank-details ul li:last-child:nth-child(odd) { grid-column: auto; }
                .woocommerce-order .woocommerce-table--order-details th,
                .woocommerce-order .woocommerce-table--order-details td { padding: 10px !important; }
            }
        </style>
        <?php
    }

    // ─── Columnas en la Lista de Productos ───────────────────────────────────

    public static function add_ferretero_column( $columns ) {
        $new_columns = [];
        foreach ( $columns as $key => $column ) {
            $new_columns[$key] = $column;
            if ( $key === 'price' ) {
                $new_columns['owc_ferretero_price'] = 'Precio Ferretero';
            }
        }
        return $new_columns;
    }

    public static function render_ferretero_column( $column, $postid ) {
        if ( $column === 'owc_ferretero_price' ) {
            $price = get_post_meta( $postid, '_owc_distributor_price', true );
            if ( $price ) {
                echo wc_price( $price );
            } else {
                echo '<span style="color:#aaa;">-</span>';
            }
        }
    }

    // ─── Campo en Edición de Producto ────────────────────────────────────────

    public static function add_ferretero_price_field() {
        woocommerce_wp_text_input( [
            'id'          => '_owc_distributor_price',
            'label'       => 'Precio Ferretero',
            'placeholder' => 'Ej: 29.30',
            'desc_tip'    => 'true',
            'description' => 'Este precio es sincronizado desde Odoo (Tarifa B2B).',
            'type'        => 'text',
            'custom_attributes' => [
                'step' => 'any',
                'min'  => '0'
            ]
        ] );
    }

    public static function save_ferretero_price_field( $post_id ) {
        $price = isset( $_POST['_owc_distributor_price'] ) ? wc_clean( $_POST['_owc_distributor_price'] ) : '';
        update_post_meta( $post_id, '_owc_distributor_price', $price );
    }
}
