<?php
/**
 * config.additions.php  —  merge these keys into the $config array in your CMS config.php.
 * (This file is just a reference; the installer drops it beside config.php. Copy the
 *  lines in, then delete it.)
 */

// Rotating trigger secret. MUST equal $KEY on every publish target's remote_config.php.
$config['remote_key'] = 'CHANGE-ME-shared-trigger-key';

// Basic-Auth login that protects the builds/ download dir (auto-provisioned on first run).
// archive_pass MUST equal $ARCHIVE_PASS on every publish target.
$config['archive_user'] = 'deploy';
$config['archive_pass'] = 'CHANGE-ME-download-password';

// WEB-served dir that holds the build archives (must live UNDER public_html so targets can
// download it). export.php auto-creates .htpasswd/.htaccess here.
$config['builds_dir'] = '../builds/';

// PRIVATE dir for the gzip'd MySQL backups. Put it OUTSIDE public_html. Never served, never shipped.
$config['backups_dir'] = '../../backups/';

// How many timecoded archives + db backups to retain (rollback history).
$config['keep_builds'] = 20;

// Every site that should receive this build. Add one line per host (Bluehost, DreamHost, ...).
$config['publish_targets'] = array(
    'https://hudlow.com/deploy/update.php',
    // 'https://another-site.com/deploy/update.php',
);
