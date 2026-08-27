<?php
/**
 * ============================================================================
 *  php8-migrate.php — find (and optionally auto-fix) PHP 8 incompatibilities
 * ============================================================================
 *  Your host dropped PHP 7, so the CMS has to run clean on PHP 8. This walks a
 *  codebase, flags what PHP 8 removed/changed, and can auto-apply the handful of
 *  fixes that are provably safe. It NEVER touches non-PHP files (no .htaccess).
 *
 *  SAFE BY DEFAULT — report only, no changes:
 *     php php8-migrate.php ~/public_html/cms
 *
 *  APPLY the known-safe fixes (writes <file>.bak first for every change):
 *     php php8-migrate.php ~/public_html/cms --fix
 *
 *  What it AUTO-FIXES (safe, mechanical):
 *     get_magic_quotes_gpc()      -> false   (removed in 8.0; has returned false since 5.4)
 *     get_magic_quotes_runtime()  -> false
 *     E_NONE                      -> 0       (never a real constant; means "no reporting")
 *
 *  Everything else is REPORTED with file:line for you to review — paste it back.
 * ============================================================================
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$root = '.';
$fix  = false;
for ($a = 1; $a < $argc; $a++) {
    if ($argv[$a] === '--fix') $fix = true;
    else if ($argv[$a] !== '') $root = $argv[$a];
}

if (!is_dir($root) && !is_file($root)) {
    fwrite(STDERR, "Not found: $root\n");
    exit(1);
}

/* Functions REMOVED in PHP 8.0 → fatal "Call to undefined function". */
$REMOVED = array(
    'get_magic_quotes_gpc','get_magic_quotes_runtime','set_magic_quotes_runtime',
    'create_function','each','ereg','eregi','ereg_replace','eregi_replace',
    'split','spliti','sql_regcase','money_format','convert_cyr_string','hebrevc',
    'fgetss','png2wbmp','jpeg2wbmp','image2wbmp','read_exif_data','__autoload',
    'mysql_connect','mysql_query','mysql_fetch_array','mysql_num_rows','mysql_select_db',
    'restore_include_path','gmp_random','ldap_control_paged_result',
);
/* Deprecated in 8.x → still runs, but will bite later. Review. */
$DEPRECATED = array(
    'utf8_encode','utf8_decode','strftime','gmstrftime','strptime',
    'date_sunrise','date_sunset','mb_check_encoding_none',
);
/* Safe, mechanical text fixes applied only with --fix. */
$AUTOFIX = array(
    '/\bget_magic_quotes_gpc\s*\(\s*\)/i'     => 'false',
    '/\bget_magic_quotes_runtime\s*\(\s*\)/i' => 'false',
    '/\bE_NONE\b/'                            => '0',
);

$report = array();      // path => list of [line, level, msg]
$fixed  = array();      // path => count

function collect_files($root) {
    if (is_file($root)) return array($root);
    $out = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (preg_match('/\.(php|inc|phtml|php\d)$/i', $p)
            && strpos($p, '/.git/') === false
            && substr($p, -4) !== '.bak') {
            $out[] = $p;
        }
    }
    sort($out);
    return $out;
}

function scan_tokens($src, $removed, $deprecated) {
    $issues = array();
    $tokens = @token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        $name = strtolower($t[1]);
        $line = $t[2];

        // undefined-constant we know about
        if ($t[1] === 'E_NONE') { $issues[] = array($line, 'FIXABLE', 'undefined constant E_NONE (-> 0)'); continue; }

        // is this a function CALL (next real token '(') and not a method/static call?
        $j = $i + 1; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        $isCall = ($j < $n && $tokens[$j] === '(');
        if (!$isCall) continue;
        $k = $i - 1; while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
        if ($k >= 0 && is_array($tokens[$k]) && ($tokens[$k][0] === T_OBJECT_OPERATOR || $tokens[$k][0] === T_DOUBLE_COLON)) continue;
        if ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_FUNCTION) continue; // a declaration

        if (in_array($name, $removed, true)) {
            $auto = in_array($name, array('get_magic_quotes_gpc','get_magic_quotes_runtime'), true);
            $issues[] = array($line, $auto ? 'FIXABLE' : 'FATAL', $t[1].'() — removed in PHP 8.0');
        } else if (in_array($name, $deprecated, true)) {
            $issues[] = array($line, 'REVIEW', $t[1].'() — deprecated in PHP 8.x');
        }
    }
    return $issues;
}

function scan_regex($src) {
    $issues = array();
    $lines = explode("\n", $src);
    foreach ($lines as $idx => $ln) {
        $no = $idx + 1;
        if (preg_match('/\$[A-Za-z_]\w*\s*\{[^}]/', $ln))         $issues[] = array($no, 'REVIEW', 'possible curly-brace string/array offset $x{..} — removed in 8.0, use $x[..]');
        if (preg_match('/\(\s*unset\s*\)/', $ln))                  $issues[] = array($no, 'FATAL',  '(unset) cast — removed in 8.0');
        if (preg_match('/\bmysqli_(insert_id|error|errno|affected_rows|info|warning_count)\s*\(\s*\)/', $ln))
                                                                   $issues[] = array($no, 'FATAL',  'mysqli_*() called with no link — ArgumentCountError in 8.0; pass the connection, e.g. mysqli_insert_id($this->link)');
        if (preg_match('/\bimplode\s*\(\s*\$\w+\s*,\s*[\'"]/', $ln)) $issues[] = array($no, 'REVIEW', 'implode($array, $glue) argument order — reversed order removed in 8.0');
        if (preg_match('/"[^"]*\$\{[A-Za-z_]/', $ln))              $issues[] = array($no, 'REVIEW', '"${var}" string interpolation — deprecated in 8.2');
        if (preg_match('/\bFILTER_SANITIZE_STRING\b/', $ln))       $issues[] = array($no, 'REVIEW', 'FILTER_SANITIZE_STRING — deprecated in 8.1');
    }
    return $issues;
}

$files = collect_files($root);

foreach ($files as $path) {
    $src = file_get_contents($path);
    if ($src === false) continue;

    $issues = array_merge(scan_tokens($src, $REMOVED, $DEPRECATED), scan_regex($src));
    if ($issues) { usort($issues, function($a,$b){ return $a[0]-$b[0]; }); $report[$path] = $issues; }

    if ($fix) {
        $new = $src;
        foreach ($AUTOFIX as $re => $rep) $new = preg_replace($re, $rep, $new);
        if ($new !== $src) {
            @copy($path, $path.'.bak');
            file_put_contents($path, $new);
            $fixed[$path] = 1;
        }
    }
}

/* ---------------- output ---------------- */
$counts = array('FATAL'=>0,'FIXABLE'=>0,'REVIEW'=>0);
echo "\n=== PHP 8 compatibility scan: $root ===\n";
if (!$report) { echo "No known PHP 8 issues found.\n"; }
foreach ($report as $path => $issues) {
    echo "\n$path\n";
    foreach ($issues as $it) {
        list($line, $level, $msg) = $it;
        if (isset($counts[$level])) $counts[$level]++;
        printf("  %-7s line %-5d %s\n", $level, $line, $msg);
    }
}
echo "\n--- summary ---\n";
printf("  FATAL   (will crash on 8):        %d\n", $counts['FATAL']);
printf("  FIXABLE (auto-fixed with --fix):  %d\n", $counts['FIXABLE']);
printf("  REVIEW  (look into these):        %d\n", $counts['REVIEW']);
if ($fix) {
    echo "\n--- auto-fixed (".count($fixed)." files, .bak written) ---\n";
    foreach ($fixed as $p => $_) echo "  $p\n";
    if ($counts['FATAL'] > 0) echo "\n!! FATAL items above are NOT auto-fixed — they need a human. Paste them back.\n";
} else {
    echo "\nRun again with --fix to apply the FIXABLE ones (backups written). Paste the FATAL/REVIEW list.\n";
}
