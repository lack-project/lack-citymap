<?php

declare(strict_types=1);

namespace Lack\CityMap;

use RuntimeException;
use ZipArchive;

final class GermanPostalDatasetBuilder
{
    private const OPENPLZ_ENDPOINT = 'https://openplzapi.org/de/Localities';
    private const ARC_GIS_ENDPOINT = 'https://services2.arcgis.com/jUpNdisbWqRpMo35/arcgis/rest/services/PLZ_Gebiete/FeatureServer/0/query';
    private const OPENPLZ_PAGE_SIZE = 50;
    private const ARC_GIS_PAGE_SIZE = 2000;

    /**
     * @param null|callable(string): void $logger
     */
    public function __construct(private readonly mixed $logger = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCsv(
        string $builderDirectory,
        string $outputCsvPath,
        ?string $centroidSource = null,
        bool $refresh = false
    ): array {
        $builderDirectory = rtrim($builderDirectory, '/');
        $cacheDirectory = $builderDirectory . '/cache';
        $buildDirectory = $builderDirectory . '/build';

        $this->ensureDirectory($cacheDirectory);
        $this->ensureDirectory($buildDirectory);

        $this->log('Lade OpenPLZ-Orte …');
        $localities = $this->fetchOpenPlzLocalities($cacheDirectory . '/openplz', $refresh);
        $this->log(sprintf('OpenPLZ: %d Orte geladen.', count($localities)));

        $this->log('Lade ArcGIS-PLZ-Gebiete …');
        $statsByPostalCode = $this->fetchArcGisPostalStats($cacheDirectory . '/arcgis', $refresh);
        $this->log(sprintf('ArcGIS: %d PLZ-Gebiete geladen.', count($statsByPostalCode)));

        $centroidsByPostalCode = [];
        if ($centroidSource !== null && trim($centroidSource) !== '') {
            $this->log('Lade optionalen PLZ-Zentroid-Datensatz …');
            $centroidsByPostalCode = $this->fetchCentroids($centroidSource, $cacheDirectory . '/centroids', $refresh);
            $this->log(sprintf('Zentroiden: %d PLZ geladen.', count($centroidsByPostalCode)));
        } else {
            $this->log('Kein separater Zentroid-Datensatz konfiguriert, nutze ArcGIS-Zentroide als Fallback.');
        }

        $mergeResult = $this->mergeRows($localities, $statsByPostalCode, $centroidsByPostalCode);
        $rows = $mergeResult['rows'];

        $this->writeCsv($outputCsvPath, $rows);
        $this->log(sprintf('CSV geschrieben: %s (%d Zeilen)', $outputCsvPath, count($rows)));

        $summary = [
            'countryCode' => 'DE',
            'localities' => count($localities),
            'postalAreas' => count($statsByPostalCode),
            'centroids' => count($centroidsByPostalCode),
            'rowsWritten' => count($rows),
            'missingStats' => $mergeResult['missingStats'],
            'missingCoordinates' => $mergeResult['missingCoordinates'],
            'outputCsv' => $outputCsvPath,
            'sources' => [
                'openplz' => self::OPENPLZ_ENDPOINT,
                'arcgis' => self::ARC_GIS_ENDPOINT,
                'centroids' => $centroidSource,
            ],
        ];

        $this->writeSummary($buildDirectory . '/summary.de.csv.json', $summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsvToSqlite(string $builderDirectory, string $csvPath, string $outputDatabasePath): array
    {
        $builderDirectory = rtrim($builderDirectory, '/');
        $buildDirectory = $builderDirectory . '/build';
        $this->ensureDirectory($buildDirectory);

        if (! is_file($csvPath)) {
            throw new RuntimeException("CSV source not found: {$csvPath}");
        }

        $postalMapBuilder = new PostalMapBuilder();
        $postalMapBuilder->createEmptyDatabase($outputDatabasePath);
        $importResult = $postalMapBuilder->importCountry(
            databasePath: $outputDatabasePath,
            countryCode: 'DE',
            source: $csvPath,
            sourceName: 'resources-builder'
        );
        $this->log(sprintf('SQLite geschrieben: %s', $outputDatabasePath));

        $summary = [
            'countryCode' => 'DE',
            'sourceCsv' => $csvPath,
            'outputDatabase' => $outputDatabasePath,
            'imported' => $importResult['imported'],
            'skipped' => $importResult['skipped'],
        ];

        $this->writeSummary($buildDirectory . '/summary.de.sqlite.json', $summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $builderDirectory,
        string $outputCsvPath,
        string $outputDatabasePath,
        ?string $centroidSource = null,
        bool $refresh = false
    ): array {
        $csvSummary = $this->buildCsv(
            builderDirectory: $builderDirectory,
            outputCsvPath: $outputCsvPath,
            centroidSource: $centroidSource,
            refresh: $refresh
        );

        $sqliteSummary = $this->importCsvToSqlite(
            builderDirectory: $builderDirectory,
            csvPath: $outputCsvPath,
            outputDatabasePath: $outputDatabasePath
        );

        $summary = $csvSummary + [
            'outputDatabase' => $sqliteSummary['outputDatabase'],
            'imported' => $sqliteSummary['imported'],
            'skipped' => $sqliteSummary['skipped'],
        ];

        $this->writeSummary(rtrim($builderDirectory, '/') . '/build/summary.de.json', $summary);

        return $summary;
    }

    public static function webMercatorToWgs84(float $x, float $y): array
    {
        $radius = 6378137.0;
        $longitude = rad2deg($x / $radius);
        $latitude = rad2deg((2.0 * atan(exp($y / $radius))) - (M_PI / 2.0));

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * @return array<int, array{country_code: string, postal_code: string, place_name: string, state_name: string}>
     */
    private function fetchOpenPlzLocalities(string $cacheDirectory, bool $refresh): array
    {
        $this->ensureDirectory($cacheDirectory);

        $rows = [];
        $seen = [];

        for ($page = 1; $page < 10000; $page++) {
            $url = self::OPENPLZ_ENDPOINT . '?' . http_build_query([
                'postalCode' => '.*',
                'pageSize' => self::OPENPLZ_PAGE_SIZE,
                'page' => $page,
            ]);

            $pageRows = $this->fetchJson(
                url: $url,
                cachePath: sprintf('%s/page-%05d.json', $cacheDirectory, $page),
                refresh: $refresh
            );

            if (! is_array($pageRows) || $pageRows === []) {
                break;
            }

            foreach ($pageRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $postalCode = trim((string)($row['postalCode'] ?? ''));
                $placeName = trim((string)($row['name'] ?? ''));
                $stateName = trim((string)($row['federalState']['name'] ?? ''));
                if ($postalCode === '' || $placeName === '' || $stateName === '') {
                    continue;
                }

                $key = $postalCode . '|' . $placeName . '|' . $stateName;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows[] = [
                    'country_code' => 'DE',
                    'postal_code' => $postalCode,
                    'place_name' => $placeName,
                    'state_name' => $stateName,
                ];
            }

            if (count($pageRows) < self::OPENPLZ_PAGE_SIZE) {
                break;
            }
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => [$left['postal_code'], $left['place_name'], $left['state_name']]
                <=> [$right['postal_code'], $right['place_name'], $right['state_name']]
        );

        return $rows;
    }

    /**
     * @return array<string, array{population: ?int, area_km2: ?float, latitude: float, longitude: float}>
     */
    private function fetchArcGisPostalStats(string $cacheDirectory, bool $refresh): array
    {
        $this->ensureDirectory($cacheDirectory);

        $rowsByPostalCode = [];

        for ($offset = 0; $offset < 500000; $offset += self::ARC_GIS_PAGE_SIZE) {
            $url = self::ARC_GIS_ENDPOINT . '?' . http_build_query([
                'where' => '1=1',
                'returnGeometry' => 'false',
                'returnCentroid' => 'true',
                'outFields' => 'plz,einwohner,qkm',
                'orderByFields' => 'plz',
                'resultOffset' => $offset,
                'resultRecordCount' => self::ARC_GIS_PAGE_SIZE,
                'f' => 'json',
            ]);

            $payload = $this->fetchJson(
                url: $url,
                cachePath: sprintf('%s/page-%05d.json', $cacheDirectory, (int)floor($offset / self::ARC_GIS_PAGE_SIZE) + 1),
                refresh: $refresh
            );

            $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];
            if ($features === []) {
                break;
            }

            foreach ($features as $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
                $centroid = is_array($feature['centroid'] ?? null) ? $feature['centroid'] : [];

                $postalCode = trim((string)($attributes['plz'] ?? ''));
                if ($postalCode === '' || ! isset($centroid['x'], $centroid['y'])) {
                    continue;
                }

                $coordinates = self::webMercatorToWgs84((float)$centroid['x'], (float)$centroid['y']);
                $areaKm2 = isset($attributes['qkm']) && $attributes['qkm'] !== null ? (float)$attributes['qkm'] : null;
                $population = isset($attributes['einwohner']) && $attributes['einwohner'] !== null ? (int)$attributes['einwohner'] : null;

                if (isset($rowsByPostalCode[$postalCode])) {
                    $existing = $rowsByPostalCode[$postalCode];
                    $existingArea = $existing['area_km2'] ?? 0.0;
                    $newArea = $areaKm2 ?? 0.0;
                    $combinedArea = $existingArea + $newArea;

                    $rowsByPostalCode[$postalCode] = [
                        'population' => ($existing['population'] ?? 0) + ($population ?? 0),
                        'area_km2' => ($existing['area_km2'] ?? 0.0) + ($areaKm2 ?? 0.0),
                        'latitude' => $combinedArea > 0
                            ? (($existing['latitude'] * $existingArea) + ($coordinates['latitude'] * $newArea)) / $combinedArea
                            : $coordinates['latitude'],
                        'longitude' => $combinedArea > 0
                            ? (($existing['longitude'] * $existingArea) + ($coordinates['longitude'] * $newArea)) / $combinedArea
                            : $coordinates['longitude'],
                    ];
                    continue;
                }

                $rowsByPostalCode[$postalCode] = [
                    'population' => $population,
                    'area_km2' => $areaKm2,
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                ];
            }

            if (count($features) < self::ARC_GIS_PAGE_SIZE) {
                break;
            }
        }

        ksort($rowsByPostalCode);
        return $rowsByPostalCode;
    }

    /**
     * @return array<string, array{latitude: float, longitude: float}>
     */
    private function fetchCentroids(string $source, string $cacheDirectory, bool $refresh): array
    {
        $this->ensureDirectory($cacheDirectory);

        $localSource = $this->resolveSourceToLocalPath($source, $cacheDirectory . '/source', $refresh);
        $parsedPath = $this->resolveDatasetFile($localSource, $cacheDirectory);

        if (preg_match('/\.json$/i', $parsedPath) === 1) {
            return $this->parseCentroidJson($parsedPath);
        }

        return $this->parseCentroidCsv($parsedPath);
    }

    /**
     * @param array<int, array{country_code: string, postal_code: string, place_name: string, state_name: string}> $localities
     * @param array<string, array{population: ?int, area_km2: ?float, latitude: float, longitude: float}> $statsByPostalCode
     * @param array<string, array{latitude: float, longitude: float}> $centroidsByPostalCode
     * @return array{rows: array<int, array<string, int|float|string|null>>, missingStats: int, missingCoordinates: int}
     */
    private function mergeRows(array $localities, array $statsByPostalCode, array $centroidsByPostalCode): array
    {
        $rows = [];
        $missingStats = 0;
        $missingCoordinates = 0;

        foreach ($localities as $locality) {
            $postalCode = $locality['postal_code'];
            $stats = $statsByPostalCode[$postalCode] ?? null;
            $coordinates = $centroidsByPostalCode[$postalCode] ?? $stats;

            if ($stats === null) {
                $missingStats++;
            }

            if ($coordinates === null || ! isset($coordinates['latitude'], $coordinates['longitude'])) {
                $missingCoordinates++;
                continue;
            }

            $rows[] = [
                'country_code' => $locality['country_code'],
                'postal_code' => $postalCode,
                'place_name' => $locality['place_name'],
                'state_name' => $locality['state_name'],
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
                'population' => $stats['population'] ?? null,
                'area_km2' => $stats['area_km2'] ?? null,
            ];
        }

        return [
            'rows' => $rows,
            'missingStats' => $missingStats,
            'missingCoordinates' => $missingCoordinates,
        ];
    }

    /**
     * @param array<int, array<string, int|float|string|null>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $this->ensureDirectory(dirname($path));

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV for writing: {$path}");
        }

        $columns = PostalMapBuilder::getPostalPlacesImportColumns();
        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            $csvRow = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (in_array($column, ['latitude', 'longitude', 'area_km2'], true) && $value !== null) {
                    $value = $this->formatFloat($value);
                }
                $csvRow[] = $value;
            }
            fputcsv($handle, $csvRow);
        }

        fclose($handle);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url, string $cachePath, bool $refresh): array
    {
        $content = $this->fetchText($url, $cachePath, $refresh);
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON received from {$url}");
        }

        return $decoded;
    }

    private function fetchText(string $url, string $cachePath, bool $refresh): string
    {
        if (! $refresh && is_file($cachePath)) {
            $content = file_get_contents($cachePath);
            if ($content === false) {
                throw new RuntimeException("Unable to read cache file: {$cachePath}");
            }
            return $content;
        }

        $this->ensureDirectory(dirname($cachePath));

        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'header' => implode("\r\n", [
                    'User-Agent: lack-citymap-builder/1.0',
                    'Accept: application/json, text/csv;q=0.9, */*;q=0.8',
                ]),
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            $error = error_get_last();
            throw new RuntimeException(sprintf('Unable to download %s: %s', $url, $error['message'] ?? 'unknown error'));
        }

        if (file_put_contents($cachePath, $content) === false) {
            throw new RuntimeException("Unable to write cache file: {$cachePath}");
        }

        return $content;
    }

    private function resolveSourceToLocalPath(string $source, string $cacheBasePath, bool $refresh): string
    {
        if (preg_match('#^https?://#i', $source) !== 1) {
            if (! is_file($source)) {
                throw new RuntimeException("Centroid source not found: {$source}");
            }
            return $source;
        }

        $pathInfo = pathinfo(parse_url($source, PHP_URL_PATH) ?? 'source');
        $extension = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.' . $pathInfo['extension'] : '';
        $targetPath = $cacheBasePath . $extension;

        $this->fetchText($source, $targetPath, $refresh);
        return $targetPath;
    }

    private function resolveDatasetFile(string $sourcePath, string $cacheDirectory): string
    {
        if (preg_match('/\.(csv|json)$/i', $sourcePath) === 1) {
            return $sourcePath;
        }

        if (preg_match('/\.zip$/i', $sourcePath) !== 1) {
            throw new RuntimeException("Unsupported centroid source format: {$sourcePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException("Unable to open zip archive: {$sourcePath}");
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false || preg_match('/\.(csv|json)$/i', $name) !== 1) {
                continue;
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }

            $targetPath = $cacheDirectory . '/extracted-' . basename($name);
            $target = fopen($targetPath, 'wb');
            if ($target === false) {
                fclose($stream);
                throw new RuntimeException("Unable to open extracted centroid target: {$targetPath}");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);
            $zip->close();

            return $targetPath;
        }

        $zip->close();
        throw new RuntimeException("No CSV or JSON file found in centroid archive: {$sourcePath}");
    }

    /**
     * @return array<string, array{latitude: float, longitude: float}>
     */
    private function parseCentroidJson(string $path): array
    {
        $payload = json_decode((string)file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new RuntimeException("Invalid centroid JSON: {$path}");
        }

        $rows = isset($payload[0]) ? $payload : ($payload['data'] ?? $payload['rows'] ?? []);
        if (! is_array($rows)) {
            throw new RuntimeException("Unsupported centroid JSON structure: {$path}");
        }

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeCentroidRow($row);
            if ($normalized === null) {
                continue;
            }
            $result[$normalized['postal_code']] = [
                'latitude' => $normalized['latitude'],
                'longitude' => $normalized['longitude'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{latitude: float, longitude: float}>
     */
    private function parseCentroidCsv(string $path): array
    {
        $probe = fopen($path, 'rb');
        if ($probe === false) {
            throw new RuntimeException("Unable to open centroid CSV: {$path}");
        }
        $headerLine = fgets($probe);
        fclose($probe);

        if ($headerLine === false) {
            throw new RuntimeException("Unable to read centroid CSV header: {$path}");
        }

        $delimiter = $this->detectDelimiter($headerLine);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open centroid CSV: {$path}");
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException("Unable to parse centroid CSV header: {$path}");
        }

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
        }
        $headers = array_map(fn(string $header): string => $this->normalizeHeader($header), $headers);

        $result = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [] || $row === [null]) {
                continue;
            }

            $record = [];
            foreach ($headers as $index => $header) {
                $record[$header] = $row[$index] ?? null;
            }

            $normalized = $this->normalizeCentroidRow($record);
            if ($normalized === null) {
                continue;
            }

            $result[$normalized['postal_code']] = [
                'latitude' => $normalized['latitude'],
                'longitude' => $normalized['longitude'],
            ];
        }

        fclose($handle);
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{postal_code: string, latitude: float, longitude: float}|null
     */
    private function normalizeCentroidRow(array $row): ?array
    {
        $postalCode = $this->pick($row, ['postal_code', 'postalcode', 'zipcode', 'zip', 'plz']);
        $latitude = $this->pick($row, ['latitude', 'lat']);
        $longitude = $this->pick($row, ['longitude', 'lon', 'lng', 'long']);

        if ($postalCode === null || $latitude === null || $longitude === null) {
            return null;
        }

        return [
            'postal_code' => $postalCode,
            'latitude' => $this->normalizeFloat($latitude) ?? 0.0,
            'longitude' => $this->normalizeFloat($longitude) ?? 0.0,
        ];
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $best = ',';
        $bestCount = -1;

        foreach ($delimiters as $delimiter) {
            $count = count(str_getcsv($line, $delimiter));
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = trim(mb_strtolower($header));
        $header = preg_replace('/[^\pL\pN]+/u', '_', $header) ?? $header;
        return trim($header, '_');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if ($value === null) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            return $value;
        }

        return null;
    }

    private function normalizeFloat(string $value): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        return $value === '' ? null : (float)$value;
    }

    /**
     * @param float|int|string|null $value
     */
    private function formatFloat(float|int|string|null $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function writeSummary(string $path, array $summary): void
    {
        $this->ensureDirectory(dirname($path));

        if (file_put_contents(
            $path,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
        ) === false) {
            throw new RuntimeException("Unable to write summary file: {$path}");
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
    }

    private function log(string $message): void
    {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)($message);
    }
}
