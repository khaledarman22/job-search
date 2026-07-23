// DTOs مطابقة لعقد الـ API (API_CONTRACT.md).

export interface User {
    id: number;
    name: string;
    email: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

// ---------- Dashboard ----------

export interface DashboardStats {
    jobs_total: number;
    contacts_total: number;
    queued: number;
    sent_today: number;
    daily_cap: number;
    sent_total: number;
    opened_total: number;
    open_rate: number;
}

export interface SendingWindow {
    start: string;
    end: string;
    days: number[];
    timezone: string;
}

export interface SendingState {
    enabled: boolean;
    paused_reason: string | null;
    next_send_at: string | null;
    in_window: boolean;
    window: SendingWindow;
    cap_reached: boolean;
    smtp_configured: boolean;
    apify_configured: boolean;
    template_configured: boolean;
}

export type ActivityType = 'sent' | 'opened' | 'failed' | 'scraped' | 'contact_found' | 'run_finished';

export interface ActivityItem {
    type: ActivityType;
    message: string;
    at: string;
}

export interface ApifySpend {
    spent: number;
    cap: number;
    remaining: number;
    cap_reached: boolean;
    paused: boolean;
    paused_reason: string | null;
}

export interface DashboardResponse {
    stats: DashboardStats;
    sending: SendingState;
    apify_spend: ApifySpend;
    recent_activity: ActivityItem[];
}

// ---------- Sources ----------

export interface Source {
    id: number;
    name: string;
    actor_id: string;
    input_template: Record<string, unknown>;
    field_map: Record<string, unknown>;
    default_keywords: string | null;
    default_location: string | null;
    max_items: number | null;
    enabled: boolean;
    last_run_at: string | null;
    created_at: string;
}

export interface SourcePayload {
    name: string;
    actor_id: string;
    input_template: Record<string, unknown>;
    field_map: Record<string, unknown>;
    default_keywords?: string;
    default_location?: string;
    max_items?: number;
    enabled: boolean;
}

export interface RunSourcePayload {
    keywords?: string;
    location?: string;
    max_items?: number;
}

export interface SourceTestResult {
    raw_items: unknown[];
    mapped: unknown[];
    run_status: string;
}

// ---------- Apify Runs ----------

export type RunPurpose = 'scrape' | 'enrich' | 'external';
export type RunOrigin = 'system' | 'synced';

export interface ApifyRun {
    id: number;
    apify_run_id: string;
    actor_id: string;
    actor_name: string | null;
    source_id: number | null;
    source_name: string | null;
    purpose: RunPurpose;
    origin: RunOrigin;
    status: string;
    input: Record<string, unknown> | null;
    item_count: number | null;
    usage_usd: number | null;
    started_at: string | null;
    finished_at: string | null;
    imported_at: string | null;
    error: string | null;
}

// ---------- Jobs ----------

export type JobStatus = 'new' | 'enriching' | 'enriched' | 'no_contact';

export interface JobCompany {
    id: number;
    name: string;
    domain: string | null;
    website: string | null;
    enrichment_status: string;
    contacts_count: number;
}

export interface JobPost {
    id: number;
    title: string;
    url: string | null;
    location: string | null;
    salary: string | null;
    description: string | null;
    posted_at: string | null;
    status: JobStatus;
    source: { id: number; name: string } | null;
    company: JobCompany | null;
    created_at: string;
}

export interface JobPostDetail extends Omit<JobPost, 'company'> {
    company: (JobCompany & { contacts: Contact[] }) | null;
}

// ---------- Contacts ----------

export type ContactStatus = 'new' | 'queued' | 'contacted' | 'suppressed' | 'invalid';

export interface Contact {
    id: number;
    email: string | null;
    phone: string | null;
    whatsapp_url: string | null;
    name: string | null;
    position: string | null;
    discovered_via: string | null;
    is_alternate: boolean;
    status: ContactStatus;
    company: { id: number; name: string };
    job_post: { id: number; title: string } | null;
    sightings_count: number;
    last_sent_at: string | null;
    last_opened_at: string | null;
    created_at: string;
}

export interface ContactUpdatePayload {
    email?: string;
    phone?: string;
    name?: string;
    position?: string;
}

export interface ImportEmailsStats {
    found: number;
    imported: number;
    queued: number;
    invalid: number;
    duplicates: number;
}

// ---------- Emails / Queue ----------

export type EmailStatus = 'queued' | 'sending' | 'sent' | 'failed' | 'cancelled';

export interface OutreachEmail {
    id: number;
    to_email: string;
    subject: string;
    status: EmailStatus;
    is_manual: boolean;
    attempts: number;
    error: string | null;
    position: number | null;
    queued_at: string | null;
    sent_at: string | null;
    opened_at: string | null;
    last_opened_at: string | null;
    open_count: number;
    replied_at: string | null;
    // company/contact عمليًا مش هيبقوا null بفضل الـ cascade deletes
    contact: { id: number; name: string | null; email: string | null };
    company: { id: number; name: string };
    job_post: { id: number; title: string } | null;
}

export interface EmailOpen {
    opened_at: string;
    ip: string | null;
    user_agent: string | null;
}

export interface OutreachEmailDetail extends OutreachEmail {
    body_html: string;
    opens: EmailOpen[];
}

// ---------- Settings ----------

export interface ApifySettings {
    token_set: boolean;
    token_masked: string | null;
    enrich_actor_id: string;
}

export type SmtpEncryption = 'tls' | 'ssl' | 'none';

export interface SmtpSettings {
    host: string;
    port: number;
    encryption: SmtpEncryption;
    username: string;
    password_set: boolean;
    from_email: string;
    from_name: string;
}

export interface TemplateSettings {
    subject: string;
    body: string;
}

export interface ScheduleSettings {
    min_interval: number;
    max_interval: number;
    window_start: string;
    window_end: string;
    days: number[];
    timezone: string;
    daily_cap: number;
    auto_scrape_enabled: boolean;
    auto_scrape_time: string;
}

export interface ProfileSettings {
    my_name: string;
    my_title: string;
    whatsapp: string;
    phone: string;
    portfolio: string;
    github: string;
    linkedin: string;
}

export interface AutoScrapeLocation {
    code: string;
    name: string;
}

export type ImportMode = 'all' | 'with_email' | 'companies_only';

export interface AutoScrapeSettings {
    enabled: boolean;
    time: string;
    keywords: string;
    location: string;
    random_sources: boolean;
    auto_queue: boolean;
    import_mode: ImportMode;
    available_locations: AutoScrapeLocation[];
}

export interface CvInfo {
    uploaded: boolean;
    original_name: string | null;
    size: number | null;
}

export interface SettingsResponse {
    apify: ApifySettings;
    smtp: SmtpSettings;
    template: TemplateSettings;
    schedule: ScheduleSettings;
    profile: ProfileSettings;
    auto_scrape: AutoScrapeSettings;
    cv: CvInfo;
}
