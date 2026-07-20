#!/bin/bash
#
# Server-config agent.
#
# Keeps storage/server_config_status.json describing whether this box is set up
# for the tunnel front-end (port 8443, the /api HTTPS exception, fastcgi HTTPS,
# and the rewrite rules), and applies fixes the admin panel asks for by dropping
# a storage/server_config_queue.json containing {"tasks": [...]}.
#
# Run under supervisor; install.sh registers it when this file is present.
#
BASEDIR="$(cd "$(dirname "$0")" && pwd)"
DOMAIN=$(php "$BASEDIR/get_domain.php")
QUEUE="$BASEDIR/storage/server_config_queue.json"
STATUS="$BASEDIR/storage/server_config_status.json"
CONF="/www/server/panel/vhost/nginx/$DOMAIN.conf"
FASTCGI="/www/server/nginx/conf/fastcgi.conf"
REWRITE="/www/server/panel/vhost/rewrite/$DOMAIN.conf"
LOG="$BASEDIR/storage/logs/server_config.log"

# Open a port on whichever firewall this distribution actually uses. The original
# only called firewall-cmd, which does not exist on Debian/Ubuntu, so the task
# reported success while changing nothing.
open_port() {
    local port="$1"
    if command -v firewall-cmd >/dev/null 2>&1; then
        firewall-cmd --add-port="${port}/tcp" --permanent >/dev/null 2>&1 && firewall-cmd --reload >/dev/null 2>&1
    elif command -v ufw >/dev/null 2>&1; then
        ufw allow "${port}/tcp" >/dev/null 2>&1
    elif [ -x /etc/init.d/bt ]; then
        return 0        # aaPanel's own firewall page manages it
    else
        return 1
    fi
}

update_status() {
    local has8443="false"
    local hasCond="false"
    local httpsOn="false"
    local fw8443="false"
    local hasApiLoc="false"
    local hasFcgiParam="false"

    grep -q "listen 8443" "$CONF" 2>/dev/null && has8443="true"
    # The /api exception only means anything when this vhost actually forces
    # HTTP->HTTPS. A vhost with no forced-redirect block already serves plain
    # HTTP /api - which is exactly what the tunnel needs - so report it OK
    # instead of a red cross the operator can never clear.
    if grep -q 'request_uri ~ ^/api/' "$CONF" 2>/dev/null; then
        hasCond="true"
    elif ! grep -q '#HTTP_TO_HTTPS_START' "$CONF" 2>/dev/null; then
        hasCond="true"
    fi
    grep -qP 'fastcgi_param\s+HTTPS\s+on;' "$FASTCGI" 2>/dev/null && httpsOn="true"
    # NOTE: this only proves nginx is listening locally. It cannot tell whether
    # 8443 is reachable from outside - a cloud security group or a host firewall
    # can still be blocking it while this reads true.
    (echo >/dev/tcp/127.0.0.1/8443) 2>/dev/null && fw8443="true"
    grep -q 'location ~ ^/api/' "$REWRITE" 2>/dev/null && hasApiLoc="true"
    grep -q 'fastcgi_param HTTPS on' "$REWRITE" 2>/dev/null && hasFcgiParam="true"

    # updated_at carries its own UTC offset on purpose. The panel decides whether
    # this agent is alive with (time() - strtotime(updated_at)) < 30, and PHP's
    # strtotime() reads a bare "Y-m-d H:i:s" in PHP's OWN timezone. This panel
    # ships date.timezone=PRC (UTC+8) while the box runs UTC, so a bare stamp read
    # 8 hours stale and the agent showed as permanently offline while it was
    # writing this file every 5 seconds. With the offset the reader cannot
    # misinterpret it, whatever either side's timezone is set to.
    cat > "$STATUS" << STATUSEOF
{
    "nginx_port_8443": $has8443,
    "http_to_https": $hasCond,
    "fastcgi_https": $httpsOn,
    "firewall_8443": $fw8443,
    "nginx_rewrite_api": $hasApiLoc,
    "nginx_rewrite_fcgi": $hasFcgiParam,
    "updated_at": "$(date '+%Y-%m-%d %H:%M:%S%z')"
}
STATUSEOF
    chmod 644 "$STATUS"
}

while true; do
    # Refresh the status the admin panel reads.
    update_status

    # Apply anything the panel queued.
    if [ -f "$QUEUE" ]; then
        TASKS=$(python3 -c "import json; d=json.load(open('$QUEUE')); print(' '.join(d.get('tasks',[])))" 2>/dev/null)

        if [ -n "$TASKS" ]; then
            echo "[$(date)] Processing: $TASKS" >> "$LOG"
            RELOAD=0

            for TASK in $TASKS; do
                case $TASK in
                    nginx_port_8443)
                        if ! grep -q "listen 8443" "$CONF"; then
                            sed -i 's/listen 80;/listen 80;\n    listen 8443;/' "$CONF"
                            echo "[$(date)] Port 8443 added" >> "$LOG"
                            RELOAD=1
                        fi
                        ;;
                    http_to_https)
                        if grep -q 'request_uri ~ ^/api/' "$CONF"; then
                            echo "[$(date)] HTTP_TO_HTTPS already conditional" >> "$LOG"
                        elif ! grep -q '#HTTP_TO_HTTPS_START' "$CONF"; then
                            # There is no forced-redirect block to carve an exception
                            # out of, so plain-HTTP /api already works. Doing the sed
                            # below would match no lines and change nothing - saying so
                            # beats logging a success that never happened.
                            echo "[$(date)] HTTP_TO_HTTPS: vhost has no forced redirect - nothing to fix" >> "$LOG"
                        else
                            sed -i '/#HTTP_TO_HTTPS_START/,/#HTTP_TO_HTTPS_END/c\
    #HTTP_TO_HTTPS_START\
    set $do_redirect 0;\
    if ($server_port !~ 443) {\
        set $do_redirect 1;\
    }\
    if ($request_uri ~ ^/api/) {\
        set $do_redirect 0;\
    }\
    if ($do_redirect = 1) {\
        rewrite ^(/.*)$ https://$host$1 permanent;\
    }\
    #HTTP_TO_HTTPS_END' "$CONF"
                            # Verify the edit landed before claiming it did.
                            if grep -q 'request_uri ~ ^/api/' "$CONF"; then
                                echo "[$(date)] HTTP_TO_HTTPS made conditional" >> "$LOG"
                                RELOAD=1
                            else
                                echo "[$(date)] HTTP_TO_HTTPS: edit did not apply - inspect $CONF by hand" >> "$LOG"
                            fi
                        fi
                        ;;
                    fastcgi_https)
                        sed -i 's/fastcgi_param  HTTPS              $https if_not_empty;/fastcgi_param  HTTPS              on;/' "$FASTCGI"
                        echo "[$(date)] FastCGI HTTPS on" >> "$LOG"
                        RELOAD=1
                        ;;
                    firewall_8443)
                        if open_port 8443; then
                            echo "[$(date)] Firewall: opened 8443" >> "$LOG"
                        else
                            echo "[$(date)] Firewall: no supported tool found - open 8443 manually" >> "$LOG"
                        fi
                        ;;
                esac
            done

            if [ $RELOAD -eq 1 ]; then
                nginx -t 2>/dev/null && nginx -s reload 2>/dev/null
                echo "[$(date)] Nginx reloaded" >> "$LOG"
            fi

            rm -f "$QUEUE"
            update_status
            echo "[$(date)] Queue processed" >> "$LOG"
        fi
    fi
    sleep 5
done
