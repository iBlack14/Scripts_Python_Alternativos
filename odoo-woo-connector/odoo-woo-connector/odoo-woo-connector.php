<?php
/**
 * Plugin Name: Odoo 15 WooCommerce Connector
 * Plugin URI:  
 * Description: Conector bidireccional entre Odoo 15 y WooCommerce para productos almacenables, pedidos y stock A Mano. (Con soporte de API Key para Imágenes).
 * Version:     4.4.40
 * Author:      Blxk Studio
 * Author URI:  https://blxkstudio.com
 * Text Domain: odoo-woo-connector
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad: evitar acceso directo
}

// ─── Constantes del Plugin ───────────────────────────────────────────────────
define( 'OWC_VERSION',     '4.4.40' );
define( 'OWC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'OWC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'OWC_PLUGIN_FILE', __FILE__ );

// ─── Cargar Módulos ──────────────────────────────────────────────────────────
require_once OWC_PLUGIN_DIR . 'includes/class-owc-settings.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-sync-logger.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-sync-engine.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-b2b-catalog.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-b2b-payments.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-auth-templates.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-admin-page.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-elementor-widgets.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-woo-customizer.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-api-receiver.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-customer-webhook.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-order-webhook.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-shipping-agency.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-digital-card.php';
require_once OWC_PLUGIN_DIR . 'includes/class-owc-checkout-fields.php';

// ─── Activación / Desactivación ──────────────────────────────────────────────
register_activation_hook( __FILE__,   [ 'OWC_Settings', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'OWC_Settings', 'deactivate' ] );



// ─── Inicialización ──────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Odoo WooCommerce Connector:</strong> Requiere que WooCommerce esté instalado y activo.</p></div>';
        });
        return;
    }



    // Módulo B2B de precios a invitados, IGV y registro cliente/proveedor
    OWC_B2B_Catalog::init();
    
    // Pagos B2B: transferencia/Yape y comprobante obligatorio
    OWC_B2B_Payments::init();

    // Campos adicionales para el checkout
    OWC_Checkout_Fields::init();

    // Endpoints REST API personalizados
    OWC_API_Receiver::init();

    // Sincronización de clientes en registro/actualización de perfil
    OWC_Customer_Webhook::init();

    // Webhook de pedidos → Odoo
    OWC_Order_Webhook::init();

    // Selector de agencia de envío en checkout
    OWC_Shipping_Agency::init();



    // Módulo de Plantillas de Login & Mi Cuenta
    OWC_Auth_Templates::init();

    // Panel de administración
    OWC_Admin_Page::init();

    // Personalización UI WooCommerce
    OWC_Woo_Customizer::init();

    // Widgets para Elementor
    OWC_Elementor_Widgets::init();
});

// ─── PARCHE: Forzar URL correcta de Recibo de Compra (Evitar 404) ────────────
add_filter( 'woocommerce_get_return_url', function( $return_url, $order ) {
    if ( $order ) {
        // Obligamos a WordPress a usar la ruta exacta de tu nueva página de checkout
        return home_url( '/finalizar-compra-seguro/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key() );
    }
    return $return_url;
}, 9999, 2 );
