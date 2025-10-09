<?php
/**
 * Plugin Name: Switch Language
 * Description: Automatically switches the WordPress site language based on the user's browser language setting
 * Version: 1.3.1
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

    // Handle custom translation save
    if (isset($_POST['save_custom_translation'])) {
        sl_save_custom_translation();
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
    if ($source_languages) {
        foreach ($source_languages as $code => $name) {
            echo "<option value='$code'>$name</option>";
        }
    }
    echo '</select>';

    echo '<label for="target_lang">Target Language: </label>';
    echo '<select name="target_lang" id="target_lang">';
    $target_languages = sl_deepl_get_language_codes();
    if ($target_languages) {
        foreach ($target_languages as $code => $name) {
            echo "<option value='$code'>$name</option>";
        }
    }
    echo '</select>';

    submit_button('Translate Texts', 'primary', 'translate_texts');
    echo '</form>';

    // Display extracted texts in a table with custom translation inputs
    echo '<h2>Extracted Texts and Custom Translations</h2>';
    echo '<p>Enter custom translations below. You can add translations for multiple languages per text.</p>';
    echo '<table class="widefat">';
    echo '<thead><tr><th>ID</th><th>Original Text</th><th>Source Language</th><th>Target Language</th><th>Translation</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    if (!empty($results)) {
        foreach ($results as $row) {
            // Fetch all translations for this text
            $translations = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $translation_table_name WHERE extracted_text_id = %d",
                $row->id
            ));

            // Display existing translations
            if (!empty($translations)) {
                foreach ($translations as $translation) {
                    echo '<tr>';
                    echo '<td>' . esc_html($row->id) . '</td>';
                    echo '<td>' . esc_html($row->original_text) . '</td>';
                    echo '<td>' . esc_html($row->source_language) . '</td>';
                    echo '<td>' . esc_html($translation->target_language) . '</td>';
                    echo '<td>';
                    echo '<form method="post" style="margin:0;">';
                    echo '<input type="hidden" name="extracted_text_id" value="' . esc_attr($row->id) . '">';
                    echo '<input type="hidden" name="translation_id" value="' . esc_attr($translation->id) . '">';
                    echo '<input type="hidden" name="target_language" value="' . esc_attr($translation->target_language) . '">';
                    echo '<input type="text" name="translated_text" value="' . esc_attr($translation->translated_text) . '" size="50">';
                    echo '</td>';
                    echo '<td>';
                    submit_button('Update', 'small', 'save_custom_translation', false);
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
            }

            // Add a row for adding a new translation
            echo '<tr style="background-color: #f9f9f9;">';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->original_text) . '</td>';
            echo '<td>' . esc_html($row->source_language) . '</td>';
            echo '<td>';
            echo '<form method="post" style="margin:0;">';
            echo '<input type="hidden" name="extracted_text_id" value="' . esc_attr($row->id) . '">';
            echo '<select name="target_language">';
            $available_languages = sl_deepl_get_language_codes();
            if ($available_languages) {
                foreach ($available_languages as $code => $name) {
                    echo "<option value='$code'>$name</option>";
                }
            } else {
                // Fallback if DeepL API is not available
                echo '<option value="TR">Turkish</option>';
                echo '<option value="EN">English</option>';
                echo '<option value="DE">German</option>';
                echo '<option value="FR">French</option>';
                echo '<option value="ES">Spanish</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '<td>';
            echo '<input type="text" name="translated_text" placeholder="Enter custom translation" size="50">';
            echo '</td>';
            echo '<td>';
            submit_button('Add New', 'small', 'save_custom_translation', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No extracted texts found. Click "Extract Texts from All Pages" to get started.</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

// Function to save custom translations entered by admin
function sl_save_custom_translation() {
    global $wpdb;
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $extracted_text_id = intval($_POST['extracted_text_id']);
    $target_language = sanitize_text_field($_POST['target_language']);
    $translated_text = sanitize_textarea_field($_POST['translated_text']);

    // Check if we're updating an existing translation
    if (isset($_POST['translation_id']) && !empty($_POST['translation_id'])) {
        $translation_id = intval($_POST['translation_id']);

        // Update existing translation
        $wpdb->update(
            $translation_table_name,
            ['translated_text' => $translated_text],
            ['id' => $translation_id],
            ['%s'],
            ['%d']
        );

        echo '<div class="updated"><p>Translation updated successfully.</p></div>';
    } else {
        // Check if translation already exists for this text and language
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $translation_table_name WHERE extracted_text_id = %d AND target_language = %s",
            $extracted_text_id,
            $target_language
        ));

        if ($existing) {
            // Update existing
            $wpdb->update(
                $translation_table_name,
                ['translated_text' => $translated_text],
                [
                    'extracted_text_id' => $extracted_text_id,
                    'target_language' => $target_language
                ],
                ['%s'],
                ['%d', '%s']
            );
            echo '<div class="updated"><p>Translation updated successfully.</p></div>';
        } else {
            // Insert new translation
            $wpdb->insert(
                $translation_table_name,
                [
                    'extracted_text_id' => $extracted_text_id,
                    'target_language' => $target_language,
                    'translated_text' => $translated_text,
                ]
            );
            echo '<div class="updated"><p>Custom translation added successfully.</p></div>';
        }
    }
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
