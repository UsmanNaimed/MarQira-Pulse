<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $domain = $this->faker->domainName();
        
        return [
            'organization_id' => Organization::factory(),
            'domain' => $domain,
            'home_url' => 'https://' . $domain,
            'site_url' => 'https://' . $domain,
            'status' => 'unknown',
            'wp_version' => '6.4.2',
            'php_version' => '8.2.0',
            'plugin_version' => '1.1.0',
        ];
    }
}
