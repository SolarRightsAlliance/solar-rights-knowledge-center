<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRA_Search_Analytics {
    const DB_OPTION  = 'sra_search_db_version';
    const CRON_HOOK  = 'sra_search_daily_cleanup';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sra_search_events';
    }

    public static function activate() {
        self::maybe_upgrade();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
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
        update_option( self::DB_OPTION, SRA_SEARCH_DB_VERSION, false );
    }

    public static function enabled() {
        return (bool) SRA_Search_Settings::get( 'enable_analytics' );
    }

    public static function log_search( $term, $category, $result_count, $source_path, $client_session ) {
        if ( ! self::enabled() ) {
            return '';
        }

        global $wpdb;

        $token        = wp_generate_uuid4();
        $term         = self::normalize_term( $term );
        $category     = sanitize_title( $category );
        $source_path  = self::sanitize_source_path( $source_path );
        $session_hash = self::session_hash( $client_session );

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
                'result_count' => max( 0, min( 65535, absint( $result_count ) ) ),
                'source_path'  => $source_path,
                'session_hash' => $session_hash,
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        return false === $inserted ? '' : $token;
    }

    public static function log_click( $token, $click_type, $position, $url ) {
        if ( ! self::enabled() || ! wp_is_uuid( $token, 4 ) ) {
            return false;
        }

        global $wpdb;

        $click_type = in_array( $click_type, array( 'result', 'view_all' ), true ) ? $click_type : 'result';
        $position   = max( 0, min( 100, absint( $position ) ) );
        $url        = esc_url_raw( $url );

        return false !== $wpdb->update(
            self::table_name(),
            array(
                'clicked'          => 1,
                'click_type'       => $click_type,
                'clicked_position' => $position,
                'clicked_url'      => $url,
            ),
            array( 'event_token' => $token ),
            array( '%d', '%s', '%d', '%s' ),
            array( '%s' )
        );
    }

    public static function cleanup() {
        global $wpdb;
        $days = max( 30, min( 1095, absint( SRA_Search_Settings::get( 'retention_days' ) ) ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE searched_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
        $days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;

        if ( isset( $_POST['sra_clear_analytics'] ) ) {
            check_admin_referer( 'sra_clear_analytics' );
            self::clear_all();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Search analytics were cleared.', 'solar-rights-search' ) . '</p></div>';
        }

        $summary       = self::summary( $days );
        $top_searches  = self::top_searches( $days, false );
        $no_results    = self::top_searches( $days, true );
        $low_ctr       = self::low_ctr_searches( $days );
        $sources       = self::top_sources( $days );
        ?>
        <div class="wrap sra-kc-dashboard">
            <h1><?php echo esc_html__( 'Knowledge Center Analytics', 'solar-rights-search' ); ?></h1>
            <p><?php echo esc_html__( 'Editorial insights from anonymous search activity. No IP addresses, names, emails, user accounts, cookies, or personal browsing histories are stored.', 'solar-rights-search' ); ?></p>

            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Analytics date range', 'solar-rights-search' ); ?>">
                <?php foreach ( array( 7, 30, 90, 365 ) as $range ) : ?>
                    <a class="nav-tab <?php echo $days === $range ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'sra-knowledge-center', 'days' => $range ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( _n( '%d day', '%d days', $range, 'solar-rights-search' ), $range ) ); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="sra-kc-cards">
                <?php self::metric_card( __( 'Searches', 'solar-rights-search' ), number_format_i18n( $summary['searches'] ) ); ?>
                <?php self::metric_card( __( 'Click rate', 'solar-rights-search' ), number_format_i18n( $summary['ctr'], 1 ) . '%' ); ?>
                <?php self::metric_card( __( 'No-result searches', 'solar-rights-search' ), number_format_i18n( $summary['no_results'] ) ); ?>
                <?php self::metric_card( __( 'Average results', 'solar-rights-search' ), number_format_i18n( $summary['avg_results'], 1 ) ); ?>
            </div>

            <div class="sra-kc-grid">
                <?php self::render_table( __( 'Top searches', 'solar-rights-search' ), $top_searches, false ); ?>
                <?php self::render_table( __( 'Questions we could not answer', 'solar-rights-search' ), $no_results, true ); ?>
                <?php self::render_table( __( 'Searches with low click-through', 'solar-rights-search' ), $low_ctr, false ); ?>
                <?php self::render_source_table( $sources ); ?>
            </div>

            <hr>
            <h2><?php echo esc_html__( 'Data controls', 'solar-rights-search' ); ?></h2>
            <p><?php echo esc_html__( 'Analytics can be disabled or assigned a retention period under Knowledge Center → Settings.', 'solar-rights-search' ); ?></p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete all search analytics?', 'solar-rights-search' ) ); ?>');">
                <?php wp_nonce_field( 'sra_clear_analytics' ); ?>
                <input type="hidden" name="sra_clear_analytics" value="1">
                <?php submit_button( __( 'Clear all analytics', 'solar-rights-search' ), 'delete', 'submit', false ); ?>
            </form>
        </div>
        <style>
            .sra-kc-cards{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px;margin:20px 0}.sra-kc-card,.sra-kc-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px}.sra-kc-card strong{display:block;font-size:28px;margin-top:8px}.sra-kc-grid{display:grid;grid-template-columns:repeat(2,minmax(320px,1fr));gap:18px}.sra-kc-panel table{width:100%;border-collapse:collapse}.sra-kc-panel th,.sra-kc-panel td{text-align:left;padding:9px 6px;border-bottom:1px solid #eee}.sra-kc-panel th:last-child,.sra-kc-panel td:last-child{text-align:right}@media(max-width:900px){.sra-kc-cards,.sra-kc-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.sra-kc-cards,.sra-kc-grid{grid-template-columns:1fr}}
        </style>
        <?php
    }

    private static function metric_card( $label, $value ) {
        echo '<div class="sra-kc-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
    }

    private static function render_table( $title, $rows, $is_no_results ) {
        echo '<section class="sra-kc-panel"><h2>' . esc_html( $title ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No data yet.', 'solar-rights-search' ) . '</p></section>';
            return;
        }
        echo '<table><thead><tr><th>' . esc_html__( 'Search', 'solar-rights-search' ) . '</th><th>' . esc_html__( 'Searches', 'solar-rights-search' ) . '</th><th>' . esc_html( $is_no_results ? __( 'Opportunity', 'solar-rights-search' ) : __( 'CTR', 'solar-rights-search' ) ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td>' . esc_html( $row['term'] ) . '</td><td>' . esc_html( number_format_i18n( $row['searches'] ) ) . '</td><td>';
            echo $is_no_results ? esc_html__( 'Consider new or expanded content', 'solar-rights-search' ) : esc_html( number_format_i18n( $row['ctr'], 1 ) . '%' );
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function render_source_table( $rows ) {
        echo '<section class="sra-kc-panel"><h2>' . esc_html__( 'Where searches started', 'solar-rights-search' ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No data yet.', 'solar-rights-search' ) . '</p></section>';
            return;
        }
        echo '<table><thead><tr><th>' . esc_html__( 'Page path', 'solar-rights-search' ) . '</th><th>' . esc_html__( 'Searches', 'solar-rights-search' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td><code>' . esc_html( $row['source_path'] ?: '/' ) . '</code></td><td>' . esc_html( number_format_i18n( $row['searches'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function summary( $days ) {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) searches, SUM(clicked) clicks, SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) no_results, AVG(result_count) avg_results FROM {$table} WHERE searched_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $searches = isset( $row['searches'] ) ? absint( $row['searches'] ) : 0;
        $clicks   = isset( $row['clicks'] ) ? absint( $row['clicks'] ) : 0;
        return array(
            'searches'    => $searches,
            'ctr'         => $searches ? ( $clicks / $searches ) * 100 : 0,
            'no_results'  => isset( $row['no_results'] ) ? absint( $row['no_results'] ) : 0,
            'avg_results' => isset( $row['avg_results'] ) ? (float) $row['avg_results'] : 0,
        );
    }

    private static function top_searches( $days, $no_results ) {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $condition = $no_results ? 'AND result_count = 0' : '';
        $sql = $wpdb->prepare( "SELECT term, COUNT(*) searches, (SUM(clicked) / COUNT(*)) * 100 ctr FROM {$table} WHERE searched_at >= %s {$condition} GROUP BY term ORDER BY searches DESC LIMIT 12", $since ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    private static function low_ctr_searches( $days ) {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $sql = $wpdb->prepare( "SELECT term, COUNT(*) searches, (SUM(clicked) / COUNT(*)) * 100 ctr FROM {$table} WHERE searched_at >= %s AND result_count > 0 GROUP BY term HAVING COUNT(*) >= 3 ORDER BY ctr ASC, searches DESC LIMIT 12", $since ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    private static function top_sources( $days ) {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $sql = $wpdb->prepare( "SELECT source_path, COUNT(*) searches FROM {$table} WHERE searched_at >= %s GROUP BY source_path ORDER BY searches DESC LIMIT 12", $since ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    private static function clear_all() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function normalize_term( $term ) {
        $term = sanitize_text_field( wp_unslash( $term ) );
        $term = trim( preg_replace( '/\s+/', ' ', $term ) );
        $term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
        return substr( $term, 0, 191 );
    }

    private static function sanitize_source_path( $source ) {
        $path = wp_parse_url( esc_url_raw( $source ), PHP_URL_PATH );
        return substr( sanitize_text_field( (string) $path ), 0, 255 );
    }

    private static function session_hash( $client_session ) {
        $client_session = sanitize_text_field( (string) $client_session );
        if ( '' === $client_session ) {
            return '';
        }
        return hash_hmac( 'sha256', substr( $client_session, 0, 100 ), wp_salt( 'auth' ) );
    }
}
