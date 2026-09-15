<?php

declare(strict_types=1);

namespace Lack\CityMap;

use PDO;
use RuntimeException;
use ZipArchive;

final class PostalMapBuilder
{
    /**
     * @var null|array<int, array<string, mixed>>
     */
    private static ?array $postalPlacesSchema = null;

    public static function getSchemaSql(): string
    {
        $schemaPath = dirname(__DIR__) . '/resources/schema.sql';
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new RuntimeException("Unable to read schema file: {$schemaPath}");
        }
        return $schema;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getPostalPlacesSchema(): array
    {
        if (self::$postalPlacesSchema !== null) {
            return self::$postalPlacesSchema;
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(self::getSchemaSql());

        $statement = $pdo->query('PRAGMA table_info(postal_places)');
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($columns === []) {
            throw new RuntimeException('Table postal_places not found in schema.sql');
        }

        return self::$postalPlacesSchema = $columns;
    }

    /**
     * @return array<int, string>
     */
    public static function getPostalPlacesImportColumns(): array
    {
        $columns = [];
        foreach (self::getPostalPlacesSchema() as $column) {
            $name = (string)($column['name'] ?? '');
            $type = strtoupper((string)($column['type'] ?? ''));
            $isPrimaryKey = (int)($column['pk'] ?? 0) > 0;

            if ($name === '' || $isPrimaryKey) {
                continue;
            }

            if ($type === 'INTEGER' && str_contains($name, 'id')) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
    }

    public function createEmptyDatabase(string $databasePath): PDO
    {
        $directory = dirname($databasePath);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        if (is_file($databasePath) && ! unlink($databasePath)) {
            throw new RuntimeException("Unable to remove existing database: {$databasePath}");
        }

        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(self::getSchemaSql());

        return $pdo;
    }

    /**
     * @return array{countryCode: string, imported: int, skipped: int, source: string}
     */
    public function importCountry(
        string $databasePath,
        string $countryCode,
        string $source,
        ?string $countryName = null,
        string $sourceName = 'openplz'
    ): array {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $csvPath = $this->resolveCsvPath($source);
        [$delimiter, $headers, $handle] = $this->openCsv($csvPath);

        $mappedHeaders = array_map([$this, 'normalizeHeader'], $headers);
        $importColumns = self::getPostalPlacesImportColumns();
        $imported = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        $deleteStatement = $pdo->prepare('DELETE FROM postal_places WHERE country_code = :country_code');
        $deleteStatement->execute(['country_code' => strtoupper($countryCode)]);

        $insert = $pdo->prepare($this->buildInsertSql($importColumns));

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $data = $this->combineRow($mappedHeaders, $row);
            $rowData = $this->mapImportRowToSchema($data, $countryCode, $importColumns);
            if ($rowData === null) {
                $skipped++;
                continue;
            }

            $insert->execute($rowData);
            $imported++;
        }

        fclose($handle);
        $pdo->commit();
        $pdo->exec('ANALYZE');
        $pdo->exec('VACUUM');

        return [
            'countryCode' => strtoupper($countryCode),
            'imported' => $imported,
            'skipped' => $skipped,
            'source' => $source,
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    private function buildInsertSql(array $columns): string
    {
        if ($columns === []) {
            throw new RuntimeException('No import columns found for postal_places in schema.sql');
        }

        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        return sprintf(
            'INSERT INTO postal_places (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
    }

    /**
     * @param array<string, string|null> $data
     * @param array<int, string> $columns
     * @return null|array<string, string|int|float|null>
     */
    private function mapImportRowToSchema(array $data, string $countryCode, array $columns): ?array
    {
        $postalCode = $this->pick($data, ['postal_code', 'postalcode', 'zip', 'zipcode', 'plz']);
        $placeName = $this->pick($data, ['place_name', 'placename', 'city', 'place', 'locality', 'ort', 'name']);
        $stateName = $this->pick($data, ['state_name', 'state', 'bundesland', 'region', 'province', 'admin_name1']);
        $latitude = $this->pick($data, ['latitude', 'lat']);
        $longitude = $this->pick($data, ['longitude', 'lon', 'lng', 'long']);

        if ($postalCode === null || $placeName === null || $stateName === null || $latitude === null || $longitude === null) {
            return null;
        }

        $values = [
            'country_code' => strtoupper($this->pick($data, ['country_code', 'countrycode']) ?? $countryCode),
            'postal_code' => $postalCode,
            'place_name' => $placeName,
            'state_name' => $stateName,
            'latitude' => $this->normalizeFloat($latitude),
            'longitude' => $this->normalizeFloat($longitude),
            'population' => $this->normalizeInt($this->pick($data, ['population', 'inhabitants', 'einwohner'])),
            'area_km2' => $this->normalizeFloat($this->pick($data, ['area_km2', 'flaeche_km2', 'fläche_km2', 'area', 'surface_km2'])),
        ];

        $row = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $values)) {
                $row[$column] = $data[$column] ?? null;
                continue;
            }
            $row[$column] = $values[$column];
        }

        return $row;
    }

    private function resolveCsvPath(string $source): string
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'postal-map-');
            if ($temporaryFile === false) {
                throw new RuntimeException('Unable to create temporary file');
            }

            $content = file_get_contents($source);
            if ($content === false) {
                throw new RuntimeException("Unable to download source: {$source}");
            }

            if (file_put_contents($temporaryFile, $content) === false) {
                throw new RuntimeException("Unable to write temporary file for source: {$source}");
            }

            $source = $temporaryFile;
        }

        if (! is_file($source)) {
            throw new RuntimeException("Source not found: {$source}");
        }

        if (str_ends_with(strtolower($source), '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($source) !== true) {
                throw new RuntimeException("Unable to open zip source: {$source}");
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name !== false && preg_match('/\.csv$/i', $name) === 1) {
                    $stream = $zip->getStream($name);
                    if ($stream === false) {
                        continue;
                    }
                    $temporaryCsv = tempnam(sys_get_temp_dir(), 'postal-map-csv-');
                    if ($temporaryCsv === false) {
                        throw new RuntimeException('Unable to create temporary csv file');
                    }
                    $target = fopen($temporaryCsv, 'wb');
                    if ($target === false) {
                        throw new RuntimeException('Unable to open temporary csv file');
                    }
                    stream_copy_to_stream($stream, $target);
                    fclose($stream);
                    fclose($target);
                    $zip->close();
                    return $temporaryCsv;
                }
            }

            $zip->close();
            throw new RuntimeException("No CSV file found in zip source: {$source}");
        }

        return $source;
    }

    /**
     * @return array{0: string, 1: array<int, string>, 2: resource}
     */
    private function openCsv(string $path): array
    {
        $probeHandle = fopen($path, 'rb');
        if ($probeHandle === false) {
            throw new RuntimeException("Unable to open CSV: {$path}");
        }
        $headerLine = fgets($probeHandle);
        fclose($probeHandle);

        if ($headerLine === false) {
            throw new RuntimeException("Unable to read CSV header: {$path}");
        }

        $delimiter = $this->detectDelimiter($headerLine);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV: {$path}");
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException("Unable to parse CSV header: {$path}");
        }

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
        }

        return [$delimiter, $headers, $handle];
    }

    private function detectDelimiter(string $headerLine): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $bestCount = -1;

        foreach ($delimiters as $delimiter) {
            $count = count(str_getcsv($headerLine, $delimiter));
            if ($count > $bestCount) {
                $bestDelimiter = $delimiter;
                $bestCount = $count;
            }
        }

        return $bestDelimiter;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string|null> $row
     * @return array<string, string|null>
     */
    private function combineRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = $row[$index] ?? null;
        }
        return $data;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = trim(mb_strtolower($header));
        $header = preg_replace('/[^\pL\pN]+/u', '_', $header) ?? $header;
        return trim($header, '_');
    }

    private function pick(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
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

    private function normalizeFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        return $value === '' ? null : (float)$value;
    }

    private function normalizeInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[^0-9-]/', '', $value) ?? '';
        return $value === '' ? null : (int)$value;
    }
}
