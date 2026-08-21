SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_tracks`;
CREATE TABLE `payment_tracks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `trade_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `track_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'شناسه سفارش',
  `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'شناسه کاربر',
  `amount` int(11) NOT NULL DEFAULT '0' COMMENT 'مبلغ',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `used_at` timestamp NULL DEFAULT NULL COMMENT 'زمان استفاده',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_tracks_trade_no_track_id_unique` (`trade_no`,`track_id`),
  KEY `payment_tracks_trade_no_index` (`trade_no`),
  KEY `payment_tracks_track_id_index` (`track_id`),
  KEY `payment_tracks_order_id_index` (`order_id`),
  KEY `payment_tracks_user_id_index` (`user_id`),
  KEY `payment_tracks_is_used_index` (`is_used`),
  KEY `payment_tracks_created_at_index` (`created_at`),
  KEY `payment_tracks_is_used_created_at_index` (`is_used`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sms_logs`;
CREATE TABLE `sms_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_bot_channels`;
CREATE TABLE `v2_bot_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'آیدی یا یوزرنیم کانال',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_link` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_bot_panels`;
CREATE TABLE `v2_bot_panels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نام پنل',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'marzban' COMMENT 'نوع: marzban, x-ui, s-ui, marzneshin, mikrotik, wg',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'آدرس پنل',
  `username` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text COLLATE utf8mb4_unicode_ci COMMENT 'توکن دسترسی',
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `sub_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'لینک اشتراک',
  `inbounds` text COLLATE utf8mb4_unicode_ci COMMENT 'اینباندها JSON',
  `proxies` text COLLATE utf8mb4_unicode_ci COMMENT 'پراکسی‌ها JSON',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'فعال/غیرفعال',
  `test_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'اکانت تست فعال',
  `on_hold_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `username_method` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'random' COMMENT 'روش ساخت یوزرنیم',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_bot_settings`;
CREATE TABLE `v2_bot_settings` (
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_bot_texts`;
CREATE TABLE `v2_bot_texts` (
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'متن',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_card_payment_config`;
CREATE TABLE `v2_card_payment_config` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_number` varchar(20) NOT NULL COMMENT 'شماره کارت',
  `card_holder` varchar(100) NOT NULL COMMENT 'نام صاحب کارت',
  `bank_name` varchar(50) NOT NULL COMMENT 'نام بانک',
  `is_active` tinyint(1) DEFAULT '1',
  `min_amount` int(11) DEFAULT '50000' COMMENT 'حداقل مبلغ (تومان)',
  `max_amount` int(11) DEFAULT '50000000' COMMENT 'حداکثر مبلغ (تومان)',
  `expire_minutes` int(11) DEFAULT '30' COMMENT 'مهلت پرداخت (دقیقه)',
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='تنظیمات کارت به کارت';

DROP TABLE IF EXISTS `v2_card_payments`;
CREATE TABLE `v2_card_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL COMMENT 'ID سفارش',
  `trade_no` varchar(36) NOT NULL COMMENT 'شماره سفارش',
  `user_id` int(10) unsigned NOT NULL COMMENT 'ID کاربر',
  `expected_amount` int(11) NOT NULL COMMENT 'مبلغ مورد انتظار',
  `actual_amount` int(11) DEFAULT NULL COMMENT 'مبلغ واقعی واریز شده',
  `card_number` varchar(20) NOT NULL COMMENT 'شماره کارت مقصد',
  `card_holder` varchar(100) NOT NULL COMMENT 'نام صاحب کارت',
  `tracking_number` varchar(50) DEFAULT NULL COMMENT 'شماره پیگیری بانکی',
  `receipt_file_id` varchar(255) DEFAULT NULL,
  `status` enum('pending','claimed','verified_full','verified_partial','verified_excess','rejected','expired','cancelled') DEFAULT 'pending',
  `claimed_at` int(10) unsigned DEFAULT NULL COMMENT 'زمان ادعای واریز',
  `claim_ip` varchar(45) DEFAULT NULL,
  `claim_user_agent` text,
  `amount_fingerprint` varchar(64) NOT NULL COMMENT 'fingerprint مبلغ+زمان',
  `tracking_fingerprint` varchar(64) DEFAULT NULL COMMENT 'fingerprint شماره پیگیری',
  `duplicate_warning` tinyint(1) DEFAULT '0' COMMENT 'هشدار تکراری',
  `wallet_transaction_id` int(10) unsigned DEFAULT NULL,
  `wallet_amount` int(11) DEFAULT NULL COMMENT 'مبلغ اضافه شده به کیف پول',
  `verified_at` int(10) unsigned DEFAULT NULL,
  `verified_by` int(10) unsigned DEFAULT NULL COMMENT 'admin_id',
  `admin_note` text,
  `reject_reason` varchar(255) DEFAULT NULL,
  `telegram_message_id` bigint(20) DEFAULT NULL,
  `telegram_chat_id` bigint(20) DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `expires_at` int(10) unsigned NOT NULL COMMENT 'زمان انقضا',
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  UNIQUE KEY `uk_trade` (`trade_no`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_fingerprint` (`amount_fingerprint`,`status`),
  KEY `idx_tracking` (`tracking_fingerprint`,`status`),
  KEY `idx_expires` (`expires_at`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='پرداخت‌های کارت به کارت';

DROP TABLE IF EXISTS `v2_commission_log`;
CREATE TABLE `v2_commission_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_user_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `trade_no` char(36) NOT NULL,
  `order_amount` int(11) NOT NULL,
  `get_amount` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_coupon`;
CREATE TABLE `v2_coupon` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
  `type` tinyint(1) NOT NULL,
  `value` int(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `limit_use` int(11) DEFAULT NULL,
  `limit_use_with_user` int(11) DEFAULT NULL,
  `limit_plan_ids` varchar(255) DEFAULT NULL,
  `limit_period` varchar(255) DEFAULT NULL,
  `started_at` int(11) NOT NULL,
  `ended_at` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_giftcard`;
CREATE TABLE `v2_giftcard` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
  `type` tinyint(1) NOT NULL,
  `value` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `limit_use` int(11) DEFAULT NULL,
  `used_user_ids` varchar(255) DEFAULT NULL,
  `started_at` int(11) NOT NULL,
  `ended_at` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_invite_code`;
CREATE TABLE `v2_invite_code` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code` char(32) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `pv` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_knowledge`;
CREATE TABLE `v2_knowledge` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `language` char(5) NOT NULL COMMENT '語言',
  `category` varchar(255) NOT NULL COMMENT '分類名',
  `title` varchar(255) NOT NULL COMMENT '標題',
  `body` text NOT NULL COMMENT '內容',
  `sort` int(11) DEFAULT NULL COMMENT '排序',
  `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '顯示',
  `created_at` int(11) NOT NULL COMMENT '創建時間',
  `updated_at` int(11) NOT NULL COMMENT '更新時間',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知識庫';

DROP TABLE IF EXISTS `v2_log`;
CREATE TABLE `v2_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` text NOT NULL,
  `level` varchar(11) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  `uri` varchar(500) DEFAULT NULL,
  `method` varchar(11) NOT NULL,
  `data` text,
  `ip` varchar(128) DEFAULT NULL,
  `context` text,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_mail_log`;
CREATE TABLE `v2_mail_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(64) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `error` text,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_notice`;
CREATE TABLE `v2_notice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `img_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_order`;
CREATE TABLE `v2_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_user_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `coupon_id` int(11) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `type` int(11) NOT NULL COMMENT '1新购2续费3升级',
  `period` varchar(255) NOT NULL,
  `trade_no` varchar(36) NOT NULL,
  `callback_no` varchar(255) DEFAULT NULL,
  `total_amount` int(11) NOT NULL,
  `handling_amount` int(11) DEFAULT NULL,
  `discount_amount` int(11) DEFAULT NULL,
  `surplus_amount` int(11) DEFAULT NULL COMMENT '剩余价值',
  `refund_amount` int(11) DEFAULT NULL COMMENT '退款金额',
  `balance_amount` int(11) DEFAULT NULL COMMENT '使用余额',
  `exchange_rate` int(11) DEFAULT NULL,
  `surplus_order_ids` text COMMENT '折抵订单',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待支付1开通中2已取消3已完成4已折抵',
  `source` varchar(20) NOT NULL DEFAULT 'web' COMMENT 'منبع سفارش: web, telegram',
  `commission_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待确认1发放中2有效3无效',
  `commission_balance` int(11) NOT NULL DEFAULT '0',
  `actual_commission_balance` int(11) DEFAULT NULL COMMENT '实际支付佣金',
  `paid_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trade_no` (`trade_no`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_payment`;
CREATE TABLE `v2_payment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(32) NOT NULL,
  `payment` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `config` text NOT NULL,
  `notify_domain` varchar(128) DEFAULT NULL,
  `handling_fee_fixed` int(11) DEFAULT NULL,
  `handling_fee_percent` decimal(5,2) DEFAULT NULL,
  `enable` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_plan`;
CREATE TABLE `v2_plan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `transfer_enable` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `device_limit` int(11) DEFAULT NULL,
  `speed_limit` int(11) DEFAULT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `renew` tinyint(1) NOT NULL DEFAULT '1',
  `carry_over_days` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'انتقال روزهای باقیمانده در تمدید',
  `content` text,
  `month_price` int(11) DEFAULT NULL,
  `quarter_price` int(11) DEFAULT NULL,
  `half_year_price` int(11) DEFAULT NULL,
  `year_price` int(11) DEFAULT NULL,
  `month_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت ماهانه به دلار',
  `quarter_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت سه‌ماهه به دلار',
  `half_year_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت شش‌ماهه به دلار',
  `year_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت سالانه به دلار',
  `two_year_price` int(11) DEFAULT NULL,
  `two_year_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت دو‌ساله به دلار',
  `three_year_price` int(11) DEFAULT NULL,
  `three_year_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت سه‌ساله به دلار',
  `onetime_price` int(11) DEFAULT NULL,
  `onetime_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت یکباره به دلار',
  `reset_price` int(11) DEFAULT NULL,
  `reset_price_usd` decimal(10,2) DEFAULT NULL COMMENT 'قیمت ریست به دلار',
  `reset_traffic_method` tinyint(1) DEFAULT NULL,
  `capacity_limit` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  `price_updated_at` timestamp NULL DEFAULT NULL COMMENT 'آخرین زمان بروزرسانی قیمت‌ها',
  `last_exchange_rate` int(11) DEFAULT NULL COMMENT 'آخرین نرخ ارز استفاده شده',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_plan_prices`;
CREATE TABLE `v2_plan_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) unsigned NOT NULL,
  `month_price_usd` decimal(10,2) DEFAULT NULL,
  `quarter_price_usd` decimal(10,2) DEFAULT NULL,
  `half_year_price_usd` decimal(10,2) DEFAULT NULL,
  `year_price_usd` decimal(10,2) DEFAULT NULL,
  `two_year_price_usd` decimal(10,2) DEFAULT NULL,
  `three_year_price_usd` decimal(10,2) DEFAULT NULL,
  `onetime_price_usd` decimal(10,2) DEFAULT NULL,
  `reset_price_usd` decimal(10,2) DEFAULT NULL,
  `last_exchange_rate` int(10) unsigned DEFAULT NULL,
  `price_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_reseller_log`;
CREATE TABLE `v2_reseller_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `period` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_price` int(11) NOT NULL,
  `discount` int(11) NOT NULL,
  `final_price` int(11) NOT NULL,
  `staff_balance_after` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `v2_reseller_log_staff_id_index` (`staff_id`),
  KEY `v2_reseller_log_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_reserved_plans`;
CREATE TABLE `v2_reserved_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `period` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0=رزرو، 1=فعال شده، 2=لغو شده',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  `activated_at` int(11) DEFAULT NULL COMMENT 'زمان فعال‌سازی',
  PRIMARY KEY (`id`),
  KEY `v2_reserved_plans_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_server_anytls`;
CREATE TABLE `v2_server_anytls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `tunnel_host` varchar(255) DEFAULT NULL,
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `server_name` varchar(64) DEFAULT NULL,
  `insecure` tinyint(1) NOT NULL DEFAULT '0',
  `padding_scheme` text,
  `tls` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=plain TLS, 2=REALITY. anytls is never plaintext, so 0 is not a valid value here',
  `tls_settings` text COMMENT 'REALITY keys and/or ECH config, same shape as v2_server_v2node.tls_settings',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_group`;
CREATE TABLE `v2_server_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `addon_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'sellable as a paid add-on on top of a plan',
  `price_per_gb` int(11) NOT NULL DEFAULT '0' COMMENT 'wallet cost per GB, same unit as v2_user.balance',
  `addon_note` varchar(500) DEFAULT NULL COMMENT 'what this add-on is, shown to the customer in the app, NULL falls back to the app own wording',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_user_group`;
CREATE TABLE `v2_user_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `expired_at` int(11) DEFAULT NULL COMMENT 'NULL means a permanent admin grant, otherwise it ends with the base plan',
  `is_paid` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'charge this grant to the wallet per GB',
  `unbilled_bytes` bigint(20) NOT NULL DEFAULT '0' COMMENT 'traffic carried to the next charge so nothing is rounded up',
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_group` (`user_id`,`group_id`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='extra access groups an admin granted on top of v2_user.group_id';

DROP TABLE IF EXISTS `v2_user_group_usage`;
CREATE TABLE `v2_user_group_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `u` bigint(20) NOT NULL DEFAULT '0',
  `d` bigint(20) NOT NULL DEFAULT '0',
  `amount` int(11) NOT NULL DEFAULT '0' COMMENT 'charged to the wallet, same unit as v2_user.balance',
  `record_at` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_group_day` (`user_id`,`group_id`,`record_at`),
  KEY `idx_group_day` (`group_id`,`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='per-day ledger of paid add-on traffic and what it cost';

DROP TABLE IF EXISTS `v2_server_hysteria`;
CREATE TABLE `v2_server_hysteria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` int(11) NOT NULL,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `tunnel_host` varchar(255) DEFAULT NULL,
  `port` varchar(255) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `up_mbps` int(11) NOT NULL,
  `down_mbps` int(11) NOT NULL,
  `obfs` varchar(64) DEFAULT NULL,
  `obfs_password` varchar(255) DEFAULT NULL,
  `server_name` varchar(64) DEFAULT NULL,
  `insecure` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_mdns`;
CREATE TABLE `v2_server_mdns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_id` text COLLATE utf8mb4_unicode_ci,
  `parent_id` int(11) DEFAULT NULL,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `server_port` int(11) NOT NULL,
  `domain` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `encryption_method` int(11) NOT NULL DEFAULT '2',
  `encryption_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL DEFAULT '1.00',
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_server_trusttunnel`;
CREATE TABLE `v2_server_trusttunnel` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_id` text COLLATE utf8mb4_unicode_ci,
  `parent_id` int(11) DEFAULT NULL,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `server_port` int(11) NOT NULL,
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'certificate hostname, falls back to host',
  `cert_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'self-signed' COMMENT 'self-signed | letsencrypt | provided',
  `acme_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'required when cert_type=letsencrypt',
  `cert_chain_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'required when cert_type=provided',
  `cert_key_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'required when cert_type=provided',
  `custom_sni` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anti_dpi` tinyint(1) NOT NULL DEFAULT '0',
  `client_random_prefix` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'connection filter, NOT authentication',
  `upstream_protocol` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'http2 | http3, empty lets the client choose',
  `has_ipv6` tinyint(1) NOT NULL DEFAULT '0',
  `dns_upstreams` text COLLATE utf8mb4_unicode_ci COMMENT 'one per line, encrypted DNS through the tunnel',
  `rate` decimal(10,2) NOT NULL DEFAULT '1.00',
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `v2_server_route`;
CREATE TABLE `v2_server_route` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `remarks` varchar(255) NOT NULL,
  `match` text NOT NULL,
  `action` varchar(11) NOT NULL,
  `action_value` text,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_shadowsocks`;
CREATE TABLE `v2_server_shadowsocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `rate` varchar(11) NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `cipher` varchar(255) NOT NULL,
  `obfs` char(11) DEFAULT NULL,
  `obfs_settings` varchar(255) DEFAULT NULL,
  `show` tinyint(4) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_trojan`;
CREATE TABLE `v2_server_trojan` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '节点ID',
  `group_id` varchar(255) NOT NULL COMMENT '节点组',
  `route_id` varchar(255) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL COMMENT '父节点',
  `tags` varchar(255) DEFAULT NULL COMMENT '节点标签',
  `name` varchar(255) NOT NULL COMMENT '节点名称',
  `rate` varchar(11) NOT NULL COMMENT '倍率',
  `host` varchar(255) NOT NULL COMMENT '主机名',
  `port` varchar(11) NOT NULL COMMENT '连接端口',
  `server_port` int(11) NOT NULL COMMENT '服务端口',
  `network` varchar(11) DEFAULT NULL,
  `network_settings` text,
  `allow_insecure` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否允许不安全',
  `server_name` varchar(255) DEFAULT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否显示',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='trojan伺服器表';

DROP TABLE IF EXISTS `v2_server_tuic`;
CREATE TABLE `v2_server_tuic` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `tunnel_host` varchar(255) DEFAULT NULL,
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `server_name` varchar(64) DEFAULT NULL,
  `insecure` tinyint(1) NOT NULL DEFAULT '0',
  `disable_sni` tinyint(1) NOT NULL DEFAULT '0',
  `udp_relay_mode` varchar(64) DEFAULT NULL,
  `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0',
  `congestion_control` varchar(64) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_v2node`;
CREATE TABLE `v2_server_v2node` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `listen_ip` varchar(255) NOT NULL DEFAULT '0.0.0.0',
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `protocol` varchar(24) NOT NULL COMMENT '协议类型',
  `tls` tinyint(1) NOT NULL COMMENT 'tls类型',
  `tls_settings` text COMMENT 'tls配置',
  `flow` varchar(64) DEFAULT NULL COMMENT 'vless流控',
  `network` varchar(11) NOT NULL COMMENT '传输类型',
  `network_settings` text COMMENT '传输配置',
  `encryption` varchar(64) DEFAULT NULL COMMENT 'vless加密',
  `encryption_settings` text COMMENT 'vless加密配置',
  `disable_sni` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic禁用sni',
  `udp_relay_mode` varchar(64) DEFAULT NULL COMMENT 'tuic udp中继模式',
  `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic 0rtt握手',
  `congestion_control` varchar(64) DEFAULT NULL COMMENT 'tuic拥塞控制',
  `cipher` varchar(64) DEFAULT NULL COMMENT 'shadowsocks加密方式',
  `up_mbps` int(11) NOT NULL COMMENT 'hysteria上行带宽',
  `down_mbps` int(11) NOT NULL COMMENT 'hysteria下行带宽',
  `obfs` varchar(64) DEFAULT NULL COMMENT 'hysteria1混淆密码/hysteria2混淆类型',
  `obfs_password` varchar(255) DEFAULT NULL COMMENT 'hysteria2混淆密码',
  `padding_scheme` text COMMENT 'anytls填充配置',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_v2ray`;
CREATE TABLE `v2_server_v2ray` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tls` tinyint(4) NOT NULL DEFAULT '0',
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `network` text NOT NULL,
  `rules` text,
  `networkSettings` text,
  `tlsSettings` text,
  `ruleSettings` text,
  `dnsSettings` text,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_vless`;
CREATE TABLE `v2_server_vless` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` text NOT NULL,
  `route_id` text,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `port` int(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tls` tinyint(1) NOT NULL,
  `tls_settings` text,
  `flow` varchar(64) DEFAULT NULL,
  `network` varchar(11) NOT NULL,
  `network_settings` text,
  `encryption` varchar(64) DEFAULT NULL,
  `encryption_settings` text,
  `tags` text,
  `rate` varchar(11) NOT NULL,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_vmess`;
CREATE TABLE `v2_server_vmess` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(255) NOT NULL,
  `route_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `host` varchar(255) NOT NULL,
  `port` varchar(11) NOT NULL,
  `server_port` int(11) NOT NULL,
  `tls` tinyint(4) NOT NULL DEFAULT '0',
  `tags` varchar(255) DEFAULT NULL,
  `rate` varchar(11) NOT NULL,
  `network` varchar(11) NOT NULL,
  `rules` text,
  `networkSettings` text,
  `tlsSettings` text,
  `ruleSettings` text,
  `dnsSettings` text,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_sms_log`;
CREATE TABLE `v2_sms_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(15) NOT NULL,
  `message` text NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `error` text,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `message_id` varchar(100) DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `delivered_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_stat`;
CREATE TABLE `v2_stat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `record_at` int(11) NOT NULL,
  `record_type` char(1) NOT NULL,
  `order_count` int(11) NOT NULL,
  `order_total` int(11) NOT NULL,
  `register_count` int(11) NOT NULL,
  `invite_count` int(11) NOT NULL,
  `transfer_used_total` varchar(32) NOT NULL,
  `paid_count` int(11) NOT NULL COMMENT '订单数量',
  `paid_total` int(11) NOT NULL COMMENT '订单合计',
  `commission_count` int(11) NOT NULL,
  `commission_total` int(11) NOT NULL COMMENT '佣金合计',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `record_at` (`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='订单统计';

DROP TABLE IF EXISTS `v2_stat_server`;
CREATE TABLE `v2_stat_server` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL COMMENT '节点id',
  `server_type` char(11) NOT NULL COMMENT '节点类型',
  `u` bigint(20) NOT NULL,
  `d` bigint(20) NOT NULL,
  `record_type` char(1) NOT NULL COMMENT 'd day m month',
  `record_at` int(11) NOT NULL COMMENT '记录时间',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id_server_type_record_at` (`server_id`,`server_type`,`record_at`),
  KEY `record_at` (`record_at`),
  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='节点数据统计';

DROP TABLE IF EXISTS `v2_stat_user`;
CREATE TABLE `v2_stat_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `server_rate` decimal(10,2) NOT NULL,
  `u` bigint(20) NOT NULL,
  `d` bigint(20) NOT NULL,
  `record_type` char(2) NOT NULL,
  `record_at` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_rate_user_id_record_at` (`server_rate`,`user_id`,`record_at`),
  KEY `user_id` (`user_id`),
  KEY `record_at` (`record_at`),
  KEY `server_rate` (`server_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_stat_user_device`;
-- Per-account device history.
--
-- The panel already counts a user's live devices - the nodes push their alive
-- IPs and UniProxyController folds them into ALIVE_IP_USER_<id> in the cache,
-- where device_limit_mode=1 makes that a count of DISTINCT IPs rather than of
-- connections. What it has never had is a memory of it: the number is live and
-- then it is gone.
--
-- One row per user per day. A five-minute sampler keeps the peak, and enough
-- to average, which is what makes "how many devices was I using" answerable
-- for yesterday as well as for now.
--
-- Shaped to match v2_stat_user next door: int timestamps, a unique key on the
-- pair that must not repeat, and an index on each column anything filters by.
CREATE TABLE `v2_stat_user_device` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  -- Midnight of the day this row covers, server time.
  `record_at` int(11) NOT NULL,
  -- The most devices seen at once that day: the number that answers "is
  -- someone else on my account".
  `peak` smallint(5) unsigned NOT NULL DEFAULT '0',
  -- Sum and count of the samples, so an average can be taken without storing
  -- every sample. A day of five-minute samples is 288 of them, and 288 rows
  -- per user per day is not worth what it would buy.
  `total` int(11) NOT NULL DEFAULT '0',
  `samples` smallint(5) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_record_at` (`user_id`,`record_at`),
  KEY `record_at` (`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_ticket`;
CREATE TABLE `v2_ticket` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `level` tinyint(1) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:已开启 1:已关闭',
  `reply_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:待回复 1:已回复',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_ticket_message`;
CREATE TABLE `v2_ticket_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `message` text CHARACTER SET utf8mb4 NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_tunnel_map`;
CREATE TABLE `v2_tunnel_map` (
  `server_id` int(11) NOT NULL,
  `direct_host` varchar(255) NOT NULL,
  `tunnel_ip` varchar(255) NOT NULL,
  PRIMARY KEY (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_tutorial`;
CREATE TABLE `v2_tutorial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
  `steps` text,
  `show` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_user`;
CREATE TABLE `v2_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_user_id` int(11) DEFAULT NULL,
  `telegram_id` bigint(20) DEFAULT NULL,
  `bot_step` varchar(100) DEFAULT NULL COMMENT 'مرحله فعلی در ربات',
  `bot_data` text COMMENT 'داده موقت ربات JSON',
  `bot_test_count` int(11) NOT NULL DEFAULT '0' COMMENT 'تعداد تست گرفته',
  `bot_ref_code` varchar(32) DEFAULT NULL COMMENT 'کد معرف',
  `bot_referrer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'معرف کاربر',
  `email` varchar(64) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `phone_verified` tinyint(1) NOT NULL DEFAULT '0',
  `phone_verified_at` int(11) DEFAULT NULL,
  `password` varchar(64) NOT NULL,
  `password_algo` char(10) DEFAULT NULL,
  `password_salt` char(10) DEFAULT NULL,
  `balance` int(11) NOT NULL DEFAULT '0',
  `discount` int(11) DEFAULT NULL,
  `commission_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0: system 1: period 2: onetime',
  `commission_rate` int(11) DEFAULT NULL,
  `commission_balance` int(11) NOT NULL DEFAULT '0',
  `t` int(11) NOT NULL DEFAULT '0',
  `u` bigint(20) NOT NULL DEFAULT '0',
  `d` bigint(20) NOT NULL DEFAULT '0',
  `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
  `device_limit` int(11) DEFAULT NULL,
  `banned` tinyint(1) NOT NULL DEFAULT '0',
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` int(11) DEFAULT NULL,
  `is_staff` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_ip` int(11) DEFAULT NULL,
  `uuid` varchar(36) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `speed_limit` int(11) DEFAULT NULL,
  `auto_renewal` tinyint(4) NOT NULL DEFAULT '0',
  `remind_expire` tinyint(4) DEFAULT '1',
  `remind_traffic` tinyint(4) DEFAULT '1',
  `token` char(32) NOT NULL,
  `expired_at` bigint(20) DEFAULT '0',
  `remarks` text,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `google_id` (`google_id`),
  UNIQUE KEY `v2_user_bot_ref_code_unique` (`bot_ref_code`),
  KEY `idx_phone` (`phone`),
  KEY `idx_sms_reminders` (`phone_verified`,`remind_traffic`,`remind_expire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `v2_user_banned_backup`;
CREATE TABLE `v2_user_banned_backup` (
  `id` int(11) NOT NULL DEFAULT '0',
  `banned` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `migrations` (`id`,`migration`,`batch`) VALUES
(1,'2019_08_19_000000_create_failed_jobs_table',1),
(2,'2025_09_02_032551_create_sms_logs_table',2),
(3,'2025_09_02_033507_add_error_to_sms_logs_table',3),
(4,'2025_10_02_152631_add_google_id_to_users_table',4),
(5,'2025_10_20_150000_add_usd_prices_to_v2_plan_table',5),
(6,'2025_10_21_135725_create_settings_table',6),
(7,'2025_10_22_010145_add_usd_prices_to_v2_plan_table',1),
(8,'2025_10_22_010145_add_usd_prices_to_v2_plan_table',1),
(9,'2025_10_22_010236_create_settings_table',1),
(10,'2025_10_22_145625_create_payment_tracks_table',7),
(11,'2025_11_15_214639_increase_uri_length_in_v2_log_table',8),
(12,'2025_10_25_000001_create_payment_tracks_table',9),
(13,'2025_12_29_102129_create_plan_prices_table',9),
(14,'2026_01_01_172208_add_exchange_rate_to_orders_table',9),
(15,'2026_01_01_212259_create_bot_panels_table',10),
(16,'2026_01_02_030235_add_source_to_orders_table',11),
(17,'2026_01_03_000001_add_payment_transit_settings',12),
(18,'2026_01_29_000001_create_external_configs_table',13),
(19,'2026_02_14_003710_add_carry_over_days_to_v2_plan_table',14),
(20,'2026_02_14_011103_create_v2_reserved_plans_table',15),
(21,'2026_07_03_100000_create_v2_server_mdns_table',16),
(22,'2026_08_15_100000_create_v2_server_trusttunnel_table',17);

DROP TABLE IF EXISTS `v2_mirror_export`;
-- The snapshot the Iranian relay pulls, so it can still answer a paying
-- subscriber when the link out of Iran is down.
--
-- Why a table and not a live endpoint: the export has to render each user's
-- subscription, and the Clash-family renderers call the global header()
-- directly. Doing that inside an HTTP request would inject
-- subscription-userinfo and content-disposition into the export's own
-- response, and under webman - where a worker outlives the request - those
-- headers can reach the next request too. In CLI, header() is inert. So a
-- scheduled command renders into this table and the endpoint only reads it.
--
-- It also keeps the cost off the request path: a measured full build is 70
-- seconds for the 336 accounts that have a live plan (33 when most are
-- unchanged), which is fine once an hour in the background and not fine while
-- a worker is held open.
--
-- 🔑 Nothing here identifies anyone to someone who steals the table. The
-- subjects are sha256 digests of the credentials, never the credentials, so
-- the relay - a box in Iran that nobody logs into - can match what a caller
-- presents without ever holding a token it could replay.
CREATE TABLE `v2_mirror_export` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  -- One JSON document per user: the session digests, the bodies of
  -- getSubscribe and info, and one rendered subscription per client flag.
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  -- sha256 of the payload. Lets a build skip writing a row that has not
  -- changed, and lets a future sync ask for only what moved.
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `built_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  -- The endpoint pages by id, and staleness is judged by built_at.
  KEY `built_at` (`built_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
