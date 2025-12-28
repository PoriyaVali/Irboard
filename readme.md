<div align="center">

<!-- Animated Header -->
<img width="100%" src="https://capsule-render.vercel.app/api?type=waving&color=0:667eea,100:764ba2&height=200&section=header&text=Irboard&fontSize=80&fontColor=ffffff&animation=fadeIn&fontAlignY=35&desc=پنل%20مدیریت%20پروکسی%20قدرتمند%20و%20حرفه‌ای&descSize=20&descAlignY=55"/>

<!-- Animated Logo -->
<a href="https://github.com/PoriyaVali/Irboard">
  <img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="Irboard Logo" width="120" height="120" style="border-radius: 20px;"/>
</a>

<!-- Typing Animation -->
<br/>
<a href="https://git.io/typing-svg">
  <img src="https://readme-typing-svg.demolab.com?font=Vazirmatn&size=24&duration=3000&pause=1000&color=667EEA&center=true&vCenter=true&multiline=true&repeat=true&width=600&height=60&lines=%F0%9F%9A%80+%D9%BE%D9%86%D9%84+%D9%85%D8%AF%DB%8C%D8%B1%DB%8C%D8%AA+%D9%BE%D8%B1%D9%88%DA%A9%D8%B3%DB%8C+%D9%BE%DB%8C%D8%B4%D8%B1%D9%81%D8%AA%D9%87;%E2%9C%A8+%D8%B3%D8%B1%DB%8C%D8%B9%D8%8C+%D8%A7%D9%85%D9%86%D8%8C+%D9%85%D9%82%DB%8C%D8%A7%D8%B3%E2%80%8C%D9%BE%D8%B0%DB%8C%D8%B1" alt="Typing SVG" />
</a>

<br/>

<!-- Animated Badges -->
<p>
  <img src="https://img.shields.io/badge/PHP-7.3+-777BB4?style=for-the-badge&logo=php&logoColor=white&labelColor=1a1b27" alt="PHP"/>
  <img src="https://img.shields.io/badge/Laravel-10.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white&labelColor=1a1b27" alt="Laravel"/>
  <img src="https://img.shields.io/badge/MySQL-5.5+-4479A1?style=for-the-badge&logo=mysql&logoColor=white&labelColor=1a1b27" alt="MySQL"/>
  <img src="https://img.shields.io/badge/Redis-Latest-DC382D?style=for-the-badge&logo=redis&logoColor=white&labelColor=1a1b27" alt="Redis"/>
</p>

<!-- Stats Badges -->
<p>
  <img src="https://img.shields.io/github/stars/PoriyaVali/Irboard?style=for-the-badge&logo=github&color=f4d03f&labelColor=1a1b27" alt="Stars"/>
  <img src="https://img.shields.io/github/forks/PoriyaVali/Irboard?style=for-the-badge&logo=github&color=58d68d&labelColor=1a1b27" alt="Forks"/>
  <img src="https://img.shields.io/github/issues/PoriyaVali/Irboard?style=for-the-badge&logo=github&color=e74c3c&labelColor=1a1b27" alt="Issues"/>
  <img src="https://img.shields.io/github/license/PoriyaVali/Irboard?style=for-the-badge&logo=github&color=9b59b6&labelColor=1a1b27" alt="License"/>
</p>

<!-- Animated Line -->
<img src="https://user-images.githubusercontent.com/73097560/115834477-dbab4500-a447-11eb-908a-139a6edaec5c.gif" width="100%"/>

</div>

<br/>

## ⚡ ویژگی‌های کلیدی

<table>
<tr>
<td width="50%">

### 🎯 عملکرد
- ⚡ پردازش سریع با Redis Cache
- 🔄 مدیریت Queue با Laravel Horizon
- 📊 مانیتورینگ Real-time
- 🚀 بهینه‌سازی خودکار

</td>
<td width="50%">

### 🛡️ امنیت
- 🔐 احراز هویت چندلایه
- 🛡️ محافظت در برابر حملات
- 📝 لاگ‌گیری جامع
- 🔒 رمزنگاری End-to-End

</td>
</tr>
</table>

<br/>

## 📋 پیش‌نیازها

<div align="center">

| 📦 نرم‌افزار | 🔢 نسخه | 📝 توضیحات |
|:---:|:---:|:---:|
| <img src="https://skillicons.dev/icons?i=php" width="20"/> PHP | `7.3+` | با extensionهای لازم |
| <img src="https://skillicons.dev/icons?i=laravel" width="20"/> Laravel | `10.x` | آخرین نسخه پایدار |
| <img src="https://skillicons.dev/icons?i=mysql" width="20"/> MySQL | `5.5+` | یا MariaDB 10+ |
| <img src="https://skillicons.dev/icons?i=redis" width="20"/> Redis | `Latest` | برای Cache و Queue |
| 📦 Composer | `Latest` | مدیریت پکیج‌ها |

</div>

<br/>

## 🔧 بک‌اند پشتیبانی شده

<div align="center">

[![V2bX](https://img.shields.io/badge/V2bX_اصلاح_شده-00D4AA?style=for-the-badge&logo=v&logoColor=white)](https://github.com/PoriyaVali/V2bX)

</div>

<br/>

## 📦 مراحل مهاجرت

<details>
<summary><b>🔹 مرحله ۱: مهاجرت فایل‌های پنل</b></summary>

<br/>

```bash
# تغییر منبع ریپازیتوری
git remote set-url origin https://github.com/PoriyaVali/Irboard

# سوئیچ به شاخه اصلی
git checkout master

# اجرای اسکریپت آپدیت
./update.sh
```

</details>

<details>
<summary><b>🔹 مرحله ۲: پیکربندی کش Redis</b></summary>

<br/>

```bash
# تنظیم درایور کش
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env

# پاکسازی و بازسازی کش‌ها
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan optimize:clear
```

</details>

<details>
<summary><b>🔹 مرحله ۳: ذخیره مجدد تنظیمات قالب</b></summary>

<br/>

> 📌 وارد پنل مدیریت شوید:
> 
> **تنظیمات قالب** ← **انتخاب قالب default** ← **تنظیمات قالب** ← **ذخیره**

</details>

<br/>

## 🗺️ نقشه راه

<div align="center">

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': { 'primaryColor': '#667eea', 'secondaryColor': '#764ba2'}}}%%
timeline
    title نقشه راه توسعه Irboard
    section 2024 Q1
        نسخه 1.0 : پنل پایه
                  : مدیریت کاربران
    section 2024 Q2
        نسخه 1.5 : پشتیبانی Multi-server
                  : API پیشرفته
    section 2024 Q3
        نسخه 2.0 : داشبورد جدید
                  : گزارش‌گیری پیشرفته
```

</div>

<br/>

## 📖 مستندات

<div align="center">

[![Docs](https://img.shields.io/badge/📚_مستندات_کامل-667eea?style=for-the-badge)](https://v2board.com)
[![Wiki](https://img.shields.io/badge/📖_ویکی-764ba2?style=for-the-badge)](https://github.com/PoriyaVali/Irboard/wiki)
[![FAQ](https://img.shields.io/badge/❓_سوالات_متداول-9b59b6?style=for-the-badge)](https://github.com/PoriyaVali/Irboard/discussions)

</div>

<br/>

## 💖 حامیان

<div align="center">

تشکر ویژه از حامیان پروژه:

<a href="https://www.jetbrains.com/">
  <img src="https://resources.jetbrains.com/storage/products/company/brand/logos/jb_beam.png" alt="JetBrains" width="80"/>
</a>

<br/>

**[JetBrains](https://www.jetbrains.com/)** - ارائه لایسنس رایگان برای پروژه‌های متن‌باز

</div>

<br/>

## 🤝 مشارکت

<div align="center">

مشارکت شما در توسعه این پروژه ارزشمند است!

[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=for-the-badge)](https://github.com/PoriyaVali/Irboard/pulls)

</div>

1. پروژه را Fork کنید
2. شاخه جدید بسازید (`git checkout -b feature/amazing-feature`)
3. تغییرات را Commit کنید (`git commit -m 'Add amazing feature'`)
4. به شاخه Push کنید (`git push origin feature/amazing-feature`)
5. Pull Request ایجاد کنید

<br/>

## 🐛 گزارش مشکلات

<div align="center">

[![Issues](https://img.shields.io/badge/🐛_گزارش_مشکل-e74c3c?style=for-the-badge)](https://github.com/PoriyaVali/Irboard/issues/new?template=bug_report.md)
[![Feature Request](https://img.shields.io/badge/💡_درخواست_ویژگی-2ecc71?style=for-the-badge)](https://github.com/PoriyaVali/Irboard/issues/new?template=feature_request.md)

</div>

<br/>

## 📊 آمار پروژه

<div align="center">

![GitHub Stats](https://github-readme-stats.vercel.app/api?username=PoriyaVali&show_icons=true&theme=tokyonight&hide_border=true&bg_color=1a1b27&title_color=667eea&icon_color=764ba2)

[![Activity Graph](https://github-readme-activity-graph.vercel.app/graph?username=PoriyaVali&theme=tokyo-night&hide_border=true&bg_color=1a1b27&color=667eea&line=764ba2&point=ffffff)](https://github.com/PoriyaVali)

</div>

<br/>

<!-- Footer Wave -->
<img width="100%" src="https://capsule-render.vercel.app/api?type=waving&color=0:667eea,100:764ba2&height=120&section=footer"/>

<div align="center">

**ساخته شده با ❤️ توسط [PoriyaVali](https://github.com/PoriyaVali)**

<br/>

[![GitHub](https://img.shields.io/badge/GitHub-PoriyaVali-181717?style=flat-square&logo=github)](https://github.com/PoriyaVali)
[![Telegram](https://img.shields.io/badge/Telegram-Contact-26A5E4?style=flat-square&logo=telegram)](https://t.me/)

<br/>

<sub>⭐ اگر این پروژه برایتان مفید بود، ستاره دادن فراموش نشود!</sub>

</div>
