<?php
/**
 * Motor de Sincronización Odoo -> WooCommerce (PHP Nativo Optimizado)
 * Ejecuta la importación por lotes para evitar sobrecarga del servidor y caídas de página.
 *
 * @package  OdooWooConnector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Sync_Engine {

    const CACHE_KEY_COMPARE = 'owc_compare_catalog_cache';
    const CACHE_KEY_PRODUCTS = 'owc_cached_odoo_products';

    private static $odoo_category_paths = [];

    /**
     * Replica en WooCommerce la ruta completa de una categoría de Odoo.
     * Devuelve el ID del último nivel (la categoría que recibirá el producto).
     */
    public static function sync_odoo_category_tree( $client, $categ_id, $fallback_name = '' ) {
        $categ_id = (int) $categ_id;
        if ( $categ_id > 0 && isset( self::$odoo_category_paths[ $categ_id ] ) ) {
            return self::ensure_wc_category_path( self::$odoo_category_paths[ $categ_id ] );
        }

        $path = [];
        $seen = [];
        $current_id = $categ_id;

        // product.category es jerárquica; recorremos parent_id hasta la raíz.
        while ( $current_id > 0 && ! isset( $seen[ $current_id ] ) ) {
            $seen[ $current_id ] = true;
            $rows = $client->execute_kw(
                'product.category',
                'read',
                [ [ $current_id ] ],
                [ 'fields' => [ 'id', 'name', 'parent_id' ] ]
            );
            if ( ! is_array( $rows ) || empty( $rows[0]['name'] ) ) break;

            array_unshift( $path, sanitize_text_field( $rows[0]['name'] ) );
            $parent = $rows[0]['parent_id'] ?? false;
            $current_id = is_array( $parent ) ? (int) ( $parent[0] ?? 0 ) : 0;
        }

        if ( empty( $path ) && $fallback_name ) {
            $path = self::split_category_path( $fallback_name );
        }
        if ( $categ_id > 0 ) self::$odoo_category_paths[ $categ_id ] = $path;
        return self::ensure_wc_category_path( $path );
    }

    public static function split_category_path( $category ) {
        $parts = preg_split( '/\s*(?:\/|>|»|→)\s*/u', (string) $category, -1, PREG_SPLIT_NO_EMPTY );
        return array_values( array_filter( array_map( 'sanitize_text_field', $parts ), function( $name ) {
            return $name !== '' && strcasecmp( $name, 'All' ) !== 0 && strcasecmp( $name, 'Todas' ) !== 0;
        } ) );
    }

    public static function ensure_wc_category_path( array $path ) {
        $parent_id = 0;
        foreach ( $path as $name ) {
            $name = sanitize_text_field( $name );
            if ( $name === '' ) continue;

            // Buscar por nombre y padre evita confundir ramas con nombres iguales.
            $terms = get_terms( [
                'taxonomy' => 'product_cat', 'hide_empty' => false,
                'name' => $name, 'parent' => $parent_id, 'number' => 1,
            ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $parent_id = (int) $terms[0]->term_id;
                continue;
            }

            $created = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent_id ] );
            if ( is_wp_error( $created ) ) {
                // term_exists puede devolver el ID cuando otro proceso lo creó a la vez.
                $existing = term_exists( $name, 'product_cat', $parent_id );
                if ( ! $existing ) return 0;
                $parent_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
            } else {
                $parent_id = (int) $created['term_id'];
            }
        }
        return $parent_id;
    }

    /** Sincroniza también categorías sin productos para conservar todo el árbol de Odoo. */
    public static function sync_all_odoo_categories( $client ) {
        $rows = $client->search_read( 'product.category', [], [ 'id', 'name', 'parent_id' ] );
        if ( ! is_array( $rows ) ) return 0;

        $map = [];
        foreach ( $rows as $row ) {
            if ( ! empty( $row['id'] ) && ! empty( $row['name'] ) ) $map[ (int) $row['id'] ] = $row;
        }
        $paths = [];
        $building = [];
        $build = function( $id ) use ( &$build, &$paths, &$building, $map ) {
            $id = (int) $id;
            if ( isset( $paths[ $id ] ) ) return $paths[ $id ];
            if ( ! isset( $map[ $id ] ) || isset( $building[ $id ] ) ) return [];
            $building[ $id ] = true;
            $row = $map[ $id ];
            $parent = $row['parent_id'] ?? false;
            $path = is_array( $parent ) && ! empty( $parent[0] ) ? $build( (int) $parent[0] ) : [];
            $name = sanitize_text_field( $row['name'] );
            if ( $name !== '' && strcasecmp( $name, 'All' ) !== 0 && strcasecmp( $name, 'Todas' ) !== 0 ) $path[] = $name;
            unset( $building[ $id ] );
            return $paths[ $id ] = $path;
        };

        $synced = 0;
        $expected = 0;
        $keep_term_ids = [];
        foreach ( array_keys( $map ) as $id ) {
            $path = $build( $id );
            self::$odoo_category_paths[ $id ] = $path;
            if ( $path ) {
                $expected++;
                $term_id = self::ensure_wc_category_path( $path );
                if ( $term_id ) {
                    $keep_term_ids[] = (int) $term_id;
                    $synced++;
                }
            }
        }

        // Espejo estricto: eliminar de WooCommerce categorías que no existen en Odoo.
        // Solo se llega aquí después de recibir una lista válida y no vacía desde Odoo.
        $default_term_id = (int) get_option( 'default_product_cat', 0 );
        if ( $default_term_id ) $keep_term_ids[] = $default_term_id;
        $keep_term_ids = array_values( array_unique( array_filter( $keep_term_ids ) ) );
        $woo_terms = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'fields'=>'ids' ] );
        $removed = 0;
        if ( $expected > 0 && $synced === $expected && ! is_wp_error( $woo_terms ) ) {
            foreach ( $woo_terms as $term_id ) {
                $term_id = (int) $term_id;
                if ( ! in_array( $term_id, $keep_term_ids, true ) ) {
                    $deleted = wp_delete_term( $term_id, 'product_cat' );
                    if ( ! is_wp_error( $deleted ) && $deleted ) $removed++;
                }
            }
        }
        if ( $removed > 0 && class_exists( 'OWC_Sync_Logger' ) ) {
            OWC_Sync_Logger::log( 'category_cleanup', "Se eliminaron {$removed} categorías fantasma que no existen en Odoo.", 'success' );
        }
        return $synced;
    }

    /**
     * Obtiene el cliente XML-RPC configurado
     */
    public static function get_client() {
        if ( ! class_exists( 'OdooXmlRpc' ) ) {
            require_once OWC_PLUGIN_DIR . 'includes/class-owc-xmlrpc.php';
        }
        $url  = OWC_Settings::get('odoo_url');
        $db   = OWC_Settings::get('odoo_db');
        $user = OWC_Settings::get('odoo_user');
        $pass = OWC_Settings::get('odoo_password');

        if ( ! $url || ! $db || ! $user || ! $pass ) {
            return null;
        }
        return new OdooXmlRpc( $url, $db, $user, $pass );
    }

    /**
     * Prueba la conexión con Odoo
     */
    public static function test_connection() {
        $client = self::get_client();
        if ( ! $client ) {
            $msg = 'Por favor completa la URL, Base de Datos, Usuario y Contraseña de Odoo.';
            OWC_Sync_Logger::log( 'connection_test', $msg, 'error' );
            return [ 'success' => false, 'error' => $msg ];
        }

        $res = $client->test_connection();
        if ( ! empty( $res['success'] ) ) {
            OWC_Sync_Logger::log( 'connection_test', "Conexión exitosa con Odoo. Versión: {$res['server_version']} | UID: {$res['uid']}", 'success' );
        } else {
            OWC_Sync_Logger::log( 'connection_test', "Fallo de conexión: " . ( $res['error'] ?? 'Desconocido' ), 'error' );
        }
        return $res;
    }

    /**
     * Construye un índice en memoria de todos los productos de WooCommerce en 1 SOLA consulta SQL.
     * Esto evita ejecutar miles de consultas dentro del bucle y hace que la sincronización sea 10x más rápida.
     */
    public static function build_wc_index() {
        global $wpdb;

        $rows = $wpdb->get_results( "
            SELECT p.ID, p.post_title,
                   MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) as sku,
                   MAX(CASE WHEN pm.meta_key = '_odoo_product_id' THEN pm.meta_value END) as odoo_id
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_sku', '_odoo_product_id')
            WHERE p.post_type = 'product' AND p.post_status != 'trash'
            GROUP BY p.ID, p.post_title
        ", ARRAY_A );

        $by_odoo_id = [];
        $by_sku     = [];
        $by_name    = [];

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $r ) {
                $id = (int) $r['ID'];
                $odoo_id = (int) ( $r['odoo_id'] ?? 0 );
                $sku = trim( (string) ( $r['sku'] ?? '' ) );
                $name = mb_strtolower( trim( (string) $r['post_title'] ) );

                if ( $odoo_id > 0 ) {
                    $by_odoo_id[ $odoo_id ] = $id;
                }
                if ( $sku !== '' ) {
                    $by_sku[ mb_strtolower( $sku ) ] = $id;
                }
                if ( $name !== '' ) {
                    $by_name[ $name ] = $id;
                }
            }
        }

        return [
            'by_odoo_id' => $by_odoo_id,
            'by_sku'     => $by_sku,
            'by_name'    => $by_name,
        ];
    }

    /**
     * Búsqueda en memoria instantánea (0 consultas SQL adicionales)
     */
    public static function lookup_wc_id( array &$index, $odoo_id, $sku, $name, $allow_name = true ) {
        $odoo_id = (int) $odoo_id;
        if ( $odoo_id > 0 && isset( $index['by_odoo_id'][ $odoo_id ] ) ) {
            return $index['by_odoo_id'][ $odoo_id ];
        }

        $sku_clean = mb_strtolower( trim( (string) $sku ) );
        if ( $sku_clean !== '' && isset( $index['by_sku'][ $sku_clean ] ) ) {
            return $index['by_sku'][ $sku_clean ];
        }

        if ( $allow_name ) {
            $name_clean = mb_strtolower( trim( (string) $name ) );
            if ( $name_clean !== '' && isset( $index['by_name'][ $name_clean ] ) ) {
                return $index['by_name'][ $name_clean ];
            }
        }

        return null;
    }

    /**
     * Registra un nuevo producto en el índice en memoria para evitar duplicados en el mismo lote
     */
    public static function register_in_index( array &$index, $wc_id, $odoo_id, $sku, $name ) {
        $wc_id = (int) $wc_id;
        $odoo_id = (int) $odoo_id;
        if ( $odoo_id > 0 ) $index['by_odoo_id'][ $odoo_id ] = $wc_id;

        $sku_clean = mb_strtolower( trim( (string) $sku ) );
        if ( $sku_clean !== '' ) $index['by_sku'][ $sku_clean ] = $wc_id;

        $name_clean = mb_strtolower( trim( (string) $name ) );
        if ( $name_clean !== '' ) $index['by_name'][ $name_clean ] = $wc_id;
    }

    /**
     * Obtiene el SKU desde Referencia Interna o Código de Barras.
     * Respeta el campo preferido y usa el otro automáticamente como respaldo.
     */
    public static function get_odoo_sku( array $product, $match_field = 'default_code' ) {
        $preferred = $match_field === 'barcode' ? 'barcode' : 'default_code';
        $alternate = $preferred === 'barcode' ? 'default_code' : 'barcode';
        $sku = trim( (string) ( $product[ $preferred ] ?? '' ) );

        if ( $sku === '' ) {
            $sku = trim( (string) ( $product[ $alternate ] ?? '' ) );
        }

        return $sku;
    }

    /**
     * Localiza un producto en WooCommerce individualmente
     */
    public static function find_wc_product( $odoo_id, $sku = '', $name = '' ) {
        global $wpdb;

        if ( ! empty( $odoo_id ) ) {
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_odoo_product_id' AND meta_value = %s LIMIT 1",
                (string) $odoo_id
            ) );
            if ( $post_id ) {
                $p = wc_get_product( $post_id );
                if ( $p ) return $p;
            }
        }

        if ( ! empty( $sku ) ) {
            $product_id = wc_get_product_id_by_sku( $sku );
            if ( $product_id ) {
                $p = wc_get_product( $product_id );
                if ( $p ) {
                    update_post_meta( $product_id, '_odoo_product_id', $odoo_id );
                    return $p;
                }
            }
        }

        if ( ! empty( $name ) ) {
            $clean_name = sanitize_text_field( $name );
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status != 'trash' LIMIT 1",
                $clean_name
            ) );
            if ( $post_id ) {
                $p = wc_get_product( $post_id );
                if ( $p ) {
                    update_post_meta( $post_id, '_odoo_product_id', $odoo_id );
                    if ( empty( $p->get_sku() ) && ! empty( $sku ) ) {
                        $p->set_sku( $sku );
                        $p->save();
                    }
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Procesa un lote (batch) de productos de Odoo hacia WooCommerce
     * Permite que la importación se divida en bloques pequeños (ej. 30 productos),
     * garantizando que el servidor NUNCA se cuelgue ni exceda tiempos de ejecución.
     */
    public static function sync_products_batch( $offset = 0, $batch_size = 25, $force_create = false ) {
        @set_time_limit( 120 );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $client = self::get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'error' => 'Credenciales de Odoo no configuradas.' ];
        }

        // Primer lote: traer el árbol completo, incluso ramas todavía sin productos.
        if ( (int) $offset === 0 ) self::sync_all_odoo_categories( $client );

        $match_field = OWC_Settings::get('sync_match_field', 'default_code');
        // Si force_create=true (llamada desde botón manual "Importar Todo"), siempre crear.
        // Esto evita el bug donde auto_create_products se guardaba como 'no' accidentalmente.
        $auto_create = $force_create || ( OWC_Settings::get('auto_create_products', 'yes') === 'yes' );

        // En el primer lote (offset 0), consultar Odoo y almacenar en caché temporal
        $all_products = ( $offset === 0 ) ? false : get_transient( self::CACHE_KEY_PRODUCTS );

        if ( false === $all_products || ! is_array( $all_products ) ) {
            $all_products = $client->get_odoo_products( $match_field, 3000 );
            if ( ! is_array( $all_products ) ) {
                return [ 'success' => false, 'error' => $client->get_last_error() ?: 'Error al consultar productos en Odoo.' ];
            }
            set_transient( self::CACHE_KEY_PRODUCTS, $all_products, 600 ); // 10 minutos
        }

        $total_count = count( $all_products );
        if ( $total_count === 0 ) {
            return [
                'success' => true,
                'total'   => 0,
                'done'    => true,
                'message' => 'No se encontraron productos en Odoo para importar.',
            ];
        }

        // Obtener el fragmento correspondiente
        $chunk = array_slice( $all_products, $offset, $batch_size );
        if ( empty( $chunk ) ) {
            delete_transient( self::CACHE_KEY_PRODUCTS );
            delete_transient( self::CACHE_KEY_COMPARE );
            return [
                'success' => true,
                'total'   => $total_count,
                'done'    => true,
                'message' => "Importación completada para todos los {$total_count} productos.",
            ];
        }

        // Cargar índice en memoria de WooCommerce (1 sola consulta SQL)
        $wc_index = self::build_wc_index();

        $batch_created = 0;
        $batch_updated = 0;
        $created_names = [];

        foreach ( $chunk as $p ) {
            $odoo_id = (int) $p['id'];
            $name    = sanitize_text_field( $p['name'] ?? '' );
            if ( ! $name ) continue;

            $sku = self::get_odoo_sku( $p, $match_field );
            if ( empty( $sku ) ) $sku = 'ODOO-' . $odoo_id;

            // El stock solo se enlaza por ID Odoo o SKU; nunca por nombre.
            $wc_id = self::lookup_wc_id( $wc_index, $odoo_id, $sku, $name, false );
            $wc_product = null;

            if ( $wc_id ) {
                $wc_product = wc_get_product( $wc_id );
                if ( $wc_product ) {
                    $batch_updated++;
                }
            }

            if ( ! $wc_product ) {
                if ( ! $auto_create ) continue;
                $wc_product = new WC_Product_Simple();
                $wc_product->set_status( 'publish' );
                $batch_created++;
                $created_names[] = $name;
            }

            $wc_product->set_name( $name );
            $wc_product->set_sku( $sku );

            if ( isset( $p['public_price'] ) && (float)$p['public_price'] >= 0 ) {
                $price = (float) $p['public_price'];
                $wc_product->set_regular_price( $price );
                $wc_product->set_price( $price );
            }
            if ( isset( $p['ferretero_price'] ) ) {
                $wc_product->update_meta_data( '_owc_distributor_price', (string) $p['ferretero_price'] );
            }

            if ( ! empty( $p['description_sale'] ) ) {
                $wc_product->set_description( wp_kses_post( $p['description_sale'] ) );
            }

            if ( isset( $p['weight'] ) && (float)$p['weight'] > 0 ) {
                $wc_product->set_weight( (float)$p['weight'] );
            }

            $wc_product->set_manage_stock( true );
            $qty = (int) round( (float)( $p['free_qty'] ?? $p['qty_available'] ?? 0 ) );
            $wc_product->set_stock_quantity( $qty );
            $wc_product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );

            // Categoría
            if ( ! empty( $p['categ_id'] ) && is_array( $p['categ_id'] ) && count( $p['categ_id'] ) > 1 ) {
                $cat_name = sanitize_text_field( $p['categ_id'][1] );
                if ( $cat_name && $cat_name !== 'All' && $cat_name !== 'Todas' ) {
                    $cat_id = self::sync_odoo_category_tree( $client, (int) $p['categ_id'][0], $cat_name );
                    if ( $cat_id ) {
                        $wc_product->set_category_ids( [ $cat_id ] );
                    }
                }
            }

            $saved_id = $wc_product->save();
            if ( $saved_id ) {
                update_post_meta( $saved_id, '_odoo_product_id', $odoo_id );
                if ( OWC_Settings::get( 'sync_images', 'yes' ) === 'yes' && ! $wc_product->get_image_id() ) {
                    self::import_odoo_product_image( $client, $odoo_id, $saved_id, $sku );
                }
                self::register_in_index( $wc_index, $saved_id, $odoo_id, $sku, $name );
            }
        }

        $next_offset = $offset + count( $chunk );
        $is_done = ( $next_offset >= $total_count );

        if ( $is_done ) {
            delete_transient( self::CACHE_KEY_PRODUCTS );
            delete_transient( self::CACHE_KEY_COMPARE );
            OWC_Settings::set_last_sync( 'products' );
            OWC_Sync_Logger::log( 'sync-products-wp', "Importación por lotes completada. {$total_count} productos procesados.", 'success' );
        }

        $percent = $total_count > 0 ? min( 100, round( ( $next_offset / $total_count ) * 100 ) ) : 100;

        return [
            'success'       => true,
            'offset'        => $next_offset,
            'total'         => $total_count,
            'percent'       => $percent,
            'batch_created' => $batch_created,
            'batch_updated' => $batch_updated,
            'done'          => $is_done,
            'created_names' => $created_names,
        ];
    }

    /**
     * Sincroniza e Importa el Catálogo Completo (Ejecución individual / cron)
     */
    public static function sync_products() {
        @set_time_limit( 300 );
        $res = self::sync_products_batch( 0, 5000 );
        if ( ! empty( $res['success'] ) ) {
            $msg = "Catálogo sincronizado exitosamente ({$res['total']} productos procesados de Odoo).";
            return [
                'success' => true,
                'total'   => $res['total'],
                'message' => $msg,
            ];
        }
        return $res;
    }

    /**
     * Sincroniza solo el stock desde Odoo
     * Actualiza existencias de forma ultra-rápida usando índice en memoria.
     */
    public static function sync_stock() {
        @set_time_limit( 180 );

        $client = self::get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'error' => 'Credenciales de Odoo incompletas.' ];
        }

        $match_field = OWC_Settings::get('sync_match_field', 'default_code');
        $auto_create = OWC_Settings::get('auto_create_products', 'yes') === 'yes';
        $stock_items = $client->get_odoo_stock( $match_field, 2500 );

        if ( empty( $stock_items ) ) {
            $err = $client->get_last_error();
            if ( $err ) {
                return [ 'success' => false, 'error' => 'Error consultando stock en Odoo: ' . $err ];
            }
            return [ 'success' => true, 'updated' => 0, 'missing' => 0, 'message' => 'No se encontraron existencias en Odoo para actualizar.' ];
        }

        // Cargar índice en memoria de WooCommerce (1 sola consulta SQL)
        $wc_index = self::build_wc_index();

        $updated = 0;
        $created = 0;
        $missing = 0;
        $missing_names = [];

        foreach ( $stock_items as $item ) {
            $odoo_id = (int) $item['id'];
            $name    = sanitize_text_field( $item['name'] ?? '' );
            $sku     = self::get_odoo_sku( $item, $match_field );
            if ( empty( $sku ) ) $sku = 'ODOO-' . $odoo_id;

            $wc_id = self::lookup_wc_id( $wc_index, $odoo_id, $sku, $name );

            if ( $wc_id ) {
                $wc_product = wc_get_product( $wc_id );
                if ( $wc_product ) {
                    $qty = (int) round( (float)( $item['free_qty'] ?? $item['qty_available'] ?? 0 ) );
                    wc_update_product_stock( $wc_product, $qty, 'set' );
                    $updated++;
                }
            } else {
                $missing++;
                $missing_names[] = $name ?: $sku;

                if ( $auto_create ) {
                    $new_p = new WC_Product_Simple();
                    $new_p->set_name( $name ?: "Producto {$sku}" );
                    $new_p->set_status( 'publish' );
                    $new_p->set_sku( $sku );
                    $new_p->set_manage_stock( true );
                    $qty = (int) round( (float)( $item['free_qty'] ?? $item['qty_available'] ?? 0 ) );
                    $new_p->set_stock_quantity( $qty );
                    $new_p->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                    $saved_id = $new_p->save();
                    if ( $saved_id ) {
                        update_post_meta( $saved_id, '_odoo_product_id', $odoo_id );
                        self::register_in_index( $wc_index, $saved_id, $odoo_id, $sku, $name );
                        $created++;
                    }
                }
            }
        }

        delete_transient( self::CACHE_KEY_COMPARE );
        OWC_Settings::set_last_sync('stock');

        $total_items = count( $stock_items );
        $msg_parts = [];
        $msg_parts[] = "✔ Stock sincronizado ({$total_items} productos procesados en Odoo).";
        $msg_parts[] = "📦 {$updated} productos actualizados en WooCommerce.";

        if ( $created > 0 ) {
            $msg_parts[] = "📥 Había {$created} productos almacenables de Odoo que faltaban en WooCommerce y fueron creados con su stock A Mano.";
        } elseif ( $missing > 0 ) {
            $preview = implode( ', ', array_slice( $missing_names, 0, 4 ) );
            if ( count( $missing_names ) > 4 ) $preview .= ' y ' . ( count( $missing_names ) - 4 ) . ' más';
            $msg_parts[] = "⚠ Hay {$missing} productos en Odoo que faltan en tu WooCommerce ({$preview}). Haz clic en 'Importar Catálogo' para crearlos todos.";
        }

        $full_message = implode( ' ', $msg_parts );

        OWC_Sync_Logger::log(
            'sync-stock-wp',
            "Stock sincronizado. Actualizados: {$updated}, Creados: {$created}, Faltantes en Woo: {$missing}.",
            'success'
        );

        return [
            'success'       => true,
            'updated'       => $updated,
            'created'       => $created,
            'missing'       => $missing,
            'missing_names' => $missing_names,
            'total'         => $total_items,
            'message'       => $full_message,
        ];
    }

    /**
     * Compara el catálogo de Odoo frente a WooCommerce con caché inteligente
     */
    public static function compare_catalogs( $force_refresh = false ) {
        global $wpdb;

        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_COMPARE );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $client = self::get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'error' => 'Credenciales de Odoo incompletas o no configuradas.' ];
        }

        $match_field = OWC_Settings::get('sync_match_field', 'default_code');

        // 1. Obtener productos de Odoo en 1 sola llamada XML-RPC
        $odoo_products = $client->get_odoo_products( $match_field, 2500 );
        if ( ! is_array( $odoo_products ) ) {
            return [ 'success' => false, 'error' => $client->get_last_error() ?: 'No se pudieron consultar los productos en Odoo.' ];
        }

        // 2. Obtener productos de WooCommerce en 1 sola consulta SQL
        $wc_raw = $wpdb->get_results( "
            SELECT p.ID, p.post_title,
                   MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) as sku,
                   MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) as stock,
                   MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price,
                   MAX(CASE WHEN pm.meta_key = '_odoo_product_id' THEN pm.meta_value END) as odoo_id
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_sku', '_stock', '_price', '_odoo_product_id')
            WHERE p.post_type = 'product' AND p.post_status != 'trash'
            GROUP BY p.ID, p.post_title
        ", ARRAY_A );

        $wc_by_odoo_id = [];
        $wc_by_sku     = [];
        $wc_by_name    = [];
        $matched_wc_ids = [];

        foreach ( $wc_raw as $w ) {
            $w_odoo_id = (int) ( $w['odoo_id'] ?? 0 );
            $w_sku     = trim( (string) ( $w['sku'] ?? '' ) );
            $w_name    = mb_strtolower( trim( (string) $w['post_title'] ) );

            if ( $w_odoo_id > 0 ) $wc_by_odoo_id[ $w_odoo_id ] = $w;
            if ( $w_sku !== '' ) $wc_by_sku[ mb_strtolower( $w_sku ) ] = $w;
            if ( $w_name !== '' ) $wc_by_name[ $w_name ] = $w;
        }

        $items = [];
        $count_in_odoo = count( $odoo_products );
        $count_missing_in_woo = 0;
        $count_synced = 0;

        foreach ( $odoo_products as $op ) {
            $o_id    = (int) $op['id'];
            $o_name  = trim( (string) ( $op['name'] ?? '' ) );
            $o_sku   = self::get_odoo_sku( $op, $match_field );
            if ( empty( $o_sku ) ) $o_sku = 'ODOO-' . $o_id;

            $o_stock = (int) round( (float)( $op['free_qty'] ?? $op['qty_available'] ?? 0 ) );
            $o_price = isset( $op['public_price'] ) ? (float) $op['public_price'] : (float) ( $op['list_price'] ?? 0 );
            $o_ferretero = isset( $op['ferretero_price'] ) ? (float) $op['ferretero_price'] : null;

            $matched_wc = null;
            if ( isset( $wc_by_odoo_id[ $o_id ] ) ) {
                $matched_wc = $wc_by_odoo_id[ $o_id ];
            } elseif ( isset( $wc_by_sku[ mb_strtolower( $o_sku ) ] ) ) {
                $matched_wc = $wc_by_sku[ mb_strtolower( $o_sku ) ];
            }

            if ( $matched_wc ) {
                $matched_wc_ids[ (int) $matched_wc['ID'] ] = true;
                $count_synced++;
                $items[] = [
                    'status'       => 'synced',
                    'status_label' => 'Sincronizado',
                    'odoo_id'      => $o_id,
                    'wc_id'        => (int) $matched_wc['ID'],
                    'name'         => $o_name,
                    'sku'          => $o_sku,
                    'odoo_stock'   => $o_stock,
                    'wc_stock'     => $matched_wc['stock'] !== null ? (int) $matched_wc['stock'] : '0',
                    'odoo_price'   => $o_price,
                    'odoo_ferretero' => $o_ferretero,
                    'wc_price'     => (float) ( $matched_wc['price'] ?? 0 ),
                ];
            } else {
                $count_missing_in_woo++;
                $items[] = [
                    'status'       => 'missing_in_woo',
                    'status_label' => 'Falta en WooCommerce',
                    'odoo_id'      => $o_id,
                    'wc_id'        => null,
                    'name'         => $o_name,
                    'sku'          => $o_sku,
                    'odoo_stock'   => $o_stock,
                    'wc_stock'     => 'No existe',
                    'odoo_price'   => $o_price,
                    'odoo_ferretero' => $o_ferretero,
                    'wc_price'     => '—',
                ];
            }
        }

        $count_only_in_woo = 0;
        foreach ( $wc_raw as $w ) {
            $w_id = (int) $w['ID'];
            if ( ! isset( $matched_wc_ids[ $w_id ] ) ) {
                $count_only_in_woo++;
                $items[] = [
                    'status'       => 'only_in_woo',
                    'status_label' => 'Solo en WooCommerce (Falta en Odoo)',
                    'odoo_id'      => null,
                    'wc_id'        => $w_id,
                    'name'         => $w['post_title'],
                    'sku'          => $w['sku'] ?: '—',
                    'odoo_stock'   => 'No existe',
                    'wc_stock'     => $w['stock'] !== null ? (int) $w['stock'] : '0',
                    'odoo_price'   => '—',
                    'wc_price'     => (float) ( $w['price'] ?? 0 ),
                ];
            }
        }

        $result = [
            'success'              => true,
            'count_in_odoo'        => $count_in_odoo,
            'count_in_woo'         => count( $wc_raw ),
            'count_synced'         => $count_synced,
            'count_missing_in_woo' => $count_missing_in_woo,
            'count_only_in_woo'    => $count_only_in_woo,
            'items'                => $items,
        ];

        set_transient( self::CACHE_KEY_COMPARE, $result, 300 ); // Caché de 5 minutos
        return $result;
    }

    /**
     * Importa un producto individual desde Odoo a WooCommerce
     */
    public static function import_single_product( $odoo_id ) {
        $client = self::get_client();
        if ( ! $client ) return [ 'success' => false, 'error' => 'Credenciales no configuradas.' ];

        $fields = [ 'id', 'name', 'default_code', 'barcode', 'list_price', 'description_sale', 'qty_available', 'free_qty', 'type', 'weight', 'categ_id', 'product_tmpl_id' ];
        $results = $client->search_read( 'product.product', [
            [ 'id', '=', (int)$odoo_id ],
            [ 'type', '=', 'product' ],
        ], $fields, 1 );

        if ( empty( $results ) ) {
            return [ 'success' => false, 'error' => "No se encontró el producto almacenable ID {$odoo_id} en Odoo." ];
        }

        $p = $results[0];
        $match_field = OWC_Settings::get('sync_match_field', 'default_code');
        $sku = self::get_odoo_sku( $p, $match_field );
        if ( empty( $sku ) ) $sku = 'ODOO-' . (int) $p['id'];

        $wc_product = self::find_wc_product( $p['id'], $sku, $p['name'] );
        $is_new = false;
        if ( ! $wc_product ) {
            $wc_product = new WC_Product_Simple();
            $wc_product->set_status('publish');
            $is_new = true;
        }

        $wc_product->set_name( sanitize_text_field( $p['name'] ) );
        $wc_product->set_sku( $sku );
        if ( isset( $p['public_price'] ) ) {
            $wc_product->set_regular_price( (float) $p['public_price'] );
            $wc_product->set_price( (float) $p['public_price'] );
        }
        if ( isset( $p['ferretero_price'] ) ) {
            $wc_product->update_meta_data( '_owc_distributor_price', (string) $p['ferretero_price'] );
        }
        if ( ! empty( $p['description_sale'] ) ) {
            $wc_product->set_description( wp_kses_post( $p['description_sale'] ) );
        }
        $wc_product->set_manage_stock( true );
        $qty = (int) round( (float)( $p['free_qty'] ?? $p['qty_available'] ?? 0 ) );
        $wc_product->set_stock_quantity( $qty );
        $wc_product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );

        $saved_id = $wc_product->save();
        if ( $saved_id ) {
            update_post_meta( $saved_id, '_odoo_product_id', (int) $p['id'] );
            if ( OWC_Settings::get( 'sync_images', 'yes' ) === 'yes' && ! $wc_product->get_image_id() ) {
                self::import_odoo_product_image( $client, (int) $p['id'], $saved_id, $sku );
            }
            delete_transient( self::CACHE_KEY_COMPARE );
            $action_word = $is_new ? 'creado e importado' : 'actualizado';
            return [
                'success' => true,
                'message' => "Producto almacenable '{$p['name']}' {$action_word} exitosamente con {$qty} uds. A Mano.",
            ];
        }

        return [ 'success' => false, 'error' => 'No se pudo guardar el producto en WooCommerce.' ];
    }

    /**
     * Copia la imagen principal de un producto de Odoo cuando WooCommerce aún no tiene una.
     */
    private static function import_odoo_product_image( $client, $odoo_id, $product_id, $sku ) {
        $records = $client->search_read( 'product.product', [ [ 'id', '=', (int) $odoo_id ] ], [ 'image_1920' ], 1 );
        $image_b64 = $records[0]['image_1920'] ?? '';

        if ( empty( $image_b64 ) ) {
            return;
        }

        $image_id = self::set_product_image_from_base64( $product_id, $image_b64, $sku );
        if ( is_wp_error( $image_id ) ) {
            OWC_Sync_Logger::log( 'sync-product-image', 'No se pudo importar la imagen de Odoo para SKU ' . $sku . ': ' . $image_id->get_error_message(), 'warning' );
            return;
        }

        if ( $image_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $product->set_image_id( $image_id );
                $product->save();
            }
        }
    }

    private static function set_product_image_from_base64( $product_id, $image_b64, $sku ) {
        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $image_b64 = preg_replace( '#^data:image/[^;]+;base64,#i', '', trim( $image_b64 ) );
        $image_b64 = preg_replace( '/\s+/', '', $image_b64 );
        $image_data = base64_decode( $image_b64, true );
        if ( false === $image_data || empty( $image_data ) ) {
            return new WP_Error( 'owc_image_decode', 'La imagen de Odoo no tiene un formato base64 válido.' );
        }

        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $mime = $finfo->buffer( $image_data );
        $extensions = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
        if ( ! isset( $extensions[ $mime ] ) ) {
            return new WP_Error( 'owc_image_type', 'El archivo de imagen recibido no es compatible.' );
        }

        $filename = 'odoo-' . sanitize_file_name( $sku ) . '.' . $extensions[ $mime ];
        $upload = wp_upload_bits( $filename, null, $image_data );
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'owc_image_upload', $upload['error'] );
        }

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name( $filename ),
            'post_status'    => 'inherit',
        ], $upload['file'], $product_id );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
        return $attachment_id;
    }

    /**
     * Exporta/crea un producto de WooCommerce hacia Odoo
     */
    public static function export_single_product( $wc_product_id ) {
        $client = self::get_client();
        if ( ! $client ) return [ 'success' => false, 'error' => 'Credenciales no configuradas.' ];

        $wc_product = wc_get_product( $wc_product_id );
        if ( ! $wc_product ) return [ 'success' => false, 'error' => 'Producto de WooCommerce no encontrado.' ];

        $name  = $wc_product->get_name();
        $sku   = $wc_product->get_sku() ?: 'WOO-' . $wc_product_id;
        $price = (float) ( $wc_product->get_regular_price() ?: $wc_product->get_price() );
        $desc  = $wc_product->get_description();

        $new_odoo_id = $client->create_product( $name, $sku, $price, $desc );
        if ( $new_odoo_id && is_int( $new_odoo_id ) ) {
            update_post_meta( $wc_product_id, '_odoo_product_id', $new_odoo_id );
            if ( empty( $wc_product->get_sku() ) ) {
                $wc_product->set_sku( $sku );
                $wc_product->save();
            }
            delete_transient( self::CACHE_KEY_COMPARE );
            return [
                'success' => true,
                'message' => "Producto '{$name}' creado exitosamente en Odoo con ID #{$new_odoo_id}.",
                'odoo_id' => $new_odoo_id,
            ];
        }

        return [ 'success' => false, 'error' => $client->get_last_error() ?: 'Odoo no permitió la creación del producto.' ];
    }

    /**
     * Envía a papelera un producto que existe solo en WooCommerce.
     * Antes de moverlo valida nuevamente contra Odoo por ID enlazado, SKU o nombre.
     */
    public static function trash_woo_only_product( $wc_product_id ) {
        $wc_product_id = absint( $wc_product_id );
        if ( ! $wc_product_id ) {
            return [ 'success' => false, 'error' => 'ID de WooCommerce no válido.' ];
        }

        $wc_product = wc_get_product( $wc_product_id );
        if ( ! $wc_product ) {
            return [ 'success' => false, 'error' => 'Producto de WooCommerce no encontrado.' ];
        }

        $client = self::get_client();
        if ( ! $client ) {
            return [ 'success' => false, 'error' => 'Credenciales de Odoo no configuradas.' ];
        }

        $match_field = OWC_Settings::get( 'sync_match_field', 'default_code' );
        $odoo_id = (int) get_post_meta( $wc_product_id, '_odoo_product_id', true );
        $sku = trim( (string) $wc_product->get_sku() );
        $name = trim( (string) $wc_product->get_name() );

        $domain = [];
        if ( $odoo_id > 0 ) {
            $domain = [[ 'id', '=', $odoo_id ]];
        } elseif ( $sku !== '' ) {
            $domain = [
                '|',
                [ 'default_code', '=', $sku ],
                [ 'barcode', '=', $sku ],
            ];
        } elseif ( $name !== '' ) {
            $domain = [[ 'name', '=ilike', $name ]];
        }

        if ( ! empty( $domain ) ) {
            $domain[] = [ 'active', '=', true ];
            $domain[] = [ 'sale_ok', '=', true ];
            $domain[] = [ 'type', '=', 'product' ];
            $found = $client->search_read( 'product.product', $domain, [ 'id', 'name' ], 1 );
            if ( ! empty( $found ) ) {
                return [
                    'success' => false,
                    'error'   => "No se envió a papelera: el producto parece existir en Odoo ({$found[0]['name']}).",
                ];
            }
        }

        $trashed = wp_trash_post( $wc_product_id );
        if ( ! $trashed ) {
            return [ 'success' => false, 'error' => 'WordPress no pudo mover el producto a la papelera.' ];
        }

        delete_transient( self::CACHE_KEY_COMPARE );
        OWC_Sync_Logger::log(
            'trash-woo-only',
            "Producto Woo #{$wc_product_id} enviado a papelera porque no pertenece al catálogo físico de Odoo: {$name}",
            'success'
        );

        return [
            'success' => true,
            'message' => "Producto '{$name}' enviado a papelera. No pertenece al catálogo físico de Odoo.",
        ];
    }
}
