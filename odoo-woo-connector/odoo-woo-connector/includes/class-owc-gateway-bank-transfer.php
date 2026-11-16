<?php
/**
 * Pasarela manual de transferencia bancaria con comprobante.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Gateway_Bank_Transfer extends WC_Payment_Gateway {

    public $account_name;
    public $account_data;
    public $payment_image;
    public $accounts;

    public function __construct() {
        $this->id                 = 'owc_bank_transfer';
        $this->method_title       = 'Transferencia bancaria manual';
        $this->method_description = 'Transferencia sin conexión bancaria. El administrador verifica el comprobante manualmente.';
        $this->has_fields         = true;

        $this->init_form_fields();
        $this->init_settings();
        $this->icon = esc_url_raw( $this->get_option( 'icon_url', '' ) );

        $this->enabled       = $this->get_option( 'enabled', 'yes' );
        $this->title         = $this->get_option( 'title', 'Transferencia bancaria' );
        $this->description   = $this->get_option( 'description', 'Transfiere el total del pedido y adjunta tu comprobante.' );
        $this->account_name  = $this->get_option( 'account_name', 'GRUPO FLORES PERÚ S.A.' );
        $this->account_data  = $this->get_option( 'account_data', $this->default_account_data() );
        $this->accounts = $this->get_option( 'accounts', [] );
        $this->payment_image = $this->get_option( 'payment_image', '' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
    }

    private function default_account_data() {
        return "BCP SOLES: 220-7224146-0-92\nCCI: 00222000722414609221\n\nBCP DÓLARES: 220-7224889-1-06\nCCI: 00222000722488910620\n\nINTERBANK: 440-3008328850\nCCI: 003-440-003008328850-53";
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/Desactivar',
                'type'    => 'checkbox',
                'label'   => 'Activar transferencia bancaria manual',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'Título',
                'type'        => 'text',
                'default'     => 'Transferencia bancaria',
                'description' => 'Nombre mostrado al cliente durante el pago.',
            ],
            'icon_url' => [
                'title' => 'Icono del método de pago',
                'type' => 'url',
                'description' => 'Sube el icono a Medios y pega su URL. Aparece junto al nombre del método de pago.',
            ],
            'description' => [
                'title'   => 'Instrucciones',
                'type'    => 'textarea',
                'default' => 'Transfiere el total del pedido y adjunta tu comprobante. El pedido será atendido después de verificar el pago.',
            ],
            'account_name' => [
                'title'   => 'Titular',
                'type'    => 'text',
                'default' => 'GRUPO FLORES PERÚ S.A.',
            ],
            'accounts' => [
                'title' => 'Cuentas por banco',
                'type' => 'accounts',
                'default' => [],
                'description' => 'Configura el banco, icono, titular, cuenta y CCI de cada cuenta. Si no agregas cuentas aquí, se utilizan los datos anteriores.',
            ],
            'account_data' => [
                'title'       => 'Cuentas bancarias',
                'type'        => 'textarea',
                'default'     => $this->default_account_data(),
                'description' => 'Separa cada banco o cuenta con una línea en blanco. Primera línea: banco y cuenta; siguientes líneas: CCI u otros datos. Se muestran en el checkout y debajo del asesor al finalizar el pedido.',
            ],
            'payment_image' => [
                'title'       => 'URL de imagen de cuentas',
                'type'        => 'url',
                'description' => 'Opcional: sube la imagen a Medios y pega aquí su URL.',
            ],
        ];
    }

    public function generate_accounts_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $rows = $this->get_option( $key, [] );
        if ( ! is_array( $rows ) ) $rows = [];
        // The empty row also ensures deleting all rows clears the saved list.
        $rows[] = [];
        ob_start();
        echo '<tr><th scope="row">' . esc_html( $data['title'] ) . '</th><td><div id="owc-account-editor">';
        foreach ( array_values( $rows ) as $index => $row ) {
            echo '<fieldset class="owc-account-editor-row" style="padding:12px;border:1px solid #ccd0d4;margin-bottom:10px;max-width:650px;">';
            foreach ( [ 'bank' => 'Banco / moneda', 'icon' => 'URL del icono del banco', 'holder' => 'Titular', 'number' => 'Número de cuenta', 'cci' => 'CCI (opcional)' ] as $field => $label ) {
                echo '<label style="display:block;margin-bottom:8px;">' . esc_html( $label ) . '<input style="display:block;width:100%;" type="' . ( 'icon' === $field ? 'url' : 'text' ) . '" name="' . esc_attr( $field_key . '[' . $index . '][' . $field . ']' ) . '" value="' . esc_attr( $row[ $field ] ?? '' ) . '"></label>';
            }
            echo '<button type="button" class="button owc-remove-account">Quitar cuenta</button></fieldset>';
        }
        echo '</div><button type="button" class="button" id="owc-add-account">Agregar cuenta bancaria</button><p class="description">' . esc_html( $data['description'] ) . '</p>';
        ?>
        <script>
        (function(){
            const editor = document.getElementById('owc-account-editor');
            const template = editor.querySelector('fieldset').cloneNode(true);
            let nextIndex = editor.children.length;
            document.getElementById('owc-add-account').addEventListener('click', function(){
                const row = template.cloneNode(true);
                row.querySelectorAll('input').forEach(function(input){
                    input.value = '';
                    input.name = input.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
                });
                nextIndex++;
                editor.appendChild(row);
            });
            editor.addEventListener('click', function(event){
                if (!event.target.classList.contains('owc-remove-account')) return;
                const row = event.target.closest('fieldset');
                if (editor.children.length === 1) row.querySelectorAll('input').forEach(function(input){input.value='';});
                else row.remove();
            });
        })();
        </script>
        <?php
        echo '</td></tr>';
        return ob_get_clean();
    }

    public function validate_accounts_field( $key, $value ) {
        $clean = [];
        foreach ( is_array( $value ) ? $value : [] as $row ) {
            if ( ! is_array( $row ) ) continue;
            $account = [];
            foreach ( [ 'bank', 'icon', 'holder', 'number', 'cci' ] as $field ) {
                $raw = isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ? (string) $row[ $field ] : '';
                $account[ $field ] = 'icon' === $field ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
            }
            if ( '' !== $account['bank'] || '' !== $account['number'] ) $clean[] = $account;
        }
        return $clean;
    }

    public function payment_fields() {
        echo '<p>Al realizar el pedido verás las cuentas bancarias y podrás adjuntar tu comprobante.</p>';
    }

    public function render_accounts() {
        if ( empty( $this->accounts ) && ! trim( (string) $this->account_data ) && ! $this->payment_image ) return;
        echo '<style>
        .owc-bank-accounts{margin:16px 0!important;padding:0!important;border:0!important;background:transparent!important;min-width:0;}
        .owc-bank-accounts h3{font-size:18px!important;line-height:1.3!important;margin:0 0 8px!important;color:#172033!important;}
        .owc-bank-accounts .owc-bank-holder{font-size:13px!important;line-height:1.5!important;margin:0 0 12px!important;}
        .owc-bank-accounts .owc-bank-grid{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr))!important;gap:10px!important;padding:0!important;margin:0!important;border:0!important;background:transparent!important;white-space:normal!important;min-width:0;}
        .owc-bank-accounts .owc-bank-card{padding:12px!important;margin:0!important;border:1px solid #dbe3ed!important;border-radius:8px!important;background:#fff!important;min-width:0;box-sizing:border-box;}
        .owc-bank-accounts .owc-bank-icon{display:block!important;width:48px!important;height:32px!important;object-fit:contain;margin:0 0 8px!important;float:none!important;}
        .owc-bank-accounts .owc-bank-title{display:block;font-size:14px!important;line-height:1.4!important;color:#0047ba!important;margin:0 0 6px!important;}
        .owc-bank-accounts .owc-bank-line{display:block!important;padding:0!important;margin:4px 0 0!important;border:0!important;background:transparent!important;white-space:normal!important;line-height:1.5!important;font-size:13px!important;}
        .owc-bank-accounts .owc-bank-label{display:block;font-size:11px;color:#64748b;}
        .owc-bank-accounts .owc-bank-number{display:block;font-size:12px!important;font-variant-numeric:tabular-nums;white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important;overflow-x:auto;max-width:100%;color:#172033!important;}
        </style>';
        echo '<section class="owc-bank-accounts">';
        echo '<h3>Cuentas bancarias</h3>';
        if ( empty( $this->accounts ) && $this->account_name ) {
            echo '<p class="owc-bank-holder"><strong>Titular:</strong> ' . esc_html( $this->account_name ) . '</p>';
        }
        $groups = preg_split( '/\n\s*\n/', str_replace( "\r", '', trim( (string) $this->account_data ) ), -1, PREG_SPLIT_NO_EMPTY );
        echo '<div class="owc-bank-grid">';
        if ( is_array( $this->accounts ) && ! empty( $this->accounts ) ) {
            foreach ( $this->accounts as $account ) {
                echo '<article class="owc-bank-card">';
                if ( ! empty( $account['icon'] ) ) echo '<img class="owc-bank-icon" src="' . esc_url( $account['icon'] ) . '" alt="' . esc_attr( $account['bank'] ?? '' ) . '">';
                echo '<strong class="owc-bank-title">' . esc_html( $account['bank'] ?? '' ) . '</strong>';
                foreach ( [ 'holder' => 'Titular', 'number' => 'Cuenta', 'cci' => 'CCI' ] as $field => $label ) {
                    $value = $account[ $field ] ?? '';
                    if ( 'holder' === $field && '' === $value ) $value = $this->account_name;
                    if ( '' === $value ) continue;
                    echo '<div class="owc-bank-line"><span class="owc-bank-label">' . esc_html( $label ) . '</span><span' . ( 'holder' === $field ? '' : ' class="owc-bank-number"' ) . '>' . esc_html( $value ) . '</span></div>';
                }
                echo '</article>';
            }
        } else foreach ( $groups as $group ) {
            $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $group ) ), 'strlen' ) );
            if ( ! $lines ) continue;
            $heading = explode( ':', array_shift( $lines ), 2 );
            echo '<article class="owc-bank-card"><strong class="owc-bank-title">' . esc_html( trim( $heading[0] ) ) . '</strong>';
            if ( isset( $heading[1] ) && '' !== trim( $heading[1] ) ) {
                array_unshift( $lines, 'Cuenta: ' . trim( $heading[1] ) );
            }
            foreach ( $lines as $line ) {
                $parts = explode( ':', $line, 2 );
                echo '<div class="owc-bank-line">';
                if ( count( $parts ) === 2 ) {
                    echo '<span class="owc-bank-label">' . esc_html( trim( $parts[0] ) ) . '</span><span class="owc-bank-number">' . esc_html( trim( $parts[1] ) ) . '</span>';
                } else {
                    echo '<span class="owc-bank-number">' . esc_html( $line ) . '</span>';
                }
                echo '</div>';
            }
            echo '</article>';
        }
        echo '</div>';
        if ( $this->payment_image ) {
            echo '<p><img src="' . esc_url( $this->payment_image ) . '" alt="Cuentas bancarias" style="display:block;max-width:100%;height:auto;"></p>';
        }
        echo '</section>';
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'result' => 'failure' ];

        $order->update_status( 'on-hold', 'Transferencia pendiente de verificación manual del comprobante.' );
        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    public function thankyou_page() {
        echo '<p>Recibimos tu pedido. Realiza la transferencia y adjunta el comprobante para verificar tu pago.</p>';
    }
}
