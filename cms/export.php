<?php
/**
 * obvious/export.php — build + PUBLISH (tarball edition; replaces the dead SVN tail)
 *
 * Drop-in for the CMS's obvious/export.php. The cache->build logic is UNCHANGED.
 * Only the publish tail changed:
 *   - the MySQL dump now goes to a PRIVATE, non-web backups dir (was shipped in the build)
 *   - the svn commit + single remote ping are replaced by: package build/ -> a
 *     Basic-Auth-protected tarball, keep the last N as history, and ping every
 *     publish target so each pulls the new build.
 *
 * Reads deploy settings from obvious/deploy.conf.php (keys/targets). Pure PHP;
 * needs only PHP 5.4+ with Phar (default). No svn / rsync / zip required.
 */

include_once("config.php");
@include(dirname(__FILE__) . "/deploy.conf.php");   // $config['remote_key','archive_*','publish_targets',...]

session_start();
if (isset($_SESSION['password']) && $_SESSION['password'] == $config['admin_password']) {
        // Good.
} else {
        include('authorize.php');
}
global $config;

/* Constants */
$BUILD_DIR = "../build/";
$REVISION = date("d-m-Y-G:i");
$LOG_FILE = "../build-$REVISION.log";

$EXTRAS_DIR = "../extras/";
$CACHE_DIR = "../cache/";
$IMAGES_DIR = "../images/";

/* Deploy settings (paths resolved from THIS file so they don't depend on cwd) */
$HERE         = dirname(__FILE__);
$BUILDS_DIR   = isset($config['builds_dir'])      ? $config['builds_dir']       : $HERE."/../builds/";        // web-served: <cms-host>/builds/
$BACKUPS_DIR  = isset($config['backups_dir'])     ? $config['backups_dir']      : $HERE."/../../../backups/"; // private, outside web root
$KEEP         = isset($config['keep_builds'])     ? (int)$config['keep_builds'] : 20;
$TARGETS      = isset($config['publish_targets']) ? $config['publish_targets']  : array();
$REMOTE_KEY   = isset($config['remote_key'])      ? $config['remote_key']       : '';
$ARCHIVE_USER = isset($config['archive_user'])    ? $config['archive_user']     : 'deploy';
$ARCHIVE_PASS = isset($config['archive_pass'])    ? $config['archive_pass']     : '';

/* Clear build directory for a clean build */
shell_exec("rm -Rf $BUILD_DIR");
shell_exec("mkdir $BUILD_DIR");

/* Get list of files */
$list = shell_exec("ls $CACHE_DIR\\|*");
$list = str_replace($CACHE_DIR, '', $list);
$files = explode("\n", $list);

/* For each file... */
foreach ($files as $file) {
  /* Format file name using cached name as basis */
  $file = trim($file);
  if ($file == "")
    continue;
  $new_file = $file;
  /* Escape | (for command) and convert to / */
  $file = str_replace("|", "\\|", $file);
  $new_file = str_replace("|", "/", $new_file);
  /* Convert php files to index.php, otherwise, strip .php extension */
  if (substr_count($new_file,'.') > 1) {
    $new_file = substr($new_file,0,strrpos($new_file,'/'));
  } else {
    $new_file = str_replace(".php", "index.php", $new_file);
  }
  /* Moving to revision/ */
  $new_file = $BUILD_DIR.$new_file;
  $new_file_dir = substr($new_file,0,strrpos($new_file,'/'));

  /* Move to build location */
  if ($new_file != '')
    shell_exec("mkdir -p $new_file_dir");
  shell_exec("cp $CACHE_DIR$file $new_file");

}

/* Move images to build location */
shell_exec("mkdir $BUILD_DIR/images");
shell_exec("./updateDirectory.bash $IMAGES_DIR $BUILD_DIR/images >> $LOG_FILE");
/* Move extras to build location */
shell_exec("yes n | cp -iR $EXTRAS_DIR/* $BUILD_DIR >> $LOG_FILE");

/* Dump database to a PRIVATE backup (was: $BUILD_DIR/database.sql, which got shipped) */
@mkdir($BACKUPS_DIR, 0700, true);
export_deny_htaccess($BACKUPS_DIR);
$dbfile = rtrim($BACKUPS_DIR,'/')."/db-$REVISION.sql";
shell_exec("mysqldump -u ".escapeshellarg($config['db_user'])." --password=".escapeshellarg($config['db_password'])
         ." -h ".escapeshellarg($config['db_host'])." ".escapeshellarg($config['db_name'])
         ." > ".escapeshellarg($dbfile)." 2>> ".escapeshellarg($LOG_FILE));
if (@filesize($dbfile) > 0) shell_exec("gzip -f ".escapeshellarg($dbfile)." 2>/dev/null");
export_prune($BACKUPS_DIR, 'db-', '', $KEEP);

/* Package build/ -> a Basic-Auth-protected tarball the publish sites pull */
@mkdir($BUILDS_DIR, 0755, true);
export_basic_auth($BUILDS_DIR, $ARCHIVE_USER, $ARCHIVE_PASS);
$archive = rtrim($BUILDS_DIR,'/')."/build-$REVISION.tar.gz";
if (export_targz($BUILD_DIR, $archive)) {
    @copy($archive, rtrim($BUILDS_DIR,'/')."/latest.tar.gz");
    export_prune($BUILDS_DIR, 'build-', '.tar.gz', $KEEP);
} else {
    echo "ERROR: could not build archive<br />";
}

/* Trigger each publish site to pull the new build */
$key = md5(round(time()/1000).$REMOTE_KEY);
foreach ($TARGETS as $url) {
    $ping = $url.(strpos($url,'?')===false?'?':'&')."key=".$key;
    echo "<br />Updating... ".htmlspecialchars($url);
    export_http_get($ping);
}

if (!isset($_SERVER["HTTP_REFERER"]) || !$_SERVER["HTTP_REFERER"])
  $_SERVER["HTTP_REFERER"] = "/obvious/page/";

header($_SERVER["HTTP_REFERER"]);


/* ============================ helpers (top-level so they hoist) ============================ */

function export_targz($srcDir, $destTarGz) {
    $srcDir = rtrim($srcDir, '/');
    if (class_exists('PharData')) {
        $tar = preg_replace('/\.gz$/', '', $destTarGz);
        @unlink($tar); @unlink($destTarGz);
        try {
            $p = new PharData($tar);
            $p->buildFromDirectory($srcDir);
            $p->compress(Phar::GZ);
            unset($p);
            @unlink($tar);
            if (file_exists($destTarGz)) return true;
        } catch (Exception $e) { @unlink($tar); }
    }
    @shell_exec("tar -czf ".escapeshellarg($destTarGz)." -C ".escapeshellarg($srcDir)." . 2>/dev/null");
    return file_exists($destTarGz);
}

function export_prune($dir, $prefix, $suffix, $keep) {
    $items = array();
    foreach ((array)@scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if ($prefix !== '' && strpos($f, $prefix) !== 0) continue;
        if ($suffix !== '' && substr($f, -strlen($suffix)) !== $suffix) continue;
        $items[$f] = @filemtime(rtrim($dir,'/').'/'.$f);
    }
    arsort($items);
    $i = 0;
    foreach ($items as $f => $m) { $i++; if ($i > $keep) @unlink(rtrim($dir,'/').'/'.$f); }
}

function export_basic_auth($dir, $user, $pass) {
    if ($pass === '') return;
    $dir = rtrim($dir, '/');
    if (!file_exists($dir.'/.htpasswd')) {
        $hash = function_exists('password_hash') ? password_hash($pass, PASSWORD_BCRYPT) : crypt($pass);
        @file_put_contents($dir.'/.htpasswd', $user.':'.$hash."\n");
    }
    if (!file_exists($dir.'/.htaccess')) {
        $abs = realpath($dir.'/.htpasswd'); if ($abs === false) $abs = $dir.'/.htpasswd';
        @file_put_contents($dir.'/.htaccess',
            "AuthType Basic\nAuthName \"Restricted\"\nAuthUserFile ".$abs."\nRequire valid-user\n".
            "ErrorDocument 401 default\n");   // clean 401 instead of the host's broken custom error doc
    }
}

function export_deny_htaccess($dir) {
    $dir = rtrim($dir, '/');
    if (!file_exists($dir.'/.htaccess')) {
        @file_put_contents($dir.'/.htaccess',
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n".
            "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
}

function export_http_get($url, $user = '', $pass = '') {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        if ($user !== '') curl_setopt($ch, CURLOPT_USERPWD, $user.':'.$pass);
        $r = curl_exec($ch); curl_close($ch);
        if ($r !== false) return $r;
    }
    if (ini_get('allow_url_fopen')) {
        $opts = array('http' => array('timeout' => 120));
        if ($user !== '') $opts['http']['header'] = 'Authorization: Basic '.base64_encode($user.':'.$pass);
        $r = @file_get_contents($url, false, stream_context_create($opts));
        if ($r !== false) return $r;
    }
    $auth = $user !== '' ? ' -u '.escapeshellarg($user.':'.$pass) : '';
    return @shell_exec('curl -fsSL'.$auth.' '.escapeshellarg($url).' 2>/dev/null');
}
