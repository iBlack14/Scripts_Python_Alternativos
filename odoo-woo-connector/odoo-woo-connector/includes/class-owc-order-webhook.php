<?php
/**
 * Webhook de Pedidos WooCommerce → Odoo
 * Captura nuevos pedidos en WooCommerce y los notifica al servicio Python
 * (que los crea como sale.order en Odoo 15).
 *
 * Se activa en los hooks: woocommerce_order_status_processing
 *                         woocommerce_order_status_completed
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Order_Webhook {

    public static function init() {
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'checkout_order_processed' ], 20, 3 );
        add_action( 'woocommerce_order_status_on-hold',     [ __CLASS__, 'order_placed' ], 10, 2 );
        add_action( 'woocommerce_order_status_processing',  [ __CLASS__, 'order_placed' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed',   [ __CLASS__, 'order_placed' ], 10, 2 );
    }

    /**
     * WooCommerce dispara este hook apenas se crea el pedido en checkout.
     */
    public static function checkout_order_processed( $order_id, $posted_data = [], $order = null ) {
        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( $order ) {
            self::order_placed( $order_id, $order );
        }
    }

    /**
     * Se ejecuta cuando un pedido pasa a estado "processing" o "completed".
     * Envía los datos del pedido al servicio Python (que crea la sale.order en Odoo).
     */
    public static function order_placed( $order_id, $order ) {
        if ( OWC_Settings::get( 'sync_orders', 'yes' ) !== 'yes' ) {
            return;
        }

        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        // Transferencia/Yape y pago en tienda se envían solo después de confirmar el pago.
        $manual_methods = [ 'owc_bank_transfer', 'owc_yape', 'bacs', 'owc_store_payment' ];
        if ( in_array( $order->get_payment_method(), $manual_methods, true )
            && $order->get_meta( '_owc_payment_review_status' ) !== 'approved' ) {
            return;
        }

        // La creación de la venta es idempotente por client_order_ref. Aunque
        // ya se haya enviado, se vuelve a consultar para completar el contacto
        // con datos corregidos del checkout sin duplicar la venta.
        $already_sent = get_post_meta( $order_id, '_owc_sent_to_odoo', true );
        $was_already_sent = $already_sent === 'yes';

        $payload = self::build_order_payload( $order );
        $result  = self::send_to_odoo_service( $payload );

        if ( $result['success'] ) {
            update_post_meta( $order_id, '_owc_sent_to_odoo', 'yes' );
            update_post_meta( $order_id, '_owc_odoo_sale_id', $result['odoo_sale_id'] ?? '' );
            if ( ! $was_already_sent ) {
                $order->add_order_note( '✔ Pedido enviado a Odoo correctamente. ' . ( $result['message'] ?? '' ) );
            }
            OWC_Sync_Logger::log( 'order-webhook', "Pedido #{$order_id} enviado a Odoo exitosamente.", 'success' );
        } else {
            $error_msg = $result['error'] ?? 'Error desconocido';
            $order->add_order_note( "⚠ Error al enviar pedido a Odoo: {$error_msg}" );
            OWC_Sync_Logger::log( 'order-webhook', "Error enviando pedido #{$order_id} a Odoo: {$error_msg}", 'error' );

            // Reintentar con wp_schedule_single_event
            if ( ! wp_next_scheduled( 'owc_retry_order', [ $order_id ] ) ) {
                wp_schedule_single_event( time() + 300, 'owc_retry_order', [ $order_id ] );
            }
        }

        OWC_Settings::set_last_sync( 'orders' );
    }

    // ─── Construir Payload del Pedido ─────────────────────────────────────────
    private static function build_order_payload( $order ) {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'sku'      => $product ? $product->get_sku() : '',
                'quantity' => $item->get_quantity(),
                'price'    => $item->get_subtotal() / $item->get_quantity(),
                'total'    => $item->get_total(),
            ];
        }

        $shipping_lines = [];
        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            $shipping_lines[] = [
                'method_id'    => $shipping_item->get_method_id(),
                'method_title' => $shipping_item->get_method_title() ?: $shipping_item->get_name(),
                'total'        => (float) $shipping_item->get_total(),
                'total_tax'    => (float) $shipping_item->get_total_tax(),
            ];
        }

        $billing_ubigeo = function_exists( 'get_name_ubigeo_billing' ) ? get_name_ubigeo_billing( $order ) : [];
        if ( ! is_array( $billing_ubigeo ) ) $billing_ubigeo = [];

        return [
            'woo_order_id'         => $order->get_id(),
            'woo_order_number'     => $order->get_order_number(),
            'status'               => $order->get_status(),
            'date_created'         => $order->get_date_created()->format('Y-m-d H:i:s'),
            'currency'             => $order->get_currency(),
            'total'                => $order->get_total(),
            'subtotal'             => $order->get_subtotal(),
            'shipping_total'       => $order->get_shipping_total(),
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'payment_proof_url'    => wp_get_attachment_url( absint( $order->get_meta( '_owc_payment_proof_id' ) ) ) ?: '',
            'customer_note'        => $order->get_customer_note(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'tax_id'     => $order->get_meta('_billing_tax_id') ?: ( $order->get_customer_id() ? get_user_meta( $order->get_customer_id(), 'billing_tax_id', true ) ?: get_user_meta( $order->get_customer_id(), 'owc_tax_id', true ) : '' ),
                'document_type' => $order->get_meta('_billing_document_type') ?: ( $order->get_customer_id() ? get_user_meta( $order->get_customer_id(), 'owc_document_type', true ) : '' ),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $billing_ubigeo['distrito'] ?? $order->get_billing_city(),
                'province'   => $billing_ubigeo['provincia'] ?? '',
                'state'      => $billing_ubigeo['departamento'] ?? $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'city'       => $order->get_shipping_city(),
                'country'    => $order->get_shipping_country(),
            ],
            'line_items'      => $items,
            'shipping_lines'  => $shipping_lines,
            'shipping_agency' => class_exists('OWC_Shipping_Agency') ? OWC_Shipping_Agency::get_order_agency( $order ) : '',
        ];
    }

    // ─── Enviar Payload al Servicio Python ────────────────────────────────────
    private static function send_to_odoo_service( $payload ) {
        $odoo_url     = OWC_Settings::get('odoo_url');
        $odoo_db      = OWC_Settings::get('odoo_db');
        $odoo_user    = OWC_Settings::get('odoo_user');
        $odoo_pass    = OWC_Settings::get('odoo_password');
        $match_field  = OWC_Settings::get('sync_match_field', 'default_code');

        if ( ! $odoo_url || ! $odoo_db || ! $odoo_user || ! $odoo_pass ) {
            return [ 'success' => false, 'error' => 'Credenciales de Odoo no configuradas en el plugin.' ];
        }

        // Llamar directamente a Odoo vía XML-RPC desde PHP
        return self::create_odoo_sale_order_xmlrpc( $payload, [
            'url'         => $odoo_url,
            'db'          => $odoo_db,
            'user'        => $odoo_user,
            'password'    => $odoo_pass,
            'match_field' => $match_field,
        ]);
    }

    // ─── Comunicación XML-RPC Directa con Odoo 15 ────────────────────────────
    private static function create_odoo_sale_order_xmlrpc( $order_data, $odoo_cfg ) {
        $url      = rtrim( $odoo_cfg['url'], '/' );
        $db       = $odoo_cfg['db'];
        $user     = $odoo_cfg['user'];
        $password = $odoo_cfg['password'];
        $match_field = $odoo_cfg['match_field'];

        if ( ! class_exists('OdooXmlRpc') ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-xmlrpc.php';
        }

        $rpc = new OdooXmlRpc( $url, $db, $user, $password );
        if ( ! $rpc->authenticate() ) {
            return [ 'success' => false, 'error' => 'Fallo de autenticación XML-RPC con Odoo.' ];
        }

        // 1. Buscar o crear partner
        $billing     = $order_data['billing'];
        $email       = $billing['email'] ?? '';
        
        $company_name = trim( $billing['company'] ?? '' );
        $full_name    = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
        
        $name = $company_name ?: $full_name;
        $name = $name ?: 'Cliente Web';
        
        $partner_id  = $rpc->find_or_create_partner( $email, $name, $billing );

        if ( ! $partner_id ) {
            return [ 'success' => false, 'error' => 'No se pudo crear/encontrar el cliente en Odoo.' ];
        }

        // 2. Verificar si ya existe el pedido
        $ref = 'WOO-' . $order_data['woo_order_id'];
        $existing = $rpc->search_read( 'sale.order', [[ 'client_order_ref', '=', $ref ]], ['id', 'name'], 1 );
        if ( ! empty( $existing ) ) {
            return [ 'success' => true, 'message' => "Ya existía en Odoo: {$existing[0]['name']}", 'odoo_sale_id' => $existing[0]['id'] ];
        }

        // 3. Construir líneas de pedido
        $order_lines = [];
        foreach ( $order_data['line_items'] as $line ) {
            $sku     = $line['sku'] ?? '';
            $prod_id = null;

            if ( $sku ) {
                $found = $rpc->search_read( 'product.product', [
                    '|',
                    [ 'default_code', '=', $sku ],
                    [ 'barcode', '=', $sku ],
                ], ['id'], 1 );
                if ( ! empty( $found ) ) $prod_id = $found[0]['id'];
            }

            if ( ! $prod_id ) {
                $found = $rpc->search_read( 'product.product', [[ 'name', '=ilike', $line['name'] ]], ['id'], 1 );
                if ( ! empty( $found ) ) $prod_id = $found[0]['id'];
            }

            if ( ! $prod_id ) continue;

            $order_lines[] = [ 0, 0, [
                'product_id'      => $prod_id,
                'product_uom_qty' => (float) $line['quantity'],
                'price_unit'      => (float) $line['price'],
                'name'            => $line['name'],
            ]];
        }

        // Agregar el método de envío como una línea de servicio independiente.
        $shipping_lines = $order_data['shipping_lines'] ?? [];
        if ( empty( $shipping_lines ) && (float) ( $order_data['shipping_total'] ?? 0 ) > 0 ) {
            $shipping_lines[] = [
                'method_id'    => 'woocommerce_shipping',
                'method_title' => 'Envío WooCommerce',
                'total'        => (float) $order_data['shipping_total'],
            ];
        }

        // Los métodos gratuitos (incluido recojo en tienda) no deben crear una
        // línea de venta de importe cero: algunas configuraciones de Odoo no
        // permiten facturarla.
        $shipping_lines = array_values( array_filter( $shipping_lines, function( $shipping ) {
            return (float) ( $shipping['total'] ?? 0 ) > 0;
        } ) );

        if ( ! empty( $shipping_lines ) ) {
            $shipping_product = $rpc->search_read( 'product.product', [[ 'default_code', '=', 'WOO-SHIPPING' ]], ['id'], 1 );
            $shipping_product_id = ! empty( $shipping_product ) ? (int) $shipping_product[0]['id'] : 0;

            if ( ! $shipping_product_id ) {
                $shipping_product_id = (int) $rpc->create( 'product.product', [
                    'name'         => 'Flete / Envío WooCommerce',
                    'default_code' => 'WOO-SHIPPING',
                    'type'         => 'service',
                    'sale_ok'      => true,
                    'purchase_ok'  => false,
                    'list_price'   => 0.0,
                ] );
            }

            if ( $shipping_product_id ) {
                foreach ( $shipping_lines as $shipping ) {
                    $method_title = trim( (string) ( $shipping['method_title'] ?? '' ) ) ?: 'Envío WooCommerce';
                    $order_lines[] = [ 0, 0, [
                        'product_id'      => $shipping_product_id,
                        'product_uom_qty' => 1.0,
                        'price_unit'      => (float) ( $shipping['total'] ?? 0 ),
                        'name'            => 'Flete: ' . $method_title,
                    ]];
                }
            } else {
                return [ 'success' => false, 'error' => 'No se pudo encontrar o crear el servicio WOO-SHIPPING para registrar el flete.' ];
            }
        }

        if ( empty( $order_lines ) ) {
            return [ 'success' => false, 'error' => 'No se pudieron emparejar productos del pedido con Odoo.' ];
        }

        // 4. Crear la orden de venta
        $vals = [
            'partner_id'       => $partner_id,
            'client_order_ref' => $ref,
            'note'             => sprintf(
                "Pedido importado desde WooCommerce #%s.\nCelular: %s\nMétodo de pago: %s\nComprobante: %s\nAgencia de envío: %s\nNota del cliente: %s",
                $order_data['woo_order_id'],
                $order_data['billing']['phone'] ?? '',
                $order_data['payment_method_title'] ?? '',
                $order_data['payment_proof_url'] ?? '',
                $order_data['shipping_agency'] ?? '',
                $order_data['customer_note'] ?? ''
            ),
            'order_line'         => $order_lines,
            'woo_source'         => true,
            'woo_order_id'       => (int) $order_data['woo_order_id'],
            'woo_order_status'   => $order_data['status'] ?? '',
            'woo_payment_method' => $order_data['payment_method_title'] ?? '',
        ];

        $new_id = $rpc->create( 'sale.order', $vals );
        if ( $new_id ) {
            return [ 'success' => true, 'odoo_sale_id' => $new_id, 'message' => "Creado como sale.order ID: {$new_id}" ];
        }

        return [ 'success' => false, 'error' => 'Error al crear sale.order en Odoo.' ];
    }
}

// Reintento programado de envío
add_action( 'owc_retry_order', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order ) {
        delete_post_meta( $order_id, '_owc_sent_to_odoo' );
        OWC_Order_Webhook::order_placed( $order_id, $order );
    }
});
