<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Perform translation with DeepL API
function deepl_translate_text($text, $translate_to_lang, $translate_from_lang) {
    $api_key = get_option('deepl_api_key'); // Retrieve the API key from WordPress options
    if (empty($api_key)) {
        return null;
    }

    $endpoint = 'https://api-free.deepl.com/v2/translate';
    $data = http_build_query([
        'auth_key' => $api_key,
        'text' => $text,
        'target_lang' => $translate_to_lang,
        'source_lang' => $translate_from_lang,
    ]);

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $data,
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($endpoint, false, $context);

    if ($result === FALSE) {
        return null; // return null if translation failed
    }

    $json = json_decode($result, true);
    if (!is_array($json) || !isset($json['translations'][0]['text'])) {
        return null; // Return null to indicate an unexpected error occurred
    }
    return $json['translations'][0]['text'] ?? $text; // Return the translation or original text if something goes wrong
}

// Get the translation languages from the DeepL API
function deepl_get_language_names($no_parentheses = false, $type = 'target') {
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
    $url = $endpoint . '?type=' . $type; // Add the type parameter to the query string

    $options = [
        'http' => [
            'header' => "Authorization: DeepL-Auth-Key $api_key\r\n",
            'method' => 'GET',
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    if ($result === FALSE) {
        return null;
    }

    $json = json_decode($result, true);
    if (!is_array($json) || empty($json)) {
        return null;
    }

    set_transient($transient_key, $json, DAY_IN_SECONDS); // Cache the result for 24 hours

    return $json;
}

// Test the DeepL API with a shortcode [test_deepl_api]
function test_deepl_api_shortcode() {
    $output = '';

    // Test the get_deepl_language_names function
    $languages = deepl_get_language_names();
    if ($languages === null) {
        $output .= 'Failed to retrieve language names.<br>';
    } else {
        $output .= 'Available languages: ' . implode(', ', $languages) . '<br>';
    }

    // Test the deepl_translate_text function
    $translated_text = deepl_translate_text('Merhaba Dünya!', 'EN', 'TR');
    if ($translated_text === null) {
        $output .= 'Translation failed. Please check your API key.<br>';
    }
    $output .= 'Translated text from Turkish: ' . $translated_text . '<br>';
    return $output;
}
add_shortcode('test_deepl_api', 'test_deepl_api_shortcode');
