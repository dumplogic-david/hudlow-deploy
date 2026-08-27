<?php
/**
 * ============================================================================
 *  CMS-SIDE EXPORT / PUBLISH  —  drop-in replacement for the old SVN export.php
 * ============================================================================
 *
 *  WHERE IT GOES
 *    Your CMS admin directory, next to config.php / cache/ / updateDirectory.bash
 *    (the installer backs up your old export.php first).
 *
 *  WHAT CHANGED vs the SVN version
 *    - The cache -> build step is UNCHANGED.
 *    - The MySQL dump now goes to a PRIVATE, non-web backups/ dir (gzip'd) instead
 *      of into build/, so the sensitive dump is never shipped or web-exposed.
 *    - The SVN commit + single remote ping are replaced by:
 *        * package build/ -> builds/build-<rev>.tar.gz  (+ a stable latest.tar.gz)
 *        * keep the last N archives as rollback history; prune the rest
 *        * auto-provision Basic Auth (.htpasswd) on builds/
 *        * ping EVERY publish target so each pulls the new build
 *
 *  UNIVERSAL BY DESIGN
 *    Archiving/HTTP are pure PHP (Phar + curl/streams fallbacks). The cache->build
 *    step keeps its existing shell usage, which already works on this host.
 *
 *  RUN IT
 *    Via the CMS admin (as before), or from SSH:  php export.php
 * ============================================================================
 */

@set_time_limit(0);
include_once("config.php");

/* Auth for web use; allow CLI runs for testing/cron. */
if (php_sapi_name() !== 'cli') {
    session_start();
    if (!(isset($_SESSION['password']) && $_SESSION['password'] == $config['admin_password'])) {
        include('authorize.php');
    }
}
global $config;

/* ---- build config (unchanged) ---- */
$BUILD_DIR  = "../build/";
$REVISION   = date("d-m-Y-G:i");
$LOG_FILE   = "../build-$REVISION.log";
$EXTRAS_DIR = "../extras/";
$CACHE_DIR  = "../cache/";
$IMAGES_DIR = "../images/";

/* ---- transport config (see config.additions.php) ---- */
$BUILDS_DIR   = isset($config['builds_dir'])      ? $config['builds_dir']       : "../builds/";     // WEB-served, Basic-Auth protected
$BACKUPS_DIR  = isset($config['backups_dir'])     ? $config['backups_dir']      : "../../backups/"; // NON-web (outside public_html)
$KEEP_BUILDS  = isset($config['keep_builds'])     ? (int)$config['keep_builds'] : 20;
$TARGETS      = isset($config['publish_targets']) ? $config['publish_targets']  : array();
$REMOTE_KEY   = $config['remote_key'];
$ARCHIVE_USER = isset($config['archive_user'])    ? $config['archive_user']     : 'deploy';
$ARCHIVE_PASS = isset($config['archive_pass'])    ? $config['archive_pass']     : '';

/* ============================ 1. build (unchanged) ============================ */
shell_exec("rm -Rf $BUILD_DIR");
shell_exec("mkdir $BUILD_DIR");

$list  = shell_exec("ls $CACHE_DIR\\|*");
$list  = str_replace($CACHE_DIR, '', $list);
$files = explode("\n", $list);

foreach ($files as $file) {
    $file = trim($file);
    if ($file == "") { continue; }
    $new_file = $file;
    $file = str_replace("|", "\\|", $file);
    $new_file = str_replace("|", "/", $new_file);
    if (substr_count($new_file, '.') > 1) {
        $new_file = substr($new_file, 0, strrpos($new_file, '/'));
    } else {
        $new_file = str_replace(".php", "index.php", $new_file);
    }
    $new_file = $BUILD_DIR . $new_file;
    $new_file_dir = substr($new_file, 0, strrpos($new_file, '/'));
    if ($new_file != '') { shell_exec("mkdir -p $new_file_dir"); }
    shell_exec("cp $CACHE_DIR$file $new_file");
}

shell_exec("mkdir $BUILD_DIR/images");
shell_exec("./updateDirectory.bash $IMAGES_DIR $BUILD_DIR/images >> $LOG_FILE");
shell_exec("yes n | cp -iR $EXTRAS_DIR/* $BUILD_DIR >> $LOG_FILE");

/* ============ 2. DB dump -> PRIVATE backup (was build/database.sql) ============ */
@mkdir($BACKUPS_DIR, 0700, true);
ensure_deny_htaccess($BACKUPS_DIR);   // belt-and-suspenders if it somehow sits under a docroot
$dbfile = rtrim($BACKUPS_DIR, '/') . "/db-$REVISION.sql";
$dump = "mysqldump -u " . escapeshellarg($config['db_user'])
      . " -p" . escapeshellarg($config['db_password'])
      . " -h " . escapeshellarg($config['db_host'])
      . " " . escapeshellarg($config['db_name']);
@shell_exec($dump . " > " . escapeshellarg($dbfile) . " 2>> " . escapeshellarg($LOG_FILE));
if (@filesize($dbfile) > 0) { @shell_exec("gzip -f " . escapeshellarg($dbfile) . " 2>/dev/null"); }  // best-effort compress
prune_old($BACKUPS_DIR, 'db-', '', $KEEP_BUILDS);

/* ================ 3. package build/ -> tar.gz (pure PHP, universal) ================ */
@mkdir($BUILDS_DIR, 0755, true);
ensure_basic_auth($BUILDS_DIR, $ARCHIVE_USER, $ARCHIVE_PASS);
$archive = rtrim($BUILDS_DIR, '/') . "/build-$REVISION.tar.gz";
if (make_targz($BUILD_DIR, $archive)) {
    @copy($archive, rtrim($BUILDS_DIR, '/') . "/latest.tar.gz");   // stable URL for pullers
    prune_old($BUILDS_DIR, 'build-', '.tar.gz', $KEEP_BUILDS);
} else {
    echo "ERROR: could not build archive\n";
}

/* ======================= 4. trigger every publish target ======================= */
$key = md5(round(time() / 1000) . $REMOTE_KEY);
foreach ($TARGETS as $url) {
    $ping = $url . (strpos($url, '?') === false ? '?' : '&') . "key=" . $key;
    if (php_sapi_name() === 'cli') { echo "ping: $url\n"; } else { echo "<br />Updating... " . htmlspecialchars($url); }
    http_get($ping);
}

/* ---- return the admin to where they came from (web only) ---- */
if (php_sapi_name() !== 'cli') {
    if (empty($_SERVER["HTTP_REFERER"])) { $_SERVER["HTTP_REFERER"] = "/"; }
    header("Location: " . $_SERVER["HTTP_REFERER"]);
}


/* ============================ helpers (pure PHP) ============================ */

function make_targz($srcDir, $destTarGz) {
    $srcDir = rtrim($srcDir, '/');
    if (class_exists('PharData')) {
        $tar = preg_replace('/\.gz$/', '', $destTarGz);   // build-REV.tar
        @unlink($tar); @unlink($destTarGz);
        try {
            $p = new PharData($tar);
            $p->buildFromDirectory($srcDir);
            $p->compress(Phar::GZ);          // writes build-REV.tar.gz
            unset($p);
            @unlink($tar);
            if (file_exists($destTarGz)) { return true; }
        } catch (Exception $e) { /* fall through */ }
        @unlink($tar);
    }
    @shell_exec("tar -czf " . escapeshellarg($destTarGz) . " -C " . escapeshellarg($srcDir) . " . 2>/dev/null");
    return file_exists($destTarGz);
}

/* Keep the newest $keep files matching prefix/suffix (by mtime); delete the rest.
   mtime is used because the d-m-Y-G:i timecode does not sort chronologically. */
function prune_old($dir, $prefix, $suffix, $keep) {
    $items = array();
    foreach ((array)@scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        if ($prefix !== '' && strpos($f, $prefix) !== 0) { continue; }
        if ($suffix !== '' && substr($f, -strlen($suffix)) !== $suffix) { continue; }
        $items[$f] = @filemtime(rtrim($dir, '/') . '/' . $f);
    }
    arsort($items);   // newest first
    $i = 0;
    foreach ($items as $f => $m) {
        $i++;
        if ($i > $keep) { @unlink(rtrim($dir, '/') . '/' . $f); }
    }
}

function ensure_basic_auth($dir, $user, $pass) {
    if ($pass === '') { return; }
    $dir = rtrim($dir, '/');
    $htpw = $dir . '/.htpasswd';
    $htac = $dir . '/.htaccess';
    if (!file_exists($htpw)) {
        $hash = function_exists('password_hash') ? password_hash($pass, PASSWORD_BCRYPT) : crypt($pass);
        @file_put_contents($htpw, $user . ':' . $hash . "\n");
        @chmod($htpw, 0644);
    }
    if (!file_exists($htac)) {
        $abs = realpath($htpw); if ($abs === false) { $abs = $htpw; }
        $rules  = "AuthType Basic\n";
        $rules .= "AuthName \"Restricted\"\n";
        $rules .= "AuthUserFile " . $abs . "\n";
        $rules .= "Require valid-user\n";
        @file_put_contents($htac, $rules);
    }
}

function ensure_deny_htaccess($dir) {
    $dir = rtrim($dir, '/');
    $htac = $dir . '/.htaccess';
    if (!file_exists($htac)) {
        // Works on Apache 2.2 and 2.4
        $rules  = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
        $rules .= "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
        @file_put_contents($htac, $rules);
    }
}

function http_get($url, $user = '', $pass = '') {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        if ($user !== '') { curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass); }
        $r = curl_exec($ch); curl_close($ch);
        if ($r !== false) { return $r; }
    }
    if (ini_get('allow_url_fopen')) {
        $opts = array('http' => array('timeout' => 120));
        if ($user !== '') { $opts['http']['header'] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass); }
        $r = @file_get_contents($url, false, stream_context_create($opts));
        if ($r !== false) { return $r; }
    }
    $auth = $user !== '' ? ' -u ' . escapeshellarg($user . ':' . $pass) : '';
    return @shell_exec('curl -fsSL' . $auth . ' ' . escapeshellarg($url) . ' 2>/dev/null');
}
