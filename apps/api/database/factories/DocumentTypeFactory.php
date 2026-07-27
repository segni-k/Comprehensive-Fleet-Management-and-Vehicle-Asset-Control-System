<?php

namespace Database\Factories;

use App\Documents\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentType> */
final class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'TEST_'.fake()->unique()->regexify('[A-Z]{10}'),
            'name' => ['en' => fake()->words(3, true)],
            'allowed_mime_types' => ['application/pdf'],
            'maximum_bytes' => 1_000_000,
            'malware_scan_required' => true,
            'retention_class' => 'business_record',
            'status' => 'active',
        ];
    }
}
