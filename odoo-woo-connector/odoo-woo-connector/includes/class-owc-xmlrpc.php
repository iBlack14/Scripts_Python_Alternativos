<?php
/**
 * Cliente XML-RPC Ligero para Odoo 15
 * Implementación pura en PHP sin necesidad de la extensión php-xmlrpc.
 * Compatible con PHP 7.4, 8.0, 8.1, 8.2 y 8.3+.
 *
 * @package  OdooWooConnector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OdooXmlRpc {

    private $url;
    private $db;
    private $username;
    private $password;
    private $uid = null;
    private $last_error = null;
    private $company_id = null;
    private $company_loaded = false;

    public function __construct( $url, $db, $username, $password ) {
        $this->url      = rtrim( trim( $url ), '/' );
        $this->db       = trim( $db );
        $this->username = trim( $username );
        $this->password = $password;
    }

    public function get_last_error() {
        return $this->last_error;
    }

    public function get_uid() {
        return $this->uid;
    }

    // ─── Autenticación ────────────────────────────────────────────────────────
    public function authenticate() {
        if ( $this->uid ) {
            return true;
        }

        $this->last_error = null;

        // Odoo 15 común requiere: db (str), login (str), password (str), user_agent_env (dict/struct)
        $response = $this->xmlrpc_call(
            "{$this->url}/xmlrpc/2/common",
            'authenticate',
            [ $this->db, $this->username, $this->password, (object) [] ]
        );

        if ( is_int( $response ) && $response > 0 ) {
            $this->uid = $response;
            return true;
        }

        if ( empty( $this->last_error ) ) {
            $this->last_error = "Credenciales incorrectas o la base de datos '{$this->db}' no existe en Odoo.";
        }
        return false;
    }

    // ─── Llamada execute_kw ───────────────────────────────────────────────────
    public function execute_kw( $model, $method, $args = [], $kwargs = [] ) {
        if ( ! $this->uid ) {
            if ( ! $this->authenticate() ) {
                return false;
            }
        }

        return $this->xmlrpc_call(
            "{$this->url}/xmlrpc/2/object",
            'execute_kw',
            [
                $this->db,
                $this->uid,
                $this->password,
                $model,
                $method,
                $args,
                (object) $kwargs
            ]
        );
    }

    // ─── Probar Conexión ───────────────────────────────────────────────────────
    public function test_connection() {
        $this->last_error = null;

        if ( empty( $this->url ) || empty( $this->db ) || empty( $this->username ) || empty( $this->password ) ) {
            return [
                'success' => false,
                'error'   => 'Faltan credenciales por configurar. Complete URL, Base de datos, Usuario y Contraseña.',
            ];
        }

        // 1. Probar conectividad con el endpoint común (version info)
        $version_info = $this->xmlrpc_call( "{$this->url}/xmlrpc/2/common", 'version', [] );
        if ( $version_info === null && ! empty( $this->last_error ) ) {
            return [
                'success' => false,
                'error'   => 'No se pudo conectar con Odoo: ' . $this->last_error,
            ];
        }

        // 2. Intentar autenticación
        $authenticated = $this->authenticate();

        if ( $authenticated && $this->uid ) {
            $server_ver = '15.x';
            if ( is_array( $version_info ) && ! empty( $version_info['server_version'] ) ) {
                $server_ver = (string) $version_info['server_version'];
            }
            return [
                'success'        => true,
                'uid'            => $this->uid,
                'server_version' => $server_ver,
            ];
        }

        return [
            'success' => false,
            'error'   => ! empty( $this->last_error ) ? $this->last_error : 'No se pudo autenticar en Odoo. Verifique usuario, contraseña y base de datos.',
        ];
    }

    // ─── Obtener Productos desde Odoo ─────────────────────────────────────────
    public function get_odoo_products( $match_field = 'default_code', $limit = 1000 ) {
        $domain = [
            [ 'sale_ok', '=', true ],
            [ 'active', '=', true ],
            [ 'type', '=', 'product' ],
        ];
        $fields = [
            'id', 'name', 'default_code', 'barcode', 'list_price', 'description_sale',
            'qty_available', 'free_qty', 'type', 'weight', 'categ_id', 'standard_price', 'product_tmpl_id'
        ];
        $products = $this->search_read( 'product.product', $domain, $fields, $limit );
        $products = $this->apply_on_hand_stock( $products );

        if ( ! empty( $products ) ) {
            $product_ids = [];
            $tmpl_ids = [];
            foreach ( $products as $p ) {
                if ( ! empty( $p['id'] ) ) $product_ids[] = (int) $p['id'];
                if ( ! empty( $p['product_tmpl_id'][0] ) ) $tmpl_ids[] = (int) $p['product_tmpl_id'][0];
            }

            if ( ! empty( $product_ids ) || ! empty( $tmpl_ids ) ) {
                $domain_pl = [ '|', [ 'product_id', 'in', $product_ids ], [ 'product_tmpl_id', 'in', $tmpl_ids ] ];
                $pricelist_items = $this->search_read( 'product.pricelist.item', $domain_pl, [ 'pricelist_id', 'product_id', 'product_tmpl_id', 'fixed_price' ] );

                foreach ( $products as &$p ) {
                    $p['ferretero_price'] = isset($p['list_price']) ? (float)$p['list_price'] : 0.0;
                    $p['public_price'] = isset($p['list_price']) ? (float)$p['list_price'] : 0.0;
                    
                    $p_id = (int) ($p['id'] ?? 0);
                    $t_id = (int) ($p['product_tmpl_id'][0] ?? 0);

                    foreach ( $pricelist_items as $item ) {
                        $applies = false;
                        if ( ! empty( $item['product_id'][0] ) && (int) $item['product_id'][0] === $p_id ) {
                            $applies = true;
                        } elseif ( ! empty( $item['product_tmpl_id'][0] ) && (int) $item['product_tmpl_id'][0] === $t_id && empty( $item['product_id'] ) ) {
                            $applies = true;
                        }

                        if ( $applies ) {
                            $pl_name = strtoupper( $item['pricelist_id'][1] ?? '' );
                            
                            // Ignorar la lista de Abraham para evitar sobrescribir el precio público normal
                            if ( strpos( $pl_name, 'ABRAHAM' ) !== false ) {
                                continue;
                            }

                            if ( strpos( $pl_name, 'FERRETERO' ) !== false ) {
                                $p['ferretero_price'] = (float) $item['fixed_price'];
                            } elseif ( strpos( $pl_name, 'PUBLICO' ) !== false ) {
                                $p['public_price'] = (float) $item['fixed_price'];
                            }
                        }
                    }
                }
                unset($p);
            }
        }

        return $products;
    }

    // ─── Obtener Stock desde Odoo ─────────────────────────────────────────────
    public function get_odoo_stock( $match_field = 'default_code', $limit = 2000 ) {
        $domain = [
            [ 'sale_ok', '=', true ],
            [ 'active', '=', true ],
            [ 'type', '=', 'product' ],
        ];
        $fields = [ 'id', 'name', 'default_code', 'barcode', 'qty_available', 'free_qty', 'type' ];
        $products = $this->search_read( 'product.product', $domain, $fields, $limit );
        return $this->apply_on_hand_stock( $products );
    }

    /**
     * Obtiene la compañía principal del usuario usado por la conexión XML-RPC.
     */
    private function get_current_company_id() {
        if ( $this->company_loaded ) return $this->company_id;
        $this->company_loaded = true;

        if ( ! $this->uid && ! $this->authenticate() ) return null;

        $users = $this->execute_kw(
            'res.users',
            'read',
            [ [ (int) $this->uid ] ],
            [ 'fields' => [ 'company_id' ] ]
        );

        if ( is_array( $users ) && ! empty( $users[0]['company_id'] ) ) {
            $company = $users[0]['company_id'];
            $this->company_id = is_array( $company ) ? (int) $company[0] : (int) $company;
        }

        return $this->company_id;
    }

    /**
     * Recalcula "A Mano" desde stock.quant para evitar cantidades de otras
     * compañías o ubicaciones que no sean internas.
     */
    private function apply_on_hand_stock( $products ) {
        // Se ha deshabilitado el recálculo personalizado para usar qty_available nativo de Odoo.
        return $products;
    }

    // ─── Helpers de modelos ───────────────────────────────────────────────────
    public function search_read( $model, $domain, $fields = [], $limit = 0 ) {
        $kwargs = [ 
            'fields' => $fields,
            'context' => [ 'allowed_company_ids' => [2] ] // Forzar a Compañía 2 (Grupo Flores)
        ];
        if ( $limit ) $kwargs['limit'] = $limit;
        $result = $this->execute_kw( $model, 'search_read', [ $domain ], $kwargs );
        return is_array( $result ) ? $result : [];
    }

    public function create( $model, $vals ) {
        return $this->execute_kw( $model, 'create', [ $vals ] );
    }

    public function create_product( $name, $sku = '', $price = 0, $description = '' ) {
        $vals = [
            'name'             => sanitize_text_field( $name ),
            'default_code'     => sanitize_text_field( $sku ),
            'list_price'       => (float) $price,
            'type'             => 'product',
            'sale_ok'          => true,
            'description_sale' => sanitize_textarea_field( $description ),
        ];
        return $this->create( 'product.product', $vals );
    }

    // ─── Buscar o Crear Partner ───────────────────────────────────────────────
    public function find_or_create_partner( $email, $name, $billing = [] ) {
        $tax_id = preg_replace( '/\D+/', '', (string) ( $billing['tax_id'] ?? '' ) );
        $partner_id = 0;
        if ( $tax_id ) {
            $found = $this->search_read( 'res.partner', [[ 'vat', '=', $tax_id ]], ['id', 'name'], 1 );
            if ( ! empty( $found ) ) $partner_id = (int) $found[0]['id'];
        }

        if ( ! $partner_id && $email ) {
            $found = $this->search_read( 'res.partner', [[ 'email', '=ilike', $email ]], ['id', 'name'], 1 );
            if ( ! empty( $found ) ) $partner_id = (int) $found[0]['id'];
        }

        $company = trim( (string) ( $billing['company'] ?? '' ) );
        $vals = [
            'name'          => $name ?: 'Cliente WooCommerce',
            'email'         => $email,
            'phone'         => $billing['phone']     ?? '',
            'mobile'        => $billing['phone']     ?? '',
            'street'        => $billing['address_1'] ?? '',
            'street2'       => $billing['address_2'] ?? '',
            'city'          => $billing['city']      ?? '',
            'zip'           => $billing['postcode']  ?? '',
            'vat'           => $tax_id,
            'company_type'  => $company ? 'company' : 'person',
            'customer_rank' => 1,
            'comment'       => 'Creado automáticamente por Odoo WooCommerce Connector',
        ];

        $country_code = strtoupper( trim( (string) ( $billing['country'] ?? 'PE' ) ) );
        if ( $country_code ) {
            $country = $this->search_read( 'res.country', [[ 'code', '=', $country_code ]], ['id'], 1 );
            if ( ! empty( $country ) ) {
                $vals['country_id'] = $country[0]['id'];
                $state = trim( (string) ( $billing['state'] ?? '' ) );
                if ( $state ) {
                    $state_name = $state;
                    if ( function_exists( 'WC' ) && WC()->countries ) {
                        $wc_states = WC()->countries->get_states( $country_code );
                        $state_name = $wc_states[ $state ] ?? $state;
                    }
                    $state_found = $this->search_read(
                        'res.country.state',
                        [[ 'country_id', '=', $country[0]['id'] ], '|', [ 'code', '=ilike', $state ], [ 'name', '=ilike', $state_name ]],
                        ['id'],
                        1
                    );
                    if ( empty( $state_found ) && function_exists( 'remove_accents' ) ) {
                        $all_states = $this->search_read( 'res.country.state', [[ 'country_id', '=', $country[0]['id'] ]], ['id', 'name', 'code'] );
                        $wanted_state = strtolower( remove_accents( $state_name ) );
                        foreach ( $all_states as $candidate ) {
                            $candidate_name = strtolower( remove_accents( (string) ( $candidate['name'] ?? '' ) ) );
                            $candidate_code = strtolower( remove_accents( (string) ( $candidate['code'] ?? '' ) ) );
                            if ( $candidate_name === $wanted_state || $candidate_code === $wanted_state ) {
                                $state_found = [ $candidate ];
                                break;
                            }
                        }
                    }
                    if ( ! empty( $state_found ) ) $vals['state_id'] = $state_found[0]['id'];
                }

                // Localización peruana: Odoo usa res.city para la provincia y
                // l10n_pe.res.city.district para el distrito.
                $province = trim( (string) ( $billing['province'] ?? '' ) );
                $district = trim( (string) ( $billing['city'] ?? '' ) );
                $province_found = [];
                if ( $province ) {
                    $province_domain = [[ 'name', '=ilike', $province ]];
                    if ( ! empty( $vals['state_id'] ) ) $province_domain[] = [ 'state_id', '=', $vals['state_id'] ];
                    $province_found = $this->search_read( 'res.city', $province_domain, ['id', 'name'], 1 );
                    if ( ! empty( $province_found ) ) $vals['city'] = $province_found[0]['name'];
                }
                if ( $district ) {
                    $district_domain = [[ 'name', '=ilike', $district ]];
                    if ( ! empty( $province_found ) ) $district_domain[] = [ 'city_id', '=', $province_found[0]['id'] ];
                    $district_found = $this->search_read( 'l10n_pe.res.city.district', $district_domain, ['id'], 1 );
                    if ( ! empty( $district_found ) ) $vals['l10n_pe_district'] = $district_found[0]['id'];
                }
            }
        }

        // Asignar tipo de documento peruano (l10n_latam) si existe
        if ( ! empty( $tax_id ) ) {
            $requested_type = strtoupper( trim( (string) ( $billing['document_type'] ?? '' ) ) );
            $doc_type = in_array( $requested_type, [ 'DNI', 'RUC' ], true ) ? $requested_type : ( strlen( $tax_id ) === 11 ? 'RUC' : 'DNI' );
            $type_found = $this->search_read( 'l10n_latam.identification.type', [[ 'name', '=', $doc_type ]], ['id'], 1 );
            if ( ! empty( $type_found ) ) {
                $vals['l10n_latam_identification_type_id'] = $type_found[0]['id'];
            }
        }

        if ( $partner_id ) {
            // Un pedido solo completa huecos: nunca reemplaza la ficha maestra
            // creada durante el registro o mantenida directamente en Odoo.
            $update_vals = $vals;
            unset( $update_vals['comment'] );
            foreach ( $update_vals as $field => $value ) {
                if ( $value === '' || $value === null ) unset( $update_vals[ $field ] );
            }
            $protected_fields = [
                'name', 'email', 'phone', 'mobile', 'street', 'street2', 'city',
                'zip', 'country_id', 'state_id', 'l10n_pe_district', 'vat', 'company_type',
                'customer_rank', 'l10n_latam_identification_type_id',
            ];
            $current_rows = $this->execute_kw(
                'res.partner',
                'read',
                [ [ $partner_id ] ],
                [ 'fields' => $protected_fields ]
            );
            $current = is_array( $current_rows ) && ! empty( $current_rows[0] ) ? $current_rows[0] : [];
            $current_had_tax_id = ! empty( $current['vat'] );
            foreach ( $protected_fields as $field ) {
                $current_value = $current[ $field ] ?? null;
                $has_current_value = ! ( $current_value === false || $current_value === null || $current_value === '' || $current_value === [] );
                if ( $has_current_value ) unset( $update_vals[ $field ] );
            }
            // Si se completa por primera vez el DNI/RUC, corregir también el
            // tipo genérico VAT que Odoo coloca por defecto.
            if ( ! $current_had_tax_id && $tax_id && isset( $vals['l10n_latam_identification_type_id'] ) ) {
                $update_vals['l10n_latam_identification_type_id'] = $vals['l10n_latam_identification_type_id'];
            }
            $updated = $this->execute_kw( 'res.partner', 'write', [ [ $partner_id ], $update_vals ] );
            return $updated ? $partner_id : false;
        }

        return $this->create( 'res.partner', $vals );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  IMPLEMENTACIÓN XML-RPC PURA (sin extensión php-xmlrpc)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Realiza una llamada XML-RPC usando wp_remote_post con control detallado de errores.
     */
    public function xmlrpc_call( $endpoint, $method, $params ) {
        $xml = $this->encode_request( $method, $params );

        $response = wp_remote_post( $endpoint, [
            'method'    => 'POST',
            'body'      => $xml,
            'headers'   => [
                'Content-Type' => 'text/xml; charset=UTF-8',
                'User-Agent'   => 'Odoo-Woo-Connector/1.0',
            ],
            'timeout'   => 30,
            'sslverify' => false,
        ]);

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'Error de conexión HTTP: ' . $response->get_error_message();
            error_log( '[OWC] XML-RPC cURL/HTTP error: ' . $this->last_error );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $this->last_error = "Servidor Odoo respondió con código HTTP {$code}. Verifique que la URL '{$endpoint}' sea accesible.";
            error_log( "[OWC] XML-RPC HTTP {$code}: " . substr( $body, 0, 200 ) );
            return null;
        }

        return $this->decode_response( $body );
    }

    /**
     * Construye el XML de una petición XML-RPC.
     */
    private function encode_request( $method, $params ) {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= "<methodCall>\n";
        $xml .= "  <methodName>" . htmlspecialchars( $method, ENT_XML1, 'UTF-8' ) . "</methodName>\n";
        $xml .= "  <params>\n";

        foreach ( $params as $param ) {
            $xml .= "    <param>\n      <value>" . $this->encode_value( $param ) . "</value>\n    </param>\n";
        }

        $xml .= "  </params>\n";
        $xml .= "</methodCall>";

        return $xml;
    }

    /**
     * Codifica un valor PHP a formato XML-RPC.
     */
    private function encode_value( $value ) {
        if ( is_null( $value ) || $value === false ) {
            return '<boolean>0</boolean>';
        }

        if ( is_bool( $value ) ) {
            return '<boolean>' . ( $value ? '1' : '0' ) . '</boolean>';
        }

        if ( is_int( $value ) ) {
            return '<int>' . $value . '</int>';
        }

        if ( is_float( $value ) || is_double( $value ) ) {
            return '<double>' . $value . '</double>';
        }

        if ( is_string( $value ) ) {
            return '<string>' . htmlspecialchars( $value, ENT_XML1, 'UTF-8' ) . '</string>';
        }

        // Struct (objeto o array asociativo)
        if ( is_object( $value ) || ( is_array( $value ) && $this->is_assoc( $value ) ) ) {
            $struct = '<struct>';
            foreach ( (array) $value as $k => $v ) {
                $struct .= '<member>';
                $struct .= '<name>' . htmlspecialchars( (string) $k, ENT_XML1, 'UTF-8' ) . '</name>';
                $struct .= '<value>' . $this->encode_value( $v ) . '</value>';
                $struct .= '</member>';
            }
            $struct .= '</struct>';
            return $struct;
        }

        // Array secuencial
        if ( is_array( $value ) ) {
            $arr = '<array><data>';
            foreach ( $value as $v ) {
                $arr .= '<value>' . $this->encode_value( $v ) . '</value>';
            }
            $arr .= '</data></array>';
            return $arr;
        }

        return '<string>' . htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' ) . '</string>';
    }

    /**
     * Decodifica la respuesta XML-RPC a un valor PHP.
     */
    private function decode_response( $xml_string ) {
        if ( empty( $xml_string ) ) {
            $this->last_error = 'El servidor Odoo devolvió una respuesta vacía.';
            return null;
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_string );

        if ( $xml === false ) {
            $xml_errors = libxml_get_errors();
            libxml_clear_errors();
            $err_msg = ! empty( $xml_errors ) ? $xml_errors[0]->message : 'XML no válido';
            $this->last_error = 'Respuesta no válida del servidor Odoo (' . trim( $err_msg ) . '). Verifique que la URL apunte a Odoo.';
            error_log( '[OWC] XML-RPC: No se pudo parsear XML: ' . substr( $xml_string, 0, 300 ) );
            return null;
        }

        // Verificar faultCode / faultString de XML-RPC
        if ( isset( $xml->fault ) ) {
            $fault = $this->decode_value( $xml->fault->value );
            $raw_msg = is_array( $fault ) ? ( $fault['faultString'] ?? 'Error de Odoo' ) : (string) $fault;

            // Extraer el mensaje relevante si contiene un traceback de Python
            if ( preg_match( '/\n([a-zA-Z0-9_\.]+(?:Error|Exception):[^\n]+)/', $raw_msg, $matches ) ) {
                $clean_msg = $matches[1];
            } else {
                $clean_msg = substr( trim( $raw_msg ), 0, 250 );
            }

            $this->last_error = 'Error de Odoo: ' . $clean_msg;
            error_log( '[OWC] Odoo XML-RPC Fault: ' . $raw_msg );
            return null;
        }

        // Respuesta normal
        if ( isset( $xml->params->param->value ) ) {
            return $this->decode_value( $xml->params->param->value );
        }

        $this->last_error = 'La respuesta XML de Odoo no contiene parámetros válidos.';
        return null;
    }

    /**
     * Decodifica un nodo <value> XML-RPC a un valor PHP nativo.
     */
    private function decode_value( $value_node ) {
        $children = $value_node->children();

        // Si no hay hijos con tipo explícito, el valor es texto plano (string)
        if ( count( $children ) === 0 ) {
            return (string) $value_node;
        }

        $child = $children[0];
        $type  = $child->getName();

        switch ( $type ) {
            case 'int':
            case 'i4':
            case 'i8':
                return (int) (string) $child;

            case 'boolean':
                return ( (string) $child === '1' );

            case 'double':
                return (float) (string) $child;

            case 'string':
            case 'dateTime.iso8601':
            case 'base64':
                return (string) $child;

            case 'nil':
                return null;

            case 'array':
                $result = [];
                if ( isset( $child->data->value ) ) {
                    foreach ( $child->data->value as $item ) {
                        $result[] = $this->decode_value( $item );
                    }
                }
                return $result;

            case 'struct':
                $result = [];
                if ( isset( $child->member ) ) {
                    foreach ( $child->member as $member ) {
                        $key = (string) $member->name;
                        $result[ $key ] = $this->decode_value( $member->value );
                    }
                }
                return $result;

            default:
                return (string) $child;
        }
    }

    /**
     * Comprueba si un array es asociativo.
     */
    private function is_assoc( array $arr ) {
        if ( empty( $arr ) ) return false;
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }
}
