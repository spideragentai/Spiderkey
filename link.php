<?php
declare(strict_types=1);
// صفحه‌ی ورودی (DirectoryIndex). قبلاً رمز ادمین از طریق URL/فیلد hidden عبور می‌کرد؛
// حالا همه‌ی تنظیمات (آدرس API، Webhook و …) داخل پنل ادمین → Settings است.
header('Location: admin.php#settings', true, 302);
exit;
