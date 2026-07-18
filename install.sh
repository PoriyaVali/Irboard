#!/bin/bash
#
# IrBoard — one-command installer for aaPanel servers.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/PoriyaVali/Irboard/main/install.sh) --domain panel.example.com
#
# It is idempotent: re-running only fixes what is missing, and every file it
# touches is backed up as <file>.bak_irboard_<timestamp> first.
#
# What it does, in order:
#   1. preflight        root / aaPanel / php / sqlite3 / python3
#   2. site             resolve domain + install dir (aaPanel sites table)
#   3. prerequisites    redis-server, php redis/pcntl/posix extensions
#   4. code             clone the repo (if needed) and run init.sh
#   5. nginx rewrite    _server_configs/backend_rewrite.conf -> vhost/rewrite/<domain>.conf
#   6. supervisor       WebMan / V2b(horizon) / server-config-agent  (.ini + UI registry)
#   7. cron             artisan schedule:run every minute, aaPanel-native
#   8. verify           nginx -t + reload, supervisor status, HTTP check
#
set -uo pipefail

REPO_URL="https://github.com/PoriyaVali/Irboard.git"
TS="$(date +%Y%m%d_%H%M%S)"

# aaPanel layout (verified on a clean install)
BT_PANEL="/www/server/panel"
BT_DB="$BT_PANEL/data/default.db"
SUP_DIR="$BT_PANEL/plugin/supervisor"
SUP_PROFILE="$SUP_DIR/profile"
SUP_LOG="$SUP_DIR/log"
SUP_REGISTRY="$SUP_DIR/config.json"
SUP_CTL="$BT_PANEL/pyenv/bin/supervisorctl"
SUP_CONF="/etc/supervisor/supervisord.conf"
NGINX_VHOST="$BT_PANEL/vhost/nginx"
NGINX_REWRITE="$BT_PANEL/vhost/rewrite"
CRON_DIR="/www/server/cron"

DOMAIN=""
APP_DIR=""
TUNE_NGINX=0
SKIP_INSTALL=0
FORCE=0

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m[ok]\033[0m %s\n' "$*"; }
skip() { printf '  \033[1;34m[--]\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

backup() { [ -f "$1" ] && cp -a "$1" "$1.bak_irboard_$TS" && ok "backed up $(basename "$1")"; return 0; }

usage() {
    cat <<'USAGE'
Usage: install.sh [options]

  --domain <name>   Site domain (default: auto-detected from aaPanel)
  --dir <path>      Install directory (default: /www/wwwroot/<domain>)
  --tune-nginx      Also apply global nginx tuning (gzip/cache). Off by default
                    because /www/server/nginx/conf/nginx.conf is SERVER-WIDE and
                    affects every other site on the box.
  --skip-install    Skip clone + init.sh (code is already in place)
  --force           Merge into the directory even if it holds unrecognised files
  -h, --help        Show this help
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --dir)    APP_DIR="${2:-}"; shift 2 ;;
        --tune-nginx)   TUNE_NGINX=1; shift ;;
        --skip-install) SKIP_INSTALL=1; shift ;;
        --force)  FORCE=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown option: $1 (try --help)" ;;
    esac
done

# ── 1. preflight ────────────────────────────────────────────────────────────
say "1/8  Preflight"
[ "$(id -u)" -eq 0 ] || die "Run as root."
[ -d "$BT_PANEL" ]   || die "aaPanel not found at $BT_PANEL — this installer targets aaPanel."
command -v php >/dev/null     || die "php not found."
command -v sqlite3 >/dev/null || die "sqlite3 not found (needed to read aaPanel's database)."
command -v python3 >/dev/null || die "python3 not found (needed to update the supervisor registry)."
[ -f "$BT_DB" ] || die "aaPanel database missing: $BT_DB"
ok "root, aaPanel, php $(php -r 'echo PHP_VERSION;'), sqlite3, python3"

# ── 2. resolve site ─────────────────────────────────────────────────────────
say "2/8  Resolving site"
if [ -z "$DOMAIN" ] && [ -f "./get_domain.php" ]; then
    DOMAIN="$(php ./get_domain.php 2>/dev/null)"
    [ -n "$DOMAIN" ] && ok "domain from get_domain.php: $DOMAIN"
fi
if [ -z "$DOMAIN" ]; then
    mapfile -t _sites < <(sqlite3 "$BT_DB" "select name from sites where name not in ('default');" 2>/dev/null)
    if [ "${#_sites[@]}" -eq 1 ]; then
        DOMAIN="${_sites[0]}"; ok "domain auto-detected from aaPanel: $DOMAIN"
    elif [ "${#_sites[@]}" -gt 1 ]; then
        printf '\n'; warn "Several sites exist — pick one with --domain:"
        printf '    %s\n' "${_sites[@]}"; exit 1
    else
        die "No site found. Create the site in aaPanel first, then re-run with --domain <name>."
    fi
fi
[ -n "$APP_DIR" ] || APP_DIR="$(sqlite3 "$BT_DB" "select path from sites where name='$DOMAIN';" 2>/dev/null)"
[ -n "$APP_DIR" ] || APP_DIR="/www/wwwroot/$DOMAIN"
ok "install dir: $APP_DIR"
mkdir -p "$APP_DIR"

# ── 3. prerequisites ────────────────────────────────────────────────────────
say "3/8  Prerequisites"
# .env.example uses redis for cache, session AND queue, so redis is mandatory.
if command -v redis-server >/dev/null || systemctl list-unit-files 2>/dev/null | grep -q '^redis'; then
    skip "redis-server already installed"
else
    if command -v apt-get >/dev/null; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq redis-server >/dev/null 2>&1 \
            && ok "redis-server installed" || warn "could not install redis-server automatically"
    elif command -v yum >/dev/null; then
        yum install -y -q redis >/dev/null 2>&1 && ok "redis installed" || warn "could not install redis"
    else
        warn "unknown package manager — install redis manually"
    fi
fi
systemctl enable --now redis-server >/dev/null 2>&1 || systemctl enable --now redis >/dev/null 2>&1 || true
if redis-cli ping 2>/dev/null | grep -q PONG; then ok "redis responds to PING"; else warn "redis is NOT responding — the panel will fail on cache/session/queue"; fi

for ext in redis pcntl posix; do
    if php -m 2>/dev/null | grep -qix "$ext"; then ok "php extension: $ext"
    else warn "php extension MISSING: $ext  (install it from aaPanel > PHP > Extensions)"; fi
done

# ── 4. code + installer ─────────────────────────────────────────────────────
say "4/8  Application code"
if [ "$SKIP_INSTALL" -eq 1 ]; then
    skip "--skip-install given"
elif [ -f "$APP_DIR/artisan" ]; then
    skip "code already present (artisan found)"
else
    git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
    # A half-finished attempt usually leaves scaffolding like storage/ behind, and
    # `rm -rf ./*` never removes dotfiles — so "directory is not empty" on its own is
    # a bad reason to refuse. Only stop when something we do not recognise is there.
    leftovers=""
    for entry in $(ls -A "$APP_DIR" 2>/dev/null); do
        case "$entry" in
            storage|bootstrap|vendor|.git|.env|.user.ini|404.html|index.html) ;;
            *) leftovers="$leftovers $entry" ;;
        esac
    done
    if [ -n "$leftovers" ] && [ "$FORCE" -eq 0 ]; then
        warn "$APP_DIR already contains:$leftovers"
        die "Refusing to write over it. Clean the directory, or re-run with --force to merge."
    fi
    # Clone to a temp dir and copy in, so leftover empty scaffolding cannot block us
    # (git clone insists on an empty target).
    tmp="$(mktemp -d)"
    if git clone -q "$REPO_URL" "$tmp/src"; then
        cp -a "$tmp/src/." "$APP_DIR"/ && ok "IrBoard code installed"
        rm -rf "$tmp"
    else
        rm -rf "$tmp"; die "git clone failed"
    fi
fi

# A stray worker still holding the app port would make supervisor's WebMan fail to
# bind. Reclaim it, but only when it is clearly one of ours.
if ss -lntpH 2>/dev/null | grep -q '127.0.0.1:6600'; then
    stray="$(ss -lntpH 2>/dev/null | grep '127.0.0.1:6600' | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2)"
    if [ -n "$stray" ] && tr '\0' ' ' < "/proc/$stray/cmdline" 2>/dev/null | grep -qiE 'workerman|webman|adapterman'; then
        kill "$stray" 2>/dev/null && sleep 2
        ok "released port 6600 from a stray worker (pid $stray)"
    else
        warn "port 6600 is in use by pid ${stray:-?} which is not a webman process — WebMan may fail to start"
    fi
fi
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
cd "$APP_DIR" || die "cannot cd to $APP_DIR"

if [ "$SKIP_INSTALL" -eq 0 ] && [ ! -f "$APP_DIR/.env" ]; then
    say "     running init.sh (composer + database import + admin user)"
    bash "$APP_DIR/init.sh" || die "init.sh failed"
else
    skip ".env already exists — not re-running init.sh"
fi

# ── 5. nginx URL rewrite ────────────────────────────────────────────────────
say "5/8  Nginx URL rewrite"
SRC_REWRITE="$APP_DIR/_server_configs/backend_rewrite.conf"
DST_REWRITE="$NGINX_REWRITE/$DOMAIN.conf"
VHOST="$NGINX_VHOST/$DOMAIN.conf"
[ -f "$SRC_REWRITE" ] || die "missing $SRC_REWRITE"
mkdir -p "$NGINX_REWRITE"
if [ -f "$DST_REWRITE" ] && cmp -s "$SRC_REWRITE" "$DST_REWRITE"; then
    skip "rewrite config already current"
else
    backup "$DST_REWRITE"
    cp "$SRC_REWRITE" "$DST_REWRITE" && ok "wrote $DST_REWRITE"
fi
# A site REBUILD in aaPanel regenerates the vhost with every include commented
# out, which serves the site as pure static files (404 on every panel route).
if [ -f "$VHOST" ]; then
    if grep -qE "^\s*#\s*include\s+$NGINX_REWRITE/$DOMAIN\.conf;" "$VHOST"; then
        backup "$VHOST"
        sed -i "s|^\(\s*\)#\s*\(include\s\+$NGINX_REWRITE/$DOMAIN\.conf;\)|\1\2|" "$VHOST"
        ok "re-enabled the commented-out rewrite include"
    elif grep -qE "^\s*include\s+$NGINX_REWRITE/$DOMAIN\.conf;" "$VHOST"; then
        ok "vhost includes the rewrite config"
    else
        warn "vhost does not include $DST_REWRITE — add it inside the server block"
    fi
else
    warn "vhost not found: $VHOST"
fi

# ── 6. supervisor ───────────────────────────────────────────────────────────
say "6/8  Supervisor programs"
if [ ! -d "$SUP_DIR" ]; then
    warn "supervisor plugin not installed — install it from aaPanel > App Store, then re-run"
else
    mkdir -p "$SUP_PROFILE" "$SUP_LOG"
    write_program() {   # name, command
        local name="$1" cmd="$2" ini="$SUP_PROFILE/$1.ini"
        if [ -f "$ini" ] && grep -qF "command=$cmd" "$ini"; then skip "program $name already configured"; return; fi
        backup "$ini"
        cat > "$ini" <<INI
[program:$name]
command=$cmd
directory=$APP_DIR/
autorestart=true
startsecs=3
startretries=3
stdout_logfile=$SUP_LOG/$name.out.log
stderr_logfile=$SUP_LOG/$name.err.log
stdout_logfile_maxbytes=2MB
stderr_logfile_maxbytes=2MB
user=root
priority=999
numprocs=1
stopsignal=QUIT
process_name=%(program_name)s_%(process_num)02d
INI
        ok "program $name"
    }
    # NOTE: webman must NOT be started with -d here; supervisor owns the process.
    write_program "WebMan"              "php -c cli-php.ini webman.php start"
    write_program "V2b"                 "php artisan horizon"
    write_program "server-config-agent" "/bin/bash $APP_DIR/server_config_agent.sh"

    # The .ini files make supervisord run them; config.json is what the aaPanel
    # UI reads. Without it the programs work but are invisible in the panel.
    python3 - "$SUP_REGISTRY" "$APP_DIR" <<'PY'
import json, os, sys
path, app_dir = sys.argv[1], sys.argv[2]
wanted = [
    ("WebMan",              "php -c cli-php.ini webman.php start"),
    ("V2b",                 "php artisan horizon"),
    ("server-config-agent", "/bin/bash %s/server_config_agent.sh" % app_dir),
]
try:
    with open(path) as fh:
        data = json.load(fh)
    if not isinstance(data, list):
        data = []
except Exception:
    data = []
by_name = {e.get("program"): e for e in data if isinstance(e, dict)}
for name, cmd in wanted:
    by_name[name] = {"program": name, "command": cmd, "directory": app_dir + "/",
                     "user": "root", "priority": "999", "numprocs": "1",
                     "runStatus": "ERROR", "ps": name}
with open(path, "w") as fh:
    json.dump(list(by_name.values()), fh)
print("  \033[1;32m[ok]\033[0m aaPanel supervisor registry updated")
PY

    if [ -x "$SUP_CTL" ]; then
        "$SUP_CTL" -c "$SUP_CONF" reread >/dev/null 2>&1
        "$SUP_CTL" -c "$SUP_CONF" update >/dev/null 2>&1
        ok "supervisor reread + update"
    else
        warn "supervisorctl not found at $SUP_CTL"
    fi
fi

# ── 7. cron ─────────────────────────────────────────────────────────────────
say "7/8  Scheduler cron (every minute)"
CRON_CMD="php $APP_DIR/artisan schedule:run"
EXISTING_HASH="$(sqlite3 "$BT_DB" "select echo from crontab where sBody like '%artisan schedule:run%' limit 1;" 2>/dev/null)"
if [ -n "$EXISTING_HASH" ] && [ -f "$CRON_DIR/$EXISTING_HASH" ]; then
    skip "scheduler cron already registered ($EXISTING_HASH)"
else
    mkdir -p "$CRON_DIR"
    HASH="$(openssl rand -hex 16 2>/dev/null || head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    SCRIPT="$CRON_DIR/$HASH"
    cat > "$SCRIPT" <<CRONEOF
#!/bin/bash
PATH=/bin:/sbin:/usr/bin:/usr/sbin:/usr/local/bin:/usr/local/sbin:~/bin
export PATH
echo \$\$ > $SCRIPT.pl
sudo -u root bash <<'EOF'
$CRON_CMD
EOF
echo "----------------------------------------------------------------------------"
endDate=\`date +"%Y-%m-%d %H:%M:%S"\`
echo "★[\$endDate] Successful"
echo "----------------------------------------------------------------------------"
if [[ "\$1" != "start" ]]; then
    btpython $BT_PANEL/script/log_task_analyzer.py $SCRIPT.log
fi
rm -f $SCRIPT.pl
CRONEOF
    chmod 700 "$SCRIPT"
    ok "cron script $HASH"

    sqlite3 "$BT_DB" "insert into crontab (name,type,where1,where_hour,where_minute,echo,addtime,status,save,sName,sBody,sType) \
        values ('IrBoard','minute-n','1',1,1,'$HASH',datetime('now'),1,3,'','$CRON_CMD','toShell');" 2>/dev/null \
        && ok "registered in the aaPanel task list" || warn "could not write the aaPanel cron row"

    LINE="*/1 * * * *  $SCRIPT >> $SCRIPT.log 2>&1"
    ( crontab -l 2>/dev/null | grep -vF "$SCRIPT"; echo "$LINE" ) | crontab - \
        && ok "crontab line installed" || warn "could not update crontab"
fi

# optional, opt-in: server-wide nginx tuning
if [ "$TUNE_NGINX" -eq 1 ]; then
    NGX_MAIN="/www/server/nginx/conf/nginx.conf"
    if [ -f "$NGX_MAIN" ] && ! grep -q "application/json" "$NGX_MAIN"; then
        backup "$NGX_MAIN"
        sed -i 's|^\(\s*\)gzip_types\(.*\)$|\1gzip_types text/plain application/javascript application/x-javascript text/javascript text/css application/xml application/json application/ld+json image/svg+xml image/x-icon application/rss+xml text/xml;|' "$NGX_MAIN"
        ok "gzip types extended (json/svg included)"
    else
        skip "nginx already tuned"
    fi
fi

# ── 8. permissions + verification ───────────────────────────────────────────
say "8/8  Permissions and verification"
id www >/dev/null 2>&1 && chown -R www:www "$APP_DIR" 2>/dev/null && ok "ownership set to www:www"

if nginx -t >/dev/null 2>&1; then
    nginx -s reload >/dev/null 2>&1 && ok "nginx config valid, reloaded"
else
    warn "nginx -t FAILED — not reloading. Run 'nginx -t' to see why."
fi

if [ -x "$SUP_CTL" ]; then
    printf '\n'; "$SUP_CTL" -c "$SUP_CONF" status 2>/dev/null | sed 's/^/    /'
fi

printf '\n'
sleep 3
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:6600/api/v1/guest/comm/config" -H "Host: $DOMAIN" 2>/dev/null)"
if [ "$CODE" = "200" ]; then ok "backend answers on 127.0.0.1:6600 (HTTP $CODE)"
else warn "backend on 127.0.0.1:6600 returned '$CODE' — check: $SUP_LOG/WebMan.err.log"; fi

SECURE_PATH="$(php -r '$c=@include "'"$APP_DIR"'/config/v2board.php"; echo is_array($c)&&isset($c["secure_path"])?$c["secure_path"]:"";' 2>/dev/null)"
printf '\n\033[1;32m==> IrBoard is set up.\033[0m\n'
[ -n "$SECURE_PATH" ] && printf '    Admin panel: https://%s/%s\n' "$DOMAIN" "$SECURE_PATH"
printf '    Logs:        %s/\n\n' "$SUP_LOG"
