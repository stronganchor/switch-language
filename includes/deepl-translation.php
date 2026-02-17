<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function sl_deepl_get_api_base_url($api_key = '') {
    if ($api_key === '') {
        $api_key = (string) get_option('sl_deepl_api_key', '');
    }

    $api_key = trim($api_key);
    if ($api_key === '') {
        return 'https://api-free.deepl.com/v2';
    }

    $is_free_key = substr($api_key, -3) === ':fx';
    return $is_free_key ? 'https://api-free.deepl.com/v2' : 'https://api.deepl.com/v2';
}

// Perform translation with DeepL API using wp_remote_post
function sl_deepl_translate_text($text, $translate_to_lang, $translate_from_lang, &$error_message = '') {
    $error_message = '';

    $api_key = trim((string) get_option('sl_deepl_api_key', ''));
    if ($api_key === '') {
        $error_message = 'DeepL API key is empty.';
        return null;
    }

    $endpoint = sl_deepl_get_api_base_url($api_key) . '/translate';

    // Prepare the request data
    $data = [
        'text' => $text,
        'target_lang' => $translate_to_lang,
    ];
    if (!empty($translate_from_lang)) {
        $data['source_lang'] = $translate_from_lang;
    }

    // Use wp_remote_post to send the request
    $response = wp_remote_post($endpoint, [
        'body' => $data,
        'headers' => [
            'Authorization' => 'DeepL-Auth-Key ' . $api_key,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'timeout' => 20,
    ]);

    // Check for errors in the response
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return null;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);

    // Get the response body
    $result = wp_remote_retrieve_body($response);

    // Decode the JSON response
    $json = json_decode($result, true);

    if ($status_code < 200 || $status_code >= 300) {
        if (is_array($json) && !empty($json['message'])) {
            $error_message = 'HTTP ' . $status_code . ': ' . $json['message'];
        } else {
            $error_message = 'HTTP ' . $status_code . ' returned by DeepL translate endpoint.';
        }
        return null;
    }

    // Check if the translation exists
    if (!is_array($json) || !isset($json['translations'][0]['text'])) {
        $error_message = 'DeepL did not return a translation text.';
        return null;
    }

    // Return the translated text or the original text if something goes wrong
    return $json['translations'][0]['text'] ?? $text;
}

// Get the translation languages from the DeepL API
function sl_deepl_get_language_names($no_parentheses = false, $type = 'target') {
    $json = sl_deepl_get_language_json($type);
    if (!is_array($json) || empty($json)) {
        return null;
    }

    // Remove parentheses and duplicate entries if no_parentheses is true
    if ($no_parentheses) {
        $names = array_map(function($lang) {
            return preg_replace('/\s*\(.*\)/', '', $lang['name']);
        }, $json);
        return array_values(array_unique($names));
    }

    // Map the response to just the names of the languages
    return array_map(function($lang) {
        return $lang['name'];
    }, $json);
}

// Get an array with keys as language codes and values as language names
function sl_deepl_get_language_codes($type = 'target') {
    $json = sl_deepl_get_language_json($type);
    if ($json === null) {
        return null;
    }

    return array_column($json, 'name', 'language');
}

// Get the full language json from the DeepL API
function sl_deepl_get_language_json($type = 'target', $force_refresh = false, &$error_message = '') {
    $error_message = '';
    $transient_key = 'sl_deepl_language_json_' . $type;
    $cached_json = $force_refresh ? false : get_transient($transient_key);

    if ($cached_json !== false) {
        return $cached_json;
    }

    $api_key = trim((string) get_option('sl_deepl_api_key', ''));
    if ($api_key === '') {
        $error_message = 'DeepL API key is empty.';
        return null;
    }

    $endpoint = sl_deepl_get_api_base_url($api_key) . '/languages';
    $url = add_query_arg('type', $type, $endpoint);

    // Use wp_remote_get() to make the API call
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'DeepL-Auth-Key ' . $api_key,
        ],
        'timeout' => 20,
    ]);

    // Check for errors
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return null;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);

    // Get the response body
    $response_body = wp_remote_retrieve_body($response);
    $json = json_decode($response_body, true);

    if ($status_code < 200 || $status_code >= 300) {
        if (is_array($json) && !empty($json['message'])) {
            $error_message = 'HTTP ' . $status_code . ': ' . $json['message'];
        } else {
            $error_message = 'HTTP ' . $status_code . ' returned by DeepL languages endpoint.';
        }
        return null;
    }

    if (!is_array($json) || empty($json)) {
        $error_message = 'DeepL languages endpoint returned an empty response.';
        return null;
    }

    // Cache the result for 24 hours
    set_transient($transient_key, $json, DAY_IN_SECONDS);

    return $json;
}

function sl_test_deepl_api_connection() {
    $api_key = trim((string) get_option('sl_deepl_api_key', ''));
    $result = [
        'ok' => false,
        'messages' => [],
        'details' => [
            'api_base_url' => sl_deepl_get_api_base_url($api_key),
            'key_suffix' => $api_key !== '' ? substr($api_key, -4) : '',
        ],
    ];

    if ($api_key === '') {
        $result['messages'][] = 'DeepL API key is empty.';
        return $result;
    }

    $language_error = '';
    $languages = sl_deepl_get_language_json('target', true, $language_error);
    if (!is_array($languages) || empty($languages)) {
        $result['messages'][] = 'Language list check failed: ' . ($language_error !== '' ? $language_error : 'Unknown error.');
        return $result;
    }
    $result['details']['target_language_count'] = count($languages);

    $translation_error = '';
    $translated = sl_deepl_translate_text('Hello world', 'DE', 'EN', $translation_error);
    if (!is_string($translated) || trim($translated) === '') {
        $result['messages'][] = 'Translation check failed: ' . ($translation_error !== '' ? $translation_error : 'Unknown error.');
        return $result;
    }

    $result['ok'] = true;
    $result['details']['sample_translation'] = $translated;
    $result['messages'][] = 'DeepL connection is working.';
    return $result;
}

// Test the DeepL API with a shortcode [sl_test_deepl_api]
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
add_shortcode('sl_test_deepl_api', 'sl_test_deepl_api_shortcode');
