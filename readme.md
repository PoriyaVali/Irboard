<div align="center">

<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Irboard Logo" width="150" height="150"/>

# 🚀 Irboard

**پنل مدیریت پروکسی قدرتمند و حرفه‌ای**

[![PHP](https://img.shields.io/badge/PHP-7.3+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![MySQL](https://img.shields.io/badge/MySQL-5.5+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis](https://img.shields.io/badge/Redis-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)

</div>

---

## 📋 پیش‌نیازها

| نرم‌افزار | نسخه |
|:---------:|:----:|
| PHP | 7.3+ |
| Composer | آخرین نسخه |
| MySQL | 5.5+ |
| Redis | آخرین نسخه |
| Laravel | آخرین نسخه |

---

## 🔧 بک‌اند پشتیبانی شده

- [V2bX اصلاح شده](https://github.com/PoriyaVali/V2bX)

---

## 📦 مراحل مهاجرت از نسخه اصلی

### مرحله ۱: مهاجرت فایل‌های پنل

```bash
git remote set-url origin https://github.com/PoriyaVali/Irboard
git checkout master
./update.sh
```

### مرحله ۲: پیکربندی کش Redis

```bash
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan optimize:clear
```

### مرحله ۳: ذخیره مجدد تنظیمات قالب

> وارد پنل مدیریت شوید:
> 
> **تنظیمات قالب** ← **انتخاب قالب default** ← **تنظیمات قالب** ← **ذخیره**

---

## 📖 مستندات

📚 [مشاهده مستندات کامل](https://v2board.com)

---

## 💖 حامیان

تشکر ویژه از [Jetbrains](https://www.jetbrains.com/) برای ارائه لایسنس رایگان پروژه‌های متن‌باز.

<a href="https://www.jetbrains.com/">
  <img src="https://resources.jetbrains.com/storage/products/company/brand/logos/jb_beam.png" alt="JetBrains" width="100"/>
</a>

---

## 🐛 گزارش مشکلات

برای گزارش مشکلات، لطفاً از بخش [Issues](https://github.com/PoriyaVali/Irboard/issues) استفاده کنید و قالب مشخص شده را رعایت نمایید.

---

<div align="center">

**ساخته شده با ❤️ توسط [PoriyaVali](https://github.com/PoriyaVali)**

</div>
