# hudlow-deploy

Self-hosted publish system for the **Obvious CMS** — replaces the dead SVN pipeline.
The CMS builds a static site into a password-protected tarball; each published site
**pulls** it on a key-triggered ping and mirrors it into place. No SVN, no rsync, no
git on the servers, nothing that breaks on the next host upgrade.

Repo: `github.com/dumplogic-david/hudlow-deploy` — served raw for one-line installs.
The tooling holds **no secrets**; keys/passwords live only in each server's config.

---

## How it works

```
CMS site (e.g. cms.dhudlow.com)                 Published site(s) (e.g. dhudlow.com)
  obvious/cache_export.php                         <site>/deploy/update.php
    ├─ cacheall.php   (DB -> cache/)               on key-ping:
    └─ export.php     (cache/ -> build/)             1. curl builds/latest.tar.gz (Basic Auth)
         ├─ tar.gz  -> builds/latest.tar.gz          2. extract to staging/
         │           (+ build-<rev>.tar.gz history)  3. refuse empty/garbage build (sentinel)
         ├─ mysqldump -> ~/backups (private)         4. mirror staging/ -> live dir (+delete,
         └─ ping every publish target ───────────►      protecting the deploy/ machinery)
```

- **Trigger key** (`remote_key`) authorizes the ping; **Basic-Auth password**
  (`archive_pass`) authorizes the tarball download. Two independent secrets.
- The MySQL dump is a **private backup only** — it never enters the shipped tarball.
- `builds/latest.tar.gz` returns **200 with the deploy password, denied without it**.
  (On this host anonymous requests return 500 rather than a clean 401 — harmless;
  the puller always sends the password.)

## Repo contents

```
install.sh                 interactive bootstrap: `... cms` or `... publish`
cms/export.php             Obvious export.php with the tarball tail (drop-in for obvious/export.php)
cms/config.additions.php   reference config keys (wizard writes deploy.conf.php for you)
publish/update.php         the puller/deployer (installed at <site>/deploy/update.php)
publish/remote_config.sample.php   reference for the publish-side config
tools/php8-migrate.php     scan/auto-fix PHP 8 incompatibilities in an Obvious codebase
MIGRATION.md               design rationale + the original SVN→tarball migration story
README.md                  this file
```

---

## Migrate a new Obvious site — runbook

Pick two secrets once and reuse them on **every** box for a given CMS:
`REMOTE_KEY` (trigger) and `ARCHIVE_PASS` (download). Generate with
`openssl rand -hex 24` and `openssl rand -hex 18`.

### 0. PHP 8 compatibility (if the host is on PHP 8+, and Obvious predates it)
```bash
cd ~
curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/tools/php8-migrate.php -o php8-migrate.php
php php8-migrate.php ~/public_html/<cms-site>            # scan (read-only)
php php8-migrate.php ~/public_html/<cms-site> --fix      # auto-fix get_magic_quotes_gpc, E_NONE (.bak written)
sed -i 's/mysqli_insert_id()/mysqli_insert_id($this->link)/g' ~/public_html/<cms-site>/obvious/database.php
php php8-migrate.php ~/public_html/<cms-site>            # confirm FATAL: 0 (utf8_encode REVIEWs are OK to leave)
rm ~/php8-migrate.php
```

### 1. CMS side — run in the `obvious/` dir (where `config.php` lives)
```bash
cd ~/public_html/<cms-site>/obvious
cp export.php export.php.svn-backup                       # keep the old SVN version
curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/cms/export.php -o export.php
cat > deploy.conf.php <<'EOF'
<?php
$config['remote_key']   = 'REMOTE_KEY';
$config['archive_user'] = 'deploy';
$config['archive_pass'] = 'ARCHIVE_PASS';
$config['keep_builds']  = 20;
$config['publish_targets'] = array(
    'https://<published-site>/deploy/update.php',
);
EOF
```
Run an export from the admin (`.../obvious/cache_export.php`), then verify:
```bash
curl -sI -u deploy:ARCHIVE_PASS https://<cms-site>/builds/latest.tar.gz | head -1   # want 200
```

### 2. Each published site — run in the live site dir
```bash
cd ~/public_html/<published-site>
curl -fsSL https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main/install.sh -o /tmp/hlw.sh
bash /tmp/hlw.sh publish
```
Wizard answers: trigger key = `REMOTE_KEY`, CMS base URL = `https://<cms-site>`,
this site's root URL = `https://<published-site>`, deploy dir = Enter.
It prints the endpoint URL to add to `publish_targets` on the CMS.

### 3. Publish
Back up the live site first (`tar czf ~/<site>-backup.tar.gz -C ~/public_html <site>`),
then run the export from the CMS admin. It pings every target; each pulls and mirrors
the new build. The updater **refuses to deploy an empty/garbage build**, so a bad
pull leaves the live site untouched.

---

## Gotchas we hit (so you don't again)

- **PHP version is the #1 trap.** Obvious is PHP 7.x-era. If the host only offers
  PHP 8 (no 7.x in MultiPHP Manager), you MUST run the PHP 8 scanner (step 0).
  Symptoms on 8: `Undefined constant E_NONE`, `Call to undefined function
  get_magic_quotes_gpc()`, `ArgumentCountError` on `mysqli_insert_id()`.
- **Never overwrite a docroot `.htaccess`.** The Obvious front controller needs
  `RewriteEngine On … RewriteRule ^ index.php [L]` (or `FallbackResource /index.php`).
  On cPanel/PHP-FPM, `.htaccess` cannot set the PHP version — use MultiPHP Manager;
  `AddHandler` lines will 404 or 500 the whole site.
- **Don't host the PHP tooling on a live PHP host.** Mod_Security 406-blocks files
  containing `shell_exec`/`system`, and `.php` URLs execute instead of serving source.
  That's why the tooling lives on GitHub raw (`text/plain`, no WAF).
- **PHP function hoisting:** top-level `function` declarations hoist; ones wrapped in
  `if (...) { function … }` do NOT. Keep helpers at top level.

## Logs / troubleshooting

```bash
# export build log (CMS side)
tail -60 "$(ls -t ~/public_html/<cms-site>/build-*.log | head -1)"
# publish log
tail ~/public_html/<published-site>/deploy/update.log
# PHP errors (cPanel drops error_log per directory)
find ~/public_html/<site> -name error_log -exec tail -5 {} \;
```
Note: `config.php` sets `error_reporting(0)`, which hides warnings — bump it
temporarily to debug, then restore.
