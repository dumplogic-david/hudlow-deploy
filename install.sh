#!/usr/bin/env bash
# ============================================================================
#  hudlow deploy — interactive bootstrap
# ============================================================================
#  RUN IT INSIDE THE HOSTED CONTENT DIRECTORY (not your home folder):
#
#    # publish target — cd into the live site dir first:
#    cd ~/public_html/dhudlow
#    curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/install.sh | bash -s -- publish
#
#    # CMS box — cd into the dir that holds config.php:
#    cd ~/public_html/cms
#    curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/install.sh | bash -s -- cms
#
#  It asks a few questions (reading your terminal via /dev/tty even under
#  curl|bash) and writes the config for you — no file editing.
# ============================================================================
set -eu

RAW_BASE="${RAW_BASE:-https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main}"
SIDE="${1:-}"
TTY=/dev/tty

say()  { printf '%s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
rule() { say "------------------------------------------------------------"; }
have_tty() { (exec 3<"$TTY") 2>/dev/null; }   # actually try to OPEN the terminal, don't just stat it

# Escape a value for a single-quoted PHP string (handles \ and ').
php_quote() { local s="$1"; s="${s//\\/\\\\}"; s="${s//\'/\\\'}"; printf '%s' "$s"; }

ask() { # ask <prompt> <default>  -> echoes answer
  local p="$1" d="${2:-}" a=""
  if [ -n "$d" ]; then printf '%s [%s]: ' "$p" "$d" >"$TTY"; else printf '%s: ' "$p" >"$TTY"; fi
  IFS= read -r a <"$TTY" || a=""
  [ -z "$a" ] && a="$d"
  printf '%s' "$a"
}
ask_secret() { # ask_secret <prompt>  -> echoes answer, no on-screen echo
  local p="$1" a=""
  printf '%s: ' "$p" >"$TTY"
  stty -echo <"$TTY" 2>/dev/null || true
  IFS= read -r a <"$TTY" || a=""
  stty echo  <"$TTY" 2>/dev/null || true
  printf '\n' >"$TTY"
  printf '%s' "$a"
}

fetch() { # fetch <url> <dest>
  if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then wget -qO "$2" "$1"
  else die "need curl or wget on this host"; fi
}

# --------------------------------------------------------------------------
publish_side() {
  local DEST MDIR KEY AURL AUSER APASS LIVE SENT
  DEST="$PWD"
  MDIR="$DEST/deploy"
  mkdir -p "$MDIR/staging"
  fetch "$RAW_BASE/publish/update.php" "$MDIR/update.php"

  # Our own .htaccess, inside deploy/ — never collides with the site's root .htaccess.
  cat > "$MDIR/.htaccess" <<'HT'
<IfModule mod_authz_core.c>
  <FilesMatch "^(remote_config\.php|update\.log)$">
    Require all denied
  </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
  <FilesMatch "^(remote_config\.php|update\.log)$">
    Order allow,deny
    Deny from all
  </FilesMatch>
</IfModule>
RedirectMatch 404 /deploy/staging/
HT

  if [ -f "$MDIR/remote_config.php" ] && have_tty; then
    if [ "$(ask 'remote_config.php exists — keep it? (y/n)' 'y')" = "y" ]; then
      say "• Kept existing config."; publish_done "$DEST" "$MDIR" ""; return 0
    fi
  fi
  if ! have_tty; then
    fetch "$RAW_BASE/publish/remote_config.sample.php" "$MDIR/remote_config.php"
    say "• No terminal for the wizard — wrote sample $MDIR/remote_config.php to edit."
    publish_done "$DEST" "$MDIR" ""; return 0
  fi

  rule; say "PUBLISH setup — a few questions"; rule
  KEY="$(ask_secret 'Trigger key (identical on CMS + every target)')"
  AURL="$(ask 'Archive URL (the CMS builds/latest.tar.gz)' 'https://YOUR-CMS-HOST/builds/latest.tar.gz')"
  AUSER="$(ask 'Archive Basic-Auth user' 'deploy')"
  APASS="$(ask_secret 'Archive Basic-Auth password')"
  LIVE="$(ask 'Live site directory (what gets deployed)' "$DEST")"
  SENT="$(ask 'Sentinel file that must exist in a valid build' 'index.php')"
  {
    echo "<?php"
    echo "\$KEY          = '$(php_quote "$KEY")';"
    echo "\$ARCHIVE_URL  = '$(php_quote "$AURL")';"
    echo "\$ARCHIVE_USER = '$(php_quote "$AUSER")';"
    echo "\$ARCHIVE_PASS = '$(php_quote "$APASS")';"
    echo "\$LIVE_DIR     = '$(php_quote "$LIVE")';"
    echo "\$SENTINEL     = '$(php_quote "$SENT")';"
    echo "\$EXCLUDES     = array();"
  } > "$MDIR/remote_config.php"
  chmod 600 "$MDIR/remote_config.php" 2>/dev/null || true
  publish_done "$DEST" "$MDIR" "$LIVE"
}

publish_done() { # <dest> <mdir> <live>
  local DEST="$1" MDIR="$2" LIVE="${3:-$PWD}"
  rule
  say "PUBLISH installed:  $MDIR/update.php"
  say "Deploys into:       $LIVE"
  say "Endpoint URL:       https://<public url of $DEST>/deploy/update.php"
  say "  → add that URL to publish_targets on the CMS."
  rule
  say "Dry-run test (safe — a bad build aborts without touching the site):"
  say "  php \"$MDIR/update.php\" \"\$(php -r 'echo md5(round(time()/1000).\"YOURKEY\");')\""
  say "  tail \"$MDIR/update.log\""
}

# --------------------------------------------------------------------------
cms_side() {
  local DEST KEY AUSER APASS BUILDS BACKUPS KEEP t TARGETS
  DEST="$PWD"
  [ -f "$DEST/config.php" ] || say "!! No config.php in $DEST — are you in the CMS admin dir? Continuing anyway."
  if [ -f "$DEST/export.php" ]; then
    cp "$DEST/export.php" "$DEST/export.php.svn-backup"
    say "• Backed up old export.php -> export.php.svn-backup"
  fi
  fetch "$RAW_BASE/cms/export.php" "$DEST/export.php"

  if [ -f "$DEST/deploy.conf.php" ] && have_tty; then
    if [ "$(ask 'deploy.conf.php exists — keep it? (y/n)' 'y')" = "y" ]; then
      say "• Kept existing deploy config."; cms_done "$DEST"; return 0
    fi
  fi
  if ! have_tty; then
    fetch "$RAW_BASE/cms/config.additions.php" "$DEST/config.additions.php"
    say "• No terminal for the wizard — wrote config.additions.php to merge into config.php."
    cms_done "$DEST"; return 0
  fi

  rule; say "CMS setup — a few questions"; rule
  KEY="$(ask_secret 'Trigger key (identical on every target)')"
  AUSER="$(ask 'Archive Basic-Auth user' 'deploy')"
  APASS="$(ask_secret 'Archive Basic-Auth password')"
  BUILDS="$(ask 'Builds dir (UNDER public_html)' '../builds/')"
  BACKUPS="$(ask 'Backups dir (OUTSIDE public_html)' '../../backups/')"
  KEEP="$(ask 'How many builds/backups to keep' '20')"
  printf 'Publish target URLs — one per line, blank to finish:\n' >"$TTY" || true
  TARGETS=""
  while : ; do
    t="$(ask '  target URL' '')"
    [ -z "$t" ] && break
    TARGETS="${TARGETS}    '$(php_quote "$t")',
"
  done
  {
    echo "<?php"
    echo "/* Deploy settings written by the installer wizard; included by export.php after config.php. */"
    echo "\$config['remote_key']   = '$(php_quote "$KEY")';"
    echo "\$config['archive_user'] = '$(php_quote "$AUSER")';"
    echo "\$config['archive_pass'] = '$(php_quote "$APASS")';"
    echo "\$config['builds_dir']   = '$(php_quote "$BUILDS")';"
    echo "\$config['backups_dir']  = '$(php_quote "$BACKUPS")';"
    echo "\$config['keep_builds']  = $(printf '%d' "${KEEP:-20}" 2>/dev/null || echo 20);"
    echo "\$config['publish_targets'] = array("
    printf '%s' "$TARGETS"
    echo ");"
  } > "$DEST/deploy.conf.php"
  cms_done "$DEST"
}

cms_done() { # <dest>
  local DEST="$1"
  rule
  say "CMS installed in:  $DEST  (old export.php -> export.php.svn-backup)"
  say "Deploy settings:   $DEST/deploy.conf.php  (auto-loaded by export.php)"
  rule
  say "Publish once:  php export.php"
  say "Then verify:   curl -sI https://YOUR-CMS-HOST/builds/latest.tar.gz   (expect 401 = protected)"
}

# --------------------------------------------------------------------------
case "$SIDE" in
  publish) publish_side ;;
  cms)     cms_side ;;
  *)       die "usage: install.sh <cms|publish>   (run inside the hosted content dir)";;
esac
