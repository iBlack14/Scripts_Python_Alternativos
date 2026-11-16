<?php
/**
 * Módulo de Plantillas y Diseños Estructurales para Login, Registro y Mi Cuenta en WooCommerce
 * Ofrece 3 Maquetaciones / Arquitecturas distintas + Paletas de Colores Personalizables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Auth_Templates {

    const LAYOUT_SPLIT    = 'split_screen';  // Diseño 1: Doble Columna con Panel Hero Lateral
    const LAYOUT_CENTERED = 'centered_card';  // Diseño 2: Tarjeta Flotante Centrada
    const LAYOUT_DUAL     = 'side_by_side';   // Diseño 3: Portal Ejecutivo Comparativo
    const LAYOUT_NATIVE   = 'native';         // Diseño 4: Estándar del Tema Nativo

    private static $css_rendered = false;
    private static $js_rendered  = false;

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_assets' ] );
        add_action( 'wp_head',            [ __CLASS__, 'maybe_output_template_css' ], 15 );
        add_action( 'wp_footer',          [ __CLASS__, 'output_template_js' ], 99 );
        add_action( 'wp_footer',          [ __CLASS__, 'render_shipping_method_ui' ], 100 );
        
        // Estructuración del formulario de Login / Registro
        add_action( 'woocommerce_before_customer_login_form', [ __CLASS__, 'render_auth_wrapper_start' ], 1 );
        add_action( 'woocommerce_after_customer_login_form',  [ __CLASS__, 'render_auth_wrapper_end' ], 999 );

        // Header enriquecido en el panel de Mi Cuenta
        add_action( 'woocommerce_account_content', [ __CLASS__, 'render_account_dashboard_header' ], 1 );

        // Badge B2B opcional en el Carrito de compras
        add_action( 'woocommerce_after_cart_totals', [ __CLASS__, 'render_cart_b2b_badge' ] );

        // Redirección canónica si existen páginas duplicadas de Mi Cuenta (ej. /mi-cuenta/ vs /mi-cuenta-2/)
        add_action( 'template_redirect', [ __CLASS__, 'canonical_myaccount_redirect' ] );

        // Clases dinámicas en el body
        add_filter( 'body_class', [ __CLASS__, 'filter_body_classes' ] );
    }

    /**
     * Redirige accesos a páginas secundarias o slugs desfasados hacia la página canónica de Mi Cuenta de WooCommerce
     */
    public static function canonical_myaccount_redirect() {
        if ( is_admin() || wp_doing_ajax() ) return;

        $official_account_id = (int) get_option( 'woocommerce_myaccount_page_id' );
        if ( ! $official_account_id ) return;

        // Si la página actual es la vieja 'mi-cuenta' (ID 9) pero la oficial configurada en WooCommerce es otra (ej. ID 10179 'mi-cuenta-2')
        if ( is_page( 9 ) && 9 !== $official_account_id ) {
            $official_url = get_permalink( $official_account_id );
            if ( $official_url ) {
                global $wp;
                $request = $wp->request ?? '';
                if ( preg_match( '#mi-cuenta/(.*)#', $request, $matches ) ) {
                    $endpoint_part = trim( $matches[1], '/' );
                    if ( $endpoint_part ) {
                        $official_url = trailingslashit( $official_url ) . $endpoint_part . '/';
                    }
                }
                wp_safe_redirect( $official_url, 301 );
                exit;
            }
        }
    }

    /**
     * Comprueba si la configuración actual corresponde a una paleta clara
     */
    public static function is_light_palette() {
        $light_palettes = [ 'grupo_flores', 'minimal_light', 'elegant_cream', 'ocean_breeze' ];
        $palette = OWC_Settings::get( 'color_palette', 'grupo_flores' );
        if ( in_array( $palette, $light_palettes, true ) ) {
            return true;
        }
        $bg = OWC_Settings::get( 'custom_bg_color', '#090e17' );
        return ( $bg && self::is_light_color( $bg ) );
    }

    /**
     * Agrega clases CSS para habilitar estilos en Carrito y Checkout
     */
    public static function filter_body_classes( $classes ) {
        if ( is_cart() && OWC_Settings::get( 'enable_cart_styling', 'yes' ) === 'yes' ) {
            $classes[] = 'owc-cart-styled';
            if ( OWC_Settings::get( 'cart_layout', 'modern_split' ) === 'modern_split' ) {
                $classes[] = 'owc-cart-split-layout';
            }
        }
        if ( is_checkout() && OWC_Settings::get( 'enable_checkout_styling', 'yes' ) === 'yes' ) {
            $classes[] = 'owc-checkout-styled';
        }
        $theme = OWC_Settings::get( 'design_theme', 'cyber_glass' );
        if ( $theme ) {
            $classes[] = 'owc-theme-' . sanitize_html_class( $theme );
        }

        if ( self::is_light_palette() ) {
            $classes[] = 'owc-light-palette';
        }

        return $classes;
    }

    /**
     * Determina si un color hex es "claro" (luminancia > 0.5)
     */
    private static function is_light_color( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) return false;
        $r = hexdec( substr( $hex, 0, 2 ) ) / 255;
        $g = hexdec( substr( $hex, 2, 2 ) ) / 255;
        $b = hexdec( substr( $hex, 4, 2 ) ) / 255;
        // Luminancia relativa (fórmula W3C)
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $lum > 0.5;
    }

    /**
     * Badge informativo opcional en el Carrito
     */
    public static function render_cart_b2b_badge() {
        return;
    }

    /**
     * Obtiene el diseño/layout activo
     */
    public static function get_active_layout() {
        $layout = OWC_Settings::get( 'auth_layout', self::LAYOUT_SPLIT );
        // Compatibilidad retroactiva con versiones previas
        if ( $layout === 'glassmorphism' ) $layout = self::LAYOUT_SPLIT;
        if ( $layout === 'corporate' )    $layout = self::LAYOUT_DUAL;
        if ( $layout === 'luxe' )         $layout = self::LAYOUT_CENTERED;
        if ( $layout === 'none' )         $layout = self::LAYOUT_NATIVE;
        return $layout;
    }

    /**
     * Convierte un color HEX (#rrggbb) a rgba con la opacidad indicada.
     * Si recibe algo que no es hex, lo retorna tal cual.
     */
    private static function hex_to_rgba( $hex, $alpha = 0.16 ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return $hex; // Devolver tal cual si no es hex válido
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha );
    }

    /**
     * Resuelve los colores según la paleta elegida o configurados directamente por el usuario.
     *
     * Lógica de prioridad:
     * 1. Si el usuario guardó colores personalizados (custom_*) DIFERENTES a los defaults
     *    de fábrica (emerald), esos colores tienen prioridad absoluta.
     * 2. Si no, se usan los colores de la paleta seleccionada (color_palette).
     * 3. primary_d se auto-calcula siempre desde el color primario final.
     */
    public static function get_color_tokens() {
        $palette = OWC_Settings::get( 'color_palette', 'grupo_flores' );

        $palettes = [
            'grupo_flores' => [
                'primary'   => '#0047ba',
                'accent'    => '#f37021',
                'bg'        => '#f4f6fa',
                'surface'   => '#ffffff',
                'surface_2' => '#ffffff',
                'text'      => '#0f172a',
                'text_dim'  => '#475569',
                'border'    => '#cbd5e1',
            ],
            'emerald' => [
                'primary'   => '#00d4aa',
                'accent'    => '#0284c7',
                'bg'        => '#090e17',
                'surface'   => '#111827',
                'surface_2' => '#1a2332',
                'text'      => '#ffffff',
                'text_dim'  => '#94a3b8',
                'border'    => 'rgba(0, 212, 170, 0.22)',
            ],
            'minimal_light' => [
                'primary'   => '#0047ba',
                'accent'    => '#f37021',
                'bg'        => '#f8fafc',
                'surface'   => '#ffffff',
                'surface_2' => '#ffffff',
                'text'      => '#0f172a',
                'text_dim'  => '#475569',
                'border'    => '#cbd5e1',
            ],
            'royal_blue' => [
                'primary'   => '#2563eb',
                'accent'    => '#1d4ed8',
                'bg'        => '#0f172a',
                'surface'   => '#1e293b',
                'surface_2' => '#334155',
                'text'      => '#ffffff',
                'text_dim'  => '#94a3b8',
                'border'    => 'rgba(37, 99, 235, 0.28)',
            ],
            'amber_gold' => [
                'primary'   => '#f59e0b',
                'accent'    => '#d97706',
                'bg'        => '#18181b',
                'surface'   => '#27272a',
                'surface_2' => '#3f3f46',
                'text'      => '#ffffff',
                'text_dim'  => '#a1a1aa',
                'border'    => 'rgba(245, 158, 11, 0.28)',
            ],
            'cyber_violet' => [
                'primary'   => '#a855f7',
                'accent'    => '#6366f1',
                'bg'        => '#0c0a1a',
                'surface'   => '#18132e',
                'surface_2' => '#241c45',
                'text'      => '#ffffff',
                'text_dim'  => '#a5b4fc',
                'border'    => 'rgba(168, 85, 247, 0.25)',
            ],
            'crimson_red' => [
                'primary'   => '#ef4444',
                'accent'    => '#dc2626',
                'bg'        => '#150909',
                'surface'   => '#261010',
                'surface_2' => '#3b1818',
                'text'      => '#ffffff',
                'text_dim'  => '#fca5a5',
                'border'    => 'rgba(239, 68, 68, 0.25)',
            ],
            'elegant_cream' => [
                'primary'   => '#e67e22',
                'accent'    => '#d35400',
                'bg'        => '#faf8f5',
                'surface'   => '#ffffff',
                'surface_2' => '#f3ede4',
                'text'      => '#2c1810',
                'text_dim'  => '#8b7355',
                'border'    => '#e0d5c5',
            ],
            'ocean_breeze' => [
                'primary'   => '#0047ba',
                'accent'    => '#f37021',
                'bg'        => '#f4f6fa',
                'surface'   => '#ffffff',
                'surface_2' => '#ffffff',
                'text'      => '#0f172a',
                'text_dim'  => '#475569',
                'border'    => '#cbd5e1',
            ],
        ];

        $def = $palettes[ $palette ] ?? $palettes['grupo_flores'];
        $emerald_defaults = $palettes['emerald'];

        // ── Mapa de campos custom => clave de paleta ──
        $field_map = [
            'custom_primary_color'   => 'primary',
            'custom_accent_color'    => 'accent',
            'custom_bg_color'        => 'bg',
            'custom_surface_color'   => 'surface',
            'custom_surface_2_color' => 'surface_2',
            'custom_text_color'      => 'text',
            'custom_text_dim_color'  => 'text_dim',
            'custom_border_color'    => 'border',
        ];

        // Determinar si el usuario personalizó al menos un color
        // (diferente a los defaults de fábrica emerald)
        $has_custom = false;
        foreach ( $field_map as $setting_key => $token_key ) {
            $saved = OWC_Settings::get( $setting_key );
            if ( $saved && strtolower( trim( $saved ) ) !== strtolower( trim( $emerald_defaults[ $token_key ] ) ) ) {
                $has_custom = true;
                break;
            }
        }

        // Si el usuario tenía guardados los valores del preset antiguo deslavado ocean_breeze (#0891b2),
        // migrar automáticamente al nuevo estándar corporativo de Grupo Flores (#0047ba / #f37021)
        $saved_pri = strtolower( trim( OWC_Settings::get( 'custom_primary_color', '' ) ) );
        if ( $saved_pri === '#0891b2' ) {
            $has_custom = false;
            $palette = 'grupo_flores';
            $def = $palettes['grupo_flores'];
        }

        $tokens = [];
        foreach ( $field_map as $setting_key => $token_key ) {
            if ( $has_custom ) {
                // El usuario personalizó colores → usar sus valores custom
                $tokens[ $token_key ] = OWC_Settings::get( $setting_key, $def[ $token_key ] );
            } else {
                // No personalizó → usar 100% la paleta seleccionada
                $tokens[ $token_key ] = $def[ $token_key ];
            }
        }

        // Auto-calcular primary_d (versión translúcida) y contraste de texto para botones primarios
        $tokens['primary_d'] = self::hex_to_rgba( $tokens['primary'], 0.16 );
        $tokens['btn_text']  = self::is_light_color( $tokens['primary'] ) ? '#0a0f1a' : '#ffffff';

        return $tokens;
    }

    /**
     * Enqueue de fuentes de Google en Front-End
     */
    public static function enqueue_frontend_assets() {
        wp_enqueue_style( 'owc-google-fonts', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap', [], null );
    }

    /**
     * Intenta emitir el CSS dinámico en <head> si estamos en páginas relevantes
     */
    public static function maybe_output_template_css() {
        if ( self::$css_rendered ) return;

        $layout = self::get_active_layout();
        $cart_styled = OWC_Settings::get( 'enable_cart_styling', 'yes' ) === 'yes';

        if ( $layout === self::LAYOUT_NATIVE && ! $cart_styled && ! is_checkout() ) return;

        $is_target = is_account_page() || is_checkout() || is_cart();
        if ( ! $is_target && is_singular() ) {
            global $post;
            if ( $post ) {
                $content = $post->post_content ?? '';
                $slug    = $post->post_name ?? '';
                if ( has_shortcode( $content, 'woocommerce_my_account' ) ||
                     has_shortcode( $content, 'woocommerce_cart' ) ||
                     has_shortcode( $content, 'woocommerce_checkout' ) ||
                     strpos( $slug, 'cuenta' ) !== false ||
                     strpos( $slug, 'carrito' ) !== false ||
                     strpos( $slug, 'checkout' ) !== false ) {
                    $is_target = true;
                }
            }
        }

        if ( $is_target ) {
            self::output_template_css();
        }
    }

    /**
     * Envoltorio inicial según la arquitectura seleccionada
     */
    public static function render_auth_wrapper_start() {
        $layout = self::get_active_layout();
        if ( $layout === self::LAYOUT_NATIVE ) return;

        // Inyección infalible: si el CSS no fue cargado en <head> (ej. Elementor Canvas o hook omitido), se inyecta inmediatamente
        if ( ! self::$css_rendered ) {
            self::output_template_css();
        }

        $title = OWC_Settings::get( 'auth_title', 'Portal Empresarial & Tienda B2B' );
        $subtitle = OWC_Settings::get( 'auth_subtitle', 'Accede a tu cuenta para ver precios exclusivos, existencias y pedidos.' );
        $hero_title = OWC_Settings::get( 'auth_hero_title', 'Acceso para Clientes y Ferreteros' );
        $hero_subtitle = OWC_Settings::get( 'auth_hero_subtitle', 'Gestiona tus compras mayoristas, cotizaciones y pedidos empresariales.' );
        ?>
        <div class="owc-auth-portal owc-layout-<?php echo esc_attr( $layout ); ?><?php echo self::is_light_palette() ? ' owc-light-palette' : ''; ?>">
            <div class="owc-auth-shell">

                <?php if ( $layout === self::LAYOUT_SPLIT ) : ?>
                    <!-- Panel Hero Lateral (Diseño Split-Screen) -->
                    <div class="owc-hero-side">
                        <h2 class="owc-hero-title"><?php echo esc_html( $hero_title ); ?></h2>
                        <p class="owc-hero-desc"><?php echo esc_html( $hero_subtitle ); ?></p>
                        
                        <div class="owc-hero-features">
                            <div class="owc-hf-item">
                                <span class="owc-hf-icon">⚡</span>
                                <div>
                                    <strong>Precios Ferreteros B2B</strong>
                                    <span>Visualiza precios con IGV y descuentos exclusivos por volumen.</span>
                                </div>
                            </div>
                            <div class="owc-hf-item">
                                <span class="owc-hf-icon">📦</span>
                                <div>
                                    <strong>Catálogo Empresarial</strong>
                                    <span>Consulta productos, precios y disponibilidad para tu empresa.</span>
                                </div>
                            </div>
                            <div class="owc-hf-item">
                                <span class="owc-hf-icon">🏢</span>
                                <div>
                                    <strong>Facturación con RUC</strong>
                                    <span>Registro rápido para empresas y distribuidores autorizados.</span>
                                </div>
                            </div>
                        </div>

                        <div class="owc-hero-footer-tag">
                            <span>Atención para <strong>clientes y proveedores</strong></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Contenedor Principal de Formularios -->
                <div class="owc-forms-side">
                    <div class="owc-auth-header-card">
                        <?php if ( $layout !== self::LAYOUT_SPLIT ) : ?>
                            <div class="owc-auth-badge">
                                <span class="owc-badge-dot"></span> Acceso Clientes & Ferreteros
                            </div>
                        <?php endif; ?>
                        <h1 class="owc-auth-main-title"><?php echo esc_html( $title ); ?></h1>
                        <p class="owc-auth-sub-title"><?php echo esc_html( $subtitle ); ?></p>
                        
                        <?php 
                        $can_register = ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) );
                        if ( $layout !== self::LAYOUT_DUAL && $can_register ) : 
                        ?>
                            <!-- Pestañas Interactivas Login / Registro -->
                            <div class="owc-auth-tabs">
                                <button type="button" class="owc-auth-tab active" data-tab="login">
                                    <span>🔑 Iniciar Sesión</span>
                                </button>
                                <button type="button" class="owc-auth-tab" data-tab="register">
                                    <span>📝 Crear Cuenta</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="owc-auth-forms-grid">

                    <?php if ( ! $can_register ) : 
                        $register_url = OWC_Settings::get( 'register_url', '' );
                        if ( empty( $register_url ) ) {
                            // Fallback: intentar la URL de mi-cuenta con parámetro de registro
                            $register_url = add_query_arg( 'action', 'register', wc_get_page_permalink( 'myaccount' ) );
                        }
                    ?>
                        <!-- Enlace de registro alternativo cuando WooCommerce no habilita registro directo -->
                        <div class="owc-register-cta-bar">
                            <div class="owc-register-cta-content">
                                <span class="owc-register-cta-icon">📝</span>
                                <div class="owc-register-cta-text">
                                    <strong>¿No tienes cuenta?</strong>
                                    <span>Regístrate como cliente o ferretero/distribuidor para acceder a precios exclusivos.</span>
                                </div>
                            </div>
                            <a href="<?php echo esc_url( $register_url ); ?>" class="owc-register-cta-btn">Crear Cuenta Empresarial</a>
                        </div>
                    <?php endif; ?>
        <?php
    }

    /**
     * Envoltorio final
     */
    public static function render_auth_wrapper_end() {
        $layout = self::get_active_layout();
        if ( $layout === self::LAYOUT_NATIVE ) return;
        ?>
                    </div>
                    <?php if ( $layout !== self::LAYOUT_SPLIT ) : ?>
                        <div class="owc-auth-footer">
                            <span>Portal B2B Empresarial</span>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
        // Inyección infalible de JS si wp_footer no ha corrido
        if ( ! self::$js_rendered ) {
            self::output_template_js();
        }
    }

    /**
     * Banner del Dashboard de Mi Cuenta
     */
    /**
     * Banner del Dashboard de Mi Cuenta
     */
    public static function render_account_dashboard_header() {
        if ( ! is_user_logged_in() ) return;
        if ( ! self::$css_rendered ) {
            self::output_template_css();
        }

        // Si estamos dentro de un sub-apartado (pedidos, editar cuenta, direcciones, etc.)
        // NO renderizar el banner general del escritorio, sino un encabezado claro y específico
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
            self::render_endpoint_header();
            return;
        }
        
        $user = wp_get_current_user();
        $is_provider = in_array( 'owc_provider', (array) $user->roles, true );
        $company = get_user_meta( $user->ID, 'billing_company', true ) ?: get_user_meta( $user->ID, 'owc_company_name', true );
        $tax_id  = get_user_meta( $user->ID, 'billing_tax_id', true ) ?: get_user_meta( $user->ID, 'owc_tax_id', true );
        $document_type = strtoupper( sanitize_text_field( get_user_meta( $user->ID, 'owc_document_type', true ) ) );
        $document_digits = preg_replace( '/\D+/', '', (string) $tax_id );
        if ( strlen( $document_digits ) === 8 ) $document_type = 'DNI';
        elseif ( strlen( $document_digits ) === 11 ) $document_type = 'RUC';
        elseif ( ! in_array( $document_type, [ 'DNI', 'RUC' ], true ) ) $document_type = 'Documento';
        
        $customer_orders = wc_get_orders([
            'customer' => $user->ID,
            'limit'    => -1,
            'return'   => 'ids',
        ]);
        $order_count = is_array( $customer_orders ) ? count( $customer_orders ) : 0;
        
        $layout = self::get_active_layout();
        ?>
        <div class="owc-acc-header-banner">
            <div class="owc-acc-user-info">
                <div class="owc-acc-avatar">
                    <?php echo get_avatar( $user->ID, 72 ); ?>
                </div>
                <div class="owc-acc-details">
                    <div class="owc-acc-role-pill <?php echo $is_provider ? 'is-provider' : 'is-customer'; ?>">
                        <?php echo $is_provider ? '🏢 Ferretero / Distribuidor' : '👤 Cliente Registrado'; ?>
                    </div>
                    <h2 class="owc-acc-name">¡Hola, <?php echo esc_html( $user->display_name ); ?>!</h2>
                    <div class="owc-acc-meta">
                        <span>📧 <?php echo esc_html( $user->user_email ); ?></span>
                        <?php if ( $company ) : ?>
                            <span>🏢 <strong><?php echo esc_html( $company ); ?></strong></span>
                        <?php endif; ?>
                        <?php if ( $tax_id ) : ?>
                            <span>📄 <?php echo esc_html( $document_type ); ?>: <strong><?php echo esc_html( $tax_id ); ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="owc-acc-stats-row">
                <div class="owc-acc-stat-box">
                    <span class="stat-num"><?php echo esc_html( $order_count ); ?></span>
                    <span class="stat-lbl">Pedidos Realizados</span>
                </div>
                <div class="owc-acc-stat-box">
                    <span class="stat-num"><?php echo $is_provider ? 'B2B' : 'VIP'; ?></span>
                    <span class="stat-lbl">Nivel de Cuenta</span>
                </div>
                <div class="owc-acc-stat-box">
                    <span class="stat-num">Activo</span>
                    <span class="stat-lbl">Estado de Cuenta</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Encabezado específico para sub-apartados de Mi Cuenta (pedidos, editar cuenta, etc.)
     */
    private static function render_endpoint_header() {
        $icon  = '⚙️';
        $title = '';
        $desc  = '';

        if ( is_wc_endpoint_url( 'orders' ) ) {
            $icon  = '📦';
            $title = 'Mis Pedidos';
            $desc  = 'Historial y estado de tus compras.';
        } elseif ( is_wc_endpoint_url( 'view-order' ) ) {
            $icon  = '📄';
            $title = 'Detalles del Pedido';
            $desc  = 'Información detallada de la orden, facturación y estado de despacho.';
        } elseif ( is_wc_endpoint_url( 'edit-account' ) ) {
            $icon  = '👤';
            $title = 'Detalles de la Cuenta';
            $desc  = 'Actualiza tus datos personales, correo electrónico y contraseña de acceso.';
        } elseif ( is_wc_endpoint_url( 'edit-address' ) ) {
            $icon  = '📍';
            $title = 'Direcciones de Facturación y Envío';
            $desc  = 'Administra tus direcciones para emisión de facturas con RUC y entregas.';
        } elseif ( is_wc_endpoint_url( 'downloads' ) ) {
            $icon  = '📥';
            $title = 'Descargas Disponibles';
            $desc  = 'Accede a tus archivos y comprobantes digitales descargables.';
        } elseif ( is_wc_endpoint_url( 'payment-methods' ) ) {
            $icon  = '💳';
            $title = 'Métodos de Pago';
            $desc  = 'Tarjetas y métodos de pago guardados en tu cuenta.';
        }

        if ( ! $title ) return;
        ?>
        <div class="owc-endpoint-header">
            <div class="owc-endpoint-icon"><?php echo esc_html( $icon ); ?></div>
            <div>
                <h2 class="owc-endpoint-title"><?php echo esc_html( $title ); ?></h2>
                <p class="owc-endpoint-desc"><?php echo esc_html( $desc ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Imprime el CSS dinámico según la paleta y el diseño seleccionado
     */
    public static function output_template_css() {
        if ( self::$css_rendered ) return;
        self::$css_rendered = true;

        $c = self::get_color_tokens();
        $layout = self::get_active_layout();

        // ── Validación: detectar colores rotos/inválidos (todos blancos o negros) ──
        $all_bw = true;
        foreach ( ['primary','accent','bg','surface','surface_2'] as $k ) {
            $v = strtolower( trim( $c[$k] ) );
            if ( ! in_array( $v, ['#000000','#ffffff','#fff','#000',''], true ) ) {
                $all_bw = false;
                break;
            }
        }
        if ( $all_bw ) {
            // Colores rotos → forzar emerald como fallback de emergencia
            $c = [
                'primary'   => '#00d4aa',
                'primary_d' => 'rgba(0, 212, 170, 0.16)',
                'accent'    => '#0284c7',
                'bg'        => '#090e17',
                'surface'   => '#111827',
                'surface_2' => '#1a2332',
                'text'      => '#ffffff',
                'text_dim'  => '#94a3b8',
                'border'    => 'rgba(0, 212, 170, 0.22)',
            ];
        }
        ?>
        <style id="owc-auth-dynamic-styles">
        :root {
            --owc-c-pri: <?php echo esc_attr( $c['primary'] ); ?>;
            --owc-c-pri-dim: <?php echo esc_attr( $c['primary_d'] ); ?>;
            --owc-c-acc: <?php echo esc_attr( $c['accent'] ); ?>;
            --owc-c-bg: <?php echo esc_attr( $c['bg'] ); ?>;
            --owc-c-surface: <?php echo esc_attr( $c['surface'] ); ?>;
            --owc-c-surface-2: <?php echo esc_attr( $c['surface_2'] ); ?>;
            --owc-c-text: <?php echo esc_attr( $c['text'] ); ?>;
            --owc-c-text-dim: <?php echo esc_attr( $c['text_dim'] ); ?>;
            --owc-c-border: <?php echo esc_attr( $c['border'] ); ?>;
            --owc-c-btn-txt: <?php echo esc_attr( $c['btn_text'] ?? '#ffffff' ); ?>;
        }

        /* ── Override Global de Tema en Body y Elementor ── */
        body.owc-theme-cyber_glass,
        body.owc-theme-corp_slate,
        body.owc-theme-luxury_noir {
            background-color: var(--owc-c-bg) !important;
            color: var(--owc-c-text) !important;
        }
        body.owc-theme-cyber_glass .site-content,
        body.owc-theme-cyber_glass .page-content,
        body.owc-theme-cyber_glass .entry-content,
        body.owc-theme-cyber_glass .elementor,
        body.owc-theme-cyber_glass .elementor-widget-container,
        body.owc-theme-cyber_glass .woocommerce,
        body.owc-theme-corp_slate .site-content,
        body.owc-theme-corp_slate .page-content,
        body.owc-theme-corp_slate .entry-content,
        body.owc-theme-corp_slate .elementor-widget-container,
        body.owc-theme-corp_slate .woocommerce,
        body.owc-theme-luxury_noir .site-content,
        body.owc-theme-luxury_noir .page-content,
        body.owc-theme-luxury_noir .entry-content,
        body.owc-theme-luxury_noir .elementor-widget-container,
        body.owc-theme-luxury_noir .woocommerce {
            background-color: transparent !important;
        }

        /* ── Centrado Forzado y Eliminación de Barras Laterales / Encabezados Huérfanos en Mi Cuenta ── */
        .woocommerce-account .page-header,
        .woocommerce-account header.page-header,
        .woocommerce-account div.page-header,
        .woocommerce-account h1.entry-title,
        .woocommerce-account .entry-title,
        .woocommerce-account .entry-header,
        .woocommerce-account #secondary,
        .woocommerce-account .sidebar,
        .woocommerce-account .widget-area,
        .page-id-9 .page-header,
        .page-id-9 h1.entry-title,
        .page-id-10179 .page-header,
        .page-id-10179 header.page-header,
        .page-id-10179 div.page-header,
        .page-id-10179 h1.entry-title,
        .page-id-10179 .entry-title {
            display: none !important;
        }

        /* Garantizar que los contenedores ancestros centren el contenido SOLO en el area principal (sin romper header/footer) */
        .woocommerce-account .site-main,
        .woocommerce-account #content,
        .woocommerce-account main#content,
        .woocommerce-account .content-area,
        .woocommerce-account #primary,
        .woocommerce-account .page-content,
        .woocommerce-account .entry-content,
        .woocommerce-account .woocommerce,
        .woocommerce-account main .elementor-section-wrap,
        .woocommerce-account main .elementor-section,
        .woocommerce-account main .elementor-container,
        .woocommerce-account main .elementor-column,
        .woocommerce-account main .elementor-column-wrap,
        .woocommerce-account main .elementor-widget-wrap,
        .woocommerce-account main .elementor-widget-container,
        .woocommerce-account main .elementor-element,
        .woocommerce-account main .e-con,
        .woocommerce-account main .e-con-inner,
        .woocommerce-account main .e-child,
        .woocommerce-account .site-main .elementor-section,
        .woocommerce-account .site-main .elementor-container,
        .woocommerce-account .site-main .elementor-column,
        .woocommerce-account .site-main .e-con,
        .page-id-10179 .site-main,
        .page-id-10179 #content,
        .page-id-10179 main#content,
        .page-id-10179 .content-area,
        .page-id-10179 #primary,
        .page-id-10179 .page-content,
        .page-id-10179 .entry-content,
        .page-id-10179 .woocommerce,
        .page-id-10179 main .elementor-section-wrap,
        .page-id-10179 main .elementor-section,
        .page-id-10179 main .elementor-container,
        .page-id-10179 main .elementor-column,
        .page-id-10179 main .elementor-column-wrap,
        .page-id-10179 main .elementor-widget-wrap,
        .page-id-10179 main .elementor-widget-container,
        .page-id-10179 main .elementor-element,
        .page-id-10179 main .e-con,
        .page-id-10179 main .e-con-inner,
        .page-id-10179 main .e-child {
            width: 100% !important;
            max-width: 100% !important;
            margin-left: auto !important;
            margin-right: auto !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            float: none !important;
            clear: both !important;
            display: block !important;
            box-sizing: border-box !important;
        }

        .owc-auth-portal {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            width: 100vw !important;
            max-width: 100vw !important;
            margin: 36px calc(50% - 50vw) 64px calc(50% - 50vw) !important;
            padding: 0 16px !important;
            box-sizing: border-box !important;
            color: var(--owc-c-text) !important;
            float: none !important;
            clear: both !important;
            position: relative !important;
            left: 0 !important;
            right: auto !important;
            transform: none !important;
        }
        .owc-auth-portal * { box-sizing: border-box !important; }

        .owc-auth-shell {
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.12), 0 0 0 1px var(--owc-c-border);
            position: relative;
            width: 100% !important;
            margin: 0 auto !important;
            float: none !important;
        }

        /* ── DISEÑO 1: SPLIT-SCREEN (Doble Columna con Panel Hero) ── */
        .owc-layout-split_screen .owc-auth-shell {
            display: grid !important;
            grid-template-columns: 1fr 1.15fr !important;
            max-width: 1060px !important;
            width: 100% !important;
            margin: 0 auto !important;
            background: var(--owc-c-surface) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 22px !important;
            box-shadow:
                0 30px 80px rgba(0, 47, 108, 0.16),
                0 0 0 1px var(--owc-c-border) !important;
        }
        .owc-hero-side {
            background: linear-gradient(155deg, #061933 0%, #002e6b 50%, #0047ba 100%);
            padding: 48px 40px;
            border-right: 1px solid rgba(0, 71, 186, 0.2);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        /* Sutil efecto de destello radial en el hero */
        .owc-hero-side::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -30%;
            width: 80%;
            height: 80%;
            background: radial-gradient(circle, var(--owc-c-pri-dim) 0%, transparent 70%);
            pointer-events: none;
            opacity: 0.5;
        }
        .owc-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--owc-c-pri-dim);
            color: var(--owc-c-pri);
            border: 1px solid var(--owc-c-border);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            width: fit-content;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        .owc-live-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--owc-c-pri);
            box-shadow: 0 0 8px var(--owc-c-pri), 0 0 16px var(--owc-c-pri-dim);
            animation: owcPulse 2s infinite;
        }
        .owc-hero-title {
            font-size: 30px;
            font-weight: 800;
            line-height: 1.2;
            color: var(--owc-c-text);
            margin: 0 0 14px 0;
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
        }
        .owc-hero-desc {
            font-size: 14.5px;
            color: var(--owc-c-text-dim);
            line-height: 1.65;
            margin: 0 0 32px 0;
            position: relative;
            z-index: 1;
        }
        .owc-hero-features {
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        .owc-hf-item {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .owc-hf-icon {
            font-size: 20px;
            background: var(--owc-c-surface-2);
            border: 1px solid var(--owc-c-border);
            padding: 10px;
            border-radius: 12px;
            line-height: 1;
            flex-shrink: 0;
            transition: all 0.25s ease;
        }
        .owc-hf-item:hover .owc-hf-icon {
            background: var(--owc-c-pri-dim);
            border-color: var(--owc-c-pri);
            transform: scale(1.08);
        }
        .owc-hf-item strong {
            display: block;
            font-size: 13.5px;
            color: var(--owc-c-text);
            margin-bottom: 3px;
        }
        .owc-hf-item span {
            font-size: 12.5px;
            color: var(--owc-c-text-dim);
            line-height: 1.45;
        }
        .owc-hero-footer-tag {
            font-size: 11px;
            color: var(--owc-c-text-dim);
            padding-top: 24px;
            border-top: 1px solid var(--owc-c-border);
            position: relative;
            z-index: 1;
        }
        .owc-hero-footer-tag strong { color: var(--owc-c-pri); }
        .owc-forms-side {
            padding: 44px 40px;
            background: var(--owc-c-surface);
            position: relative;
        }
        /* Sutil gradiente en el fondo del form side */
        .owc-forms-side::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 40%;
            background: linear-gradient(to top, var(--owc-c-bg), transparent);
            pointer-events: none;
            opacity: 0.3;
        }

        /* ── DISEÑO 2: CENTERED FLOATING CARD (Caja Centrada) ── */
        .owc-layout-centered_card .owc-auth-shell {
            max-width: 500px !important;
            margin: 20px auto !important;
            background: var(--owc-c-surface) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 20px !important;
            padding: 42px 38px !important;
            box-shadow: 0 25px 60px rgba(0,0,0,0.65), 0 0 35px var(--owc-c-pri-dim) !important;
            position: relative !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
        }
        .owc-layout-centered_card .owc-forms-side {
            padding: 0 !important;
            background: transparent !important;
        }

        /* ── DISEÑO 3: DUAL PORTAL (Dos Columnas Comparativas) ── */
        .owc-layout-side_by_side .owc-auth-shell {
            max-width: 1100px !important;
            margin: 0 auto !important;
            padding: 38px 32px !important;
            background: var(--owc-c-surface) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 20px !important;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6), 0 0 35px var(--owc-c-pri-dim) !important;
        }
        .owc-layout-side_by_side .owc-forms-side {
            padding: 0 !important;
            background: transparent !important;
        }
        .owc-layout-side_by_side .owc-auth-forms-grid .u-columns.col2-set {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 32px !important;
        }

        /* ── Elementos Comunes ── */
        .owc-auth-header-card {
            text-align: center !important;
            margin-bottom: 24px !important;
        }
        .owc-auth-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: var(--owc-c-pri-dim) !important;
            color: var(--owc-c-pri) !important;
            border: 1px solid var(--owc-c-border) !important;
            padding: 5px 14px !important;
            border-radius: 20px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            margin-bottom: 14px !important;
        }
        .owc-badge-dot {
            width: 8px !important;
            height: 8px !important;
            border-radius: 50% !important;
            background: var(--owc-c-pri) !important;
            box-shadow: 0 0 8px var(--owc-c-pri) !important;
            animation: owcPulse 2s infinite !important;
        }
        @keyframes owcPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }
        .owc-auth-main-title {
            font-size: 24px !important;
            font-weight: 800 !important;
            color: var(--owc-c-text) !important;
            margin: 0 0 6px 0 !important;
            line-height: 1.3 !important;
            letter-spacing: -0.4px !important;
        }
        .owc-auth-sub-title {
            font-size: 13.5px !important;
            color: var(--owc-c-text-dim) !important;
            margin: 0 0 18px 0 !important;
            line-height: 1.5 !important;
        }

        /* ── Pestañas Interactivas (Anti-Elementor Reset Completo) ── */
        .owc-auth-tabs {
            display: inline-flex !important;
            gap: 6px !important;
            background: var(--owc-c-bg) !important;
            border: 1px solid var(--owc-c-border) !important;
            padding: 5px !important;
            border-radius: 12px !important;
            margin: 8px auto 0 auto !important;
            max-width: 100% !important;
        }
        .owc-auth-tabs button.owc-auth-tab {
            all: unset !important;
            box-sizing: border-box !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            padding: 10px 22px !important;
            border-radius: 8px !important;
            font-size: 13.5px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            background: transparent !important;
            color: var(--owc-c-text-dim) !important;
            border: 1px solid transparent !important;
            transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
            line-height: 1.4 !important;
            text-shadow: none !important;
            box-shadow: none !important;
            appearance: none !important;
            -webkit-appearance: none !important;
        }
        .owc-auth-tabs button.owc-auth-tab:hover {
            color: var(--owc-c-text) !important;
            background: rgba(255, 255, 255, 0.06) !important;
        }
        .owc-auth-tabs button.owc-auth-tab.active {
            background: var(--owc-c-pri) !important;
            color: var(--owc-c-btn-txt, #050b14) !important;
            font-weight: 800 !important;
            border-color: var(--owc-c-pri) !important;
            box-shadow: 0 4px 16px var(--owc-c-pri-dim) !important;
        }

        /* ── Resets del Formulario de WooCommerce ── */
        .owc-auth-forms-grid {
            margin-top: 18px !important;
        }

        /* ── Banner de Registro Alternativo ── */
        .owc-register-cta-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
            border: 1px solid #b8d0f7;
            border-left: 4px solid #0047ba;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 22px;
        }
        .owc-register-cta-content {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .owc-register-cta-icon {
            font-size: 28px;
            flex-shrink: 0;
        }
        .owc-register-cta-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .owc-register-cta-text strong {
            font-size: 15px;
            color: #0f172a;
            font-weight: 700;
        }
        .owc-register-cta-text span {
            font-size: 13px;
            color: #475569;
            line-height: 1.4;
        }
        .owc-register-cta-btn {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            background: linear-gradient(135deg, #0047ba 0%, #002e6b 100%);
            color: #ffffff !important;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none !important;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(0, 71, 186, 0.25);
            letter-spacing: 0.2px;
        }
        .owc-register-cta-btn:hover {
            background: linear-gradient(135deg, #f37021 0%, #d95b0e 100%);
            color: #ffffff !important;
            transform: translateY(-1px);
            box-shadow: 0 5px 18px rgba(243, 112, 33, 0.35);
        }
        @media (max-width: 640px) {
            .owc-register-cta-bar {
                flex-direction: column;
                text-align: center;
            }
            .owc-register-cta-content {
                flex-direction: column;
            }
        }
        .owc-auth-forms-grid h2 {
            display: none !important;
        }
        .owc-auth-forms-grid .u-columns.col2-set,
        .owc-auth-forms-grid .u-column1,
        .owc-auth-forms-grid .u-column2,
        .owc-auth-forms-grid .col-1,
        .owc-auth-forms-grid .col-2,
        .owc-auth-forms-grid form.login,
        .owc-auth-forms-grid form.register,
        .woocommerce-account .woocommerce form.login,
        .woocommerce-account .woocommerce form.register {
            width: 100% !important;
            float: none !important;
            padding: 0 !important;
            margin: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        /* En diseño Dual, las dos columnas tienen tarjetas separadas */
        .owc-layout-side_by_side .owc-auth-forms-grid .u-column1,
        .owc-layout-side_by_side .owc-auth-forms-grid .u-column2,
        .owc-layout-side_by_side .owc-auth-forms-grid .col-1,
        .owc-layout-side_by_side .owc-auth-forms-grid .col-2 {
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 16px !important;
            padding: 28px !important;
        }
        .owc-layout-side_by_side .owc-auth-forms-grid h2 {
            display: block !important;
            font-size: 18px !important;
            font-weight: 800 !important;
            color: var(--owc-c-text) !important;
            margin-bottom: 18px !important;
            padding-bottom: 10px !important;
            border-bottom: 1px solid var(--owc-c-border) !important;
        }

        /* Form rows, labels e inputs */
        .owc-auth-forms-grid form .form-row,
        .woocommerce form.login .form-row,
        .woocommerce form.register .form-row {
            margin-bottom: 16px !important;
            padding: 0 !important;
            width: 100% !important;
            float: none !important;
        }
        .owc-auth-forms-grid label,
        .woocommerce form.login label,
        .woocommerce form.register label {
            display: block !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            color: var(--owc-c-text-dim) !important;
            margin-bottom: 7px !important;
            text-transform: none !important;
        }
        .owc-auth-forms-grid label .required,
        .woocommerce form.login label .required {
            color: var(--owc-c-pri) !important;
        }
        .owc-auth-forms-grid input.input-text,
        .owc-auth-forms-grid input[type="text"],
        .owc-auth-forms-grid input[type="email"],
        .owc-auth-forms-grid input[type="password"],
        .owc-auth-forms-grid select,
        .woocommerce form.login .form-row input.input-text,
        .woocommerce form.register .form-row input.input-text {
            width: 100% !important;
            height: 50px !important;
            line-height: 50px !important;
            padding: 0 18px !important;
            border-radius: 12px !important;
            font-size: 14px !important;
            font-family: inherit !important;
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
            color: var(--owc-c-text) !important;
            outline: none !important;
            box-sizing: border-box !important;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.15) !important;
        }
        .owc-auth-forms-grid input.input-text::placeholder,
        .owc-auth-forms-grid input[type="text"]::placeholder,
        .owc-auth-forms-grid input[type="email"]::placeholder,
        .owc-auth-forms-grid input[type="password"]::placeholder {
            color: var(--owc-c-text-dim) !important;
            opacity: 0.6 !important;
        }
        .owc-auth-forms-grid input.input-text:focus,
        .owc-auth-forms-grid input[type="text"]:focus,
        .owc-auth-forms-grid input[type="email"]:focus,
        .owc-auth-forms-grid input[type="password"]:focus,
        .owc-auth-forms-grid select:focus,
        .woocommerce form.login .form-row input.input-text:focus,
        .woocommerce form.register .form-row input.input-text:focus {
            border-color: var(--owc-c-pri) !important;
            background: var(--owc-c-bg) !important;
            box-shadow: 0 0 0 3px var(--owc-c-pri-dim), 0 0 20px var(--owc-c-pri-dim), inset 0 1px 2px rgba(0,0,0,0.1) !important;
        }

        /* Checkbox Recuérdame */
        .owc-auth-forms-grid .woocommerce-form-login__rememberme {
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            font-size: 13px !important;
            color: var(--owc-c-text-dim) !important;
            cursor: pointer !important;
            margin-top: 6px !important;
        }
        .owc-auth-forms-grid .woocommerce-form-login__rememberme input[type="checkbox"] {
            accent-color: var(--owc-c-pri) !important;
            width: 16px !important;
            height: 16px !important;
            cursor: pointer !important;
        }

        /* ¿Olvidaste la contraseña? */
        .owc-auth-forms-grid .lost_password {
            margin-top: 16px !important;
            text-align: center !important;
            font-size: 13px !important;
        }
        .owc-auth-forms-grid .lost_password a {
            color: var(--owc-c-pri) !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
            position: relative !important;
        }
        .owc-auth-forms-grid .lost_password a::after {
            content: '' !important;
            position: absolute !important;
            bottom: -2px !important;
            left: 0 !important;
            width: 0 !important;
            height: 1px !important;
            background: var(--owc-c-pri) !important;
            transition: width 0.3s ease !important;
        }
        .owc-auth-forms-grid .lost_password a:hover::after {
            width: 100% !important;
        }
        .owc-auth-forms-grid .lost_password a:hover {
            opacity: 0.9 !important;
        }

        /* Botón Submit Principal (Anti-Elementor Reset Completo) */
        .owc-auth-forms-grid button.woocommerce-form-login__submit,
        .owc-auth-forms-grid button.woocommerce-form-register__submit,
        .owc-auth-forms-grid button[type="submit"],
        .owc-auth-forms-grid .button,
        .woocommerce form.login button[type="submit"],
        .woocommerce form.register button[type="submit"] {
            all: unset !important;
            box-sizing: border-box !important;
            display: block !important;
            width: 100% !important;
            height: 52px !important;
            line-height: 52px !important;
            text-align: center !important;
            background: linear-gradient(135deg, var(--owc-c-pri) 0%, var(--owc-c-acc) 100%) !important;
            color: var(--owc-c-btn-txt, #050b14) !important;
            font-size: 15px !important;
            font-weight: 800 !important;
            border-radius: 12px !important;
            cursor: pointer !important;
            box-shadow: 0 6px 24px var(--owc-c-pri-dim), 0 2px 6px rgba(0,0,0,0.3) !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            margin-top: 20px !important;
            margin-bottom: 12px !important;
            letter-spacing: 0.3px !important;
            position: relative !important;
            overflow: hidden !important;
        }
        .owc-auth-forms-grid button.woocommerce-form-login__submit::before,
        .owc-auth-forms-grid button.woocommerce-form-register__submit::before,
        .owc-auth-forms-grid button[type="submit"]::before,
        .woocommerce form.login button[type="submit"]::before,
        .woocommerce form.register button[type="submit"]::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important; left: -100% !important;
            width: 100% !important; height: 100% !important;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent) !important;
            transition: left 0.5s ease !important;
        }
        .owc-auth-forms-grid button.woocommerce-form-login__submit:hover::before,
        .owc-auth-forms-grid button.woocommerce-form-register__submit:hover::before,
        .owc-auth-forms-grid button[type="submit"]:hover::before,
        .woocommerce form.login button[type="submit"]:hover::before,
        .woocommerce form.register button[type="submit"]:hover::before {
            left: 100% !important;
        }
        .owc-auth-forms-grid button.woocommerce-form-login__submit:hover,
        .owc-auth-forms-grid button.woocommerce-form-register__submit:hover,
        .owc-auth-forms-grid button[type="submit"]:hover,
        .owc-auth-forms-grid .button:hover,
        .woocommerce form.login button[type="submit"]:hover,
        .woocommerce form.register button[type="submit"]:hover {
            transform: translateY(-2px) scale(1.01) !important;
            filter: brightness(1.1) !important;
            box-shadow: 0 10px 30px var(--owc-c-pri-dim), 0 4px 10px rgba(0,0,0,0.4) !important;
        }
        .owc-auth-forms-grid button.woocommerce-form-login__submit:active,
        .owc-auth-forms-grid button.woocommerce-form-register__submit:active,
        .owc-auth-forms-grid button[type="submit"]:active,
        .owc-auth-forms-grid .button:active,
        .woocommerce form.login button[type="submit"]:active,
        .woocommerce form.register button[type="submit"]:active {
            transform: translateY(0) scale(0.99) !important;
            box-shadow: 0 4px 12px var(--owc-c-pri-dim) !important;
        }

        /* ── B2B Role Selector & Register Extra Fields ── */
        .owc-auth-forms-grid .owc-reg-box {
            background: var(--owc-c-bg) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 12px !important;
            padding: 16px !important;
            margin: 16px 0 20px 0 !important;
        }
        .owc-auth-forms-grid .owc-reg-title {
            color: var(--owc-c-text) !important;
            font-size: 13.5px !important;
            font-weight: 700 !important;
            margin-bottom: 10px !important;
        }
        .owc-auth-forms-grid .owc-role-card {
            background: var(--owc-c-surface) !important;
            border: 2px solid var(--owc-c-border) !important;
            border-radius: 10px !important;
            padding: 12px !important;
            transition: all 0.2s ease !important;
        }
        .owc-auth-forms-grid .owc-role-option input:checked + .owc-role-card {
            border-color: var(--owc-c-pri) !important;
            background: var(--owc-c-surface-2) !important;
            box-shadow: 0 0 0 1px var(--owc-c-pri), 0 4px 15px var(--owc-c-pri-dim) !important;
        }
        .owc-auth-forms-grid .owc-rc-title {
            color: var(--owc-c-text) !important;
            font-size: 13px !important;
            font-weight: 700 !important;
        }
        .owc-auth-forms-grid .owc-rc-desc {
            color: var(--owc-c-text-dim) !important;
            font-size: 11.5px !important;
        }

        .owc-auth-footer {
            text-align: center;
            font-size: 12px;
            color: var(--owc-c-text-dim);
            margin-top: 24px;
        }
        .owc-auth-footer a { color: var(--owc-c-pri); text-decoration: none; font-weight: 600; }
        .owc-auth-footer a:hover { text-decoration: underline; }

        /* ── WooCommerce Notificaciones & Alertas ── */
        .woocommerce-notices-wrapper .woocommerce-error,
        .woocommerce-notices-wrapper .woocommerce-message,
        .woocommerce-notices-wrapper .woocommerce-info {
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 12px !important;
            color: var(--owc-c-text) !important;
            padding: 14px 20px !important;
            margin: 16px 0 !important;
            font-size: 13.5px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important;
            list-style: none !important;
        }
        .woocommerce-notices-wrapper .woocommerce-error {
            border-left: 5px solid #ef4444 !important;
        }
        .woocommerce-notices-wrapper .woocommerce-message {
            border-left: 5px solid var(--owc-c-pri) !important;
        }
        .woocommerce-notices-wrapper .woocommerce-info {
            border-left: 5px solid var(--owc-c-acc) !important;
        }

        /* ── Mi Cuenta: Navegación por Pestañas ── */
        .woocommerce-account .woocommerce-MyAccount-navigation {
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li a {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 8px;
            color: var(--owc-c-text-dim);
            text-decoration: none;
            font-weight: 600;
            font-size: 13.5px;
            transition: all 0.2s ease;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li:hover a {
            background: var(--owc-c-surface-2);
            color: var(--owc-c-text);
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li.is-active a {
            background: var(--owc-c-pri-dim);
            color: var(--owc-c-pri);
            border: 1px solid var(--owc-c-border);
            font-weight: 700;
        }

        /* ── Mi Cuenta: Tablas de Pedidos y Detalles ── */
        .woocommerce-account .woocommerce-MyAccount-content table.shop_table {
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 12px;
            overflow: hidden;
            background: var(--owc-c-surface) !important;
            color: var(--owc-c-text) !important;
            width: 100% !important;
            margin: 16px 0 !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content table.shop_table th {
            background: var(--owc-c-surface-2) !important;
            color: var(--owc-c-text) !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            padding: 14px 16px !important;
            border-bottom: 1px solid var(--owc-c-border) !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content table.shop_table td {
            padding: 14px 16px !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            font-size: 13.5px !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content table.shop_table tr:last-child td {
            border-bottom: none !important;
        }

        /* Banner Dashboard Mi Cuenta */
        .owc-acc-header-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            padding: 22px 26px;
            border-radius: 14px;
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            color: var(--owc-c-text);
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .owc-acc-user-info { display: flex; align-items: center; gap: 16px; }
        .owc-acc-avatar img { border-radius: 50%; border: 2px solid var(--owc-c-pri); }
        .owc-acc-role-pill {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 20px;
            margin-bottom: 3px;
        }
        .owc-acc-role-pill.is-provider {
            background: var(--owc-c-pri-dim);
            color: var(--owc-c-pri);
            border: 1px solid var(--owc-c-border);
        }
        .owc-acc-role-pill.is-customer {
            background: rgba(255,255,255,0.08);
            color: var(--owc-c-text);
            border: 1px solid var(--owc-c-border);
        }
        .owc-acc-name { font-size: 20px; font-weight: 800; color: var(--owc-c-text); margin: 0 0 3px 0; }
        .owc-acc-meta { font-size: 12.5px; color: var(--owc-c-text-dim); display: flex; gap: 12px; flex-wrap: wrap; }
        .owc-acc-stats-row { display: flex; gap: 12px; }
        .owc-acc-stat-box {
            padding: 10px 16px;
            border-radius: 10px;
            background: var(--owc-c-surface-2);
            border: 1px solid var(--owc-c-border);
            text-align: center;
            color: var(--owc-c-pri);
        }
        .owc-acc-stat-box .stat-num { font-size: 16px; font-weight: 800; display: block; }
        .owc-acc-stat-box .stat-lbl { font-size: 10.5px; color: var(--owc-c-text-dim); }

        /* ── Encabezado Moderno para Sub-Apartados (Pedidos, Editar Cuenta, Direcciones) ── */
        .owc-endpoint-header {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 22px 26px;
            border-radius: 16px;
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            color: var(--owc-c-text);
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .owc-endpoint-icon {
            font-size: 30px;
            line-height: 1;
            flex-shrink: 0;
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--owc-c-surface-2);
            border: 1px solid var(--owc-c-border);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .owc-endpoint-title {
            font-size: 22px !important;
            font-weight: 800 !important;
            color: var(--owc-c-text) !important;
            margin: 0 0 4px 0 !important;
        }
        .owc-endpoint-desc {
            font-size: 13.5px !important;
            color: var(--owc-c-text-dim) !important;
            margin: 0 !important;
            line-height: 1.4 !important;
        }

        /* ── Layout Global en 2 Columnas para Mi Cuenta ── */
        body.woocommerce-account .site-main,
        body.woocommerce-account .page-content,
        body.woocommerce-account .entry-content,
        body.woocommerce-account .elementor-widget-container {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        .woocommerce-account .woocommerce {
            width: 100% !important;
            max-width: 1200px !important;
            margin: 24px auto 60px auto !important;
            padding: 0 16px !important;
            box-sizing: border-box !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce {
            display: block !important;
            max-width: 100% !important;
            margin: 0 auto !important;
            padding: 0 !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper {
            max-width: 620px !important;
            margin: 38px auto 0 auto !important;
            padding: 0 16px !important;
            box-sizing: border-box !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-message,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-info,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-error {
            display: flex !important;
            align-items: flex-start !important;
            gap: 14px !important;
            width: 100% !important;
            margin: 0 auto 18px auto !important;
            padding: 18px 20px 18px 18px !important;
            border: 1px solid var(--owc-c-border) !important;
            border-left: none !important;
            border-radius: 16px !important;
            background: var(--owc-c-surface) !important;
            color: var(--owc-c-text) !important;
            box-shadow: 0 18px 48px rgba(0, 47, 108, 0.13), 0 0 0 1px rgba(255,255,255,0.7) !important;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            font-size: 14px !important;
            line-height: 1.55 !important;
            font-weight: 500 !important;
            box-sizing: border-box !important;
            position: relative !important;
            overflow: hidden !important;
            list-style: none !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-message::before,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-info::before,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-error::before {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: static !important;
            width: 28px !important;
            height: 28px !important;
            min-width: 28px !important;
            margin: 0 !important;
            border-radius: 999px !important;
            background: rgba(16, 185, 129, 0.12) !important;
            color: #059669 !important;
            font-size: 15px !important;
            font-weight: 900 !important;
            line-height: 1 !important;
            content: '✓' !important;
            top: auto !important;
            left: auto !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-info::before {
            background: var(--owc-c-pri-dim) !important;
            color: var(--owc-c-pri) !important;
            content: 'i' !important;
            font-family: inherit !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-error::before {
            background: rgba(239, 68, 68, 0.12) !important;
            color: #dc2626 !important;
            content: '!' !important;
            font-family: inherit !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-message::after,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-info::after,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper .woocommerce-error::after {
            content: '' !important;
            position: absolute !important;
            inset: 0 0 auto 0 !important;
            height: 4px !important;
            background: linear-gradient(90deg, #10b981, var(--owc-c-pri)) !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper + p,
        body.woocommerce-account:not(.logged-in) .woocommerce .woocommerce-notices-wrapper ~ p {
            width: min(100%, 620px) !important;
            margin: 18px auto 72px auto !important;
            padding: 24px 26px !important;
            border-radius: 16px !important;
            border: 1px solid var(--owc-c-border) !important;
            background: var(--owc-c-surface) !important;
            color: var(--owc-c-text-dim) !important;
            box-shadow: 0 14px 40px rgba(0, 47, 108, 0.10) !important;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            font-size: 14px !important;
            line-height: 1.7 !important;
            text-align: center !important;
            box-sizing: border-box !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password {
            width: min(100%, 520px) !important;
            margin: 42px auto 72px auto !important;
            padding: 34px 36px 32px !important;
            border-radius: 20px !important;
            border: 1px solid var(--owc-c-border) !important;
            background: var(--owc-c-surface) !important;
            color: var(--owc-c-text) !important;
            box-shadow: 0 24px 70px rgba(0, 47, 108, 0.16), 0 0 0 1px rgba(255,255,255,0.65) !important;
            box-sizing: border-box !important;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            position: relative !important;
            overflow: hidden !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword::before,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password::before {
            content: 'Recuperar acceso' !important;
            display: block !important;
            color: var(--owc-c-text) !important;
            font-size: 24px !important;
            font-weight: 800 !important;
            line-height: 1.15 !important;
            text-align: center !important;
            margin-bottom: 10px !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword::after,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password::after {
            content: '' !important;
            position: absolute !important;
            inset: 0 0 auto 0 !important;
            height: 5px !important;
            background: linear-gradient(90deg, var(--owc-c-pri), var(--owc-c-acc)) !important;
            pointer-events: none !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword > p:first-child,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password > p:first-child {
            color: var(--owc-c-text-dim) !important;
            font-size: 14px !important;
            line-height: 1.65 !important;
            text-align: center !important;
            margin: 0 0 24px !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword .form-row,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password .form-row {
            width: 100% !important;
            margin: 0 0 18px !important;
            padding: 0 !important;
            float: none !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword label,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password label {
            display: block !important;
            color: var(--owc-c-text) !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            margin-bottom: 8px !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword input.input-text,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password input.input-text {
            width: 100% !important;
            height: 52px !important;
            padding: 0 16px !important;
            border-radius: 12px !important;
            border: 1px solid var(--owc-c-border) !important;
            background: var(--owc-c-surface-2) !important;
            color: var(--owc-c-text) !important;
            font-size: 14px !important;
            font-family: inherit !important;
            outline: none !important;
            box-sizing: border-box !important;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06) !important;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword input.input-text:focus,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password input.input-text:focus {
            border-color: var(--owc-c-pri) !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 4px var(--owc-c-pri-dim), inset 0 1px 2px rgba(15, 23, 42, 0.04) !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword button.button,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password button.button {
            all: unset !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            height: 52px !important;
            border-radius: 12px !important;
            background: linear-gradient(135deg, var(--owc-c-pri), var(--owc-c-acc)) !important;
            color: var(--owc-c-btn-txt, #ffffff) !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            font-family: inherit !important;
            cursor: pointer !important;
            box-shadow: 0 10px 24px var(--owc-c-pri-dim) !important;
            box-sizing: border-box !important;
            transition: transform 0.2s ease, filter 0.2s ease, box-shadow 0.2s ease !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword button.button:hover,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password button.button:hover {
            transform: translateY(-1px) !important;
            filter: brightness(1.04) !important;
            box-shadow: 0 14px 30px var(--owc-c-pri-dim) !important;
        }
        body.woocommerce-account:not(.logged-in) .woocommerce form.woocommerce-ResetPassword .clear,
        body.woocommerce-account:not(.logged-in) .woocommerce form.lost_reset_password .clear {
            display: none !important;
        }
        @media (min-width: 820px) {
            .woocommerce-account .woocommerce {
                display: grid !important;
                grid-template-columns: 280px 1fr !important;
                gap: 32px !important;
                align-items: start !important;
            }
            .woocommerce-account .woocommerce > .woocommerce-notices-wrapper {
                grid-column: 1 / -1 !important;
                width: 100% !important;
            }
            .woocommerce-account .woocommerce > .woocommerce-notices-wrapper:empty {
                display: none !important;
            }
            .woocommerce-account .woocommerce-MyAccount-navigation {
                grid-column: 1 !important;
                grid-row: 2 !important;
                width: 100% !important;
                max-width: 280px !important;
                margin-bottom: 0 !important;
                position: sticky !important;
                top: 24px !important;
            }
            .woocommerce-account .woocommerce-MyAccount-content {
                grid-column: 2 !important;
                grid-row: 2 !important;
                min-width: 0 !important;
                width: 100% !important;
                float: none !important;
            }
        }
        @media (max-width: 819px) {
            .woocommerce-account .woocommerce {
                display: block !important;
            }
            .woocommerce-account .woocommerce-MyAccount-navigation {
                width: 100% !important;
                max-width: 100% !important;
                margin-bottom: 24px !important;
            }
            .woocommerce-account .woocommerce-MyAccount-content {
                width: 100% !important;
            }
        }

        /* ── Navegación con Iconos de Pestaña ── */
        .woocommerce-account .woocommerce-MyAccount-navigation {
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            box-sizing: border-box;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation ul {
            list-style: none !important;
            padding: 0 !important;
            margin: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li a {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            padding: 12px 16px !important;
            border-radius: 10px !important;
            color: var(--owc-c-text-dim) !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            font-size: 13.5px !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
            border: 1px solid transparent !important;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li:hover a {
            background: var(--owc-c-surface-2) !important;
            color: var(--owc-c-text) !important;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation li.is-active a {
            background: var(--owc-c-pri-dim) !important;
            color: var(--owc-c-pri) !important;
            border-color: var(--owc-c-pri) !important;
            font-weight: 800 !important;
        }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--dashboard a::before { content: '🏠'; font-size: 16px; }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--orders a::before { content: '📦'; font-size: 16px; }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--downloads a::before { content: '📥'; font-size: 16px; }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--edit-address a::before { content: '📍'; font-size: 16px; }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--edit-account a::before { content: '👤'; font-size: 16px; }
        .woocommerce-account .woocommerce-MyAccount-navigation-link--customer-logout a::before { content: '🚪'; font-size: 16px; }

        /* ── Formularios en Mi Cuenta (Detalles de Cuenta, Cambio de Clave, Direcciones) ── */
        .woocommerce-account .woocommerce-MyAccount-content form.edit-account,
        .woocommerce-account .woocommerce-MyAccount-content form.woocommerce-EditAccountForm,
        .woocommerce-account .woocommerce-MyAccount-content form.woocommerce-address-fields__field-wrapper,
        .woocommerce-account .woocommerce-MyAccount-content form {
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            border-radius: 16px;
            padding: 28px;
            box-sizing: border-box;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .woocommerce-account .woocommerce-MyAccount-content fieldset {
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 12px !important;
            padding: 20px 22px !important;
            margin: 22px 0 !important;
            background: var(--owc-c-surface-2) !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content legend {
            color: var(--owc-c-text) !important;
            font-size: 15px !important;
            font-weight: 800 !important;
            padding: 0 10px !important;
            border: none !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content .form-row {
            margin-bottom: 16px !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content label {
            color: var(--owc-c-text) !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            margin-bottom: 6px !important;
            display: block !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content input[type="text"],
        .woocommerce-account .woocommerce-MyAccount-content input[type="email"],
        .woocommerce-account .woocommerce-MyAccount-content input[type="password"],
        .woocommerce-account .woocommerce-MyAccount-content input[type="tel"],
        .woocommerce-account .woocommerce-MyAccount-content select,
        .woocommerce-account .woocommerce-MyAccount-content textarea {
            width: 100% !important;
            height: 48px !important;
            line-height: 48px !important;
            padding: 0 16px !important;
            border-radius: 10px !important;
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
            color: var(--owc-c-text) !important;
            font-size: 13.5px !important;
            box-sizing: border-box !important;
            outline: none !important;
            transition: all 0.2s ease !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content fieldset input[type="password"] {
            background: var(--owc-c-surface) !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content input:focus,
        .woocommerce-account .woocommerce-MyAccount-content select:focus,
        .woocommerce-account .woocommerce-MyAccount-content textarea:focus {
            border-color: var(--owc-c-pri) !important;
            box-shadow: 0 0 0 3px var(--owc-c-pri-dim) !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content em {
            font-size: 11.5px !important;
            color: var(--owc-c-text-dim) !important;
            display: block !important;
            margin-top: 4px !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content button[type="submit"],
        .woocommerce-account .woocommerce-MyAccount-content button.button,
        .woocommerce-account .woocommerce-MyAccount-content a.button {
            background: var(--owc-c-pri) !important;
            color: var(--owc-c-btn-txt, #0a0f1a) !important;
            border-radius: 10px !important;
            padding: 12px 26px !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            border: none !important;
            cursor: pointer !important;
            box-shadow: 0 4px 14px var(--owc-c-pri-dim) !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: all 0.2s ease !important;
            line-height: normal !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content button[type="submit"]:hover,
        .woocommerce-account .woocommerce-MyAccount-content a.button:hover {
            transform: translateY(-2px) !important;
            filter: brightness(1.1) !important;
        }

        /* ── Avisos & Notificaciones en Mi Cuenta ── */
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-message,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-error {
            background: var(--owc-c-surface) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 14px !important;
            color: var(--owc-c-text) !important;
            padding: 18px 24px !important;
            margin-bottom: 22px !important;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            flex-wrap: wrap !important;
            gap: 16px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            line-height: 1.5 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info::before,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-message::before,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-error::before {
            display: none !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info a.button,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info a.woocommerce-Button,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-message a.button {
            background: var(--owc-c-pri) !important;
            color: var(--owc-c-btn-txt, #0a0f1a) !important;
            border-radius: 10px !important;
            padding: 11px 22px !important;
            font-size: 13.5px !important;
            font-weight: 800 !important;
            border: none !important;
            box-shadow: 0 4px 14px var(--owc-c-pri-dim) !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
            margin: 0 !important;
            float: none !important;
            display: inline-block !important;
        }
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info a.button:hover,
        .woocommerce-account .woocommerce-MyAccount-content .woocommerce-info a.woocommerce-Button:hover {
            transform: translateY(-2px) !important;
            filter: brightness(1.1) !important;
        }

        @media (max-width: 820px) {
            .owc-layout-split_screen .owc-auth-shell { grid-template-columns: 1fr !important; }
            .owc-layout-side_by_side .owc-auth-forms-grid .u-columns.col2-set { grid-template-columns: 1fr !important; }
            .owc-hero-side { border-right: none !important; border-bottom: 1px solid var(--owc-c-border) !important; padding: 32px 24px !important; }
            .owc-forms-side { padding: 28px 24px !important; }
            .owc-auth-portal { margin: 16px calc(50% - 50vw) 40px calc(50% - 50vw) !important; }
            .owc-acc-header-banner { flex-direction: column; align-items: flex-start; }
            .owc-acc-stats-row { width: 100%; justify-content: space-between; }
            .owc-hero-title { font-size: 24px !important; }
            .owc-auth-main-title { font-size: 20px !important; }
        }

        /* ════════════════════════════════════════════════════════════════════
           MODIFICADORES POR TIPO DE TEMA (Design Archetypes)
           ════════════════════════════════════════════════════════════════════ */
        /* Theme: Cyber Glassmorphism */
        body.owc-theme-cyber_glass .owc-auth-shell,
        body.owc-theme-cyber_glass .woocommerce-cart table.shop_table.cart,
        body.owc-theme-cyber_glass .woocommerce-cart .cart-collaterals .cart_totals {
            backdrop-filter: blur(20px) saturate(1.3);
            -webkit-backdrop-filter: blur(20px) saturate(1.3);
            box-shadow:
                0 25px 60px rgba(0,0,0,0.65),
                0 0 40px -5px var(--owc-c-pri-dim),
                inset 0 1px 0 rgba(255,255,255,0.04);
        }
        body.owc-theme-cyber_glass .owc-auth-shell::after {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--owc-c-pri), transparent);
            opacity: 0.4;
            pointer-events: none;
        }

        /* Theme: Minimalist Clean Light */
        body.owc-theme-minimal_light {
            background-color: var(--owc-c-bg) !important;
        }
        body.owc-theme-minimal_light .owc-auth-shell,
        body.owc-theme-minimal_light .woocommerce-cart table.shop_table.cart,
        body.owc-theme-minimal_light .woocommerce-cart .cart-collaterals .cart_totals {
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            border: 1px solid var(--owc-c-border);
        }

        /* Theme: Executive Slate Corporate */
        body.owc-theme-corp_slate .owc-auth-shell,
        body.owc-theme-corp_slate .woocommerce-cart table.shop_table.cart,
        body.owc-theme-corp_slate .woocommerce-cart .cart-collaterals .cart_totals {
            border-radius: 12px;
            box-shadow: 0 12px 35px rgba(15,23,42,0.45);
        }

        /* Theme: Luxury Noir & Gold */
        body.owc-theme-luxury_noir .owc-auth-shell,
        body.owc-theme-luxury_noir .woocommerce-cart table.shop_table.cart,
        body.owc-theme-luxury_noir .woocommerce-cart .cart-collaterals .cart_totals {
            box-shadow: 0 25px 60px rgba(0,0,0,0.8), 0 0 20px rgba(245, 158, 11, 0.1);
            border-color: var(--owc-c-border);
        }

        /* ════════════════════════════════════════════════════════════════════
           OVERRIDE INTEGRAL PARA TEMAS Y PALETAS CLARAS
           ════════════════════════════════════════════════════════════════════ */
        body.owc-light-palette,
        .owc-auth-portal.owc-light-palette {
            color-scheme: light;
        }
        body.owc-light-palette .owc-auth-shell,
        .owc-auth-portal.owc-light-palette .owc-auth-shell,
        body.owc-light-palette .woocommerce-cart table.shop_table.cart,
        body.owc-light-palette .woocommerce-cart .cart-collaterals .cart_totals,
        body.owc-light-palette .woocommerce-checkout #order_review,
        body.owc-light-palette .woocommerce-checkout #customer_details .col-1,
        body.owc-light-palette .woocommerce-checkout #customer_details .col-2,
        body.owc-light-palette .woocommerce-account .woocommerce-MyAccount-navigation ul,
        body.owc-light-palette .woocommerce-account .woocommerce-MyAccount-content {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), 0 1px 3px rgba(0, 0, 0, 0.04) !important;
            border-color: var(--owc-c-border) !important;
        }
        body.owc-light-palette .owc-hero-side,
        .owc-auth-portal.owc-light-palette .owc-hero-side {
            background: linear-gradient(155deg, #061933 0%, #002e6b 50%, #0047ba 100%) !important;
            color: #ffffff !important;
            border-right: 1px solid rgba(0, 71, 186, 0.25) !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hero-title,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hero-title {
            color: #ffffff !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hero-desc,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hero-desc {
            color: #cbd5e1 !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hero-badge,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hero-badge {
            background: rgba(243, 112, 33, 0.18) !important;
            border: 1px solid rgba(243, 112, 33, 0.5) !important;
            color: #ffaa6b !important;
            font-weight: 800 !important;
        }
        body.owc-light-palette .owc-hero-side .owc-live-dot,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-live-dot {
            background: #f37021 !important;
            box-shadow: 0 0 8px #f37021, 0 0 16px rgba(243, 112, 33, 0.6) !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hf-icon,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hf-icon {
            background: rgba(255, 255, 255, 0.08) !important;
            border-color: rgba(255, 255, 255, 0.16) !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hf-item:hover .owc-hf-icon,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hf-item:hover .owc-hf-icon {
            background: rgba(243, 112, 33, 0.25) !important;
            border-color: #f37021 !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hf-item strong,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hf-item strong {
            color: #ffffff !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hf-item span,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hf-item span {
            color: #cbd5e1 !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hero-footer-tag,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hero-footer-tag {
            border-top: 1px solid rgba(255, 255, 255, 0.15) !important;
            color: #94a3b8 !important;
        }
        body.owc-light-palette .owc-hero-side .owc-hero-footer-tag strong,
        .owc-auth-portal.owc-light-palette .owc-hero-side .owc-hero-footer-tag strong {
            color: #ff944d !important;
        }

        /* Formulario de Alta Definición y Contraste Profesional */
        body.owc-light-palette .owc-forms-side,
        .owc-auth-portal.owc-light-palette .owc-forms-side {
            background: #ffffff !important;
        }
        body.owc-light-palette .owc-auth-main-title,
        .owc-auth-portal.owc-light-palette .owc-auth-main-title {
            color: #0f172a !important;
        }
        body.owc-light-palette .owc-auth-sub-title,
        .owc-auth-portal.owc-light-palette .owc-auth-sub-title {
            color: #475569 !important;
        }
        body.owc-light-palette .owc-auth-forms-grid label,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid label {
            color: #1e293b !important;
            font-weight: 700 !important;
            font-size: 13.5px !important;
        }
        body.owc-light-palette .owc-auth-forms-grid label .required,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid label .required {
            color: #f37021 !important;
        }
        body.owc-light-palette .owc-auth-forms-grid input.input-text,
        body.owc-light-palette .owc-auth-forms-grid input[type="text"],
        body.owc-light-palette .owc-auth-forms-grid input[type="email"],
        body.owc-light-palette .owc-auth-forms-grid input[type="password"],
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input.input-text,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="text"],
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="email"],
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="password"] {
            background: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            color: #0f172a !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;
        }
        body.owc-light-palette .owc-auth-forms-grid input.input-text:focus,
        body.owc-light-palette .owc-auth-forms-grid input[type="text"]:focus,
        body.owc-light-palette .owc-auth-forms-grid input[type="email"]:focus,
        body.owc-light-palette .owc-auth-forms-grid input[type="password"]:focus,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input.input-text:focus,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="text"]:focus,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="email"]:focus,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid input[type="password"]:focus {
            background: #ffffff !important;
            border-color: #0047ba !important;
            box-shadow: 0 0 0 3.5px rgba(0, 71, 186, 0.15), 0 1px 2px rgba(0,0,0,0.05) !important;
        }
        body.owc-light-palette .owc-auth-forms-grid button[type="submit"],
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid button[type="submit"] {
            background: linear-gradient(135deg, #0047ba 0%, #002e6b 100%) !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 800 !important;
            box-shadow: 0 6px 20px rgba(0, 71, 186, 0.32) !important;
            transition: all 0.25s ease !important;
        }
        body.owc-light-palette .owc-auth-forms-grid button[type="submit"]:hover,
        .owc-auth-portal.owc-light-palette .owc-auth-forms-grid button[type="submit"]:hover {
            background: linear-gradient(135deg, #f37021 0%, #d85c0e 100%) !important;
            box-shadow: 0 8px 25px rgba(243, 112, 33, 0.42) !important;
            transform: translateY(-2px) !important;
        }
        body.owc-light-palette .lost_password a,
        .owc-auth-portal.owc-light-palette .lost_password a {
            color: #0047ba !important;
            font-weight: 700 !important;
        }
        body.owc-light-palette .lost_password a:hover,
        .owc-auth-portal.owc-light-palette .lost_password a:hover {
            color: #f37021 !important;
        }
        body.owc-light-palette .owc-auth-tabs {
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
        }
        body.owc-light-palette .owc-auth-tabs button.owc-auth-tab:hover,
        .owc-auth-portal.owc-light-palette .owc-auth-tabs button.owc-auth-tab:hover {
            background: rgba(0, 0, 0, 0.05) !important;
            color: var(--owc-c-text) !important;
        }
        body.owc-light-palette .owc-acc-role-pill.is-customer {
            background: rgba(0, 0, 0, 0.06) !important;
            color: var(--owc-c-text) !important;
            border: 1px solid var(--owc-c-border) !important;
        }
        body.owc-light-palette .woocommerce-cart table.shop_table.cart td,
        body.owc-light-palette .woocommerce-account .woocommerce-MyAccount-content table.shop_table td {
            border-bottom: 1px solid var(--owc-c-border) !important;
        }
        body.owc-light-palette .woocommerce-cart .cart-collaterals .cart_totals table.shop_table th,
        body.owc-light-palette .woocommerce-cart .cart-collaterals .cart_totals table.shop_table td {
            border-bottom: 1px dashed var(--owc-c-border) !important;
        }

        /* ════════════════════════════════════════════════════════════════════
           CARRITO DE COMPRAS MODERNO (WooCommerce Cart)
           ════════════════════════════════════════════════════════════════════ */
        .woocommerce-cart .woocommerce {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, sans-serif;
            color: var(--owc-c-text);
            max-width: 1200px;
            margin: 20px auto;
        }

        /* Disposición en 2 Columnas (Modern Split Layout) */
        @media (min-width: 992px) {
            .owc-cart-split-layout.woocommerce-cart .woocommerce {
                display: grid;
                grid-template-columns: 1.6fr 1fr;
                gap: 32px;
                align-items: start;
            }
            .owc-cart-split-layout.woocommerce-cart .woocommerce .woocommerce-notices-wrapper {
                grid-column: 1 / -1;
            }
            .owc-cart-split-layout.woocommerce-cart .woocommerce form.woocommerce-cart-form {
                grid-column: 1;
            }
            .owc-cart-split-layout.woocommerce-cart .woocommerce .cart-collaterals {
                grid-column: 2;
                margin-top: 0 !important;
                position: sticky;
                top: 24px;
            }
        }

        /* Tabla del Carrito */
        .woocommerce-cart table.shop_table.cart {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 16px !important;
            overflow: hidden !important;
            background: var(--owc-c-surface) !important;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2) !important;
            margin-bottom: 24px !important;
            width: 100% !important;
        }
        .woocommerce-cart table.shop_table.cart th {
            background: var(--owc-c-surface-2) !important;
            color: var(--owc-c-text) !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            padding: 16px !important;
            border-bottom: 1px solid var(--owc-c-border) !important;
        }
        .woocommerce-cart table.shop_table.cart td {
            padding: 16px !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            vertical-align: middle !important;
            font-size: 14px !important;
            color: var(--owc-c-text) !important;
        }
        .woocommerce-cart table.shop_table.cart tr:last-child td {
            border-bottom: none !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-thumbnail img {
            width: 68px !important;
            height: 68px !important;
            object-fit: cover !important;
            border-radius: 10px !important;
            border: 1px solid var(--owc-c-border) !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-name a {
            color: var(--owc-c-text) !important;
            font-weight: 700 !important;
            font-size: 14.5px !important;
            text-decoration: none !important;
            transition: color 0.2s ease !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-name a:hover {
            color: var(--owc-c-pri) !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-price,
        .woocommerce-cart table.shop_table.cart td.product-subtotal {
            font-weight: 800 !important;
            color: var(--owc-c-pri) !important;
            font-size: 15px !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-remove a.remove {
            color: #ef4444 !important;
            background: rgba(239,68,68,0.1) !important;
            width: 28px !important;
            height: 28px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            text-decoration: none !important;
            font-size: 18px !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            transition: all 0.2s ease !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-remove a.remove:hover {
            background: #ef4444 !important;
            color: #fff !important;
            transform: scale(1.1) !important;
        }

        /* Controles de Cantidad */
        .woocommerce-cart .quantity input.qty,
        .woocommerce-checkout .quantity input.qty {
            width: 60px !important;
            height: 38px !important;
            padding: 0 8px !important;
            text-align: center !important;
            border-radius: 8px !important;
            background: var(--owc-c-surface-2) !important;
            border: 1px solid var(--owc-c-border) !important;
            color: var(--owc-c-text) !important;
            font-weight: 700 !important;
            font-size: 14px !important;
        }

        /* Acciones de Carrito: Cupón y Botón Actualizar */
        .woocommerce-cart td.actions {
            padding: 20px 16px !important;
            background: var(--owc-c-surface-2) !important;
        }
        .woocommerce-cart .coupon {
            display: inline-flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        .woocommerce-cart .coupon input.input-text {
            height: 42px !important;
            padding: 0 14px !important;
            border-radius: 8px !important;
            background: var(--owc-c-bg) !important;
            border: 1px solid var(--owc-c-border) !important;
            color: var(--owc-c-text) !important;
            font-size: 13px !important;
            min-width: 170px !important;
        }
        .woocommerce-cart .coupon button.button,
        .woocommerce-cart td.actions button[name="update_cart"] {
            height: 42px !important;
            padding: 0 18px !important;
            border-radius: 8px !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            background: var(--owc-c-surface) !important;
            color: var(--owc-c-text) !important;
            border: 1px solid var(--owc-c-border) !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        .woocommerce-cart .coupon button.button:hover,
        .woocommerce-cart td.actions button[name="update_cart"]:hover {
            border-color: var(--owc-c-pri) !important;
            color: var(--owc-c-pri) !important;
            background: var(--owc-c-pri-dim) !important;
        }

        /* Totales del Carrito (Cart Collaterals) */
        .woocommerce-cart .cart-collaterals .cart_totals {
            background: var(--owc-c-surface) !important;
            border: 1px solid var(--owc-c-border) !important;
            border-radius: 16px !important;
            padding: 26px !important;
            box-shadow: 0 12px 36px rgba(0,0,0,0.25) !important;
            width: 100% !important;
            float: none !important;
            box-sizing: border-box !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals h2 {
            font-size: 18px !important;
            font-weight: 800 !important;
            color: var(--owc-c-text) !important;
            margin: 0 0 16px 0 !important;
            padding-bottom: 12px !important;
            border-bottom: 1px solid var(--owc-c-border) !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table {
            border: none !important;
            background: transparent !important;
            width: 100% !important;
            margin-bottom: 20px !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table th,
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table td {
            background: transparent !important;
            padding: 10px 0 !important;
            border: none !important;
            border-bottom: 1px dashed rgba(255,255,255,0.08) !important;
            font-size: 14px !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table th {
            font-weight: 600 !important;
            color: var(--owc-c-text-dim) !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table td {
            text-align: right !important;
            font-weight: 700 !important;
            color: var(--owc-c-text) !important;
        }
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table tr.order-total th,
        .woocommerce-cart .cart-collaterals .cart_totals table.shop_table tr.order-total td {
            font-size: 18px !important;
            font-weight: 800 !important;
            color: var(--owc-c-pri) !important;
            border-bottom: none !important;
            padding-top: 14px !important;
        }
        .woocommerce-cart .wc-proceed-to-checkout a.checkout-button {
            display: block !important;
            width: 100% !important;
            padding: 15px !important;
            font-size: 15px !important;
            font-weight: 800 !important;
            text-align: center !important;
            text-decoration: none !important;
            border-radius: 10px !important;
            background: var(--owc-c-pri) !important;
            color: var(--owc-c-btn-txt, #0a0f1a) !important;
            border: none !important;
            box-shadow: 0 4px 18px var(--owc-c-pri-dim) !important;
            transition: all 0.25s ease !important;
            cursor: pointer !important;
        }
        .woocommerce-cart .wc-proceed-to-checkout a.checkout-button:hover {
            transform: translateY(-2px) !important;
            filter: brightness(1.1) !important;
            box-shadow: 0 6px 24px var(--owc-c-pri-dim) !important;
        }
        .owc-cart-b2b-assurance {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--owc-c-text-dim);
            margin-top: 14px;
            text-align: center;
        }
        .owc-cart-b2b-assurance strong { color: var(--owc-c-pri); }

        /* Carrito Vacío */
        .woocommerce-cart .cart-empty,
        .woocommerce-cart p.cart-empty {
            background: var(--owc-c-surface);
            border: 1px solid var(--owc-c-border);
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--owc-c-text);
            margin: 30px auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .woocommerce-cart .return-to-shop {
            text-align: center;
            margin-bottom: 40px;
        }
        .woocommerce-cart .return-to-shop a.button {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 8px;
            background: var(--owc-c-pri);
            color: var(--owc-c-btn-txt, #0a0f1a);
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 4px 14px var(--owc-c-pri-dim);
            transition: all 0.25s ease;
        }

        /* Carrito responsive: producto en tarjeta, quitar X de la columna de imagen */
        .woocommerce-cart table.shop_table.cart td.product-remove {
            width: 44px !important;
            text-align: center !important;
        }
        .woocommerce-cart table.shop_table.cart td.product-thumbnail {
            width: 88px !important;
            text-align: center !important;
        }
        @media (max-width: 900px) {
            body.woocommerce-cart .woocommerce {
                padding: 0 14px !important;
                margin: 16px auto 48px !important;
            }
            body.woocommerce-cart table.shop_table.cart {
                display: block !important;
                border: 0 !important;
                border-radius: 0 !important;
                background: transparent !important;
                box-shadow: none !important;
                overflow: visible !important;
            }
            body.woocommerce-cart table.shop_table.cart thead {
                display: none !important;
            }
            body.woocommerce-cart table.shop_table.cart tbody,
            body.woocommerce-cart table.shop_table.cart tr,
            body.woocommerce-cart table.shop_table.cart td {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
            body.woocommerce-cart table.shop_table.cart tr.cart_item {
                position: relative !important;
                display: grid !important;
                grid-template-columns: 82px minmax(0, 1fr) !important;
                gap: 10px 14px !important;
                padding: 18px 16px !important;
                margin: 0 0 16px !important;
                background: #ffffff !important;
                border: 1px solid #d7dee8 !important;
                border-radius: 12px !important;
                box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08) !important;
            }
            body.woocommerce-cart table.shop_table.cart td {
                padding: 0 !important;
                border: 0 !important;
                background: transparent !important;
                color: #0f172a !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-remove {
                position: absolute !important;
                top: 10px !important;
                right: 10px !important;
                width: 30px !important;
                z-index: 2 !important;
                text-align: right !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-remove a.remove {
                width: 26px !important;
                height: 26px !important;
                margin: 0 !important;
                font-size: 16px !important;
                background: #fee2e2 !important;
                color: #dc2626 !important;
                transform: none !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-thumbnail {
                grid-row: 1 / span 4 !important;
                grid-column: 1 !important;
                width: 82px !important;
                min-height: 82px !important;
                display: flex !important;
                align-items: flex-start !important;
                justify-content: center !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-thumbnail::before,
            body.woocommerce-cart table.shop_table.cart td.product-remove::before {
                content: none !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-thumbnail img {
                width: 74px !important;
                height: 74px !important;
                object-fit: contain !important;
                background: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-name,
            body.woocommerce-cart table.shop_table.cart td.product-price,
            body.woocommerce-cart table.shop_table.cart td.product-quantity,
            body.woocommerce-cart table.shop_table.cart td.product-subtotal {
                grid-column: 2 !important;
                padding-right: 34px !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-name a {
                display: block !important;
                color: #0f172a !important;
                font-size: 14px !important;
                line-height: 1.25 !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-price,
            body.woocommerce-cart table.shop_table.cart td.product-quantity,
            body.woocommerce-cart table.shop_table.cart td.product-subtotal {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 12px !important;
                padding-top: 8px !important;
                border-top: 1px solid #eef2f7 !important;
                font-size: 13px !important;
            }
            body.woocommerce-cart table.shop_table.cart td.product-price::before,
            body.woocommerce-cart table.shop_table.cart td.product-quantity::before,
            body.woocommerce-cart table.shop_table.cart td.product-subtotal::before {
                content: attr(data-title) !important;
                color: #64748b !important;
                font-weight: 800 !important;
                text-transform: none !important;
            }
            body.woocommerce-cart table.shop_table.cart td.actions {
                display: block !important;
                padding: 16px !important;
                background: #ffffff !important;
                border: 1px solid #d7dee8 !important;
                border-radius: 12px !important;
                box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08) !important;
            }
            body.woocommerce-cart .coupon {
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 10px !important;
                width: 100% !important;
                margin-bottom: 10px !important;
            }
            body.woocommerce-cart .coupon input.input-text,
            body.woocommerce-cart .coupon button.button,
            body.woocommerce-cart td.actions button[name="update_cart"] {
                width: 100% !important;
                min-width: 0 !important;
            }
            body.woocommerce-cart .cart-collaterals .cart_totals {
                padding: 22px !important;
                border-radius: 12px !important;
            }
        }

        /* ════════════════════════════════════════════════════════════════════
           CHECKOUT CORPORATIVO ESTABLE (WooCommerce Checkout + Mini Cart)
           ════════════════════════════════════════════════════════════════════ */
        body.woocommerce-checkout,
        body.woocommerce-checkout .site,
        body.woocommerce-checkout .content-area,
        body.woocommerce-checkout #content,
        body.woocommerce-checkout .site-content,
        body.woocommerce-checkout .entry-content {
            background: #f4f6fa !important;
            color: #0f172a !important;
        }
        body.woocommerce-checkout .woocommerce {
            max-width: 1180px !important;
            margin: 24px auto 72px !important;
            padding: 0 18px !important;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }
        body.woocommerce-checkout h1,
        body.woocommerce-checkout .entry-title {
            color: #0047ba !important;
            font-size: clamp(22px, 2.2vw, 28px) !important;
            font-weight: 900 !important;
            letter-spacing: -0.03em !important;
            margin: 0 0 22px !important;
            padding-bottom: 12px !important;
            border-bottom: 2px solid #0047ba !important;
        }
        body.woocommerce-checkout .woocommerce-info,
        body.woocommerce-checkout .woocommerce-message,
        body.woocommerce-checkout .woocommerce-error {
            max-width: 1180px !important;
            margin: 0 auto 18px !important;
            padding: 14px 18px !important;
            background: #ffffff !important;
            border: 1px solid #d7dee8 !important;
            border-left: 4px solid #0047ba !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06) !important;
            color: #334155 !important;
            font-size: 13px !important;
        }
        body.woocommerce-checkout form.checkout {
            width: 100% !important;
            max-width: 1180px !important;
            margin: 0 auto !important;
            color: #0f172a !important;
        }
        @media (min-width: 992px) {
            body.owc-checkout-styled.woocommerce-checkout form.checkout {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) minmax(390px, 420px) !important;
                grid-template-areas:
                    "customer order-title"
                    "customer order-review" !important;
                column-gap: 28px !important;
                row-gap: 0 !important;
                align-items: start !important;
            }
            body.owc-checkout-styled.woocommerce-checkout form.checkout #customer_details {
                grid-area: customer !important;
                min-width: 0 !important;
                width: 100% !important;
                float: none !important;
            }
            body.owc-checkout-styled.woocommerce-checkout form.checkout #order_review_heading {
                grid-area: order-title !important;
            }
            body.owc-checkout-styled.woocommerce-checkout form.checkout #order_review {
                grid-area: order-review !important;
                position: sticky !important;
                top: 18px !important;
                align-self: start !important;
            }
        }
        body.woocommerce-checkout #customer_details,
        body.woocommerce-checkout #customer_details .col-1,
        body.woocommerce-checkout #customer_details .col-2,
        body.woocommerce-checkout #order_review_heading,
        body.woocommerce-checkout #order_review {
            box-sizing: border-box !important;
            transform: none !important;
        }
        body.woocommerce-checkout #customer_details .col-1,
        body.woocommerce-checkout #customer_details .col-2 {
            width: 100% !important;
            float: none !important;
            clear: both !important;
            margin: 0 0 24px !important;
            padding: 24px !important;
            background: #ffffff !important;
            border: 1px solid #d7dee8 !important;
            border-radius: 10px !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.07) !important;
        }
        body.woocommerce-checkout .woocommerce-billing-fields h3,
        body.woocommerce-checkout .woocommerce-additional-fields h3,
        body.woocommerce-checkout #order_review_heading {
            color: #0047ba !important;
            font-size: 15px !important;
            font-weight: 900 !important;
            letter-spacing: -0.01em !important;
            margin: 0 0 18px !important;
        }
        body.woocommerce-checkout #order_review_heading {
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 18px 18px 0 !important;
            background: #ffffff !important;
            border: 1px solid #d7dee8 !important;
            border-bottom: 0 !important;
            border-radius: 10px 10px 0 0 !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.07) !important;
        }
        body.woocommerce-checkout #order_review {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
            clear: none !important;
            margin: 0 !important;
            padding: 14px 18px 18px !important;
            background: #ffffff !important;
            border: 1px solid #d7dee8 !important;
            border-top: 0 !important;
            border-radius: 0 0 10px 10px !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.07) !important;
            overflow: visible !important;
        }
        body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper,
        body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper,
        body.woocommerce-checkout .woocommerce-additional-fields__field-wrapper {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px 16px !important;
        }
        body.woocommerce-checkout .form-row,
        body.woocommerce-checkout .form-row-first,
        body.woocommerce-checkout .form-row-last,
        body.woocommerce-checkout .form-row-wide {
            width: 100% !important;
            float: none !important;
            clear: none !important;
            margin: 0 !important;
            padding: 0 !important;
            min-width: 0 !important;
        }
        body.woocommerce-checkout .form-row-wide,
        body.woocommerce-checkout #billing_address_1_field,
        body.woocommerce-checkout #billing_address_2_field,
        body.woocommerce-checkout #billing_state_field,
        body.woocommerce-checkout #billing_city_field,
        body.woocommerce-checkout #order_comments_field {
            grid-column: 1 / -1 !important;
        }
        body.woocommerce-checkout label {
            display: block !important;
            margin: 0 0 6px !important;
            color: #334155 !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            line-height: 1.35 !important;
        }
        body.woocommerce-checkout input.input-text,
        body.woocommerce-checkout textarea,
        body.woocommerce-checkout select,
        body.woocommerce-checkout .select2-container .select2-selection--single {
            width: 100% !important;
            min-height: 44px !important;
            padding: 10px 12px !important;
            background: #f8fafc !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            color: #0f172a !important;
            font-size: 13px !important;
            line-height: 1.35 !important;
            box-shadow: none !important;
            outline: none !important;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease !important;
            transform: none !important;
            box-sizing: border-box !important;
        }
        body.woocommerce-checkout textarea {
            min-height: 92px !important;
            resize: vertical !important;
        }
        body.woocommerce-checkout input.input-text:focus,
        body.woocommerce-checkout textarea:focus,
        body.woocommerce-checkout select:focus,
        body.woocommerce-checkout .select2-container--open .select2-selection--single {
            background: #ffffff !important;
            border-color: #0047ba !important;
            box-shadow: 0 0 0 3px rgba(0, 71, 186, 0.12) !important;
        }
        body.woocommerce-checkout .select2-container,
        body.woocommerce-checkout .select2-container--default,
        body.woocommerce-checkout .select2-container .select2-selection--single,
        body.woocommerce-checkout .select2-selection__rendered {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        body.woocommerce-checkout .select2-container .select2-selection--single {
            padding: 0 !important;
        }
        body.woocommerce-checkout .select2-selection__rendered {
            padding: 10px 34px 10px 12px !important;
            color: #0f172a !important;
            line-height: 22px !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table {
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            margin: 0 0 14px !important;
            background: transparent !important;
            border: 0 !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table th,
        body.woocommerce-checkout .woocommerce-checkout-review-order-table td {
            padding: 11px 0 !important;
            border: 0 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            color: #334155 !important;
            font-size: 13px !important;
            line-height: 1.35 !important;
            vertical-align: top !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .product-name {
            width: 62% !important;
            color: #0f172a !important;
            font-weight: 800 !important;
            word-break: break-word !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .product-total,
        body.woocommerce-checkout .woocommerce-checkout-review-order-table tfoot td {
            width: 38% !important;
            text-align: right !important;
            color: #0f172a !important;
            font-weight: 900 !important;
            white-space: nowrap !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table tfoot th {
            color: #334155 !important;
            font-weight: 800 !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .order-total th,
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .order-total td,
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .order-total strong,
        body.woocommerce-checkout .woocommerce-checkout-review-order-table .order-total .amount {
            color: #0047ba !important;
            font-size: 17px !important;
            font-weight: 950 !important;
            border-bottom: 0 !important;
        }
        body.woocommerce-checkout #payment {
            margin: 14px 0 0 !important;
            padding: 16px !important;
            background: #f8fafc !important;
            border: 1px solid #d7dee8 !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            color: #334155 !important;
        }
        body.woocommerce-checkout #payment ul.payment_methods {
            padding: 0 !important;
            margin: 0 0 12px !important;
            border: 0 !important;
        }
        body.woocommerce-checkout #payment div.payment_box {
            margin: 10px 0 0 !important;
            padding: 16px !important;
            background: #ffffff !important;
            border: 1px solid #d7dee8 !important;
            border-radius: 8px !important;
            color: #475569 !important;
            font-size: 13px !important;
            line-height: 1.5 !important;
            box-shadow: 0 4px 12px rgba(15,23,42,0.03) !important;
        }
        body.woocommerce-checkout #payment div.payment_box p { margin: 0 0 10px !important; }
        body.woocommerce-checkout #payment div.payment_box p:last-child { margin-bottom: 0 !important; }
        body.woocommerce-checkout #payment div.payment_box pre,
        body.woocommerce-checkout #payment div.payment_box blockquote,
        body.woocommerce-checkout #payment div.payment_box fieldset,
        body.woocommerce-checkout #payment div.payment_box div:not(.owc-bank-grid):not(.owc-bank-line) {
            display: block !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 14px 16px !important;
            margin: 12px 0 0 !important;
            font-family: inherit !important;
            font-size: 12.5px !important;
            line-height: 1.6 !important;
            color: #1e293b !important;
            white-space: pre-wrap !important;
            word-break: break-word !important;
        }
        body.woocommerce-checkout #payment div.payment_box div p {
            margin: 0 !important;
        }
        body.woocommerce-checkout #payment #place_order,
        body.woocommerce-checkout button.button,
        body.woocommerce-checkout input.button {
            width: 100% !important;
            min-height: 50px !important;
            margin-top: 14px !important;
            background: #0047ba !important;
            color: #ffffff !important;
            border: 0 !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 24px rgba(0, 71, 186, 0.20) !important;
            font-size: 14px !important;
            font-weight: 900 !important;
            letter-spacing: -0.01em !important;
            text-transform: none !important;
            transition: background-color 0.18s ease, box-shadow 0.18s ease !important;
            transform: none !important;
        }
        body.woocommerce-checkout #payment #place_order:hover,
        body.woocommerce-checkout button.button:hover,
        body.woocommerce-checkout input.button:hover {
            background: #f37021 !important;
            color: #ffffff !important;
            box-shadow: 0 12px 28px rgba(243, 112, 33, 0.22) !important;
            transform: none !important;
        }
        @media (max-width: 991px) {
            body.owc-checkout-styled.woocommerce-checkout form.checkout {
                display: block !important;
            }
            body.woocommerce-checkout #order_review_heading {
                margin-top: 22px !important;
            }
            body.woocommerce-checkout #order_review {
                position: static !important;
            }
        }
        @media (max-width: 640px) {
            body.woocommerce-checkout .woocommerce {
                margin-top: 16px !important;
                padding: 0 12px !important;
            }
            body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper,
            body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper,
            body.woocommerce-checkout .woocommerce-additional-fields__field-wrapper {
                grid-template-columns: 1fr !important;
            }
            body.woocommerce-checkout #customer_details .col-1,
            body.woocommerce-checkout #customer_details .col-2 {
                padding: 18px !important;
            }
            body.woocommerce-checkout #order_review_heading,
            body.woocommerce-checkout #order_review {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }
        }

        /* Checkout: unir "Tu pedido" con su cuadro en una sola tarjeta */
        body.woocommerce-checkout #order_review_heading {
            display: none !important;
        }
        @media (min-width: 992px) {
            body.owc-checkout-styled.woocommerce-checkout form.checkout {
                grid-template-areas: "customer order-review" !important;
            }
        }
        body.woocommerce-checkout #order_review {
            position: relative !important;
            padding: 0 18px 18px !important;
            border: 1px solid #d7dee8 !important;
            border-radius: 12px !important;
            background: #ffffff !important;
            box-shadow: 0 12px 34px rgba(15, 23, 42, 0.08) !important;
            overflow: hidden !important;
        }
        body.woocommerce-checkout #order_review::before {
            content: 'Tu pedido' !important;
            display: block !important;
            margin: 0 -18px 14px !important;
            padding: 16px 18px !important;
            background: linear-gradient(135deg, #eef6ff 0%, #fff7f1 100%) !important;
            border-bottom: 1px solid #dbe7f7 !important;
            color: #0047ba !important;
            font-size: 15px !important;
            font-weight: 950 !important;
            letter-spacing: -0.02em !important;
        }
        body.woocommerce-checkout .woocommerce-checkout-review-order-table thead th {
            padding-top: 0 !important;
        }

        body.woocommerce-checkout .woocommerce-mini-cart,
        body.woocommerce-checkout .widget_shopping_cart_content,
        .widget_shopping_cart_content,
        .woocommerce-mini-cart {
            background: #ffffff !important;
            color: #0f172a !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart-item,
        body.woocommerce-checkout .mini_cart_item,
        .woocommerce-mini-cart-item,
        .mini_cart_item {
            color: #0f172a !important;
            border-color: #e2e8f0 !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart__total,
        body.woocommerce-checkout .woocommerce-mini-cart__buttons,
        .woocommerce-mini-cart__total,
        .woocommerce-mini-cart__buttons {
            color: #0f172a !important;
            border-color: #e2e8f0 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart {
            margin: 0 !important;
            padding: 0 !important;
            list-style: none !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item {
            position: relative !important;
            display: grid !important;
            grid-template-columns: 58px minmax(0, 1fr) !important;
            gap: 10px !important;
            align-items: start !important;
            padding: 0 28px 16px 0 !important;
            margin: 0 0 14px !important;
            border-bottom: 1px solid #e2e8f0 !important;
            min-height: 70px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item > a:not(.remove) {
            grid-column: 1 / -1 !important;
            display: grid !important;
            grid-template-columns: 58px minmax(0, 1fr) !important;
            gap: 10px !important;
            align-items: start !important;
            color: #0f172a !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            line-height: 1.25 !important;
            text-decoration: none !important;
            padding-right: 28px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item img {
            grid-column: 1 !important;
            width: 56px !important;
            height: 56px !important;
            object-fit: contain !important;
            margin: 0 !important;
            float: none !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .quantity {
            grid-column: 2 !important;
            display: block !important;
            margin-top: -6px !important;
            color: #64748b !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            line-height: 1.35 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item a.remove,
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .remove_from_cart_button {
            position: absolute !important;
            top: 18px !important;
            right: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 22px !important;
            height: 22px !important;
            margin: 0 !important;
            padding: 0 !important;
            float: none !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 50% !important;
            color: #64748b !important;
            font-size: 15px !important;
            line-height: 1 !important;
            text-decoration: none !important;
            transform: none !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item a.remove:hover,
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .remove_from_cart_button:hover {
            background: #fee2e2 !important;
            border-color: #fecaca !important;
            color: #dc2626 !important;
            transform: none !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__total {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            gap: 12px !important;
            padding: 14px 0 !important;
            margin: 0 0 14px !important;
            border-top: 0 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
            font-size: 14px !important;
            font-weight: 900 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__total .amount,
        .widget_shopping_cart_content .woocommerce-mini-cart__total strong {
            color: #0047ba !important;
            font-weight: 950 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 8px !important;
            margin: 0 !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart__buttons .button,
        body.woocommerce-checkout .widget_shopping_cart .buttons .button,
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .button,
        .widget_shopping_cart .buttons .button {
            background: #0047ba !important;
            color: #ffffff !important;
            border-radius: 6px !important;
            border: 1px solid #0047ba !important;
            box-shadow: 0 8px 18px rgba(0, 71, 186, 0.18) !important;
            transform: none !important;
            min-height: 42px !important;
            padding: 10px 12px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            line-height: 1.1 !important;
            white-space: normal !important;
            word-break: normal !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart__buttons .checkout,
        body.woocommerce-checkout .widget_shopping_cart .buttons .checkout,
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .checkout,
        .widget_shopping_cart .buttons .checkout {
            background: #f37021 !important;
            border-color: #f37021 !important;
            box-shadow: 0 8px 18px rgba(243, 112, 33, 0.20) !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart__buttons .button:hover,
        body.woocommerce-checkout .widget_shopping_cart .buttons .button:hover,
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .button:hover,
        .widget_shopping_cart .buttons .button:hover {
            background: #002e6b !important;
            border-color: #002e6b !important;
            color: #ffffff !important;
            transform: none !important;
        }
        body.woocommerce-checkout .woocommerce-mini-cart__buttons .checkout:hover,
        body.woocommerce-checkout .widget_shopping_cart .buttons .checkout:hover,
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .checkout:hover,
        .widget_shopping_cart .buttons .checkout:hover {
            background: #d95b0e !important;
            border-color: #d95b0e !important;
        }
        @media (max-width: 380px) {
            .widget_shopping_cart_content .woocommerce-mini-cart__buttons {
                grid-template-columns: 1fr !important;
            }
        }

        /* Mini-carrito/drawer: paleta corporativa pastel y profesional */
        .site-header-cart,
        .widget_shopping_cart,
        .widget_shopping_cart_content,
        .woocommerce.widget_shopping_cart,
        .ast-site-header-cart .widget_shopping_cart,
        .elementor-menu-cart__container,
        .elementor-menu-cart__main,
        .xoo-wsc-container,
        .xoo-wsc-modal .xoo-wsc-container {
            background: #ffffff !important;
            color: #102033 !important;
        }
        .widget_shopping_cart_content,
        .elementor-menu-cart__main,
        .xoo-wsc-container {
            border-left: 1px solid #dbe7f7 !important;
            box-shadow: -18px 0 44px rgba(15, 32, 51, 0.12) !important;
        }
        .widget_shopping_cart_content::before,
        .elementor-menu-cart__main::before,
        .xoo-wsc-container::before {
            content: '' !important;
            display: block !important;
            height: 4px !important;
            margin: 0 0 18px !important;
            background: linear-gradient(90deg, #0047ba 0%, #f37021 100%) !important;
            border-radius: 999px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item,
        .elementor-menu-cart__product,
        .xoo-wsc-product {
            background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%) !important;
            border: 1px solid #e1ebf8 !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 22px rgba(0, 71, 186, 0.06) !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item {
            padding: 12px 36px 12px 12px !important;
            margin-bottom: 12px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item > a:not(.remove),
        .elementor-menu-cart__product-name a,
        .xoo-wsc-product-title a {
            color: #102033 !important;
            font-weight: 850 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .quantity,
        .elementor-menu-cart__product-price,
        .xoo-wsc-qty-price {
            color: #607084 !important;
            font-weight: 750 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item img,
        .elementor-menu-cart__product-image img,
        .xoo-wsc-img-col img {
            background: #f6f9fd !important;
            border: 1px solid #dce8f6 !important;
            border-radius: 10px !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item a.remove,
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .remove_from_cart_button,
        .elementor-menu-cart__product-remove,
        .xoo-wsc-remove {
            background: #fff7f1 !important;
            border: 1px solid #ffd9c4 !important;
            color: #c84f0c !important;
            border-radius: 50% !important;
            box-shadow: 0 4px 10px rgba(243, 112, 33, 0.10) !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item a.remove:hover,
        .widget_shopping_cart_content .woocommerce-mini-cart li.mini_cart_item .remove_from_cart_button:hover,
        .elementor-menu-cart__product-remove:hover,
        .xoo-wsc-remove:hover {
            background: #f37021 !important;
            border-color: #f37021 !important;
            color: #ffffff !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__total,
        .elementor-menu-cart__subtotal,
        .xoo-wsc-ft-totals {
            background: #f8fbff !important;
            border: 1px solid #dbe7f7 !important;
            border-radius: 12px !important;
            padding: 13px 14px !important;
            color: #102033 !important;
            box-shadow: 0 8px 20px rgba(0, 71, 186, 0.05) !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__total .amount,
        .elementor-menu-cart__subtotal .amount,
        .xoo-wsc-ft-amt {
            color: #0047ba !important;
            font-weight: 950 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .button,
        .widget_shopping_cart .buttons .button,
        .elementor-menu-cart__footer-buttons .elementor-button,
        .xoo-wsc-ft-buttons-cont a {
            background: #eef6ff !important;
            border: 1px solid #cfe0fb !important;
            color: #0047ba !important;
            border-radius: 9px !important;
            box-shadow: 0 8px 18px rgba(0, 71, 186, 0.08) !important;
            font-weight: 900 !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .checkout,
        .widget_shopping_cart .buttons .checkout,
        .elementor-menu-cart__footer-buttons .elementor-button--checkout,
        .xoo-wsc-ft-buttons-cont a.xoo-wsc-ft-btn-checkout {
            background: linear-gradient(135deg, #f37021 0%, #df6418 100%) !important;
            border-color: #f37021 !important;
            color: #ffffff !important;
            box-shadow: 0 10px 22px rgba(243, 112, 33, 0.18) !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .button:hover,
        .widget_shopping_cart .buttons .button:hover,
        .elementor-menu-cart__footer-buttons .elementor-button:hover,
        .xoo-wsc-ft-buttons-cont a:hover {
            background: #0047ba !important;
            border-color: #0047ba !important;
            color: #ffffff !important;
        }
        .widget_shopping_cart_content .woocommerce-mini-cart__buttons .checkout:hover,
        .widget_shopping_cart .buttons .checkout:hover,
        .elementor-menu-cart__footer-buttons .elementor-button--checkout:hover,
        .xoo-wsc-ft-buttons-cont a.xoo-wsc-ft-btn-checkout:hover {
            background: #d95b0e !important;
            border-color: #d95b0e !important;
            color: #ffffff !important;
        }

        /* Elementor Menu Cart real: estructura inspeccionada en grupofloressa.com */
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__container.elementor-lightbox {
            background: rgba(15, 23, 42, 0.34) !important;
            backdrop-filter: blur(2px) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__main {
            width: min(390px, 92vw) !important;
            padding: 22px 18px !important;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%) !important;
            border-left: 1px solid #dbe7f7 !important;
            box-shadow: -20px 0 45px rgba(15, 32, 51, 0.16) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__main::before {
            content: '' !important;
            display: block !important;
            height: 4px !important;
            margin: -4px 0 18px !important;
            border-radius: 999px !important;
            background: linear-gradient(90deg, #0047ba 0%, #f37021 100%) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__close-button {
            top: 16px !important;
            right: 16px !important;
            color: #64748b !important;
            opacity: 1 !important;
        }
        .elementor-widget-woocommerce-menu-cart .widget_shopping_cart_content {
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
        }
        .elementor-widget-woocommerce-menu-cart .widget_shopping_cart_content::before {
            content: none !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__products {
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product {
            display: grid !important;
            grid-template-columns: 62px minmax(0, 1fr) 26px !important;
            grid-template-areas:
                "image name remove"
                "image price remove" !important;
            gap: 4px 12px !important;
            align-items: center !important;
            min-height: 82px !important;
            margin: 0 !important;
            padding: 12px !important;
            background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%) !important;
            border: 1px solid #e1ebf8 !important;
            border-radius: 14px !important;
            box-shadow: 0 8px 22px rgba(0, 71, 186, 0.06) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-image {
            grid-area: image !important;
            width: 62px !important;
            min-width: 62px !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-image a,
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-image img {
            display: block !important;
            width: 62px !important;
            height: 62px !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-image img {
            object-fit: contain !important;
            padding: 5px !important;
            background: #ffffff !important;
            border: 1px solid #dbe7f7 !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 14px rgba(15, 32, 51, 0.06) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-name {
            grid-area: name !important;
            min-width: 0 !important;
            padding: 0 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-name a {
            display: block !important;
            color: #102033 !important;
            font-size: 14px !important;
            font-weight: 850 !important;
            line-height: 1.22 !important;
            text-decoration: none !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-price {
            grid-area: price !important;
            padding: 0 !important;
            color: #607084 !important;
            font-size: 12.5px !important;
            font-weight: 750 !important;
            line-height: 1.25 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-price .amount {
            color: #0047ba !important;
            font-weight: 850 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-remove {
            grid-area: remove !important;
            position: static !important;
            width: 26px !important;
            height: 26px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            align-self: center !important;
            justify-self: end !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-remove a,
        .elementor-widget-woocommerce-menu-cart .elementor_remove_from_cart_button,
        .elementor-widget-woocommerce-menu-cart .remove_from_cart_button {
            position: relative !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 24px !important;
            height: 24px !important;
            min-width: 24px !important;
            min-height: 24px !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff7f1 !important;
            border: 1px solid #ffd9c4 !important;
            border-radius: 50% !important;
            color: #c84f0c !important;
            opacity: 1 !important;
            box-shadow: 0 4px 10px rgba(243, 112, 33, 0.10) !important;
            transform: none !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-remove a + a {
            display: none !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-remove a::before,
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__product-remove a::after {
            background: currentColor !important;
            height: 2px !important;
            width: 11px !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__subtotal {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            gap: 14px !important;
            margin: 2px 0 0 !important;
            padding: 14px 15px !important;
            background: #f3f8ff !important;
            border: 1px solid #dbe7f7 !important;
            border-radius: 13px !important;
            color: #102033 !important;
            font-size: 14px !important;
            font-weight: 900 !important;
            box-shadow: 0 8px 20px rgba(0, 71, 186, 0.05) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__subtotal .amount {
            color: #0047ba !important;
            font-size: 15px !important;
            font-weight: 950 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__footer-buttons {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__footer-buttons .elementor-button {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-height: 44px !important;
            padding: 11px 12px !important;
            background: #eef6ff !important;
            border: 1px solid #cfe0fb !important;
            border-radius: 10px !important;
            color: #0047ba !important;
            font-size: 13px !important;
            font-weight: 900 !important;
            line-height: 1.1 !important;
            text-align: center !important;
            box-shadow: 0 8px 18px rgba(0, 71, 186, 0.08) !important;
        }
        .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__footer-buttons .elementor-button--checkout {
            background: linear-gradient(135deg, #f37021 0%, #df6418 100%) !important;
            border-color: #f37021 !important;
            color: #ffffff !important;
            box-shadow: 0 10px 22px rgba(243, 112, 33, 0.18) !important;
        }
        @media (max-width: 420px) {
            .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__main {
                padding: 20px 14px !important;
            }
            .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__footer-buttons {
                grid-template-columns: 1fr !important;
            }
        }
        </style>
        <?php
    }

    /**
     * Script front-end para alternar entre pestañas Login y Registro
     */
    public static function output_template_js() {
        if ( self::$js_rendered ) return;
        self::$js_rendered = true;

        $layout = self::get_active_layout();
        if ( $layout === self::LAYOUT_DUAL ) return; // En Dual ambos formularios son visibles simultáneamente
        ?>
        <script id="owc-auth-dynamic-js">
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.owc-auth-tab');
            if (!tabs.length) return;

            const grid = document.querySelector('.owc-auth-forms-grid');
            if (!grid) return;

            // Selector resiliente para Login (columna o form directo)
            const loginEl = grid.querySelector('.u-column1') || 
                            grid.querySelector('.col-1') || 
                            grid.querySelector('form.login');

            // Selector resiliente para Registro
            const registerEl = grid.querySelector('.u-column2') || 
                               grid.querySelector('.col-2') || 
                               grid.querySelector('form.register');

            if (tabs.length && loginEl && registerEl) {
                tabs.forEach(tab => {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        tabs.forEach(t => t.classList.remove('active'));
                        this.classList.add('active');
                        const target = this.dataset.tab;

                        if (target === 'login') {
                            loginEl.style.display = 'block';
                            registerEl.style.display = 'none';
                        } else {
                            loginEl.style.display = 'none';
                            registerEl.style.display = 'block';
                        }
                    });
                });

                loginEl.style.display = 'block';
                registerEl.style.display = 'none';
            } else if (tabs.length && loginEl && !registerEl) {
                // Si el registro está inhabilitado en WooCommerce, ocultar pestañas limpiamente
                const tabsWrapper = document.querySelector('.owc-auth-tabs');
                if (tabsWrapper) tabsWrapper.style.display = 'none';
                loginEl.style.display = 'block';
            }
        });
        </script>
        <?php
    }

    /** Presentación en tarjetas para los métodos de entrega del checkout. */
    public static function render_shipping_method_ui() {
        if ( ! is_checkout() || is_order_received_page() ) return;
        ?>
        <style id="owc-shipping-method-ui-css">
        /* ── Convertir tabla de order-review a bloque para que las filas no sean limitadas por columnas ── */
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>thead,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tbody,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tfoot,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>thead>tr,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tbody>tr,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tfoot>tr{display:block!important;width:100%!important;box-sizing:border-box!important}
        /* Celdas normales: flex para que th quede a la izquierda y td a la derecha */
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tbody>tr:not(.woocommerce-shipping-totals),
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>tfoot>tr:not(.woocommerce-shipping-totals),
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table>thead>tr{display:flex!important;align-items:baseline!important;justify-content:space-between!important;width:100%!important}
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table th,
        body.woocommerce-checkout table.woocommerce-checkout-review-order-table td{display:block!important;box-sizing:border-box!important}
        /* Fila de envío: bloque completo */
        body.woocommerce-checkout tr.woocommerce-shipping-totals.shipping{display:block!important;width:100%!important}
        body.woocommerce-checkout tr.woocommerce-shipping-totals.shipping>th{display:block!important;width:100%!important;border:0!important;color:#0f172a!important;font-size:15px!important;font-weight:900!important;padding:18px 0 11px!important;text-align:left!important}
        body.woocommerce-checkout tr.woocommerce-shipping-totals.shipping>th::before{content:'🚚';display:inline-block;margin-right:7px;font-size:16px}
        body.woocommerce-checkout tr.woocommerce-shipping-totals.shipping>td{display:block!important;width:100%!important;max-width:none!important;min-width:0!important;box-sizing:border-box!important;border:0!important;padding:0 0 16px!important;text-align:left!important}
        body.woocommerce-checkout ul#shipping_method{display:flex!important;flex-flow:column nowrap!important;align-items:stretch!important;width:100%!important;max-width:none!important;gap:10px!important;margin:0!important;padding:0!important;text-align:left!important;list-style:none!important}
        /* Tarjeta: grid 3 columnas: radio | texto | badge */
        body.woocommerce-checkout ul#shipping_method li{
            position:relative!important;
            display:grid!important;
            grid-template-columns:22px minmax(0, 1fr) auto!important;
            grid-template-rows:auto auto!important;
            align-items:start!important;
            column-gap:8px!important;
            float:none!important;clear:both!important;
            width:100%!important;max-width:none!important;min-width:0!important;
            box-sizing:border-box!important;
            margin:0!important;
            padding:12px 14px!important;
            background:#fff!important;
            border:1px solid #d7dee8!important;
            border-radius:10px!important;
            list-style:none!important;
            box-shadow:0 2px 8px rgba(15,23,42,.04)!important;
            transition:border-color .18s ease,box-shadow .18s ease,background .18s ease!important
        }
        body.woocommerce-checkout ul#shipping_method li:hover{border-color:#8db5e8!important;box-shadow:0 5px 14px rgba(0,71,186,.09)!important}
        body.woocommerce-checkout ul#shipping_method li:has(input:checked){border-color:#0047ba!important;box-shadow:0 0 0 2px rgba(0,71,186,.18),0 4px 14px rgba(0,71,186,.10)!important;background:linear-gradient(135deg,#f7fbff 0%,#fff 100%)!important}
        /* Radio */
        body.woocommerce-checkout ul#shipping_method li input{grid-column:1!important;grid-row:1!important;width:16px!important;height:16px!important;margin:4px 0 0!important;accent-color:#0047ba!important;cursor:pointer!important;flex-shrink:0!important}
        /* Etiqueta de nombre */
        body.woocommerce-checkout ul#shipping_method li label{
            grid-column:2!important;grid-row:1!important;
            display:block!important;
            margin:0!important;
            min-width:0!important;
            color:#0f172a!important;
            font-size:13px!important;font-weight:900!important;
            line-height:1.35!important;letter-spacing:-.01em!important;
            cursor:pointer!important;text-transform:none!important;
            white-space:normal!important;word-break:break-word!important
        }
        /* Icono emoji dentro del label */
        body.woocommerce-checkout .owc-shipping-icon{
            display:inline-flex;align-items:center;justify-content:center;
            width:20px;height:20px;margin:-2px 5px 0 0;
            background:#eef5ff;border-radius:5px;
            font-size:12px;vertical-align:middle;flex-shrink:0
        }
        /* Badge de precio (S/ x.xx) — columna 3, fila 1 */
        body.woocommerce-checkout ul#shipping_method li label .woocommerce-Price-amount{
            position:static!important;
            display:inline-flex!important;
            align-items:center!important;justify-content:center!important;
            min-width:46px!important;min-height:22px!important;
            padding:2px 7px!important;
            background:#d71920!important;border-radius:4px!important;
            color:#fff!important;font-size:10px!important;font-weight:950!important;
            box-shadow:0 2px 6px rgba(215,25,32,.18)!important;
            white-space:nowrap!important;vertical-align:middle!important
        }
        /* Wrapper del badge: grid-column 3, fila 1 */
        body.woocommerce-checkout ul#shipping_method li label{
            display:flex!important;
            align-items:center!important;
            gap:6px!important;
            flex-wrap:wrap!important
        }
        /* Badge "Gratis" — columna 3 */
        body.woocommerce-checkout .owc-shipping-free{
            grid-column:3!important;grid-row:1!important;
            display:inline-flex;align-items:center;justify-content:center;
            min-width:46px;min-height:22px;padding:2px 7px;
            background:#16803c;border-radius:4px;
            color:#fff;font-size:10px;font-weight:950;line-height:1;
            box-shadow:0 2px 6px rgba(22,128,60,.18);
            white-space:nowrap;align-self:start;margin-top:3px
        }
        /* Descripcion — fila 2, columnas 2-3 */
        body.woocommerce-checkout .owc-shipping-description{
            grid-column:2 / span 2!important;grid-row:2!important;
            display:block!important;
            margin:6px 0 0!important;
            color:#64748b!important;font-size:11px!important;
            font-weight:500!important;line-height:1.45!important;
            text-align:left!important;
            white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;width:100%!important;
        }
        body.woocommerce-checkout .owc-shipping-description::before{content:'✓';display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;margin-right:4px;background:#fff3e8;border-radius:50%;color:#f37021;font-size:9px;font-weight:900;vertical-align:1px}
        body.woocommerce-checkout .owc-billing-summary{display:none;margin:0 0 18px;padding:0}
        body.woocommerce-checkout .owc-billing-summary.is-visible{display:block}
        /* Fila del botón editar */
        body.woocommerce-checkout .owc-billing-summary__edit-row{margin-bottom:6px}
        body.woocommerce-checkout .owc-billing-summary__edit{
            display:inline-flex;align-items:center;gap:5px;
            width:auto!important;padding:0!important;margin:0!important;
            background:none!important;border:none!important;box-shadow:none!important;
            color:#0047ba!important;font-size:13px!important;font-weight:700!important;
            cursor:pointer;text-decoration:underline;text-underline-offset:2px;
            transition:color .15s
        }
        body.woocommerce-checkout .owc-billing-summary__edit:hover{color:#033685!important}
        /* Líneas de dirección */
        body.woocommerce-checkout .owc-billing-summary__addr-row{
            font-size:12px;color:#172033;line-height:1.55;
            padding:0;margin:2px 0
        }
        body.woocommerce-checkout .owc-billing-summary__addr-row strong{
            color:#172033;font-weight:700;margin-right:4px
        }
        body.woocommerce-checkout .owc-billing-summary__addr-row span{color:#475569}
        body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper.owc-is-collapsed{display:none!important}
        body.woocommerce-checkout .woocommerce-billing-fields h3.owc-is-collapsed{margin-bottom:6px!important}
        </style>
        <script id="owc-shipping-method-ui-js">
        (function(){
            function enhanceShippingMethods(){
                var row=document.querySelector('tr.woocommerce-shipping-totals.shipping');
                if(!row)return;
                var heading=row.querySelector('th');
                if(heading)heading.textContent='Elija un método de entrega';
                row.querySelectorAll('ul#shipping_method li').forEach(function(item){
                    var input=item.querySelector('input.shipping_method');
                    var label=item.querySelector('label');
                    if(!label)return;
                    var method=(input&&input.value||'').toLowerCase();
                    item.classList.toggle('owc-is-pickup',method.indexOf('local_pickup')!==-1);
                    item.classList.toggle('owc-is-delivery',method.indexOf('local_pickup')===-1);
                    if(!label.querySelector('.owc-shipping-icon')){
                        var icon=document.createElement('span');icon.className='owc-shipping-icon';
                        icon.textContent=method.indexOf('local_pickup')!==-1?'🏬':'🚚';label.insertBefore(icon,label.firstChild);
                    }
                    if(!label.querySelector('.woocommerce-Price-amount')&&!item.querySelector('.owc-shipping-free')){
                        var free=document.createElement('span');free.className='owc-shipping-free';free.textContent='Gratis';item.appendChild(free);
                    }
                    if(!item.querySelector('.owc-shipping-description')){
                        var description=document.createElement('small');description.className='owc-shipping-description';
                        description.textContent=method.indexOf('local_pickup')!==-1
                            ? 'Recoge tu pedido en tienda. Te avisaremos cuando esté listo para recoger.'
                            : 'Confirma siempre tu dirección de entrega. Si necesitas registrar otra, contáctanos.';
                        item.appendChild(description);
                    }
                });
            }
            function fieldValue(id){var field=document.getElementById(id);return field?(field.value||'').trim():'';}
            function selectText(id){var s=document.getElementById(id);if(!s)return'';var opt=s.options[s.selectedIndex];return opt?opt.text.trim():'';}

            /* ── Construye la dirección legible de facturación ── */
            function buildBillingAddress(){
                var addr  = fieldValue('billing_address_1');
                var addr2 = fieldValue('billing_address_2');
                var dist  = selectText('billing_distrito') || fieldValue('billing_city');
                var prov  = selectText('billing_provincia') || '';
                var dep   = selectText('billing_departamento') || fieldValue('billing_state');
                var country = selectText('billing_country') || fieldValue('billing_country');
                var parts = [addr, addr2, dist, prov, dep, country].filter(Boolean);
                return parts.join(', ');
            }

            /* ── Construye la dirección de envío ── */
            function buildShippingAddress(){
                var s1    = fieldValue('shipping_address_1') || fieldValue('billing_address_1');
                var s2    = fieldValue('shipping_address_2') || fieldValue('billing_address_2');
                var dist  = selectText('shipping_distrito') || selectText('billing_distrito') || fieldValue('shipping_city') || fieldValue('billing_city');
                var prov  = selectText('shipping_provincia') || selectText('billing_provincia') || '';
                var dep   = selectText('shipping_departamento') || selectText('billing_departamento') || fieldValue('shipping_state') || fieldValue('billing_state');
                var country = selectText('shipping_country') || selectText('billing_country') || '';
                var parts = [s1, s2, dist, prov, dep, country].filter(Boolean);
                return parts.join(', ');
            }

            function enhanceBillingSummary(){
                var billing = document.querySelector('.woocommerce-billing-fields');
                var fields  = billing && billing.querySelector('.woocommerce-billing-fields__field-wrapper');
                if(!billing || !fields) return;

                /* Quitar tarjeta anterior para redibujar con datos actualizados */
                var old = billing.querySelector('.owc-billing-summary');
                if(old){ old.remove(); fields.classList.remove('owc-is-collapsed'); }

                var first   = fieldValue('billing_first_name');
                var last    = fieldValue('billing_last_name');
                var email   = fieldValue('billing_email');
                var phone   = fieldValue('billing_phone');
                var address = fieldValue('billing_address_1');
                var city    = selectText('billing_distrito') || fieldValue('billing_city');
                var state   = selectText('billing_departamento') || fieldValue('billing_state');
                var country = fieldValue('billing_country');
                var tax     = fieldValue('billing_tax_id');
                var doc     = selectText('billing_document_type') || fieldValue('billing_document_type') || fieldValue('owc_document_type');

                /* Solo colapsar si los campos mínimos están completos */
                var requiredComplete = first && last && email && phone && address && city && state && country;
                if(!requiredComplete) return;

                var billingAddr  = buildBillingAddress();
                var shippingAddr = buildShippingAddress();

                /* ── Crear tarjeta ── */
                var card = document.createElement('div');
                card.className = 'owc-billing-summary is-visible';

                /* Línea de editar */
                var editRow = document.createElement('div');
                editRow.className = 'owc-billing-summary__edit-row';
                var editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'owc-billing-summary__edit';
                editBtn.innerHTML = '&#9998; Cambiar datos de Facturación y Dirección de Entrega';
                editRow.appendChild(editBtn);
                card.appendChild(editRow);

                /* Dirección de Factura */
                if(billingAddr){
                    var rowF = document.createElement('div');
                    rowF.className = 'owc-billing-summary__addr-row';
                    rowF.innerHTML = '<strong>Dirección de Factura:</strong> <span>' + billingAddr + '</span>';
                    card.appendChild(rowF);
                }

                /* Dirección de Entrega */
                if(shippingAddr){
                    var rowS = document.createElement('div');
                    rowS.className = 'owc-billing-summary__addr-row';
                    rowS.innerHTML = '<strong>Dirección de Entrega:</strong> <span>' + shippingAddr + '</span>';
                    card.appendChild(rowS);
                }

                /* Insertar ANTES del fieldwrapper y colapsar el form */
                var heading = billing.querySelector('h3');
                if(heading) heading.classList.add('owc-is-collapsed');
                fields.parentNode.insertBefore(card, fields);
                fields.classList.add('owc-is-collapsed');

                /* Toggle al hacer clic en "Cambiar datos" */
                editBtn.addEventListener('click', function(){
                    var isCollapsed = fields.classList.toggle('owc-is-collapsed');
                    editBtn.innerHTML = isCollapsed
                        ? '&#9998; Cambiar datos de Facturación y Dirección de Entrega'
                        : '&#8593; Ocultar formulario';
                    if(!isCollapsed){
                        fields.scrollIntoView({behavior:'smooth', block:'start'});
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', function(){ enhanceShippingMethods(); enhanceBillingSummary(); });
            if(window.jQuery) window.jQuery(document.body).on('updated_checkout', function(){ enhanceShippingMethods(); enhanceBillingSummary(); });
        }());
        </script>
        <?php
    }
}
