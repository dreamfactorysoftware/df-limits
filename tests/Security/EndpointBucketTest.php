<?php

namespace DreamFactory\Core\Limit\Tests\Security;

use DreamFactory\Core\Limit\Models\Limit;
use PHPUnit\Framework\TestCase;

class EndpointBucketTest extends TestCase
{
    /** @dataProvider bucketProvider */
    public function testBucket(string $input, string $expected): void
    {
        $this->assertSame($expected, Limit::bucketEndpoint($input));
    }

    public static function bucketProvider(): array
    {
        return [
            ['db/_table/users/12345', 'db/_table/users/:id'],
            ['db/_table/orders/42/items/7', 'db/_table/orders/:id/items/:id'],
            ['db/_table/users/550e8400-e29b-41d4-a716-446655440000', 'db/_table/users/:uuid'],
            ['files/abcdef0123456789abcdef0123456789', 'files/:hash'],
            ['db/_schema', 'db/_schema'],
        ];
    }
}
