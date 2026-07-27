<?php

namespace Database\Factories;

use App\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationTemplate> */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'TEST_'.fake()->unique()->regexify('[A-Z]{10}'),
            'version_number' => 1,
            'channel' => 'in_app',
            'locale' => 'en',
            'subject' => 'Review ready',
            'body' => 'Reference {{reference}} is ready.',
            'allowed_variables' => ['reference'],
            'classification' => 'internal',
            'status' => 'draft',
            'effective_from' => now()->subDay(),
        ];
    }
}
