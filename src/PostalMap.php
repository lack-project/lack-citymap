<?php

declare(strict_types=1);

namespace Lack\CityMap;

use PDO;

final class PostalMap
{
    private PDO $pdo;

    public function __construct(PDO|string|null $database = null)
    {
        if ($database instanceof PDO) {
            $this->pdo = $database;
            return;
        }

        $this->pdo = self::createPdo($database ?? self::getDefaultDatabasePath());
    }

    public static function open(?string $databasePath = null): self
    {
        return new self($databasePath);
    }

    public function getLocationByPostalCode(string $postalCode, string $countryCode = 'DE'): ?PostalLocation
    {
        $locations = $this->getLocationsByPostalCode($postalCode, $countryCode);
        return $locations[0] ?? null;
    }

    /**
     * @return array<int, PostalLocation>
     */
    public function getLocationsByPostalCode(string $postalCode, string $countryCode = 'DE'): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
               FROM postal_places
              WHERE country_code = :country_code
                AND postal_code = :postal_code
           ORDER BY population DESC,
                    place_name ASC,
                    state_name ASC'
        );

        $statement->execute([
            'country_code' => strtoupper($countryCode),
            'postal_code' => trim($postalCode),
        ]);

        return array_map(static fn(array $row) => PostalLocation::fromRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, PostalLocation>
     */
    public function getPostalCodesByLocation(string $query, string $countryCode = 'DE', int $limit = 20): array
    {
        return $this->searchLocations($query, $countryCode, $limit);
    }

    /**
     * @return array<int, PostalLocation>
     */
    public function searchLocations(string $query, string $countryCode = 'DE', int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $candidateRows = $this->findSearchCandidates($query, strtoupper($countryCode), max($limit * 8, 50));
        if ($candidateRows === []) {
            return [];
        }

        $center = $this->resolveSearchCenter($query, $candidateRows);
        $normalizedQuery = $this->normalize($query);

        $results = [];
        foreach ($candidateRows as $row) {
            $distanceKm = null;
            if ($center !== null) {
                $distanceKm = Geo::distanceKm(
                    $center['latitude'],
                    $center['longitude'],
                    (float)$row['latitude'],
                    (float)$row['longitude']
                );
            }

            $results[] = [
                'rank' => $this->rankSearchRow($row, $normalizedQuery),
                'distance' => $distanceKm,
                'location' => PostalLocation::fromRow($row, $distanceKm),
            ];
        }

        usort(
            $results,
            static function (array $left, array $right): int {
                if ($left['rank'] !== $right['rank']) {
                    return $left['rank'] <=> $right['rank'];
                }

                $leftDistance = $left['distance'] ?? PHP_FLOAT_MAX;
                $rightDistance = $right['distance'] ?? PHP_FLOAT_MAX;
                if ($leftDistance !== $rightDistance) {
                    return $leftDistance <=> $rightDistance;
                }

                return strcmp($left['location']->postalCode, $right['location']->postalCode);
            }
        );

        $deduplicated = [];
        $seen = [];
        foreach ($results as $result) {
            $location = $result['location'];
            $key = implode('|', [
                $location->countryCode,
                $location->postalCode,
                $location->placeName,
                $location->stateName,
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduplicated[] = $location;
            if (count($deduplicated) >= $limit) {
                break;
            }
        }

        return $deduplicated;
    }

    /**
     * @return array<int, PostalLocation>
     */
    public function findWithinRadius(string $postalCode, float $radiusKm, string $countryCode = 'DE', int $limit = 100): array
    {
        $origin = $this->getLocationByPostalCode($postalCode, $countryCode);
        if ($origin === null) {
            return [];
        }

        return $this->findWithinRadiusFromPoint(
            latitude: $origin->latitude,
            longitude: $origin->longitude,
            radiusKm: $radiusKm,
            countryCode: $origin->countryCode,
            limit: $limit
        );
    }

    /**
     * @return array<int, PostalLocation>
     */
    public function searchNearbyLocations(string $query, float $radiusKm, string $countryCode = 'DE', int $limit = 100): array
    {
        $candidateRows = $this->findSearchCandidates($query, strtoupper($countryCode), 50);
        if ($candidateRows === []) {
            return [];
        }

        $center = $this->resolveSearchCenter($query, $candidateRows);
        if ($center === null) {
            return [];
        }

        return $this->findWithinRadiusFromPoint(
            latitude: $center['latitude'],
            longitude: $center['longitude'],
            radiusKm: $radiusKm,
            countryCode: strtoupper($countryCode),
            limit: $limit
        );
    }

    private static function createPdo(string $databasePath): PDO
    {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private static function getDefaultDatabasePath(): string
    {
        return dirname(__DIR__) . '/resources/data.de.sqlite';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findSearchCandidates(string $query, string $countryCode, int $limit): array
    {
        $like = '%' . $query . '%';

        $statement = $this->pdo->prepare(
            'SELECT *
               FROM postal_places
              WHERE country_code = :country_code
                AND (
                    postal_code LIKE :like_raw
                    OR place_name LIKE :like_raw
                    OR state_name LIKE :like_raw
                )
           ORDER BY population DESC, place_name ASC
              LIMIT :limit'
        );
        $statement->bindValue('country_code', $countryCode);
        $statement->bindValue('like_raw', $like);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{latitude: float, longitude: float}|null
     */
    private function resolveSearchCenter(string $query, array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $normalizedQuery = $this->normalize($query);
        $postalQuery = preg_replace('/\s+/', '', $query) ?? $query;

        $anchorRows = array_values(array_filter(
            $rows,
            function (array $row) use ($normalizedQuery, $postalQuery): bool {
                $place = $this->normalize((string)$row['place_name']);
                $state = $this->normalize((string)$row['state_name']);
                return $place === $normalizedQuery
                    || $state === $normalizedQuery
                    || (string)$row['postal_code'] === $postalQuery;
            }
        ));

        if ($anchorRows === []) {
            $anchorRows = array_values(array_filter(
                $rows,
                function (array $row) use ($normalizedQuery): bool {
                    $place = $this->normalize((string)$row['place_name']);
                    $state = $this->normalize((string)$row['state_name']);
                    return str_starts_with($place, $normalizedQuery)
                        || str_contains($place, $normalizedQuery)
                        || str_contains($state, $normalizedQuery);
                }
            ));
        }

        if ($anchorRows === []) {
            $bestPlace = $this->normalize((string)$rows[0]['place_name']);
            $anchorRows = array_values(array_filter(
                $rows,
                fn(array $row): bool => $this->normalize((string)$row['place_name']) === $bestPlace
            ));
        }

        $latitude = 0.0;
        $longitude = 0.0;
        foreach ($anchorRows as $row) {
            $latitude += (float)$row['latitude'];
            $longitude += (float)$row['longitude'];
        }

        $count = count($anchorRows);
        if ($count === 0) {
            return null;
        }

        return [
            'latitude' => $latitude / $count,
            'longitude' => $longitude / $count,
        ];
    }

    private function rankSearchRow(array $row, string $normalizedQuery): int
    {
        $place = $this->normalize((string)$row['place_name']);
        $state = $this->normalize((string)$row['state_name']);
        $postalCode = $this->normalize((string)$row['postal_code']);

        return match (true) {
            $postalCode === $normalizedQuery => 0,
            $place === $normalizedQuery => 1,
            $state === $normalizedQuery => 2,
            str_starts_with($place, $normalizedQuery) => 3,
            str_starts_with($state, $normalizedQuery) => 4,
            str_contains($place, $normalizedQuery) => 5,
            str_contains($state, $normalizedQuery) => 6,
            default => 10,
        };
    }

    /**
     * @return array<int, PostalLocation>
     */
    private function findWithinRadiusFromPoint(
        float $latitude,
        float $longitude,
        float $radiusKm,
        string $countryCode,
        int $limit
    ): array {
        $box = Geo::boundingBox($latitude, $longitude, $radiusKm);

        $statement = $this->pdo->prepare(
            'SELECT *
               FROM postal_places
              WHERE country_code = :country_code
                AND latitude BETWEEN :min_latitude AND :max_latitude
                AND longitude BETWEEN :min_longitude AND :max_longitude'
        );
        $statement->execute([
            'country_code' => $countryCode,
            'min_latitude' => $box['minLatitude'],
            'max_latitude' => $box['maxLatitude'],
            'min_longitude' => $box['minLongitude'],
            'max_longitude' => $box['maxLongitude'],
        ]);

        $results = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $distanceKm = Geo::distanceKm($latitude, $longitude, (float)$row['latitude'], (float)$row['longitude']);
            if ($distanceKm > $radiusKm) {
                continue;
            }
            $results[] = PostalLocation::fromRow($row, $distanceKm);
        }

        usort(
            $results,
            static function (PostalLocation $left, PostalLocation $right): int {
                $distanceCompare = ($left->distanceKm ?? PHP_FLOAT_MAX) <=> ($right->distanceKm ?? PHP_FLOAT_MAX);
                if ($distanceCompare !== 0) {
                    return $distanceCompare;
                }

                return strcmp($left->postalCode, $right->postalCode);
            }
        );

        return array_slice($results, 0, $limit);
    }

    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
