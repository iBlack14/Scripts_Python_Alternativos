<?php
/**
 * Widgets Elementor del plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWC_Elementor_Widgets {
    public static function init() {
        add_action( 'elementor/widgets/register', [ __CLASS__, 'register_widgets' ] );
        add_action( 'elementor/elements/categories_registered', [ __CLASS__, 'register_category' ] );
        add_action( 'elementor/frontend/after_enqueue_styles', [ __CLASS__, 'enqueue_styles' ] );
        add_action( 'elementor/editor/after_enqueue_styles', [ __CLASS__, 'enqueue_styles' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_cart_drawer' ] );
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'add_cart_drawer_fragment' ] );
    }

    public static function register_category( $elements_manager ) {
        $elements_manager->add_category(
            'owc-widgets',
            [
                'title' => __( 'Odoo Woo Connector', 'odoo-woo-connector' ),
                'icon'  => 'fa fa-plug',
            ]
        );
    }

    public static function enqueue_styles() {
        wp_enqueue_style(
            'owc-elementor-widgets',
            OWC_PLUGIN_URL . 'assets/css/owc-elementor-widgets.css',
            [],
            OWC_VERSION
        );

        wp_enqueue_script(
            'owc-elementor-widgets',
            OWC_PLUGIN_URL . 'assets/js/owc-elementor-widgets.js',
            [ 'jquery', 'wc-cart-fragments' ],
            OWC_VERSION,
            true
        );
    }

    public static function register_widgets( $widgets_manager ) {
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
            return;
        }

        if ( ! class_exists( 'OWC_Elementor_Action_Bar_Widget' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/elementor/class-owc-action-bar-widget.php';
        }

        if ( ! class_exists( 'OWC_Elementor_Product_Explorer_Widget' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/elementor/class-owc-product-explorer-widget.php';
        }

        $widgets_manager->register( new \OWC_Elementor_Action_Bar_Widget() );
        $widgets_manager->register( new \OWC_Elementor_Product_Explorer_Widget() );
    }

    public static function render_cart_drawer() {
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }
        echo self::get_cart_drawer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Incluye el carrito personalizado en la respuesta AJAX de WooCommerce.
     */
    public static function add_cart_drawer_fragment( $fragments ) {
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $fragments['div.owc-cart-drawer'] = self::get_cart_drawer_html();
        }
        return $fragments;
    }

    private static function get_cart_drawer_html() {
        ob_start();
        ?>
        <div class="owc-cart-drawer" aria-hidden="true" data-owc-cart-count="<?php echo esc_attr( WC()->cart->get_cart_contents_count() ); ?>" data-owc-cart-subtotal="<?php echo esc_attr( wp_strip_all_tags( WC()->cart->get_cart_subtotal() ) ); ?>">
            <button class="owc-cart-drawer__overlay" type="button" data-owc-cart-close aria-label="<?php esc_attr_e( 'Cerrar carrito', 'odoo-woo-connector' ); ?>"></button>
            <aside class="owc-cart-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Carrito', 'odoo-woo-connector' ); ?>">
                <header class="owc-cart-drawer__header">
                    <strong><?php esc_html_e( 'Mi carrito', 'odoo-woo-connector' ); ?></strong>
                    <button class="owc-cart-drawer__close" type="button" data-owc-cart-close aria-label="<?php esc_attr_e( 'Cerrar carrito', 'odoo-woo-connector' ); ?>">×</button>
                </header>
                <div class="owc-cart-drawer__content">
                    <?php self::render_custom_cart_items(); ?>
                </div>
            </aside>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_custom_cart_items() {
        if ( WC()->cart->is_empty() ) {
            echo '<p class="owc-cart-drawer__empty">' . esc_html__( 'Tu carrito esta vacio.', 'odoo-woo-connector' ) . '</p>';
            return;
        }

        echo '<div class="owc-cart-drawer__items">';

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'] ?? null;

            if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
                continue;
            }

            $product_name  = $product->get_name();
            $product_link  = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
            $thumbnail     = $product->get_image( 'woocommerce_thumbnail' );
            $quantity      = absint( $cart_item['quantity'] );
            $product_price = WC()->cart->get_product_price( $product );
            $remove_url    = wc_get_cart_remove_url( $cart_item_key );

            echo '<article class="owc-cart-drawer__product">';
            echo '<div class="owc-cart-drawer__thumb">';
            if ( $product_link ) {
                echo '<a href="' . esc_url( $product_link ) . '">' . wp_kses_post( $thumbnail ) . '</a>';
            } else {
                echo wp_kses_post( $thumbnail );
            }
            echo '</div>';

            echo '<div class="owc-cart-drawer__meta">';
            if ( $product_link ) {
                echo '<a class="owc-cart-drawer__name" href="' . esc_url( $product_link ) . '">' . esc_html( $product_name ) . '</a>';
            } else {
                echo '<span class="owc-cart-drawer__name">' . esc_html( $product_name ) . '</span>';
            }
            echo '<span class="owc-cart-drawer__line">' . esc_html( $quantity ) . ' × ' . wp_kses_post( $product_price ) . '</span>';
            echo '</div>';

            echo '<a class="owc-cart-drawer__remove" href="' . esc_url( $remove_url ) . '" aria-label="' . esc_attr__( 'Eliminar producto', 'odoo-woo-connector' ) . '">×</a>';
            echo '</article>';
        }

        echo '</div>';
        echo '<div class="owc-cart-drawer__summary">';
        echo '<span>' . esc_html__( 'Subtotal', 'odoo-woo-connector' ) . '</span>';
        echo '<strong>' . wp_kses_post( WC()->cart->get_cart_subtotal() ) . '</strong>';
        echo '</div>';
        echo '<div class="owc-cart-drawer__actions">';
        echo '<a class="owc-cart-drawer__button owc-cart-drawer__button--secondary" href="' . esc_url( wc_get_cart_url() ) . '">' . esc_html__( 'Ver carrito', 'odoo-woo-connector' ) . '</a>';
        echo '<a class="owc-cart-drawer__button owc-cart-drawer__button--primary" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Finalizar compra', 'odoo-woo-connector' ) . '</a>';
        echo '</div>';
    }
}
