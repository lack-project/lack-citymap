<?php

declare(strict_types=1);

namespace Lack\CityMap;

final readonly class PostalLocation
{
    public function __construct(
        public string $countryCode,
        public string $postalCode,
        public string $placeName,
        public string $stateName,
        public float $latitude,
        public float $longitude,
        public ?int $population,
        public ?float $areaKm2,
        public ?float $distanceKm = null,
    ) {
    }

    public static function fromRow(array $row, ?float $distanceKm = null): self
    {
        return new self(
            countryCode: (string)$row['country_code'],
            postalCode: (string)$row['postal_code'],
            placeName: (string)$row['place_name'],
            stateName: (string)$row['state_name'],
            latitude: (float)$row['latitude'],
            longitude: (float)$row['longitude'],
            population: isset($row['population']) && $row['population'] !== null && $row['population'] !== '' ? (int)$row['population'] : null,
            areaKm2: isset($row['area_km2']) && $row['area_km2'] !== null && $row['area_km2'] !== '' ? (float)$row['area_km2'] : null,
            distanceKm: $distanceKm,
        );
    }

    public function toArray(): array
    {
        return [
            'countryCode' => $this->countryCode,
            'postalCode' => $this->postalCode,
            'placeName' => $this->placeName,
            'stateName' => $this->stateName,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'population' => $this->population,
            'areaKm2' => $this->areaKm2,
            'distanceKm' => $this->distanceKm,
        ];
    }
}
