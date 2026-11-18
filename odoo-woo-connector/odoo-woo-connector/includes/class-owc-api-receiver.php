<?php
/**
 * API REST Receiver
 * Expone endpoints en WordPress para que el servicio Python de Odoo
 * pueda enviar productos y stock actualizados a WooCommerce.
 *
 * Base URL: /wp-json/odoo-sync/v1/
 *
 * Endpoints disponibles:
 *   POST /wp-json/odoo-sync/v1/push-products    → Crear/actualizar productos y precios
 *   POST /wp-json/odoo-sync/v1/push-categories  → Sincronizar árbol y eliminar categorías fantasma
 *   POST /wp-json/odoo-sync/v1/push-stock        → Actualizar existencias/inventario
 *   GET  /wp-json/odoo-sync/v1/status            → Estado de la última sincronización
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_API_Receiver {

    const NAMESPACE = 'odoo-sync/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    // ─── Registro de Rutas ────────────────────────────────────────────────────
    public static function register_routes() {
        // 1. Recibir productos desde Odoo
        register_rest_route( self::NAMESPACE, '/push-products', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_push_products' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        register_rest_route( self::NAMESPACE, '/push-categories', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_push_categories' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        // 2. Recibir actualizaciones de stock desde Odoo
        register_rest_route( self::NAMESPACE, '/push-stock', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_push_stock' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        // Consultar primero; Odoo solo enviará imágenes que falten en WordPress.
        register_rest_route( self::NAMESPACE, '/missing-images', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_missing_images' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        register_rest_route( self::NAMESPACE, '/push-images', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_push_images' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        // 3. Estado del plugin y últimas sincronizaciones
        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_status' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);

        // 4. Ajustes del plugin (leído por Python para intervalos dinámicos)
        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_get_settings' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
        ]);
    }

    public static function handle_push_categories( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $rows = isset( $body['categories'] ) && is_array( $body['categories'] ) ? $body['categories'] : [];
        if ( empty( $rows ) ) return new WP_REST_Response( [ 'success'=>false, 'error'=>'No se recibieron categorías.' ], 400 );

        $map = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['id'] ?? 0 );
            $name = sanitize_text_field( $row['name'] ?? '' );
            if ( $id && $name ) $map[ $id ] = [ 'id'=>$id, 'name'=>$name, 'parent_id'=>absint( $row['parent_id'] ?? 0 ) ];
        }
        if ( empty( $map ) ) return new WP_REST_Response( [ 'success'=>false, 'error'=>'El árbol recibido no es válido.' ], 400 );

        $paths = [];
        $building = [];
        $build = function( $id ) use ( &$build, &$paths, &$building, $map ) {
            $id = (int) $id;
            if ( isset( $paths[ $id ] ) ) return $paths[ $id ];
            if ( ! isset( $map[ $id ] ) || isset( $building[ $id ] ) ) return [];
            $building[ $id ] = true;
            $row = $map[ $id ];
            $path = $row['parent_id'] ? $build( $row['parent_id'] ) : [];
            if ( strcasecmp( $row['name'], 'All' ) !== 0 && strcasecmp( $row['name'], 'Todas' ) !== 0 ) $path[] = $row['name'];
            unset( $building[ $id ] );
            return $paths[ $id ] = $path;
        };

        $keep = [];
        $expected = 0;
        $synced = 0;
        foreach ( array_keys( $map ) as $id ) {
            $path = $build( $id );
            if ( ! $path ) continue;
            $expected++;
            $term_id = OWC_Sync_Engine::ensure_wc_category_path( $path );
            if ( $term_id ) { $keep[] = (int) $term_id; $synced++; }
        }
        if ( $expected === 0 || $synced !== $expected ) {
            return new WP_REST_Response( [ 'success'=>false, 'error'=>'No se pudo validar todo el árbol; no se eliminó ninguna categoría.', 'synced'=>$synced ], 500 );
        }

        $default = (int) get_option( 'default_product_cat', 0 );
        if ( $default ) $keep[] = $default;
        $keep = array_values( array_unique( $keep ) );
        $removed = 0;
        if ( ! empty( $body['delete_missing'] ) ) {
            $terms = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'fields'=>'ids' ] );
            if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term_id ) {
                if ( ! in_array( (int) $term_id, $keep, true ) ) {
                    $result = wp_delete_term( (int) $term_id, 'product_cat' );
                    if ( $result && ! is_wp_error( $result ) ) $removed++;
                }
            }
        }
        OWC_Sync_Logger::log( 'push-categories', "Sincronizadas: {$synced}; fantasmas eliminadas: {$removed}.", 'success' );
        return new WP_REST_Response( [ 'success'=>true, 'synced'=>$synced, 'removed'=>$removed ], 200 );
    }

    // ─── Autenticación por Token ──────────────────────────────────────────────
    public static function check_token( WP_REST_Request $request ) {
        $token = $request->get_header( 'X-Odoo-Token' );
        if ( ! $token ) {
            $token = $request->get_param( 'token' );
        }
        $saved_token = OWC_Settings::get( 'api_secret_token' );
        if ( empty( $saved_token ) || $token !== $saved_token ) {
            return new WP_Error( 'owc_unauthorized', 'Token de autenticación inválido o faltante.', [ 'status' => 401 ] );
        }
        return true;
    }

    // ─── Endpoint: Push Productos ─────────────────────────────────────────────
    /**
     * Espera un body JSON:
     * {
     *   "products": [
     *     {
     *       "sku": "REF-001",
     *       "name": "Laptop HP 15",
     *       "price": "2500.00",
     *       "description": "Descripción del producto",
     *       "stock": 15,
     *       "manage_stock": true,
     *       "weight": "1.5",
     *       "category": "Laptops",
     *       "image_base64": "<base64-encoded PNG/JPEG desde Odoo>"  ← imagen principal
     *     },
     *     ...
     *   ]
     * }
     */
    public static function handle_push_products( WP_REST_Request $request ) {
        $body     = $request->get_json_params();
        $products = isset( $body['products'] ) ? $body['products'] : [];

        if ( empty( $products ) || ! is_array( $products ) ) {
            return new WP_REST_Response( [ 'error' => 'No se recibieron productos válidos.' ], 400 );
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ( $products as $prod_data ) {
            $sku = isset( $prod_data['sku'] ) ? sanitize_text_field( $prod_data['sku'] ) : '';
            if ( ! $sku ) {
                $skipped++;
                continue;
            }

            $result = self::upsert_product( $sku, $prod_data );
            if ( $result === 'created' )  $created++;
            elseif ( $result === 'updated' ) $updated++;
            elseif ( is_string( $result ) && strpos( $result, 'error' ) !== false ) {
                $errors[] = $result;
                $skipped++;
            }
        }

        OWC_Settings::set_last_sync( 'products' );
        OWC_Sync_Logger::log( 'push-products', "Creados: {$created}, Actualizados: {$updated}, Omitidos/Error: {$skipped}", count($errors) > 0 ? 'warning' : 'success' );

        return new WP_REST_Response([
            'success'  => true,
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'received' => count( $products ),
        ], 200 );
    }

    public static function handle_missing_images( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $skus = isset( $body['skus'] ) && is_array( $body['skus'] ) ? $body['skus'] : [];
        $missing = [];

        foreach ( array_slice( $skus, 0, 500 ) as $raw_sku ) {
            $sku = sanitize_text_field( $raw_sku );
            if ( ! $sku ) continue;
            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $product_id ) continue;
            $product = wc_get_product( $product_id );
            if ( $product && ! $product->get_image_id() ) $missing[] = $sku;
        }

        return new WP_REST_Response( [
            'success'      => true,
            'missing_skus' => $missing,
        ], 200 );
    }

    public static function handle_push_images( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        $images = isset( $body['images'] ) && is_array( $body['images'] ) ? $body['images'] : [];
        $uploaded = 0;
        $skipped = 0;
        $errors = [];

        foreach ( array_slice( $images, 0, 5 ) as $item ) {
            $sku = sanitize_text_field( $item['sku'] ?? '' );
            $image_b64 = $item['image_b64'] ?? '';
            $product_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
            $product = $product_id ? wc_get_product( $product_id ) : false;
            if ( ! $product || ! $image_b64 ) { $skipped++; continue; }

            // Comprobación final: una imagen existente no se decodifica ni procesa.
            if ( $product->get_image_id() ) { $skipped++; continue; }

            $image_id = self::set_product_image_from_base64( $product_id, $image_b64, $sku );
            if ( is_wp_error( $image_id ) ) {
                $errors[] = "SKU {$sku}: " . $image_id->get_error_message();
                continue;
            }
            if ( $image_id ) {
                $product->set_image_id( $image_id );
                $product->save();
                $uploaded++;
            }
        }

        return new WP_REST_Response( [
            'success'  => true,
            'uploaded' => $uploaded,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], 200 );
    }

    // ─── Lógica: Crear o Actualizar Producto en WooCommerce ──────────────────
    private static function upsert_product( $sku, $data ) {
        // Buscar producto existente por SKU
        $args = [
            'post_type'  => 'product',
            'meta_key'   => '_sku',
            'meta_value' => $sku,
            'fields'     => 'ids',
            'numberposts'=> 1,
        ];
        $posts = get_posts( $args );
        $product_id = ! empty( $posts ) ? $posts[0] : null;

        $name        = sanitize_text_field( $data['name'] ?? '' );
        $price       = sanitize_text_field( $data['price'] ?? '0' );
        $description = wp_kses_post( $data['description'] ?? '' );
        $weight      = sanitize_text_field( $data['weight'] ?? '' );
        $stock       = isset( $data['stock'] ) ? (int) $data['stock'] : null;
        $manage_stk  = isset( $data['manage_stock'] ) ? (bool) $data['manage_stock'] : true;
        $category    = isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '';
        $dist_price  = isset( $data['distributor_price'] ) ? sanitize_text_field( $data['distributor_price'] ) : '';
        $image_b64   = $data['image_base64'] ?? $data['image_b64'] ?? $data['image'] ?? ''; // base64 desde Odoo
        $status      = sanitize_key( $data['status'] ?? 'publish' );
        $status      = in_array( $status, [ 'publish', 'draft' ], true ) ? $status : 'publish';

        try {
            if ( $product_id ) {
                // ── Actualizar producto existente ──
                $product = wc_get_product( $product_id );
                if ( ! $product ) return 'error: wc_get_product devolvió nulo para ID ' . $product_id;

                if ( $name )  $product->set_name( $name );
                if ( $price ) $product->set_regular_price( $price );
                if ( $description ) $product->set_description( $description );
                if ( $weight ) $product->set_weight( $weight );
                $product->set_status( $status );

                $product->set_manage_stock( $manage_stk );
                if ( $manage_stk && $stock !== null ) {
                    $product->set_stock_quantity( $stock );
                    $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
                }

                if ( $dist_price !== '' ) {
                    $product->update_meta_data( '_owc_distributor_price', $dist_price );
                }

                // ── Imagen principal desde Odoo ──
                if ( ! empty( $image_b64 ) ) {
                    $img_id = self::set_product_image_from_base64( $product_id, $image_b64, $sku );
                    if ( is_wp_error( $img_id ) ) {
                        return 'error: Error de imagen - ' . $img_id->get_error_message();
                    } elseif ( $img_id ) {
                        $product->set_image_id( $img_id );
                    }
                }

                // Categoría
                if ( $category ) {
                    $cat_id = OWC_Sync_Engine::ensure_wc_category_path( OWC_Sync_Engine::split_category_path( $category ) );
                    if ( $cat_id ) {
                        $product->set_category_ids( [ $cat_id ] );
                    }
                }

                $product->save();
                return 'updated';
            } else {
                // ── Crear nuevo producto ──
                $product = new WC_Product_Simple();
                $product->set_name( $name ?: 'Producto Odoo (' . $sku . ')' );
                $product->set_sku( $sku );
                $product->set_regular_price( $price );
                $product->set_description( $description );
                if ( $weight ) $product->set_weight( $weight );
                $product->set_status( $status );
                $product->set_catalog_visibility( 'visible' );

                $product->set_manage_stock( $manage_stk );
                if ( $manage_stk && $stock !== null ) {
                    $product->set_stock_quantity( $stock );
                    $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
                }

                // Categoría
                if ( $category ) {
                    $cat_id = OWC_Sync_Engine::ensure_wc_category_path( OWC_Sync_Engine::split_category_path( $category ) );
                    if ( $cat_id ) $product->set_category_ids( [ $cat_id ] );
                }

                if ( $dist_price !== '' ) {
                    $product->add_meta_data( '_owc_distributor_price', $dist_price );
                }

                // Guardar primero para obtener el post ID y poder adjuntar la imagen
                $new_product_id = $product->save();

                // ── Imagen principal desde Odoo ──
                if ( ! empty( $image_b64 ) && $new_product_id ) {
                    $img_id = self::set_product_image_from_base64( $new_product_id, $image_b64, $sku );
                    if ( is_wp_error( $img_id ) ) {
                        return 'error: Creado, pero error de imagen - ' . $img_id->get_error_message();
                    } elseif ( $img_id ) {
                        $product->set_image_id( $img_id );
                        $product->save();
                    }
                }

                return 'created';
            }
        } catch ( Exception $e ) {
            return 'error: ' . $e->getMessage();
        }
    }

    private static function set_product_image_from_base64( $product_id, $image_b64, $sku ) {
        // Necesitamos las funciones de manejo de archivos de WP
        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // ── Limpiar base64 ──────────────────────────────────────────────────
        $image_b64 = preg_replace( '#^data:image/[^;]+;base64,#i', '', trim( $image_b64 ) );
        $image_b64 = preg_replace( '/\s+/', '', $image_b64 );

        // Decodificar
        $image_data = base64_decode( $image_b64, true );
        if ( $image_data === false || empty( $image_data ) ) {
            return new WP_Error( 'owc_image_decode', 'No se pudo decodificar la imagen base64 para SKU: ' . $sku );
        }

        $new_md5 = md5( $image_data );

        // ── Verificar si el producto ya tiene una imagen con el mismo contenido ──
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $current_img_id = (int) $product->get_image_id();
            if ( $current_img_id > 0 ) {
                $current_file = get_attached_file( $current_img_id );
                if ( $current_file && file_exists( $current_file ) ) {
                    $current_md5 = md5_file( $current_file );
                    if ( $current_md5 === $new_md5 ) {
                        // Imagen idéntica → no subir, devolver el attachment actual
                        return $current_img_id;
                    }
                    // Imagen cambió → eliminar el attachment anterior para no acumular basura
                    wp_delete_attachment( $current_img_id, true );
                }
            }
        }

        // ── Detectar MIME y extensión ────────────────────────────────────────
        $finfo   = new finfo( FILEINFO_MIME_TYPE );
        $mime    = $finfo->buffer( $image_data );
        $ext_map = [ 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
        $ext     = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'png';
        $filename = 'odoo-' . sanitize_file_name( $sku ) . '.' . $ext;

        // ── Subir al directorio de uploads ───────────────────────────────────
        $upload = wp_upload_bits( $filename, null, $image_data );
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'owc_image_upload', 'Error subiendo imagen: ' . $upload['error'] );
        }

        // ── Registrar como adjunto en la Biblioteca de Medios ───────────────
        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name( $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $upload['file'], $product_id );

        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }

        // Generar miniaturas según los tamaños registrados en WordPress
        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        return $attach_id;
    }

    // ─── Endpoint: Push Stock ─────────────────────────────────────────────────
    /**
     * Espera un body JSON:
     * {
     *   "stock": [
     *     { "sku": "REF-001", "qty": 10 },
     *     { "sku": "REF-002", "qty": 0  }
     *   ]
     * }
     */
    public static function handle_push_stock( WP_REST_Request $request ) {
        $body      = $request->get_json_params();
        $stock_list = isset( $body['stock'] ) ? $body['stock'] : [];

        if ( empty( $stock_list ) || ! is_array( $stock_list ) ) {
            return new WP_REST_Response( [ 'error' => 'No se recibió información de stock válida.' ], 400 );
        }

        $updated = 0;
        $not_found = 0;
        $errors = [];

        foreach ( $stock_list as $item ) {
            $sku = sanitize_text_field( $item['sku'] ?? '' );
            $qty = isset( $item['qty'] ) ? max( 0, (int) $item['qty'] ) : 0;

            if ( ! $sku ) { $not_found++; continue; }

            // Buscar producto por SKU
            $product_id = wc_get_product_id_by_sku( $sku );

            if ( ! $product_id ) {
                $not_found++;
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) { $errors[] = "Error cargando producto SKU: {$sku}"; continue; }

            try {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                $product->save();
                $updated++;
            } catch ( Exception $e ) {
                $errors[] = "Error actualizando stock SKU {$sku}: " . $e->getMessage();
            }
        }

        OWC_Settings::set_last_sync( 'stock' );
        OWC_Sync_Logger::log( 'push-stock', "Actualizados: {$updated}, No encontrados: {$not_found}", count($errors) > 0 ? 'warning' : 'success' );

        return new WP_REST_Response([
            'success'    => true,
            'updated'    => $updated,
            'not_found'  => $not_found,
            'errors'     => $errors,
            'total_sent' => count( $stock_list ),
        ], 200 );
    }

    // ─── Endpoint: Status ─────────────────────────────────────────────────────
    public static function handle_status( WP_REST_Request $request ) {
        return new WP_REST_Response([
            'plugin'              => 'Odoo 15 WooCommerce Connector',
            'version'             => OWC_VERSION,
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
            'last_sync_products'  => OWC_Settings::get('last_sync_products') ?: 'Nunca',
            'last_sync_stock'     => OWC_Settings::get('last_sync_stock')    ?: 'Nunca',
            'last_sync_orders'    => OWC_Settings::get('last_sync_orders')   ?: 'Nunca',
            'status'              => 'ok',
        ], 200 );
    }

    // ─── Endpoint: Ajustes del plugin ──────────────────────────────────────────
    public static function handle_get_settings( WP_REST_Request $request ) {
        return new WP_REST_Response( [
            'image_sync_interval_minutes' => (int) OWC_Settings::get( 'image_sync_interval_minutes', 2 ),
        ], 200 );
    }
}
