<?php
/**
 * Logger de Sincronización - Tabla propia en la base de datos WordPress
 * Registra todas las actividades de sincronización con estado y mensaje.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OWC_Sync_Logger {

    const TABLE = 'owc_sync_log';

    // ─── Crear tabla al activar el plugin ────────────────────────────────────
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE;
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50)  NOT NULL,
            message    TEXT         NOT NULL,
            status     VARCHAR(20)  NOT NULL DEFAULT 'info',
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ─── Registrar Evento ─────────────────────────────────────────────────────
    public static function log( $event_type, $message, $status = 'info' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE;

        $inserted = $wpdb->insert( $table_name, [
            'event_type' => sanitize_text_field( $event_type ),
            'message'    => sanitize_textarea_field( $message ),
            'status'     => sanitize_text_field( $status ),
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s'] );

        if ( false === $inserted ) {
            // Si la tabla no existía porque se subió el zip sin reactivar
            self::create_table();
            $wpdb->insert( $table_name, [
                'event_type' => sanitize_text_field( $event_type ),
                'message'    => sanitize_textarea_field( $message ),
                'status'     => sanitize_text_field( $status ),
                'created_at' => current_time('mysql'),
            ], ['%s', '%s', '%s', '%s'] );
        }
    }

    // ─── Obtener Logs Recientes ───────────────────────────────────────────────
    public static function get_recent( $limit = 50 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d", $limit ),
            ARRAY_A
        );
    }

    // ─── Limpiar logs antiguos (mantener últimos N días) ─────────────────────
    public static function cleanup( $days = 30 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE;
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
        );
    }
}
