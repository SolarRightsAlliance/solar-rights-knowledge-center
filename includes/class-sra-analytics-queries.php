<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRA_Analytics_Queries {

    public static function summary( $days ) {
        $current = self::summary_between(
            self::date_days_ago( $days ),
            gmdate( 'Y-m-d H:i:s' )
        );

        $previous = self::summary_between(
            self::date_days_ago( $days * 2 ),
            self::date_days_ago( $days )
        );

        $current['search_change'] = self::percent_change(
            $previous['searches'],
            $current['searches']
        );

        $current['ctr_change'] = $current['ctr'] - $previous['ctr'];

        return $current;
    }

    private static function summary_between( $start, $end ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    COUNT(*) AS searches,
                    SUM(clicked) AS clicks,
                    SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS no_results,
                    AVG(result_count) AS avg_results
                FROM {$table}
                WHERE searched_at >= %s
                  AND searched_at < %s
                ",
                $start,
                $end
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $searches = isset( $row['searches'] ) ? absint( $row['searches'] ) : 0;
        $clicks   = isset( $row['clicks'] ) ? absint( $row['clicks'] ) : 0;

        return array(
            'searches'    => $searches,
            'ctr'         => $searches ? ( $clicks / $searches ) * 100 : 0,
            'no_results'  => isset( $row['no_results'] ) ? absint( $row['no_results'] ) : 0,
            'avg_results' => isset( $row['avg_results'] ) ? (float) $row['avg_results'] : 0,
        );
    }

    public static function top_searches( $days, $no_results = false ) {

        global $wpdb;

        $table     = SRA_Search_Analytics::table_name();
        $since     = self::date_days_ago( $days );
        $condition = $no_results ? 'AND result_count = 0' : '';

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                COUNT(*) AS searches,
                AVG(result_count) AS avg_results,
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

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function low_ctr_searches( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::date_days_ago( $days );

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                COUNT(*) AS searches,
                AVG(result_count) AS avg_results,
                (SUM(clicked) / COUNT(*)) * 100 AS ctr
            FROM {$table}
            WHERE
                searched_at >= %s
                AND result_count > 0
            GROUP BY term
            HAVING COUNT(*) >= 3
            ORDER BY ctr ASC, searches DESC
            LIMIT 12
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function content_opportunities( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::date_days_ago( $days );

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                COUNT(*) AS searches,
                AVG(result_count) AS avg_results,
                (SUM(clicked) / COUNT(*)) * 100 AS ctr
            FROM {$table}
            WHERE searched_at >= %s
            GROUP BY term
            HAVING
                AVG(result_count) = 0
                OR (SUM(clicked) / COUNT(*)) < 0.50
            ORDER BY searches DESC
            LIMIT 50
            ",
            $since
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $opportunities = array();

        foreach ( $rows as $row ) {

            $searches    = absint( $row['searches'] );
            $avg_results = (float) $row['avg_results'];
            $ctr         = (float) $row['ctr'];

            if ( $avg_results <= 0 ) {
                $factor = 1.0;
                $issue  = 'No results';
            } elseif ( $ctr < 25 ) {
                $factor = 0.75;
                $issue  = 'Very low engagement';
            } elseif ( $ctr < 50 ) {
                $factor = 0.50;
                $issue  = 'Low engagement';
            } else {
                continue;
            }

            $opportunities[] = array(
                'term'        => $row['term'],
                'searches'    => $searches,
                'avg_results' => $avg_results,
                'ctr'         => $ctr,
                'issue'       => $issue,
                'score'       => $searches * $factor,
            );
        }

        usort(
            $opportunities,
            static function ( $a, $b ) {
                if ( $a['score'] === $b['score'] ) {
                    return $b['searches'] <=> $a['searches'];
                }

                return $b['score'] <=> $a['score'];
            }
        );

        return array_slice( $opportunities, 0, 12 );
    }

    public static function trending_searches( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();

        $current_start  = self::date_days_ago( $days );
        $previous_start = self::date_days_ago( $days * 2 );

        $sql = $wpdb->prepare(
            "
            SELECT
                term,
                SUM(CASE WHEN searched_at >= %s THEN 1 ELSE 0 END) AS current_searches,
                SUM(
                    CASE
                        WHEN searched_at >= %s AND searched_at < %s
                        THEN 1
                        ELSE 0
                    END
                ) AS previous_searches
            FROM {$table}
            WHERE searched_at >= %s
            GROUP BY term
            HAVING current_searches > 0
            ORDER BY current_searches DESC
            LIMIT 100
            ",
            $current_start,
            $previous_start,
            $current_start,
            $previous_start
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $trending = array();

        foreach ( $rows as $row ) {

            $current  = absint( $row['current_searches'] );
            $previous = absint( $row['previous_searches'] );

            if ( $current < 2 ) {
                continue;
            }

            if ( 0 === $previous ) {
                $growth = null;
                $label  = 'New';
            } else {
                $growth = self::percent_change( $previous, $current );

                if ( $growth <= 0 ) {
                    continue;
                }

                $label = number_format_i18n( $growth, 0 ) . '%';
            }

            $trending[] = array(
                'term'              => $row['term'],
                'current_searches'  => $current,
                'previous_searches' => $previous,
                'growth'            => $growth,
                'growth_label'      => $label,
            );
        }

        usort(
            $trending,
            static function ( $a, $b ) {

                if ( null === $a['growth'] && null !== $b['growth'] ) {
                    return -1;
                }

                if ( null !== $a['growth'] && null === $b['growth'] ) {
                    return 1;
                }

                if ( $a['growth'] === $b['growth'] ) {
                    return $b['current_searches'] <=> $a['current_searches'];
                }

                return $b['growth'] <=> $a['growth'];
            }
        );

        return array_slice( $trending, 0, 12 );
    }

    public static function top_clicked_articles( $days ) {

        global $wpdb;

        $table = SRA_Search_Analytics::table_name();
        $since = self::date_days_ago( $days );

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

        $rows     = $wpdb->get_results( $sql, ARRAY_A );
        $articles = array();

        foreach ( $rows as $row ) {

            $url = esc_url_raw( $row['clicked_url'] );

            if ( '' === $url ) {
                continue;
            }

            $post_id = url_to_postid( $url );

            if ( $post_id ) {
                $title = get_the_title( $post_id );
            } else {
                $path  = wp_parse_url( $url, PHP_URL_PATH );
                $title = $path ? untrailingslashit( $path ) : $url;
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
        $since = self::date_days_ago( $days );

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

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    private static function percent_change( $previous, $current ) {

        $previous = (float) $previous;
        $current  = (float) $current;

        if ( 0.0 === $previous ) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return ( ( $current - $previous ) / $previous ) * 100;
    }

    private static function date_days_ago( $days ) {

        $days = max( 0, absint( $days ) );

        return gmdate(
            'Y-m-d H:i:s',
            time() - ( $days * DAY_IN_SECONDS )
        );
    }
}