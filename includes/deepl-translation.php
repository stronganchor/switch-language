<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Perform translation with DeepL API using wp_remote_post
function sl_deepl_translate_text($text, $translate_to_lang, $translate_from_lang) {
    $api_key = get_option('deepl_api_key'); // Retrieve the API key from WordPress options
    if (empty($api_key)) {
        return null;
    }

    $endpoint = 'https://api-free.deepl.com/v2/translate';

    // Prepare the request data
    $data = [
        'auth_key' => $api_key,
        'text' => $text,
        'target_lang' => $translate_to_lang,
        'source_lang' => $translate_from_lang,
    ];

    // Use wp_remote_post to send the request
    $response = wp_remote_post($endpoint, [
        'body' => $data,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
    ]);

    // Check for errors in the response
    if (is_wp_error($response)) {
        return null; // Return null if there's an error
    }

    // Get the response body
    $result = wp_remote_retrieve_body($response);

    // Decode the JSON response
    $json = json_decode($result, true);

    // Check if the translation exists
    if (!is_array($json) || !isset($json['translations'][0]['text'])) {
        return null; // Return null to indicate an unexpected error occurred
    }

    // Return the translated text or the original text if something goes wrong
    return $json['translations'][0]['text'] ?? $text;
}

// Get the translation languages from the DeepL API
function sl_deepl_get_language_names($no_parentheses = false, $type = 'target') {
    $json = deepl_get_language_json();

    // Remove parentheses and duplicate entries if no_parentheses is true
    if ($no_parentheses) {
        $json = array_map(function($lang) {
            return preg_replace('/\s*\(.*\)/', '', $lang['name']);
        }, $json);
        $json = array_unique($json);
    }

    // Map the response to just the names of the languages
    return array_map(function($lang) {
        return $lang['name'];
    }, $json);
}

// Get an array with keys as language codes and values as language names
function deepl_get_language_codes() {
    $json = deepl_get_language_json();
    if ($json === null) {
        return null;
    }

    return array_column($json, 'name', 'language');
}

// Get the full language json from the DeepL API
function deepl_get_language_json($type = 'target') {
    $transient_key = 'deepl_language_json_' . $type;
    $cached_json = get_transient($transient_key);

    if ($cached_json !== false) {
        return $cached_json;
    }

    $api_key = get_option('deepl_api_key');
    if (empty($api_key)) {
        return null;
    }

    $endpoint = 'https://api-free.deepl.com/v2/languages';
    $url = add_query_arg('type', $type, $endpoint); // Add type to the query string

    // Use wp_remote_get() to make the API call
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'DeepL-Auth-Key ' . $api_key,
        ],
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        return null;
    }

    // Get the response body
    $response_body = wp_remote_retrieve_body($response);
    $json = json_decode($response_body, true);

    if (!is_array($json) || empty($json)) {
        return null;
    }

    // Cache the result for 24 hours
    set_transient($transient_key, $json, DAY_IN_SECONDS);

    return $json;
}

// Test the DeepL API with a shortcode [test_deepl_api]
function sl_test_deepl_api_shortcode() {
    $output = '';

    // Test the get_deepl_language_names function
    $languages = sl_deepl_get_language_names();
    if ($languages === null) {
        $output .= 'Failed to retrieve language names.<br>';
    } else {
        $output .= 'Available languages: ' . implode(', ', $languages) . '<br>';
    }

    // Test the deepl_translate_text function
    $translated_text = sl_deepl_translate_text('Merhaba Dünya!', 'EN', 'TR');
    if ($translated_text === null) {
        $output .= 'Translation failed. Please check your API key.<br>';
    }
    $output .= 'Translated text from Turkish: ' . $translated_text . '<br>';
    return $output;
}
add_shortcode('test_deepl_api', 'sl_test_deepl_api_shortcode');
