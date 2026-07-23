# نظام البحث عن الوظائف والتواصل التلقائي

سيستم شخصي بيسحب إعلانات وظايف عبر **Apify**، يستخرج إيميلات وأرقام تليفونات الشركات اللي بتوظّف، ويبعت لهم تلقائيًا إيميل تقديم رسمي (بقالب ثابت + CV مرفق + رقم واتساب) — إيميل واحد كل فترة عشوائية قصيرة، مع تتبع فتح الإيميل. مبني على **Laravel 13 + React (Vite)** وواجهة عربية RTL.

## المكوّنات باختصار

| الجزء | الوصف |
|---|---|
| **السحب (Scraping)** | أكتورز Apify: LinkedIn / Indeed / Wuzzuf + أي أكتور مخصص. قابل للتعديل من الداشبورد. |
| **الإثراء (Enrichment)** | لأن إعلانات الوظايف نادرًا ما فيها إيميل الـ HR، بنشغّل أكتور `vdrmota/contact-info-scraper` على موقع الشركة لاستخراج الإيميلات والتليفونات. شركة واحدة = عملية إثراء واحدة مهما كان عدد وظايفها. |
| **اختيار أحسن إيميل** | ترتيب ذكي: `careers@` > `hr@`/`jobs@` > `info@`... و`noreply@` مستبعد. الفائز بس يُدرج تلقائيًا، والباقي بدائل للإدراج اليدوي. |
| **الطابور والإرسال** | إيميل واحد كل فاصل عشوائي (افتراضي 15–45 دقيقة)، ضمن نافذة يومية وأيام محددة وحد أقصى يومي. ممنوع الإرسال مرتين لنفس الإيميل إلا بإعادة إدراج يدوية. |
| **تتبع الفتح** | بكسل ذاتي الاستضافة (`/t/{token}.gif`). ملاحظة: تقريبي بسبب كاش Gmail للصور. |
| **مزامنة تاريخ Apify** | عند إدخال/تغيير التوكن، بيسحب تاريخ عمليات البحث القديمة من حسابك. |

## المتطلبات

- PHP 8.3+ (مُختبَر على 8.5) مع إضافات: `pdo_sqlite`, `openssl`, `mbstring`, `dom`
- Composer 2
- Node.js 20+ و npm (للبناء فقط — مش مطلوب على السيرفر بعد البناء)
- (اختياري) MySQL 8 لو مش عايز SQLite

## التثبيت المحلي

```bash
composer install
npm install
cp .env.example .env        # لو مش موجود
php artisan key:generate
```

عدّل `.env` (القيم المهمة):

```dotenv
APP_URL=http://localhost:8000     # لازم يطابق العنوان اللي بتفتح منه (بورت وكله) — Sanctum

DB_CONNECTION=sqlite              # أو mysql وبياناتها
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# بيانات الأدمن — تُستخدم مرة واحدة عند الـ seeding
ADMIN_NAME="Khaled"
ADMIN_EMAIL=khaled.waleed.dev@gmail.com
ADMIN_PASSWORD=اكتب-باسورد-قوي-هنا
```

جهّز قاعدة البيانات والواجهة:

```bash
php artisan migrate --seed         # الجداول + مستخدم الأدمن + 3 مصادر جاهزة
npm run build                      # يبني الواجهة إلى public/build
```

شغّل محليًا:

```bash
php artisan serve --port=8000      # ثم افتح http://localhost:8000
```

> ⚠️ **تحذير APP_KEY**: التوكن وباسورد SMTP بيتخزنوا **مشفّرين** بمفتاح `APP_KEY`. تغيير/فقدان المفتاح بيبطّل القيم المشفّرة، وهتحتاج تدخّلها من الإعدادات تاني.

## الإعداد من الداشبورد (بعد أول تسجيل دخول)

1. **الإعدادات ← Apify**: ألصق رمز الـ API. أول ما تحفظه بيتحقق منه ويسحب تاريخك. عدّل الأكتورز أو ضيف مصدر لو حبيت، واستخدم **«اختبار المصدر»** لتظبيط خريطة الحقول.
2. **الإعدادات ← SMTP**: بيانات Gmail (تحت) + **«أرسل بريدًا تجريبيًا»** للتأكد.
3. **الإعدادات ← القالب**: اكتب الموضوع والنص، استخدم شرائح المتغيرات، وشوف المعاينة.
4. **الإعدادات ← الجدولة**: الفاصل، الحد اليومي، النافذة، الأيام.
5. **الإعدادات ← الملف الشخصي**: اسمك، واتساب، الروابط، **ارفع الـ CV (PDF)**.
6. من **عمليات البحث** شغّل أول بحث، أو فعّل السحب التلقائي من الجدولة.
7. لما تكون جاهز، فعّل **الإرسال** من الرئيسية أو الطابور.

### إعداد Gmail App Password

1. فعّل **التحقق بخطوتين** على حسابك في Google.
2. روح [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) وأنشئ App Password (16 حرف).
3. في إعدادات SMTP: `smtp.gmail.com` / بورت `587` / تشفير `tls` / اسم المستخدم = إيميلك / كلمة السر = الـ App Password.

> Gmail المجاني حده الآمن ~100 إيميل/يوم. الافتراضي عندنا 40 (بحد أقصى 100) — سيبه معقول عشان متتبلّكش.

## الرفع على السيرفر (Production)

1. ارفع الكود، شغّل `composer install --no-dev -o` و`npm ci && npm run build`.
2. اضبط `.env`: `APP_ENV=production`، `APP_DEBUG=false`، `APP_URL=https://نطاقك`، و`SANCTUM_STATEFUL_DOMAINS=نطاقك` (بدون بروتوكول).
3. وجّه الـ web root إلى مجلد `public/`. تأكد إن `storage/` و`bootstrap/cache/` قابلين للكتابة.
4. `php artisan migrate --force --seed` (أول مرة بس للـ seed).
5. `php artisan config:cache && php artisan route:cache`.
6. **سطر الكرون الوحيد المطلوب** (كل دقيقة):

   ```cron
   * * * * * cd /path/to/job-search && php artisan schedule:run >> /dev/null 2>&1
   ```

   ده بيدير كل حاجة تلقائيًا: السحب، المتابعة، الإثراء، تعبئة الطابور، الإرسال المجدوَل، ومزامنة التاريخ — **من غير أي worker دائم** (مناسب حتى للاستضافة المشتركة).

7. تتبع الفتح محتاج الموقع يكون على URL عام يوصله Gmail (HTTPS).

### تشيك-ليست الـ Deliverability (مهم عشان الإيميلات متروحش سبام)

- **SPF / DKIM / DMARC**: لو بتبعت من دومين خاص، اضبطهم في DNS. من Gmail الشخصي مش محتاج، بس التقديم بمرفق من عنوان شخصي ممكن يتصنّف Promotions.
- ابدأ بحد يومي صغير (10–15) وزوّده تدريجيًا (Warm-up).
- خلّي القالب شخصي ومختصر، ومتبعتش لأكتر من إيميل لنفس الشركة.

## الأوامر (للاختبار اليدوي)

```bash
php artisan outreach:scrape --source=1 --keywords="Laravel Developer" --location=Egypt
php artisan apify:poll
php artisan apify:sync-history --full
php artisan outreach:enrich
php artisan outreach:queue-fill
php artisan outreach:send-tick
php artisan schedule:list          # يعرض كل المهام المجدولة
php artisan test                   # مجموعة الاختبارات
```

## المعمارية (نظرة سريعة)

- **الخدمات** (`app/Services/`): `Apify/ApifyClient`, `Apify/RunImporter`, `EnrichmentService`, `EmailRanker`, `TemplateRenderer`, `EmailValidator`, `MailerService`, `OutreachService`.
- **الأوامر** (`app/Console/Commands/`): كلها مجدوَلة في `routes/console.php`، وكل واحد بيتحكم في بواباته بنفسه من الإعدادات.
- **الـ API**: `routes/api.php` (محمي بـ Sanctum SPA)، والعقد الكامل في [`API_CONTRACT.md`](API_CONTRACT.md).
- **الواجهة**: `resources/js/` (React + TS + Tailwind + TanStack Query).
- **البكسل العام**: `routes/web.php` → `TrackingController`.

## v2 (مؤجّل)

متابعات تلقائية بعد N أيام بدون رد • كشف الردود عبر IMAP • معالجة الإيميلات المرتدة (bounces) • دقة أعلى لتتبع الفتح • حد «إيميل واحد لكل شركة كل 30 يوم» • دمج الوظايف المكررة عبر المصادر.
# job-search
