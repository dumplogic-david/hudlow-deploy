# hudlow.com — SVN → pull-a-tarball deploy migration

A self-hosted replacement for the dead SVN publish pipeline. One neutral, versioned
build archive; every host **pulls** it, key-triggered. No SVN, no third-party data
hosting, no server-side git — and installable anywhere with a one-line `curl`.

---

## 1. Why we're migrating
DreamHost deprecated Subversion and a server upgrade dropped `mod_dav_svn`, so
`http://www.hudlow.com/dhudlowcms` now returns **404**. That URL was the transport
between the CMS build box and the production servers, so the whole publish chain
(`svn commit` on one side, `svn checkout` + `cp` on the other) is dead.

## 2. The old architecture (reverse-engineered)
```
CMS/build box (export.php)          SVN repo (DreamHost)        Publish server(s)
  cache/ + MySQL  ─build─▶ build/  ──svn commit──▶  [repo]  ──svn checkout──▶ live dir
                                                         ▲  obvious_remote_update.php
                                        ping ────────────┘  (triggered pull)
```
- `database.sql` was dumped **into** `build/` — meaning it was shipped to, and
  web-exposed on, every publish server. (Latent leak; fixed below.)
- The trigger key `md5(round(time()/1000).$KEY)` gated the pull.

## 3. The new architecture
```
CMS/build box (export.php)                         Publish server(s): /deploy/update.php
  cache/ + MySQL
    ├─ build/  ─► builds/build-<rev>.tar.gz         on key-ping:
    │            builds/latest.tar.gz  (stable URL)    1. curl latest.tar.gz  (Basic Auth)
    │            [Basic-Auth protected, keep last 20]  2. extract to staging/
    ├─ MySQL ─► backups/db-<rev>.sql.gz  (PRIVATE,     3. SAFETY: refuse empty/garbage build
    │            non-web, never shipped)               4. mirror staging ─► LIVE_DIR
    └─ ping every publish target ──────────────────────   (add/update/remove; machinery protected)
```

**Two independent secrets** (layered defense):
1. **Rotating key** — authorizes the *trigger* (`md5(round(time()/1000).$KEY)`, ±1 window for clock skew).
2. **Basic-Auth password** — authorizes the *download* of `builds/`.

Neither secret leaking exposes the database, because the DB dump never enters the
archive — it stays in a private, non-web `backups/` dir as gzip'd rollback history.

## 4. Universal PHP-hosting compatibility
The transport is **pure PHP**; the only hard requirement is PHP **5.4+** with the
**Phar** extension (enabled by default virtually everywhere). Every external tool is
optional, with fallbacks tried in order:

| Need         | 1st (native)        | 2nd fallback            | 3rd fallback     |
|--------------|---------------------|-------------------------|------------------|
| archive      | `PharData` (tar.gz) | shell `tar`             | —                |
| extract      | `PharData`          | shell `tar`             | —                |
| HTTP GET     | `curl` ext          | `file_get_contents`     | shell curl/wget  |
| file mirror  | native PHP recursion| (no external dep)       | —                |
| DB backup    | shell `mysqldump`   | (best-effort; non-fatal)| —                |

No `svn`, `rsync`, `zip`/`unzip`, or `git` binary is required on any host. The
publish side needs **no shell at all** in the common path. Syntax targets PHP 5.4–8.x
(no `??`, no typed props, no `str_contains`).

## 5. Install anywhere — one-liner bootstrap
The tooling lives in a **public GitHub repo** (`dumplogic-david/hudlow-deploy`) and is
fetched from `raw.githubusercontent.com`. It holds **no secrets** (keys/passwords/DB
never touch it), so public hosting is safe. GitHub raw serves exact source with no
WAF and no PHP execution — which is why we moved off cms.dhudlow.com, whose
Mod_Security 406-blocked the PHP files for containing `shell_exec`/`system`.

```bash
RAW=https://raw.githubusercontent.com/dumplogic-david/hudlow-deploy/main

# On a publish host (run from ~):
curl -fsSL $RAW/install.sh | bash -s -- publish

# On the CMS box (run from the CMS admin dir):
cd ~/path/to/cms-admin
curl -fsSL $RAW/install.sh | bash -s -- cms
```

The installer finds `public_html`, drops the right files, protects secrets with
`.htaccess`, backs up your old `export.php`, and prints the exact next steps.

**To update the tooling:** edit here, then `git commit -am '...' && git push`. The
`main` raw URLs pick up the change immediately.

## 6. Migration plan — step by step

### A. One-time prep (CMS box)
1. `cd` into the CMS admin dir; run the `cms` bootstrap (backs up old `export.php`).
2. Merge `config.additions.php` into `config.php`; pick a **KEY** and a
   **download password**; delete `config.additions.php`.
3. Confirm `builds_dir` is **under** `public_html` and `backups_dir` is **outside** it.
4. Run `php export.php` once. Verify:
   - `../build-*.log` is clean,
   - `builds/latest.tar.gz` exists and the URL prompts for Basic Auth,
   - `backups/db-*.sql.gz` exists and is **not** reachable from the web,
   - the DB dump is **not** inside the tarball.

### B. Each publish host
1. Run the `publish` bootstrap from `~`.
2. Edit `deploy/remote_config.php`: same **KEY**, the `builds/latest.tar.gz` URL,
   the Basic-Auth user/pass, and the absolute **LIVE_DIR**.
3. Dry-run from SSH:
   ```bash
   php ~/public_html/deploy/update.php "$(php -r 'echo md5(round(time()/1000)."YOUR_KEY");')"
   tail deploy/update.log
   ```
   Expect `ok` and a "Deployed N files" line. If anything is off it prints an
   `ABORT:` reason and leaves the live site **untouched**.
4. Add `https://THIS-HOST/deploy/update.php` to `publish_targets` in the CMS config.

### C. Cutover
1. From the CMS, run `export.php`. It pings every target; each pulls and deploys.
2. Browse each live site; confirm content updated.
3. Publish a trivial change end-to-end to confirm the full loop.

### D. Decommission SVN
Once every target is confirmed on the new system, delete the old SVN working copies,
`obvious_remote_update.php`, `svnUpdateStatus.bash`, and any `deploy_svn_name` config.

## 7. Safety & rollback
- **No-wipe guard:** a deploy is refused unless the extracted build is non-empty and
  contains `$SENTINEL` (default `index.php`). A failed download/extract changes nothing.
- **Machinery protected:** the mirror never deletes `deploy/`, `.htaccess`,
  `.well-known`, `cgi-bin`, or anything in `$EXCLUDES` (e.g. runtime upload dirs).
- **Rollback:** the last 20 `builds/build-<rev>.tar.gz` are kept. To roll back, copy
  an older one over `latest.tar.gz` and re-ping, or extract it into `LIVE_DIR` by hand.
- **DB restore:** `gunzip -c backups/db-<rev>.sql.gz | mysql ...` (backups only; the
  live sites don't use MySQL at request time).

## 8. Open items
- [ ] Choose where to host the tooling (public repo / gist / own domain) and set `RAW_BASE`.
- [ ] Fill real values: KEY, download password, CMS host, each target's LIVE_DIR.
- [ ] Enumerate all publish targets (Bluehost + DreamHost) into `publish_targets`.

## 9. Files in this project
```
install.sh                       one-line bootstrap (cms|publish); served from GitHub raw
cms/export.php                   new CMS publish script (drop-in for old export.php)
cms/config.additions.php         config keys to merge into config.php
publish/update.php               publish-side updater (replaces obvious_remote_update.php)
publish/remote_config.sample.php publish-side secrets template
MIGRATION.md                     this document
```
