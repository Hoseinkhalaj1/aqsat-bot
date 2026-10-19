<div dir="rtl">

# API مینی‌اپ

یک نقطه ورود: `POST api.php` با بدنه JSON:

```json
{ "action": "dash", "...": "..." }
```

پاسخ:
```json
{ "ok": true,  "data": { } }
{ "ok": false, "error": "bad_amount" }
```

## احراز هویت
هر درخواست باید هدر `X-Init-Data` را با مقدار `Telegram.WebApp.initData` داشته باشد. سرور:
1. همه فیلدها به‌جز `hash` را به ترتیب الفبا به‌صورت `key=value` با `\n` به هم می‌چسباند.
2. `secret = HMAC_SHA256(key="WebAppData", msg=bot_token)`
3. `hash` باید برابر `hex(HMAC_SHA256(key=secret, msg=data_check_string))` باشد.
4. `auth_date` حداکثر ۲۴ ساعت قدیمی باشد و کاربر در ربات **مجاز** باشد.

خطاهای احراز هویت با کد HTTP ۴۰۱: `no_auth`، `bad_auth`، `expired`، `forbidden`.

## اکشن‌ها

| اکشن | ورودی | توضیح |
|---|---|---|
| `boot` | — | کاربر و تنظیمات، امروز، دسته‌ها، درآمدها، کاربران دیگر |
| `dash` | — | برنامه ماه، اقساط ماه جاری (و ماه بعد از ۲۰ام) با منبع، پرسش‌های واریز، معوقه، بدهی |
| `obs` | — | تعهدات فعال و بایگانی |
| `ob` | `id` | جزئیات یک تعهد و همه سررسیدها با پرداخت‌ها |
| `create` | `type, title, category_id, creditor, first_date, amounts[] \| amount, period_unit, period_n, paid_count, shared_user_id, owner_share, income_id` | افزودن |
| `ob_update` | `id, title?, category_id?, creditor?, note?, amount?` | ویرایش (فقط مالک) |
| `due_update` | `id, amount?, date?` | ویرایش یک سررسید (فقط مالک) |
| `pay` | `due_id, amount?` | پرداخت کامل یا جزئی |
| `undo` | `due_id` | لغو آخرین پرداخت |
| `settle` / `stop` / `resume` / `del` | `id` | تسویه کامل، توقف، ادامه، حذف |
| `plan` | `off` | برنامه تخصیص ماه (off = فاصله از ماه جاری) |
| `assign` | `ob_id, income_id` | تخصیص دستی (۰ = حذف تخصیص) |
| `mode` | `mode: auto \| manual` | حالت تخصیص |
| `income_save` / `income_del` | `id?, title, amount, day, active` | مدیریت درآمد |
| `income_override` | `id, ym, amount?` | مبلغ واقعی یک ماه (`ym` مثل `1405-08`) |
| `deposit` | `income_id, ym, amount?` | ثبت واریز؛ برنامه همان واریز را برمی‌گرداند |
| `set_aside` | `income_id, ym, undo?` | «کنار گذاشتم» |
| `calendar` | `jy, jm` | سررسیدها و واریزهای یک ماه شمسی |
| `reports` | `off` | شاخص‌ها، دسته‌ها، پیش‌بینی، بزرگ‌ترین تعهدها، وام‌ها، سال |
| `excel` | `year?` | ارسال فایل اکسل در چت ربات |
| `send_summary` | `off` | ارسال خلاصه ماه در ربات |
| `settings` | `remind_days[], remind_hour, overdue_daily, monthly_summary, income_remind` | تنظیمات یادآوری |
| `cat_save` / `cat_del` | `id?, name, emoji, budget` | دسته‌ها |

تاریخ‌ها در ورودی و خروجی میلادی (`YYYY-MM-DD`) هستند؛ مبالغ به تومان و عدد صحیح. فیلد `mine` در سررسیدها سهم کاربر جاری است.

</div>
