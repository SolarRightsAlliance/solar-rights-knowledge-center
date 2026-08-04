<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stores and maintains anonymous Knowledge Center search events.
 */
final class SRA_Search_Analytics {

    const DB_OPTION = 'sra_search_db_version';
    const CRON_HOOK = 'sra_search_daily_cleanup';

    public static function table_name() {
        global $wpdb;

        return $wpdb->prefix . 'sra_search_events';
    }

    public static function activate() {
        self::maybe_upgrade();

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event(
                time() + HOUR_IN_SECONDS,
                'daily',
                self::CRON_HOOK
            );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event(
                $timestamp,
                self::CRON_HOOK
            );
        }
    }

    public static function maybe_upgrade() {

        if ( get_option( self::DB_OPTION ) === SRA_SEARCH_DB_VERSION ) {
            return;
        }

        global $wpdb;

        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_token char(36) NOT NULL,
            searched_at datetime NOT NULL,
            term varchar(191) NOT NULL,
            category varchar(191) NOT NULL,
            result_count smallint(5) unsigned NOT NULL DEFAULT 0,
            clicked tinyint(1) unsigned NOT NULL DEFAULT 0,
            click_type varchar(20) NOT NULL DEFAULT '',
            clicked_position smallint(5) unsigned NOT NULL DEFAULT 0,
            clicked_url text NULL,
            source_path varchar(255) NOT NULL DEFAULT '',
            session_hash char(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY event_token (event_token),
            KEY searched_at (searched_at),
            KEY term (term),
            KEY category (category),
            KEY clicked (clicked)
        ) {$charset_collate};";

        dbDelta( $sql );

        update_option(
            self::DB_OPTION,
            SRA_SEARCH_DB_VERSION,
            false
        );
    }

    public static function enabled() {
        return (bool) SRA_Search_Settings::get( 'enable_analytics' );
    }

    public static function log_search(
    $term,
    $category,
    $result_count,
    $source_path
) {

        if ( ! self::enabled() ) {
            return '';
        }

        global $wpdb;

        $token       = wp_generate_uuid4();
$term        = self::normalize_term( $term );
$category    = sanitize_title( $category );
$source_path = self::sanitize_source_path( $source_path );

        if ( '' === $term || '' === $category ) {
            return '';
        }

        $inserted = $wpdb->insert(
            self::table_name(),
            array(
                'event_token'  => $token,
                'searched_at'  => current_time( 'mysql', true ),
                'term'         => $term,
                'category'     => $category,
                'result_count' => max(
                    0,
                    min( 65535, absint( $result_count ) )
                ),
                'source_path'  => $source_path,
                
            ),
            array(
    '%s',
    '%s',
    '%s',
    '%s',
    '%d',
    '%s',
)
        );

        return false === $inserted
            ? ''
            : $token;
    }

    public static function log_click(
        $token,
        $click_type,
        $position,
        $url
    ) {

        if (
            ! self::enabled() ||
            ! wp_is_uuid( $token, 4 )
        ) {
            return false;
        }

        global $wpdb;

        $click_type = in_array(
            $click_type,
            array( 'result', 'view_all' ),
            true
        )
            ? $click_type
            : 'result';

        $position = max(
            0,
            min( 100, absint( $position ) )
        );

        $url = esc_url_raw( $url );

        return false !== $wpdb->update(
            self::table_name(),
            array(
                'clicked'          => 1,
                'click_type'       => $click_type,
                'clicked_position' => $position,
                'clicked_url'      => $url,
            ),
            array(
                'event_token' => $token,
            ),
            array(
                '%d',
                '%s',
                '%d',
                '%s',
            ),
            array(
                '%s',
            )
        );
    }

    public static function cleanup() {

        global $wpdb;

        $days = max(
            30,
            min(
                1095,
                absint(
                    SRA_Search_Settings::get(
                        'retention_days'
                    )
                )
            )
        );

        $cutoff = gmdate(
            'Y-m-d H:i:s',
            time() - ( $days * DAY_IN_SECONDS )
        );

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table_name() . ' WHERE searched_at < %s',
                $cutoff
            )
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function clear_all() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        global $wpdb;

        return false !== $wpdb->query(
            'TRUNCATE TABLE ' . self::table_name()
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function normalize_term( $term ) {

        $term = sanitize_text_field(
            wp_unslash( $term )
        );

        $term = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $term
            )
        );

        $term = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $term, 'UTF-8' )
            : strtolower( $term );

        return substr(
            $term,
            0,
            191
        );
    }

    private static function sanitize_source_path( $source ) {

        $path = wp_parse_url(
            esc_url_raw( $source ),
            PHP_URL_PATH
        );

        return substr(
            sanitize_text_field(
                (string) $path
            ),
            0,
            255
        );
    }
}
    