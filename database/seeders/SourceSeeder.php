<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    /**
     * المصادر المبدئية — خرائط الحقول تقديرية حسب توثيق كل أكتور،
     * وتُضبط نهائيًا من الداشبورد عبر زر «اختبار المصدر».
     */
    public function run(): void
    {
        $sources = [
            [
                // pay-per-result، بلا اشتراك شهري ولا كوكيز (بديل bebity الإيجاري)
                'name' => 'LinkedIn Jobs',
                'actor_id' => 'cheap_scraper/linkedin-job-scraper',
                'input_template' => [
                    'keyword' => ['{keywords}'],
                    'locations' => ['{location}'],
                    'maxItems' => 150,
                    'publishedAt' => 'r604800', // آخر 7 أيام
                ],
                'field_map' => [
                    'title' => 'jobTitle',
                    'url' => 'jobUrl',
                    'external_id' => 'jobId',
                    'company_name' => 'companyName',
                    'location' => 'location',
                    'description' => 'jobDescription',
                    'posted_at' => 'publishedAt',
                ],
                'default_keywords' => 'Flutter Developer',
                'default_location' => 'Egypt',
                'max_items' => 150,
                'enabled' => true,
            ],
            [
                'name' => 'Indeed',
                'actor_id' => 'misceres/indeed-scraper',
                'input_template' => [
                    'position' => '{keywords}',
                    'country' => '{country}',
                    'location' => '{location}',
                    'maxItemsPerSearch' => 50,
                ],
                'field_map' => [
                    'title' => 'positionName',
                    'url' => 'url',
                    'external_id' => 'id',
                    'company_name' => 'company',
                    'location' => 'location',
                    'salary' => 'salary',
                    'description' => 'description',
                    'posted_at' => 'postingDateParsed',
                    'company_website' => 'companyInfo.url',
                ],
                'default_keywords' => 'Flutter Developer',
                'default_location' => 'Cairo',
                'max_items' => 50,
                'enabled' => true,
            ],
            [
                'name' => 'Wuzzuf',
                'actor_id' => 'shahidirfan/wuzzuf-jobs-scraper',
                'input_template' => [
                    'keyword' => '{keywords}',
                    'results_wanted' => 50,
                ],
                'field_map' => [
                    'title' => 'title',
                    'url' => 'url',
                    'company_name' => 'company',
                    'location' => 'location',
                    'salary' => 'salary',
                    'description' => 'description_text',
                    'posted_at' => 'date_posted',
                ],
                'default_keywords' => 'Flutter Developer',
                'default_location' => 'Egypt',
                'max_items' => 50,
                'enabled' => true,
            ],
            [
                'name' => 'LinkedIn Posts',
                'actor_id' => 'curious_coder/linkedin-post-search-scraper',
                'input_template' => [
                    'searchQuery' => '{keywords} {location}',
                    'limit' => 50,
                ],
                'field_map' => [
                    'title' => 'authorName',
                    'url' => 'postUrl',
                    'external_id' => 'postId',
                    'company_name' => 'authorName',
                    'location' => 'location',
                    'description' => 'postText',
                    'posted_at' => 'postDate',
                ],
                'default_keywords' => 'hiring Laravel Developer',
                'default_location' => 'Egypt',
                'max_items' => 50,
                'enabled' => true,
            ],
            [
                'name' => 'Facebook Posts',
                'actor_id' => 'scraper_one/facebook-posts-search',
                'input_template' => [
                    'keyword' => '{keywords} {location}',
                    'search_type' => 'posts',
                    'results_limit' => 50,
                    'filter_by_recent_posts' => true,
                ],
                'field_map' => [
                    'title' => 'pageName',
                    'url' => 'url',
                    'external_id' => 'postId',
                    'company_name' => 'pageName',
                    'location' => 'location',
                    'description' => 'text',
                    'posted_at' => 'time',
                ],
                'default_keywords' => 'hiring Laravel Developer',
                'default_location' => 'Egypt',
                'max_items' => 50,
                'enabled' => true,
            ],
        ];

        foreach ($sources as $source) {
            Source::updateOrCreate(['actor_id' => $source['actor_id']], $source);
        }
    }
}
