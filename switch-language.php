<?php
/**
 * Plugin Name: Switch Language
 * Description: Automatically switches the WordPress site language based on the user's browser language setting
 * Version: 1.3.0
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
function sl_switch_language() {
    if (is_admin() || !isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    $available_languages = get_available_languages();
    $available_languages[] = 'en_US'; // Include English as it's always available

    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $language_map = ['en' => 'en_US', 'tr' => 'tr_TR'];
    $wp_lang = isset($language_map[$browser_lang]) ? $language_map[$browser_lang] : '';

    if ($wp_lang && in_array($wp_lang, $available_languages)) {
        switch_to_locale($wp_lang);

        // Also set the locale for the session
        add_filter('locale', function($locale) use ($wp_lang) {
            return $wp_lang;
        });
    }
}
add_action('plugins_loaded', 'sl_switch_language', 1);

// Add an admin menu with sub-pages
function sl_add_switch_language_admin_menu() {
    add_menu_page(
        'Switch Language',
        'Switch Language',
        'manage_options',
        'switch-language',
        'sl_switch_language_settings_page',
        'dashicons-translation'
    );

    add_submenu_page(
        'switch-language',
        'Extracted Texts',
        'Extracted Texts',
        'manage_options',
        'extracted-texts',
        'sl_display_extracted_texts'
    );

    add_submenu_page(
        'switch-language',
        'DeepL API Settings',
        'DeepL API Settings',
        'manage_options',
        'sl-deepl-api-settings',
        'sl_deepl_api_settings_page'
    );
}
add_action('admin_menu', 'sl_add_switch_language_admin_menu');

// Settings page for the main Switch Language menu
function sl_switch_language_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>Switch Language Settings</h1>';
    echo '<p>This plugin automatically switches the WordPress site language based on the user\'s browser language setting.</p>';
    echo '<h2>Current Configuration</h2>';
    echo '<ul>';
    echo '<li><strong>Enabled Languages:</strong> ' . implode(', ', get_available_languages()) . '</li>';
    echo '<li><strong>Default Language:</strong> ' . get_locale() . '</li>';
    echo '</ul>';
    echo '<h2>How It Works</h2>';
    echo '<ol>';
    echo '<li>The plugin detects the user\'s browser language preference</li>';
    echo '<li>If a matching language is installed in WordPress, the site content is switched to that language</li>';
    echo '<li>Custom translations from the "Extracted Texts" feature are also applied when available</li>';
    echo '</ol>';
    echo '<p><strong>Note:</strong> Make sure the language files for your desired languages are installed in WordPress (Settings > General > Site Language).</p>';
    echo '</div>';
}

// Create the custom tables for storing extracted texts and translations
function sl_create_text_translation_tables() {
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
register_activation_hook(__FILE__, 'sl_create_text_translation_tables');

// Register DeepL API settings
function sl_deepl_api_register_settings() {
    register_setting('sl_deepl_api_settings_group', 'sl_deepl_api_key');
    add_settings_section('sl_deepl_api_settings_section', 'DeepL API Configuration', null, 'sl-deepl-api-settings');
    add_settings_field('sl_deepl_api_key', 'DeepL API Key', 'sl_deepl_api_key_callback', 'sl-deepl-api-settings', 'sl_deepl_api_settings_section');
}
add_action('admin_init', 'sl_deepl_api_register_settings');

function sl_deepl_api_key_callback() {
    $deepl_api_key = get_option('sl_deepl_api_key', '');
    echo '<input type="text" name="sl_deepl_api_key" value="' . esc_attr($deepl_api_key) . '" size="40">';
}

// DeepL API Settings page
function sl_deepl_api_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>DeepL API Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('sl_deepl_api_settings_group');
    do_settings_sections('sl-deepl-api-settings');
    submit_button();
    echo '</form>';
    echo '<hr>';
    echo '<h2>Test Your API</h2>';
    echo '<p>Use the shortcode <code>[sl_test_deepl_api]</code> on any page or post to test your DeepL API connection.</p>';
    echo '</div>';
}

// Function to capture and process the page content using output buffering
function sl_start_language_switch_buffer() {
    ob_start('sl_process_translations_in_buffer');
}
add_action('template_redirect', 'sl_start_language_switch_buffer');

// Function to process the buffer and replace text with translations, allowing for partial language matches (e.g., 'en' matches 'en_US' or 'en_GB')
function sl_process_translations_in_buffer($content) {
    global $wpdb;

    // Get user's browser language (2-letter code)
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

    // Log the detected browser language
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Detected browser language: " . $browser_lang);
    }

    // Get all extracted texts from the database
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $extracted_texts = $wpdb->get_results("SELECT id, original_text FROM $table_name");

    // Sort the extracted texts by length, longest first
    usort($extracted_texts, function($a, $b) {
        return strlen($b->original_text) - strlen($a->original_text);
    });

    // Replace each original text with its translation, if available
    foreach ($extracted_texts as $text) {
        // Query for translations where the first two characters of the target language match the browser language
        $translated_text = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d AND LOWER(SUBSTRING(target_language, 1, 2)) = %s",
            $text->id, strtolower($browser_lang)
        ));

        // Log whether a translation was found and the matched language
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (!empty($translated_text)) {
                error_log("Translation found for text ID " . $text->id . " for browser language " . $browser_lang);
            } else {
                error_log("No translation found for text ID " . $text->id . " for browser language " . $browser_lang);
            }
        }

        // If a translation is found, replace the original text in the page content
        if (!empty($translated_text)) {
            $content = str_replace($text->original_text, $translated_text, $content);
        }
    }

    // Return the modified content with translations
    return $content;
}

// Display extracted texts page with translations
function sl_display_extracted_texts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $results = $wpdb->get_results("SELECT * FROM $table_name");

    // Handle button actions (extract texts, clear database, translate texts)
    if (isset($_POST['extract_texts'])) {
        sl_extract_text_from_all_pages();
    }

    if (isset($_POST['clear_database'])) {
        sl_clear_extracted_texts();
    }

    if (isset($_POST['translate_texts'])) {
        $source_lang = sanitize_text_field($_POST['source_lang']);
        $target_lang = sanitize_text_field($_POST['target_lang']);
        sl_translate_and_display_texts($source_lang, $target_lang, $results);
    }

    echo '<div class="wrap">';
    echo '<h1>Extracted Texts</h1>';

    // Add buttons to manually extract text, clear database, and translate texts
    echo '<form method="post">';
    submit_button('Extract Texts from All Pages', 'primary', 'extract_texts', false);
    submit_button('Clear Database', 'secondary', 'clear_database', false);

    // Translation section with language selection
    echo '<h2>Translate Extracted Texts</h2>';
    echo '<label for="source_lang">Source Language: </label>';
    echo '<select name="source_lang" id="source_lang">';
    $source_languages = sl_deepl_get_language_codes();
    foreach ($source_languages as $code => $name) {
        echo "<option value='$code'>$name</option>";
    }
    echo '</select>';

    echo '<label for="target_lang">Target Language: </label>';
    echo '<select name="target_lang" id="target_lang">';
    $target_languages = sl_deepl_get_language_codes();
    foreach ($target_languages as $code => $name) {
        echo "<option value='$code'>$name</option>";
    }
    echo '</select>';

    submit_button('Translate Texts', 'primary', 'translate_texts');
    echo '</form>';

    // Display extracted texts in a table
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
            echo '<td>' . esc_html($translated_text ?? 'No translation available') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No extracted texts found.</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

// Function to clear the extracted texts and translations from the database
function sl_clear_extracted_texts() {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}extracted_texts");
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}extracted_text_translations");

    echo '<div class="updated"><p>The database has been cleared.</p></div>';
}

// Function to translate extracted texts using DeepL and display in admin page
function sl_translate_and_display_texts($source_lang, $target_lang, $results) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    foreach ($results as $row) {
        // Check if this text already has a translation in the target language
        $existing_translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d AND target_language = %s",
            $row->id,
            $target_lang
        ));

        // If a translation already exists, skip translating it again
        if (!empty($existing_translation)) {
            continue;
        }

        // Translate the text using DeepL
        $translated_text = sl_deepl_translate_text($row->original_text, $target_lang, $source_lang);

        if (!is_wp_error($translated_text) && !empty($translated_text)) {
            // Insert the new translation into the database
            $wpdb->insert(
                $translation_table_name,
                [
                    'extracted_text_id' => $row->id,
                    'translated_text'   => $translated_text,
                    'target_language'   => $target_lang,
                ]
            );
        }
    }

    echo '<div class="updated"><p>All texts have been translated and displayed below.</p></div>';
}

// Function to extract text from all published pages, posts, and WooCommerce products
function sl_extract_text_from_all_pages() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';

    // Query all published pages, posts, and WooCommerce products
    $args = [
        'post_type' => ['page', 'post', 'product'],
        'post_status' => 'publish',
        'posts_per_page' => -1
    ];
    $pages = get_posts($args);

    foreach ($pages as $page) {
        // Check if this post is a WooCommerce product
        if ($page->post_type === 'product') {
            // Get the permalink (URL) for the product page
            $product_url = get_permalink($page->ID);

            // Fetch the front-end HTML of the product page
            $response = wp_remote_get($product_url);
            if (is_wp_error($response)) {
                continue;
            }

            $html = wp_remote_retrieve_body($response);

            // Use regex to extract all text within HTML tags, excluding script and style content
            $html = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);
            $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);

            // Use regex to extract text from within HTML tags
            preg_match_all('/>([^<>]+)</', $html, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $extracted_text) {
                    // Sanitize and clean up extracted text
                    $extracted_text = trim(strip_tags($extracted_text));

                    // Skip empty strings and texts that are only numbers/punctuation/symbols
                    if (empty($extracted_text) || preg_match('/^[\W\d]+$/', $extracted_text)) {
                        continue;
                    }

                    // Check if this text already exists in the database to avoid duplicates
                    $existing_text = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name WHERE BINARY original_text = %s",
                        $extracted_text
                    ));

                    if (!$existing_text && !empty($extracted_text)) {
                        // Insert the unique extracted text into the database
                        $wpdb->insert(
                            $table_name,
                            [
                                'original_text' => $extracted_text,
                                'source_language' => get_locale(),
                            ]
                        );
                    }
                }
            }
        } else {
            // Handle regular posts and pages (non-WooCommerce products)
            // Initialize content to extract from this post
            $content_to_extract = '';

            // Get the post/page title and content
            $page_title = $page->post_title;
            $page_content = $page->post_content;

            // Combine title and content for text extraction
            $content_to_extract .= $page_title . "\n" . $page_content;

            // Remove inline <style> and <script> tags and their content
            $content_to_extract = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $content_to_extract);
            $content_to_extract = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content_to_extract);

            // Use regex to extract text from within HTML tags
            preg_match_all('/>([^<>]+)</', $content_to_extract, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $extracted_text) {
                    // Sanitize and clean up extracted text
                    $extracted_text = trim(strip_tags($extracted_text));

                    // Skip empty strings and texts that are only numbers/punctuation/symbols
                    if (empty($extracted_text) || preg_match('/^[\W\d]+$/', $extracted_text)) {
                        continue;
                    }

                    // Check if this text already exists in the database to avoid duplicates
                    $existing_text = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name WHERE original_text = %s",
                        $extracted_text
                    ));

                    if (!$existing_text && !empty($extracted_text)) {
                        // Insert the unique extracted text into the database
                        $wpdb->insert(
                            $table_name,
                            [
                                'original_text' => $extracted_text,
                                'source_language' => get_locale(),
                            ]
                        );
                    }
                }
            }
        }
    }

    echo '<div class="updated"><p>Text extraction from all pages, posts, and products completed. Check the Extracted Texts page.</p></div>';
}
