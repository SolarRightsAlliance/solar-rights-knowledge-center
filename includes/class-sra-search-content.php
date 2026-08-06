<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controls which WordPress content is eligible for Knowledge Center search.
 *
 * All Posts and Pages are opt-in.
 */
final class SRA_Search_Content {

    const META_KEY              = '_sra_include_in_search';
    const SEARCH_TERMS_META_KEY = '_sra_search_priority_terms';

    const NONCE_ACTION = 'sra_save_search_content';
    const NONCE_NAME   = 'sra_search_content_nonce';
    const COLUMN_KEY   = 'sra_knowledge_center_search';

    private static $instance;

    public static function instance() {

        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {

        add_action(
            'add_meta_boxes',
            array( $this, 'add_meta_boxes' )
        );

        add_action(
            'save_post_post',
            array( $this, 'save_individual_setting' )
        );

        add_action(
            'save_post_page',
            array( $this, 'save_individual_setting' )
        );

        add_filter(
            'manage_post_posts_columns',
            array( $this, 'add_list_column' )
        );

        add_filter(
            'manage_page_posts_columns',
            array( $this, 'add_list_column' )
        );

        add_action(
            'manage_post_posts_custom_column',
            array( $this, 'render_list_column' ),
            10,
            2
        );

        add_action(
            'manage_page_posts_custom_column',
            array( $this, 'render_list_column' ),
            10,
            2
        );

        add_filter(
            'bulk_actions-edit-post',
            array( $this, 'add_bulk_actions' )
        );

        add_filter(
            'bulk_actions-edit-page',
            array( $this, 'add_bulk_actions' )
        );

        add_filter(
            'handle_bulk_actions-edit-post',
            array( $this, 'handle_bulk_action' ),
            10,
            3
        );

        add_filter(
            'handle_bulk_actions-edit-page',
            array( $this, 'handle_bulk_action' ),
            10,
            3
        );

        add_action(
            'admin_notices',
            array( $this, 'render_bulk_notice' )
        );
    }

    public function add_meta_boxes() {

        foreach ( array( 'post', 'page' ) as $post_type ) {

            add_meta_box(
                'sra-knowledge-center-search',
                __(
                    'Knowledge Center Search',
                    'solar-rights-search'
                ),
                array(
                    $this,
                    'render_meta_box',
                ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post ) {

        wp_nonce_field(
            self::NONCE_ACTION,
            self::NONCE_NAME
        );

        $included = self::is_searchable(
            $post->ID
        );

        $search_terms = self::get_priority_search_terms_raw(
            $post->ID
        );

        ?>
        <p>
            <label>
                <input
                    type="checkbox"
                    name="sra_include_in_search"
                    value="1"
                    <?php checked( $included ); ?>
                >

                <?php
                echo esc_html__(
                    'Include this content in Knowledge Center search',
                    'solar-rights-search'
                );
                ?>
            </label>
        </p>

        <p class="description">
            <?php
            echo esc_html__(
                'Only content with this box checked is eligible to appear in Knowledge Center search results.',
                'solar-rights-search'
            );
            ?>
        </p>

        <hr>

        <p>
            <label for="sra_search_priority_terms">
                <strong>
                    <?php
                    echo esc_html__(
                        'Search terms to prioritize this content for',
                        'solar-rights-search'
                    );
                    ?>
                </strong>
            </label>
        </p>

        <textarea
            id="sra_search_priority_terms"
            name="sra_search_priority_terms"
            rows="7"
            style="width:100%;"
            placeholder="solar company&#10;solar companies&#10;installer&#10;solar installer"
        ><?php echo esc_textarea( $search_terms ); ?></textarea>

        <p class="description">
            <?php
            echo esc_html__(
                'Optional. Enter one search term or phrase per line. Matching searches will strongly prioritize this content.',
                'solar-rights-search'
            );
            ?>
        </p>
        <?php
    }

    public function save_individual_setting( $post_id ) {

        if (
            defined( 'DOING_AUTOSAVE' ) &&
            DOING_AUTOSAVE
        ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if (
            ! isset( $_POST[ self::NONCE_NAME ] ) ||
            ! wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash(
                        $_POST[ self::NONCE_NAME ]
                    )
                ),
                self::NONCE_ACTION
            )
        ) {
            return;
        }

        if (
            ! current_user_can(
                'edit_post',
                $post_id
            )
        ) {
            return;
        }

        if (
            isset(
                $_POST['sra_include_in_search']
            )
        ) {

            update_post_meta(
                $post_id,
                self::META_KEY,
                '1'
            );

        } else {

            delete_post_meta(
                $post_id,
                self::META_KEY
            );
        }

        $search_terms = isset(
            $_POST['sra_search_priority_terms']
        )
            ? sanitize_textarea_field(
                wp_unslash(
                    $_POST['sra_search_priority_terms']
                )
            )
            : '';

        if ( '' !== trim( $search_terms ) ) {

            update_post_meta(
                $post_id,
                self::SEARCH_TERMS_META_KEY,
                $search_terms
            );

        } else {

            delete_post_meta(
                $post_id,
                self::SEARCH_TERMS_META_KEY
            );
        }
    }

    public function add_list_column( $columns ) {

        $columns[ self::COLUMN_KEY ] = __(
            'Knowledge Center',
            'solar-rights-search'
        );

        return $columns;
    }

    public function render_list_column(
        $column_name,
        $post_id
    ) {

        if (
            self::COLUMN_KEY !==
            $column_name
        ) {
            return;
        }

        if (
            self::is_searchable(
                $post_id
            )
        ) {

            echo esc_html__(
                'Included',
                'solar-rights-search'
            );

        } else {

            echo esc_html__(
                'Not included',
                'solar-rights-search'
            );
        }
    }

    public function add_bulk_actions( $actions ) {

        $actions['sra_include_search'] = __(
            'Include in Knowledge Center',
            'solar-rights-search'
        );

        $actions['sra_exclude_search'] = __(
            'Exclude from Knowledge Center',
            'solar-rights-search'
        );

        return $actions;
    }

    public function handle_bulk_action(
        $redirect_url,
        $action,
        $post_ids
    ) {

        if (
            ! in_array(
                $action,
                array(
                    'sra_include_search',
                    'sra_exclude_search',
                ),
                true
            )
        ) {
            return $redirect_url;
        }

        $updated = 0;

        foreach ( $post_ids as $post_id ) {

            $post_id = absint(
                $post_id
            );

            if (
                ! $post_id ||
                ! current_user_can(
                    'edit_post',
                    $post_id
                )
            ) {
                continue;
            }

            $post_type = get_post_type(
                $post_id
            );

            if (
                ! in_array(
                    $post_type,
                    array( 'post', 'page' ),
                    true
                )
            ) {
                continue;
            }

            if (
                'sra_include_search' ===
                $action
            ) {

                update_post_meta(
                    $post_id,
                    self::META_KEY,
                    '1'
                );

            } else {

                delete_post_meta(
                    $post_id,
                    self::META_KEY
                );
            }

            $updated++;
        }

        return add_query_arg(
            array(
                'sra_kc_updated' => $updated,
                'sra_kc_action'  =>
                    'sra_include_search' === $action
                        ? 'included'
                        : 'excluded',
            ),
            $redirect_url
        );
    }

    public function render_bulk_notice() {

        if (
            empty(
                $_GET['sra_kc_updated']
            ) ||
            empty(
                $_GET['sra_kc_action']
            )
        ) {
            return;
        }

        $updated = absint(
            $_GET['sra_kc_updated']
        );

        $action = sanitize_key(
            wp_unslash(
                $_GET['sra_kc_action']
            )
        );

        if (
            ! in_array(
                $action,
                array(
                    'included',
                    'excluded',
                ),
                true
            )
        ) {
            return;
        }

        $message = 'included' === $action
            ? sprintf(
                _n(
                    '%d item included in Knowledge Center search.',
                    '%d items included in Knowledge Center search.',
                    $updated,
                    'solar-rights-search'
                ),
                $updated
            )
            : sprintf(
                _n(
                    '%d item excluded from Knowledge Center search.',
                    '%d items excluded from Knowledge Center search.',
                    $updated,
                    'solar-rights-search'
                ),
                $updated
            );

        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php echo esc_html( $message ); ?>
            </p>
        </div>
        <?php
    }

    public static function is_searchable(
        $post_id
    ) {

        return '1' === get_post_meta(
            $post_id,
            self::META_KEY,
            true
        );
    }

    public static function get_priority_search_terms_raw(
        $post_id
    ) {

        return (string) get_post_meta(
            $post_id,
            self::SEARCH_TERMS_META_KEY,
            true
        );
    }

    public static function page_is_searchable(
        $post_id
    ) {

        return self::is_searchable(
            $post_id
        );
    }
}