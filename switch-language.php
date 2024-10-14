<?php
/**
 * Plugin Name: Switch Language
 * Plugin URI: https://example.com
 * Description: Automatically switches the WordPress site language based on the user's browser language setting.
 * Version: 1.0.0
 * Author: Strong Anchor Tech
 * Author URI: https://stronganchortech.com
 * License: GPL2 or later
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Switch the site language to match the browser's language
function switch_language() {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return;
    }

    // Get the list of available languages in your WordPress site
    $available_languages = get_available_languages(); // Returns an array of installed language codes (e.g., 'en_US')
	
    // Extract the preferred language from the browser
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2); // This gets the 2-letter language code
    
    // Convert browser language to WordPress format (e.g., 'en' to 'en_US')
    // This part may require customization based on your specific available languages and default language format
    switch ($browser_lang) {
        case 'en':
            $wp_lang = 'en_US';
            break;
        case 'tr':
            $wp_lang = 'tr_TR';
            break;
        // Add more cases as needed for your site's languages
        default:
            $wp_lang = ''; // Default language set in WordPress settings
            break;
    }
    
    // Check if the browser language is available in your site and switch
    if (in_array($wp_lang, $available_languages)) {
        switch_to_locale($wp_lang);
    }
}

// Hook the function to an action that runs early in the WordPress initialization process
add_action('init', 'switch_language');
