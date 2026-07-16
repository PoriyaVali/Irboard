#!/bin/bash
echo "=== Restore Backend Configs ==="

BASEDIR="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN=$(php "$BASEDIR/get_domain.php")
CONF="/www/server/panel/vhost/nginx/$DOMAIN.conf"

# ۱. nginx listen 8443
if ! grep -q "listen 8443" "$CONF" 2>/dev/null; then
    sed -i 's/listen 80;/listen 80;\n    listen 8443;/' "$CONF"
    echo "✅ Port 8443 added"
else
    echo "⏭️ Port 8443 already exists"
fi

# ۲. HTTP_TO_HTTPS شرطی
if grep -q 'request_uri ~ ^/api/' "$CONF"; then
    echo "⏭️ HTTP_TO_HTTPS already conditional"
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
    echo "✅ HTTP_TO_HTTPS fixed"
fi

# ۳. fastcgi HTTPS on
sed -i 's/fastcgi_param  HTTPS              $https if_not_empty;/fastcgi_param  HTTPS              on;/' /www/server/nginx/conf/fastcgi.conf
echo "✅ fastcgi HTTPS on"

# ۴. Laravel force_https
sed -i "s/'force_https' => '1'/'force_https' => '0'/" "$BASEDIR/config/v2board.php"
echo "✅ force_https = 0"

# ۵. TrustProxies
sed -i "s/protected \$proxies;/protected \$proxies = '*';/" "$BASEDIR/app/Http/Middleware/TrustProxies.php"
echo "✅ TrustProxies = *"

# ۶. فایروال
firewall-cmd --add-port=8443/tcp --permanent 2>/dev/null && firewall-cmd --reload 2>/dev/null
echo "✅ Firewall port 8443"

# ۷. Laravel cache clear
cd "$BASEDIR"
php artisan config:clear
php artisan cache:clear
php artisan route:clear
echo "✅ Laravel cache cleared"

# ۸. nginx reload
nginx -t && nginx -s reload
echo "✅ Nginx reloaded"

echo "=== Done! ==="
echo "⚠️ دیتابیس: payment_proxy_enable=1 و payment_proxy_url=https://127.0.0.1:19443/payment_proxy.php"
