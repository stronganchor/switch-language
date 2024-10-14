<?php
/**
 * Plugin Name: Switch Language
 * Description: Automatically switches the WordPress site language based on the user's browser language setting
 * Version: 1.1.0
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

        // Extract text from HTML content
        $extracted_texts = extract_user_facing_text($homepage_content);

        // Save extracted texts to the database, avoiding duplicates
        save_extracted_texts($extracted_texts);
    }
}

// Utility function to extract user-facing text from HTML
function extract_user_facing_text($content) {
    // Load content into DOMDocument to parse HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress warnings for invalid HTML
    $dom->loadHTML($content);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//text()[normalize-space() and not(ancestor::script) and not(ancestor::style)]');

    $extracted_texts = [];
    foreach ($nodes as $node) {
        $extracted_texts[] = trim($node->nodeValue); // Collect all user-facing texts
    }

    return array_filter($extracted_texts); // Remove empty texts
}

// Save the extracted texts into the custom database table without creating duplicates
function save_extracted_texts($extracted_texts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $source_language = get_locale(); // Use current WordPress locale as source language

    // Set the charset to UTF-8 explicitly
    $wpdb->query("SET NAMES 'utf8mb4'");

    foreach ($extracted_texts as $text) {
        // Check if the text already exists in the database
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

// Add an admin menu page to display and manage extracted texts
function add_text_extraction_admin_menu() {
    add_menu_page(
        'Extracted Texts', 
        'Extracted Texts', 
        'manage_options', 
        'extracted-texts', 
        'display_extracted_texts'
    );
}
add_action('admin_menu', 'add_text_extraction_admin_menu');

// Display extracted texts in a table and add buttons for running extraction and emptying the database
function display_extracted_texts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    echo '<div class="wrap">';
    echo '<h1>Extracted Texts</h1>';
    
    // Add buttons for running extraction and emptying the database
    echo '<form method="post">';
    submit_button('Run Extraction Again', 'primary', 'run_extraction');
    submit_button('Empty Database', 'delete', 'empty_database');
    echo '</form>';

    // Handle form submissions
    if (isset($_POST['run_extraction'])) {
        extract_homepage_text();
        echo '<div class="updated"><p>Extraction process has been run again.</p></div>';
    }

    if (isset($_POST['empty_database'])) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>All extracted texts have been deleted.</p></div>';
    }

    // Display the extracted texts in a table
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

// Trigger the text extraction on plugin activation
register_activation_hook(__FILE__, 'extract_homepage_text');
