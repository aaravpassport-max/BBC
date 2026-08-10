const dotenv = require('dotenv');
const path = require('path');

// Must run before any application module calls dotenv.config() against the
// default .env — dotenv does not override already-set process.env values, so
// loading .env.test here first ensures every module (pool.ts, auth.service.ts,
// etc.) resolves DATABASE_URL and secrets to the TEST database, never the dev
// one. This is what makes it safe to run the suite repeatedly without ever
// touching real dev data.
dotenv.config({ path: path.resolve(__dirname, '.env.test') });
