#!/bin/bash
#
# Safe updater for Irboard.
#
# Rules this script follows (each one is a bug the old updater had):
#   1. NEVER destroy local work. Many panels run from a modified working tree;
#      the old script ran `git reset --hard` and silently wiped it. We refuse.
#   2. Never hardcode a branch name — follow whatever this checkout tracks
#      (the old script assumed "master" and broke on "main").
#   3. Keep dependencies reproducible: install from composer.lock instead of
#      deleting the lock and re-resolving every package.
#   4. Touch the web server LAST, restart it ourselves, and never leave it
#      stopped mid-update (the old script stopped it *before* the DB update, so
#      workers came back up against a half-migrated database).
#   5. Always print a rollback command and verify the panel afterwards.
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR" || exit 1

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[ -d .git ] || die "Not a git deployment (no .git directory)."
command -v git >/dev/null 2>&1 || die "git is not installed."
command -v php >/dev/null 2>&1 || die "php is not installed."

git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1

# ── 1. Refuse to run if updating would destroy local changes ─────────────────
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    warn "This deployment has uncommitted local changes:"
    git status --short --untracked-files=no | head -15
    echo
    die "Refusing to update — it would overwrite the changes above.
Commit them, run 'git stash', or back this directory up first, then re-run."
fi

# ── 2. Resolve branch/remote instead of assuming "origin master" ─────────────
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" != "HEAD" ] || die "Detached HEAD — check out a branch first."
REMOTE="$(git config "branch.$BRANCH.remote" 2>/dev/null || echo origin)"
git remote get-url "$REMOTE" >/dev/null 2>&1 \
    || die "No '$REMOTE' remote is configured for branch '$BRANCH'."

BEFORE="$(git rev-parse HEAD)"
say "Branch '$BRANCH' <- $(git remote get-url "$REMOTE")"
say "Rollback point: git reset --hard $BEFORE"

# ── 3. Fast-forward only — never rewrite or discard history ──────────────────
git fetch --prune "$REMOTE" || die "git fetch failed."
git rev-parse --verify --quiet "$REMOTE/$BRANCH" >/dev/null \
    || die "'$REMOTE/$BRANCH' not found on the remote. Wrong remote or branch?"

if [ "$BEFORE" = "$(git rev-parse "$REMOTE/$BRANCH")" ]; then
    say "Code already up to date."
else
    git merge --ff-only "$REMOTE/$BRANCH" \
        || die "Local and remote have diverged — cannot fast-forward.
Nothing was changed. Resolve manually, then re-run."
    say "Code updated to $(git rev-parse --short HEAD)."
fi

# ── 4. Dependencies (reproducible) ───────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
else
    if [ ! -f composer.phar ]; then
        say "Downloading composer.phar"
        wget -q https://github.com/composer/composer/releases/latest/download/composer.phar \
             -O composer.phar || die "Could not download composer. Install it manually."
    fi
    COMPOSER="php composer.phar"
fi

if [ -f composer.lock ]; then
    say "Installing dependencies from composer.lock"
    $COMPOSER install --no-interaction --optimize-autoloader || die "composer install failed."
else
    warn "No composer.lock present — resolving dependencies fresh."
    $COMPOSER update --no-interaction --optimize-autoloader || die "composer update failed."
fi

# AdapterMan is only needed for the PHP 8 webman runtime, and only if missing.
PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
if [ "$PHP_MAJOR" -ge 8 ] && [ -f webman.php ] && [ ! -d vendor/joanhey/adapterman ]; then
    say "Adding joanhey/adapterman"
    $COMPOSER require joanhey/adapterman --no-interaction || warn "Could not add adapterman."
fi
if [ "$PHP_MAJOR" -ge 8 ] && [ -f cli-php.ini ] && ! php -m | grep -qi pcntl \
   && ! grep -q '^extension=pcntl.so' cli-php.ini; then
    say "Enabling pcntl in cli-php.ini"
    sed -i '/^extension=redis.so/a extension=pcntl.so' cli-php.ini
fi

# ── 5. Database + caches, BEFORE the web server is touched ───────────────────
say "Applying database updates"
php artisan v2board:update || die "Database update failed — panel left untouched and running.
Rollback: git reset --hard $BEFORE"

say "Clearing caches"
php artisan config:clear >/dev/null 2>&1
php artisan cache:clear  >/dev/null 2>&1
php artisan view:clear   >/dev/null 2>&1
php artisan route:clear  >/dev/null 2>&1

# ── 6. Restart the web server LAST — and actually bring it back up ───────────
restart_webman() {
    local sc
    for sc in /www/server/panel/pyenv/bin/supervisorctl "$(command -v supervisorctl 2>/dev/null)"; do
        [ -n "$sc" ] && [ -x "$sc" ] || continue
        if "$sc" -c /etc/supervisor/supervisord.conf status 2>/dev/null | grep -qi webman; then
            say "Restarting webman via supervisor"
            "$sc" -c /etc/supervisor/supervisord.conf restart 'WebMan:*' >/dev/null 2>&1 && return 0
        fi
    done
    say "Restarting webman directly"
    php -c cli-php.ini webman.php restart -d >/dev/null 2>&1 && return 0
    return 1
}
if [ -f webman.php ]; then
    restart_webman || warn "Could not restart webman automatically — please restart it."
fi

# ── 7. Ownership (aaPanel) ──────────────────────────────────────────────────
[ -f /etc/init.d/bt ] && chown -R www "$APP_DIR" 2>/dev/null

# ── 8. Verify ───────────────────────────────────────────────────────────────
if [ -f webman.php ]; then
    sleep 3
    CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 10 \
            http://127.0.0.1:6600/api/v1/guest/comm/config 2>/dev/null || echo 000)"
    if [ "$CODE" = "200" ]; then
        say "Update complete — panel is responding (HTTP 200)."
    else
        warn "Panel returned HTTP $CODE. Check storage/logs/ ."
        warn "Rollback: git reset --hard $BEFORE && ./update.sh"
        exit 1
    fi
else
    say "Update complete."
fi
