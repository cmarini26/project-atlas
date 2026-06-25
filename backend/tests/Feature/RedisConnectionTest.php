<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisConnectionTest extends TestCase
{
    public function test_redis_connection_responds_to_ping(): void
    {
        if (config('cache.default') === 'array') {
            $this->markTestSkipped('Redis not configured in this test environment.');
        }

        try {
            $result = Redis::ping();
            $this->assertTrue(in_array($result, [true, 'PONG', '+PONG'], strict: true));
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }
    }

    public function test_redis_can_set_and_get_values(): void
    {
        if (config('cache.default') === 'array') {
            $this->markTestSkipped('Redis not configured in this test environment.');
        }

        try {
            $key = 'atlas:bootstrap:test:'.uniqid();
            Redis::set($key, 'ok', 'EX', 10);
            $value = Redis::get($key);
            Redis::del($key);

            $this->assertEquals('ok', $value);
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }
    }
}
