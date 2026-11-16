<?php
/** Pago presencial en tienda, sin cuentas bancarias ni comprobantes. */
if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Gateway_Store_Payment extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'owc_store_payment';
        $this->method_title = 'Pago en tienda';
        $this->method_description = 'El cliente crea su pedido y paga presencialmente. El administrador confirma el cobro en WooCommerce.';
        $this->has_fields = true;
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option( 'enabled', 'yes' );
        $this->title = $this->get_option( 'title', 'Pago en tienda' );
        $this->description = $this->get_option( 'description', $this->default_instructions() );
        $this->icon = esc_url_raw( $this->get_option( 'icon_url', '' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    private function default_instructions() {
        return 'Acércate a nuestra tienda e indica tu número de pedido. Comunícate con tu asesor asignado para coordinar la atención. Tu pedido será procesado cuando confirmemos el pago.';
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [ 'title' => 'Activar/Desactivar', 'type' => 'checkbox', 'label' => 'Activar pago en tienda', 'default' => 'yes' ],
            'title' => [ 'title' => 'Título', 'type' => 'text', 'default' => 'Pago en tienda' ],
            'icon_url' => [ 'title' => 'URL del icono', 'type' => 'url', 'description' => 'Opcional: imagen de Medios. Si se deja vacío se usa el icono de tienda incluido.' ],
            'description' => [ 'title' => 'Instrucciones después del pedido', 'type' => 'textarea', 'default' => $this->default_instructions(), 'description' => 'Se muestran debajo del asesor, después de realizar el pedido.' ],
            'store_address' => [ 'title' => 'Dirección de la tienda', 'type' => 'textarea', 'description' => 'Opcional. Se muestra después del pedido.' ],
            'store_hours' => [ 'title' => 'Horario de atención', 'type' => 'text', 'description' => 'Opcional. Se muestra después del pedido.' ],
        ];
    }

    public function payment_fields() {
        echo '<p>Realiza tu pedido y paga en tienda. En la siguiente pantalla verás las instrucciones para coordinar con tu asesor.</p>';
    }

    public function render_store_details( $order ) {
        echo '<p><strong>Número de pedido:</strong> ' . esc_html( $order->get_order_number() ) . '</p>';
        $address = $this->get_option( 'store_address', '' );
        $hours = $this->get_option( 'store_hours', '' );
        if ( $address ) echo '<p><strong>Dirección:</strong><br>' . nl2br( esc_html( $address ) ) . '</p>';
        if ( $hours ) echo '<p><strong>Horario de atención:</strong> ' . esc_html( $hours ) . '</p>';
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'result' => 'failure' ];
        $order->update_status( 'on-hold', 'Pendiente de pago presencial en tienda. Confirmar el cobro antes de procesar el pedido.' );
        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();
        return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
    }
}
