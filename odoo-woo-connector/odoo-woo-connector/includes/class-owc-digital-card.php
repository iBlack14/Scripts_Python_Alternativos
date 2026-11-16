<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWC_Digital_Card {
    public static function init() {
        add_action( 'template_redirect', [ __CLASS__, 'render_digital_card' ] );
    }

    public static function render_digital_card() {
        if ( isset( $_GET['owc_tarjeta_digital'] ) && OWC_Settings::get('enable_internal_digital_card') === 'yes' ) {
            include OWC_PLUGIN_DIR . 'templates/digital-card.php';
            exit;
        }
    }
}
OWC_Digital_Card::init();
