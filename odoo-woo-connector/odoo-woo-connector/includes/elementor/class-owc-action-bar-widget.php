<?php
/**
 * Barra de accesos rapidos para Elementor.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWC_Elementor_Action_Bar_Widget extends \Elementor\Widget_Base {
    public function get_name() {
        return 'owc_action_bar';
    }

    public function get_title() {
        return __( 'Barra de 3 opciones', 'odoo-woo-connector' );
    }

    public function get_icon() {
        return 'eicon-navigation-horizontal';
    }

    public function get_categories() {
        return [ 'owc-widgets' ];
    }

    public function get_style_depends() {
        return [ 'owc-elementor-widgets' ];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'section_content',
            [
                'label' => __( 'Opciones', 'odoo-woo-connector' ),
            ]
        );

        $this->add_control(
            'layout',
            [
                'label'   => __( 'Diseno', 'odoo-woo-connector' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'pill',
                'options' => [
                    'pill'    => __( 'Diseno 1 - Pastillas', 'odoo-woo-connector' ),
                    'compact' => __( 'Diseno 2 - Compacto', 'odoo-woo-connector' ),
                    'cards'   => __( 'Diseno 3 - Tarjetas', 'odoo-woo-connector' ),
                ],
            ]
        );

        $this->add_control(
            'alignment',
            [
                'label'   => __( 'Alineacion', 'odoo-woo-connector' ),
                'type'    => \Elementor\Controls_Manager::CHOOSE,
                'default' => 'center',
                'options' => [
                    'left' => [
                        'title' => __( 'Izquierda', 'odoo-woo-connector' ),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => __( 'Centro', 'odoo-woo-connector' ),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => __( 'Derecha', 'odoo-woo-connector' ),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
            ]
        );

        $this->add_control(
            'full_width',
            [
                'label'        => __( 'Ocupar todo el ancho', 'odoo-woo-connector' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Si', 'odoo-woo-connector' ),
                'label_off'    => __( 'No', 'odoo-woo-connector' ),
                'return_value' => 'yes',
                'default'      => '',
            ]
        );

        $repeater = new \Elementor\Repeater();

        $repeater->add_control(
            'title',
            [
                'label'       => __( 'Titulo', 'odoo-woo-connector' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Mi carrito', 'odoo-woo-connector' ),
                'label_block' => true,
            ]
        );

        $repeater->add_control(
            'subtitle',
            [
                'label'       => __( 'Subtitulo', 'odoo-woo-connector' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __( 'Ver carro', 'odoo-woo-connector' ),
                'label_block' => true,
            ]
        );

        $repeater->add_control(
            'link',
            [
                'label'       => __( 'Enlace', 'odoo-woo-connector' ),
                'type'        => \Elementor\Controls_Manager::URL,
                'placeholder' => home_url( '/' ),
                'default'     => [
                    'url' => '#',
                ],
            ]
        );

        $repeater->add_control(
            'click_action',
            [
                'label'   => __( 'Accion al click', 'odoo-woo-connector' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'link',
                'options' => [
                    'link'      => __( 'Abrir enlace', 'odoo-woo-connector' ),
                    'open_cart' => __( 'Abrir carrito lateral', 'odoo-woo-connector' ),
                ],
            ]
        );

        $repeater->add_control(
            'dynamic_cart',
            [
                'label'        => __( 'Mostrar cantidad/subtotal del carrito', 'odoo-woo-connector' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Si', 'odoo-woo-connector' ),
                'label_off'    => __( 'No', 'odoo-woo-connector' ),
                'return_value' => 'yes',
                'default'      => '',
                'condition'    => [
                    'click_action' => 'open_cart',
                ],
            ]
        );

        $repeater->add_control(
            'icon',
            [
                'label'   => __( 'Icono', 'odoo-woo-connector' ),
                'type'    => \Elementor\Controls_Manager::ICONS,
                'default' => [
                    'value'   => 'fas fa-shopping-cart',
                    'library' => 'fa-solid',
                ],
            ]
        );

        $this->add_control(
            'items',
            [
                'label'       => __( 'Items', 'odoo-woo-connector' ),
                'type'        => \Elementor\Controls_Manager::REPEATER,
                'fields'      => $repeater->get_controls(),
                'title_field' => '{{{ title }}}',
                'default'     => [
                    [
                        'title'    => __( 'Mi carrito', 'odoo-woo-connector' ),
                        'subtitle' => __( 'Ver carro', 'odoo-woo-connector' ),
                        'click_action' => 'open_cart',
                        'dynamic_cart' => 'yes',
                        'icon'     => [
                            'value'   => 'fas fa-shopping-cart',
                            'library' => 'fa-solid',
                        ],
                    ],
                    [
                        'title'    => __( 'Lista de deseos', 'odoo-woo-connector' ),
                        'subtitle' => __( 'Ver lista de deseos', 'odoo-woo-connector' ),
                        'icon'     => [
                            'value'   => 'fas fa-heart',
                            'library' => 'fa-solid',
                        ],
                    ],
                    [
                        'title'    => __( 'Grupo economico', 'odoo-woo-connector' ),
                        'subtitle' => __( 'Mi cuenta', 'odoo-woo-connector' ),
                        'icon'     => [
                            'value'   => 'fas fa-user',
                            'library' => 'fa-solid',
                        ],
                    ],
                ],
                'prevent_empty' => false,
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            [
                'label' => __( 'Estilo', 'odoo-woo-connector' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'accent_color',
            [
                'label'     => __( 'Color principal', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e30613',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-accent: {{VALUE}}; --owc-icon-bg: {{VALUE}}; --owc-border-hover: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label'     => __( 'Color de titulo', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#243042',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'subtitle_color',
            [
                'label'     => __( 'Color de subtitulo', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#657084',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-muted: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'background_color',
            [
                'label'     => __( 'Fondo del item', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'bar_background_color',
            [
                'label'     => __( 'Fondo de barra compacta', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-bar-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'hover_background_color',
            [
                'label'     => __( 'Fondo al pasar mouse', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#f7f8fa',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-hover-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'border_color',
            [
                'label'     => __( 'Color de borde', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e7eaf0',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-border: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'show_border',
            [
                'label'        => __( 'Mostrar borde', 'odoo-woo-connector' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Si', 'odoo-woo-connector' ),
                'label_off'    => __( 'No', 'odoo-woo-connector' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'show_shadow',
            [
                'label'        => __( 'Mostrar sombra', 'odoo-woo-connector' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => __( 'Si', 'odoo-woo-connector' ),
                'label_off'    => __( 'No', 'odoo-woo-connector' ),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'icon_background_color',
            [
                'label'     => __( 'Fondo de icono', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e30613',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-icon-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'icon_color',
            [
                'label'     => __( 'Color de icono', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-icon-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_heading',
            [
                'label'     => __( 'Carrito lateral', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            'drawer_header_bg',
            [
                'label'     => __( 'Fondo cabecera carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#0047ba',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-header-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_header_text',
            [
                'label'     => __( 'Titulo carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-header-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_title_size',
            [
                'label'      => __( 'Tamano titulo carrito', 'odoo-woo-connector' ),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [
                    'px' => [
                        'min' => 10,
                        'max' => 28,
                    ],
                ],
                'default'    => [
                    'unit' => 'px',
                    'size' => 14,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-title-size: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_header_border',
            [
                'label'     => __( 'Borde cabecera carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e30613',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-header-border: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_panel_bg',
            [
                'label'     => __( 'Fondo panel carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-panel-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_card_bg',
            [
                'label'     => __( 'Fondo producto carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-card-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_card_border',
            [
                'label'     => __( 'Borde producto carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e8ecf2',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-card-border: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_product_text',
            [
                'label'     => __( 'Texto producto carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#1f2937',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-product-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_muted_text',
            [
                'label'     => __( 'Texto precio/cantidad carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#667085',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-muted-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_primary',
            [
                'label'     => __( 'Fondo finalizar compra', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e30613',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-primary: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_primary_hover',
            [
                'label'     => __( 'Hover finalizar compra', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#b80510',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-primary-hover: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_secondary',
            [
                'label'     => __( 'Fondo ver carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#0047ba',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-secondary: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_secondary_hover',
            [
                'label'     => __( 'Hover ver carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#00378f',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-secondary-hover: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_text',
            [
                'label'     => __( 'Texto botones carrito', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_button_text_hover',
            [
                'label'     => __( 'Texto botones hover', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#ffffff',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-button-text-hover: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'drawer_close_bg',
            [
                'label'     => __( 'Fondo boton cerrar', 'odoo-woo-connector' ),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#e30613',
                'selectors' => [
                    '{{WRAPPER}} .owc-action-bar' => '--owc-drawer-close-bg: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $items     = array_slice( $settings['items'] ?? [], 0, 3 );
        $layout    = ! empty( $settings['layout'] ) ? sanitize_html_class( $settings['layout'] ) : 'pill';
        $alignment = ! empty( $settings['alignment'] ) ? sanitize_html_class( $settings['alignment'] ) : 'center';
        $width     = ! empty( $settings['full_width'] ) ? ' owc-action-bar--full' : '';
        $border    = empty( $settings['show_border'] ) ? ' owc-action-bar--no-border' : '';
        $shadow    = empty( $settings['show_shadow'] ) ? ' owc-action-bar--no-shadow' : '';

        if ( empty( $items ) ) {
            return;
        }

        echo '<div class="owc-action-bar owc-action-bar--' . esc_attr( $layout ) . ' owc-action-bar--align-' . esc_attr( $alignment ) . esc_attr( $width ) . esc_attr( $border ) . esc_attr( $shadow ) . '">';

        foreach ( $items as $item ) {
            $title    = $item['title'] ?? '';
            $subtitle = $item['subtitle'] ?? '';
            $link     = $item['link']['url'] ?? '#';
            $action   = ! empty( $item['click_action'] ) ? sanitize_key( $item['click_action'] ) : 'link';
            $dynamic  = ! empty( $item['dynamic_cart'] ) && $action === 'open_cart' ? 'yes' : 'no';
            $target   = ! empty( $item['link']['is_external'] ) ? ' target="_blank"' : '';
            $nofollow = ! empty( $item['link']['nofollow'] ) ? ' rel="nofollow"' : '';

            $my_account_url  = wc_get_page_permalink( 'myaccount' );
            $account_path    = $my_account_url ? wp_parse_url( $my_account_url, PHP_URL_PATH ) : '';
            $item_path       = $link && $link !== '#' ? wp_parse_url( $link, PHP_URL_PATH ) : '';
            $normalized_text = remove_accents( strtolower( trim( $title . ' ' . $subtitle ) ) );
            $is_account_item = ( $account_path && $item_path && untrailingslashit( $account_path ) === untrailingslashit( $item_path ) )
                || strpos( $normalized_text, 'mi cuenta' ) !== false
                || strpos( $normalized_text, 'grupo economico' ) !== false;

            if ( $is_account_item ) {
                $link = $my_account_url ?: $link;

                if ( is_user_logged_in() ) {
                    $current_user = wp_get_current_user();
                    $title = $current_user->display_name ?: $current_user->user_login;
                    $subtitle = __( 'Mi cuenta', 'odoo-woo-connector' );
                } else {
                    $title = __( 'Iniciar sesión', 'odoo-woo-connector' );
                    $subtitle = __( 'Mi cuenta', 'odoo-woo-connector' );
                }
            }

            echo '<a class="owc-action-bar__item" data-owc-action="' . esc_attr( $action ) . '" data-owc-dynamic-cart="' . esc_attr( $dynamic ) . '" href="' . esc_url( $link ) . '"' . $target . $nofollow . '>';
            echo '<span class="owc-action-bar__icon">';

            if ( ! empty( $item['icon']['value'] ) ) {
                \Elementor\Icons_Manager::render_icon( $item['icon'], [ 'aria-hidden' => 'true' ] );
            }

            echo '</span>';
            echo '<span class="owc-action-bar__copy">';
            echo '<span class="owc-action-bar__title">' . esc_html( $title ) . '</span>';
            echo '<span class="owc-action-bar__subtitle">' . esc_html( $subtitle ) . '</span>';
            echo '</span>';
            echo '</a>';
        }

        echo '</div>';
    }
}
