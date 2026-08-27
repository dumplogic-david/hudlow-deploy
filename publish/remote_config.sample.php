<?php
/**
 * remote_config.php  —  PUBLISH-SIDE secrets  (edit, then it is read by update.php)
 * Location: ~/public_html/deploy/remote_config.php   (the .htaccess here blocks web access to it)
 *
 * The installer copies this sample to remote_config.php only if one does not
 * already exist, so re-running the bootstrap never clobbers your real secrets.
 */

// Rotating trigger secret. MUST be byte-for-byte equal to $config['remote_key'] on the CMS box.
$KEY = 'CHANGE-ME-shared-trigger-key';

// Where to pull the build archive from (the CMS box), plus its Basic-Auth login.
$ARCHIVE_URL  = 'https://YOUR-CMS-HOST/builds/latest.tar.gz';
$ARCHIVE_USER = 'deploy';
$ARCHIVE_PASS = 'CHANGE-ME-download-password';   // MUST equal $config['archive_pass'] on the CMS box

// Absolute path to the live site directory this host serves.
// This is the tree that gets mirrored (added/updated/removed). Keep it SEPARATE
// from this deploy/ folder so the machinery is never touched.
$LIVE_DIR = '/home/USER/public_html/dhudlow';

// A file that MUST exist in every valid build. If it is missing after extract,
// the deploy is refused and the live site is left untouched. Set '' to disable.
$SENTINEL = 'index.php';

// Extra top-level names in LIVE_DIR to NEVER delete during a mirror (optional).
// e.g. array('uploads', 'guestbook.dat') for runtime data the CMS doesn't own.
$EXCLUDES = array();
