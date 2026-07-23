# API Contract — نظام التوظيف

عقد ملزم بين الـ Laravel API والـ React frontend. أي تغيير هنا لازم ينعكس في الطرفين.

## عام

- كل المسارات تحت `/api`، JSON فقط، محمية بـ `auth:sanctum` (SPA cookie، same-origin) **ما عدا** `POST /api/login` وبكسل التتبع `GET /t/{token}.gif`.
- قبل login: الواجهة تطلب `GET /sanctum/csrf-cookie` ثم ترسل `X-XSRF-TOKEN` تلقائيًا (axios `withCredentials: true` + `withXSRFToken: true`).
- القوائم المصفّحة ترجع شكل Laravel paginator: `{ data: [...], current_page, last_page, per_page, total }`.
- الأخطاء: 422 بشكل Laravel validation `{ message, errors: {field: [..]} }`، و409/400 بـ `{ message, code? }`.
- كل التواريخ ISO 8601 UTC. الواجهة تعرضها بتوقيت `Africa/Cairo`.

## Auth

- `POST /api/login` — body `{email, password}` → 200 `{user: {id, name, email}}` أو 422. Throttle 5/دقيقة.
- `POST /api/logout` → 204.
- `GET /api/me` → `{user: {id, name, email}}` أو 401.

## Dashboard

- `GET /api/dashboard` →
```json
{
  "stats": {
    "jobs_total": 0, "contacts_total": 0, "queued": 0,
    "sent_today": 0, "daily_cap": 40, "sent_total": 0,
    "opened_total": 0, "open_rate": 0.0
  },
  "sending": {
    "enabled": true, "paused_reason": null,
    "next_send_at": "2026-07-19T12:00:00Z",
    "in_window": true, "window": {"start": "09:00", "end": "18:00", "days": [0,1,2,3,4], "timezone": "Africa/Cairo"},
    "cap_reached": false,
    "smtp_configured": true, "apify_configured": true, "template_configured": true
  },
  "recent_activity": [ {"type": "sent|opened|failed|scraped|contact_found|run_finished", "message": "نص عربي جاهز للعرض", "at": "ISO"} ]
}
```
- `activity.message` يُبنى في السيرفر بالعربي.

## Sources (المصادر)

Source object: `{id, name, actor_id, input_template, field_map, default_keywords, default_location, max_items, enabled, last_run_at, created_at}`

- `GET /api/sources` → `{data: [Source]}` (بدون تصفيح — قليلة).
- `POST /api/sources` — body: name, actor_id, input_template (object), field_map (object), default_keywords?, default_location?, max_items?, enabled.
- `PUT /api/sources/{id}` — نفس الحقول.
- `DELETE /api/sources/{id}` → 204.
- `POST /api/sources/{id}/run` — body `{keywords?, location?, max_items?}` → 201 `{run: ApifyRun}` — يشغّل الأكتور فورًا.
- `POST /api/sources/{id}/test` — body `{keywords?, location?}` → 200 `{raw_items: [..حتى 3 عناصر خام..], mapped: [..نتيجة الـ field_map..], run_status}` — قد يستغرق حتى 60 ثانية.

## Searches / Apify Runs (عمليات البحث)

ApifyRun object: `{id, apify_run_id, actor_id, actor_name, source_id, source_name, purpose: "scrape|enrich|external", origin: "system|synced", status, input, item_count, usage_usd, started_at, finished_at, imported_at, error}`

- `GET /api/runs?purpose=&origin=&status=&page=` → paginated.
- `GET /api/runs/{id}` → `{run: ApifyRun}` — يجلب `input` و`item_count` من Apify لو ناقصين (lazy).
- `POST /api/runs/{id}/import` — body `{source_id}` (اختيار field_map) → 200 `{imported: N}` — استيراد داتاسِت run متزامن يدويًا.
- `POST /api/runs/sync` → 200 `{synced: N}` — سحب تاريخ الحساب من Apify الآن.

## Jobs (الوظائف)

JobPost object: `{id, title, url, location, salary, description, posted_at, status: "new|enriching|enriched|no_contact", source: {id,name}|null, company: {id, name, domain, website, enrichment_status, contacts_count}|null, created_at}`

- `GET /api/job-posts?status=&source_id=&has_contact=&q=&page=` → paginated.
- `GET /api/job-posts/{id}` → `{job: JobPost & {company.contacts: [Contact]}}`.
- `POST /api/job-posts/{id}/queue` → 201 `{email: OutreachEmail}` — يضيف أفضل جهة اتصال للطابور بسياق الوظيفة دي. أخطاء 409 بـ `code`: `already_queued | already_sent | no_contact | suppressed` + `message` عربي (لو `already_sent` الواجهة تعرض اقتراح «إعادة الإدراج» من صفحة جهات الاتصال).
- `POST /api/companies/{id}/enrich` → 202 `{company}` — يعيد ضبط حالة الإثراء لـ pending.

## Contacts (جهات الاتصال)

Contact object: `{id, email, phone, whatsapp_url, name, position, discovered_via, is_alternate, status: "new|queued|contacted|suppressed|invalid", company: {id,name}, job_post: {id,title}|null, sightings_count, last_sent_at, last_opened_at, created_at}`

- `GET /api/contacts?status=&phone_only=&sent_before=&q=&page=` → paginated.
- `PATCH /api/contacts/{id}` — body `{email?, phone?, name?, position?}` → `{contact}`.
- `POST /api/contacts/{id}/queue` — body `{job_post_id?}` → 201 `{email: OutreachEmail}` — إدراج/إعادة إدراج يدوي (مسموح حتى لو مُرسل قبل كده). 409 لو suppressed أو في الطابور حاليًا أو بلا إيميل.
- `POST /api/contacts/{id}/suppress` / `POST /api/contacts/{id}/unsuppress` → `{contact}`.
- `POST /api/contacts/import` — multipart أو JSON: `emails` (نص، سطر/فاصلة لكل إيميل) و/أو `file` (txt/csv ≤2MB)، `queue` (bool، افتراضي true) → 200 `{ok, stats: {found, imported, queued, invalid, duplicates}}`. ينشئ شركة+جهة اتصال (discovered_via=manual) لكل إيميل صالح غير مكرر، ويدخلهم الطابور لو `queue`.

## Emails / Queue (الطابور وسجل الإرسال)

OutreachEmail object: `{id, to_email, subject, status: "queued|sending|sent|failed|cancelled", is_manual, attempts, error, position, queued_at, sent_at, opened_at, last_opened_at, open_count, replied_at, contact: {id, name, email}, company: {id, name}, job_post: {id, title}|null}`

- `GET /api/emails?status=&opened=&q=&page=` → paginated. `status=queued` يرجّع الطابور مرتّب بـ position ثم id (وبصفحة 100 عنصر بدل 20).
- `GET /api/emails/{id}` → `{email: OutreachEmail & {body_html, opens: [{opened_at, ip, user_agent}]}}`.
- `POST /api/emails/{id}/cancel` → `{email}` — للـ queued فقط، 409 غير كده.
- `POST /api/emails/{id}/requeue` → 201 `{email}` — لـ failed/cancelled/sent: ينشئ إدخال طابور جديد يدوي لنفس جهة الاتصال ونفس الوظيفة.
- `PATCH /api/emails/{id}` — body `{replied: bool}` → `{email}` — toggle «تم الرد» اليدوي (للمرسل فقط، 409 غير كده).
- `PATCH /api/queue/reorder` — body `{ordered_ids: [..]}` → 204 — يعيد كتابة position للـ queued.
- `POST /api/queue/pause` / `POST /api/queue/resume` → `{sending: {...}}` (نفس شكل dashboard.sending).

## Settings (الإعدادات)

- `GET /api/settings` →
```json
{
  "apify": {"token_set": true, "token_masked": "apify_api_•••abcd", "enrich_actor_id": "vdrmota/contact-info-scraper"},
  "smtp": {"host": "smtp.gmail.com", "port": 587, "encryption": "tls", "username": "", "password_set": true, "from_email": "", "from_name": ""},
  "template": {"subject": "...", "body": "...HTML..."},
  "schedule": {"min_interval": 15, "max_interval": 45, "window_start": "09:00", "window_end": "18:00", "days": [0,1,2,3,4], "timezone": "Africa/Cairo", "daily_cap": 40, "auto_scrape_enabled": false, "auto_scrape_time": "10:00"},
  "profile": {"my_name": "", "my_title": "", "whatsapp": "", "phone": "", "portfolio": "", "github": "", "linkedin": ""},
  "cv": {"uploaded": true, "original_name": "cv.pdf", "size": 12345}
}
```
- `PUT /api/settings/apify` — body `{token?, enrich_actor_id?}` — لو فيه token: يتحقق منه عبر Apify `users/me` (422 لو غلط) ويشغّل مزامنة التاريخ فورًا → `{ok: true, account_name}`.
- `PUT /api/settings/smtp` — body `{host, port, encryption, username, password?, from_email, from_name}` (password فارغ = احتفظ بالقديم).
- `POST /api/settings/smtp-test` — body `{to}` → 200 `{ok: true}` أو 422 `{message: نص خطأ SMTP}`.
- `PUT /api/settings/template` — body `{subject, body}`.
- `POST /api/settings/template-preview` — body `{subject, body}` → `{subject, body}` بعد الاستبدال ببيانات عينة.
- `PUT /api/settings/schedule` — body بنفس مفاتيح `schedule` أعلاه (تحقق: min<max، cap<=100).
- `PUT /api/settings/profile` — body بنفس مفاتيح `profile`.
- `POST /api/settings/cv` — multipart `file` (PDF ≤5MB) → `{cv: {...}}`.
- `GET /api/settings/cv` → تنزيل الملف الحالي.
- كل PUT يرجّع 200 `{ok: true}` + الجزء المحدَّث.

### إعدادات الاسكراب التلقائي (Auto-scrape)

يظهر ضمن `GET /api/settings` كمفتاح `auto_scrape`:
```json
"auto_scrape": {
  "enabled": false,
  "time": "10:00",
  "keywords": "",            // "" = تلقائي (يستخدم كلمات كل مصدر الافتراضية)
  "location": "",            // "" = تلقائي (عشوائي في الوطن العربي)؛ أو كود دولة مثل "SA"
  "random_sources": true,    // true = مجموعة عشوائية من المصادر ؛ false = كل المصادر المفعّلة
  "auto_queue": true,        // المستخرجون الجدد يدخلون الطابور تلقائيًا
  "import_mode": "all",      // "all" | "with_email" | "companies_only"
  "available_locations": [{"code": "EG", "name": "Egypt"}, {"code": "SA", "name": "Saudi Arabia"}]
}
```
- `PUT /api/settings/auto-scrape` — body: `{enabled: bool, time: "HH:MM", keywords: string, location: string, random_sources: bool, auto_queue: bool, import_mode: string}` → 200 `{ok: true, auto_scrape: {...}}`. تحقق: `time` بصيغة HH:MM، `location` إما "" أو كود ضمن `available_locations`، `import_mode` ضمن الثلاثة.

**سلوك `import_mode` عند استيراد نتائج الاسكراب (RunImporter):**
- `all` (الافتراضي) — يحفظ كل الوظائف والشركات، وينشئ جهات اتصال لما يلاقي إيميل. (السلوك الحالي)
- `with_email` — **يحفظ فقط** الوظائف اللي طلع منها إيميل فعلي (من حقل مُخرَّط أو من نص الوصف). أي وظيفة/شركة بلا إيميل تُتجاهل تمامًا ولا تُحفظ.
- `companies_only` — يحفظ الوظائف والشركات للتصفح، لكن **لا ينشئ جهات اتصال** إطلاقًا (فلا شيء يدخل الطابور من الاسكراب).
- السلوك (في الجدولة): `keywords` غير الفارغة تُستخدم لكل المصادر (وإلا كلمات المصدر الافتراضية)؛ `location` المحددة = دولة ثابتة (وإلا عشوائي عربي)؛ `random_sources` يتحكم في اختيار المصادر؛ `auto_queue=false` يوقف `outreach:queue-fill`.

## متغيرات القالب (placeholders)

`{company}`, `{job_title}`, `{job_url}`, `{contact_name}`, `{my_name}`, `{my_title}`, `{whatsapp}`, `{phone}`, `{portfolio}`, `{github}`, `{linkedin}` — أقواس مفردة.
