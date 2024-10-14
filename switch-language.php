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

// Include DeepL translation file
require_once plugin_dir_path(__FILE__) . 'includes/deepl-translation.php';

// Switch the site language based on the user's browser language
function switch_language() {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    $available_languages = get_available_languages();
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $language_map = ['en' => 'en_US', 'tr' => 'tr_TR'];
    $wp_lang = isset($language_map[$browser_lang]) ? $language_map[$browser_lang] : '';

    if (in_array($wp_lang, $available_languages)) {
        switch_to_locale($wp_lang);
    }
}
add_action('init', 'switch_language');

// Add an admin menu with sub-pages
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

// Create the custom tables for storing extracted texts and translations
function create_text_translation_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table to store original extracted texts
    $table_name = $wpdb->prefix . 'extracted_texts';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        original_text text NOT NULL,
        source_language varchar(10) NOT NULL,
        translated_text text DEFAULT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Table to store translations
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';
    $sql = "CREATE TABLE $translation_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        extracted_text_id mediumint(9) NOT NULL,
        target_language varchar(10) NOT NULL,
        translated_text text NOT NULL,
        PRIMARY KEY  (id),
        FOREIGN KEY (extracted_text_id) REFERENCES $table_name(id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'create_text_translation_tables');

// Register DeepL API settings
function deepl_api_register_settings() {
    register_setting('deepl_api_settings_group', 'deepl_api_key');
    add_settings_section('deepl_api_settings_section', 'DeepL API Configuration', null, 'deepl-api-settings');
    add_settings_field('deepl_api_key', 'DeepL API Key', 'deepl_api_key_callback', 'deepl-api-settings', 'deepl_api_settings_section');
}
add_action('admin_init', 'deepl_api_register_settings');

function deepl_api_key_callback() {
    $deepl_api_key = get_option('deepl_api_key', '');
    echo '<input type="text" name="deepl_api_key" value="' . esc_attr($deepl_api_key) . '" size="40">';
}

// Function to capture and process the page content using output buffering
function start_language_switch_buffer() {
    ob_start('process_translations_in_buffer');
}
add_action('template_redirect', 'start_language_switch_buffer');

// Function to process the buffer and replace text with translations
function process_translations_in_buffer($content) {
    global $wpdb;

    // Get user's browser language
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

    // Get all extracted texts from the database
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $extracted_texts = $wpdb->get_results("SELECT id, original_text FROM $table_name");

    // Replace each original text with its translation, if available
    foreach ($extracted_texts as $text) {
        // Get the translated text for the user's browser language
        $translated_text = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d AND target_language = %s",
            $text->id, $browser_lang
        ));

        // If a translation is found, replace the original text in the page content
        if (!empty($translated_text)) {
            $content = str_replace($text->original_text, $translated_text, $content);
        }
    }

    // Return the modified content with translations
    return $content;
}

// Display extracted texts page with translations
function display_extracted_texts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';
    
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    // Handle button actions (extract texts, clear database, translate texts)
    if (isset($_POST['extract_texts'])) {
        extract_text_from_page();  // Extract text from homepage (or provide a specific page ID)
    }

    if (isset($_POST['clear_database'])) {
        clear_extracted_texts();
    }

    if (isset($_POST['translate_texts'])) {
        $source_lang = sanitize_text_field($_POST['source_lang']);
        $target_lang = sanitize_text_field($_POST['target_lang']);
        translate_and_display_texts($source_lang, $target_lang, $results);
    }

    echo '<div class="wrap">';
    echo '<h1>Extracted Texts</h1>';

    // Add buttons to manually extract text, clear database, and translate texts
    echo '<form method="post">';
    submit_button('Extract Texts from Homepage', 'primary', 'extract_texts', false);
    submit_button('Clear Database', 'secondary', 'clear_database', false);

    // Translation section with language selection
    echo '<h2>Translate Extracted Texts</h2>';
    echo '<label for="source_lang">Source Language: </label>';
    echo '<select name="source_lang" id="source_lang">';
    $source_languages = deepl_get_language_codes();
    foreach ($source_languages as $code => $name) {
        echo "<option value='$code'>$name</option>";
    }
    echo '</select>';

    echo '<label for="target_lang">Target Language: </label>';
    echo '<select name="target_lang" id="target_lang">';
    $target_languages = deepl_get_language_codes();
    foreach ($target_languages as $code => $name) {
        echo "<option value='$code'>$name</option>";
    }
    echo '</select>';

    submit_button('Translate Texts', 'primary', 'translate_texts');
    echo '</form>';

    // Display extracted texts in a table, including translations
    echo '<table class="widefat">';
    echo '<thead><tr><th>ID</th><th>Text</th><th>Source Language</th><th>Translated Text</th></tr></thead>';
    echo '<tbody>';

    if (!empty($results)) {
        foreach ($results as $row) {
            // Fetch the translation for this text (if available)
            $translated_text = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d",
                $row->id
            ));

            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->original_text) . '</td>';
            echo '<td>' . esc_html($row->source_language) . '</td>';
            echo '<td>' . esc_html($translated_text ?? '') . '</td>'; // Display translated text if available
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No extracted texts found.</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}


// Function to clear the extracted texts and translations from the database
function clear_extracted_texts() {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}extracted_texts");
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}extracted_text_translations");

    echo '<div class="updated"><p>The database has been cleared.</p></div>';
}

// Function to translate extracted texts using DeepL and store them in the translation table
function translate_and_display_texts($source_lang, $target_lang, $results) {
    global $wpdb;
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    foreach ($results as $row) {
        // Translate the text using DeepL
        $translated_text = deepl_translate_text($row->original_text, $target_lang, $source_lang);

        if (!is_wp_error($translated_text)) {
            // Store the translation in the translations table
            $wpdb->replace(
                $translation_table_name,
                [
                    'extracted_text_id' => $row->id,
                    'target_language'   => $target_lang,
                    'translated_text'   => $translated_text,
                ]
            );
        }
    }

    echo '<div class="updated"><p>All texts have been translated and stored in the database.</p></div>';
}

// Function to extract texts from a specific page (e.g., homepage) and add them to the database
function extract_text_from_page($page_id = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';

    if (is_null($page_id)) {
        // Default to homepage if no page_id is provided
        $page_id = get_option('page_on_front');
    }

    // Get the content of the page
    $page_content = get_post_field('post_content', $page_id);

    // Remove inline <style> and <script> tags and their content
    $page_content = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $page_content);
    $page_content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $page_content);

    // Use regex to extract text from within HTML tags
    preg_match_all('/>([^<>]+)</', $page_content, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $extracted_text) {
            // Sanitize and clean up extracted text
            $extracted_text = trim(strip_tags($extracted_text));

            // Skip empty strings and texts that are only numbers/punctuation/symbols
            if (empty($extracted_text) || preg_match('/^[\W\d]+$/', $extracted_text)) {
                continue;
            }

            // Check if this text already exists in the database to avoid duplicates
            $existing_text = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE original_text = %s", $extracted_text));

            if (!$existing_text) {
                // Insert the extracted text into the database
                $wpdb->insert(
                    $table_name,
                    [
                        'original_text' => $extracted_text,
                        'source_language' => get_locale(), // Use WordPress' default language as the source
                    ]
                );
            }
        }
    }

    echo '<div class="updated"><p>Text extraction completed. Check the Extracted Texts page.</p></div>';
}



