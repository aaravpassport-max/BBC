-- Runs automatically the first time the postgis/postgis container starts
-- with an empty data directory (Postgres's own docker-entrypoint-initdb.d
-- convention). Creates the two extensions every migration in this backend
-- depends on, before any migration itself ever runs.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS postgis;
