<?php

declare(strict_types=1);

namespace Lack\CityMap\Test;

use Lack\CityMap\PostalMap;
use Lack\CityMap\PostalMapBuilder;
use PHPUnit\Framework\TestCase;

final class PostalMapTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/postal-map-test-' . bin2hex(random_bytes(8)) . '.sqlite';

        $csvPath = sys_get_temp_dir() . '/postal-map-test-' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($csvPath, implode("\n", [
            'country_code,postal_code,place_name,state_name,latitude,longitude,population,area_km2',
            'DE,50667,Köln,Nordrhein-Westfalen,50.9413,6.9583,1086000,405.15',
            'DE,50668,Köln,Nordrhein-Westfalen,50.9490,6.9603,1086000,405.15',
            'DE,50670,Köln,Nordrhein-Westfalen,50.9505,6.9477,1086000,405.15',
            'DE,50825,Köln,Nordrhein-Westfalen,50.9518,6.9166,1086000,405.15',
            'DE,50937,Köln,Nordrhein-Westfalen,50.9237,6.9272,1086000,405.15',
            'DE,53111,Bonn,Nordrhein-Westfalen,50.7374,7.0982,331000,141.06',
        ]));

        $builder = new PostalMapBuilder();
        $builder->createEmptyDatabase($this->databasePath);
        $builder->importCountry($this->databasePath, 'DE', $csvPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
    }

    public function testGetLocationByPostalCodeReturnsLocation(): void
    {
        $map = PostalMap::open($this->databasePath);

        $location = $map->getLocationByPostalCode('50667');

        self::assertNotNull($location);
        self::assertSame('Köln', $location->placeName);
        self::assertSame('DE', $location->countryCode);
        self::assertSame('Nordrhein-Westfalen', $location->stateName);
    }

    public function testConstructorAcceptsCustomDatabasePath(): void
    {
        $map = new PostalMap($this->databasePath);

        $location = $map->getLocationByPostalCode('50667');

        self::assertNotNull($location);
        self::assertSame('50667', $location->postalCode);
    }

    public function testSearchLocationsReturnsCentralPostalCodesFirst(): void
    {
        $map = PostalMap::open($this->databasePath);

        $results = $map->searchLocations('Köln', 'DE', 5);

        self::assertCount(5, $results);
        self::assertSame('Köln', $results[0]->placeName);
        self::assertLessThanOrEqual($results[1]->distanceKm ?? PHP_FLOAT_MAX, $results[0]->distanceKm ?? PHP_FLOAT_MAX);
    }

    public function testFindWithinRadiusReturnsSortedMatches(): void
    {
        $map = PostalMap::open($this->databasePath);

        $results = $map->findWithinRadius('50667', 5.0);

        self::assertNotEmpty($results);
        self::assertSame('50667', $results[0]->postalCode);
        self::assertEqualsWithDelta(0.0, $results[0]->distanceKm ?? -1, 0.001);
        self::assertLessThan(10.0, $results[count($results) - 1]->distanceKm ?? 999.0);
    }
}
