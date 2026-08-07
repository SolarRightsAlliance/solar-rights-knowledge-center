<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRA_Knowledge_Video_Embed {

    private static $instance;

    public static function instance() {

        if ( ! self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {

        add_shortcode(
            'sra_knowledge_video',
            array( $this, 'shortcode' )
        );

        add_action(
            'wp_enqueue_scripts',
            array( $this, 'register_assets' )
        );
    }

    public function register_assets() {

        wp_register_style(
            'sra-knowledge-video',
            SRA_SEARCH_URL . 'assets/knowledge-video.css',
            array(),
            SRA_SEARCH_VERSION
        );
    }

    public function shortcode( $atts ) {

        if ( ! is_singular() ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'max_width' => '280',
                'ratio'     => '9/16',
            ),
            $atts,
            'sra_knowledge_video'
        );

        /*
         * Use the queried object first. Elementor can temporarily change
         * the global post while rendering widgets, so get_the_ID() is not
         * always the Consumer Guide article that owns this shortcode.
         */
        $current_post_id = absint(
            get_queried_object_id()
        );

        if ( ! $current_post_id ) {
            $current_post_id = absint(
                get_the_ID()
            );
        }

        if ( ! $current_post_id ) {
            return '';
        }

        /*
         * First try the bidirectional ACF field stored on the Consumer
         * Guide article. Ask ACF for the raw value when available, then
         * fall back to ordinary post meta.
         */
        if ( function_exists( 'get_field' ) ) {

            $knowledge_video_id = get_field(
                'knowledge-video',
                $current_post_id,
                false
            );

        } else {

            $knowledge_video_id = get_post_meta(
                $current_post_id,
                'knowledge-video',
                true
            );
        }

        if ( is_array( $knowledge_video_id ) ) {
            $knowledge_video_id = reset(
                $knowledge_video_id
            );
        }

        if (
            is_object( $knowledge_video_id ) &&
            isset( $knowledge_video_id->ID )
        ) {
            $knowledge_video_id =
                $knowledge_video_id->ID;
        }

        $knowledge_video_id =
            absint( $knowledge_video_id );

        /*
         * If the reverse/bidirectional value is unavailable, find the
         * Knowledge Video from the forward relationship instead. This
         * lets the embed work from the single editorial relationship
         * maintained on the Knowledge Video itself.
         */
        if ( ! $knowledge_video_id ) {

            $video_query = new WP_Query(
                array(
                    'post_type'              => 'knowledge-video',
                    'post_status'            => 'publish',
                    'posts_per_page'         => 1,
                    'fields'                 => 'ids',
                    'meta_key'               => 'related_consumer_guide_article',
                    'meta_value'             => $current_post_id,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );

            if ( ! empty( $video_query->posts ) ) {
                $knowledge_video_id =
                    absint( $video_query->posts[0] );
            }
        }

        if (
            ! $knowledge_video_id ||
            'knowledge-video' !== get_post_type(
                $knowledge_video_id
            ) ||
            'publish' !== get_post_status(
                $knowledge_video_id
            )
        ) {
            return '';
        }

        if ( function_exists( 'get_field' ) ) {

            $youtube_url = get_field(
                'youtube_url',
                $knowledge_video_id,
                false
            );

        } else {

            $youtube_url = get_post_meta(
                $knowledge_video_id,
                'youtube_url',
                true
            );
        }

        $youtube_url = trim(
            (string) $youtube_url
        );

        $video_id = $this->youtube_video_id(
            $youtube_url
        );

        if ( '' === $video_id ) {
            return '';
        }

        $max_width = absint(
            $atts['max_width']
        );

        if ( $max_width < 120 ) {
            $max_width = 280;
        }

        $ratio = $this->sanitize_ratio(
            $atts['ratio']
        );

        $title = get_the_title(
            $knowledge_video_id
        );

        wp_enqueue_style(
            'sra-knowledge-video'
        );

        $embed_url = add_query_arg(
            array(
                'rel' => '0',
            ),
            'https://www.youtube-nocookie.com/embed/' .
            rawurlencode( $video_id )
        );

        ob_start();
        ?>
        <div
            class="sra-knowledge-video"
            style="<?php echo esc_attr(
                '--sra-video-max-width:' .
                $max_width .
                'px;--sra-video-ratio:' .
                $ratio . ';'
            ); ?>"
        >
            <iframe
                src="<?php echo esc_url( $embed_url ); ?>"
                title="<?php echo esc_attr( $title ); ?>"
                loading="lazy"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            ></iframe>
        </div>
        <?php

        return ob_get_clean();
    }

    private function youtube_video_id(
        $url
    ) {

        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url(
            $url
        );

        if (
            empty( $parts['host'] )
        ) {
            return '';
        }

        $host = strtolower(
            preg_replace(
                '/^www\./',
                '',
                $parts['host']
            )
        );

        $video_id = '';

        if ( 'youtu.be' === $host ) {

            $video_id = isset(
                $parts['path']
            )
                ? trim(
                    $parts['path'],
                    '/'
                )
                : '';

        } elseif (
            in_array(
                $host,
                array(
                    'youtube.com',
                    'm.youtube.com',
                ),
                true
            )
        ) {

            $path = isset(
                $parts['path']
            )
                ? trim(
                    $parts['path'],
                    '/'
                )
                : '';

            if (
                0 === strpos(
                    $path,
                    'shorts/'
                )
            ) {

                $video_id = substr(
                    $path,
                    strlen(
                        'shorts/'
                    )
                );

            } elseif (
                0 === strpos(
                    $path,
                    'embed/'
                )
            ) {

                $video_id = substr(
                    $path,
                    strlen(
                        'embed/'
                    )
                );

            } elseif (
                isset(
                    $parts['query']
                )
            ) {

                parse_str(
                    $parts['query'],
                    $query_args
                );

                if (
                    ! empty(
                        $query_args['v']
                    )
                ) {
                    $video_id =
                        $query_args['v'];
                }
            }
        }

        $video_id = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) $video_id
        );

        return is_string(
            $video_id
        )
            ? $video_id
            : '';
    }

    private function sanitize_ratio(
        $ratio
    ) {

        $ratio = preg_replace(
            '/\s+/',
            '',
            (string) $ratio
        );

        if (
            ! preg_match(
                '/^[1-9][0-9]*\/[1-9][0-9]*$/',
                $ratio
            )
        ) {
            return '9/16';
        }

        return str_replace(
            '/',
            ' / ',
            $ratio
        );
    }
}
