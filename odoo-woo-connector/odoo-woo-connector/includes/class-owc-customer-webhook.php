<?php
/**
 * Webhook de Clientes WooCommerce → Odoo
 * Sincroniza el registro y actualización de perfiles de cliente con Odoo.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Customer_Webhook {

    public static function init() {
        add_action( 'woocommerce_created_customer', [ __CLASS__, 'sync_customer_to_odoo' ], 20, 1 );
        add_action( 'profile_update', [ __CLASS__, 'sync_customer_to_odoo' ], 20, 1 );
        add_action( 'woocommerce_save_account_details', [ __CLASS__, 'sync_customer_to_odoo' ], 20, 1 );
        add_action( 'woocommerce_customer_save_address', [ __CLASS__, 'sync_customer_to_odoo' ], 20, 1 );
    }

    public static function sync_customer_to_odoo( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $email = $user->user_email;
        $company_name = get_user_meta( $user_id, 'billing_company', true ) ?: get_user_meta( $user_id, 'owc_company_name', true );
        $tax_id  = get_user_meta( $user_id, 'billing_tax_id', true ) ?: get_user_meta( $user_id, 'owc_tax_id', true );
        $phone = get_user_meta( $user_id, 'billing_phone', true ) ?: get_user_meta( $user_id, 'owc_phone', true );
        $first_name = get_user_meta( $user_id, 'billing_first_name', true ) ?: $user->first_name;
        $last_name = get_user_meta( $user_id, 'billing_last_name', true ) ?: $user->last_name;
        $address_1 = get_user_meta( $user_id, 'billing_address_1', true );
        $address_2 = get_user_meta( $user_id, 'billing_address_2', true );
        $city = get_user_meta( $user_id, 'billing_city', true );
        $state = get_user_meta( $user_id, 'billing_state', true );
        $postcode = get_user_meta( $user_id, 'billing_postcode', true );
        $country = get_user_meta( $user_id, 'billing_country', true ) ?: 'PE';
        $document_type = strtoupper( get_user_meta( $user_id, 'owc_document_type', true ) );
        if ( ! $document_type && $tax_id ) {
            $document_type = strlen( preg_replace( '/\D+/', '', $tax_id ) ) === 11 ? 'RUC' : 'DNI';
        }
        
        $full_name = trim( $first_name . ' ' . $last_name );
        $name = $company_name ?: $full_name;
        $name = $name ?: 'Cliente Web';

        $billing = [
            'phone' => $phone,
            'address_1' => $address_1,
            'address_2' => $address_2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'country' => $country,
            'tax_id' => $tax_id,
            'document_type' => $document_type,
            'company' => $company_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];

        // Evitar únicamente envíos idénticos; permite un segundo envío cuando
        // WooCommerce termina de guardar metadatos después de profile_update.
        static $synced = [];
        $fingerprint = md5( wp_json_encode( [ $email, $name, $billing ] ) );
        if ( isset( $synced[ $user_id ][ $fingerprint ] ) ) return;
        $synced[ $user_id ][ $fingerprint ] = true;

        // Llamar a OdooXmlRpc
        $odoo_url     = OWC_Settings::get('odoo_url');
        $odoo_db      = OWC_Settings::get('odoo_db');
        $odoo_user    = OWC_Settings::get('odoo_user');
        $odoo_pass    = OWC_Settings::get('odoo_password');

        if ( ! $odoo_url || ! $odoo_db || ! $odoo_user || ! $odoo_pass ) {
            return;
        }

        if ( ! class_exists('OdooXmlRpc') ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-xmlrpc.php';
        }

        $rpc = new OdooXmlRpc( $odoo_url, $odoo_db, $odoo_user, $odoo_pass );
        if ( ! $rpc->authenticate() ) {
            return;
        }

        $partner_id = $rpc->find_or_create_partner( $email, $name, $billing );
        if ( $partner_id ) {
            // Actualizar el partner en caso de que ya exista pero le falten datos
            $vals = [];
            if ( $company_name ) $vals['name'] = $company_name;
            if ( $phone ) $vals['phone'] = $phone;
            if ( $tax_id ) $vals['vat'] = $tax_id;
            if ( $address_1 ) $vals['street'] = $address_1;
            if ( $address_2 ) $vals['street2'] = $address_2;
            if ( $city ) $vals['city'] = $city;
            if ( $postcode ) $vals['zip'] = $postcode;
            $vals['company_type'] = $company_name ? 'company' : 'person';

            if ( $country ) {
                $country_found = $rpc->search_read( 'res.country', [[ 'code', '=', strtoupper( $country ) ]], ['id'], 1 );
                if ( ! empty( $country_found ) ) {
                    $vals['country_id'] = $country_found[0]['id'];
                    if ( $state ) {
                        $state_name = $state;
                        if ( function_exists( 'WC' ) && WC()->countries ) {
                            $wc_states = WC()->countries->get_states( strtoupper( $country ) );
                            $state_name = $wc_states[ $state ] ?? $state;
                        }
                        $state_domain = [[ 'country_id', '=', $country_found[0]['id'] ], '|', [ 'code', '=ilike', $state ], [ 'name', '=ilike', $state_name ]];
                        $state_found = $rpc->search_read( 'res.country.state', $state_domain, ['id'], 1 );
                        if ( ! empty( $state_found ) ) $vals['state_id'] = $state_found[0]['id'];
                    }
                }
            }

            if ( $tax_id ) {
                $doc_type = in_array( $document_type, [ 'DNI', 'RUC' ], true ) ? $document_type : ( strlen( trim( $tax_id ) ) === 11 ? 'RUC' : 'DNI' );
                $type_found = $rpc->search_read( 'l10n_latam.identification.type', [[ 'name', '=', $doc_type ]], ['id'], 1 );
                if ( ! empty( $type_found ) ) {
                    $vals['l10n_latam_identification_type_id'] = $type_found[0]['id'];
                }
            }

            if ( ! empty( $vals ) ) {
                $updated = $rpc->execute_kw( 'res.partner', 'write', [ [ $partner_id ], $vals ] );
                if ( ! $updated ) error_log( '[OWC] No se pudo completar el contacto en Odoo: ' . $rpc->get_last_error() );
            }
        } else {
            error_log( '[OWC] No se pudo crear/encontrar el contacto en Odoo: ' . $rpc->get_last_error() );
        }
    }
}
