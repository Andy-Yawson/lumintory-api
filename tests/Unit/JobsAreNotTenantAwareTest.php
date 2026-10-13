<?php

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Jobs here carry their own workspace id. Spatie multitenancy drops any queued job without a
 * "current tenant" unless it's marked NotTenantAware, and it does so silently. This catches a
 * new job that forgets the marker.
 */
class JobsAreNotTenantAwareTest extends TestCase
{
    public function test_every_queued_job_is_marked_not_tenant_aware(): void
    {
        foreach (glob(__DIR__.'/../../app/Jobs/*.php') as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if (is_subclass_of($class, ShouldQueue::class)) {
                $this->assertTrue(is_subclass_of($class, NotTenantAware::class), "{$class} must implement NotTenantAware or it is silently dropped by the queue");
            }
        }
    }
}
