<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MilestoneFourReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $documentTypeId = DB::table('document_types')->where('code', 'SUPPORTING_EVIDENCE')->value('id') ?? (string) Str::ulid();
        DB::table('document_types')->upsert([
            [
                'id' => $documentTypeId,
                'code' => 'SUPPORTING_EVIDENCE',
                'name' => json_encode(['en' => 'Supporting evidence', 'om' => 'Ragaa deeggersaa', 'am' => 'ደጋፊ ማስረጃ'], JSON_THROW_ON_ERROR),
                'allowed_mime_types' => json_encode(['application/pdf', 'image/jpeg', 'image/png'], JSON_THROW_ON_ERROR),
                'maximum_bytes' => 20_971_520,
                'malware_scan_required' => true,
                'retention_class' => 'business_record',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['id'], [
            'name', 'allowed_mime_types', 'maximum_bytes', 'malware_scan_required',
            'retention_class', 'status', 'updated_at',
        ]);

        $bodies = [
            'en' => ['Decision ready', 'Reference {{reference}} is ready for your review.'],
            'om' => ['Murtiin qophaaʼeera', 'Wabii {{reference}} akka ati ilaaltuuf qophaaʼeera.'],
            'am' => ['ውሳኔው ዝግጁ ነው', 'ማጣቀሻ {{reference}} ለግምገማዎ ዝግጁ ነው።'],
        ];
        foreach ($bodies as $locale => [$subject, $body]) {
            $templateId = DB::table('notification_templates')
                ->whereNull('organization_id')
                ->where('code', 'WORKFLOW_DECISION_READY')
                ->where('version_number', 1)
                ->where('channel', 'in_app')
                ->where('locale', $locale)
                ->value('id') ?? (string) Str::ulid();
            DB::table('notification_templates')->upsert([[
                'id' => $templateId,
                'organization_id' => null,
                'code' => 'WORKFLOW_DECISION_READY',
                'version_number' => 1,
                'channel' => 'in_app',
                'locale' => $locale,
                'subject' => $subject,
                'body' => $body,
                'allowed_variables' => json_encode(['reference'], JSON_THROW_ON_ERROR),
                'classification' => 'internal',
                'status' => 'active',
                'effective_from' => now()->startOfDay(),
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], [
                'subject', 'body', 'allowed_variables', 'classification', 'status',
                'effective_from', 'effective_to', 'updated_at',
            ]);
        }
    }
}
