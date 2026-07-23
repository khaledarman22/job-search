<?php

namespace App\Console\Commands;

use App\Enums\EnrichmentStatus;
use App\Models\Company;
use App\Services\CompanyDomainResolver;
use Illuminate\Console\Command;

/**
 * سكرابرات الوظائف بترجّع صفحة الشركة على LinkedIn/Indeed مش موقعها الحقيقي،
 * فالشركة بتنزل بـ domain = NULL والإثراء عمره ما بيشتغل عليها.
 * الأمر ده بيحاول يستنتج الدومين الحقيقي من الاسم ويرجّعها لطابور الإثراء.
 */
class ResolveDomainsCommand extends Command
{
    protected $signature = 'outreach:resolve-domains {--limit=25}';

    protected $description = 'Resolve real company domains from names for companies that have no website';

    public function handle(CompanyDomainResolver $resolver): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $companies = Company::query()
            ->whereNull('domain')
            ->whereNull('domain_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $resolved = 0;

        foreach ($companies->values() as $index => $company) {
            if ($index > 0) {
                usleep(300_000); // نكون مؤدبين مع الـ endpoint المجاني
            }

            $match = $resolver->resolve($company->name);

            // مفيش تطابق مؤكد — نعلّمها «اتفحصت» بس عشان ما تتجربش تاني في لوب
            $company->domain_checked_at = now();

            if ($match !== null) {
                $company->domain = $match['domain'];
                $company->website = "https://{$match['domain']}";
                // Pending عشان باتش الإثراء الحالي يلقطها من غير أي تغيير فيه
                $company->enrichment_status = EnrichmentStatus::Pending;
                $resolved++;
                $this->line("{$company->name} → {$match['domain']}");
            }

            $company->save();
        }

        $this->info("resolved {$resolved} / checked {$companies->count()}");

        return self::SUCCESS;
    }
}
