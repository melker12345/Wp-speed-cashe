Installation
As a Standard Plugin:

    Upload the plugin to /wp-content/plugins/.
    Activate it from Plugins > Installed Plugins.
    Configure settings in Settings > Lock Site.

As a Must-Use Plugin:

    Place lock-site.php in /wp-content/mu-plugins/.
    The plugin will activate automatically.

How It Works

    Locks the site if critical files are modified or if the modification date expires.

    Unlocks the site using a secret hash via URL:

yoursite.com/?unlock=YOUR_SECRET_HASH

Updates security checksums using:

    yoursite.com/?update_checksums=YOUR_SECRET_HASH

    Runs file integrity checks and detects system time rollbacks to prevent security breaches.

Advanced Security Features

    Expands file integrity checks to additional files:
        wp-config.php
        lock-site.php
        index.php
        wp-load.php

    Stores two copies of checksums (site_lock_checksums and site_lock_checksums_backup) to prevent tampering.

    Uses WordPress’s built-in admin_post hooks for secure requests:
        admin_post_lock_site_toggle
        admin_post_lock_site_update_checksums

    Provides a more secure and flexible way to lock/unlock the site and update security settings.

Usage

    Set the expected hash and modification date in the settings.
    Once configured, the settings page disappears to enhance security.
    To modify security settings, unlock the site using the secret URL and update settings.
