<?php
// config.local.php — مقادیر حساس. این فایل را هرگز در گیت/اشتراک عمومی نگذارید.
// (دسترسی وب به آن در .htaccess بسته شده است)

// توکن ربات از @BotFather (پیشنهاد: بعد از انتقال، توکن جدید بگیرید)
define('BOT_TOKEN', '9004047542:AAGDFFumuKt72OT3FRXwo_oqM25osXF23oc');

// آیدی عددی ادمین‌های ربات
define('ADMIN_IDS', [8872751291, 7623976106, 1434200690]);

// رشته‌ی تصادفی؛ برای امضای webhook تلگرام
define('WEBHOOK_SECRET', 'q7Vx2mNp9Rk4Tz8Lw3Bc6Yd1Hs5Jf0Ga');

// ⚠️ هرگز تغییر ندهید! کلیدهای لایسنس با این مقدار رمز/هش شده‌اند؛ تغییرش همه‌ی کلیدها را از کار می‌اندازد
define('SECRET_SALT', 'K9f2LmQ7xR4tY8vB3nC6pD1sH5jG0aZk9Wq2Er7Ty4Ui8Op3As6Df1Gh5Jk0Lz2M');

// امضای API قدیمی (api/activate.php و verify.php) — با تغییر آن باید مود هم به‌روز شود
define('API_SECRET', 'Sp1d3r_X9Kp!7vQm#2LzT8nR@5wYh4BcD');

// رمز ورود ادمین. ترجیحاً هش باشد: php -r "echo password_hash('رمز', PASSWORD_BCRYPT);"
define('ADMIN_PANEL_PASSWORD', '138S237@3278s');

// آدرس API که در پنل نمایش داده می‌شود
define('DEFAULT_API_URL', 'https://spiderxcheat.pingbaz.space/api.php');

