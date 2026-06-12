# Builder für die deutsche Datenbasis

Dieses Verzeichnis baut die deutsche Datenbasis unter `resources/` per Script auf.

## Verwendete Quellen

Standardmäßig werden diese freien Quellen zusammengeführt:

1. **PLZ + Ort + Bundesland**
   - OpenPLZ API
   - Endpoint: `https://openplzapi.org/de/Localities`
2. **PLZ-Gebiete + Einwohner + Fläche**
   - ArcGIS Open Data „Postleitzahlengebiete in Deutschland“
   - Endpoint: `https://services2.arcgis.com/jUpNdisbWqRpMo35/arcgis/rest/services/PLZ_Gebiete/FeatureServer/0/query`
3. **PLZ + Latitude/Longitude**
   - optional über `--centroid-source`
   - erwartet CSV, JSON oder ZIP mit Feldern wie `plz` / `postal_code` und `latitude` / `longitude`
   - falls nicht gesetzt, nutzt der Builder die Zentroide aus dem ArcGIS-Datensatz als Fallback

## Ausgabe

Der Builder erzeugt standardmäßig:

- CSV: `resources/data.de.csv`
- SQLite: `resources/data.de.sqlite`
- CSV-Zusammenfassung: `resources/builder/build/summary.de.csv.json`
- SQLite-Zusammenfassung: `resources/builder/build/summary.de.sqlite.json`
- Gesamt-Zusammenfassung beim Kombi-Run: `resources/builder/build/summary.de.json`
- Cache der Quelldaten: `resources/builder/cache/`

## Aufruf

### 1. CSV erzeugen

```bash
php resources/builder/build-de-postal-csv.php
```

Mit expliziter Zentroid-Quelle:

```bash
php resources/builder/build-de-postal-csv.php \
  --centroid-source="https://example.org/de-plz-centroids.csv"
```

Mit frischem Download statt Cache:

```bash
php resources/builder/build-de-postal-csv.php --refresh
```

### 2. CSV in SQLite importieren

```bash
php resources/builder/import-de-postal-csv-to-sqlite.php
```

Mit abweichenden Pfaden:

```bash
php resources/builder/import-de-postal-csv-to-sqlite.php \
  --input-csv="resources/data.de.csv" \
  --output-db="resources/data.de.sqlite"
```

### Optional: beide Schritte hintereinander

```bash
php resources/builder/build-de-postal-db.php
```

## Hinweise

- Die OpenPLZ-Abfrage läuft seitenweise.
- Einwohner und Fläche werden pro PLZ aus ArcGIS übernommen.
- Wenn eine PLZ in OpenPLZ existiert, aber keine Koordinaten gefunden werden, wird der Datensatz übersprungen.
- Wenn kein separater GitHub-Zentroid-Datensatz gesetzt ist, sind Koordinaten trotzdem über ArcGIS verfügbar.
