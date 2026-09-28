<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class SchedulerMutexTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_mutexes_work_without_redis_and_preserve_exclusion(): void
    {
        config()->set('cache.default', 'redis');
        config()->set('cache.stores.redis.driver', 'unavailable-redis');
        $events = collect(Schedule::events())->filter(fn ($event) => str_contains($event->command ?? '', 'backups:schedule') ||
            str_contains($event->command ?? '', 'engine:recover-stale'));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertTrue($event->mutex->create($event));
            $this->assertFalse($event->mutex->create($event));
            $this->assertTrue($event->mutex->exists($event));
            $event->mutex->forget($event);
            $this->assertTrue($event->mutex->create($event));
            $event->mutex->forget($event);
        }
    }

    public function test_schedule_pause_resume_and_run_use_the_same_database_store_without_redis(): void
    {
        config()->set('cache.default', 'redis');
        config()->set('cache.stores.redis.driver', 'unavailable-redis');
        Artisan::call('schedule:pause');
        $this->assertTrue(Cache::store('database')->get('illuminate:schedule:paused'));
        $this->assertSame(0, Artisan::call('schedule:run'));
        Artisan::call('schedule:resume');
        $this->assertFalse(Cache::store('database')->has('illuminate:schedule:paused'));
        Artisan::call('schedule:interrupt');
        $this->assertTrue(Cache::store('database')->get('illuminate:schedule:interrupt'));
    }
}
