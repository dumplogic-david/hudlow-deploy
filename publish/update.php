<?php
/**
 * ============================================================================
 *  PUBLISH-SIDE UPDATER  —  pulls the latest build from the CMS and deploys it
 * ============================================================================
 *
 *  WHAT IT REPLACES
 *    The old SVN-based obvious_remote_update.php (svn checkout + updateDirectory).
 *
 *  WHERE IT GOES
 *    ~/public_html/deploy/update.php   (web-reachable at https://SITE/deploy/update.php)
 *    Alongside it: remote_config.php   (your secrets — protected by .htaccess)
 *
 *  HOW IT WORKS  (triggered by the CMS pinging this URL with ?key=...)
 *    1. Verify the rotating trigger key.
 *    2. Download the build archive from the CMS over HTTPS (Basic Auth).
 *    3. Extract it to a staging dir.
 *    4. SAFETY: refuse to deploy an empty/garbage archive (never wipes a live site).
 *    5. Mirror staging -> LIVE_DIR (adds/updates/removes), protecting the machinery.
 *
 *  UNIVERSAL BY DESIGN
 *    Pure PHP. Needs only PHP 5.4+ with the Phar extension (on by default).
 *    No svn / rsync / zip / git required. curl, url-fopen, and shell tar are each
 *    tried in turn but none is mandatory.
 *
 *  MANUAL TEST (from SSH):
 *    php update.php "$(php -r 'echo md5(round(time()/1000)."YOUR_KEY");')"
 * ============================================================================
 */

@set_time_limit(0);
@ignore_user_abort(true);

$NOW = date('m-d-Y H:i:s');
require dirname(__FILE__) . '/remote_config.php';   // $KEY, $ARCHIVE_URL, $ARCHIVE_USER, $ARCHIVE_PASS, $LIVE_DIR, [$SENTINEL], [$EXCLUDES]

$HERE        = dirname(__FILE__);
$LOG_FILE    = $HERE . '/update.log';
$STAGING     = $HERE . '/staging';
$TMP_ARCHIVE = $HERE . '/build.tar.gz';
$SENTINEL    = isset($SENTINEL) ? $SENTINEL : 'index.php';   // must exist in a valid build; '' disables the check
$EXCLUDES    = isset($EXCLUDES) ? $EXCLUDES : array();

function logmsg($m) { global $LOG_FILE, $NOW; @file_put_contents($LOG_FILE, "[$NOW] $m\n", FILE_APPEND); }
function done($msg, $http) { if (php_sapi_name() !== 'cli') { http_response_code($http); } echo $msg . "\n"; exit; }

/* ---- 0. authorize (accept the current 1000s window and its neighbours for clock skew) ---- */
$got = isset($_GET['key']) ? $_GET['key'] : (isset($argv[1]) ? $argv[1] : '');
$ok  = false;
$t   = time();
foreach (array(0, -1000, 1000) as $off) {
    if ($got !== '' && $got === md5(round(($t + $off) / 1000) . $KEY)) { $ok = true; break; }
}
if (!$ok) { logmsg('Access denied: wrong key'); done('denied', 403); }

/* ---- guard against a dangerous LIVE_DIR ---- */
$LIVE_DIR = rtrim($LIVE_DIR, '/');
$bad = array('', '/', rtrim(getenv('HOME'), '/'), rtrim($HERE, '/'));
if (in_array($LIVE_DIR, $bad, true) || strlen($LIVE_DIR) < 4) {
    logmsg('ABORT: unsafe LIVE_DIR "' . $LIVE_DIR . '"'); done('bad LIVE_DIR', 500);
}

logmsg('Updating from ' . $ARCHIVE_URL . ' ...');

/* ---- 1. download ---- */
if (!http_download($ARCHIVE_URL, $ARCHIVE_USER, $ARCHIVE_PASS, $TMP_ARCHIVE)) {
    logmsg('ABORT: download failed'); done('download failed', 502);
}

/* ---- 2. extract to a fresh staging dir ---- */
rrmdir($STAGING);
@mkdir($STAGING, 0755, true);
if (!extract_targz($TMP_ARCHIVE, $STAGING)) {
    logmsg('ABORT: extract failed'); rrmdir($STAGING); @unlink($TMP_ARCHIVE); done('extract failed', 500);
}

/* ---- 3. SAFETY: never deploy an empty/garbage build ---- */
$count = count_files($STAGING);
$sentinel_ok = ($SENTINEL === '') || file_exists($STAGING . '/' . $SENTINEL);
if ($count < 1 || !$sentinel_ok) {
    logmsg("ABORT: unsafe build (files=$count, sentinel '$SENTINEL' " . ($sentinel_ok ? 'ok' : 'MISSING') . ') — live site untouched');
    rrmdir($STAGING); @unlink($TMP_ARCHIVE); done('unsafe build — refused', 409);
}

/* ---- 4. mirror staging -> live, protecting the deploy machinery ---- */
$protect = array_merge(
    array('deploy', 'staging', '.htaccess', '.htpasswd', '.well-known', 'cgi-bin'),
    $EXCLUDES
);
mirror($STAGING, $LIVE_DIR, $protect);

/* ---- 5. cleanup ---- */
rrmdir($STAGING);
@unlink($TMP_ARCHIVE);
logmsg("Done. Deployed $count files to $LIVE_DIR");
done('ok', 200);


/* ============================ helpers (pure PHP) ============================ */

function http_download($url, $user, $pass, $dest) {
    // (a) curl extension
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if ($fp) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            if ($user !== '') { curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass); }
            $ok = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); fclose($fp);
            if ($ok && $code < 400 && @filesize($dest) > 0) { return true; }
            @unlink($dest);
        }
    }
    // (b) streams (allow_url_fopen)
    if (ini_get('allow_url_fopen')) {
        $opts = array('http' => array('timeout' => 300, 'follow_location' => 1),
                      'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true));
        if ($user !== '') { $opts['http']['header'] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass); }
        $data = @file_get_contents($url, false, stream_context_create($opts));
        if ($data !== false && strlen($data) > 0) { @file_put_contents($dest, $data); return @filesize($dest) > 0; }
    }
    // (c) shell curl / wget
    $auth = $user !== '' ? ' -u ' . escapeshellarg($user . ':' . $pass) : '';
    @shell_exec('curl -fsSL' . $auth . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($dest) . ' 2>/dev/null');
    if (@filesize($dest) > 0) { return true; }
    $wauth = $user !== '' ? ' --user=' . escapeshellarg($user) . ' --password=' . escapeshellarg($pass) : '';
    @shell_exec('wget -q' . $wauth . ' -O ' . escapeshellarg($dest) . ' ' . escapeshellarg($url) . ' 2>/dev/null');
    return @filesize($dest) > 0;
}

function extract_targz($archive, $destDir) {
    if (class_exists('PharData')) {
        try {
            $p = new PharData($archive);
            $p->extractTo($destDir, null, true);   // handles .tar.gz transparently
            return count_files($destDir) > 0;
        } catch (Exception $e) { /* fall through */ }
    }
    @shell_exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($destDir) . ' 2>/dev/null');
    return count_files($destDir) > 0;
}

/* Mirror $src into $dst: copy/update everything from src, then delete anything
   in dst that isn't in src — EXCEPT top-level names in $protect. */
function mirror($src, $dst, $protect) {
    $src = rtrim($src, '/'); $dst = rtrim($dst, '/');
    @mkdir($dst, 0755, true);
    copy_tree($src, $dst);
    prune_tree($src, $dst, $dst, $protect);
}

function copy_tree($src, $dst) {
    $dh = @opendir($src); if (!$dh) { return; }
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        $s = $src . '/' . $e; $d = $dst . '/' . $e;
        if (is_dir($s)) {
            if (file_exists($d) && !is_dir($d)) { @unlink($d); }
            @mkdir($d, 0755, true);
            copy_tree($s, $d);
        } else {
            if (is_dir($d)) { rrmdir($d); }
            @copy($s, $d);
            $perm = @fileperms($s); if ($perm !== false) { @chmod($d, $perm & 0777); }
        }
    }
    closedir($dh);
}

function prune_tree($src, $dst, $liveRoot, $protect) {
    $dh = @opendir($dst); if (!$dh) { return; }
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        if ($dst === $liveRoot && in_array($e, $protect, true)) { continue; }
        $s = $src . '/' . $e; $d = $dst . '/' . $e;
        if (!file_exists($s)) {
            if (is_dir($d)) { rrmdir($d); } else { @unlink($d); }
        } else if (is_dir($d) && is_dir($s)) {
            prune_tree($s, $d, $liveRoot, $protect);
        }
    }
    closedir($dh);
}

function count_files($dir) {
    $n = 0; $dh = @opendir($dir); if (!$dh) { return 0; }
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        $n++; if (is_dir($dir . '/' . $e)) { $n += count_files($dir . '/' . $e); }
    }
    closedir($dh); return $n;
}

function rrmdir($dir) {
    if (!file_exists($dir)) { return; }
    if (!is_dir($dir)) { @unlink($dir); return; }
    $dh = @opendir($dir); if (!$dh) { return; }
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        if (is_dir($p)) { rrmdir($p); } else { @unlink($p); }
    }
    closedir($dh); @rmdir($dir);
}
