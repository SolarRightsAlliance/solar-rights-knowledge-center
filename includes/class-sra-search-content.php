<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Controls which WordPress content is eligible for Knowledge Center search.
 */
final class SRA_Search_Content {

    const META_KEY     = '_sra_include_in_search';
    const NONCE_ACTION = 'sra_save_search_content';
    const NONCE_NAME   = 'sra_search_content_nonce';

    private static $instance;

    public static function instance() {

        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {

        add_action(
            'add_meta_boxes_page',
            array( $this, 'add_page_meta_box' )
        );

        add_action(
            'save_post_page',
            array( $this, 'save_page_setting' )
        );
    }

    public function add_page_meta_box() {

        add_meta_box(
            'sra-knowledge-center-search',
            __(
                'Knowledge Center Search',
                'solar-rights-search'
            ),
            array(
                $this,
                'render_page_meta_box',
            ),
            'page',
            'side',
            'default'
        );
    }

    public function render_page_meta_box( $post ) {

        wp_nonce_field(
            self::NONCE_ACTION,
            self::NONCE_NAME
        );

        $included = '1' === get_post_meta(
            $post->ID,
            self::META_KEY,
            true
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
                    'Include this page in Knowledge Center search',
                    'solar-rights-search'
                );
                ?>
            </label>
        </p>

        <p class="description">
            <?php
            echo esc_html__(
                'Only pages with this box checked will be eligible to appear in Knowledge Center search results.',
                'solar-rights-search'
            );
            ?>
        </p>
        <?php
    }

    public function save_page_setting( $post_id ) {

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

        $included = isset(
            $_POST['sra_include_in_search']
        );

        if ( $included ) {

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
    }

    public static function page_is_searchable( $post_id ) {

        return '1' === get_post_meta(
            $post_id,
            self::META_KEY,
            true
        );
    }
}