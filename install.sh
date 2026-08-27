#!/usr/bin/env bash
# ============================================================================
#  hudlow deploy — one-line bootstrap
# ============================================================================
#  Migrate a host off SVN and onto the pull-a-tarball deploy system.
#  The scripts it installs contain NO secrets, so hosting this publicly is safe.
#
#  RUN FROM YOUR ACCOUNT HOME (~). The installer finds public_html for you.
#
#  Publish target (any site that serves the built pages):
#     curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/install.sh | bash -s -- publish
#
#  CMS box (must be run IN your CMS admin dir, next to config.php):
#     cd ~/path/to/cms-admin
#     curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/install.sh | bash -s -- cms
#
#  Override where files are fetched from:  RAW_BASE=https://other/path ...
# ============================================================================
set -eu

RAW_BASE="${RAW_BASE:-https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main}"
SIDE="${1:-}"

say()  { printf '%s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
rule() { say "------------------------------------------------------------"; }

fetch() { # fetch <url> <dest>
  if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then wget -qO "$2" "$1"
  else die "need curl or wget on this host"; fi
}

find_webroot() {
  for d in "$HOME/public_html" "$HOME/www" "$PWD"; do
    [ -d "$d" ] && { printf '%s' "$d"; return; }
  done
  printf '%s' "$PWD"
}

case "$SIDE" in
  publish)
    WEBROOT="$(find_webroot)"
    DEST="$WEBROOT/deploy"
    mkdir -p "$DEST/staging"
    fetch "$RAW_BASE/publish/update.php" "$DEST/update.php"
    if [ -f "$DEST/remote_config.php" ]; then
      say "• Kept existing $DEST/remote_config.php (not overwritten)."
    else
      fetch "$RAW_BASE/publish/remote_config.sample.php" "$DEST/remote_config.php"
      say "• Wrote $DEST/remote_config.php  <-- EDIT THIS"
    fi
    # Serve update.php but hide secrets/logs/staging from the web (Apache 2.2 + 2.4)
    cat > "$DEST/.htaccess" <<'HT'
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
    rule
    say "PUBLISH side installed:  $DEST"
    say "Endpoint URL:            https://THIS-HOST/deploy/update.php"
    rule
    say "NEXT:"
    say "  1. Edit $DEST/remote_config.php"
    say "       - \$KEY            (same on CMS + every target)"
    say "       - \$ARCHIVE_URL    https://YOUR-CMS-HOST/builds/latest.tar.gz"
    say "       - \$ARCHIVE_USER / \$ARCHIVE_PASS   (the builds/ Basic-Auth login)"
    say "       - \$LIVE_DIR       absolute path to the served site dir"
    say "  2. Add this endpoint to publish_targets on the CMS box."
    say "  3. Dry test from SSH:"
    say "       php $DEST/update.php \"\$(php -r 'echo md5(round(time()/1000).\"YOUR_KEY\");')\""
    say "     then check $DEST/update.log"
    ;;

  cms)
    DEST="$PWD"
    [ -f "$DEST/config.php" ] || say "!! No config.php in $DEST — are you in the CMS admin dir? Continuing anyway."
    if [ -f "$DEST/export.php" ]; then
      cp "$DEST/export.php" "$DEST/export.php.svn-backup" && say "• Backed up old export.php -> export.php.svn-backup"
    fi
    fetch "$RAW_BASE/cms/export.php"            "$DEST/export.php"
    fetch "$RAW_BASE/cms/config.additions.php" "$DEST/config.additions.php"
    rule
    say "CMS side installed in:  $DEST"
    rule
    say "NEXT:"
    say "  1. Merge the keys from config.additions.php into your config.php, then delete it."
    say "  2. Make sure builds/ is UNDER public_html and backups/ is OUTSIDE it"
    say "     (paths come from config: builds_dir / backups_dir)."
    say "  3. Publish once — via the CMS admin, or:  php export.php"
    say "     Watch ../build-*.log, then confirm https://YOUR-CMS-HOST/builds/latest.tar.gz"
    say "     prompts for the Basic-Auth password."
    ;;

  *)
    die "usage: install.sh <cms|publish>   (run from your account home)"
    ;;
esac
