<?php
/**
 * Plugin Name: Switch Language
 * Description: Automatically switches the WordPress site language based on the user's browser language setting
 * Version: 1.2.0
 * Author: Strong Anchor Tech
 * Author URI: https://stronganchortech.com
 * License: GPL2 or later
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Switch the site language based on the user's browser language
function switch_language() {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    // Get the list of available languages in your WordPress site
    $available_languages = get_available_languages();

    // Extract the preferred language from the browser
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

    // Convert browser language to WordPress locale
    $language_map = [
        'en' => 'en_US',
        'tr' => 'tr_TR',
    ];

    $wp_lang = isset($language_map[$browser_lang]) ? $language_map[$browser_lang] : '';

    // Switch language if it's available
    if (in_array($wp_lang, $available_languages)) {
        switch_to_locale($wp_lang);
    }
}
add_action('init', 'switch_language');

// Create a custom table for storing extracted text
function create_text_extraction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        original_text text NOT NULL,
        source_language varchar(10) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'create_text_extraction_table');

// Extract homepage content and store user-facing text
function extract_homepage_text() {
    // Get the homepage content
    $homepage_id = get_option('page_on_front'); // Get the homepage ID
    if ($homepage_id) {
        $homepage_content = get_post_field('post_content', $homepage_id);
        $homepage_content = mb_convert_encoding($homepage_content, 'UTF-8', 'auto'); // Convert to UTF-8

        // Extract text from HTML content
        $extracted_texts = extract_user_facing_text($homepage_content);

        // Save extracted texts to the database, avoiding duplicates
        save_extracted_texts($extracted_texts);
    }
}

// Utility function to extract user-facing text from HTML
function extract_user_facing_text($content) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true); 
    $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
    $dom->loadHTML($content);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//text()[normalize-space() and not(ancestor::script) and not(ancestor::style)]');

    $extracted_texts = [];
    foreach ($nodes as $node) {
        $extracted_texts[] = trim($node->nodeValue);
    }

    return array_filter($extracted_texts);
}

// Save the extracted texts into the custom database table without creating duplicates
function save_extracted_texts($extracted_texts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $source_language = get_locale();

    $wpdb->query("SET NAMES 'utf8mb4'");

    foreach ($extracted_texts as $text) {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE original_text = %s AND source_language = %s",
            $text, $source_language
        ));

        if ($exists == 0) {
            $wpdb->insert($table_name, [
                'original_text' => $text,
                'source_language' => $source_language
            ]);
        }
    }
}

// Add an admin menu with two sub-pages
function add_switch_language_admin_menu() {
    add_menu_page(
        'Switch Language',
        'Switch Language',
        'manage_options',
        'switch-language',
        'switch_language_settings_page',
        'dashicons-translation'
    );

    add_submenu_page(
        'switch-language',
        'Extracted Texts',
        'Extracted Texts',
        'manage_options',
        'extracted-texts',
        'display_extracted_texts'
    );

    add_submenu_page(
        'switch-language',
        'DeepL API Settings',
        'DeepL API Settings',
        'manage_options',
        'deepl-api-settings',
        'deepl_api_settings_page'
    );
}
add_action('admin_menu', 'add_switch_language_admin_menu');

// Display extracted texts page
function display_extracted_texts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    echo '<div class="wrap">';
    echo '<h1>Extracted Texts</h1>';
    echo '<form method="post">';
    submit_button('Run Extraction Again', 'primary', 'run_extraction');
    submit_button('Empty Database', 'delete', 'empty_database');
    echo '</form>';

    if (isset($_POST['run_extraction'])) {
        extract_homepage_text();
        echo '<div class="updated"><p>Extraction process has been run again.</p></div>';
    }

    if (isset($_POST['empty_database'])) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>All extracted texts have been deleted.</p></div>';
    }

    echo '<table class="widefat">';
    echo '<thead><tr><th>ID</th><th>Text</th><th>Source Language</th></tr></thead>';
    echo '<tbody>';

    if (!empty($results)) {
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->original_text) . '</td>';
            echo '<td>' . esc_html($row->source_language) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="3">No extracted texts found.</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

// DeepL API Settings page
function deepl_api_settings_page() {
    ?>
    <div class="wrap">
        <h1>DeepL API Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('deepl_api_settings_group');
            do_settings_sections('deepl-api-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register DeepL API settings
function deepl_api_register_settings() {
    register_setting('deepl_api_settings_group', 'deepl_api_key');

    add_settings_section(
        'deepl_api_settings_section',
        'DeepL API Configuration',
        null,
        'deepl-api-settings'
    );

    add_settings_field(
        'deepl_api_key',
        'DeepL API Key',
        'deepl_api_key_callback',
        'deepl-api-settings',
        'deepl_api_settings_section'
    );
}
add_action('admin_init', 'deepl_api_register_settings');

// DeepL API Key field callback
function deepl_api_key_callback() {
    $deepl_api_key = get_option('deepl_api_key', '');
    echo '<input type="text" name="deepl_api_key" value="' . esc_attr($deepl_api_key) . '" size="40">';
}

// Default settings page
function switch_language_settings_page() {
    echo '<h1>Switch Language Plugin Settings</h1>';
}
