<?php
/**
 * Pagos manuales por transferencia y Yape con comprobante.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_B2B_Payments {

    const PROOF_META = '_owc_payment_proof_id';
    const OWNER_META = '_owc_payment_proof_user';
    const TOKEN_META = '_owc_payment_proof_token';
    const REVIEW_META = '_owc_payment_review_status';

    public static function init() {
        add_action( 'woocommerce_before_thankyou', [ __CLASS__, 'remove_native_bank_details' ] );
        add_action( 'woocommerce_thankyou', [ __CLASS__, 'render_order_payment' ], 6 );
        add_action( 'woocommerce_view_order', [ __CLASS__, 'render_order_payment' ], 6 );
        add_filter( 'woocommerce_settings_api_form_fields_bacs', [ __CLASS__, 'native_bank_icon_setting' ] );
        add_filter( 'woocommerce_gateway_icon', [ __CLASS__, 'payment_icon' ], 10, 2 );
        add_action( 'wp_head', [ __CLASS__, 'payment_choice_styles' ] );
        add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_manual_gateways' ] );
        add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'filter_gateways' ], 1000 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ __CLASS__, 'render_admin_proof' ] );
        add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'render_email_proof' ], 10, 4 );
        add_action( 'wp_ajax_owc_upload_payment_proof', [ __CLASS__, 'ajax_upload_proof' ] );
        add_action( 'wp_ajax_nopriv_owc_upload_payment_proof', [ __CLASS__, 'ajax_upload_proof' ] );
        add_action( 'admin_post_owc_confirm_store_payment', [ __CLASS__, 'handle_store_payment_confirmation' ] );
        add_action( 'admin_post_owc_review_payment', [ __CLASS__, 'handle_payment_review' ] );
    }

    public static function register_manual_gateways( $gateways ) {
        if ( ! class_exists( 'OWC_Gateway_Bank_Transfer' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-gateway-bank-transfer.php';
        }
        if ( ! class_exists( 'OWC_Gateway_Yape' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-gateway-yape.php';
        }
        if ( ! class_exists( 'OWC_Gateway_Store_Payment' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-gateway-store-payment.php';
        }
        $gateways[] = 'OWC_Gateway_Store_Payment';
        $gateways[] = 'OWC_Gateway_Bank_Transfer';
        $gateways[] = 'OWC_Gateway_Yape';
        return $gateways;
    }

    private static function is_provider() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        return in_array( OWC_B2B_Catalog::ROLE_PROVIDER, (array) $user->roles, true );
    }

    public static function is_manual_method( $method ) {
        return in_array( (string) $method, [ 'owc_bank_transfer', 'owc_yape', 'bacs' ], true );
    }

    private static function posted_payment_method() {
        return sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
    }

    public static function filter_gateways( $gateways ) {
        // Preferir la transferencia nativa cuando está disponible; evitar duplicados.
        if ( isset( $gateways['owc_bank_transfer'], $gateways['bacs'] ) ) {
            unset( $gateways['owc_bank_transfer'] );
        }

        if ( ! self::is_provider() ) return $gateways;

        foreach ( $gateways as $gateway_id => $gateway ) {
            $id = strtolower( (string) $gateway_id );
            $title = strtolower( wp_strip_all_tags( $gateway->get_title() ) );
            $is_bank_transfer = $id === 'bacs' || strpos( $title, 'transferencia' ) !== false;
            $is_yape = strpos( $id, 'yape' ) !== false || strpos( $title, 'yape' ) !== false;

            if ( ! $is_bank_transfer && ! $is_yape && 'owc_store_payment' !== $id ) {
                unset( $gateways[ $gateway_id ] );
            }
        }

        return $gateways;
    }

    public static function native_bank_icon_setting( $fields ) {
        $fields['owc_icon_url'] = [
            'title' => 'Icono de transferencia bancaria',
            'type' => 'url',
            'description' => 'Opcional: pega la URL de una imagen de Medios. Si se deja vacío, se muestra el icono bancario incluido.',
            'default' => '',
        ];
        return $fields;
    }

    public static function payment_icon( $html, $id ) {
        if ( ! self::is_manual_method( $id ) && 'owc_store_payment' !== $id ) return $html;
        if ( 'bacs' === $id ) {
            $native = get_option( 'woocommerce_bacs_settings', [] );
            if ( ! empty( $native['owc_icon_url'] ) ) return '<img class="owc-method-icon" src="' . esc_url( $native['owc_icon_url'] ) . '" alt="">';
        }
        if ( $html ) return $html;
        $options = get_option( 'woocommerce_' . ( 'bacs' === $id ? 'owc_bank_transfer' : $id ) . '_settings', [] );
        if ( ! empty( $options['icon_url'] ) ) return '<img class="owc-method-icon" src="' . esc_url( $options['icon_url'] ) . '" alt="">';
        $path = 'owc_yape' === $id
            ? '<rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 7h6M9 11h6M10 17h4"/>'
            : '<path d="M3 9l9-6 9 6H3zM5 10v8m7-8v8m7-8v8M3 21h18M3 18h18"/>';
        if ( 'owc_store_payment' === $id ) $path = '<path d="M3 10l2-7h14l2 7M3 10h18M5 10v11h14V10M9 21v-7h6v7"/><path d="M8 3l-1 7m9-7l1 7"/>';
        return '<svg class="owc-method-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="' . ( 'owc_yape' === $id ? '#742384' : '#0047ba' ) . '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    public static function payment_choice_styles() {
        if ( ! is_checkout() ) return;
        echo '<style>body.woocommerce-checkout #payment ul.payment_methods li.wc_payment_method{padding:12px!important;margin:0 0 10px!important;border:1px solid #dbe3ed;border-radius:10px;display:flex!important;flex-wrap:wrap;align-items:center;gap:10px;min-width:0;}body.woocommerce-checkout #payment ul.payment_methods li.wc_payment_method>input{margin:0!important;flex:0 0 auto;}body.woocommerce-checkout #payment ul.payment_methods li.wc_payment_method>label{display:flex!important;align-items:center;gap:10px;flex:1;margin:0!important;min-width:0;font-size:14px!important;}body.woocommerce-checkout #payment ul.payment_methods li.wc_payment_method>label img,body.woocommerce-checkout #payment .owc-method-icon{width:28px!important;height:28px!important;object-fit:contain;float:none!important;margin:0!important;}body.woocommerce-checkout #payment ul.payment_methods .payment_box{flex-basis:100%;min-width:0;}body.woocommerce-checkout #payment .payment_box.payment_method_bacs{display:none!important;}</style>';
    }

    public static function remove_native_bank_details( $order_id ) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['bacs'] ) ) remove_action( 'woocommerce_thankyou_bacs', [ $gateways['bacs'], 'thankyou_page' ] );
    }

    public static function render_order_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ( ! self::is_manual_method( $order->get_payment_method() ) && 'owc_store_payment' !== $order->get_payment_method() ) ) return;
        $key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
        $owner = get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
        if ( ! $owner && ( ! $key || ! hash_equals( $order->get_order_key(), $key ) ) ) return;
        $gateways = WC()->payment_gateways()->payment_gateways();
        $method = $order->get_payment_method();
        if ( isset( $gateways[ $method ] ) ) {
            $gateway = $gateways[ $method ];
            $instructions = 'bacs' === $method ? (string) $gateway->get_option( 'instructions', '' ) : '';
            if ( '' === trim( $instructions ) ) $instructions = (string) $gateway->get_option( 'description', '' );
            if ( '' !== trim( $instructions ) ) {
                echo '<section class="owc-order-payment-instructions" style="margin:16px 0;padding:14px;border:1px solid #dbe3ed;border-radius:8px;overflow-wrap:anywhere;"><h3 style="margin:0 0 8px;font-size:17px;">Instrucciones de pago</h3>';
                echo wpautop( wp_kses_post( $instructions ) );
                echo '</section>';
            }
        }
        if ( 'owc_store_payment' === $method ) {
            if ( isset( $gateways[ $method ] ) ) $gateways[ $method ]->render_store_details( $order );
            return; // Pago presencial: no mostrar cuentas, QR ni carga de comprobantes.
        }
        if ( 'owc_yape' === $order->get_payment_method() ) {
            if ( isset( $gateways['owc_yape'] ) ) $gateways['owc_yape']->render_payment_details();
        } else {
            $accounts = 'bacs' === $order->get_payment_method() ? get_option( 'woocommerce_bacs_accounts', [] ) : [];
            $accounts = array_filter( is_array( $accounts ) ? $accounts : [], static function( $account ) { return is_array( $account ) && ! empty( $account['account_number'] ); } );
            if ( $accounts ) {
                echo '<section style="margin:20px 0;"><h3>Cuentas bancarias</h3>';
                foreach ( $accounts as $account ) {
                    echo '<article style="padding:14px;border:1px solid #dbe3ed;border-radius:8px;margin:10px 0;overflow-wrap:anywhere;">';
                    foreach ( [ 'bank_name' => 'Banco', 'account_name' => 'Titular', 'account_number' => 'Cuenta', 'iban' => 'CCI / IBAN', 'sort_code' => 'Código bancario', 'bic' => 'BIC' ] as $field => $label ) {
                        if ( ! empty( $account[$field] ) ) echo '<p style="margin:4px 0;"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $account[$field] ) . '</p>';
                    }
                    echo '</article>';
                }
                echo '</section>';
            } elseif ( isset( $gateways['owc_bank_transfer'] ) ) {
                $gateways['owc_bank_transfer']->render_accounts();
            }
        }
        $proof_id = absint( $order->get_meta( self::PROOF_META ) );
        $review = $order->get_meta( self::REVIEW_META );
        if ( $proof_id ) echo '<p>' . ( 'approved' === $review ? 'Pago aprobado.' : ( 'rejected' === $review ? 'Comprobante rechazado. Adjunta un nuevo comprobante.' : 'Comprobante recibido. Tu pago está pendiente de verificación.' ) ) . '</p>';
        if ( $order->has_status( [ 'pending', 'on-hold' ] ) || ( $order->has_status( 'failed' ) && 'rejected' === $review ) ) self::render_proof_field( $order );
    }

    public static function render_proof_field( $order ) {
        $nonce = wp_create_nonce( 'owc_upload_payment_proof' );
        $upload_token = wp_generate_password( 32, false, false );
        ?>
        <div class="owc-payment-proof" id="owc-payment-proof">
            <h3>Comprobante de pago</h3>
            <p>Realiza el pago con los datos indicados arriba y adjunta tu comprobante. Nuestro equipo verificará el pago.</p>
            <label for="owc_payment_proof_file">Archivo del comprobante <abbr class="required" title="obligatorio">*</abbr></label>
            <input type="file" id="owc_payment_proof_file" accept="image/jpeg,image/png,application/pdf">
            <input type="hidden" name="owc_payment_proof_id" id="owc_payment_proof_id" value="">
            <input type="hidden" name="owc_payment_proof_token" id="owc_payment_proof_token" value="<?php echo esc_attr( $upload_token ); ?>">
            <div class="owc-proof-help">Formatos permitidos: JPG, PNG o PDF. Tamaño máximo: 5 MB.</div>
            <div id="owc-proof-status" aria-live="polite"></div>
        </div>
        <style>
            .owc-payment-proof { margin: 18px 0; padding: 18px; border: 1px solid #cbd5e1; border-left: 4px solid #0047ba; border-radius: 6px; background: #f8fafc; }
            .owc-payment-proof h3 { margin: 0 0 8px; font-size: 17px; }
            .owc-payment-proof p { margin: 0 0 14px; color: #475569; }
            .owc-payment-proof label { display: block; margin-bottom: 7px; font-weight: 700; }
            .owc-payment-proof input[type="file"] { display: block; width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; box-sizing: border-box; }
            .owc-proof-help, #owc-proof-status { margin-top: 7px; font-size: 12px; color: #64748b; }
            #owc-proof-status.is-loading { color: #1d4ed8; }
            #owc-proof-status.is-success { color: #047857; font-weight: 700; }
            #owc-proof-status.is-error { color: #b91c1c; font-weight: 700; }
        </style>
        <script>
        (function($) {
            $(document.body).on('change', '#owc_payment_proof_file', function() {
                const file = this.files && this.files[0];
                const status = $('#owc-proof-status');
                const proofId = $('#owc_payment_proof_id');
                proofId.val('');
                if (!file) return;

                if (file.size > 5 * 1024 * 1024) {
                    status.attr('class', 'is-error').text('El archivo supera el máximo de 5 MB.');
                    this.value = '';
                    return;
                }

                const data = new FormData();
                data.append('action', 'owc_upload_payment_proof');
                data.append('order_id', '<?php echo absint( $order->get_id() ); ?>');
                data.append('order_key', '<?php echo esc_js( $order->get_order_key() ); ?>');
                data.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                data.append('upload_token', $('#owc_payment_proof_token').val());
                data.append('proof', file);
                status.attr('class', 'is-loading').text('Subiendo comprobante...');
                $('#place_order').prop('disabled', true);

                $.ajax({
                    url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                    method: 'POST',
                    data: data,
                    processData: false,
                    contentType: false
                }).done(function(response) {
                    if (response && response.success && response.data) {
                        proofId.val(response.data.attachment_id);
                        status.attr('class', 'is-success').text('Comprobante enviado: ' + response.data.filename + '. Pendiente de verificación.');
                    } else {
                        const message = response && response.data && response.data.message ? response.data.message : 'No se pudo subir el comprobante.';
                        status.attr('class', 'is-error').text(message);
                    }
                }).fail(function() {
                    status.attr('class', 'is-error').text('Error de conexión al subir el comprobante.');
                }).always(function() {
                    $('#place_order').prop('disabled', false);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_upload_proof() {
        check_ajax_referer( 'owc_upload_payment_proof', 'nonce' );

        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        $key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) || ! self::is_manual_method( $order->get_payment_method() ) ) {
            wp_send_json_error( [ 'message' => 'No tienes acceso a este pedido.' ], 403 );
        }
        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) && ! ( $order->has_status( 'failed' ) && 'rejected' === $order->get_meta( self::REVIEW_META ) ) ) {
            wp_send_json_error( [ 'message' => 'Este pedido ya no admite comprobantes.' ], 400 );
        }
        $upload_token = sanitize_text_field( wp_unslash( $_POST['upload_token'] ?? '' ) );
        if ( strlen( $upload_token ) < 20 ) {
            wp_send_json_error( [ 'message' => 'La sesión de carga no es válida. Recarga la página.' ], 403 );
        }
        if ( empty( $_FILES['proof'] ) || ! empty( $_FILES['proof']['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Selecciona un archivo válido.' ], 400 );
        }
        if ( (int) $_FILES['proof']['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => 'El archivo supera el máximo de 5 MB.' ], 400 );
        }

        $allowed = [ 'image/jpeg', 'image/png', 'application/pdf' ];
        $checked = wp_check_filetype_and_ext( $_FILES['proof']['tmp_name'], $_FILES['proof']['name'] );
        if ( empty( $checked['type'] ) || ! in_array( $checked['type'], $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'Solo se permiten archivos JPG, PNG o PDF.' ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'proof', 0, [], [ 'test_form' => false ] );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ], 400 );
        }

        update_post_meta( $attachment_id, self::OWNER_META, get_current_user_id() );
        update_post_meta( $attachment_id, self::TOKEN_META, wp_hash( $upload_token ) );
        update_post_meta( $attachment_id, '_owc_payment_proof_pending', time() );

        $order->update_meta_data( self::PROOF_META, $attachment_id );
        $order->update_meta_data( self::REVIEW_META, 'pending' );
        $order->update_meta_data( '_owc_manual_payment_method', $order->get_payment_method() );
        $order->add_order_note( 'Cliente adjuntó comprobante de pago. Pendiente de verificación manual.' );
        $order->save();
        self::attach_proof_to_order( $order );
        if ( $order->has_status( 'failed' ) ) {
            $order->update_status( 'on-hold', 'Nuevo comprobante pendiente de revisión.' );
            wc_reduce_stock_levels( $order->get_id() );
        }
        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'filename'      => wp_basename( get_attached_file( $attachment_id ) ),
        ] );
    }

    public static function attach_proof_to_order( $order ) {
        $proof_id = absint( $order->get_meta( self::PROOF_META ) );
        if ( ! $proof_id ) return;
        wp_update_post( [ 'ID' => $proof_id, 'post_parent' => $order->get_id() ] );
        delete_post_meta( $proof_id, '_owc_payment_proof_pending' );
        delete_post_meta( $proof_id, self::TOKEN_META );
    }

    public static function render_admin_proof( $order ) {
        if ( 'owc_store_payment' === $order->get_payment_method() ) {
            self::render_admin_store_payment( $order );
            return;
        }
        $proof_id = absint( $order->get_meta( self::PROOF_META ) );
        if ( ! $proof_id ) return;
        $url = wp_get_attachment_url( $proof_id );
        if ( ! $url ) return;

        $review = $order->get_meta( self::REVIEW_META ) ?: 'pending';
        $labels = [
            'pending'  => 'Pendiente de verificación',
            'approved' => 'Pago aprobado',
            'rejected' => 'Comprobante rechazado',
        ];

        echo '<div style="margin-top:16px;padding:14px;border:1px solid #cbd5e1;background:#f8fafc;">';
        echo '<p><strong>Pago manual:</strong> ' . esc_html( $order->get_payment_method_title() ) . '</p>';
        echo '<p><strong>Estado:</strong> ' . esc_html( $labels[ $review ] ?? $review ) . '</p>';
        if ( wp_attachment_is_image( $proof_id ) ) {
            echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="Comprobante" style="display:block;max-width:320px;height:auto;border:1px solid #cbd5e1;"></a></p>';
        }
        echo '<p><a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Ver comprobante completo</a></p>';

        if ( $review === 'pending' ) {
            $base_url = admin_url( 'admin-post.php?action=owc_review_payment&order_id=' . $order->get_id() );
            $approve_url = wp_nonce_url( $base_url . '&decision=approve', 'owc_review_payment_' . $order->get_id() );
            $reject_url = wp_nonce_url( $base_url . '&decision=reject', 'owc_review_payment_' . $order->get_id() );
            echo '<p><a class="button button-primary" href="' . esc_url( $approve_url ) . '">Aprobar pago</a> ';
            echo '<a class="button" href="' . esc_url( $reject_url ) . '" onclick="return confirm(\'¿Rechazar este comprobante?\');">Rechazar comprobante</a></p>';
        }
        echo '</div>';
    }

    public static function render_admin_store_payment( $order ) {
        $approved = 'approved' === $order->get_meta( self::REVIEW_META );
        echo '<div style="margin-top:16px;padding:14px;border:1px solid #cbd5e1;background:#f8fafc;"><p><strong>Pago en tienda</strong></p>';
        echo '<p>' . ( $approved ? 'Pago confirmado en tienda.' : 'Pendiente de confirmar el cobro en tienda.' ) . '</p>';
        if ( ! $approved && current_user_can( 'manage_woocommerce' ) && $order->has_status( [ 'pending', 'on-hold', 'processing', 'completed' ] ) ) {
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=owc_confirm_store_payment&order_id=' . $order->get_id() ), 'owc_confirm_store_payment_' . $order->get_id() );
            echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Confirmar pago en tienda</a></p>';
            echo '<p style="font-size:12px;">Pulsa cuando hayas recibido el pago. Se registrará el cobro y se activará el envío del pedido a Odoo.</p>';
        }
        echo '</div>';
    }

    public static function handle_store_payment_confirmation() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        check_admin_referer( 'owc_confirm_store_payment_' . $order_id );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No tienes permiso para confirmar pagos.' );
        $order = wc_get_order( $order_id );
        if ( ! $order || 'owc_store_payment' !== $order->get_payment_method() ) wp_die( 'Pedido o método de pago no válido.' );
        if ( ! $order->has_status( [ 'pending', 'on-hold', 'processing', 'completed' ] ) ) wp_die( 'El estado del pedido no permite confirmar este pago.' );
        if ( 'approved' !== $order->get_meta( self::REVIEW_META ) ) {
            $previous_status = $order->get_status();
            $order->update_meta_data( self::REVIEW_META, 'approved' );
            $order->update_meta_data( '_owc_store_payment_confirmed_by', get_current_user_id() );
            $order->update_meta_data( '_owc_store_payment_confirmed_at', current_time( 'mysql', true ) );
            if ( ! $order->get_date_paid() ) $order->set_date_paid( time() );
            $order->add_order_note( 'Pago en tienda confirmado por ' . wp_get_current_user()->display_name . '.' );
            $order->save();
            $order->payment_complete();
            // Older orders may already be processing: no status hook will fire.
            if ( $previous_status === $order->get_status() && class_exists( 'OWC_Order_Webhook' ) ) {
                OWC_Order_Webhook::order_placed( $order_id, $order );
            }
        }
        wp_safe_redirect( $order->get_edit_order_url() );
        exit;
    }

    public static function handle_payment_review() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        $decision = sanitize_key( $_GET['decision'] ?? '' );
        check_admin_referer( 'owc_review_payment_' . $order_id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No tienes permiso para verificar pagos.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::is_manual_method( $order->get_payment_method() ) ) {
            wp_die( 'Pedido o método de pago no válido.' );
        }

        if ( $decision === 'approve' ) {
            $order->update_meta_data( self::REVIEW_META, 'approved' );
            $order->add_order_note( 'Pago aprobado manualmente por ' . wp_get_current_user()->display_name . '.' );
            $order->save();
            $order->payment_complete();
        } elseif ( $decision === 'reject' ) {
            $order->update_meta_data( self::REVIEW_META, 'rejected' );
            $order->add_order_note( 'Comprobante rechazado manualmente por ' . wp_get_current_user()->display_name . '.' );
            $order->save();
            $order->update_status( 'failed' );
            wc_increase_stock_levels( $order_id );
        }

        $redirect = wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function render_email_proof( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $sent_to_admin ) return;
        $proof_id = absint( $order->get_meta( self::PROOF_META ) );
        $url = $proof_id ? wp_get_attachment_url( $proof_id ) : '';
        if ( ! $url ) return;

        if ( $plain_text ) {
            echo "\nComprobante de pago: " . esc_url_raw( $url ) . "\n";
        } else {
            echo '<p><strong>Comprobante de pago:</strong> <a href="' . esc_url( $url ) . '">Ver archivo</a></p>';
        }
    }
}
