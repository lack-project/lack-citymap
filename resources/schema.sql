PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE postal_places (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    country_code    TEXT NOT NULL,
    postal_code     TEXT NOT NULL,
    place_name      TEXT NOT NULL,
    state_name      TEXT NOT NULL,
    latitude        REAL NOT NULL,
    longitude       REAL NOT NULL,
    population      INTEGER,
    area_km2        REAL
);

CREATE UNIQUE INDEX ux_postal_places_country_postal_place_state
    ON postal_places (
        country_code,
        postal_code,
        place_name,
        state_name
    );

CREATE INDEX idx_postal_places_country_postal_code
    ON postal_places (country_code, postal_code);

CREATE INDEX idx_postal_places_country_place_name
    ON postal_places (country_code, place_name);

CREATE INDEX idx_postal_places_country_state_name
    ON postal_places (country_code, state_name);

CREATE INDEX idx_postal_places_country_lat_lon
    ON postal_places (country_code, latitude, longitude);

CREATE INDEX idx_postal_places_country_place_postal
    ON postal_places (country_code, place_name, postal_code);
