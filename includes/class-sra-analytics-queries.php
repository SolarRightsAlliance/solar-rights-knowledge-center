<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reporting queries for anonymous Knowledge Center analytics.
 */
final class SRA_Analytics_Queries {

    public static function summary( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::since_date( $days );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    COUNT(*) AS searches,
                    SUM(clicked) AS clicks,
                    SUM(
                        CASE
                            WHEN result_count = 0
                            THEN 1
                            ELSE 0
                        END
                    ) AS no_results,
                    AVG(result_count) AS avg_results
                FROM {$table}
                WHERE searched_at >= %s
                ",
                $since
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $searches = isset( $row['searches'] )
            ? absint( $row['searches'] )
            : 0;

        $clicks = isset( $row['clicks'] )
            ? absint( $row['clicks'] )
            : 0;

        return array(
            'searches' => $searches,

            'ctr' => $searches
                ? ( $clicks / $searches ) * 100
                : 0,

            'no_results' => isset( $row['no_results'] )
                ? absint( $row['no_results'] )
                : 0,

            'avg_results' => isset( $row['avg_results'] )
                ? (float) $row['avg_results']
                : 0,
        );
    }

    public static function top_searches(
        $days,
        $no_results = false
    ) {

        global $wpdb;

        $table     = SRA_Search_Analytics::table_name();
        $since     = self::since_date( $days );
        $condition = $no_results
            ? 'AND result_count = 0'
            : '';

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                COUNT(*) AS searches,
                (SUM(clicked) / COUNT(*)) * 100 AS ctr
            FROM {$table}
            WHERE searched_at >= %s
            {$condition}
            GROUP BY term
            ORDER BY searches DESC
            LIMIT 12
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $wpdb->get_results(
            $sql,
            ARRAY_A
        );
    }

    public static function low_ctr_searches( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::since_date( $days );

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                COUNT(*) AS searches,
                (SUM(clicked) / COUNT(*)) * 100 AS ctr
            FROM {$table}
            WHERE
                searched_at >= %s
                AND result_count > 0
            GROUP BY term
            HAVING COUNT(*) >= 3
            ORDER BY
                ctr ASC,
                searches DESC
            LIMIT 12
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $wpdb->get_results(
            $sql,
            ARRAY_A
        );
    }

    /**
     * Return the most-clicked individual search results.
     *
     * Only actual result clicks are included. "View all" clicks are
     * intentionally excluded.
     */
    public static function top_clicked_articles( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::since_date( $days );

        $sql = $wpdb->prepare(
            "
            SELECT
                clicked_url,
                COUNT(*) AS clicks
            FROM {$table}
            WHERE
                searched_at >= %s
                AND clicked = 1
                AND click_type = 'result'
                AND clicked_url IS NOT NULL
                AND clicked_url <> ''
            GROUP BY clicked_url
            ORDER BY clicks DESC
            LIMIT 12
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = $wpdb->get_results(
            $sql,
            ARRAY_A
        );

        $articles = array();

        foreach ( $rows as $row ) {

            $url = esc_url_raw(
                $row['clicked_url']
            );

            if ( '' === $url ) {
                continue;
            }

            $post_id = url_to_postid( $url );

            if ( $post_id ) {

                $title = get_the_title( $post_id );

            } else {

                /*
                 * Fallback for a URL that can no longer be resolved
                 * to a current WordPress post.
                 */
                $path = wp_parse_url(
                    $url,
                    PHP_URL_PATH
                );

                $title = $path
                    ? untrailingslashit( $path )
                    : $url;
            }

            $articles[] = array(
                'post_id' => absint( $post_id ),
                'title'   => $title,
                'url'     => $url,
                'clicks'  => absint( $row['clicks'] ),
            );
        }

        return $articles;
    }

    public static function top_sources( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::since_date( $days );

        $sql = $wpdb->prepare(
            "
            SELECT
                source_path,
                COUNT(*) AS searches
            FROM {$table}
            WHERE searched_at >= %s
            GROUP BY source_path
            ORDER BY searches DESC
            LIMIT 12
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $wpdb->get_results(
            $sql,
            ARRAY_A
        );
    }

    private static function since_date( $days ) {

        $days = max(
            1,
            absint( $days )
        );

        return gmdate(
            'Y-m-d H:i:s',
            time() - ( $days * DAY_IN_SECONDS )
        );
    }
}