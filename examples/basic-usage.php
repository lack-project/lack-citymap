#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lack\CityMap\PostalMap;

$databasePath = dirname(__DIR__) . '/resources/data.de.sqlite';
$map = new PostalMap();

$location = $map->getLocationByPostalCode('50667');
$searchResults = $map->searchLocations('Köln', 'DE', 3);
$nearby = $map->findWithinRadius('50667', 10.0, 'DE', 5);

printf("Datenbank: %s\n\n", $databasePath);

printf("Lookup 50667:\n");
print_r($location?->toArray());

printf("\nSuche nach Köln (max. 3 Treffer):\n");
foreach ($searchResults as $result) {
    print_r($result->toArray());
}

printf("\nUmkreis 10 km um 50667 (max. 5 Treffer):\n");
foreach ($nearby as $result) {
    print_r($result->toArray());
}
