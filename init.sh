#!/bin/bash
#
# First-time installer for Irboard.
#
# Fixes over the old script:
#   - Uses the system composer when present instead of re-downloading it.
#   - `composer install` needs a lock file; a fresh clone has none (it is
#     gitignored), so fall back to `composer update` instead of failing.
#   - Creates .env and APP_KEY up front — `v2board:install` needs them.
#   - Fails loudly with a clear message instead of silently continuing.
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR" || exit 1

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

command -v php >/dev/null 2>&1 || die "php is not installed."

# ── 1. Composer ─────────────────────────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
else
    if [ ! -f composer.phar ]; then
        say "Downloading composer.phar"
        wget -q https://github.com/composer/composer/releases/latest/download/composer.phar \
             -O composer.phar || die "Could not download composer. Install it manually and re-run."
    fi
    COMPOSER="php composer.phar"
fi

# ── 2. Dependencies ─────────────────────────────────────────────────────────
if [ -f composer.lock ]; then
    say "Installing dependencies from composer.lock"
    $COMPOSER install --no-interaction --optimize-autoloader || die "composer install failed."
else
    say "No composer.lock — resolving dependencies"
    $COMPOSER update --no-interaction --optimize-autoloader || die "composer update failed."
fi

PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
if [ "$PHP_MAJOR" -ge 8 ] && [ -f webman.php ] && [ ! -d vendor/joanhey/adapterman ]; then
    say "Adding joanhey/adapterman (webman runtime)"
    $COMPOSER require joanhey/adapterman --no-interaction || warn "Could not add adapterman."
fi

# ── 3. Environment ──────────────────────────────────────────────────────────
if [ ! -f .env ]; then
    [ -f .env.example ] || die ".env.example is missing — cannot create .env."
    say "Creating .env from .env.example"
    cp .env.example .env
    warn "Edit .env now and set DB_* / REDIS_* before continuing."
fi
if ! grep -qE '^APP_KEY=.+' .env; then
    say "Generating APP_KEY"
    php artisan key:generate --force || die "Could not generate APP_KEY."
fi

# ── 4. Writable paths ───────────────────────────────────────────────────────
mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null

# ── 5. Install the database schema ──────────────────────────────────────────
say "Installing database schema"
php artisan v2board:install || die "v2board:install failed. Check the DB settings in .env."

# ── 6. Ownership (aaPanel) ──────────────────────────────────────────────────
[ -f /etc/init.d/bt ] && chown -R www "$APP_DIR" 2>/dev/null

say "Install complete."
[ -f webman.php ] && say "Start the panel with: php -c cli-php.ini webman.php start -d"
