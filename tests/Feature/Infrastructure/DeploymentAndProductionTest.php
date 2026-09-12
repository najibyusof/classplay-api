<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeploymentAndProductionTest extends TestCase
{
    use RefreshDatabase;

    // 1. Health check endpoint GET /api/v1/health returns 200 OK when DB is healthy.
    public function test_health_check_returns_200_when_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service is healthy.')
            ->assertJsonPath('data.status', 'ok');

        $this->assertNotEmpty($response->json('data.timestamp'));
    }

    // 2. Health check returns 503 when DB is down, without exposing credentials or stack traces.
    public function test_health_check_returns_503_without_leaking_details_when_db_fails(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->once()
            ->andThrow(new \RuntimeException('Connection failed: Access denied for user root@localhost (using password: YES)'));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Service is unhealthy.');

        $this->assertStringNotContainsString('Access denied', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    // 3. Production configuration (APP_DEBUG=false) shields stack traces on 500 errors.
    public function test_production_config_shields_stack_traces(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/v1/test-500-error', function () {
            throw new \RuntimeException('Internal sensitive system failure!');
        });

        $response = $this->getJson('/api/v1/test-500-error')->assertServerError();

        $response->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Server error.');

        $this->assertStringNotContainsString('Internal sensitive system failure', $response->getContent());
    }

    // 4. Queue configuration and required tables exist.
    public function test_queue_configuration_is_ready_for_production(): void
    {
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    // 5. Scheduler tasks are properly configured.
    public function test_scheduler_tasks_are_registered(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertSame('Asia/Kuala_Lumpur', $event->timezone);
            $this->assertTrue($event->onOneServer);
        }
    }
}
