<?php

declare(strict_types=1);

namespace Lack\CityMap\Test;

use Lack\CityMap\Geo;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    public function testDistanceKmReturnsApproximateGreatCircleDistance(): void
    {
        $distance = Geo::distanceKm(50.0, 8.0, 51.0, 8.0);
        self::assertEqualsWithDelta(111.2, $distance, 0.5);
    }
}
