#!/bin/sh
set -e
echo "Running database migrations..."
node dist/scripts/migrate.js
echo "Starting API server..."
exec node dist/server.js
