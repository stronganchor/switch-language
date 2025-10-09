<?php
/**
 * Plugin Name: Anchor
 * Plugin URI: https://stronganchortech.com
 * Description: Custom tools for managing Strong Anchor Tech's WordPress sites
 * Author: Strong Anchor Tech
 * Version: 1.1.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the plugin update checker
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use Duplicator\Utils\Email\EmailSummary;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/stronganchor/anchor-plugin', // GitHub repository URL
    __FILE__,                                         // Full path to the main plugin file
    'anchor-plugin'                                   // Plugin slug
);

// If the site has defined ANCHOR_GITHUB_TOKEN, use it to authenticate:
if ( defined( 'ANCHOR_GITHUB_TOKEN' ) && ANCHOR_GITHUB_TOKEN ) {
    $myUpdateChecker->setAuthentication( ANCHOR_GITHUB_TOKEN );
}

// Set the branch to "main"
$myUpdateChecker->setBranch( 'main' );

// -----------------------------------------------------------------------------
// ** Admin Menu & Page **
// -----------------------------------------------------------------------------

// Add the "Anchor" top-level menu
function anchor_add_admin_page() {
    add_menu_page(
        'Anchor',                 // Page title
        'Anchor',                 // Menu title
        'manage_options',         // Capability
        'anchor-plugin',          // Menu slug
        'anchor_admin_page',      // Callback function
        'dashicons-admin-site-alt3' // Icon (closest available to a sea anchor)
    );
}
add_action( 'admin_menu', 'anchor_add_admin_page' );

// Admin page content with buttons for various tools, including disabling pingbacks/trackbacks
function anchor_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>Anchor Admin Tools</h1>';

    // Ensure nonces for form security
    $nonce_action = 'anchor_admin_actions';
    $nonce_name   = 'anchor_admin_nonce';

    // Style buttons to sit inline with spacing
    echo '<style>
        .anchor-button-wrapper form {
            display: inline-block;
            margin-right: 20px;
        }
    </style>';

    echo '<div class="anchor-button-wrapper">';

    // 1) Permalink flush button
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="flush_permalinks">';
    echo '<input type="submit" class="button button-primary" value="Flush Permalinks Now">';
    echo '</form>';

    // 2) Error reporting toggle button
    $error_reporting_enabled = get_option( 'anchor_error_reporting_enabled' ) === '1';
    $error_label            = $error_reporting_enabled
        ? 'Disable Error Reporting for Admins'
        : 'Enable Error Reporting for Admins';
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="toggle_error_reporting">';
    echo '<input type="submit" class="button button-primary" value="' . esc_attr( $error_label ) . '">';
    echo '</form>';

    // 3) Disable Pingbacks & Trackbacks button
    $pings_disabled = get_option( 'anchor_disable_pings_enabled' ) === '1';
    $pings_label    = $pings_disabled
        ? 'Pingbacks/Trackbacks Already Disabled'
        : 'Disable Pingbacks & Trackbacks';
    // If already disabled, disable the button
    $button_attr = $pings_disabled ? 'disabled' : '';
    echo '<form method="post" action="">';
    wp_nonce_field( $nonce_action, $nonce_name );
    echo '<input type="hidden" name="anchor_action" value="disable_pings">';
    echo '<input type="submit" class="button button-primary" value="' . esc_attr( $pings_label ) . '" ' . $button_attr . '>';
    echo '</form>';

    echo '</div>'; // End button wrapper

    // Handle submitted actions
    if ( isset( $_POST['anchor_action'] ) && check_admin_referer( $nonce_action, $nonce_name ) ) {
        switch ( sanitize_text_field( $_POST['anchor_action'] ) ) {

            case 'flush_permalinks':
                anchor_flush_permalinks();
                break;

            case 'toggle_error_reporting':
                $new_status = $error_reporting_enabled ? '0' : '1';
                update_option( 'anchor_error_reporting_enabled', $new_status );
                echo '<div class="notice notice-success"><p>Error reporting has been '
                     . ( $new_status === '1' ? 'enabled' : 'disabled' )
                     . ' for admins.</p></div>';
                break;

            case 'disable_pings':
                if ( ! $pings_disabled ) {
                    update_option( 'anchor_disable_pings_enabled', '1' );
                    echo '<div class="notice notice-success"><p>Pingbacks and trackbacks have been disabled site-wide.</p></div>';
                    // Optionally, also disable for existing posts by updating options
                    update_option( 'default_ping_status', 'closed' );
                    update_option( 'default_pingback_flag', '0' );
                }
                break;
        }
    }

    echo '</div>';
}

// Function to flush permalinks and show a success notice
function anchor_flush_permalinks() {
    flush_rewrite_rules( true );
    echo '<div class="notice notice-success"><p>Permalinks have been flushed successfully.</p></div>';
}

// -----------------------------------------------------------------------------
// ** Error Reporting Control **
// -----------------------------------------------------------------------------

// Dynamically control PHP error reporting based on the admin setting
function anchor_set_error_reporting() {
    if ( get_option( 'anchor_error_reporting_enabled' ) === '1' ) {
        if ( current_user_can( 'administrator' ) && is_user_logged_in() ) {
            // Show all PHP errors to admins
            error_reporting( E_ALL );
            @ini_set( 'display_errors', 1 );
        } else {
            // Hide errors for non-admins or guests
            error_reporting( 0 );
            @ini_set( 'display_errors', 0 );
        }
    } else {
        // If the feature is “off,” hide errors universally
        error_reporting( 0 );
        @ini_set( 'display_errors', 0 );
    }
}
add_action( 'init', 'anchor_set_error_reporting' );

// -----------------------------------------------------------------------------
// ** Pingbacks & Trackbacks Disabling **
// -----------------------------------------------------------------------------

// On every page load, if the “disable pings” option is set, apply filters to block them.
function anchor_disable_pings_apply() {
    if ( get_option( 'anchor_disable_pings_enabled' ) === '1' ) {

        // 1) Force new posts to have pingbacks/trackbacks off
        add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
        add_filter( 'pre_option_default_ping_status', '__return_zero' );

        // 2) Disable XML-RPC pingback.ping method so remote sites cannot send pingbacks
        add_filter( 'xmlrpc_methods', function( $methods ) {
            if ( isset( $methods['pingback.ping'] ) ) {
                unset( $methods['pingback.ping'] );
            }
            return $methods;
        });

        // 3) Prevent self-pings (WP sending a trackback to itself)
        add_action( 'pre_ping', function( &$links ) {
            $home_url = get_option( 'home' );
            foreach ( $links as $l => $link ) {
                if ( 0 === strpos( $link, $home_url ) ) {
                    unset( $links[ $l ] );
                }
            }
        });

        // 4) Remove the “X-Pingback” HTTP header so bots can’t easily find the XML-RPC endpoint
        add_filter( 'wp_headers', function( $headers ) {
            if ( isset( $headers['X-Pingback'] ) ) {
                unset( $headers['X-Pingback'] );
            }
            return $headers;
        });

        // 5) Disable XML-RPC entirely to prevent any pingback calls
        add_filter( 'xmlrpc_enabled', '__return_false' );
    }
}
add_action( 'init', 'anchor_disable_pings_apply' );

/**
 * Force‐disable Duplicator Pro email summaries:
 *  - Overrides any submitted frequency to "never"
 *  - Clears the scheduled cron
 *  - Persists "never" via the Global Entity (SnapIO)
 */
function anchor_force_disable_duplicator_summaries() {
    // Bail if Duplicator Pro isn’t loaded or the classes aren’t available
    if (
        ! defined( 'DUPLICATOR_PRO_VERSION' )
        || ! class_exists( EmailSummary::class )
        || ! class_exists( 'DUP_PRO_Global_Entity' )
    ) {
        return;
    }

    // 1) If the Settings page is submitting a frequency, force it to 'never'
    if ( isset( $_REQUEST['_email_summary_frequency'] ) ) {
        $_REQUEST['_email_summary_frequency'] = EmailSummary::SEND_FREQ_NEVER;
        $_POST   ['_email_summary_frequency'] = EmailSummary::SEND_FREQ_NEVER;
    }

    // 2) Unschedule any pending summary email
    wp_clear_scheduled_hook( 'duplicator_weekly_summary' );

    // 3) Programmatically set + save "never" in Duplicator Pro’s Global Entity
    /** @var \DUP_PRO_Global_Entity $global */
    $global = \DUP_PRO_Global_Entity::getInstance();
    $global->setEmailSummaryFrequency( EmailSummary::SEND_FREQ_NEVER );
    $global->save();
}
add_action( 'admin_init', 'anchor_force_disable_duplicator_summaries', 1 );

// -----------------------------------------------------------------------------
// ** Activation & Deactivation Hooks **
// -----------------------------------------------------------------------------

/**
 * On plugin activation: clear cron and force “never” for Duplicator Pro summaries.
 */
function anchor_activate() {
    // Flush permalinks
    flush_rewrite_rules( true );
    // Disable any Duplicator Pro weekly summary immediately
    anchor_force_disable_duplicator_summaries();
    // Default our own features off
    update_option( 'anchor_error_reporting_enabled', '0' );
    update_option( 'anchor_disable_pings_enabled',   '0' );
}
register_activation_hook( __FILE__, 'anchor_activate' );

/**
 * On Anchor plugin upgrade: rerun the disable routine so you don’t have to visit wp‑admin.
 */
add_action( 'upgrader_process_complete', function( $upgrader, $hook_data ) {
    if (
        isset( $hook_data['action'], $hook_data['type'], $hook_data['plugins'] )
        && $hook_data['action']  === 'update'
        && $hook_data['type']    === 'plugin'
        && in_array( plugin_basename( __FILE__ ), (array) $hook_data['plugins'], true )
    ) {
        anchor_force_disable_duplicator_summaries();
    }
}, 10, 2 );

function anchor_deactivate() {
    // Flush permalinks again when deactivating
    flush_rewrite_rules( true );
    // Clean up our options
    delete_option( 'anchor_error_reporting_enabled' );
    delete_option( 'anchor_disable_pings_enabled' );
}
register_deactivation_hook( __FILE__, 'anchor_deactivate' );
