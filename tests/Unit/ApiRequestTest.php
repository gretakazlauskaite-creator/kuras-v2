<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ApiException;
use App\Http\ApiRequest;
use PHPUnit\Framework\TestCase;

final class ApiRequestTest extends TestCase
{
    public function testPaginationIsBounded(): void
    {
        $request = new ApiRequest(['page' => '2', 'per_page' => '100']);

        self::assertSame(2, $request->integer('page', 1, 1, 10000));
        self::assertSame(100, $request->integer('per_page', 20, 1, 100));
    }

    public function testOutOfRangePaginationIsRejected(): void
    {
        $this->expectException(ApiException::class);
        (new ApiRequest(['per_page' => '101']))->integer('per_page', 20, 1, 100);
    }

    public function testBoundsRequireFourOrderedCoordinates(): void
    {
        $bounds = (new ApiRequest(['bounds' => '20.6,53.8,26.9,56.5']))->bounds();

        self::assertSame(20.6, $bounds['min_lng']);
        self::assertSame(56.5, $bounds['max_lat']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(ApiException::class);
        (new ApiRequest(['date' => '2026-02-31']))->date('date', '2026-07-17');
    }
}
