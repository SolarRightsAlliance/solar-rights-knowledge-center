<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRA_Search_Plugin {

    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_shortcode( 'sra_search', array( $this, 'shortcode' ) );

        add_action( 'wp_ajax_sra_search', array( $this, 'ajax_search' ) );
        add_action( 'wp_ajax_nopriv_sra_search', array( $this, 'ajax_search' ) );

        add_action( 'wp_ajax_sra_search_click', array( $this, 'ajax_click' ) );
        add_action( 'wp_ajax_nopriv_sra_search_click', array( $this, 'ajax_click' ) );

        add_action( 'pre_get_posts', array( $this, 'filter_main_query' ) );

        add_action( 'admin_init', array( 'SRA_Search_Settings', 'register' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        add_action(
            'plugins_loaded',
            array( 'SRA_Search_Analytics', 'maybe_upgrade' )
        );

        add_action(
            SRA_Search_Analytics::CRON_HOOK,
            array( 'SRA_Search_Analytics', 'cleanup' )
        );
    }

    public function register_assets() {

        wp_register_style(
            'sra-search',
            SRA_SEARCH_URL . 'assets/search.css',
            array(),
            SRA_SEARCH_VERSION
        );

        wp_register_script(
            'sra-search',
            SRA_SEARCH_URL . 'assets/search.js',
            array(),
            SRA_SEARCH_VERSION,
            true
        );
    }

    public function shortcode( $atts ) {

        $atts = shortcode_atts(
            array(
                'category'    => 'consumer-guide',
                'placeholder' => 'Search...',
                'no_results'  => 'No matching articles found.',
            ),
            $atts,
            'sra_search'
        );

        $category_slug = sanitize_title( $atts['category'] );
        $category      = get_category_by_slug( $category_slug );

        if ( ! $category ) {
            return current_user_can( 'manage_options' )
                ? '<p>' .
                    esc_html__(
                        'Solar Rights Search: invalid category slug.',
                        'solar-rights-search'
                    ) .
                    '</p>'
                : '';
        }

        $max_results = absint(
            SRA_Search_Settings::get( 'max_results' )
        );

        wp_enqueue_style( 'sra-search' );
        wp_enqueue_script( 'sra-search' );

        wp_localize_script(
            'sra-search',
            'sraSearchSettings',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'homeUrl'        => home_url( '/' ),
                'nonce'          => wp_create_nonce( 'sra_search_nonce' ),
                'maxResults'     => $max_results,
                'highlightTerms' => (bool) SRA_Search_Settings::get(
                    'highlight_terms'
                ),
                'analytics'      => (bool) SRA_Search_Settings::get(
                    'enable_analytics'
                ),
            )
        );

        $search_id = wp_unique_id( 'sra-search-' );

        ob_start();
        ?>
        <div
            id="<?php echo esc_attr( $search_id ); ?>"
            class="sra-live-search"
            data-category="<?php echo esc_attr( $category_slug ); ?>"
            data-no-results="<?php echo esc_attr( $atts['no_results'] ); ?>"
        >
            <form
                class="sra-live-search__form"
                role="search"
                method="get"
                action="<?php echo esc_url( home_url( '/' ) ); ?>"
                autocomplete="off"
            >
                <div class="sra-live-search__input-wrap">
                    <label
                        class="screen-reader-text"
                        for="<?php echo esc_attr( $search_id ); ?>-input"
                    >
                        <?php echo esc_html( $atts['placeholder'] ); ?>
                    </label>

                    <input
                        id="<?php echo esc_attr( $search_id ); ?>-input"
                        class="sra-live-search__input"
                        type="search"
                        name="s"
                        placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
                        autocomplete="off"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $search_id ); ?>-results"
                    >

                    <input
                        type="hidden"
                        name="category_scope"
                        value="<?php echo esc_attr( $category_slug ); ?>"
                    >

                    <span
                        class="sra-live-search__status"
                        aria-hidden="true"
                    ></span>
                </div>
            </form>

            <div
                id="<?php echo esc_attr( $search_id ); ?>-results"
                class="sra-live-search__results"
                role="listbox"
                hidden
            ></div>
        </div>
        <?php

        return ob_get_clean();
    }

    public function ajax_search() {

        check_ajax_referer(
            'sra_search_nonce',
            'nonce'
        );

        $term = isset( $_GET['term'] )
            ? sanitize_text_field(
                wp_unslash( $_GET['term'] )
            )
            : '';

        $category_slug = isset( $_GET['category'] )
            ? sanitize_title(
                wp_unslash( $_GET['category'] )
            )
            : '';

        $source_path = isset( $_GET['source'] )
            ? esc_url_raw(
                wp_unslash( $_GET['source'] )
            )
            : '';

        $term = trim( $term );

        if (
            strlen( $term ) < 2 ||
            strlen( $term ) > 80
        ) {
            wp_send_json_success(
                array(
                    'results'    => array(),
                    'eventToken' => '',
                )
            );
        }

        if (
            empty( $category_slug ) ||
            ! get_category_by_slug( $category_slug )
        ) {
            wp_send_json_error(
                array(
                    'message' => __(
                        'Invalid search category.',
                        'solar-rights-search'
                    ),
                ),
                400
            );
        }

        $options_hash = md5(
            wp_json_encode(
                SRA_Search_Settings::get_all()
            )
        );

        $cache_key = 'sra_search_' . md5(
            SRA_SEARCH_VERSION .
            '|' .
            $options_hash .
            '|' .
            strtolower(
                $category_slug . '|' . $term
            )
        );

        $results = get_transient( $cache_key );

        if ( false === $results ) {

            $results = $this->ranked_results(
                $term,
                $category_slug
            );

            set_transient(
                $cache_key,
                $results,
                5 * MINUTE_IN_SECONDS
            );
        }

        $event_token = SRA_Search_Analytics::log_search(
            $term,
            $category_slug,
            count( $results ),
            $source_path
        );

        wp_send_json_success(
            array(
                'results'    => $results,
                'eventToken' => $event_token,
            )
        );
    }

    public function ajax_click() {

        check_ajax_referer(
            'sra_search_nonce',
            'nonce'
        );

        $token = isset( $_POST['event_token'] )
            ? sanitize_text_field(
                wp_unslash( $_POST['event_token'] )
            )
            : '';

        $click_type = isset( $_POST['click_type'] )
            ? sanitize_key(
                wp_unslash( $_POST['click_type'] )
            )
            : 'result';

        $position = isset( $_POST['position'] )
            ? absint( $_POST['position'] )
            : 0;

        $url = isset( $_POST['url'] )
            ? esc_url_raw(
                wp_unslash( $_POST['url'] )
            )
            : '';

        if ( ! $this->is_local_url( $url ) ) {
            $url = '';
        }

        SRA_Search_Analytics::log_click(
            $token,
            $click_type,
            $position,
            $url
        );

        wp_send_json_success();
    }

    public function admin_menu() {

        add_menu_page(
            __( 'Knowledge Center', 'solar-rights-search' ),
            __( 'Knowledge Center', 'solar-rights-search' ),
            'manage_options',
            'sra-knowledge-center',
            array( 'SRA_Analytics_Dashboard', 'render' ),
            'dashicons-welcome-learn-more',
            58
        );

        add_submenu_page(
            'sra-knowledge-center',
            __( 'Analytics', 'solar-rights-search' ),
            __( 'Analytics', 'solar-rights-search' ),
            'manage_options',
            'sra-knowledge-center',
            array( 'SRA_Analytics_Dashboard', 'render' )
        );

        add_submenu_page(
            'sra-knowledge-center',
            __( 'Settings', 'solar-rights-search' ),
            __( 'Settings', 'solar-rights-search' ),
            'manage_options',
            'sra-search-settings',
            array( 'SRA_Search_Settings', 'render_page' )
        );
    }

    private function ranked_results(
        $term,
        $category_slug
    ) {

        $max_results = absint(
            SRA_Search_Settings::get( 'max_results' )
        );

        $fetch_limit = min(
            200,
            max(
                30,
                $max_results * 10
            )
        );

        $query = new WP_Query(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'posts_per_page'         => $fetch_limit,
                'category_name'          => $category_slug,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            )
        );

        $original_terms = $this->normalize_terms(
            $term
        );

        $expanded_terms = $this->expanded_terms(
            $original_terms
        );

        $scored = array();

        foreach ( $query->posts as $post ) {

            $post_id = (int) $post->ID;

            $title = $this->plain_text(
                get_the_title( $post_id )
            );

            $excerpt = $this->plain_text(
                get_the_excerpt( $post_id )
            );

            $content = $this->plain_text(
                get_post_field(
                    'post_content',
                    $post_id
                )
            );

            $score = $this->score_post(
                $term,
                $original_terms,
                $expanded_terms,
                $title,
                $excerpt,
                $content
            );

            if ( $score <= 0 ) {
                continue;
            }

            $thumbnail = get_the_post_thumbnail_url(
                $post_id,
                'thumbnail'
            );

            $scored[] = array(
                'score'     => $score,
                'date'      => get_post_time(
                    'U',
                    true,
                    $post_id
                ),
                'title'     => $title,
                'url'       => esc_url_raw(
                    get_permalink( $post_id )
                ),
                'excerpt'   => html_entity_decode(
                    wp_trim_words(
                        $excerpt,
                        18
                    ),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ),
                'thumbnail' => $thumbnail
                    ? esc_url_raw( $thumbnail )
                    : '',
            );
        }

        usort(
            $scored,
            static function ( $a, $b ) {

                if ( $a['score'] === $b['score'] ) {
                    return $b['date'] <=> $a['date'];
                }

                return $b['score'] <=> $a['score'];
            }
        );

        $scored = array_slice(
            $scored,
            0,
            $max_results + 1
        );

        return array_map(
            static function ( $item ) {
                unset(
                    $item['score'],
                    $item['date']
                );

                return $item;
            },
            $scored
        );
    }

    private function score_post(
        $raw_term,
        $original_terms,
        $expanded_terms,
        $title,
        $excerpt,
        $content
    ) {

        $title_l   = $this->lower( $title );
        $excerpt_l = $this->lower( $excerpt );
        $content_l = $this->lower( $content );
        $phrase    = $this->lower(
            trim( $raw_term )
        );

        $score = 0;

        if ( '' !== $phrase ) {

            if ( $title_l === $phrase ) {
                $score += 250;
            } elseif (
                false !== strpos(
                    $title_l,
                    $phrase
                )
            ) {
                $score += 140;
            }

            if (
                false !== strpos(
                    $excerpt_l,
                    $phrase
                )
            ) {
                $score += 55;
            }

            if (
                false !== strpos(
                    $content_l,
                    $phrase
                )
            ) {
                $score += 20;
            }
        }

        foreach ( $original_terms as $search_term ) {

            $score += $this->field_score(
                $search_term,
                $title_l,
                $excerpt_l,
                $content_l,
                60,
                24,
                8
            );
        }

        foreach (
            array_diff(
                $expanded_terms,
                $original_terms
            ) as $synonym
        ) {

            $score += $this->field_score(
                $synonym,
                $title_l,
                $excerpt_l,
                $content_l,
                28,
                12,
                4
            );
        }

        return $score;
    }

    private function field_score(
        $term,
        $title,
        $excerpt,
        $content,
        $title_weight,
        $excerpt_weight,
        $content_weight
    ) {

        $score = 0;

        if ( false !== strpos( $title, $term ) ) {
            $score += $title_weight;
        }

        if ( false !== strpos( $excerpt, $term ) ) {
            $score += $excerpt_weight;
        }

        if ( false !== strpos( $content, $term ) ) {
            $score += $content_weight;
        }

        return $score;
    }

    private function normalize_terms( $term ) {

        $parts = preg_split(
            '/\s+/',
            $this->lower( $term )
        );

        $parts = array_filter(
            array_map(
                'trim',
                is_array( $parts )
                    ? $parts
                    : array()
            )
        );

        return array_values(
            array_unique( $parts )
        );
    }

    private function expanded_terms(
        $original_terms
    ) {

        if (
            ! SRA_Search_Settings::get(
                'enable_synonyms'
            )
        ) {
            return $original_terms;
        }

        $expanded = $original_terms;
        $groups   = $this->synonym_groups();

        foreach ( $groups as $group ) {

            $intersects = false;

            foreach ( $group as $candidate ) {

                foreach (
                    $original_terms as $original
                ) {

                    if (
                        $candidate === $original ||
                        false !== strpos(
                            $candidate,
                            $original
                        ) ||
                        false !== strpos(
                            $original,
                            $candidate
                        )
                    ) {
                        $intersects = true;
                        break 2;
                    }
                }
            }

            if ( $intersects ) {
                $expanded = array_merge(
                    $expanded,
                    $group
                );
            }
        }

        return array_values(
            array_unique(
                array_filter( $expanded )
            )
        );
    }

    private function synonym_groups() {

        $raw = (string) SRA_Search_Settings::get(
            'synonym_groups'
        );

        $lines = preg_split(
            '/\r\n|\r|\n/',
            $raw
        );

        $out = array();

        foreach (
            is_array( $lines )
                ? $lines
                : array()
            as $line
        ) {

            $items = array_map(
                array( $this, 'lower' ),
                array_map(
                    'trim',
                    explode( '|', $line )
                )
            );

            $items = array_values(
                array_unique(
                    array_filter( $items )
                )
            );

            if ( count( $items ) >= 2 ) {
                $out[] = $items;
            }
        }

        return $out;
    }

    private function plain_text( $value ) {

        return html_entity_decode(
            wp_strip_all_tags(
                (string) $value
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function lower( $value ) {

        return function_exists( 'mb_strtolower' )
            ? mb_strtolower(
                (string) $value,
                'UTF-8'
            )
            : strtolower(
                (string) $value
            );
    }

    private function is_local_url( $url ) {

        if ( '' === $url ) {
            return false;
        }

        $url_host = wp_parse_url(
            $url,
            PHP_URL_HOST
        );

        $home_host = wp_parse_url(
            home_url( '/' ),
            PHP_URL_HOST
        );

        return $url_host &&
            $home_host &&
            strtolower( $url_host ) ===
            strtolower( $home_host );
    }

    public function filter_main_query( $query ) {

        if (
            is_admin() ||
            ! $query->is_main_query() ||
            ! $query->is_search() ||
            empty( $_GET['category_scope'] )
        ) {
            return;
        }

        $category_slug = sanitize_title(
            wp_unslash(
                $_GET['category_scope']
            )
        );

        if (
            ! get_category_by_slug(
                $category_slug
            )
        ) {
            return;
        }

        $query->set(
            'post_type',
            'post'
        );

        $query->set(
            'category_name',
            $category_slug
        );
    }
}