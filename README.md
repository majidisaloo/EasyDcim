# EasyDCIM Bandwidth Manager for WHMCS

این ریپو یک WHMCS Addon Module کامل برای مدیریت ترافیک سرویس‌های EasyDCIM فراهم می‌کند.

## قابلیت‌ها

- شناسایی سرویس‌ها بر اساس PID/GID و Custom Fieldهای EasyDCIM.
- محاسبه مصرف ترافیک در بازه سیکل واقعی هر سرویس (`nextduedate`-based).
- اعمال سیاست محدودسازی پس از اتمام سهمیه:
  - Disable Portها
  - Suspend سرویس سفارش
  - یا هر دو
- خرید پکیج ترافیک اضافه تا پایان همان سیکل.
- Override دائمی سهمیه/مود/اکشن به‌ازای هر سرویس.
- Auto-Buy بر اساس آستانه Remaining GB و Credit مشتری.
- Graph Export از EasyDCIM (AggregateTraffic) با Cache برای Client Area.
- Cron poll و daily reconcile برای enforce/reconcile مستمر.
- لاگ کامل API callها و عملیات وضعیت.

## ساختار ماژول

- `modules/addons/easydcim_bw/easydcim_bw.php`: Entry pointهای استاندارد WHMCS addon.
- `modules/addons/easydcim_bw/lib/*`: منطق core شامل API client، cycle calculator، enforce manager، cache، خرید و ...
- `modules/addons/easydcim_bw/templates/admin/dashboard.php`: UI مدیریت defaults/overrides/packages/logs.
- `modules/addons/easydcim_bw/templates/client/dashboard.tpl`: UI کلاینت برای مشاهده وضعیت و خرید ترافیک اضافه.
- `modules/addons/easydcim_bw/cron/poll.php`: کران اصلی بررسی مصرف.
- `modules/addons/easydcim_bw/cron/reconcile.php`: کران daily reconcile.

## Custom Fieldهای مورد انتظار روی Service

روی محصول/سرویس در WHMCS این custom fieldها را تعریف کنید:

- `easydcim_service_id` (الزامی)
- `easydcim_order_id` (برای suspend/unsuspend)
- `easydcim_server_id` (اختیاری)
- `traffic_mode` (اختیاری: IN/OUT/TOTAL)
- `base_quota_override_gb` (اختیاری)

## DB Tables

ماژول در activation این جدول‌ها را می‌سازد:

1. `mod_easydcim_bw_product_defaults`
2. `mod_easydcim_bw_service_state`
3. `mod_easydcim_bw_purchases`
4. `mod_easydcim_bw_graph_cache`
5. `mod_easydcim_bw_logs`
6. `mod_easydcim_bw_packages`
7. `mod_easydcim_bw_service_overrides`

## نصب

1. پوشه `modules/addons/easydcim_bw` را داخل WHMCS کپی کنید.
2. در WHMCS > System Settings > Addon Modules ماژول را Activate کنید.
3. دسترسی ادمین‌ها را بدهید.
4. تنظیمات اتصال (Base URL + Token + impersonation) و PID/GIDها را در تنظیمات ماژول ذخیره کنید.
5. Product defaults، packageها و overrideها را از صفحه ادمین ماژول ثبت کنید.

## Cron

نمونه:

```bash
*/15 * * * * php -q /path/to/whmcs/modules/addons/easydcim_bw/cron/poll.php
5 0 * * * php -q /path/to/whmcs/modules/addons/easydcim_bw/cron/reconcile.php
```

## API Endpointهای پیاده‌سازی‌شده

- `POST /api/v3/client/services/{id}/bandwidth`
- `GET /api/v3/client/services/{id}/ports?with_traffic=true`
- `POST /api/v3/admin/ports/{id}/disable`
- `POST /api/v3/admin/ports/{id}/enable`
- `POST /api/v3/admin/orders/{id}/service/suspend`
- `POST /api/v3/admin/orders/{id}/service/unsuspend`
- `POST /api/v3/client/graphs/{id}/export`

## نکات

- محاسبه usage داخلی بر مبنای bytes→GB انجام می‌شود.
- پکیج‌های اضافه فقط برای همان cycle محاسبه می‌شوند (با `cycle_start` و `cycle_end` ذخیره‌شده).
- Graphها cache می‌شوند تا load روی API کاهش یابد.
