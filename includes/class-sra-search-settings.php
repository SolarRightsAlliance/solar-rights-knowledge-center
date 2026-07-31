<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRA_Search_Settings {
    const OPTION_KEY = 'sra_search_options';

    public static function defaults() {
        return array(
            'max_results'      => 6,
            'highlight_terms'  => 1,
            'enable_synonyms'  => 1,
            'enable_analytics' => 1,
            'retention_days'   => 365,
            'synonym_groups'   => "battery|storage|energy storage|home battery\nnem|net metering|net energy metering\nev|electric vehicle|electric car\nppa|power purchase agreement\nlease|leasing|solar lease\nhoa|homeowners association|homeowner association",
        );
    }

    public static function get_all() {
        $saved = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function get( $key ) {
        $options = self::get_all();
        return array_key_exists( $key, $options ) ? $options[ $key ] : null;
    }

    public static function register() {
        register_setting(
            'sra_search_settings_group',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize' ),
                'default'           => self::defaults(),
            )
        );

        add_settings_section(
            'sra_search_general',
            __( 'Search behavior', 'solar-rights-search' ),
            '__return_false',
            'sra-search-settings'
        );

        add_settings_field( 'max_results', __( 'Dropdown results', 'solar-rights-search' ), array( __CLASS__, 'render_max_results' ), 'sra-search-settings', 'sra_search_general' );
        add_settings_field( 'highlight_terms', __( 'Highlight matches', 'solar-rights-search' ), array( __CLASS__, 'render_highlight_terms' ), 'sra-search-settings', 'sra_search_general' );
        add_settings_field( 'enable_synonyms', __( 'Enable synonyms', 'solar-rights-search' ), array( __CLASS__, 'render_enable_synonyms' ), 'sra-search-settings', 'sra_search_general' );
        add_settings_field( 'synonym_groups', __( 'Synonym groups', 'solar-rights-search' ), array( __CLASS__, 'render_synonym_groups' ), 'sra-search-settings', 'sra_search_general' );

        add_settings_section(
            'sra_search_analytics',
            __( 'Privacy-conscious analytics', 'solar-rights-search' ),
            array( __CLASS__, 'render_analytics_section' ),
            'sra-search-settings'
        );

        add_settings_field( 'enable_analytics', __( 'Enable analytics', 'solar-rights-search' ), array( __CLASS__, 'render_enable_analytics' ), 'sra-search-settings', 'sra_search_analytics' );
        add_settings_field( 'retention_days', __( 'Data retention', 'solar-rights-search' ), array( __CLASS__, 'render_retention_days' ), 'sra-search-settings', 'sra_search_analytics' );
    }

    public static function sanitize( $input ) {
        $defaults = self::defaults();
        $input    = is_array( $input ) ? $input : array();

        $max_results = isset( $input['max_results'] ) ? absint( $input['max_results'] ) : $defaults['max_results'];
        $max_results = max( 3, min( 10, $max_results ) );

        $retention_days = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : $defaults['retention_days'];
        $retention_days = max( 30, min( 1095, $retention_days ) );

        $groups = isset( $input['synonym_groups'] ) ? sanitize_textarea_field( $input['synonym_groups'] ) : $defaults['synonym_groups'];

        return array(
            'max_results'      => $max_results,
            'highlight_terms'  => empty( $input['highlight_terms'] ) ? 0 : 1,
            'enable_synonyms'  => empty( $input['enable_synonyms'] ) ? 0 : 1,
            'enable_analytics' => empty( $input['enable_analytics'] ) ? 0 : 1,
            'retention_days'   => $retention_days,
            'synonym_groups'   => $groups,
        );
    }

    public static function render_analytics_section() {
        echo '<p>' . esc_html__( 'Stores aggregate search behavior without names, email addresses, IP addresses, user accounts, cookies, or individual browsing histories.', 'solar-rights-search' ) . '</p>';
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Knowledge Center Settings', 'solar-rights-search' ); ?></h1>
            <p><?php echo esc_html__( 'Configure live-search results, matching highlights, synonyms, and privacy-conscious analytics.', 'solar-rights-search' ); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'sra_search_settings_group' );
                do_settings_sections( 'sra-search-settings' );
                submit_button();
                ?>
            </form>
            <hr>
            <h2><?php echo esc_html__( 'Shortcode example', 'solar-rights-search' ); ?></h2>
            <code>[sra_search category="consumer-guide" placeholder="Search the Consumer Guide..."]</code>
        </div>
        <?php
    }

    public static function render_max_results() {
        $value = absint( self::get( 'max_results' ) );
        ?>
        <input type="number" min="3" max="10" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_results]" value="<?php echo esc_attr( $value ); ?>">
        <p class="description"><?php echo esc_html__( 'Number shown before the “View all” link. Allowed range: 3–10.', 'solar-rights-search' ); ?></p>
        <?php
    }

    public static function render_highlight_terms() {
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[highlight_terms]" value="1" <?php checked( 1, (int) self::get( 'highlight_terms' ) ); ?>> <?php echo esc_html__( 'Highlight the visitor’s typed words in titles and excerpts.', 'solar-rights-search' ); ?></label>
        <?php
    }

    public static function render_enable_synonyms() {
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_synonyms]" value="1" <?php checked( 1, (int) self::get( 'enable_synonyms' ) ); ?>> <?php echo esc_html__( 'Expand searches using the synonym groups below.', 'solar-rights-search' ); ?></label>
        <?php
    }

    public static function render_synonym_groups() {
        $value = (string) self::get( 'synonym_groups' );
        ?>
        <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[synonym_groups]" rows="9" cols="70" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description"><?php echo esc_html__( 'Enter one two-way synonym group per line, separated by vertical bars. Example: battery|storage|energy storage', 'solar-rights-search' ); ?></p>
        <?php
    }

    public static function render_enable_analytics() {
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_analytics]" value="1" <?php checked( 1, (int) self::get( 'enable_analytics' ) ); ?>> <?php echo esc_html__( 'Record search terms, category, result count, source page, and anonymous clicks.', 'solar-rights-search' ); ?></label>
        <?php
    }

    public static function render_retention_days() {
        $value = absint( self::get( 'retention_days' ) );
        ?>
        <input type="number" min="30" max="1095" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retention_days]" value="<?php echo esc_attr( $value ); ?>"> <?php echo esc_html__( 'days', 'solar-rights-search' ); ?>
        <p class="description"><?php echo esc_html__( 'Records older than this are deleted automatically. Allowed range: 30–1,095 days.', 'solar-rights-search' ); ?></p>
        <?php
    }
}
