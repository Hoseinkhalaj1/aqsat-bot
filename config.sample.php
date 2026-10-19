<?php
/* این فایل را به config.php تغییر نام دهید و مقادیر را پر کنید */
return [
    'bot_token'      => '123456789:AAAA-your-bot-token',           // از @BotFather
    'admin_ids'      => [111111111],                               // شناسه عددی شما (از @userinfobot)
    'base_url'       => 'https://example.com/aqsat-bot',           // آدرس پوشه ربات (حتماً https)
    'webhook_secret' => 'CHANGE-ME',                              // رشته تصادفی ۳۲ کاراکتری (حروف انگلیسی، عدد، _ و -) — مثلاً خروجی: openssl rand -hex 16
    'cron_key'       => 'CHANGE-ME',                              // رشته تصادفی دیگر (۱۶+ کاراکتر) برای install و cron از طریق URL
    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_aqsat',
        'user' => 'cpaneluser_aqsat',
        'pass' => 'database-password',
    ],
    'timezone'           => 'Asia/Tehran',
    'backup_hour'        => 3,     // ساعت بکاپ خودکار روزانه
    'backup_keep'        => 7,     // تعداد بکاپ‌های نگهداری‌شده روی هاست
    'backup_to_telegram' => true,  // ارسال بکاپ برای مدیر در تلگرام
];
