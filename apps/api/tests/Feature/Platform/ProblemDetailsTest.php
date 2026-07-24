<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function test_validation_errors_use_the_standard_problem_envelope(): void
    {
        Route::get('/api/testing/validation', function (): never {
            throw ValidationException::withMessages(['name' => ['The name field is required.']]);
        });

        $this->getJson('/api/testing/validation')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'type',
                'title',
                'status',
                'code',
                'correlation_id',
                'errors' => ['name'],
            ]);
    }

    public function test_production_errors_are_redacted(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/testing/failure', function (): never {
            throw new RuntimeException('database-password=must-not-leak');
        });

        $response = $this->getJson('/api/testing/failure')
            ->assertStatus(500)
            ->assertJsonPath('code', 'INTERNAL_ERROR');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('must-not-leak', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
    }
}
