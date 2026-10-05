#!/usr/bin/env bash
# One-shot local backend setup (no Docker): Postgres + PostGIS, migrate, seed.
# Usage: ./scripts/dev-setup.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v psql >/dev/null 2>&1; then
  echo "PostgreSQL client not found. Install postgresql and postgresql-contrib-postgis."
  exit 1
fi

if ! pg_isready -q 2>/dev/null; then
  echo "PostgreSQL is not running. Start it first (e.g. sudo service postgresql start)."
  exit 1
fi

echo "Ensuring database role and database exist..."
sudo -u postgres psql -v ON_ERROR_STOP=0 -c "CREATE USER app_user WITH PASSWORD 'dev_local_only';" >/dev/null 2>&1 || true
sudo -u postgres psql -v ON_ERROR_STOP=0 -c "CREATE DATABASE logistics_superapp OWNER app_user;" >/dev/null 2>&1 || true
sudo -u postgres psql -d logistics_superapp -c "CREATE EXTENSION IF NOT EXISTS postgis;"
sudo -u postgres psql -d logistics_superapp -c "GRANT ALL ON SCHEMA public TO app_user;"

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example — review JWT secrets before any real deploy."
fi

npm install
npm run build
npm run migrate
npm run seed

echo ""
echo "Setup complete. Start the API with: npm run dev"
echo "Demo logins (ALLOW_TEST_OTP=true): customer 9000000001 / OTP 111111, driver 9000000002 / OTP 222222"
