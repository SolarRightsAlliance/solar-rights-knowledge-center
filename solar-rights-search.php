<?php
/**
 * Plugin Name: Solar Rights Knowledge Center
 * Description: Human-first, category-restricted live search and privacy-conscious editorial analytics for Solar Rights Alliance content.
 * Version: 1.3.8-test5
 * Author: Solar Rights Alliance
 * License: GPL-2.0-or-later
 * Text Domain: solar-rights-search
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SRA_SEARCH_VERSION', '1.3.8-test5' );
define( 'SRA_SEARCH_DB_VERSION', '1.0.0' );
define( 'SRA_SEARCH_FILE', __FILE__ );
define( 'SRA_SEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'SRA_SEARCH_PATH', plugin_dir_path( __FILE__ ) );

require_once SRA_SEARCH_PATH . 'includes/class-sra-search-settings.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-search-analytics.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-analytics-queries.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-analytics-dashboard.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-search-content.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-knowledge-video-embed.php';

require_once SRA_SEARCH_PATH . 'includes/class-sra-search-plugin.php';

register_activation_hook(
    __FILE__,
    array(
        'SRA_Search_Analytics',
        'activate',
    )
);

register_deactivation_hook(
    __FILE__,
    array(
        'SRA_Search_Analytics',
        'deactivate',
    )
);

SRA_Search_Content::instance();

SRA_Knowledge_Video_Embed::instance();

SRA_Search_Plugin::instance();