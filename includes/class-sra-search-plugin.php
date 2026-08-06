<?php

if ( ! defined( 'ABSPATH' ) ) {/*
 * An editorially assigned search term is an explicit signal that
 * this content is relevant to the visitor's query.
 */
if ( ! empty( $editorial_search_terms ) ) {
    return true;
}
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
                'post_types'  => 'post',
                'placeholder' => 'Search...',
                'no_results'  => 'No matching articles found.',
            ),
            $atts,
            'sra_search'
        );

        $category_slugs = $this->parse_category_slugs(
            $atts['category']
        );

        $category_ids = $this->category_ids_from_slugs(
            $category_slugs
        );

        if (
            empty( $category_slugs ) ||
            count( $category_ids ) !== count( $category_slugs )
        ) {
            return current_user_can( 'manage_options' )
                ? '<p>' .
                    esc_html__(
                        'Solar Rights Search: one or more category slugs are invalid.',
                        'solar-rights-search'
                    ) .
                    '</p>'
                : '';
        }

        $post_types = $this->parse_post_types(
            $atts['post_types']
        );

        if ( empty( $post_types ) ) {
            return current_user_can( 'manage_options' )
                ? '<p>' .
                    esc_html__(
                        'Solar Rights Search: invalid post_types value.',
                        'solar-rights-search'
                    ) .
                    '</p>'
                : '';
        }

        $category_scope = implode(
            ',',
            $category_slugs
        );

        $post_type_scope = implode(
            ',',
            $post_types
        );

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
            data-category="<?php echo esc_attr( $category_scope ); ?>"
            data-post-types="<?php echo esc_attr( $post_type_scope ); ?>"
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
                        value="<?php echo esc_attr( $category_scope ); ?>"
                    >

                    <input
                        type="hidden"
                        name="post_type_scope"
                        value="<?php echo esc_attr( $post_type_scope ); ?>"
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

        $category_raw = isset( $_GET['category'] )
            ? sanitize_text_field(
                wp_unslash( $_GET['category'] )
            )
            : '';

        $post_types_raw = isset( $_GET['post_types'] )
            ? sanitize_text_field(
                wp_unslash( $_GET['post_types'] )
            )
            : 'post';

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

        $category_slugs = $this->parse_category_slugs(
            $category_raw
        );

        $category_ids = $this->category_ids_from_slugs(
            $category_slugs
        );

        if (
            empty( $category_slugs ) ||
            count( $category_ids ) !== count( $category_slugs )
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

        $post_types = $this->parse_post_types(
            $post_types_raw
        );

        if ( empty( $post_types ) ) {
            wp_send_json_error(
                array(
                    'message' => __(
                        'Invalid search content type.',
                        'solar-rights-search'
                    ),
                ),
                400
            );
        }

        $category_scope = implode(
            ',',
            $category_slugs
        );

        $post_type_scope = implode(
            ',',
            $post_types
        );

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
                $category_scope .
                '|' .
                $post_type_scope .
                '|' .
                $term
            )
        );

        $results = get_transient(
            $cache_key
        );

        if ( false === $results ) {

            $results = $this->ranked_results(
                $term,
                $category_ids,
                $post_types
            );

            set_transient(
                $cache_key,
                $results,
                5 * MINUTE_IN_SECONDS
            );
        }

        $event_token = SRA_Search_Analytics::log_search(
            $term,
            $category_scope,
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
        $category_ids,
        $post_types
    ) {

        $max_results = absint(
            SRA_Search_Settings::get(
                'max_results'
            )
        );

        /*
         * Search all eligible content.
         *
         * Previously we only scored a limited set of the newest
         * content. That could exclude older cornerstone guides before
         * relevance scoring even began.
         */
        $candidate_posts = array();

        if (
            in_array(
                'post',
                $post_types,
                true
            )
        ) {

$post_query = new WP_Query(
    array(
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'category__in'           => array_map(
            'absint',
            $category_ids
        ),
        'meta_query'             => array(
            array(
                'key'     => SRA_Search_Content::META_KEY,
                'value'   => '1',
                'compare' => '=',
            ),
        ),
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'ignore_sticky_posts'    => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    )
);

            $candidate_posts = array_merge(
                $candidate_posts,
                $post_query->posts
            );
        }

        if (
            in_array(
                'page',
                $post_types,
                true
            )
        ) {

            $page_query = new WP_Query(
                array(
                    'post_type'              => 'page',
                    'post_status'            => 'publish',
                    'posts_per_page'         => -1,
                    'meta_query'             => array(
                        array(
                            'key'     => SRA_Search_Content::META_KEY,
                            'value'   => '1',
                            'compare' => '=',
                        ),
                    ),
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                )
            );

            $candidate_posts = array_merge(
                $candidate_posts,
                $page_query->posts
            );
        }

        $meaningful_terms = $this->normalize_terms(
            $term
        );

        if ( empty( $meaningful_terms ) ) {
            return array();
        }

        $active_synonym_groups =
            $this->active_synonym_groups(
                $term
            );

        $priority_phrases =
            $this->matching_priority_phrases(
                $term
            );

        $full_phrase =
            $this->normalize_search_text(
                $term
            );

        $scored = array();

        foreach ( $candidate_posts as $post ) {

            $post_id = (int) $post->ID;

            $title = $this->plain_text(
                get_the_title(
                    $post_id
                )
            );

            $excerpt = $this->plain_text(
                get_the_excerpt(
                    $post_id
                )
            );

            $content = $this->plain_text(
                get_post_field(
                    'post_content',
                    $post_id
                )
            );

            $title_n = $this->normalize_search_text(
                $title
            );

            $excerpt_n = $this->normalize_search_text(
                $excerpt
            );

            $content_n = $this->normalize_search_text(
                $content
            );

            $editorial_search_terms =
    $this->matching_content_search_terms(
        $term,
        $post_id
    );

            if (
                ! $this->passes_relevance_gate(
    $meaningful_terms,
    $active_synonym_groups,
    $priority_phrases,
    $editorial_search_terms,
    $full_phrase,
    $title_n,
    $excerpt_n,
    $content_n
)
            ) {
                continue;
            }

            $score = $this->score_post(
    $full_phrase,
    $meaningful_terms,
    $active_synonym_groups,
    $priority_phrases,
    $editorial_search_terms,
    $title_n,
    $excerpt_n,
    $content_n
);

            if ( $score <= 0 ) {
                continue;
            }

            $thumbnail =
                get_the_post_thumbnail_url(
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
                    get_permalink(
                        $post_id
                    )
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
                    ? esc_url_raw(
                        $thumbnail
                    )
                    : '',
            );
        }

        usort(
            $scored,
            static function ( $a, $b ) {

                if (
                    $a['score'] ===
                    $b['score']
                ) {
                    return $b['date']
                        <=> $a['date'];
                }

                return $b['score']
                    <=> $a['score'];
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

    private function passes_relevance_gate(
    $meaningful_terms,
    $active_synonym_groups,
    $priority_phrases,
    $editorial_search_terms,
    $full_phrase,
    $title,
    $excerpt,
    $content
) {

        $combined = trim(
            $title .
            ' ' .
            $excerpt .
            ' ' .
            $content
        );

        /*
         * A literal match of the visitor's complete normalized query
         * is always strongly relevant.
         */
        if (
            '' !== $full_phrase &&
            $this->contains_phrase(
                $combined,
                $full_phrase
            )
        ) {
            return true;
        }

        /*
         * Matching a configured priority phrase is also sufficient.
         */
        foreach (
            $priority_phrases as $phrase
        ) {

            if (
                $this->contains_phrase(
                    $combined,
                    $phrase
                )
            ) {
                return true;
            }
        }

        $matched_terms = 0;

        foreach (
            $meaningful_terms as $term
        ) {

            if (
                $this->field_contains(
                    $term,
                    $combined
                )
            ) {
                $matched_terms++;
            }
        }

        $term_count = count(
            $meaningful_terms
        );

        /*
         * One meaningful-term searches need one concept match.
         *
         * Searches with two or more meaningful terms normally need at
         * least two concepts to match. This prevents "plug in solar"
         * from returning generic articles that only contain "solar."
         */
        $required_matches =
            $term_count >= 2
                ? 2
                : 1;

        if (
            $matched_terms >=
            $required_matches
        ) {
            return true;
        }

        /*
         * A recognized multi-word synonym can represent the complete
         * concept even when it uses different vocabulary.
         *
         * Example:
         *   plug in solar -> balcony solar
         *
         * Single-word synonym triggers such as "battery" do not bypass
         * the two-concept requirement for a query such as
         * "buy a battery."
         */
        foreach (
            $active_synonym_groups as $group
        ) {

            if (
                empty(
                    $group['strong_trigger']
                )
            ) {
                continue;
            }

            foreach (
                $group['phrases'] as $phrase
            ) {

                if (
                    $this->contains_phrase(
                        $combined,
                        $phrase
                    )
                ) {
                    return true;
                }
            }
        }

        /*
         * For a one-concept search, ordinary synonym equivalents are
         * allowed to satisfy relevance.
         *
         * Example:
         *   battery -> storage
         */
        if ( 1 === $term_count ) {

            foreach (
                $active_synonym_groups as $group
            ) {

                foreach (
                    $group['phrases'] as $phrase
                ) {

                    if (
                        $this->contains_phrase(
                            $combined,
                            $phrase
                        )
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function score_post(
    $full_phrase,
    $meaningful_terms,
    $active_synonym_groups,
    $priority_phrases,
    $editorial_search_terms,
    $title,
    $excerpt,
    $content
) {

    $score = 0;

    /*
     * Editorial search terms are our strongest ranking signal.
     *
     * These allow an editor to explicitly say that a particular
     * resource is a preferred destination for a visitor intent such
     * as "installer" or "solar company".
     */
    if ( ! empty( $editorial_search_terms ) ) {

        $score += 700;

        if ( count( $editorial_search_terms ) > 1 ) {
            $score += (
                count( $editorial_search_terms ) - 1
            ) * 50;
        }
    }

    /*
     * Complete visitor search phrase.
     */
    if ( '' !== $full_phrase ) {

        if ( $title === $full_phrase ) {

            $score += 300;

        } elseif (
            $this->contains_phrase(
                $title,
                $full_phrase
            )
        ) {

            $score += 180;
        }

        if (
            $this->contains_phrase(
                $excerpt,
                $full_phrase
            )
        ) {
            $score += 75;
        }

        if (
            $this->contains_phrase(
                $content,
                $full_phrase
            )
        ) {
            $score += 30;
        }
    }

    /*
     * Administrator-configured global priority phrases.
     */
    foreach (
        $priority_phrases as $phrase
    ) {

        if (
            $this->contains_phrase(
                $title,
                $phrase
            )
        ) {
            $score += 240;
        }

        if (
            $this->contains_phrase(
                $excerpt,
                $phrase
            )
        ) {
            $score += 100;
        }

        if (
            $this->contains_phrase(
                $content,
                $phrase
            )
        ) {
            $score += 40;
        }
    }

    /*
     * Score meaningful visitor terms that are not already represented
     * by an activated synonym group.
     */
    foreach (
        $meaningful_terms as $term
    ) {

        if (
            $this->term_is_covered_by_synonym_group(
                $term,
                $active_synonym_groups
            )
        ) {
            continue;
        }

        $score += $this->field_score(
            $term,
            $title,
            $excerpt,
            $content,
            70,
            30,
            10
        );
    }

    /*
     * Treat every member of an activated synonym group as equivalent.
     *
     * We take the BEST matching member rather than adding weaker
     * synonym points on top of the literal word.
     *
     * Therefore:
     *
     * battery
     * storage
     * energy storage
     * home battery
     *
     * all represent the same search concept.
     */
    foreach (
        $active_synonym_groups as $group
    ) {

        $best_group_score = 0;

        foreach (
            $group['phrases'] as $phrase
        ) {

            $phrase_score =
                $this->field_score(
                    $phrase,
                    $title,
                    $excerpt,
                    $content,
                    70,
                    30,
                    10
                );

            $best_group_score = max(
                $best_group_score,
                $phrase_score
            );
        }

        $score += $best_group_score;
    }

    return $score;
}

private function matching_content_search_terms(
    $raw_term,
    $post_id
) {

    $search = $this->normalize_search_text(
        $raw_term
    );

    if ( '' === $search ) {
        return array();
    }

    $raw =
        SRA_Search_Content::get_priority_search_terms_raw(
            $post_id
        );

    if ( '' === trim( $raw ) ) {
        return array();
    }

    $lines = preg_split(
        '/\r\n|\r|\n/',
        $raw
    );

    $matches = array();

    foreach (
        is_array( $lines )
            ? $lines
            : array()
        as $line
    ) {

        $phrase =
            $this->normalize_search_text(
                $line
            );

        if ( '' === $phrase ) {
            continue;
        }

        if (
            $this->field_contains(
                $phrase,
                $search
            )
        ) {
            $matches[] = $phrase;
        }
    }

    return array_values(
        array_unique(
            $matches
        )
    );
}

private function term_is_covered_by_synonym_group(
    $term,
    $active_synonym_groups
) {

    foreach (
        $active_synonym_groups as $group
    ) {

        $trigger_terms =
            $this->meaningful_phrase_terms(
                $group['trigger']
            );

        if (
            in_array(
                $term,
                $trigger_terms,
                true
            )
        ) {
            return true;
        }
    }

    return false;
}

    private function active_synonym_groups(
        $raw_term
    ) {

        if (
            ! SRA_Search_Settings::get(
                'enable_synonyms'
            )
        ) {
            return array();
        }

        $search =
            $this->normalize_search_text(
                $raw_term
            );

        if ( '' === $search ) {
            return array();
        }

        $groups =
            $this->synonym_groups();

        $active = array();

        foreach ( $groups as $group ) {

            $trigger = '';

            foreach (
                $group as $candidate
            ) {

                if (
                    $this->contains_phrase(
                        $search,
                        $candidate
                    )
                ) {
                    $trigger = $candidate;
                    break;
                }
            }

            if ( '' === $trigger ) {
                continue;
            }

            $trigger_terms =
                $this->meaningful_phrase_terms(
                    $trigger
                );

            $active[] = array(
                'phrases'        => $group,
                'trigger'        => $trigger,
                'strong_trigger' => count(
                    $trigger_terms
                ) >= 2,
            );
        }

        return $active;
    }

    private function synonym_groups() {

        $raw = (string)
            SRA_Search_Settings::get(
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

            $items = explode(
                '|',
                $line
            );

            $normalized = array();

            foreach (
                $items as $item
            ) {

                $phrase =
                    $this->normalize_search_text(
                        $item
                    );

                if ( '' !== $phrase ) {
                    $normalized[] =
                        $phrase;
                }
            }

            $normalized =
                array_values(
                    array_unique(
                        $normalized
                    )
                );

            if (
                count(
                    $normalized
                ) >= 2
            ) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    private function matching_priority_phrases(
        $raw_term
    ) {

        $search =
            $this->normalize_search_text(
                $raw_term
            );

        if ( '' === $search ) {
            return array();
        }

        $raw = (string)
            SRA_Search_Settings::get(
                'priority_phrases'
            );

        $lines = preg_split(
            '/\r\n|\r|\n/',
            $raw
        );

        $matches = array();

        foreach (
            is_array( $lines )
                ? $lines
                : array()
            as $line
        ) {

            $phrase =
                $this->normalize_search_text(
                    $line
                );

            if ( '' === $phrase ) {
                continue;
            }

            if (
                $this->contains_phrase(
                    $search,
                    $phrase
                )
            ) {
                $matches[] =
                    $phrase;
            }
        }

        return array_values(
            array_unique(
                $matches
            )
        );
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

        if (
            $this->field_contains(
                $term,
                $title
            )
        ) {
            $score += $title_weight;
        }

        if (
            $this->field_contains(
                $term,
                $excerpt
            )
        ) {
            $score += $excerpt_weight;
        }

        if (
            $this->field_contains(
                $term,
                $content
            )
        ) {
            $score += $content_weight;
        }

        return $score;
    }

    private function field_contains(
        $needle,
        $text
    ) {

        $needle =
            $this->normalize_search_text(
                $needle
            );

        if (
            '' === $needle ||
            '' === $text
        ) {
            return false;
        }

        /*
         * Multi-word values must match as a complete phrase.
         */
        if (
            false !== strpos(
                $needle,
                ' '
            )
        ) {
            return $this->contains_phrase(
                $text,
                $needle
            );
        }

        /*
         * Single words may match natural suffixes.
         *
         * Examples:
         *   buy -> buying
         *   tax -> taxes
         */
        $pattern =
            '/(?:^|\s)' .
            preg_quote(
                $needle,
                '/'
            ) .
            '[\p{L}\p{N}]*/u';

        return 1 === preg_match(
            $pattern,
            $text
        );
    }

    private function contains_phrase(
        $text,
        $phrase
    ) {

        $text =
            $this->normalize_search_text(
                $text
            );

        $phrase =
            $this->normalize_search_text(
                $phrase
            );

        if (
            '' === $text ||
            '' === $phrase
        ) {
            return false;
        }

        return false !== strpos(
            ' ' . $text . ' ',
            ' ' . $phrase . ' '
        );
    }

    private function normalize_terms(
        $term
    ) {

        $normalized =
            $this->normalize_search_text(
                $term
            );

        if ( '' === $normalized ) {
            return array();
        }

        $parts = preg_split(
            '/\s+/',
            $normalized
        );

        $stop_words =
            $this->stop_words();

        $terms = array();

        foreach (
            is_array( $parts )
                ? $parts
                : array()
            as $part
        ) {

            $part = trim(
                $part
            );

            if (
                '' === $part ||
                in_array(
                    $part,
                    $stop_words,
                    true
                )
            ) {
                continue;
            }

            $terms[] = $part;
        }

        return array_values(
            array_unique(
                $terms
            )
        );
    }

    private function meaningful_phrase_terms(
        $phrase
    ) {

        return $this->normalize_terms(
            $phrase
        );
    }

    private function stop_words() {

        return array(
            'a',
            'an',
            'and',
            'are',
            'as',
            'at',
            'be',
            'been',
            'being',
            'but',
            'by',
            'can',
            'could',
            'did',
            'do',
            'does',
            'for',
            'from',
            'had',
            'has',
            'have',
            'how',
            'i',
            'if',
            'in',
            'into',
            'is',
            'it',
            'me',
            'my',
            'of',
            'on',
            'or',
            'our',
            'should',
            'that',
            'the',
            'their',
            'them',
            'they',
            'this',
            'to',
            'was',
            'we',
            'were',
            'what',
            'when',
            'where',
            'which',
            'who',
            'why',
            'will',
            'with',
            'would',
            'you',
            'your',
        );
    }

    private function normalize_search_text(
        $value
    ) {

        $value = html_entity_decode(
            wp_strip_all_tags(
                (string) $value
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = $this->lower(
            $value
        );

        /*
         * Convert punctuation, hyphens, apostrophes, question marks,
         * etc. into spaces so searches compare consistently.
         *
         * Examples:
         *   battery?       -> battery
         *   plug-in solar  -> plug in solar
         */
        $value = preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            $value
        );

        $value = trim(
            preg_replace(
                '/\s+/u',
                ' ',
                (string) $value
            )
        );

        return $value;
    }

    private function parse_category_slugs(
        $raw
    ) {

        $parts = explode(
            ',',
            (string) $raw
        );

        $slugs = array();

        foreach (
            $parts as $part
        ) {

            $slug = sanitize_title(
                trim( $part )
            );

            if ( '' !== $slug ) {
                $slugs[] = $slug;
            }
        }

        return array_values(
            array_unique(
                $slugs
            )
        );
    }

    private function category_ids_from_slugs(
        $slugs
    ) {

        $ids = array();

        foreach (
            $slugs as $slug
        ) {

            $category =
                get_category_by_slug(
                    $slug
                );

            if ( $category ) {
                $ids[] =
                    (int)
                    $category->term_id;
            }
        }

        return array_values(
            array_unique(
                $ids
            )
        );
    }

    private function parse_post_types(
        $raw
    ) {

        $parts = explode(
            ',',
            (string) $raw
        );

        $allowed = array(
            'post',
            'page',
        );

        $post_types = array();

        foreach (
            $parts as $part
        ) {

            $post_type =
                sanitize_key(
                    trim( $part )
                );

            if (
                '' !== $post_type &&
                in_array(
                    $post_type,
                    $allowed,
                    true
                )
            ) {
                $post_types[] =
                    $post_type;
            }
        }

        return array_values(
            array_unique(
                $post_types
            )
        );
    }

    private function plain_text(
        $value
    ) {

        return html_entity_decode(
            wp_strip_all_tags(
                (string) $value
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function lower(
        $value
    ) {

        return function_exists(
            'mb_strtolower'
        )
            ? mb_strtolower(
                (string) $value,
                'UTF-8'
            )
            : strtolower(
                (string) $value
            );
    }

    private function is_local_url(
        $url
    ) {

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
            strtolower(
                $url_host
            ) ===
            strtolower(
                $home_host
            );
    }

    public function filter_main_query(
        $query
    ) {

        if (
            is_admin() ||
            ! $query->is_main_query() ||
            ! $query->is_search() ||
            empty(
                $_GET['category_scope']
            )
        ) {
            return;
        }

        $category_raw =
            sanitize_text_field(
                wp_unslash(
                    $_GET[
                        'category_scope'
                    ]
                )
            );

        $post_types_raw = isset(
            $_GET['post_type_scope']
        )
            ? sanitize_text_field(
                wp_unslash(
                    $_GET[
                        'post_type_scope'
                    ]
                )
            )
            : 'post';

        $category_slugs =
            $this->parse_category_slugs(
                $category_raw
            );

        $category_ids =
            $this->category_ids_from_slugs(
                $category_slugs
            );

        $post_types =
            $this->parse_post_types(
                $post_types_raw
            );

        if (
            empty( $category_slugs ) ||
            count( $category_ids ) !==
                count( $category_slugs ) ||
            empty( $post_types )
        ) {
            return;
        }

        if (
            in_array(
                'post',
                $post_types,
                true
            ) &&
            in_array(
                'page',
                $post_types,
                true
            )
        ) {

            $query->set(
                'post_type',
                array(
                    'post',
                    'page',
                )
            );

            $query->set(
                'sra_category_ids',
                $category_ids
            );

            add_filter(
                'posts_clauses',
                array(
                    $this,
                    'filter_mixed_search_clauses',
                ),
                10,
                2
            );

            return;
        }

        if (
            in_array(
                'page',
                $post_types,
                true
            )
        ) {

            $query->set(
                'post_type',
                'page'
            );

            $query->set(
                'meta_key',
                SRA_Search_Content::META_KEY
            );

            $query->set(
                'meta_value',
                '1'
            );

            return;
        }

        $query->set(
            'post_type',
            'post'
        );

        $query->set(
            'category__in',
            $category_ids
        );

$query->set(
    'meta_key',
    SRA_Search_Content::META_KEY
);

$query->set(
    'meta_value',
    '1'
);

    }

    public function filter_mixed_search_clauses(
        $clauses,
        $query
    ) {

        if (
            ! $query->is_main_query() ||
            ! $query->is_search()
        ) {
            return $clauses;
        }

        $category_ids =
            $query->get(
                'sra_category_ids'
            );

        if (
            empty( $category_ids ) ||
            ! is_array(
                $category_ids
            )
        ) {
            return $clauses;
        }

        global $wpdb;

        $category_ids = array_map(
            'absint',
            $category_ids
        );

        $category_ids = array_filter(
            $category_ids
        );

        if (
            empty(
                $category_ids
            )
        ) {
            return $clauses;
        }

        $category_list = implode(
            ',',
            $category_ids
        );

        $meta_key =
            SRA_Search_Content::META_KEY;

        $clauses['where'] .= "
            AND (
                
(
    {$wpdb->posts}.post_type = 'post'
    AND EXISTS (
        SELECT 1
        FROM {$wpdb->term_relationships} AS sra_tr
        INNER JOIN {$wpdb->term_taxonomy} AS sra_tt
            ON sra_tr.term_taxonomy_id = sra_tt.term_taxonomy_id
        WHERE
            sra_tr.object_id = {$wpdb->posts}.ID
            AND sra_tt.taxonomy = 'category'
            AND sra_tt.term_id IN ({$category_list})
    )
    AND EXISTS (
        SELECT 1
        FROM {$wpdb->postmeta} AS sra_post_pm
        WHERE
            sra_post_pm.post_id = {$wpdb->posts}.ID
            AND sra_post_pm.meta_key = '" .
            esc_sql(
                $meta_key
            ) .
            "'
            AND sra_post_pm.meta_value = '1'
    )
)

                OR
                (
                    {$wpdb->posts}.post_type = 'page'
                    AND EXISTS (
                        SELECT 1
                        FROM {$wpdb->postmeta} AS sra_pm
                        WHERE
                            sra_pm.post_id = {$wpdb->posts}.ID
                            AND sra_pm.meta_key = '" .
                            esc_sql(
                                $meta_key
                            ) .
                            "'
                            AND sra_pm.meta_value = '1'
                    )
                )
            )
        ";

        remove_filter(
            'posts_clauses',
            array(
                $this,
                'filter_mixed_search_clauses',
            ),
            10
        );

        return $clauses;
    }
}