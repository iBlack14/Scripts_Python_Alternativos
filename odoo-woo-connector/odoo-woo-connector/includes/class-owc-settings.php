<?php
/**
 * Clase de Configuración y Ajustes del Plugin
 * Gestiona las opciones almacenadas en WordPress (wp_options).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Settings {

    const OPTION_KEY = 'owc_settings';

    // ─── Valores por Defecto ─────────────────────────────────────────────────
    public static function defaults() {
        return [
            // Credenciales Odoo
            'odoo_url'          => '',
            'odoo_db'           => '',
            'odoo_user'         => '',
            'odoo_password'     => '',

            // Opciones de Sincronización
            'sync_match_field'  => 'default_code',  // 'default_code' o 'barcode'
            'sync_interval'     => 5,                // Minutos entre sincronizaciones
            'sync_orders'       => 'yes',            // ¿Enviar pedidos a Odoo?
            'auto_create_products' => 'yes',         // ¿Crear productos si no existen en Woo?
            'sync_images'       => 'yes',            // ¿Importar imagen de Odoo cuando falta en Woo?
            'image_sync_interval_months' => 2,       // Meses entre sincronización de imágenes

            // Modo B2B, Visibilidad de Precios e IGV
            'hide_prices_guest'        => 'yes', // Ocultar precios a visitantes/invitados
            'guest_price_text'         => 'Iniciar sesión | Registrarse para ver precio',
            'enable_igv_display'       => 'yes', // Mostrar desglose de IGV
            'igv_rate'                 => 18,    // Porcentaje de IGV (ej. 18%)
            'enable_role_registration' => 'yes', // Permitir elegir Cliente vs Ferretero/Distribuidor al registrarse

            // Diseños, Temas y Estilos Visuales (Login, Mi Cuenta, Carrito y Checkout)
            'design_theme'             => 'cyber_glass',  // 'cyber_glass', 'minimal_light', 'corporate_slate', 'luxe_noir', 'native'
            'auth_layout'              => 'split_screen', // 'split_screen', 'centered_card', 'side_by_side', 'native'
            'color_palette'            => 'grupo_flores',      // preset seleccionado
            // Manejo directo de paleta de colores por el usuario ("manejarla yo")
            'custom_primary_color'     => '#0047ba',
            'custom_accent_color'      => '#f37021',
            'custom_bg_color'          => '#f4f6fa',
            'custom_surface_color'     => '#ffffff',
            'custom_surface_2_color'   => '#ffffff',
            'custom_text_color'        => '#0f172a',
            'custom_text_dim_color'    => '#475569',
            'custom_border_color'      => '#cbd5e1',
            // Personalización de Carrito y Checkout
            'enable_cart_styling'      => 'yes',          // ¿Aplicar diseño moderno al carrito?
            'cart_layout'              => 'modern_split', // 'modern_split' (Grid 2 col con Totales flotantes) o 'classic'
            'enable_checkout_styling'  => 'yes',          // ¿Aplicar diseño moderno al checkout?
            'cart_b2b_badge'           => 'no',           // Mostrar badge informativo en el carrito
            'auth_title'               => 'Portal Empresarial & Tienda B2B',
            'auth_subtitle'            => 'Accede a tu cuenta para ver precios exclusivos, existencias y pedidos.',
            'auth_hero_title'          => 'Acceso para Clientes y Ferreteros',
            'auth_hero_subtitle'       => 'Gestiona tus compras mayoristas, cotizaciones y pedidos empresariales.',

            // Token secreto para autenticar peticiones entrantes desde el servicio Python
            'api_secret_token'  => '',

            // Agencias y Asesores
            'shipping_agencies' => "AREQUIPA EXPRESO MARVISUR EIRL\nEMP. DE TRANS. FLORES HNOS. SRL.\nSHALOM EMPRESARIAL S.A.C.",
            'checkout_advisors' => "Local Principal | \nMarina | 987654321\nAbraham | 912345678\nCristian | ",
            'digital_card_url'  => 'https://grupofloressa.com/tarjeta-digital/',
            'enable_internal_digital_card' => 'yes',

            // Control de estado
            'last_sync_products' => '',
            'last_sync_stock'    => '',
            'last_sync_orders'   => '',
        ];
    }

    // ─── Obtener todos los ajustes ───────────────────────────────────────────
    public static function get_all() {
        $saved = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $saved, self::defaults() );
    }

    // ─── Obtener un ajuste por clave ─────────────────────────────────────────
    public static function get( $key, $default = null ) {
        $all = self::get_all();
        return isset( $all[ $key ] ) ? $all[ $key ] : $default;
    }

    // ─── Guardar ajustes ─────────────────────────────────────────────────────
    public static function save( $data ) {
        $current = self::get_all();
        $updated = array_merge( $current, $data );
        update_option( self::OPTION_KEY, $updated );
    }

    // ─── Actualizar un campo de estado (sin pasar por el formulario) ─────────
    public static function set_last_sync( $type, $datetime = null ) {
        if ( ! $datetime ) {
            $datetime = current_time('Y-m-d H:i:s');
        }
        $current = self::get_all();
        $current["last_sync_{$type}"] = $datetime;
        update_option( self::OPTION_KEY, $current );
    }

    // ─── Generar Token Secreto Aleatorio ─────────────────────────────────────
    public static function generate_token() {
        return bin2hex( random_bytes( 24 ) );
    }

    // ─── Activación: crear opciones iniciales ────────────────────────────────
    public static function activate() {
        $existing = get_option( self::OPTION_KEY, null );
        if ( $existing === null ) {
            $defaults = self::defaults();
            $defaults['api_secret_token'] = self::generate_token();
            add_option( self::OPTION_KEY, $defaults );
        }
    }

    // ─── Desactivación ───────────────────────────────────────────────────────
    public static function deactivate() {
        wp_clear_scheduled_hook( 'owc_cron_sync_stock' );
    }
}
