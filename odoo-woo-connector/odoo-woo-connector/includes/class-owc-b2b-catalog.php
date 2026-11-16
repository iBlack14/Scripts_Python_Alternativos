<?php
/**
 * Módulo B2B: Visibilidad de Catálogo, Precios para Invitados,
 * Desglose de IGV y Registro Diferenciado Cliente vs Ferretero / Distribuidor.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_B2B_Catalog {

    const ROLE_PROVIDER = 'owc_provider';
    private static $registered_provider_id = 0;

    public static function init() {
        // Registrar rol personalizado de Ferretero / Distribuidor
        add_action( 'init', [ __CLASS__, 'register_roles' ] );
        add_action( 'init', [ __CLASS__, 'handle_b2b_upgrade_request' ] );
        add_action( 'init', [ __CLASS__, 'handle_email_verification' ] );
        add_action( 'init', [ __CLASS__, 'handle_verification_resend' ] );
        add_filter( 'wp_authenticate_user', [ __CLASS__, 'require_verified_provider_email' ], 30, 2 );
        add_filter( 'woocommerce_registration_redirect', [ __CLASS__, 'provider_registration_redirect' ] );
        add_action( 'woocommerce_login_form_end', [ __CLASS__, 'render_resend_verification_form' ] );
        add_action( 'template_redirect', [ __CLASS__, 'render_verification_notice' ] );

        // 1. Control de Visibilidad de Precios y Botón de Compra
        add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'filter_role_price' ], 999, 2 );
        add_filter( 'woocommerce_product_get_regular_price', [ __CLASS__, 'filter_role_price' ], 999, 2 );
        add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_price_html' ], 999, 2 );
        add_filter( 'woocommerce_get_availability_text', [ __CLASS__, 'filter_availability_text' ], 999, 2 );
        add_filter( 'woocommerce_get_availability_class', [ __CLASS__, 'filter_availability_class' ], 999, 2 );
        add_filter( 'woocommerce_admin_stock_html', [ __CLASS__, 'filter_admin_stock_html' ], 999, 2 );
        add_action( 'template_redirect', [ __CLASS__, 'control_purchasable_actions' ] );
        add_action( 'woocommerce_after_shop_loop_item_title', [ __CLASS__, 'render_stock_badge' ], 11 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_stock_badge' ], 11 );

        // 2. Registro Diferenciado: Cliente vs Ferretero / Distribuidor
        add_action( 'woocommerce_register_form', [ __CLASS__, 'render_register_fields' ] );
        add_filter( 'woocommerce_process_registration_errors', [ __CLASS__, 'validate_register_fields' ], 10, 4 );
        add_action( 'woocommerce_created_customer', [ __CLASS__, 'save_register_fields' ], 10, 3 );

        // 2.1. Reutilizar los datos registrados en el checkout y conservarlos en el pedido
        add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'add_checkout_document_fields' ] );
        add_filter( 'woocommerce_checkout_get_value', [ __CLASS__, 'prefill_checkout_document' ], 10, 2 );
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'validate_checkout_document' ] );
        add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_checkout_document_to_order' ], 10, 2 );
        add_action( 'woocommerce_checkout_update_user_meta', [ __CLASS__, 'save_checkout_document_to_user' ], 10, 2 );
        add_action( 'wp_footer', [ __CLASS__, 'render_checkout_location_prefill' ], 40 );

        // 3. Mostrar tipo de cuenta y RUC en Mi Cuenta y en Perfil de Usuario WP Admin
        add_action( 'woocommerce_account_dashboard', [ __CLASS__, 'render_account_badge' ], 5 );
        add_action( 'show_user_profile', [ __CLASS__, 'render_admin_user_fields' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_admin_user_fields' ] );
        add_action( 'personal_options_update', [ __CLASS__, 'save_admin_user_fields' ] );
        add_action( 'edit_user_profile_update', [ __CLASS__, 'save_admin_user_fields' ] );

        // 4. Precio ferretero manual en productos WooCommerce
        add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'render_product_pricing_fields' ] );
        add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_pricing_fields' ] );

        // 5. Inyectar estilos CSS front-end profesionales
        add_action( 'wp_head', [ __CLASS__, 'render_frontend_styles' ] );
    }

    /**
     * Registra el rol de Ferretero / Distribuidor si no existe
     */
    public static function register_roles() {
        if ( ! get_role( self::ROLE_PROVIDER ) ) {
            $customer_role = get_role( 'customer' );
            $caps = $customer_role ? $customer_role->capabilities : [ 'read' => true ];
            add_role( self::ROLE_PROVIDER, 'Ferretero / Distribuidor', $caps );
        }
    }

    /**
     * Filtra el HTML del precio en catálogo y ficha de producto
     */
    public static function filter_price_html( $price_html, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price_html;
        }

        $hide_for_guest = OWC_Settings::get( 'hide_prices_guest', 'yes' ) === 'yes';

        // Caso 1: Invitado / Sin sesión iniciada
        if ( $hide_for_guest && ! is_user_logged_in() ) {
            $my_account_url = wc_get_page_permalink( 'myaccount' );
            $cta_text = OWC_Settings::get( 'guest_price_text', 'Iniciar sesión | Registrarse para ver precio' );

            return sprintf(
                '<div class="owc-guest-price-container">
                    <a href="%s" class="owc-guest-price-btn" title="Acceder para ver precios y comprar">
                        <span class="owc-guest-icon">🔒</span>
                        <span class="owc-guest-label">%s</span>
                    </a>
                </div>',
                esc_url( $my_account_url ),
                esc_html( $cta_text )
            );
        }

        // Caso 2: Usuario con sesión iniciada -> Mostrar precio con desglose de IGV si está activo
        if ( is_user_logged_in() && OWC_Settings::get( 'enable_igv_display', 'yes' ) === 'yes' && $product ) {
            $raw_price = (float) $product->get_price();
            if ( $raw_price > 0 ) {
                $igv_rate = (float) OWC_Settings::get( 'igv_rate', 18 );
                $price_with_igv = $raw_price * ( 1 + ( $igv_rate / 100 ) );
                $formatted_with_igv = wc_price( $price_with_igv );

                $current_user = wp_get_current_user();
                $is_provider = self::is_approved_provider();
                $role_badge = $is_provider ? '<span class="owc-role-tag">Precio ferretero</span>' : '';

                $igv_html = sprintf(
                    '<span class="owc-igv-info">
                        <span class="owc-price-base">%s</span>
                        <span class="owc-igv-badge">+ %s%% IGV: <strong>%s</strong></span>
                        %s
                    </span>',
                    $price_html,
                    $igv_rate,
                    $formatted_with_igv,
                    $role_badge
                );
                return $igv_html;
            }
        }

        return $price_html;
    }

    public static function is_approved_provider( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( self::ROLE_PROVIDER, (array) $user->roles, true ) ) {
            return false;
        }

        return get_user_meta( $user_id, 'owc_b2b_status', true ) === 'approved'
            && get_user_meta( $user_id, 'owc_email_verified', true ) === 'yes';
    }

    public static function filter_role_price( $price, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price;
        }

        if ( ! self::is_approved_provider() || ! $product instanceof WC_Product ) {
            return $price;
        }

        $distributor_price = get_post_meta( $product->get_id(), '_owc_distributor_price', true );
        if ( $distributor_price !== '' && (float) $distributor_price > 0 ) {
            return (string) wc_format_decimal( $distributor_price );
        }

        return $price;
    }

    public static function render_product_pricing_fields() {
        woocommerce_wp_text_input( [
            'id'                => '_owc_distributor_price',
            'label'             => 'Precio ferretero/distribuidor',
            'desc_tip'          => true,
            'description'       => 'Precio visible solo para usuarios ferreteros/distribuidores aprobados manualmente.',
            'type'              => 'text',
            'data_type'         => 'price',
            'custom_attributes' => [
                'step' => '0.01',
                'min'  => '0',
            ],
        ] );
    }

    public static function save_product_pricing_fields( $product ) {
        if ( isset( $_POST['_owc_distributor_price'] ) ) {
            $price = wc_format_decimal( wp_unslash( $_POST['_owc_distributor_price'] ) );
            $product->update_meta_data( '_owc_distributor_price', $price );
        }
    }

    public static function product_has_real_stock( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        if ( ! $product->is_in_stock() ) {
            return false;
        }

        if ( $product->managing_stock() ) {
            return (int) $product->get_stock_quantity() > 0;
        }

        return false;
    }

    public static function filter_availability_text( $availability, $product ) {
        if ( ! self::product_has_real_stock( $product ) ) {
            return 'Sin stock';
        }

        return $availability;
    }

    public static function filter_availability_class( $class, $product ) {
        if ( ! self::product_has_real_stock( $product ) ) {
            return 'out-of-stock';
        }

        return $class;
    }

    public static function filter_admin_stock_html( $stock_html, $product ) {
        if ( ! self::product_has_real_stock( $product ) ) {
            return '<mark class="outofstock">Sin stock</mark>';
        }

        return $stock_html;
    }

    /**
     * Muestra unidades disponibles en catálogo y ficha de producto.
     */
    public static function render_stock_badge() {
        global $product;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $manage_stock = $product->managing_stock();
        $quantity = $manage_stock ? $product->get_stock_quantity() : null;
        $in_stock = self::product_has_real_stock( $product );

        if ( $manage_stock && $quantity !== null ) {
            $quantity = max( 0, (int) $quantity );
            $class = $quantity > 10 ? 'is-available' : ( $quantity > 0 ? 'is-low' : 'is-empty' );
            $label = $quantity > 0
                ? sprintf( _n( '%s unidad disponible', '%s unidades disponibles', $quantity, 'odoo-woo-connector' ), number_format_i18n( $quantity ) )
                : 'Sin stock';
        } else {
            $class = $in_stock ? 'is-available' : 'is-empty';
            $label = $in_stock ? 'Stock disponible' : 'Sin stock';
        }

        printf(
            '<span class="owc-stock-badge %s"><span class="owc-stock-dot"></span>%s</span>',
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    /**
     * Oculta el botón 'Añadir al carrito' para invitados si está activo el modo B2B
     */
    public static function control_purchasable_actions() {
        $hide_for_guest = OWC_Settings::get( 'hide_prices_guest', 'yes' ) === 'yes';

        if ( $hide_for_guest && ! is_user_logged_in() ) {
            // Ocultar botón en ficha de producto individual
            remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
            
            // Ocultar botón en bucles de catálogo/tienda
            remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

            // Reemplazar por aviso en ficha individual
            add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_guest_single_notice' ], 30 );
        }
    }

    /**
     * Renderiza banner explicativo en la ficha del producto para invitados
     */
    public static function render_guest_single_notice() {
        $my_account_url = wc_get_page_permalink( 'myaccount' );
        ?>
        <div class="owc-guest-single-card">
            <div class="owc-guest-card-header">
                <span class="owc-card-icon">💼</span>
                <strong>Venta exclusiva para clientes y ferreteros/distribuidores registrados</strong>
            </div>
            <p>Para ver precios mayoristas y realizar compras en línea, por favor identifícate o crea tu cuenta empresarial.</p>
            <div class="owc-guest-card-actions">
                <a href="<?php echo esc_url( $my_account_url ); ?>" class="owc-btn-primary">🔑 Iniciar Sesión</a>
                <a href="<?php echo esc_url( $my_account_url ); ?>" class="owc-btn-secondary">📝 Registrarse</a>
            </div>
        </div>
        <?php
    }

    /**
     * Campos en el formulario de registro de WooCommerce (Cliente vs Ferretero / Distribuidor)
     */
    public static function render_register_fields() {
        if ( OWC_Settings::get( 'enable_role_registration', 'yes' ) !== 'yes' ) {
            return;
        }

        $selected_type = sanitize_text_field( $_POST['owc_account_type'] ?? 'customer' );
        $company = sanitize_text_field( $_POST['owc_company_name'] ?? '' );
        $first_name = sanitize_text_field( $_POST['owc_first_name'] ?? '' );
        $last_name = sanitize_text_field( $_POST['owc_last_name'] ?? '' );
        $document_type = sanitize_text_field( $_POST['owc_document_type'] ?? 'DNI' );
        $tax_id = sanitize_text_field( $_POST['owc_tax_id'] ?? '' );
        $phone = sanitize_text_field( $_POST['owc_phone'] ?? '' );
        $address = sanitize_text_field( $_POST['owc_address_1'] ?? '' );
        $city = sanitize_text_field( $_POST['owc_city'] ?? '' );
        $state = sanitize_text_field( $_POST['owc_state'] ?? '' );
        $peru_states = self::get_peru_departments();
        $selected_state = $state;
        foreach ( $peru_states as $state_code => $state_name ) {
            if ( strtoupper( $state ) === strtoupper( $state_code ) || strtoupper( $state ) === strtoupper( $state_name ) ) {
                $selected_state = $state_code;
                break;
            }
        }
        ?>
        <div class="owc-reg-box">
            <p class="owc-reg-title">Tipo de cuenta</p>
            <div class="owc-reg-roles">
                <label class="owc-role-option">
                    <input type="radio" name="owc_account_type" value="customer" <?php checked( $selected_type, 'customer' ); ?>>
                    <span class="owc-role-card">
                        <span class="owc-rc-icon">👤</span>
                        <span class="owc-rc-title">Cliente simple</span>
                        <span class="owc-rc-desc">Compra con precio público y acceso estándar a la tienda.</span>
                    </span>
                </label>
                <label class="owc-role-option">
                    <input type="radio" name="owc_account_type" value="<?php echo esc_attr( self::ROLE_PROVIDER ); ?>" <?php checked( $selected_type, self::ROLE_PROVIDER ); ?>>
                    <span class="owc-role-card">
                        <span class="owc-rc-icon">🏢</span>
                        <span class="owc-rc-title">Ferretero / Distribuidor</span>
                        <span class="owc-rc-desc">Cuenta empresarial con RUC para acceder a precio ferretero.</span>
                    </span>
                </label>
            </div>

            <div class="owc-customer-fields">
                <p class="form-row form-row-first" data-owc-customer-only>
                    <label for="owc_first_name">Nombres <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_first_name" id="owc_first_name" value="<?php echo esc_attr( $first_name ); ?>" autocomplete="given-name" required>
                </p>
                <p class="form-row form-row-last" data-owc-customer-only>
                    <label for="owc_last_name">Apellidos <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_last_name" id="owc_last_name" value="<?php echo esc_attr( $last_name ); ?>" autocomplete="family-name" required>
                </p>
                <p class="form-row form-row-first">
                    <label for="owc_document_type">Tipo de documento <span class="required">*</span></label>
                    <select name="owc_document_type" id="owc_document_type" required>
                        <option value="DNI" <?php selected( $document_type, 'DNI' ); ?>>DNI</option>
                        <option value="RUC" <?php selected( $document_type, 'RUC' ); ?>>RUC</option>
                    </select>
                </p>
                <p class="form-row form-row-last">
                    <label for="owc_tax_id"><span data-owc-document-label>Número de documento</span> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_tax_id" id="owc_tax_id" value="<?php echo esc_attr( $tax_id ); ?>" inputmode="numeric" maxlength="11" autocomplete="off" required>
                </p>
                <p class="form-row form-row-wide" data-owc-provider-only>
                    <label for="owc_company_name">Razón social / Empresa <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_company_name" id="owc_company_name" value="<?php echo esc_attr( $company ); ?>" autocomplete="organization" required>
                </p>
                <p class="form-row form-row-wide">
                    <label for="owc_phone">Celular de contacto <span class="required">*</span></label>
                    <input type="tel" class="input-text" name="owc_phone" id="owc_phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="numeric" maxlength="9" autocomplete="tel" placeholder="987654321" required>
                </p>
                <p class="form-row form-row-wide" data-owc-customer-only>
                    <label for="owc_state">Departamento <span class="required">*</span></label>
                    <select name="owc_state" id="owc_state" autocomplete="address-level1" required>
                        <option value="">Selecciona un departamento</option>
                        <?php foreach ( $peru_states as $state_code => $state_name ) : ?>
                            <option value="<?php echo esc_attr( $state_code ); ?>" <?php selected( $selected_state, $state_code ); ?>><?php echo esc_html( $state_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="form-row form-row-wide" data-owc-customer-only>
                    <label for="owc_city">Distrito / ciudad <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_city" id="owc_city" value="<?php echo esc_attr( $city ); ?>" autocomplete="address-level2" required>
                </p>
                <p class="form-row form-row-wide" data-owc-customer-only>
                    <label for="owc_address_1">Dirección fiscal / domicilio <span class="required">*</span></label>
                    <input type="text" class="input-text" name="owc_address_1" id="owc_address_1" value="<?php echo esc_attr( $address ); ?>" autocomplete="street-address" placeholder="Av./Jr./Calle, número y referencia" required>
                </p>
                <input type="hidden" name="owc_country" value="PE">
            </div>

            <div class="owc-b2b-fields" data-owc-b2b-fields>
                <p class="owc-b2b-note">Validaremos tus datos para activar precios ferreteros/distribuidor.</p>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var fields = document.querySelector('[data-owc-b2b-fields]');
            var radios = document.querySelectorAll('input[name="owc_account_type"]');
            var documentType = document.getElementById('owc_document_type');
            var documentInput = document.getElementById('owc_tax_id');
            var documentLabel = document.querySelector('[data-owc-document-label]');
            var customerFields = document.querySelector('.owc-customer-fields');
            var providerOnly = document.querySelector('[data-owc-provider-only]');
            var allRows = customerFields ? Array.prototype.slice.call(customerFields.querySelectorAll('.form-row')) : [];
            if (customerFields) customerFields.classList.add('owc-progressive-enabled');

            function isProviderAccount() {
                var selected = document.querySelector('input[name="owc_account_type"]:checked');
                return selected && selected.value === '<?php echo esc_js( self::ROLE_PROVIDER ); ?>';
            }

            function fieldIsComplete(row) {
                var control = row.querySelector('input, select');
                if (!control) return true;
                var value = (control.value || '').trim();
                if (control.id === 'owc_tax_id') {
                    return documentType && documentType.value === 'RUC' ? /^\d{11}$/.test(value) : /^\d{8}$/.test(value);
                }
                if (control.id === 'owc_phone') return /^9\d{8}$/.test(value.replace(/\D/g, ''));
                return value.length >= 2;
            }

            function refreshProgressiveFields() {
                var activeRows = allRows.filter(function(row) {
                    if (row.hasAttribute('data-owc-provider-only')) return isProviderAccount();
                    if (row.hasAttribute('data-owc-customer-only')) return !isProviderAccount();
                    return true;
                });
                allRows.forEach(function(row) {
                    var isActive = activeRows.indexOf(row) !== -1;
                    var control = row.querySelector('input, select');
                    if (control) control.disabled = !isActive;
                    if (!isActive) {
                        row.classList.remove('is-visible');
                        row.setAttribute('aria-hidden', 'true');
                    }
                });
                var previousComplete = true;
                var visibleCount = 0;
                activeRows.forEach(function(row, index) {
                    var show = index === 0 || previousComplete;
                    row.classList.toggle('is-visible', show);
                    row.setAttribute('aria-hidden', show ? 'false' : 'true');
                    if (show) visibleCount++;
                    previousComplete = show && fieldIsComplete(row);
                });
                if (fields) fields.classList.toggle('is-visible', isProviderAccount() && previousComplete);
                if (customerFields) customerFields.style.setProperty('--owc-step-count', visibleCount);
            }

            function refreshDocumentField() {
                var type = documentType ? documentType.value : 'DNI';
                if (documentLabel) documentLabel.textContent = 'Número de ' + type;
                if (documentInput) {
                    documentInput.maxLength = type === 'RUC' ? 11 : 8;
                    documentInput.placeholder = type === 'RUC' ? '20123456789' : '12345678';
                }
                refreshProgressiveFields();
            }
            function refreshB2BFields() {
                var isProvider = isProviderAccount();
                if (documentType) {
                    if (isProvider) documentType.value = 'RUC';
                    documentType.querySelector('option[value="DNI"]').disabled = !!isProvider;
                }
                refreshDocumentField();
            }
            radios.forEach(function(radio) {
                radio.addEventListener('change', refreshB2BFields);
            });
            if (documentType) documentType.addEventListener('change', refreshDocumentField);
            allRows.forEach(function(row) {
                var control = row.querySelector('input, select');
                if (control) {
                    control.addEventListener('input', refreshProgressiveFields);
                    control.addEventListener('change', refreshProgressiveFields);
                    control.addEventListener('blur', refreshProgressiveFields);
                }
            });
            refreshB2BFields();
        });
        </script>
        <?php
    }

    /**
     * Valida campos obligatorios si elige Ferretero / Distribuidor
     */
    public static function validate_register_fields( $errors, $username, $password, $email ) {
        if ( OWC_Settings::get( 'enable_role_registration', 'yes' ) !== 'yes' ) {
            return $errors;
        }

        $type = sanitize_text_field( $_POST['owc_account_type'] ?? 'customer' );
        $required = $type === self::ROLE_PROVIDER ? [
            'owc_company_name' => 'Ingresa la razón social o nombre de empresa.',
            'owc_tax_id'     => 'Ingresa tu número de documento.',
            'owc_phone'      => 'Ingresa tu celular.',
        ] : [
            'owc_first_name' => 'Ingresa tus nombres.',
            'owc_last_name'  => 'Ingresa tus apellidos.',
            'owc_tax_id'     => 'Ingresa tu número de documento.',
            'owc_phone'      => 'Ingresa tu celular.',
            'owc_address_1'  => 'Ingresa tu dirección.',
            'owc_city'       => 'Ingresa tu distrito o ciudad.',
            'owc_state'      => 'Ingresa tu departamento.',
        ];
        foreach ( $required as $field => $message ) {
            if ( empty( trim( sanitize_text_field( $_POST[ $field ] ?? '' ) ) ) ) {
                $errors->add( 'owc_required_' . $field, '<strong>Error:</strong> ' . $message );
            }
        }

        $posted_state = sanitize_text_field( $_POST['owc_state'] ?? '' );
        $peru_states = self::get_peru_departments();
        if ( $type !== self::ROLE_PROVIDER && $posted_state && ! array_key_exists( $posted_state, $peru_states ) ) {
            $errors->add( 'owc_error_state', '<strong>Error:</strong> Selecciona un departamento válido del Perú.' );
        }

        $document_type = strtoupper( sanitize_text_field( $_POST['owc_document_type'] ?? '' ) );
        $document = preg_replace( '/\D+/', '', sanitize_text_field( $_POST['owc_tax_id'] ?? '' ) );
        if ( ! in_array( $document_type, [ 'DNI', 'RUC' ], true ) ) {
            $errors->add( 'owc_error_document_type', '<strong>Error:</strong> Selecciona DNI o RUC.' );
        } elseif ( $document_type === 'DNI' && ! preg_match( '/^\d{8}$/', $document ) ) {
            $errors->add( 'owc_error_dni_format', '<strong>Error:</strong> El DNI debe tener 8 dígitos.' );
        } elseif ( $document_type === 'RUC' && ! preg_match( '/^\d{11}$/', $document ) ) {
            $errors->add( 'owc_error_ruc_format', '<strong>Error:</strong> El RUC debe tener 11 dígitos.' );
        }
        $phone = preg_replace( '/\D+/', '', sanitize_text_field( $_POST['owc_phone'] ?? '' ) );
        if ( ! preg_match( '/^9\d{8}$/', $phone ) ) {
            $errors->add( 'owc_error_phone', '<strong>Error:</strong> Ingresa un celular peruano válido de 9 dígitos.' );
        }

        if ( $type === self::ROLE_PROVIDER ) {
            if ( $document_type !== 'RUC' ) {
                $errors->add( 'owc_provider_ruc', '<strong>Error:</strong> La cuenta Ferretero / Distribuidor requiere RUC.' );
            }
        }

        return $errors;
    }

    /**
     * Asigna el rol y guarda metadatos al crear el usuario
     */
    public static function save_register_fields( $customer_id, $new_customer_data, $password_generated ) {
        $type = sanitize_text_field( $_POST['owc_account_type'] ?? 'customer' );

        if ( $type === self::ROLE_PROVIDER ) {
            $user = new WP_User( $customer_id );
            $user->set_role( self::ROLE_PROVIDER );
            update_user_meta( $customer_id, 'owc_b2b_status', 'pending' );
            update_user_meta( $customer_id, 'owc_email_verified', 'no' );
            self::$registered_provider_id = (int) $customer_id;
            self::send_verification_email( $customer_id, true );
        } else {
            update_user_meta( $customer_id, 'owc_b2b_status', 'customer' );
            update_user_meta( $customer_id, 'owc_email_verified', 'yes' );
        }

        if ( ! empty( $_POST['owc_company_name'] ) ) {
            $company = sanitize_text_field( $_POST['owc_company_name'] );
            update_user_meta( $customer_id, 'billing_company', $company );
            update_user_meta( $customer_id, 'owc_company_name', $company );
        }

        if ( ! empty( $_POST['owc_tax_id'] ) ) {
            $tax_id = sanitize_text_field( $_POST['owc_tax_id'] );
            update_user_meta( $customer_id, 'billing_tax_id', $tax_id );
            update_user_meta( $customer_id, 'owc_tax_id', $tax_id );
        }
        if ( ! empty( $_POST['owc_phone'] ) ) {
            $phone = preg_replace( '/\D+/', '', sanitize_text_field( $_POST['owc_phone'] ) );
            update_user_meta( $customer_id, 'billing_phone', $phone );
            update_user_meta( $customer_id, 'owc_phone', $phone );
        }

        $meta_fields = [
            'owc_first_name'   => [ 'first_name', 'billing_first_name' ],
            'owc_last_name'    => [ 'last_name', 'billing_last_name' ],
            'owc_address_1'    => [ 'billing_address_1' ],
            'owc_city'         => [ 'billing_city', 'billing_distrito' ],
            'owc_state'        => [ 'billing_state', 'billing_departamento' ],
            'owc_country'      => [ 'billing_country' ],
            'owc_document_type'=> [ 'owc_document_type' ],
        ];
        foreach ( $meta_fields as $posted => $keys ) {
            $value = sanitize_text_field( $_POST[ $posted ] ?? '' );
            foreach ( $keys as $key ) update_user_meta( $customer_id, $key, $value );
        }
    }

    /**
     * Muestra en checkout el tipo y número de documento guardados al registrarse.
     */
    public static function add_checkout_document_fields( $fields ) {
        $fields['billing']['billing_document_type'] = [
            'type'     => 'select',
            'label'    => 'Tipo de documento',
            'required' => true,
            'class'    => [ 'form-row-first' ],
            'priority' => 32,
            'options'  => [ 'DNI' => 'DNI', 'RUC' => 'RUC' ],
        ];
        $fields['billing']['billing_tax_id'] = [
            'type'              => 'text',
            'label'             => 'Número de documento',
            'required'          => true,
            'class'             => [ 'form-row-last' ],
            'priority'          => 33,
            'custom_attributes' => [ 'inputmode' => 'numeric', 'maxlength' => '11' ],
        ];
        return $fields;
    }

    public static function prefill_checkout_document( $value, $input ) {
        if ( ! is_user_logged_in() || $value !== null && $value !== '' ) return $value;
        $user_id = get_current_user_id();
        if ( in_array( $input, [ 'billing_departamento', 'billing_provincia', 'billing_distrito' ], true ) ) {
            $stored = get_user_meta( $user_id, $input, true );
            if ( $stored ) return $stored;
            if ( $input === 'billing_departamento' ) return get_user_meta( $user_id, 'billing_state', true );
            if ( $input === 'billing_distrito' ) return get_user_meta( $user_id, 'billing_city', true );
        }
        if ( $input === 'billing_tax_id' ) {
            return get_user_meta( $user_id, 'billing_tax_id', true ) ?: get_user_meta( $user_id, 'owc_tax_id', true );
        }
        if ( $input === 'billing_document_type' ) {
            $type = strtoupper( sanitize_text_field( get_user_meta( $user_id, 'owc_document_type', true ) ) );
            $tax_id = get_user_meta( $user_id, 'billing_tax_id', true ) ?: get_user_meta( $user_id, 'owc_tax_id', true );
            $digits = preg_replace( '/\D+/', '', (string) $tax_id );
            if ( strlen( $digits ) === 8 ) return 'DNI';
            if ( strlen( $digits ) === 11 ) return 'RUC';
            return in_array( $type, [ 'DNI', 'RUC' ], true ) ? $type : 'DNI';
        }
        return $value;
    }

    public static function validate_checkout_document() {
        $type = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_document_type'] ?? '' ) ) );
        $document = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['billing_tax_id'] ?? '' ) ) );
        if ( ! in_array( $type, [ 'DNI', 'RUC' ], true ) ) {
            wc_add_notice( 'Selecciona DNI o RUC.', 'error' );
        } elseif ( $type === 'DNI' && ! preg_match( '/^\d{8}$/', $document ) ) {
            wc_add_notice( 'El DNI debe tener 8 dígitos.', 'error' );
        } elseif ( $type === 'RUC' && ! preg_match( '/^\d{11}$/', $document ) ) {
            wc_add_notice( 'El RUC debe tener 11 dígitos.', 'error' );
        }
    }

    public static function save_checkout_document_to_order( $order, $data ) {
        $type = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_document_type'] ?? '' ) ) );
        $document = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['billing_tax_id'] ?? '' ) ) );
        if ( in_array( $type, [ 'DNI', 'RUC' ], true ) ) $order->update_meta_data( '_billing_document_type', $type );
        if ( $document ) $order->update_meta_data( '_billing_tax_id', $document );
    }

    public static function save_checkout_document_to_user( $customer_id, $posted ) {
        $type = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_document_type'] ?? '' ) ) );
        $document = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['billing_tax_id'] ?? '' ) ) );
        if ( in_array( $type, [ 'DNI', 'RUC' ], true ) ) update_user_meta( $customer_id, 'owc_document_type', $type );
        if ( $document ) {
            update_user_meta( $customer_id, 'billing_tax_id', $document );
            update_user_meta( $customer_id, 'owc_tax_id', $document );
        }
        foreach ( [ 'departamento', 'provincia', 'distrito' ] as $location_key ) {
            $posted_key = 'billing_' . $location_key;
            if ( ! empty( $_POST[ $posted_key ] ) ) {
                $location_value = sanitize_text_field( wp_unslash( $_POST[ $posted_key ] ) );
                update_user_meta( $customer_id, $posted_key, $location_value );
            }
        }
    }

    /**
     * Selecciona por valor o texto los ubigeos guardados. Esto permite trabajar
     * con localizaciones que usan códigos internos distintos a los nombres.
     */
    public static function render_checkout_location_prefill() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! is_user_logged_in() ) return;
        $user_id = get_current_user_id();
        $department = get_user_meta( $user_id, 'billing_departamento', true ) ?: get_user_meta( $user_id, 'billing_state', true );
        $province = get_user_meta( $user_id, 'billing_provincia', true );
        $district = get_user_meta( $user_id, 'billing_distrito', true ) ?: get_user_meta( $user_id, 'billing_city', true );
        // Para registros antiguos solo existía Distrito/ciudad. Se usa como
        // provincia únicamente si coincide con una opción disponible.
        if ( ! $province ) $province = get_user_meta( $user_id, 'billing_city', true );
        ?>
        <script>
        (function() {
            var saved = <?php echo wp_json_encode( [ 'department' => $department, 'province' => $province, 'district' => $district ] ); ?>;
            function normalize(value) {
                return (value || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
            }
            function selectMatching(selector, wanted) {
                var field = document.querySelector(selector);
                if (!field || !wanted || field.value) return !!(field && field.value);
                var target = normalize(wanted);
                var option = Array.prototype.find.call(field.options || [], function(item) {
                    return normalize(item.value) === target || normalize(item.textContent) === target;
                });
                if (!option) return false;
                field.value = option.value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
                if (window.jQuery) window.jQuery(field).trigger('change');
                return true;
            }
            var attempts = 0;
            function restoreLocation() {
                attempts++;
                selectMatching('#billing_departamento', saved.department);
                selectMatching('#billing_provincia', saved.province);
                selectMatching('#billing_distrito', saved.district);
                if (attempts < 20) window.setTimeout(restoreLocation, 250);
            }
            document.addEventListener('DOMContentLoaded', restoreLocation);
            if (document.readyState !== 'loading') restoreLocation();
            if (window.jQuery) window.jQuery(document.body).on('updated_checkout country_to_state_changed', restoreLocation);
        }());
        </script>
        <?php
    }

    private static function get_peru_departments() {
        $names = [
            'Amazonas', 'Áncash', 'Apurímac', 'Arequipa', 'Ayacucho', 'Cajamarca', 'Callao',
            'Cusco', 'Huancavelica', 'Huánuco', 'Ica', 'Junín', 'La Libertad', 'Lambayeque',
            'Lima', 'Loreto', 'Madre de Dios', 'Moquegua', 'Pasco', 'Piura', 'Puno',
            'San Martín', 'Tacna', 'Tumbes', 'Ucayali',
        ];
        return array_combine( $names, $names );
    }

    public static function handle_b2b_upgrade_request() {
        if ( ! is_user_logged_in() || empty( $_POST['owc_b2b_upgrade_submit'] ) ) {
            return;
        }

        if ( empty( $_POST['owc_b2b_upgrade_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['owc_b2b_upgrade_nonce'] ) ), 'owc_b2b_upgrade' ) ) {
            wc_add_notice( 'No se pudo validar la solicitud. Inténtalo nuevamente.', 'error' );
            return;
        }

        $company = sanitize_text_field( wp_unslash( $_POST['owc_company_name'] ?? '' ) );
        $tax_id = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['owc_tax_id'] ?? '' ) ) );
        $phone = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['owc_phone'] ?? '' ) ) );

        if ( $company === '' ) {
            wc_add_notice( 'Ingresa la razón social o nombre de empresa.', 'error' );
            return;
        }

        if ( ! preg_match( '/^\d{11}$/', $tax_id ) ) {
            wc_add_notice( 'El RUC debe tener 11 dígitos.', 'error' );
            return;
        }
        if ( ! preg_match( '/^9\d{8}$/', $phone ) ) {
            wc_add_notice( 'Ingresa un celular peruano válido de 9 dígitos.', 'error' );
            return;
        }

        $user_id = get_current_user_id();
        $user = new WP_User( $user_id );
        $user->set_role( self::ROLE_PROVIDER );

        update_user_meta( $user_id, 'billing_company', $company );
        update_user_meta( $user_id, 'owc_company_name', $company );
        update_user_meta( $user_id, 'billing_tax_id', $tax_id );
        update_user_meta( $user_id, 'owc_tax_id', $tax_id );
        update_user_meta( $user_id, 'billing_phone', $phone );
        update_user_meta( $user_id, 'owc_phone', $phone );
        update_user_meta( $user_id, 'owc_b2b_status', 'pending' );
        update_user_meta( $user_id, 'owc_email_verified', 'no' );
        self::send_verification_email( $user_id, true );
        self::notify_admin_new_b2b_request( $user_id, $company, $tax_id, $phone );

        wp_logout();
        wp_safe_redirect( add_query_arg( 'owc_verify_required', '1', wc_get_page_permalink( 'myaccount' ) ) );
        exit;
    }

    private static function notify_admin_new_b2b_request( $user_id, $company, $tax_id, $phone ) {
        $admin_email = get_option( 'admin_email' );
        $user        = get_userdata( $user_id );
        if ( ! $user ) return;

        $approve_url = admin_url( 'admin.php?page=owc-odoo-connector-b2b' );
        $subject     = '🔔 Nueva solicitud de acceso Ferretero/Distribuidor — ' . get_bloginfo( 'name' );
        $message     = "Hola,\n\n";
        $message    .= "Has recibido una nueva solicitud de acceso a precios ferretero/distribuidor en " . get_bloginfo( 'name' ) . ".\n\n";
        $message    .= "─── DATOS DEL SOLICITANTE ───────────────────\n";
        $message    .= "Nombre    : " . $user->display_name . "\n";
        $message    .= "Email     : " . $user->user_email . "\n";
        $message    .= "Empresa   : " . $company . "\n";
        $message    .= "RUC       : " . $tax_id . "\n";
        $message    .= "Teléfono  : " . $phone . "\n";
        $message    .= "────────────────────────────────────────────\n\n";
        $message    .= "👉 Revisa y aprueba/rechaza la solicitud aquí:\n";
        $message    .= $approve_url . "\n\n";
        $message    .= "— Sistema Odoo WooCommerce Connector\n";

        wp_mail( $admin_email, $subject, $message );
    }

    private static function send_verification_email( $user_id, $force = false ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) return false;

        $expires = (int) get_user_meta( $user_id, 'owc_email_verify_expires', true );
        if ( ! $force && $expires > time() ) return true;

        $token = wp_generate_password( 48, false, false );
        update_user_meta( $user_id, 'owc_email_verify_token', hash( 'sha256', $token ) );
        update_user_meta( $user_id, 'owc_email_verify_expires', time() + ( 2 * DAY_IN_SECONDS ) );

        $url = add_query_arg( [
            'owc_verify_email' => $user_id,
            'token'            => $token,
        ], home_url( '/' ) );

        $subject = 'Confirma tu correo para solicitar acceso Ferretero / Distribuidor';
        $message = "Hola {$user->display_name},\n\nConfirma tu correo para continuar con la solicitud de acceso a precios ferreteros/distribuidor:\n\n{$url}\n\nEl enlace vence en 48 horas. Después de confirmar, la empresa revisará tu RUC antes de habilitar los precios B2B.";
        return wp_mail( $user->user_email, $subject, $message );
    }

    public static function handle_email_verification() {
        if ( empty( $_GET['owc_verify_email'] ) || empty( $_GET['token'] ) ) return;

        $user_id = absint( $_GET['owc_verify_email'] );
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        $stored = (string) get_user_meta( $user_id, 'owc_email_verify_token', true );
        $expires = (int) get_user_meta( $user_id, 'owc_email_verify_expires', true );
        $valid = $stored !== '' && $expires >= time() && hash_equals( $stored, hash( 'sha256', $token ) );

        if ( $valid ) {
            update_user_meta( $user_id, 'owc_email_verified', 'yes' );
            delete_user_meta( $user_id, 'owc_email_verify_token' );
            delete_user_meta( $user_id, 'owc_email_verify_expires' );
            wp_safe_redirect( add_query_arg( 'owc_email_verified', '1', wc_get_page_permalink( 'myaccount' ) ) );
        } else {
            wp_safe_redirect( add_query_arg( 'owc_email_verified', '0', wc_get_page_permalink( 'myaccount' ) ) );
        }
        exit;
    }

    public static function require_verified_provider_email( $user, $password ) {
        if ( is_wp_error( $user ) || ! in_array( self::ROLE_PROVIDER, (array) $user->roles, true ) ) return $user;
        if ( get_user_meta( $user->ID, 'owc_email_verified', true ) === 'yes' ) return $user;

        self::send_verification_email( $user->ID );
        return new WP_Error( 'owc_email_not_verified', 'Debes confirmar tu correo antes de iniciar sesión como Ferretero / Distribuidor. Revisa tu bandeja de entrada.' );
    }

    public static function provider_registration_redirect( $redirect ) {
        $user_id = self::$registered_provider_id ?: get_current_user_id();
        $user = $user_id ? get_userdata( $user_id ) : false;
        if ( $user && in_array( self::ROLE_PROVIDER, (array) $user->roles, true ) && get_user_meta( $user->ID, 'owc_email_verified', true ) !== 'yes' ) {
            wp_logout();
            return add_query_arg( 'owc_verify_required', '1', wc_get_page_permalink( 'myaccount' ) );
        }
        return $redirect;
    }

    public static function render_verification_notice() {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        if ( isset( $_GET['owc_email_verified'] ) ) {
            $verified = sanitize_key( $_GET['owc_email_verified'] ) === '1';
            wc_add_notice( $verified ? 'Correo confirmado correctamente. Ya puedes iniciar sesión; tu acceso B2B seguirá pendiente de aprobación.' : 'El enlace de confirmación no es válido o venció. Solicita uno nuevo.', $verified ? 'success' : 'error' );
        } elseif ( isset( $_GET['owc_verify_required'] ) ) {
            wc_add_notice( 'Te enviamos un enlace de confirmación. Debes confirmar tu correo antes de iniciar sesión como Ferretero / Distribuidor.', 'notice' );
        } elseif ( isset( $_GET['owc_verification_sent'] ) ) {
            wc_add_notice( 'Si el correo corresponde a una cuenta Ferretero / Distribuidor pendiente, enviamos un nuevo enlace de confirmación.', 'success' );
        }
    }

    public static function render_resend_verification_form() {
        ?>
        <div class="owc-resend-verification">
            <p><strong>¿No recibiste la confirmación para tu cuenta B2B?</strong></p>
            <form method="post">
                <?php wp_nonce_field( 'owc_resend_verification', 'owc_resend_nonce' ); ?>
                <input type="email" name="owc_resend_email" placeholder="Correo registrado" required>
                <button type="submit" name="owc_resend_submit" value="1" class="button">Reenviar confirmación</button>
            </form>
        </div>
        <?php
    }

    public static function handle_verification_resend() {
        if ( empty( $_POST['owc_resend_submit'] ) ) return;
        if ( empty( $_POST['owc_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['owc_resend_nonce'] ) ), 'owc_resend_verification' ) ) return;

        $email = sanitize_email( wp_unslash( $_POST['owc_resend_email'] ?? '' ) );
        $user = $email ? get_user_by( 'email', $email ) : false;
        if ( $user && in_array( self::ROLE_PROVIDER, (array) $user->roles, true ) && get_user_meta( $user->ID, 'owc_email_verified', true ) !== 'yes' ) {
            self::send_verification_email( $user->ID, true );
        }

        wp_safe_redirect( add_query_arg( 'owc_verification_sent', '1', wc_get_page_permalink( 'myaccount' ) ) );
        exit;
    }

    /**
     * Muestra distintivo en el panel "Mi Cuenta"
     */
    public static function render_account_badge() {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) return;

        $is_provider = in_array( self::ROLE_PROVIDER, (array) $user->roles, true );
        $company = get_user_meta( $user->ID, 'billing_company', true ) ?: get_user_meta( $user->ID, 'owc_company_name', true );
        $tax_id  = get_user_meta( $user->ID, 'billing_tax_id', true ) ?: get_user_meta( $user->ID, 'owc_tax_id', true );
        $document_type = strtoupper( sanitize_text_field( get_user_meta( $user->ID, 'owc_document_type', true ) ) );
        $document_digits = preg_replace( '/\D+/', '', (string) $tax_id );
        if ( strlen( $document_digits ) === 8 ) $document_type = 'DNI';
        elseif ( strlen( $document_digits ) === 11 ) $document_type = 'RUC';
        elseif ( ! in_array( $document_type, [ 'DNI', 'RUC' ], true ) ) $document_type = 'Documento';
        $phone = get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, 'owc_phone', true );
        $b2b_status = get_user_meta( $user->ID, 'owc_b2b_status', true );
        $email_verified = get_user_meta( $user->ID, 'owc_email_verified', true ) === 'yes';

        ?>
        <div class="owc-myaccount-status-card <?php echo $is_provider ? 'is-provider' : 'is-customer'; ?>">
            <div class="owc-mas-header">
                <span class="owc-mas-icon"><?php echo $is_provider ? '🏢' : '👤'; ?></span>
                <div>
                    <strong style="font-size:16px;display:block;">Tipo de Cuenta: <?php echo $is_provider ? 'Ferretero / Distribuidor' : 'Cliente Comprador'; ?></strong>
                    <?php if ( $company ) : ?>
                        <span style="color:#64748b;font-size:13px;">Empresa: <strong><?php echo esc_html( $company ); ?></strong> <?php echo $tax_id ? ' | ' . esc_html( $document_type ) . ': ' . esc_html( $tax_id ) : ''; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="owc-mas-desc">
                <?php if ( $is_provider ) : ?>
                    <?php if ( ! $email_verified ) : ?>
                        <span>Confirma tu correo para continuar. Sin confirmación no se habilitarán precios ferreteros/distribuidor.</span>
                    <?php elseif ( $b2b_status === 'pending' ) : ?>
                        <span>Tu solicitud ferretero/distribuidor está registrada y pendiente de validación.</span>
                    <?php elseif ( $b2b_status === 'rejected' ) : ?>
                        <span>Tu solicitud ferretero/distribuidor fue observada. Comunícate con ventas para actualizar tus datos.</span>
                    <?php else : ?>
                        <span>✔ Tienes acceso a precios ferreteros/distribuidor y catálogo completo.</span>
                    <?php endif; ?>
                <?php else : ?>
                    <span>✔ Tu cuenta tiene acceso activo al catálogo y precios de tienda.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ( ! $is_provider ) : ?>
            <div class="owc-b2b-upgrade-card">
                <div class="owc-b2b-upgrade-head">
                    <span class="owc-b2b-upgrade-kicker">Cuenta empresarial</span>
                    <strong>¿Eres ferretero o distribuidor?</strong>
                    <span>Solicita acceso a precio ferretero. Revisaremos tu RUC manualmente.</span>
                </div>
                <div class="owc-b2b-upgrade-benefits">
                    <span>Precio ferretero</span>
                    <span>Validación por RUC</span>
                    <span>Acceso aprobado por ventas</span>
                </div>
                <form method="post" class="owc-b2b-upgrade-form">
                    <?php wp_nonce_field( 'owc_b2b_upgrade', 'owc_b2b_upgrade_nonce' ); ?>
                    <p class="form-row">
                        <label for="owc_upgrade_company_name">Razón social / Empresa <span class="required">*</span></label>
                        <input type="text" class="input-text" name="owc_company_name" id="owc_upgrade_company_name" value="<?php echo esc_attr( $company ); ?>" autocomplete="organization">
                    </p>
                    <p class="form-row">
                        <label for="owc_upgrade_tax_id">RUC <span class="required">*</span></label>
                        <input type="text" class="input-text" name="owc_tax_id" id="owc_upgrade_tax_id" value="<?php echo esc_attr( $tax_id ); ?>" inputmode="numeric" maxlength="11" autocomplete="off">
                    </p>
                    <p class="form-row">
                        <label for="owc_upgrade_phone">Celular de contacto <span class="required">*</span></label>
                        <input type="tel" class="input-text" name="owc_phone" id="owc_upgrade_phone" value="<?php echo esc_attr( $phone ); ?>" inputmode="numeric" maxlength="9" autocomplete="tel" placeholder="987654321">
                    </p>
                    <button type="submit" name="owc_b2b_upgrade_submit" value="1" class="button owc-b2b-upgrade-btn">Solicitar acceso</button>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Campos en el perfil de usuario de WP Admin
     */
    public static function render_admin_user_fields( $user ) {
        $is_provider = in_array( self::ROLE_PROVIDER, (array) $user->roles, true );
        $company = get_user_meta( $user->ID, 'owc_company_name', true ) ?: get_user_meta( $user->ID, 'billing_company', true );
        $tax_id  = get_user_meta( $user->ID, 'owc_tax_id', true ) ?: get_user_meta( $user->ID, 'billing_tax_id', true );
        $phone = get_user_meta( $user->ID, 'owc_phone', true ) ?: get_user_meta( $user->ID, 'billing_phone', true );
        $b2b_status = get_user_meta( $user->ID, 'owc_b2b_status', true ) ?: ( $is_provider ? 'pending' : 'customer' );
        ?>
        <h3>🏢 Odoo Connector — Datos Ferretero / Distribuidor</h3>
        <table class="form-table">
            <tr>
                <th><label for="owc_b2b_status">Estado B2B</label></th>
                <td>
                    <select name="owc_b2b_status" id="owc_b2b_status">
                        <option value="customer" <?php selected( $b2b_status, 'customer' ); ?>>Cliente simple</option>
                        <option value="pending" <?php selected( $b2b_status, 'pending' ); ?>>Ferretero pendiente</option>
                        <option value="approved" <?php selected( $b2b_status, 'approved' ); ?>>Ferretero aprobado</option>
                        <option value="rejected" <?php selected( $b2b_status, 'rejected' ); ?>>Solicitud rechazada</option>
                    </select><br>
                    <span class="description">Usa “Ferretero aprobado” cuando el RUC ya fue validado.</span>
                </td>
            </tr>
            <tr>
                <th><label for="owc_company_name">Razón Social / Empresa</label></th>
                <td>
                    <input type="text" name="owc_company_name" id="owc_company_name" value="<?php echo esc_attr( $company ); ?>" class="regular-text"><br>
                    <span class="description">Nombre de la empresa o negocio del cliente/proveedor.</span>
                </td>
            </tr>
            <tr>
                <th><label for="owc_tax_id">RUC / Identificación Fiscal</label></th>
                <td>
                    <input type="text" name="owc_tax_id" id="owc_tax_id" value="<?php echo esc_attr( $tax_id ); ?>" class="regular-text"><br>
                    <span class="description">Número de RUC o documento tributario registrado.</span>
                </td>
            </tr>
            <tr>
                <th><label for="owc_phone">Celular de contacto</label></th>
                <td>
                    <input type="tel" name="owc_phone" id="owc_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text">
                    <p class="description">Número para llamar o contactar por WhatsApp antes de aprobar la solicitud.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_admin_user_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;

        if ( isset( $_POST['owc_company_name'] ) ) {
            update_user_meta( $user_id, 'owc_company_name', sanitize_text_field( $_POST['owc_company_name'] ) );
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( $_POST['owc_company_name'] ) );
        }
        if ( isset( $_POST['owc_tax_id'] ) ) {
            update_user_meta( $user_id, 'owc_tax_id', sanitize_text_field( $_POST['owc_tax_id'] ) );
            update_user_meta( $user_id, 'billing_tax_id', sanitize_text_field( $_POST['owc_tax_id'] ) );
        }
        if ( isset( $_POST['owc_phone'] ) ) {
            $phone = preg_replace( '/\D+/', '', sanitize_text_field( $_POST['owc_phone'] ) );
            update_user_meta( $user_id, 'owc_phone', $phone );
            update_user_meta( $user_id, 'billing_phone', $phone );
        }
        if ( isset( $_POST['owc_b2b_status'] ) ) {
            $status = sanitize_text_field( $_POST['owc_b2b_status'] );
            if ( $status === 'approved' && get_user_meta( $user_id, 'owc_email_verified', true ) !== 'yes' ) {
                $status = 'pending';
            }
            update_user_meta( $user_id, 'owc_b2b_status', $status );

            $user = new WP_User( $user_id );
            if ( $status === 'approved' || $status === 'pending' ) {
                $user->set_role( self::ROLE_PROVIDER );
            } elseif ( $status === 'customer' ) {
                $user->set_role( 'customer' );
            }
        }
    }

    /**
     * Inyecta estilos CSS modernos en el Front-End para el botón de invitados y desgloses
     */
    public static function render_frontend_styles() {
        ?>
        <style id="owc-b2b-styles">
            /* Botón de Precio para Invitados */
            .owc-guest-price-container {
                display: inline-block;
                margin: 8px 0 12px 0;
            }
            .owc-guest-price-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #ffffff;
                color: #0047ba !important;
                border: 2px solid #0047ba;
                padding: 9px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                text-decoration: none !important;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(0, 71, 186, 0.10);
                letter-spacing: 0.2px;
            }
            .owc-guest-price-btn:hover {
                background: linear-gradient(135deg, #0047ba 0%, #002e6b 100%);
                color: #ffffff !important;
                border-color: #0047ba;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0, 71, 186, 0.25);
            }
            .owc-guest-icon {
                font-size: 14px;
            }

            /* Desglose de IGV */
            .owc-igv-info {
                display: flex;
                flex-direction: column;
                gap: 3px;
                margin: 4px 0 8px 0;
            }
            .owc-igv-badge {
                display: inline-flex;
                align-items: center;
                background: #f1f5f9;
                color: #0f766e;
                font-size: 12px;
                padding: 3px 8px;
                border-radius: 6px;
                border: 1px solid #ccfbf1;
                font-weight: 500;
                width: fit-content;
            }
            .owc-role-tag {
                display: inline-block;
                background: #fef3c7;
                color: #92400e;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 4px;
                margin-top: 2px;
                width: fit-content;
            }
            .owc-stock-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                width: fit-content;
                margin: 7px auto 10px;
                padding: 5px 10px;
                border-radius: 999px;
                background: linear-gradient(135deg, #eef6ff 0%, #f8fbff 100%);
                border: 1px solid #cfe0fb;
                color: #003f9f;
                font-size: 11.5px;
                font-weight: 800;
                line-height: 1.2;
                letter-spacing: -0.01em;
                box-shadow: 0 5px 14px rgba(0, 71, 186, 0.08);
            }
            .owc-stock-badge .owc-stock-dot {
                width: 7px;
                height: 7px;
                border-radius: 50%;
                background: #0047ba;
                box-shadow: 0 0 0 3px rgba(0, 71, 186, 0.12);
                flex: 0 0 auto;
            }
            .owc-stock-badge.is-low {
                background: linear-gradient(135deg, #fff4ec 0%, #fffaf6 100%);
                border-color: #ffd7bd;
                color: #bf4b08;
            }
            .owc-stock-badge.is-low .owc-stock-dot {
                background: #f37021;
                box-shadow: 0 0 0 3px rgba(243, 112, 33, 0.13);
            }
            .owc-stock-badge.is-empty {
                background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
                border-color: #d8e0ea;
                color: #6b7280;
            }
            .owc-stock-badge.is-empty .owc-stock-dot {
                background: #94a3b8;
                box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.16);
            }
            .single-product .owc-stock-badge {
                margin: 8px 0 14px;
            }
            .woocommerce ul.products li.product .button,
            .woocommerce-page ul.products li.product .button {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                width: 100% !important;
                min-height: 40px !important;
                padding: 10px 14px !important;
                background: linear-gradient(135deg, #f37021 0%, #d95b0e 100%) !important;
                color: #ffffff !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: 0 8px 18px rgba(243, 112, 33, 0.20) !important;
                font-size: 13px !important;
                font-weight: 900 !important;
                letter-spacing: -0.01em !important;
                line-height: 1.15 !important;
                text-align: center !important;
                text-transform: capitalize !important;
                transition: background 0.2s ease, box-shadow 0.2s ease !important;
            }
            .woocommerce ul.products li.product .button:hover,
            .woocommerce-page ul.products li.product .button:hover {
                background: linear-gradient(135deg, #0047ba 0%, #002e6b 100%) !important;
                color: #ffffff !important;
                box-shadow: 0 10px 22px rgba(0, 71, 186, 0.22) !important;
            }

            /* Tarjeta Exclusiva en Ficha de Producto */
            .owc-guest-single-card {
                background: #f0f4ff;
                border: 1px solid #b8d0f7;
                border-left: 4px solid #0047ba;
                padding: 16px 20px;
                border-radius: 10px;
                margin: 18px 0;
            }
            .owc-guest-card-header {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
                color: #0f172a;
                margin-bottom: 6px;
            }
            .owc-guest-single-card p {
                font-size: 13px;
                color: #475569;
                margin: 0 0 14px 0;
                line-height: 1.5;
            }
            .owc-guest-card-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .owc-guest-card-actions .owc-btn-primary {
                background: linear-gradient(135deg, #0047ba 0%, #002e6b 100%);
                color: #fff !important;
                padding: 8px 16px;
                border-radius: 6px;
                font-weight: 600;
                font-size: 13px;
                text-decoration: none !important;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(0, 71, 186, 0.2);
            }
            .owc-guest-card-actions .owc-btn-primary:hover {
                background: linear-gradient(135deg, #f37021 0%, #d95b0e 100%);
                box-shadow: 0 4px 14px rgba(243, 112, 33, 0.3);
            }
            .owc-guest-card-actions .owc-btn-secondary {
                background: #fff;
                color: #334155 !important;
                border: 1px solid #cbd5e1;
                padding: 8px 16px;
                border-radius: 6px;
                font-weight: 600;
                font-size: 13px;
                text-decoration: none !important;
                transition: all 0.2s;
            }
            .owc-guest-card-actions .owc-btn-secondary:hover {
                background: #f1f5f9;
                border-color: #94a3b8;
            }

            /* Selector de Roles en Registro */
            .owc-reg-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                padding: 16px;
                border-radius: 10px;
                margin: 16px 0 20px 0;
            }
            .owc-reg-title {
                font-size: 14px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 12px 0;
            }
            .owc-reg-roles {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            @media (max-width: 600px) {
                .owc-reg-roles {
                    grid-template-columns: 1fr;
                }
            }
            .owc-role-option {
                cursor: pointer;
                display: block;
                position: relative;
            }
            .owc-role-option input {
                position: absolute;
                opacity: 0;
            }
            .owc-role-card {
                display: flex;
                flex-direction: column;
                padding: 12px;
                background: #fff;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
                transition: all 0.2s;
                height: 100%;
                box-sizing: border-box;
            }
            .owc-role-option input:checked + .owc-role-card {
                border-color: #0284c7;
                background: #f0f9ff;
                box-shadow: 0 2px 8px rgba(2, 132, 199, 0.15);
            }
            .owc-rc-icon {
                font-size: 20px;
                margin-bottom: 4px;
            }
            .owc-rc-title {
                font-weight: 700;
                font-size: 13px;
                color: #0f172a;
                margin-bottom: 4px;
            }
            .owc-rc-desc {
                font-size: 11px;
                color: #64748b;
                line-height: 1.4;
            }
            .owc-b2b-fields {
                display: none;
                margin-top: 12px;
                padding: 12px 14px;
                border: 1px solid #bae6fd;
                border-radius: 8px;
                background: #f0f9ff;
            }
            .owc-b2b-fields.is-visible { display: block; animation: owcStepReveal .28s ease both; }
            .owc-b2b-fields .form-row {
                margin-bottom: 12px;
            }
            .owc-customer-fields {
                margin-top: 16px;
            }
            .owc-customer-fields::before {
                content: 'Completa tus datos paso a paso';
                display: block;
                margin-bottom: 10px;
                color: #334155;
                font-size: 12px;
                font-weight: 700;
            }
            .owc-customer-fields .form-row {
                float: none !important;
                width: 100% !important;
                margin: 0 0 12px !important;
                padding: 0 !important;
                clear: both;
            }
            .owc-customer-fields.owc-progressive-enabled .form-row {
                display: none;
            }
            .owc-customer-fields.owc-progressive-enabled .form-row.is-visible {
                display: block;
                animation: owcStepReveal .28s ease both;
            }
            .owc-customer-fields .form-row.is-visible:last-child {
                margin-bottom: 2px !important;
            }
            .owc-customer-fields label {
                display: block;
                margin-bottom: 5px;
                color: #1e293b;
                font-size: 12px;
                font-weight: 700;
            }
            .owc-customer-fields input.input-text,
            .owc-customer-fields select {
                width: 100%;
                min-height: 42px;
                box-sizing: border-box;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                background: #fff;
                transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
            }
            .owc-customer-fields input.input-text:focus,
            .owc-customer-fields select:focus {
                border-color: #2563eb;
                background: #fff;
                box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
                outline: none;
            }
            @keyframes owcStepReveal {
                from { opacity: 0; transform: translateY(-6px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .owc-customer-fields.owc-progressive-enabled .form-row.is-visible,
                .owc-b2b-fields.is-visible { animation: none; }
            }
            .owc-b2b-note {
                margin: 4px 0 0;
                color: #64748b;
                font-size: 12px;
                line-height: 1.4;
            }

            /* Tarjeta Mi Cuenta */
            .owc-myaccount-status-card {
                padding: 16px 20px;
                border-radius: 10px;
                margin-bottom: 24px;
                border: 1px solid #e2e8f0;
            }
            .owc-myaccount-status-card.is-provider {
                background: #f0fdf4;
                border-color: #bbf7d0;
                border-left: 4px solid #16a34a;
            }
            .owc-myaccount-status-card.is-customer {
                background: #f8fafc;
                border-color: #e2e8f0;
                border-left: 4px solid #0284c7;
            }
            .owc-mas-header {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 6px;
            }
            .owc-mas-icon {
                font-size: 24px;
            }
            .owc-mas-desc {
                font-size: 13px;
                color: #475569;
            }
            .owc-b2b-upgrade-card {
                margin: 0 0 24px;
                padding: 20px;
                border: 1px solid #cfe0fb;
                border-left: 4px solid #0047ba;
                border-radius: 8px;
                background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            }
            .owc-b2b-upgrade-head {
                display: flex;
                flex-direction: column;
                gap: 4px;
                margin-bottom: 12px;
            }
            .owc-b2b-upgrade-kicker {
                width: fit-content;
                padding: 3px 8px;
                border-radius: 999px;
                background: #eaf2ff;
                color: #0047ba !important;
                font-size: 11px !important;
                font-weight: 800;
                text-transform: uppercase;
            }
            .owc-b2b-upgrade-head strong {
                color: #0f172a;
                font-size: 17px;
                line-height: 1.25;
            }
            .owc-b2b-upgrade-head span {
                color: #64748b;
                font-size: 13px;
            }
            .owc-b2b-upgrade-benefits {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 0 0 16px;
            }
            .owc-b2b-upgrade-benefits span {
                display: inline-flex;
                align-items: center;
                min-height: 24px;
                padding: 4px 9px;
                border-radius: 999px;
                background: #f1f5f9;
                color: #334155;
                font-size: 12px;
                font-weight: 700;
            }
            .owc-b2b-upgrade-form {
                display: grid;
                grid-template-columns: minmax(220px, 1fr) minmax(130px, 180px);
                gap: 12px 14px;
                align-items: end;
            }
            .owc-b2b-upgrade-form .form-row {
                margin: 0;
            }
            .owc-b2b-upgrade-form label {
                font-size: 12px;
                font-weight: 700;
                color: #334155;
            }
            .owc-b2b-upgrade-form input.input-text {
                width: 100%;
                min-height: 42px;
                border-radius: 8px;
                border: 1px solid #cbd5e1;
                padding: 9px 11px;
                box-sizing: border-box;
            }
            .owc-b2b-upgrade-btn {
                grid-column: 1 / -1;
                justify-self: start;
                min-height: 42px !important;
                padding: 10px 18px !important;
                border-radius: 8px !important;
                background: #0047ba !important;
                color: #ffffff !important;
                border: 0 !important;
                font-size: 13px !important;
                font-weight: 800 !important;
                line-height: 1.1 !important;
                white-space: normal !important;
                text-align: center !important;
                box-shadow: 0 8px 18px rgba(0, 71, 186, 0.18) !important;
            }
            .owc-b2b-upgrade-btn:hover {
                background: #f37021 !important;
                color: #ffffff !important;
            }
            @media (max-width: 760px) {
                .owc-b2b-upgrade-form {
                    grid-template-columns: 1fr;
                }
                .owc-b2b-upgrade-btn {
                    width: 100%;
                    justify-self: stretch;
                }
            }
        </style>
        <?php
    }
}
