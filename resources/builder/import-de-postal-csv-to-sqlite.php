#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lack\CityMap\GermanPostalDatasetBuilder;

$options = getopt('', [
    'input-csv::',
    'output-db::',
]);

$builderDirectory = __DIR__;
$inputCsvPath = $options['input-csv'] ?? dirname(__DIR__) . '/data.de.csv';
$outputDatabasePath = $options['output-db'] ?? dirname(__DIR__) . '/data.de.sqlite';

$builder = new GermanPostalDatasetBuilder(
    static function (string $message): void {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
    }
);

try {
    $summary = $builder->importCsvToSqlite(
        builderDirectory: $builderDirectory,
        csvPath: $inputCsvPath,
        outputDatabasePath: $outputDatabasePath
    );

    fwrite(STDOUT, PHP_EOL . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'SQLite-Import fehlgeschlagen: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
