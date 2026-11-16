<?php
/**
 * Pasarela manual de Yape con verificación de comprobante.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Gateway_Yape extends WC_Payment_Gateway {

    public $yape_number;
    public $account_name;
    public $qr_url;

    public function __construct() {
        $this->id                 = 'owc_yape';
        $this->method_title       = 'Yape manual';
        $this->method_description = 'Pago sin conexión a Yape. El administrador verifica el comprobante manualmente.';
        $this->has_fields         = true;

        $this->init_form_fields();
        $this->init_settings();
        $this->icon = esc_url_raw( $this->get_option( 'icon_url', '' ) );

        $this->enabled      = $this->get_option( 'enabled', 'yes' );
        $this->title        = $this->get_option( 'title', 'Yape' );
        $this->description  = $this->get_option( 'description', 'Realiza el pago por Yape y adjunta el comprobante antes de confirmar tu pedido.' );
        $this->yape_number  = $this->get_option( 'yape_number', '' ) ?: '946-195-069';
        $this->account_name = $this->get_option( 'account_name', '' ) ?: 'GRUPO FLORES PERÚ S.A.';
        $this->qr_url       = $this->get_option( 'qr_url', '' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/Desactivar',
                'type'    => 'checkbox',
                'label'   => 'Activar pagos manuales por Yape',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'Título',
                'type'        => 'text',
                'default'     => 'Yape',
                'desc_tip'    => true,
                'description' => 'Nombre mostrado durante el pago.',
            ],
            'icon_url' => [
                'title' => 'Icono del método de pago',
                'type' => 'url',
                'description' => 'Sube el icono a Medios y pega su URL. Aparece junto al nombre del método de pago.',
            ],
            'description' => [
                'title'   => 'Instrucciones',
                'type'    => 'textarea',
                'default' => 'Realiza el pago por Yape y adjunta el comprobante antes de confirmar tu pedido.',
            ],
            'yape_number' => [
                'title'       => 'Número de Yape',
                'type'        => 'text',
                'default'     => '946-195-069',
                'description' => 'Número al que el cliente debe realizar el pago.',
            ],
            'account_name' => [
                'title'       => 'Titular',
                'type'        => 'text',
                'default'     => 'GRUPO FLORES PERÚ S.A.',
                'description' => 'Nombre del titular que aparecerá junto al número.',
            ],
            'qr_url' => [
                'title'       => 'URL del código QR',
                'type'        => 'url',
                'description' => 'Enlace HTTPS de la imagen QR de Yape (opcional).',
            ],
        ];
    }

    public function payment_fields() {
        echo '<p>Al realizar el pedido verás el número y el QR de Yape y podrás adjuntar tu comprobante.</p>';
    }

    public function render_payment_details() {
        echo '<h3>Pago con Yape</h3>';
        if ( $this->yape_number ) {
            echo '<p><strong>Número Yape:</strong> ' . esc_html( $this->yape_number ) . '</p>';
        }
        if ( $this->account_name ) {
            echo '<p><strong>Titular:</strong> ' . esc_html( $this->account_name ) . '</p>';
        }
        if ( $this->qr_url ) {
            echo '<p><img src="' . esc_url( $this->qr_url ) . '" alt="Código QR de Yape" style="display:block;max-width:220px;height:auto;"></p>';
        }
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'result' => 'failure' ];

        $order->update_status( 'on-hold', 'Pago por Yape pendiente de verificación del comprobante.' );
        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    public function thankyou_page() {
        echo '<p>Recibimos tu pedido. Realiza el pago por Yape y adjunta tu comprobante para su verificación.</p>';
    }
}
