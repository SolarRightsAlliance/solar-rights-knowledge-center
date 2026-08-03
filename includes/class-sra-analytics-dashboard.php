<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress admin interface for Knowledge Center analytics.
 */
final class SRA_Analytics_Dashboard {

    public static function render() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $days = isset( $_GET['days'] )
            ? absint( $_GET['days'] )
            : 30;

        $days = in_array(
            $days,
            array( 7, 30, 90, 365 ),
            true
        )
            ? $days
            : 30;

        if ( isset( $_POST['sra_clear_analytics'] ) ) {

            check_admin_referer(
                'sra_clear_analytics'
            );

            SRA_Search_Analytics::clear_all();

            echo '<div class="notice notice-success is-dismissible"><p>';

            echo esc_html__(
                'Search analytics were cleared.',
                'solar-rights-search'
            );

            echo '</p></div>';
        }

        $summary = SRA_Analytics_Queries::summary(
            $days
        );

        $top_searches = SRA_Analytics_Queries::top_searches(
            $days,
            false
        );

        $no_results = SRA_Analytics_Queries::top_searches(
            $days,
            true
        );

        $low_ctr = SRA_Analytics_Queries::low_ctr_searches(
            $days
        );

        $content_opportunities = SRA_Analytics_Queries::content_opportunities(
            $days
        );

        $top_clicked = SRA_Analytics_Queries::top_clicked_articles(
            $days
        );

        $sources = SRA_Analytics_Queries::top_sources(
            $days
        );

        ?>
        <div class="wrap sra-kc-dashboard">

            <h1>
                <?php
                echo esc_html__(
                    'Knowledge Center Analytics',
                    'solar-rights-search'
                );
                ?>
            </h1>

            <p>
                <?php
                echo esc_html__(
                    'Editorial insights from anonymous search activity. No IP addresses, names, emails, user accounts, cookies, or personal browsing histories are stored.',
                    'solar-rights-search'
                );
                ?>
            </p>

            <nav
                class="nav-tab-wrapper"
                aria-label="<?php echo esc_attr__( 'Analytics date range', 'solar-rights-search' ); ?>"
            >
                <?php foreach ( array( 7, 30, 90, 365 ) as $range ) : ?>

                    <a
                        class="nav-tab <?php echo $days === $range ? 'nav-tab-active' : ''; ?>"
                        href="<?php
                        echo esc_url(
                            add_query_arg(
                                array(
                                    'page' => 'sra-knowledge-center',
                                    'days' => $range,
                                ),
                                admin_url( 'admin.php' )
                            )
                        );
                        ?>"
                    >
                        <?php
                        echo esc_html(
                            sprintf(
                                _n(
                                    '%d day',
                                    '%d days',
                                    $range,
                                    'solar-rights-search'
                                ),
                                $range
                            )
                        );
                        ?>
                    </a>

                <?php endforeach; ?>
            </nav>

            <div class="sra-kc-cards">

                <?php
                self::metric_card(
                    __( 'Searches', 'solar-rights-search' ),
                    number_format_i18n(
                        $summary['searches']
                    )
                );
                ?>

                <?php
                self::metric_card(
                    __( 'Click rate', 'solar-rights-search' ),
                    number_format_i18n(
                        $summary['ctr'],
                        1
                    ) . '%'
                );
                ?>

                <?php
                self::metric_card(
                    __( 'No-result searches', 'solar-rights-search' ),
                    number_format_i18n(
                        $summary['no_results']
                    )
                );
                ?>

                <?php
                self::metric_card(
                    __( 'Average results', 'solar-rights-search' ),
                    number_format_i18n(
                        $summary['avg_results'],
                        1
                    )
                );
                ?>

            </div>

            <div class="sra-kc-grid">

                <?php
                self::render_search_table(
                    __( 'Top searches', 'solar-rights-search' ),
                    $top_searches,
                    false
                );
                ?>

                <?php
                self::render_search_table(
                    __( 'Questions we could not answer', 'solar-rights-search' ),
                    $no_results,
                    true
                );
                ?>

                <?php
                self::render_search_table(
                    __( 'Searches with low click-through', 'solar-rights-search' ),
                    $low_ctr,
                    false
                );
                ?>

                <?php
                self::render_content_opportunities_table(
                    $content_opportunities
                );
                ?>

                <?php
                self::render_clicked_articles_table(
                    $top_clicked
                );
                ?>

                <?php
                self::render_source_table(
                    $sources
                );
                ?>

            </div>

            <hr>

            <h2>
                <?php
                echo esc_html__(
                    'Data controls',
                    'solar-rights-search'
                );
                ?>
            </h2>

            <p>
                <?php
                echo esc_html__(
                    'Analytics can be disabled or assigned a retention period under Knowledge Center → Settings.',
                    'solar-rights-search'
                );
                ?>
            </p>

            <form
                method="post"
                onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete all search analytics?', 'solar-rights-search' ) ); ?>');"
            >

                <?php
                wp_nonce_field(
                    'sra_clear_analytics'
                );
                ?>

                <input
                    type="hidden"
                    name="sra_clear_analytics"
                    value="1"
                >

                <?php
                submit_button(
                    __( 'Clear all analytics', 'solar-rights-search' ),
                    'delete',
                    'submit',
                    false
                );
                ?>

            </form>

        </div>

        <style>
            .sra-kc-cards {
                display: grid;
                grid-template-columns: repeat(4, minmax(150px, 1fr));
                gap: 16px;
                margin: 20px 0;
            }

            .sra-kc-card,
            .sra-kc-panel {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 18px;
            }

            .sra-kc-card strong {
                display: block;
                font-size: 28px;
                margin-top: 8px;
            }

            .sra-kc-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(320px, 1fr));
                gap: 18px;
            }

            .sra-kc-panel table {
                width: 100%;
                border-collapse: collapse;
            }

            .sra-kc-panel th,
            .sra-kc-panel td {
                text-align: left;
                padding: 9px 6px;
                border-bottom: 1px solid #eee;
                vertical-align: top;
            }

            .sra-kc-panel th:last-child,
            .sra-kc-panel td:last-child {
                text-align: right;
            }

            .sra-kc-panel a {
                text-decoration: none;
            }

            .sra-kc-panel a:hover {
                text-decoration: underline;
            }

            .sra-kc-opportunity-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                background: #f0f0f1;
                font-size: 12px;
                line-height: 1.4;
                white-space: nowrap;
            }

            .sra-kc-opportunity-score {
                font-weight: 600;
            }

            @media (max-width: 900px) {
                .sra-kc-cards,
                .sra-kc-grid {
                    grid-template-columns: 1fr 1fr;
                }
            }

            @media (max-width: 600px) {
                .sra-kc-cards,
                .sra-kc-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    private static function metric_card(
        $label,
        $value
    ) {

        echo '<div class="sra-kc-card">';

        echo '<span>' .
            esc_html( $label ) .
            '</span>';

        echo '<strong>' .
            esc_html( $value ) .
            '</strong>';

        echo '</div>';
    }

    private static function render_search_table(
        $title,
        $rows,
        $is_no_results
    ) {

        echo '<section class="sra-kc-panel">';

        echo '<h2>' .
            esc_html( $title ) .
            '</h2>';

        if ( empty( $rows ) ) {

            echo '<p>' .
                esc_html__(
                    'No data yet.',
                    'solar-rights-search'
                ) .
                '</p></section>';

            return;
        }

        echo '<table>';

        echo '<thead><tr>';

        echo '<th>' .
            esc_html__(
                'Search',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Searches',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html(
                $is_no_results
                    ? __( 'Opportunity', 'solar-rights-search' )
                    : __( 'CTR', 'solar-rights-search' )
            ) .
            '</th>';

        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {

            echo '<tr>';

            echo '<td>' .
                esc_html( $row['term'] ) .
                '</td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['searches']
                    )
                ) .
                '</td>';

            echo '<td>';

            if ( $is_no_results ) {

                echo esc_html__(
                    'Consider new or expanded content',
                    'solar-rights-search'
                );

            } else {

                echo esc_html(
                    number_format_i18n(
                        $row['ctr'],
                        1
                    ) . '%'
                );
            }

            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '</section>';
    }

    private static function render_content_opportunities_table( $rows ) {

        echo '<section class="sra-kc-panel">';

        echo '<h2>' .
            esc_html__(
                'Content opportunities',
                'solar-rights-search'
            ) .
            '</h2>';

        echo '<p>' .
            esc_html__(
                'Searches that may indicate missing content or answers that are not compelling enough to click.',
                'solar-rights-search'
            ) .
            '</p>';

        if ( empty( $rows ) ) {

            echo '<p>' .
                esc_html__(
                    'No significant opportunities yet.',
                    'solar-rights-search'
                ) .
                '</p></section>';

            return;
        }

        echo '<table>';

        echo '<thead><tr>';

        echo '<th>' .
            esc_html__(
                'Search',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Searches',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Avg. results',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'CTR',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Issue',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Score',
                'solar-rights-search'
            ) .
            '</th>';

        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {

            echo '<tr>';

            echo '<td>' .
                esc_html( $row['term'] ) .
                '</td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['searches']
                    )
                ) .
                '</td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['avg_results'],
                        1
                    )
                ) .
                '</td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['ctr'],
                        1
                    ) . '%'
                ) .
                '</td>';

            echo '<td><span class="sra-kc-opportunity-badge">' .
                esc_html( $row['issue'] ) .
                '</span></td>';

            echo '<td class="sra-kc-opportunity-score">' .
                esc_html(
                    number_format_i18n(
                        $row['score'],
                        1
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '</section>';
    }

    private static function render_clicked_articles_table( $rows ) {

        echo '<section class="sra-kc-panel">';

        echo '<h2>' .
            esc_html__(
                'Top clicked articles',
                'solar-rights-search'
            ) .
            '</h2>';

        if ( empty( $rows ) ) {

            echo '<p>' .
                esc_html__(
                    'No article clicks yet.',
                    'solar-rights-search'
                ) .
                '</p></section>';

            return;
        }

        echo '<table>';

        echo '<thead><tr>';

        echo '<th>' .
            esc_html__(
                'Article',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Clicks',
                'solar-rights-search'
            ) .
            '</th>';

        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {

            echo '<tr>';

            echo '<td>';

            if ( ! empty( $row['url'] ) ) {

                echo '<a href="' .
                    esc_url( $row['url'] ) .
                    '" target="_blank" rel="noopener noreferrer">' .
                    esc_html( $row['title'] ) .
                    '</a>';

            } else {

                echo esc_html(
                    $row['title']
                );
            }

            echo '</td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['clicks']
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '</section>';
    }

    private static function render_source_table( $rows ) {

        echo '<section class="sra-kc-panel">';

        echo '<h2>' .
            esc_html__(
                'Where searches started',
                'solar-rights-search'
            ) .
            '</h2>';

        if ( empty( $rows ) ) {

            echo '<p>' .
                esc_html__(
                    'No data yet.',
                    'solar-rights-search'
                ) .
                '</p></section>';

            return;
        }

        echo '<table>';

        echo '<thead><tr>';

        echo '<th>' .
            esc_html__(
                'Page path',
                'solar-rights-search'
            ) .
            '</th>';

        echo '<th>' .
            esc_html__(
                'Searches',
                'solar-rights-search'
            ) .
            '</th>';

        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {

            echo '<tr>';

            echo '<td><code>' .
                esc_html(
                    $row['source_path']
                        ? $row['source_path']
                        : '/'
                ) .
                '</code></td>';

            echo '<td>' .
                esc_html(
                    number_format_i18n(
                        $row['searches']
                    )
                ) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '</section>';
    }
}