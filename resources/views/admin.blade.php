<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$version}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script>
    // The admin UI language is decided by umi's own locale plugin, which reads
    // localStorage 'umi_locale' and otherwise falls back to the bundle default
    // (zh-CN, inherited from upstream). Default it to Persian on first load, and
    // only when unset, so the in-panel language switcher still works.
    if (!localStorage.getItem('umi_locale')) { localStorage.setItem('umi_locale', 'fa-IR'); }

    // NOTE: 'admin_lang' below decides nothing today - the bundle never reads
    // window.adminLang, and umi.js and umi-fa.js are byte-identical. Left in place
    // so a genuinely different umi-fa.js build would still be picked up.
    window.adminLang = localStorage.getItem('admin_lang') || 'fa';
    var umiFile = window.adminLang === 'fa' ? 'umi-fa.js' : 'umi.js';
    document.write('<script src="/assets/admin/' + umiFile + '?v={{$version}}"><\/script>');
</script>
</body>

</html>
