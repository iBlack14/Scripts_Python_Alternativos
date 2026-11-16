<?php
/**
 * OWC Checkout Fields - Campos adicionales para el checkout
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Checkout_Fields {

    public static function init() {
        add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'render_terms_fallback' ] );
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_terms_fallback' ] );
        add_filter( 'woocommerce_checkout_show_terms', '__return_true' );
        add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', [ __CLASS__, 'terms_label' ] );

        // Mostrar los campos después del formulario de facturación
        add_action( 'woocommerce_after_checkout_billing_form', [ __CLASS__, 'render_custom_fields' ], 20 );
        
        // Guardar los datos en el pedido
        add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_to_order' ], 10, 2 );
        
        // Mostrar en el admin panel
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ __CLASS__, 'show_in_admin' ], 10, 1 );

        // Banner en la página de gracias (Thank You / Order Received) y en Ver Pedido (Mi Cuenta)
        add_action( 'woocommerce_thankyou', [ __CLASS__, 'show_advisor_banner' ], 5 );
        add_action( 'woocommerce_view_order', [ __CLASS__, 'show_advisor_banner' ], 5 );
    }

    // WooCommerce renders and validates its own checkbox when a terms page is selected.
    public static function terms_label( $text ) {
        $page_id = wc_get_page_id( 'terms' );
        if ( $page_id <= 0 ) return $text;
        return 'Acepto los <a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener noreferrer">términos y condiciones</a>';
    }

    public static function render_terms_fallback() {
        if ( wc_get_page_id( 'terms' ) > 0 ) return;
        echo '<p class="form-row validate-required owc-terms"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="owc_accept_terms" value="1" required> <span>Acepto los <a href="' . esc_url( home_url( '/terminos-y-condiciones/' ) ) . '" target="_blank" rel="noopener noreferrer">términos y condiciones</a></span> <abbr class="required" title="obligatorio">*</abbr></label></p>';
    }

    public static function validate_terms_fallback() {
        if ( wc_get_page_id( 'terms' ) <= 0 && ( ! isset( $_POST['owc_accept_terms'] ) || '1' !== $_POST['owc_accept_terms'] ) ) {
            wc_add_notice( 'Debes aceptar los términos y condiciones para realizar el pedido.', 'error' );
        }
    }

    public static function advisor_name( $raw ) {
        if ( is_array( $raw ) ) return sanitize_text_field( $raw['name'] ?? '' );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) return '';
        $candidate = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
        // Support old orders saved with slashes and HTML-encoded JSON.
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $parsed = json_decode( $candidate, true );
            if ( is_array( $parsed ) ) return sanitize_text_field( $parsed['name'] ?? '' );
            if ( is_string( $parsed ) ) {
                $candidate = $parsed;
            } else {
                $candidate = wp_unslash( $candidate );
            }
        }
        // Never expose malformed serialized data as an advisor name.
        if ( false !== strpbrk( $candidate, '{}[]' ) ) return '';
        return sanitize_text_field( trim( explode( '|', $candidate )[0] ) );
    }

    public static function show_advisor_banner( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $asesor_name = self::advisor_name( $order->get_meta( '_owc_asesor_sucursal' ) );
        $asesor_text = $asesor_name
            ? 'el asesor que has seleccionado (<strong>' . esc_html( $asesor_name ) . '</strong>)'
            : 'tu asesor';
        
        $url = OWC_Settings::get( 'digital_card_url', 'https://grupofloressa.com/tarjeta-digital/' );
        if ( OWC_Settings::get( 'enable_internal_digital_card' ) === 'yes' ) {
            $url = site_url( '/?owc_tarjeta_digital=1' );
        } elseif ( empty( $url ) ) {
            $url = 'https://grupofloressa.com/tarjeta-digital/';
        }

        echo '<div style="background-color: #0047ba; color: #fff; padding: 30px 20px; border-radius: 12px; text-align: center; margin-bottom: 40px; box-shadow: 0 10px 30px rgba(0,71,186,0.15); font-family: inherit;">';
        echo '<h3 style="color: #fff; font-size: 18px; margin: 0 0 15px 0; font-weight: normal; line-height: 1.4;">Comunícate con ' . $asesor_text . ', o si no logras comunicarte, haz clic aquí.</h3>';
        echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display: inline-block; background-color: #d71920; color: #fff; padding: 14px 35px; font-weight: 800; font-size: 15px; text-decoration: none; border-radius: 8px; text-transform: uppercase; letter-spacing: 1px; transition: transform 0.2s, background 0.2s;">CLIC AQUÍ</a>';
        echo '</div>';

    }

    public static function render_custom_fields( $checkout ) {
        echo '<div id="owc_custom_checkout_fields" style="margin-top: 25px; clear: both;">';
        
        // Asesor comercial o Sucursal
        echo '<h3 style="font-size: 16px; color: #1e293b; margin-bottom: 12px;">¿Qué Asesor comercial o Sucursal hará seguimiento a tu pedido?</h3>';
        $raw_advisors = OWC_Settings::get('checkout_advisors_json', '[]');
        $advisor_options = [ '' => 'Seleccione una opción...' ];
        $decoded = json_decode( $raw_advisors, true );
        if ( is_array( $decoded ) && ! empty( $decoded ) ) {
            foreach ( $decoded as $a ) {
                $name  = trim( $a['name']  ?? '' );
                $phone = trim( $a['phone'] ?? '' );
                if ( empty( $name ) ) continue;
                $label = $phone ? $name . ' (' . $phone . ')' : $name;
                // key = serialized to recover all data later
                $key = json_encode( [ 'name' => $name, 'phone' => $phone, 'img' => trim( $a['img'] ?? '' ) ] );
                $advisor_options[ $key ] = $label;
            }
        } else {
            $advisor_options['{"name":"Local Principal","phone":"","img":""}'] = 'Local Principal';
        }

        woocommerce_form_field( 'owc_asesor_sucursal', [
            'type'        => 'select',
            'required'    => false, // Se puede cambiar luego
            'class'       => [ 'form-row-wide' ],
            'options'     => $advisor_options,
        ], $checkout->get_value( 'owc_asesor_sucursal' ) );

        // Fieldset: Datos de persona para retiro o envío
        echo '<div style="margin-top: 24px; border: 1px solid #e2e8f0; padding: 18px; border-radius: 2px;">';
        echo '<h4 style="font-size: 14px; font-weight: normal; color: #1e293b; margin: 0 0 15px 0;">Datos de persona para retiro o envío:</h4>';
        
        woocommerce_form_field( 'owc_persona_dni', [
            'type'        => 'text',
            'label'       => 'Número de identificación:',
            'placeholder' => 'DDD',
            'required'    => false,
            'class'       => [ 'form-row-wide' ],
        ], $checkout->get_value( 'owc_persona_dni' ) );

        woocommerce_form_field( 'owc_persona_nombres', [
            'type'        => 'text',
            'label'       => 'Nombres y Apellidos:',
            'placeholder' => 'DD',
            'required'    => false,
            'class'       => [ 'form-row-wide' ],
        ], $checkout->get_value( 'owc_persona_nombres' ) );

        woocommerce_form_field( 'owc_persona_telefono', [
            'type'        => 'text',
            'label'       => 'Número de telefono:',
            'placeholder' => 'DD',
            'required'    => false,
            'class'       => [ 'form-row-wide' ],
        ], $checkout->get_value( 'owc_persona_telefono' ) );

        echo '</div>';
        echo '</div>';
    }

    public static function save_to_order( $order, $data ) {
        if ( ! empty( $_POST['owc_accept_terms'] ) || ! empty( $_POST['terms'] ) ) {
            $order->update_meta_data( '_owc_terms_accepted', 'yes' );
        }
        if ( isset( $_POST['owc_asesor_sucursal'] ) ) {
            $order->update_meta_data( '_owc_asesor_sucursal', sanitize_text_field( wp_unslash( $_POST['owc_asesor_sucursal'] ) ) );
        }
        if ( isset( $_POST['owc_persona_dni'] ) ) {
            $order->update_meta_data( '_owc_persona_dni', sanitize_text_field( $_POST['owc_persona_dni'] ) );
        }
        if ( isset( $_POST['owc_persona_nombres'] ) ) {
            $order->update_meta_data( '_owc_persona_nombres', sanitize_text_field( $_POST['owc_persona_nombres'] ) );
        }
        if ( isset( $_POST['owc_persona_telefono'] ) ) {
            $order->update_meta_data( '_owc_persona_telefono', sanitize_text_field( $_POST['owc_persona_telefono'] ) );
        }
    }

    public static function show_in_admin( $order ) {
        echo '<h3>Datos Adicionales Odoo</h3>';
        
        $asesor = self::advisor_name( $order->get_meta( '_owc_asesor_sucursal' ) );
        if ( $asesor ) echo '<p><strong>Asesor/Sucursal:</strong> ' . esc_html( $asesor ) . '</p>';

        $dni = $order->get_meta( '_owc_persona_dni' );
        if ( $dni ) echo '<p><strong>DNI Retiro/Envío:</strong> ' . esc_html( $dni ) . '</p>';

        $nombres = $order->get_meta( '_owc_persona_nombres' );
        if ( $nombres ) echo '<p><strong>Nombres Retiro/Envío:</strong> ' . esc_html( $nombres ) . '</p>';

        $telefono = $order->get_meta( '_owc_persona_telefono' );
        if ( $telefono ) echo '<p><strong>Teléfono Retiro/Envío:</strong> ' . esc_html( $telefono ) . '</p>';
    }
}
