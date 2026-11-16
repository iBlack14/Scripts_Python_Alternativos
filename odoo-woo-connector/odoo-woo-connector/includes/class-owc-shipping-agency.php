<?php
/**
 * OWC Shipping Agency - Selector de Agencia de Envio en Checkout
 *
 * - Muestra el dropdown SOLO cuando el metodo de envio elegido
 *   tiene costo (flete). Si el cliente elige "Recojo en Tienda"
 *   (local_pickup o cualquier metodo gratuito) el campo se oculta
 *   via JS. La agencia es opcional y no bloquea la creacion del pedido.
 * - La agencia se guarda como meta del pedido y se envia a Odoo.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Shipping_Agency {

    const META_KEY   = '_owc_shipping_agency';
    const OPTION_KEY = 'owc_shipping_agencies';

    // IDs de metodos de envio que NO necesitan agencia (recojo/pickup).
    // Agrega aqui el ID de tu metodo "Recojo en Tienda" si no es local_pickup.
    private static $pickup_methods = [
        'local_pickup',
        'local_pickup_plus',
        'free_shipping',  // si tu "recojo" usa tarifa gratis
    ];

    // -------------------------------------------------------------------------
    public static function init() {
        add_action( 'woocommerce_after_checkout_billing_form',            [ __CLASS__, 'render_checkout_field' ] );
        add_action( 'woocommerce_checkout_create_order',                   [ __CLASS__, 'save_to_order' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ __CLASS__, 'show_in_admin' ], 10, 1 );
        add_action( 'woocommerce_order_details_after_order_table',         [ __CLASS__, 'show_in_thankyou' ], 10, 1 );
        add_action( 'wp_head',                                             [ __CLASS__, 'inject_styles_and_js' ] );
    }

    // ── Detectar si el metodo activo en el carrito es de recojo ──────────────
    private static function chosen_method_is_pickup() {
        $chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
        foreach ( $chosen as $method_id ) {
            // Quitar instancia: "local_pickup:1" -> "local_pickup"
            $base = explode( ':', $method_id )[0];
            if ( in_array( $base, self::$pickup_methods, true ) ) {
                return true;
            }
        }
        return false;
    }

    // ── Lista de agencias disponibles ────────────────────────────────────────
    public static function get_agencies() {
        // Try new JSON format first
        $json = class_exists( 'OWC_Settings' ) ? OWC_Settings::get( 'shipping_agencies_json', '[]' ) : '[]';
        $decoded = json_decode( $json, true );
        if ( is_array( $decoded ) && ! empty( $decoded ) ) {
            $list = [];
            foreach ( $decoded as $a ) {
                $name = trim( $a['name'] ?? '' );
                if ( ! empty( $name ) ) $list[] = $name;
            }
            if ( ! empty( $list ) ) return $list;
        }

        // Fallback: old text format
        $saved = class_exists( 'OWC_Settings' ) ? OWC_Settings::get( 'shipping_agencies' ) : '';
        if ( ! empty( $saved ) ) {
            $list = array_filter( array_map( 'trim', explode( "\n", $saved ) ) );
            if ( ! empty( $list ) ) return $list;
        }

        return [
            'AREQUIPA EXPRESO MARVISUR EIRL',
            'EMP. DE TRANS. FLORES HNOS. SRL.',
            'SHALOM EMPRESARIAL S.A.C.',
            'TRANSPORTES JULIO CESAR S.R.L.',
        ];
    }

    // ── Render del campo en el Checkout ──────────────────────────────────────
    public static function render_checkout_field( $checkout ) {
        $agencies = self::get_agencies();
        $options  = [ '' => '- Selecciona tu agencia de envio -' ];
        foreach ( $agencies as $agency ) {
            $options[ $agency ] = $agency;
        }

        // El wrapper se muestra/oculta con JS segun el metodo elegido.
        // Empieza oculto si el metodo actual es recojo.
        $hidden = self::chosen_method_is_pickup() ? ' style="display:none"' : '';

        echo '<div id="owc-shipping-agency-field" class="owc-agency-wrapper"' . $hidden . '>';
        woocommerce_form_field( 'owc_shipping_agency', [
            'type'     => 'select',
            'class'    => [ 'owc-agency-select form-row-wide' ],
            'label'    => 'Agencia de envio',
            'required' => false, // La agencia es opcional para cualquier metodo de envio.
            'options'  => $options,
        ], $checkout->get_value( 'owc_shipping_agency' ) );
        echo '</div>';
    }

    // ── Guardar agencia en el pedido ─────────────────────────────────────────
    public static function save_to_order( $order, $data ) {
        $agency = '';
        if ( ! empty( $_POST['owc_shipping_agency'] ) ) {
            $agency = sanitize_text_field( wp_unslash( $_POST['owc_shipping_agency'] ) );
        }
        // Si es recojo, guardamos "RECOJO EN TIENDA" automaticamente
        if ( empty( $agency ) && self::chosen_method_is_pickup() ) {
            $agency = 'RECOJO EN TIENDA';
        }
        if ( $agency ) {
            $order->update_meta_data( self::META_KEY, $agency );
        }
    }

    // ── Mostrar en Admin → Detalle de Pedido ─────────────────────────────────
    public static function show_in_admin( $order ) {
        $agency = $order->get_meta( self::META_KEY );
        if ( ! $agency ) return;
        echo '<div style="margin-top:12px;padding:10px 14px;background:#f0f4ff;border-left:4px solid #0649b8;border-radius:6px;">';
        echo '<strong style="color:#0649b8;">Agencia de envio:</strong><br>';
        echo '<span style="font-size:14px;color:#172033;">' . esc_html( $agency ) . '</span>';
        echo '</div>';
    }

    // ── Mostrar en Pagina "Pedido Recibido" ───────────────────────────────────
    public static function show_in_thankyou( $order ) {
        $agency = $order->get_meta( self::META_KEY );
        if ( ! $agency ) return;
        echo '<section class="woocommerce-order-details" style="margin-top:22px;">';
        echo '<h2 class="woocommerce-order-details__title">Agencia de Envio Seleccionada</h2>';
        echo '<p style="font-size:15px;margin:0;padding:14px 18px;background:#f0f4ff;border-radius:10px;border:1px solid #c7d8f8;">';
        echo esc_html( $agency );
        echo '</p></section>';
    }

    // ── Estilos CSS + JS reactivo ─────────────────────────────────────────────
    public static function inject_styles_and_js() {
        if ( ! is_checkout() ) return;
        ?>
        <style>
            #owc-shipping-agency-field { margin:18px 0 10px !important; }
            #owc-shipping-agency-field label {
                font-weight:700; font-size:14px; color:#172033;
                margin-bottom:6px !important; display:block;
            }
            #owc_shipping_agency {
                width:100% !important;
                padding:12px 16px !important;
                border:2px solid #c7d8f8 !important;
                border-radius:10px !important;
                font-size:14px !important;
                color:#172033 !important;
                background:#f8faff !important;
                -webkit-appearance:none !important;
                -moz-appearance:none !important;
                appearance:none !important;
                cursor:pointer;
                transition:border-color .2s, box-shadow .2s;
            }
            #owc_shipping_agency:focus {
                border-color:#0649b8 !important;
                box-shadow:0 0 0 3px rgba(6,73,184,.15) !important;
                outline:none !important;
            }
            /* Animacion suave al mostrar/ocultar */
            #owc-shipping-agency-field {
                overflow:hidden;
                transition:opacity .25s ease, max-height .3s ease;
            }
            #owc-shipping-agency-field.owc-hidden {
                display:none !important;
            }
        </style>

        <script>
        (function($){
            // IDs de metodos que NO necesitan agencia (igual que en PHP)
            var PICKUP_METHODS = ['local_pickup','local_pickup_plus','free_shipping'];

            function getChosenMethodBase() {
                // WooCommerce pone la clase "shipping_method" en los inputs radio
                var $checked = $('input[name^="shipping_method"]:checked');
                if ( ! $checked.length ) return '';
                var val = $checked.val() || '';       // ej. "local_pickup:1"
                return val.split(':')[0];
            }

            function isPickup( methodBase ) {
                return PICKUP_METHODS.indexOf( methodBase ) !== -1;
            }

            function toggleAgencyField() {
                var base   = getChosenMethodBase();
                var pickup = isPickup( base );
                var $field = $('#owc-shipping-agency-field');

                if ( pickup ) {
                    $field.slideUp(200).addClass('owc-hidden');
                    // Limpiar seleccion para que no se envie valor incorrecto
                    $('#owc_shipping_agency').val('').trigger('change');
                } else {
                    $field.slideDown(250).removeClass('owc-hidden');
                }
            }

            $(document).on( 'change', 'input[name^="shipping_method"]', function() {
                toggleAgencyField();
            });

            // Tambien escuchar cuando WooCommerce recarga el checkout (AJAX)
            $(document.body).on( 'updated_checkout', function() {
                toggleAgencyField();
            });

            // Ejecutar al cargar la pagina
            $(function(){ toggleAgencyField(); });

        })(jQuery);
        </script>
        <?php
    }

    // ── Helper publico ────────────────────────────────────────────────────────
    public static function get_order_agency( $order_id ) {
        $order = is_a( $order_id, 'WC_Order' ) ? $order_id : wc_get_order( $order_id );
        return $order ? $order->get_meta( self::META_KEY ) : '';
    }
}
