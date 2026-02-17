<?php
/**
 * Plugin Name: Switch Language
 * Description: Automatically switches the WordPress site language based on the user's browser language setting
 * Version: 1.3.6
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

function sl_get_available_locales() {
    $locales = get_available_languages();
    $locales[] = 'en_US';
    $locales = array_values(array_unique($locales));
    sort($locales, SORT_STRING);
    return $locales;
}

function sl_get_locale_label($locale) {
    if (function_exists('locale_get_display_name')) {
        $label = locale_get_display_name($locale, $locale);
        if (!empty($label)) {
            return $label;
        }
    }

    return $locale;
}

function sl_set_manual_locale_cookie($locale) {
    $cookie_name = 'sl_manual_locale';
    $secure = is_ssl();
    $http_only = true;

    if (empty($locale)) {
        setcookie($cookie_name, '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, $secure, $http_only);
        unset($_COOKIE[$cookie_name]);
        return;
    }

    setcookie($cookie_name, $locale, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, $secure, $http_only);
    $_COOKIE[$cookie_name] = $locale;
}

function sl_get_manual_locale() {
    if (empty($_COOKIE['sl_manual_locale'])) {
        return '';
    }

    $locale = sanitize_text_field(wp_unslash($_COOKIE['sl_manual_locale']));
    if ($locale === '') {
        return '';
    }

    $available_locales = sl_get_available_locales();
    return in_array($locale, $available_locales, true) ? $locale : '';
}

function sl_apply_locale($locale) {
    if (empty($locale)) {
        return;
    }

    switch_to_locale($locale);
    add_filter('locale', function() use ($locale) {
        return $locale;
    });
}

function sl_get_preferred_language_code() {
    $manual_locale = sl_get_manual_locale();
    $locale = $manual_locale ?: get_locale();
    if (!empty($locale)) {
        $normalized = str_replace('-', '_', $locale);
        $base = strtok($normalized, '_');
        if (!empty($base)) {
            return strtolower($base);
        }
    }

    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
    }

    return '';
}

function sl_handle_language_switcher_request() {
    if (is_admin() || empty($_POST['sl_language_switcher'])) {
        return;
    }

    if (empty($_POST['sl_language_switcher_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sl_language_switcher_nonce'])), 'sl_language_switcher')) {
        return;
    }

    $requested_locale = '';
    if (isset($_POST['sl_locale'])) {
        $requested_locale = sanitize_text_field(wp_unslash($_POST['sl_locale']));
    }

    if ($requested_locale === 'auto' || $requested_locale === '') {
        sl_set_manual_locale_cookie('');
    } else {
        $available_locales = sl_get_available_locales();
        if (in_array($requested_locale, $available_locales, true)) {
            sl_set_manual_locale_cookie($requested_locale);
        }
    }

    $redirect = '';
    if (!empty($_POST['sl_redirect'])) {
        $redirect = esc_url_raw(wp_unslash($_POST['sl_redirect']));
    }
    if (empty($redirect)) {
        $redirect = wp_get_referer();
    }
    if (empty($redirect)) {
        $redirect = home_url('/');
    }

    wp_safe_redirect($redirect);
    exit;
}
add_action('init', 'sl_handle_language_switcher_request');

function sl_language_switcher_shortcode($atts) {
    $atts = shortcode_atts([
        'separator' => ' | ',
    ], $atts, 'sl_language_switcher');

    $available_locales = sl_get_available_locales();
    $locale_map = [];
    foreach ($available_locales as $locale) {
        $normalized = str_replace('-', '_', $locale);
        $code = strtoupper(strtok($normalized, '_'));
        if ($code === '') {
            continue;
        }
        if (!isset($locale_map[$code])) {
            $locale_map[$code] = $locale;
        }
    }

    if (empty($locale_map)) {
        return '';
    }

    $current_locale = sl_get_manual_locale();
    if (empty($current_locale)) {
        $current_locale = get_locale();
    }
    $current_code = '';
    if (!empty($current_locale)) {
        $normalized = str_replace('-', '_', $current_locale);
        $current_code = strtoupper(strtok($normalized, '_'));
    }
    if ($current_code === '' || !isset($locale_map[$current_code])) {
        reset($locale_map);
        $current_code = key($locale_map);
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $action_url_raw = $request_uri !== '' ? esc_url_raw($request_uri) : esc_url_raw(home_url('/'));
    $action_url = esc_url($action_url_raw);
    $button_style = 'background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;';

    $output = '<form method="post" class="sl-language-switcher" action="' . $action_url . '" style="display:inline;">';
    $output .= '<input type="hidden" name="sl_language_switcher" value="1">';
    $output .= '<input type="hidden" name="sl_redirect" value="' . esc_attr($action_url_raw) . '">';
    $output .= wp_nonce_field('sl_language_switcher', 'sl_language_switcher_nonce', true, false);

    $codes = array_keys($locale_map);
    if ($current_code !== '' && in_array($current_code, $codes, true)) {
        $codes = array_values(array_unique(array_merge([$current_code], $codes)));
    }
    $count = count($codes);
    foreach ($codes as $index => $code) {
        $locale = $locale_map[$code];
        $is_active = $code === $current_code;
        $label = $is_active ? '<strong>' . esc_html($code) . '</strong>' : esc_html($code);
        $output .= '<button type="submit" name="sl_locale" value="' . esc_attr($locale) . '" style="' . esc_attr($button_style) . '" class="sl-language-switcher__link"' . ($is_active ? ' aria-current="true"' : '') . '>' . $label . '</button>';
        if ($index < $count - 1) {
            $output .= '<span class="sl-language-switcher__separator">' . esc_html($atts['separator']) . '</span>';
        }
    }

    $output .= '</form>';

    return $output;
}
add_shortcode('sl_language_switcher', 'sl_language_switcher_shortcode');

// Switch the site language based on the user's browser language
function sl_switch_language() {
    if (is_admin()) {
        return;
    }

    $available_languages = sl_get_available_locales();
    $manual_locale = sl_get_manual_locale();
    if (!empty($manual_locale) && in_array($manual_locale, $available_languages, true)) {
        sl_apply_locale($manual_locale);
        return;
    }

    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $language_map = [
        'en' => 'en_US',
        'tr' => 'tr_TR',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'es' => 'es_ES',
        'ja' => 'ja',
    ];
    $wp_lang = isset($language_map[$browser_lang]) ? $language_map[$browser_lang] : '';

    if ($wp_lang && in_array($wp_lang, $available_languages)) {
        sl_apply_locale($wp_lang);
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

function sl_render_deepl_api_test_result($test_result) {
    if (!is_array($test_result)) {
        return;
    }

    $is_ok = !empty($test_result['ok']);
    $class = $is_ok ? 'notice notice-success' : 'notice notice-error';
    $messages = isset($test_result['messages']) && is_array($test_result['messages']) ? $test_result['messages'] : [];
    $details = isset($test_result['details']) && is_array($test_result['details']) ? $test_result['details'] : [];

    echo '<div class="' . esc_attr($class) . '" style="margin-top:12px;"><p>';
    if (empty($messages)) {
        echo $is_ok ? 'DeepL test passed.' : 'DeepL test failed.';
    } else {
        echo esc_html(implode(' ', array_map('strval', $messages)));
    }
    echo '</p>';

    if (!empty($details)) {
        echo '<ul style="margin:0 0 8px 18px;list-style:disc;">';
        if (!empty($details['api_base_url'])) {
            echo '<li><strong>Endpoint:</strong> ' . esc_html($details['api_base_url']) . '</li>';
        }
        if (!empty($details['key_suffix'])) {
            echo '<li><strong>Key suffix:</strong> ' . esc_html($details['key_suffix']) . '</li>';
        }
        if (isset($details['target_language_count'])) {
            echo '<li><strong>Target languages returned:</strong> ' . esc_html((string) $details['target_language_count']) . '</li>';
        }
        if (!empty($details['sample_translation'])) {
            echo '<li><strong>Sample translation:</strong> ' . esc_html($details['sample_translation']) . '</li>';
        }
        echo '</ul>';
    }

    echo '</div>';
}

// DeepL API Settings page
function sl_deepl_api_settings_page() {
    $test_result = null;
    if (isset($_POST['sl_run_deepl_api_test'])) {
        if (!current_user_can('manage_options')) {
            $test_result = [
                'ok' => false,
                'messages' => ['You are not allowed to run this test.'],
            ];
        } elseif (
            empty($_POST['sl_deepl_api_test_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sl_deepl_api_test_nonce'])), 'sl_deepl_api_test')
        ) {
            $test_result = [
                'ok' => false,
                'messages' => ['Security check failed. Please refresh and try again.'],
            ];
        } else {
            $test_result = sl_test_deepl_api_connection();
        }
    }

    echo '<div class="wrap">';
    echo '<h1>DeepL API Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('sl_deepl_api_settings_group');
    do_settings_sections('sl-deepl-api-settings');
    submit_button();
    echo '</form>';
    echo '<hr>';
    echo '<h2>Test Your API Connection</h2>';
    echo '<p>Run a live test to validate your API key, endpoint, language list request, and a sample translation.</p>';
    echo '<form method="post">';
    echo wp_nonce_field('sl_deepl_api_test', 'sl_deepl_api_test_nonce', true, false);
    submit_button('Run API Test', 'secondary', 'sl_run_deepl_api_test', false);
    echo '</form>';
    sl_render_deepl_api_test_result($test_result);
    echo '<p style="margin-top:12px;">You can also use the shortcode <code>[sl_test_deepl_api]</code> on a page for a frontend check.</p>';
    echo '</div>';
}

// Function to capture and process the page content using output buffering
function sl_start_language_switch_buffer() {
    ob_start('sl_process_translations_in_buffer');
}
add_action('template_redirect', 'sl_start_language_switch_buffer');

// Shortcode helpers to avoid translating or breaking them.
function sl_get_shortcode_regex_pattern() {
    if (function_exists('get_shortcode_regex')) {
        global $shortcode_tags;
        if (!empty($shortcode_tags)) {
            $shortcode_regex = get_shortcode_regex();
            if (!empty($shortcode_regex)) {
                return '~' . $shortcode_regex . '~s';
            }
        }
    }

    return '/\\[[a-zA-Z][\\w-]*(?:\\s[^\\]]*)?\\]/';
}

function sl_extract_shortcodes($text) {
    $pattern = sl_get_shortcode_regex_pattern();
    if (strpos($text, '[') === false || !preg_match($pattern, $text)) {
        return [];
    }

    preg_match_all($pattern, $text, $matches);
    return $matches[0] ?? [];
}

function sl_is_text_only_shortcodes($text) {
    $pattern = sl_get_shortcode_regex_pattern();
    if (!preg_match($pattern, $text)) {
        return false;
    }

    $without_shortcodes = preg_replace($pattern, '', $text);
    return trim($without_shortcodes) === '';
}

function sl_mask_shortcodes($text, &$shortcode_map, &$shortcode_reverse_map = null) {
    $shortcode_map = [];
    if ($shortcode_reverse_map !== null) {
        $shortcode_reverse_map = [];
    }

    $pattern = sl_get_shortcode_regex_pattern();
    if (strpos($text, '[') === false || !preg_match($pattern, $text)) {
        return $text;
    }

    $prefix = '__SL_SC_' . substr(md5($text . microtime()), 0, 8) . '_';
    $index = 0;

    return preg_replace_callback($pattern, function($matches) use (&$shortcode_map, &$shortcode_reverse_map, &$index, $prefix) {
        $shortcode = $matches[0];
        if (is_array($shortcode_reverse_map) && isset($shortcode_reverse_map[$shortcode])) {
            return $shortcode_reverse_map[$shortcode];
        }

        $placeholder = $prefix . $index . '__';
        $shortcode_map[$placeholder] = $shortcode;
        if ($shortcode_reverse_map !== null) {
            $shortcode_reverse_map[$shortcode] = $placeholder;
        }
        $index++;

        return $placeholder;
    }, $text);
}

function sl_restore_shortcodes($text, $shortcode_map) {
    if (empty($shortcode_map)) {
        return $text;
    }

    return strtr($text, $shortcode_map);
}

function sl_translation_preserves_shortcodes($original_text, $translated_text) {
    $shortcodes = sl_extract_shortcodes($original_text);
    if (empty($shortcodes)) {
        return true;
    }

    $shortcode_counts = array_count_values($shortcodes);
    foreach ($shortcode_counts as $shortcode => $count) {
        if (substr_count($translated_text, $shortcode) < $count) {
            return false;
        }
    }

    return true;
}

function sl_get_available_language_options() {
    $languages = sl_deepl_get_language_codes('target');
    if (!is_array($languages) || empty($languages)) {
        return [
            'TR' => 'Turkish',
            'EN' => 'English',
            'DE' => 'German',
            'FR' => 'French',
            'ES' => 'Spanish',
        ];
    }

    return $languages;
}

function sl_get_available_source_language_options() {
    $languages = sl_deepl_get_language_codes('source');
    if (!is_array($languages) || empty($languages)) {
        return [
            'EN' => 'English',
            'DE' => 'German',
            'FR' => 'French',
            'ES' => 'Spanish',
            'TR' => 'Turkish',
        ];
    }

    return $languages;
}

function sl_normalize_target_languages($target_languages, $available_languages) {
    if (!is_array($target_languages)) {
        $target_languages = array_filter(array_map('trim', explode(',', (string) $target_languages)));
    }

    $available_codes = array_keys($available_languages);
    $target_languages = array_values(array_unique(array_intersect($target_languages, $available_codes)));

    return $target_languages;
}

function sl_normalize_source_language($source_lang, $available_languages) {
    $source_lang = trim((string) $source_lang);
    if ($source_lang === '') {
        return '';
    }

    $available_map = [];
    foreach (array_keys($available_languages) as $code) {
        $available_map[strtoupper($code)] = $code;
    }

    $upper_source = strtoupper($source_lang);
    if (isset($available_map[$upper_source])) {
        return $available_map[$upper_source];
    }

    $base_code = strtoupper(strtok($source_lang, '-'));
    if (isset($available_map[$base_code])) {
        return $available_map[$base_code];
    }

    return '';
}

// Split HTML into tokens and flag text segments safe for replacement (skip tags and script/style blocks).
function sl_split_html_for_translation($content) {
    if (function_exists('wp_html_split')) {
        $tokens = wp_html_split($content);
    } else {
        $tokens = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    if (!is_array($tokens) || empty($tokens)) {
        return [[$content], [true]];
    }

    $replaceable = [];
    $skip = false;
    foreach ($tokens as $token) {
        if ($token === '') {
            $replaceable[] = !$skip;
            continue;
        }

        if ($token[0] === '<') {
            if (preg_match('/^<\s*(script|style)\b/i', $token)) {
                $skip = true;
            } elseif (preg_match('/^<\s*\/\s*(script|style)\b/i', $token)) {
                $skip = false;
            }
            $replaceable[] = false;
            continue;
        }

        $replaceable[] = !$skip;
    }

    return [$tokens, $replaceable];
}

function sl_replace_text_in_tokens(array $tokens, array $replaceable, $search_text, $replacement_text) {
    if ($search_text === '' || $search_text === $replacement_text) {
        return $tokens;
    }

    foreach ($tokens as $index => $token) {
        if (!empty($replaceable[$index])) {
            $tokens[$index] = str_replace($search_text, $replacement_text, $token);
        }
    }

    return $tokens;
}

function sl_replace_text_in_tokens_with_overlaps(array $tokens, array $replaceable, array $replacements) {
    if (empty($replacements)) {
        return $tokens;
    }

    foreach ($tokens as $index => $token) {
        if (!empty($replaceable[$index])) {
            $tokens[$index] = sl_replace_text_with_best_matches($token, $replacements);
        }
    }

    return $tokens;
}

function sl_replace_text_with_best_matches($text, array $replacements) {
    if ($text === '' || empty($replacements)) {
        return $text;
    }

    $matches = sl_collect_replacement_matches($text, $replacements);
    if (empty($matches)) {
        $excerpt_replacement = sl_find_excerpt_replacement($text, $replacements);
        return $excerpt_replacement !== '' ? $excerpt_replacement : $text;
    }

    $selected_matches = sl_select_best_replacement_matches($matches);
    if (empty($selected_matches)) {
        return $text;
    }

    $output = '';
    $cursor = 0;
    $text_length = strlen($text);
    foreach ($selected_matches as $match) {
        if ($match['start'] > $cursor) {
            $output .= substr($text, $cursor, $match['start'] - $cursor);
        }
        $output .= $match['replace'];
        $cursor = $match['end'];
    }
    if ($cursor < $text_length) {
        $output .= substr($text, $cursor);
    }

    return $output;
}

function sl_text_length($text) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    return strlen($text);
}

function sl_text_substr($text, $start, $length = null) {
    if (function_exists('mb_substr')) {
        if ($length === null) {
            return mb_substr($text, $start, null, 'UTF-8');
        }
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    if ($length === null) {
        return substr($text, $start);
    }

    return substr($text, $start, $length);
}

function sl_get_sentence_boundary_positions($text) {
    $boundaries = [];
    if ($text === '') {
        return $boundaries;
    }

    if (!preg_match_all('/[.!?…。！？]+(?:["\')\]\x{00BB}\x{2019}\x{201D}]*)\s*/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        return $boundaries;
    }

    foreach ($matches[0] as $match) {
        $byte_end = $match[1] + strlen($match[0]);
        if ($byte_end <= 0) {
            continue;
        }

        if (function_exists('mb_strlen')) {
            $boundaries[] = mb_strlen(substr($text, 0, $byte_end), 'UTF-8');
        } else {
            $boundaries[] = $byte_end;
        }
    }

    return array_values(array_unique($boundaries));
}

function sl_text_ends_with_sentence_punctuation($text) {
    return preg_match('/[.!?…。！？]\s*$/u', trim($text)) === 1;
}

function sl_build_excerpt_from_translation($translated_text, $ratio) {
    $translated_text = trim($translated_text);
    if ($translated_text === '') {
        return '';
    }

    if ($ratio >= 0.98) {
        return $translated_text;
    }

    $translated_length = sl_text_length($translated_text);
    if ($translated_length <= 0) {
        return '';
    }

    $target_ratio = max(0.35, min(0.97, (float) $ratio));
    $target_length = (int) floor($translated_length * $target_ratio);
    $target_length = max(12, min($translated_length - 1, $target_length));
    if ($target_length >= $translated_length) {
        return $translated_text;
    }

    $boundary = 0;
    $boundaries = sl_get_sentence_boundary_positions($translated_text);
    if (!empty($boundaries)) {
        $min_before = max(10, (int) floor($target_length * 0.65));
        foreach ($boundaries as $position) {
            if ($position <= $target_length && $position >= $min_before) {
                $boundary = $position;
            }
        }

        if ($boundary === 0) {
            $max_after = min($translated_length, $target_length + max(10, (int) floor($target_length * 0.35)));
            foreach ($boundaries as $position) {
                if ($position > $target_length && $position <= $max_after) {
                    $boundary = $position;
                    break;
                }
            }
        }
    }

    if ($boundary > 0) {
        return trim(sl_text_substr($translated_text, 0, $boundary));
    }

    $excerpt = trim(sl_text_substr($translated_text, 0, $target_length));
    if (preg_match('/^(.+)\s+\S*$/u', $excerpt, $match)) {
        $word_safe = trim($match[1]);
        if ($word_safe !== '' && sl_text_length($word_safe) >= (int) floor($target_length * 0.6)) {
            $excerpt = $word_safe;
        }
    }

    return $excerpt;
}

function sl_normalize_excerpt_text($text, &$ellipsis = '') {
    $ellipsis = '';
    if ($text === '') {
        return '';
    }

    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded !== '') {
        $text = $decoded;
    }

    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    if (preg_match('/(\.\.\.|…)\s*$/u', $text, $matches)) {
        $ellipsis = $matches[1];
        $text = trim(preg_replace('/(\.\.\.|…)\s*$/u', '', $text));
    }

    return $text;
}

function sl_find_excerpt_replacement($text, array $replacements) {
    $trimmed = $text;
    $leading_ws = '';
    $trailing_ws = '';
    if (preg_match('/^\s+/u', $trimmed, $match)) {
        $leading_ws = $match[0];
    }
    if (preg_match('/\s+$/u', $trimmed, $match)) {
        $trailing_ws = $match[0];
    }
    $trimmed = trim($trimmed);

    if ($trimmed === '') {
        return '';
    }

    $ellipsis = '';
    $normalized = sl_normalize_excerpt_text($trimmed, $ellipsis);
    if ($normalized === '') {
        return '';
    }

    $normalized_length = sl_text_length($normalized);
    if ($normalized_length < 20) {
        return '';
    }

    $best = null;
    $best_ratio = 0;
    $best_length = 0;

    foreach ($replacements as $replacement) {
        $normalized_search = $replacement['normalized'] ?? '';
        if ($normalized_search === '') {
            continue;
        }

        if (strpos($normalized_search, $normalized) !== 0) {
            continue;
        }

        $search_length = $replacement['normalized_length'] ?? sl_text_length($normalized_search);
        if ($search_length <= 0) {
            continue;
        }

        $ratio = $normalized_length / $search_length;
        if ($ratio > $best_ratio || ($ratio === $best_ratio && $search_length > $best_length)) {
            $best = $replacement;
            $best_ratio = $ratio;
            $best_length = $search_length;
        }
    }

    if ($best === null) {
        return '';
    }

    if ($best_ratio < 0.6 && $ellipsis === '') {
        return '';
    }

    $replacement_text = trim($best['replace'] ?? '');
    if ($replacement_text === '') {
        return '';
    }

    $excerpt_text = sl_build_excerpt_from_translation($replacement_text, $best_ratio);
    if ($excerpt_text !== '') {
        $replacement_text = $excerpt_text;
    }

    $is_trimmed_excerpt = sl_text_length($replacement_text) < sl_text_length(trim($best['replace'] ?? ''));
    $use_ellipsis = $ellipsis;
    if ($use_ellipsis === '' && $is_trimmed_excerpt && !sl_text_ends_with_sentence_punctuation($replacement_text)) {
        $use_ellipsis = '...';
    }

    if ($use_ellipsis !== '' && !preg_match('/(\.\.\.|…)\s*$/u', $replacement_text)) {
        $replacement_text .= $use_ellipsis;
    }

    return $leading_ws . $replacement_text . $trailing_ws;
}

function sl_build_flexible_match_pattern($text) {
    if ($text === '') {
        return '';
    }

    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded !== '') {
        $text = $decoded;
    }

    static $entity_map = null;
    if ($entity_map === null) {
        $entity_map = [
            "'" => ['&#039;', '&#39;', '&apos;', '’', '&#8217;', '&#x2019;', '&rsquo;', '‘', '&#8216;', '&#x2018;', '&lsquo;'],
            '"' => ['&quot;', '&#34;', '&#x22;', '“', '&#8220;', '&#x201C;', '&ldquo;', '”', '&#8221;', '&#x201D;', '&rdquo;'],
            '&' => ['&amp;', '&#38;', '&#038;', '&#x26;', '&#X26;'],
            '’' => ['&#8217;', '&#x2019;', '&rsquo;'],
            '‘' => ['&#8216;', '&#x2018;', '&lsquo;'],
            '“' => ['&#8220;', '&#x201C;', '&ldquo;'],
            '”' => ['&#8221;', '&#x201D;', '&rdquo;'],
            '•' => ['&bull;', '&#8226;', '&#x2022;', '&#X2022;'],
            '·' => ['&middot;', '&centerdot;', '&#183;', '&#xB7;', '&#XB7;'],
            '—' => ['&#8212;', '&#x2014;', '&mdash;'],
            '–' => ['&#8211;', '&#x2013;', '&ndash;'],
            '…' => ['&#8230;', '&#x2026;', '&hellip;'],
            "\xC2\xA0" => ['&nbsp;', '&#160;', '&#xA0;'],
        ];
    }

    $pattern = '';
    $needs_pattern = false;
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return '';
    }

    foreach ($chars as $char) {
        if (preg_match('/\s/u', $char)) {
            $pattern .= '(?:\s|&nbsp;|&#160;|&#xA0;)+';
            $needs_pattern = true;
            continue;
        }

        if (isset($entity_map[$char])) {
            $alternatives = array_merge([$char], $entity_map[$char]);
            $escaped = array_map(function($item) {
                return preg_quote($item, '~');
            }, $alternatives);
            $pattern .= '(?:' . implode('|', array_unique($escaped)) . ')';
            $needs_pattern = true;
            continue;
        }

        $pattern .= preg_quote($char, '~');
    }

    if (!$needs_pattern) {
        return '';
    }

    return '~' . $pattern . '~u';
}

function sl_collect_replacement_matches($text, array $replacements) {
    $matches = [];
    $text_length = strlen($text);

    foreach ($replacements as $replacement) {
        $search_text = $replacement['search'];
        $search_length = $replacement['length'];
        if ($search_text === '' || $search_length === 0 || $search_length > $text_length) {
            continue;
        }

        $pattern = $replacement['pattern'] ?? '';
        $found_any = false;
        $local_positions = [];
        $offset = 0;
        while (($pos = strpos($text, $search_text, $offset)) !== false) {
            $found_any = true;
            $end = $pos + $search_length;
            $key = $pos . ':' . $end;
            if (!isset($local_positions[$key])) {
                $local_positions[$key] = true;
                $matches[] = [
                    'start' => $pos,
                    'end' => $end,
                    'replace' => $replacement['replace'],
                    'weight' => $search_length,
                    'order' => $replacement['order'],
                ];
            }
            $offset = $pos + 1;
        }

        if ($found_any || $pattern === '') {
            continue;
        }

        $offset = 0;
        while ($offset <= $text_length && preg_match($pattern, $text, $found, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $found[0][1];
            $match_text = $found[0][0];
            $end = $pos + strlen($match_text);
            $key = $pos . ':' . $end;
            if (!isset($local_positions[$key])) {
                $local_positions[$key] = true;
                $matches[] = [
                    'start' => $pos,
                    'end' => $end,
                    'replace' => $replacement['replace'],
                    'weight' => $search_length,
                    'order' => $replacement['order'],
                ];
            }
            $offset = $pos + 1;
        }
    }

    return $matches;
}

function sl_select_best_replacement_matches(array $matches) {
    usort($matches, function($a, $b) {
        if ($a['end'] === $b['end']) {
            if ($a['start'] === $b['start']) {
                return $a['order'] <=> $b['order'];
            }
            return $a['start'] <=> $b['start'];
        }
        return $a['end'] <=> $b['end'];
    });

    $count = count($matches);
    $ends = array_column($matches, 'end');
    $previous = array_fill(0, $count, -1);
    for ($i = 0; $i < $count; $i++) {
        $start = $matches[$i]['start'];
        $lo = 0;
        $hi = $i - 1;
        $index = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($ends[$mid] <= $start) {
                $index = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        $previous[$i] = $index;
    }

    $dp_weight = array_fill(0, $count + 1, 0);
    $dp_count = array_fill(0, $count + 1, 0);
    $choose = array_fill(0, $count, false);

    // Prefer the set of matches that maximizes replaced length, then fewer replacements.
    for ($i = 1; $i <= $count; $i++) {
        $match = $matches[$i - 1];
        $prev_index = $previous[$i - 1] + 1;
        $include_weight = $match['weight'] + $dp_weight[$prev_index];
        $include_count = 1 + $dp_count[$prev_index];
        $exclude_weight = $dp_weight[$i - 1];
        $exclude_count = $dp_count[$i - 1];

        if ($include_weight > $exclude_weight || ($include_weight === $exclude_weight && $include_count < $exclude_count)) {
            $dp_weight[$i] = $include_weight;
            $dp_count[$i] = $include_count;
            $choose[$i - 1] = true;
        } else {
            $dp_weight[$i] = $exclude_weight;
            $dp_count[$i] = $exclude_count;
            $choose[$i - 1] = false;
        }
    }

    $selected = [];
    $i = $count;
    while ($i > 0) {
        if ($choose[$i - 1]) {
            $selected[] = $matches[$i - 1];
            $i = $previous[$i - 1] + 1;
        } else {
            $i--;
        }
    }

    usort($selected, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return $selected;
}

// Function to process the buffer and replace text with translations, allowing for partial language matches (e.g., 'en' matches 'en_US' or 'en_GB')
function sl_process_translations_in_buffer($content) {
    global $wpdb;

    // Get preferred language (2-letter code) based on manual selection or locale
    $preferred_lang = sl_get_preferred_language_code();
    if (empty($preferred_lang)) {
        return $content;
    }

    // Log the detected preferred language
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Detected preferred language: " . $preferred_lang);
    }

    // Get all extracted texts from the database
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $extracted_texts = $wpdb->get_results("SELECT id, original_text FROM $table_name");

    // Sort the extracted texts by length, longest first
    usort($extracted_texts, function($a, $b) {
        return strlen($b->original_text) - strlen($a->original_text);
    });

    $shortcode_map = [];
    $shortcode_reverse_map = [];
    $content = sl_mask_shortcodes($content, $shortcode_map, $shortcode_reverse_map);
    list($content_tokens, $replaceable_tokens) = sl_split_html_for_translation($content);

    $replacements = [];
    $replacement_index = 0;

    // Collect candidate replacements with their translations.
    foreach ($extracted_texts as $text) {
        if (sl_is_text_only_shortcodes($text->original_text)) {
            continue;
        }

        // Query for translations where the first two characters of the target language match the browser language
        $translated_text = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d AND LOWER(SUBSTRING(target_language, 1, 2)) = %s",
            $text->id, strtolower($preferred_lang)
        ));

        // Log whether a translation was found and the matched language
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (!empty($translated_text)) {
                error_log("Translation found for text ID " . $text->id . " for preferred language " . $preferred_lang);
            } else {
                error_log("No translation found for text ID " . $text->id . " for preferred language " . $preferred_lang);
            }
        }

        // If a translation is found, replace the original text in the page content
        if (!empty($translated_text)) {
            if (!sl_translation_preserves_shortcodes($text->original_text, $translated_text)) {
                continue;
            }

            $search_text = $text->original_text;
            $replacement_text = $translated_text;
            if (!empty($shortcode_reverse_map)) {
                $search_text = strtr($search_text, $shortcode_reverse_map);
                $replacement_text = strtr($replacement_text, $shortcode_reverse_map);
            }

            if ($search_text === '' || $search_text === $replacement_text) {
                continue;
            }

            if (!isset($replacements[$search_text])) {
                $unused_ellipsis = '';
                $normalized_excerpt = sl_normalize_excerpt_text($search_text, $unused_ellipsis);
                $normalized_length = $normalized_excerpt !== '' ? sl_text_length($normalized_excerpt) : 0;
                $replacements[$search_text] = [
                    'search' => $search_text,
                    'replace' => $replacement_text,
                    'length' => strlen($search_text),
                    'normalized' => $normalized_excerpt,
                    'normalized_length' => $normalized_length,
                    'pattern' => sl_build_flexible_match_pattern($search_text),
                    'order' => $replacement_index++,
                ];
            }
        }
    }

    if (!empty($replacements)) {
        $content_tokens = sl_replace_text_in_tokens_with_overlaps($content_tokens, $replaceable_tokens, array_values($replacements));
    }

    // Return the modified content with translations
    $content = implode('', $content_tokens);
    return sl_restore_shortcodes($content, $shortcode_map);
}

// Display extracted texts page with translations
function sl_display_extracted_texts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    $results = $wpdb->get_results("SELECT * FROM $table_name");
    $available_languages = sl_get_available_language_options();
    $available_language_codes = array_keys($available_languages);
    $available_source_languages = sl_get_available_source_language_options();
    $available_source_codes = array_keys($available_source_languages);
    $stored_source_lang = get_option('sl_last_source_lang', '');
    $last_source_lang = sl_normalize_source_language($stored_source_lang, $available_source_languages);
    if (empty($last_source_lang) && !empty($available_source_codes)) {
        $last_source_lang = $available_source_codes[0];
    }
    if (!empty($last_source_lang) && $stored_source_lang !== $last_source_lang) {
        update_option('sl_last_source_lang', $last_source_lang);
    }
    $selected_target_langs = sl_normalize_target_languages(get_option('sl_last_target_langs', []), $available_languages);
    if (empty($selected_target_langs)) {
        $legacy_target_lang = get_option('sl_last_target_lang', '');
        if (!empty($legacy_target_lang)) {
            $selected_target_langs = sl_normalize_target_languages([$legacy_target_lang], $available_languages);
        }
    }
    $active_target_lang = get_option('sl_last_active_target_lang', '');

    // Handle button actions (extract texts, clear database, translate texts)
    if (isset($_POST['extract_texts'])) {
        sl_extract_text_from_all_pages();
    }

    if (isset($_POST['clear_database'])) {
        sl_clear_extracted_texts();
    }

    if (isset($_POST['save_target_languages'])) {
        $source_lang = sl_normalize_source_language(sanitize_text_field($_POST['source_lang']), $available_source_languages);
        if (empty($source_lang) && !empty($available_source_codes)) {
            $source_lang = $available_source_codes[0];
        }
        $target_langs = isset($_POST['target_langs']) ? array_map('sanitize_text_field', (array) $_POST['target_langs']) : [];
        $target_langs = sl_normalize_target_languages($target_langs, $available_languages);
        update_option('sl_last_source_lang', $source_lang);
        update_option('sl_last_target_langs', $target_langs);
        $last_source_lang = $source_lang;
        $selected_target_langs = $target_langs;
        if (count($selected_target_langs) === 1) {
            update_option('sl_last_active_target_lang', $selected_target_langs[0]);
            update_option('sl_last_target_lang', $selected_target_langs[0]);
        }
    }

    if (isset($_POST['add_custom_text'])) {
        if (!empty($_POST['sl_add_custom_text_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sl_add_custom_text_nonce'])), 'sl_add_custom_text')) {
            $source_lang = sl_normalize_source_language(sanitize_text_field($_POST['source_lang']), $available_source_languages);
            if (empty($source_lang) && !empty($available_source_codes)) {
                $source_lang = $available_source_codes[0];
            }
            if (!empty($source_lang)) {
                update_option('sl_last_source_lang', $source_lang);
                $last_source_lang = $source_lang;
            }

            $custom_text = isset($_POST['custom_text']) ? wp_unslash($_POST['custom_text']) : '';
            $add_result = sl_add_custom_extracted_text($custom_text, get_locale());
            if ($add_result['status'] === 'added') {
                echo '<div class="updated"><p>Custom text added successfully.</p></div>';
            } elseif ($add_result['status'] === 'exists') {
                echo '<div class="notice notice-warning"><p>That text already exists in the extracted list.</p></div>';
            } elseif ($add_result['status'] === 'invalid') {
                echo '<div class="notice notice-error"><p>Please enter valid text to add.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Unable to add the custom text. Please try again.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Unable to add the custom text due to a security check. Please try again.</p></div>';
        }
    }

    if (isset($_POST['translate_texts'])) {
        $source_lang = sl_normalize_source_language(sanitize_text_field($_POST['source_lang']), $available_source_languages);
        if (empty($source_lang) && !empty($available_source_codes)) {
            $source_lang = $available_source_codes[0];
        }
        $target_lang = sanitize_text_field($_POST['target_lang']);
        if (!in_array($target_lang, $available_language_codes, true)) {
            $target_lang = '';
        }
        if (!empty($source_lang)) {
            update_option('sl_last_source_lang', $source_lang);
            $last_source_lang = $source_lang;
        }
        if (!empty($target_lang)) {
            update_option('sl_last_active_target_lang', $target_lang);
            update_option('sl_last_target_lang', $target_lang);
            $translation_report = [];
            $translated_count = sl_translate_and_display_texts($source_lang, $target_lang, $results, $translation_report);
            $target_label = $available_languages[$target_lang] ?? $target_lang;
            if ($translated_count > 0) {
                echo '<div class="updated"><p>Added ' . esc_html($translated_count) . ' new translations for ' . esc_html($target_label) . '.</p></div>';
            } else {
                $diagnostic = sl_build_translation_report_message($translation_report);
                echo '<div class="notice notice-warning"><p>' . esc_html($diagnostic) . '</p></div>';
            }
        }
    }

    if (!in_array($active_target_lang, $selected_target_langs, true)) {
        $active_target_lang = $selected_target_langs[0] ?? '';
        if (!empty($active_target_lang)) {
            update_option('sl_last_active_target_lang', $active_target_lang);
        }
    }

    // Handle custom translation save
    if (isset($_POST['save_custom_translation'])) {
        sl_save_custom_translation();
    }

    $results = $wpdb->get_results("SELECT * FROM $table_name");
    $translations_by_lang = [];
    if (!empty($selected_target_langs) && !empty($results)) {
        $placeholders = implode(',', array_fill(0, count($selected_target_langs), '%s'));
        $query = "SELECT id, extracted_text_id, target_language, translated_text FROM $translation_table_name WHERE target_language IN ($placeholders)";
        $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $selected_target_langs));
        $translation_rows = $wpdb->get_results($prepared);
        foreach ($translation_rows as $translation_row) {
            $translations_by_lang[$translation_row->target_language][$translation_row->extracted_text_id] = $translation_row;
        }
    }
    $source_language_label = $available_source_languages[$last_source_lang] ?? $last_source_lang;

    echo '<div class="wrap">';
    echo '<h1>Extracted Texts</h1>';

    // Add buttons to manually extract text and clear database
    echo '<form method="post" class="sl-extract-actions">';
    submit_button('Extract Texts from All Pages', 'primary', 'extract_texts', false);
    submit_button('Clear Database', 'secondary', 'clear_database', false);
    echo '</form>';

    // Translation section with language selection
    echo '<h2>Translate Extracted Texts</h2>';
    echo '<p>Select one or more target languages to manage translations.</p>';
    echo '<form method="post" class="sl-language-selection">';
    echo '<label for="sl-source-lang">Source Language: </label>';
    echo '<select name="source_lang" id="sl-source-lang">';
    foreach ($available_source_languages as $code => $name) {
        echo "<option value='" . esc_attr($code) . "'" . selected($code, $last_source_lang, false) . '>' . esc_html($name) . '</option>';
    }
    echo '</select>';
    echo '<fieldset class="sl-target-language-list">';
    echo '<legend>Target Languages</legend>';
    foreach ($available_languages as $code => $name) {
        $checked = in_array($code, $selected_target_langs, true) ? ' checked' : '';
        echo '<label><input type="checkbox" name="target_langs[]" value="' . esc_attr($code) . '"' . $checked . '> ' . esc_html($name) . '</label>';
    }
    echo '</fieldset>';
    submit_button('Update Target Languages', 'secondary', 'save_target_languages');
    echo '</form>';

    echo '<h2>Add Custom Text</h2>';
    echo '<p>Add text that the extractor missed. It will appear in all selected language tabs.</p>';
    echo '<form method="post" class="sl-custom-text">';
    echo '<textarea name="custom_text" rows="3" style="width:100%; max-width: 800px;"></textarea>';
    echo '<input type="hidden" name="source_lang" class="sl-source-lang-input" value="' . esc_attr($last_source_lang) . '">';
    echo wp_nonce_field('sl_add_custom_text', 'sl_add_custom_text_nonce', true, false);
    submit_button('Add Text', 'secondary', 'add_custom_text', false);
    echo '</form>';

    // Display extracted texts in tabbed tables per target language
    echo '<h2>Extracted Texts and Custom Translations</h2>';
    echo '<p class="description">Stored locale reflects the site locale at extraction time and does not change the translation source.</p>';
    if (empty($selected_target_langs)) {
        echo '<p>Select target languages above to view translation tabs.</p>';
    } else {
        echo '<div class="sl-language-tabs">';
        echo '<div class="sl-language-tab-nav" role="tablist" aria-label="Target languages">';
        foreach ($selected_target_langs as $code) {
            $language_name = $available_languages[$code] ?? $code;
            $is_active = $code === $active_target_lang;
            $button_id = 'sl-tab-button-' . $code;
            $panel_id = 'sl-tab-' . $code;
            echo '<button type="button" class="sl-language-tab' . ($is_active ? ' is-active' : '') . '" id="' . esc_attr($button_id) . '" role="tab" aria-selected="' . ($is_active ? 'true' : 'false') . '" aria-controls="' . esc_attr($panel_id) . '" data-target="' . esc_attr($panel_id) . '">';
            echo esc_html($language_name) . ' (' . esc_html($code) . ')';
            echo '</button>';
        }
        echo '</div>';

        foreach ($selected_target_langs as $code) {
            $language_name = $available_languages[$code] ?? $code;
            $is_active = $code === $active_target_lang;
            $panel_id = 'sl-tab-' . $code;
            echo '<div class="sl-language-tab-panel' . ($is_active ? ' is-active' : '') . '" id="' . esc_attr($panel_id) . '" role="tabpanel" aria-labelledby="sl-tab-button-' . esc_attr($code) . '">';
            echo '<div class="sl-language-tab-header">';
            echo '<div class="sl-language-tab-title">';
            echo '<h3>' . esc_html($language_name) . ' (' . esc_html($code) . ')</h3>';
            if (!empty($source_language_label)) {
                echo '<span class="sl-source-language">Source: ' . esc_html($source_language_label) . ' (' . esc_html($last_source_lang) . ')</span>';
            }
            echo '</div>';
            echo '<form method="post" class="sl-translate-form">';
            echo '<input type="hidden" name="source_lang" class="sl-source-lang-input" value="' . esc_attr($last_source_lang) . '">';
            echo '<input type="hidden" name="target_lang" value="' . esc_attr($code) . '">';
            echo '<button type="submit" class="button button-primary" name="translate_texts" value="1">Translate Texts</button>';
            echo '</form>';
            echo '</div>';

            echo '<table class="widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Original Text</th><th>Stored Locale</th><th>Translation</th><th>Action</th></tr></thead>';
            echo '<tbody>';

            if (!empty($results)) {
                foreach ($results as $row) {
                    $translation = $translations_by_lang[$code][$row->id] ?? null;
                    $translated_text = $translation ? $translation->translated_text : '';
                    $translation_id = $translation ? $translation->id : '';
                    $button_label = !empty($translation_id) ? 'Update' : 'Save';
                    $form_id = 'sl-translation-form-' . $code . '-' . $row->id;
                    echo '<tr>';
                    echo '<td>' . esc_html($row->id) . '</td>';
                    echo '<td>' . esc_html($row->original_text) . '</td>';
                    echo '<td>' . esc_html($row->source_language) . '</td>';
                    echo '<td>';
                    echo '<input type="text" name="translated_text" form="' . esc_attr($form_id) . '" value="' . esc_attr($translated_text) . '" size="50">';
                    echo '</td>';
                    echo '<td>';
                    echo '<form method="post" id="' . esc_attr($form_id) . '" class="sl-translation-form" style="margin:0;">';
                    echo '<input type="hidden" name="extracted_text_id" value="' . esc_attr($row->id) . '">';
                    echo '<input type="hidden" name="target_language" value="' . esc_attr($code) . '">';
                    if (!empty($translation_id)) {
                        echo '<input type="hidden" name="translation_id" value="' . esc_attr($translation_id) . '">';
                    }
                    echo wp_nonce_field('sl_save_custom_translation', 'sl_save_custom_translation_nonce', true, false);
                    submit_button($button_label, 'small', 'save_custom_translation', false);
                    echo '<span class="sl-translation-status" aria-live="polite"></span>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="5">No extracted texts found. Click "Extract Texts from All Pages" to get started.</td></tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</div>';

        echo '<style>
            .sl-language-selection { margin-bottom: 12px; }
            .sl-target-language-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px 12px; margin: 10px 0 12px; padding: 8px 10px; border: 1px solid #dcdcde; background: #fff; }
            .sl-target-language-list legend { font-weight: 600; padding: 0 4px; }
            .sl-target-language-list label { display: flex; align-items: center; gap: 6px; }
            .sl-language-tabs { margin-top: 12px; }
            .sl-language-tab-nav { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
            .sl-language-tab { border: 1px solid #ccd0d4; background: #f0f0f1; padding: 6px 10px; cursor: pointer; }
            .sl-language-tab.is-active { background: #fff; border-bottom-color: #fff; }
            .sl-language-tab-panel { display: none; border: 1px solid #ccd0d4; background: #fff; padding: 12px; }
            .sl-language-tab-panel.is-active { display: block; }
            .sl-language-tab-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
            .sl-language-tab-title { display: flex; flex-direction: column; gap: 4px; }
            .sl-language-tab-header h3 { margin: 0; }
            .sl-source-language { color: #646970; font-size: 12px; }
            .sl-language-tab-panel table th:nth-child(1),
            .sl-language-tab-panel table td:nth-child(1) { width: 60px; }
            .sl-language-tab-panel table th:nth-child(5),
            .sl-language-tab-panel table td:nth-child(5) { width: 110px; }
            .sl-language-tab-panel table td input[type="text"] { width: 100%; max-width: 100%; box-sizing: border-box; }
            .sl-translation-status { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; font-size: 12px; color: #1d2327; }
            .sl-translation-status.is-saving { color: #646970; }
            .sl-translation-status.is-success { color: #0a7a2f; }
            .sl-translation-status.is-success::before { content: "\2713"; }
            .sl-translation-status.is-error { color: #b32d2e; }
        </style>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var tabs = document.querySelectorAll(".sl-language-tab");
                var panels = document.querySelectorAll(".sl-language-tab-panel");
                tabs.forEach(function(tab) {
                    tab.addEventListener("click", function() {
                        var targetId = tab.getAttribute("data-target");
                        tabs.forEach(function(other) {
                            other.classList.remove("is-active");
                            other.setAttribute("aria-selected", "false");
                        });
                        panels.forEach(function(panel) {
                            panel.classList.remove("is-active");
                        });
                        tab.classList.add("is-active");
                        tab.setAttribute("aria-selected", "true");
                        var panel = document.getElementById(targetId);
                        if (panel) {
                            panel.classList.add("is-active");
                        }
                    });
                });

                var sourceSelect = document.getElementById("sl-source-lang");
                if (sourceSelect) {
                    var updateSource = function() {
                        document.querySelectorAll(".sl-source-lang-input").forEach(function(input) {
                            input.value = sourceSelect.value;
                        });
                    };
                    sourceSelect.addEventListener("change", updateSource);
                    updateSource();
                }

                var ajaxUrl = window.ajaxurl || ' . wp_json_encode(admin_url('admin-ajax.php')) . ';
                document.querySelectorAll(".sl-translation-form").forEach(function(form) {
                    form.addEventListener("submit", function(event) {
                        if (!window.fetch) {
                            return;
                        }
                        event.preventDefault();

                        var formData = new FormData(form);
                        formData.append("action", "sl_save_custom_translation");

                        var row = form.closest("tr");
                        var translatedInput = row ? row.querySelector(\'input[name="translated_text"]\') : null;
                        if (translatedInput) {
                            formData.set("translated_text", translatedInput.value);
                        }

                        var status = form.querySelector(".sl-translation-status");
                        var submitButton = form.querySelector(\'input[type="submit"], button[type="submit"]\');
                        if (status) {
                            status.textContent = "Saving...";
                            status.className = "sl-translation-status is-saving";
                        }
                        if (submitButton) {
                            submitButton.disabled = true;
                        }

                        fetch(ajaxUrl, {
                            method: "POST",
                            credentials: "same-origin",
                            body: formData
                        })
                            .then(function(response) {
                                return response.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    var message = (data.data && data.data.message) ? data.data.message : "Saved.";
                                    if (status) {
                                        status.textContent = message;
                                        status.className = "sl-translation-status is-success";
                                    }
                                    if (submitButton && submitButton.value) {
                                        submitButton.value = "Update";
                                    }
                                    if (data.data && data.data.translation_id) {
                                        var translationInput = form.querySelector(\'input[name="translation_id"]\');
                                        if (!translationInput) {
                                            translationInput = document.createElement("input");
                                            translationInput.type = "hidden";
                                            translationInput.name = "translation_id";
                                            form.appendChild(translationInput);
                                        }
                                        translationInput.value = data.data.translation_id;
                                    }
                                } else {
                                    var errorMessage = (data && data.data && data.data.message) ? data.data.message : "Unable to save translation.";
                                    if (status) {
                                        status.textContent = errorMessage;
                                        status.className = "sl-translation-status is-error";
                                    }
                                }
                            })
                            .catch(function() {
                                if (status) {
                                    status.textContent = "Unable to save translation.";
                                    status.className = "sl-translation-status is-error";
                                }
                            })
                            .finally(function() {
                                if (submitButton) {
                                    submitButton.disabled = false;
                                }
                            });
                    });
                });
            });
        </script>';
    }

    echo '</div>';
}

function sl_handle_custom_translation_save($request) {
    global $wpdb;
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';

    if (!current_user_can('manage_options')) {
        return ['status' => 'error', 'message' => 'You are not allowed to do that.'];
    }

    $nonce = '';
    if (isset($request['sl_save_custom_translation_nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($request['sl_save_custom_translation_nonce']));
    }
    if ($nonce === '' || !wp_verify_nonce($nonce, 'sl_save_custom_translation')) {
        return ['status' => 'error', 'message' => 'Security check failed. Please refresh and try again.'];
    }

    $extracted_text_id = isset($request['extracted_text_id']) ? intval($request['extracted_text_id']) : 0;
    $target_language = isset($request['target_language']) ? sanitize_text_field(wp_unslash($request['target_language'])) : '';
    $translated_text = isset($request['translated_text']) ? sanitize_textarea_field(wp_unslash($request['translated_text'])) : '';

    if ($extracted_text_id <= 0 || $target_language === '') {
        return ['status' => 'error', 'message' => 'Missing translation details.'];
    }

    $translation_id = isset($request['translation_id']) ? intval($request['translation_id']) : 0;

    if ($translation_id > 0) {
        $updated = $wpdb->update(
            $translation_table_name,
            ['translated_text' => $translated_text],
            ['id' => $translation_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return ['status' => 'error', 'message' => 'Unable to update translation.'];
        }

        return [
            'status' => 'updated',
            'message' => 'Translation updated successfully.',
            'translation_id' => $translation_id,
        ];
    }

    $existing_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $translation_table_name WHERE extracted_text_id = %d AND target_language = %s",
        $extracted_text_id,
        $target_language
    ));

    if ($existing_id) {
        $updated = $wpdb->update(
            $translation_table_name,
            ['translated_text' => $translated_text],
            [
                'extracted_text_id' => $extracted_text_id,
                'target_language' => $target_language,
            ],
            ['%s'],
            ['%d', '%s']
        );
        if ($updated === false) {
            return ['status' => 'error', 'message' => 'Unable to update translation.'];
        }
        return [
            'status' => 'updated',
            'message' => 'Translation updated successfully.',
            'translation_id' => (int) $existing_id,
        ];
    }

    $inserted = $wpdb->insert(
        $translation_table_name,
        [
            'extracted_text_id' => $extracted_text_id,
            'target_language' => $target_language,
            'translated_text' => $translated_text,
        ],
        ['%d', '%s', '%s']
    );

    if (!$inserted) {
        return ['status' => 'error', 'message' => 'Unable to save translation.'];
    }

    return [
        'status' => 'saved',
        'message' => 'Custom translation added successfully.',
        'translation_id' => (int) $wpdb->insert_id,
    ];
}

// Function to save custom translations entered by admin
function sl_save_custom_translation() {
    $result = sl_handle_custom_translation_save($_POST);
    $class = $result['status'] === 'error' ? 'notice notice-error' : 'updated';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($result['message']) . '</p></div>';
}

function sl_save_custom_translation_ajax() {
    $result = sl_handle_custom_translation_save($_POST);
    if ($result['status'] === 'error') {
        wp_send_json_error($result);
    }
    wp_send_json_success($result);
}
add_action('wp_ajax_sl_save_custom_translation', 'sl_save_custom_translation_ajax');

function sl_add_custom_extracted_text($raw_text, $source_locale) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';

    $clean_text = sanitize_textarea_field($raw_text);
    $clean_text = trim($clean_text);

    if (empty($clean_text) || preg_match('/^[\W\d]+$/', $clean_text)) {
        return ['status' => 'invalid'];
    }

    if (sl_is_text_only_shortcodes($clean_text)) {
        return ['status' => 'invalid'];
    }

    $existing_text = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE BINARY original_text = %s",
        $clean_text
    ));

    if ($existing_text) {
        return ['status' => 'exists'];
    }

    if (empty($source_locale)) {
        $source_locale = get_locale();
    }

    $inserted = $wpdb->insert(
        $table_name,
        [
            'original_text' => $clean_text,
            'source_language' => $source_locale,
        ]
    );

    if (!$inserted) {
        return ['status' => 'error'];
    }

    return ['status' => 'added'];
}

// Function to clear the extracted texts and translations from the database
function sl_clear_extracted_texts() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}extracted_text_translations");
    $wpdb->query("DELETE FROM {$wpdb->prefix}extracted_texts");

    echo '<div class="updated"><p>The database has been cleared.</p></div>';
}

// Function to translate extracted texts using DeepL and display in admin page
function sl_translate_and_display_texts($source_lang, $target_lang, $results, &$report = null) {
    global $wpdb;
    $translation_table_name = $wpdb->prefix . 'extracted_text_translations';
    $translated_count = 0;
    $report_data = [
        'total_rows' => count((array) $results),
        'already_translated' => 0,
        'shortcode_only' => 0,
        'attempted' => 0,
        'deepl_failures' => 0,
        'shortcode_preservation_failures' => 0,
        'db_insert_failures' => 0,
        'translated_count' => 0,
        'error_message_counts' => [],
    ];

    foreach ($results as $row) {
        // Check if this text already has a translation in the target language
        $existing_translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $translation_table_name WHERE extracted_text_id = %d AND target_language = %s",
            $row->id,
            $target_lang
        ));

        // If a translation already exists, skip translating it again
        if (!empty($existing_translation)) {
            $report_data['already_translated']++;
            continue;
        }

        if (sl_is_text_only_shortcodes($row->original_text)) {
            $report_data['shortcode_only']++;
            continue;
        }

        // Translate the text using DeepL
        $shortcode_map = [];
        $masked_text = sl_mask_shortcodes($row->original_text, $shortcode_map);
        $report_data['attempted']++;
        $translation_error = '';
        $translated_text = sl_deepl_translate_text($masked_text, $target_lang, $source_lang, $translation_error);

        if (is_wp_error($translated_text) || empty($translated_text)) {
            $report_data['deepl_failures']++;
            $error_key = is_wp_error($translated_text) ? $translated_text->get_error_message() : trim((string) $translation_error);
            if ($error_key === '') {
                $error_key = 'DeepL returned an empty translation.';
            }
            if (!isset($report_data['error_message_counts'][$error_key])) {
                $report_data['error_message_counts'][$error_key] = 0;
            }
            $report_data['error_message_counts'][$error_key]++;
            continue;
        }

        $translated_text = sl_restore_shortcodes($translated_text, $shortcode_map);
        if (!sl_translation_preserves_shortcodes($row->original_text, $translated_text)) {
            $report_data['shortcode_preservation_failures']++;
            continue;
        }

        // Insert the new translation into the database
        $inserted = $wpdb->insert(
            $translation_table_name,
            [
                'extracted_text_id' => $row->id,
                'translated_text'   => $translated_text,
                'target_language'   => $target_lang,
            ]
        );
        if ($inserted) {
            $translated_count++;
            $report_data['translated_count']++;
        } else {
            $report_data['db_insert_failures']++;
            $db_error = trim((string) $wpdb->last_error);
            if ($db_error !== '') {
                if (!isset($report_data['error_message_counts'][$db_error])) {
                    $report_data['error_message_counts'][$db_error] = 0;
                }
                $report_data['error_message_counts'][$db_error]++;
            }
        }
    }

    if (!empty($report_data['error_message_counts'])) {
        arsort($report_data['error_message_counts']);
        $report_data['top_errors'] = array_slice($report_data['error_message_counts'], 0, 3, true);
    } else {
        $report_data['top_errors'] = [];
    }
    unset($report_data['error_message_counts']);

    $report = $report_data;

    return $translated_count;
}

function sl_build_translation_report_message($report) {
    if (!is_array($report)) {
        return 'No new translations were added. Check your DeepL API key and source language selection.';
    }

    $translated_count = (int) ($report['translated_count'] ?? 0);
    if ($translated_count > 0) {
        return 'Added ' . $translated_count . ' new translations.';
    }

    $attempted = (int) ($report['attempted'] ?? 0);
    $already_translated = (int) ($report['already_translated'] ?? 0);
    $shortcode_only = (int) ($report['shortcode_only'] ?? 0);
    $deepl_failures = (int) ($report['deepl_failures'] ?? 0);
    $db_insert_failures = (int) ($report['db_insert_failures'] ?? 0);

    if ($attempted === 0 && $already_translated > 0) {
        return 'No new translations were added because all extracted texts already have translations for this target language.';
    }

    $parts = ['No new translations were added.'];
    $parts[] = 'Attempted: ' . $attempted . '.';
    if ($already_translated > 0) {
        $parts[] = 'Already translated: ' . $already_translated . '.';
    }
    if ($shortcode_only > 0) {
        $parts[] = 'Shortcode-only skipped: ' . $shortcode_only . '.';
    }
    if ($deepl_failures > 0) {
        $parts[] = 'DeepL failures: ' . $deepl_failures . '.';
    }
    if ($db_insert_failures > 0) {
        $parts[] = 'Database insert failures: ' . $db_insert_failures . '.';
    }

    $top_errors = isset($report['top_errors']) && is_array($report['top_errors']) ? $report['top_errors'] : [];
    if (!empty($top_errors)) {
        $primary_error = (string) array_key_first($top_errors);
        if ($primary_error !== '') {
            $parts[] = 'Latest error: ' . $primary_error;
        }
    }

    return implode(' ', $parts);
}

function sl_prepare_extracted_text_candidate($text) {
    $text = wp_strip_all_tags((string) $text, true);
    if ($text === '') {
        return '';
    }

    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded !== '') {
        $text = $decoded;
    }

    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    $text = preg_replace('/\s*\[\s*(?:\.\.\.|…)\s*\]\s*$/u', '...', $text);

    return trim($text);
}

function sl_insert_extracted_text_if_missing($table_name, $text, $source_locale = '') {
    global $wpdb;

    $prepared_text = sl_prepare_extracted_text_candidate($text);
    if ($prepared_text === '' || preg_match('/^[\W\d]+$/u', $prepared_text)) {
        return false;
    }

    if (sl_is_text_only_shortcodes($prepared_text)) {
        return false;
    }

    if ($source_locale === '') {
        $source_locale = get_locale();
    }

    $existing_text = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE BINARY original_text = %s",
        $prepared_text
    ));

    if ($existing_text) {
        return false;
    }

    $inserted = $wpdb->insert(
        $table_name,
        [
            'original_text' => $prepared_text,
            'source_language' => $source_locale,
        ]
    );

    return (bool) $inserted;
}

function sl_extract_excerpt_texts_from_post($table_name, $post) {
    if (!is_object($post) || empty($post->ID)) {
        return;
    }

    $candidates = [];

    $manual_excerpt = get_post_field('post_excerpt', $post->ID, 'raw');
    if (is_string($manual_excerpt) && $manual_excerpt !== '') {
        $candidates[] = $manual_excerpt;
    }

    $generated_excerpt = get_the_excerpt($post);
    if (is_string($generated_excerpt) && $generated_excerpt !== '') {
        $candidates[] = $generated_excerpt;
    }

    $content_text = sl_prepare_extracted_text_candidate($post->post_content ?? '');
    if ($content_text !== '') {
        $content_length = sl_text_length($content_text);
        $max_sentence_length = min(260, $content_length);
        $boundaries = sl_get_sentence_boundary_positions($content_text);
        foreach ($boundaries as $boundary) {
            if ($boundary >= 60 && $boundary <= $max_sentence_length) {
                $candidates[] = sl_text_substr($content_text, 0, $boundary);
                break;
            }
        }
    }

    if (empty($candidates)) {
        return;
    }

    $candidates = array_values(array_unique(array_filter($candidates, 'is_string')));
    foreach ($candidates as $candidate) {
        sl_insert_extracted_text_if_missing($table_name, $candidate);
    }
}

// Function to extract text from all published pages, posts, WooCommerce products, and navigation menus
function sl_extract_text_from_all_pages() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'extracted_texts';

    // First, extract text from navigation menus and blocks
    sl_extract_text_from_menus($table_name);
    sl_extract_text_from_navigation_blocks($table_name);

    // Query all published pages, posts, and WooCommerce products
    $args = [
        'post_type' => ['page', 'post', 'product'],
        'post_status' => 'publish',
        'posts_per_page' => -1
    ];
    $pages = get_posts($args);

    foreach ($pages as $page) {
        sl_insert_extracted_text_if_missing($table_name, $page->post_title ?? '');
        sl_extract_excerpt_texts_from_post($table_name, $page);

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
                    sl_insert_extracted_text_if_missing($table_name, $extracted_text);
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
                    sl_insert_extracted_text_if_missing($table_name, $extracted_text);
                }
            }
        }
    }

    echo '<div class="updated"><p>Text extraction from all pages, posts, products, and navigation menus completed. Check the Extracted Texts page.</p></div>';
}

// Function to extract text from WordPress navigation menus
function sl_extract_text_from_menus($table_name) {
    // Get all registered navigation menus
    $menus = wp_get_nav_menus();

    foreach ($menus as $menu) {
        // Get all menu items for this menu
        $menu_items = wp_get_nav_menu_items($menu->term_id);

        if (!empty($menu_items)) {
            foreach ($menu_items as $menu_item) {
                // Extract the menu item title
                sl_insert_extracted_text_if_missing($table_name, $menu_item->title);
            }
        }
    }
}

function sl_extract_text_from_navigation_blocks($table_name) {
    if (!function_exists('parse_blocks')) {
        return;
    }

    $nav_posts = get_posts([
        'post_type' => 'wp_navigation',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ]);

    if (empty($nav_posts)) {
        return;
    }

    foreach ($nav_posts as $nav_post) {
        $blocks = parse_blocks($nav_post->post_content);
        $labels = [];
        sl_collect_navigation_block_labels($blocks, $labels);

        foreach ($labels as $label) {
            sl_insert_extracted_text_if_missing($table_name, $label);
        }
    }
}

function sl_collect_navigation_block_labels($blocks, &$labels) {
    if (empty($blocks) || !is_array($blocks)) {
        return;
    }

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        $block_name = $block['blockName'] ?? '';
        if ($block_name === 'core/navigation-link' || $block_name === 'core/navigation-submenu') {
            $attrs = $block['attrs'] ?? [];
            $label = '';
            if (!empty($attrs['label'])) {
                $label = $attrs['label'];
            } elseif (!empty($attrs['title'])) {
                $label = $attrs['title'];
            } elseif (!empty($attrs['id']) && is_numeric($attrs['id'])) {
                $label = get_the_title((int) $attrs['id']);
            }

            if (empty($label)) {
                $inner_html = $block['innerHTML'] ?? '';
                if (!empty($inner_html)) {
                    $label = trim(strip_tags($inner_html));
                }
            }

            if (!empty($label)) {
                $labels[] = $label;
            }
        }

        if (!empty($block['innerBlocks'])) {
            sl_collect_navigation_block_labels($block['innerBlocks'], $labels);
        }
    }
}
