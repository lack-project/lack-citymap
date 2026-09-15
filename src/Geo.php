<?php

declare(strict_types=1);

namespace Lack\CityMap;

final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public static function distanceKm(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $latFrom = deg2rad($latitude1);
        $latTo = deg2rad($latitude2);
        $latDelta = deg2rad($latitude2 - $latitude1);
        $lonDelta = deg2rad($longitude2 - $longitude1);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    public static function boundingBox(float $latitude, float $longitude, float $radiusKm): array
    {
        $latitudeDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $longitudeDivisor = max(cos(deg2rad($latitude)), 0.00001);
        $longitudeDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM / $longitudeDivisor);

        return [
            'minLatitude' => $latitude - $latitudeDelta,
            'maxLatitude' => $latitude + $latitudeDelta,
            'minLongitude' => $longitude - $longitudeDelta,
            'maxLongitude' => $longitude + $longitudeDelta,
        ];
    }
}
