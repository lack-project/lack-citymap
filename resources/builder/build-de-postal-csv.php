#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lack\CityMap\GermanPostalDatasetBuilder;

$options = getopt('', [
    'output-csv::',
    'centroid-source::',
    'refresh',
]);

$builderDirectory = __DIR__;
$outputCsvPath = $options['output-csv'] ?? dirname(__DIR__) . '/data.de.csv';
$centroidSource = $options['centroid-source'] ?? null;
$refresh = array_key_exists('refresh', $options);

$builder = new GermanPostalDatasetBuilder(
    static function (string $message): void {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
    }
);

try {
    $summary = $builder->buildCsv(
        builderDirectory: $builderDirectory,
        outputCsvPath: $outputCsvPath,
        centroidSource: is_string($centroidSource) ? $centroidSource : null,
        refresh: $refresh
    );

    fwrite(STDOUT, PHP_EOL . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'CSV-Build fehlgeschlagen: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
