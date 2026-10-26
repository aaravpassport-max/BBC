#!/usr/bin/env node
/**
 * LeadCRM desktop runtime — loopback PostgreSQL, Express API, Next.js standalone UI.
 * Used by the Electron shell and by `npm run desktop:start` after a production build.
 */
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');

const root = path.resolve(process.env.LEADCRM_APP_ROOT || path.join(__dirname, '..'));
const directory = process.env.LEADCRM_DATA_DIR || path.join(root, '.leadcrm-desktop-data');
const databaseDirectory = path.join(directory, 'postgres');
const runtimeFile = path.join(directory, 'runtime.json');
const stopFile = path.join(directory, 'stop.json');
const logFile = path.join(directory, 'desktop.log');
const frontendPort = Number(process.env.LEADCRM_FRONTEND_PORT || 13100);
const backendPort = Number(process.env.LEADCRM_BACKEND_PORT || 13101);
const dbPort = 55434;
const children = new Set();
let postgresBin;
let settings;
let stopping = false;
let startedDatabase = false;

const environment = {};
for (const key of [
  'PATH', 'Path', 'PATHEXT', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'ComSpec', 'HOME', 'USERPROFILE',
  'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432',
]) {
  if (process.env[key] !== undefined) environment[key] = process.env[key];
}

function log(message) {
  const line = `[${new Date().toISOString()}] ${message}\n`;
  try {
    fs.mkdirSync(directory, { recursive: true });
    fs.appendFileSync(logFile, line);
  } catch {}
  if (!process.env.LEADCRM_DESKTOP_QUIET) process.stdout.write(line);
}

function run(executable, args, options = {}) {
  const result = spawnSync(executable, args, {
    cwd: root,
    env: environment,
    windowsHide: true,
    stdio: 'pipe',
    ...options,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `${path.basename(executable)} failed: ${(result.stderr ?? result.stdout ?? '').toString().slice(-2000)}`,
    );
  }
  return result.stdout?.toString() ?? '';
}

const postgres = name => path.join(postgresBin, name + (process.platform === 'win32' ? '.exe' : ''));

async function freePort(port) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', () => reject(new Error(`Local port ${port} is in use.`)));
    probe.listen(port, '127.0.0.1', () => probe.close(resolve));
  });
}

function launch(args, cwd, extra = {}) {
  const stdio = process.env.LEADCRM_DESKTOP_QUIET ? 'ignore' : 'inherit';
  const child = spawn(process.execPath, args, {
    cwd,
    env: { ...environment, ...extra },
    stdio,
    windowsHide: true,
  });
  children.add(child);
  child.once('error', error => {
    log(`Child process error: ${error.message}`);
    stop(1);
  });
  child.once('exit', code => {
    children.delete(child);
    if (!stopping) stop(code ?? 1);
  });
  return child;
}

function stop(code = 0) {
  if (stopping) return;
  stopping = true;
  for (const child of children) {
    if (process.platform === 'win32') {
      spawnSync('taskkill.exe', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
    } else {
      child.kill('SIGTERM');
    }
  }
  if (startedDatabase) {
    const verified = fs.realpathSync(databaseDirectory);
    if (verified !== path.join(fs.realpathSync(directory), 'postgres')) {
      throw new Error('Database directory changed; refusing to stop PostgreSQL.');
    }
    spawnSync(postgres('pg_ctl'), ['-D', verified, '-m', 'fast', '-w', 'stop'], {
      env: environment,
      windowsHide: true,
      stdio: 'ignore',
    });
  }
  if (fs.existsSync(runtimeFile)) {
    try {
      const running = JSON.parse(fs.readFileSync(runtimeFile, 'utf8'));
      if (running.pid === process.pid) fs.unlinkSync(runtimeFile);
    } catch {}
  }
  if (fs.existsSync(stopFile)) fs.unlinkSync(stopFile);
  process.exit(code);
}

function resolvePostgresBin() {
  if (process.env.POSTGRES_BIN) return process.env.POSTGRES_BIN;
  try {
    return run('pg_config', ['--bindir']).trim();
  } catch {
    const bundled = path.join(root, '..', 'leadcrm-desktop', 'runtimes', 'postgres', 'bin');
    if (process.platform === 'win32' && fs.existsSync(path.join(bundled, 'initdb.exe'))) return bundled;
    throw new Error('PostgreSQL binaries not found. Set POSTGRES_BIN or install PostgreSQL.');
  }
}

async function main() {
  if (process.argv.includes('--stop')) {
    if (!fs.existsSync(runtimeFile)) {
      log('LeadCRM desktop is already stopped.');
      return;
    }
    const running = JSON.parse(fs.readFileSync(runtimeFile, 'utf8'));
    fs.writeFileSync(stopFile, JSON.stringify({ pid: running.pid }));
    for (let attempt = 0; attempt < 60; attempt++) {
      if (!fs.existsSync(runtimeFile)) {
        log('LeadCRM desktop stopped.');
        return;
      }
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('Stop request timed out.');
  }

  await Promise.all([freePort(frontendPort), freePort(backendPort)]);
  fs.mkdirSync(directory, { recursive: true });

  const settingsFile = path.join(directory, 'settings.json');
  settings = fs.existsSync(settingsFile)
    ? JSON.parse(fs.readFileSync(settingsFile, 'utf8'))
    : {
        format: 'leadcrm-local-development-v1',
        port: dbPort,
        database: 'leadcrm_local_development',
        databasePassword: randomBytes(24).toString('hex'),
        jwtSecret: randomBytes(32).toString('hex'),
        encryptionKey: randomBytes(32).toString('hex'),
        email: 'local.admin@camxian.com',
        password: `LeadCRM-${randomBytes(8).toString('hex')}!`,
      };

  if (
    settings.format !== 'leadcrm-local-development-v1'
    || settings.port !== dbPort
    || settings.database !== 'leadcrm_local_development'
  ) {
    throw new Error('Unexpected desktop settings profile.');
  }

  if (!fs.existsSync(settingsFile)) {
    fs.writeFileSync(settingsFile, JSON.stringify(settings, null, 2), { mode: 0o600, flag: 'wx' });
  }

  const loginPath = path.join(directory, 'LOGIN.txt');
  fs.writeFileSync(
    loginPath,
    `LeadCRM Desktop\nURL: http://127.0.0.1:${frontendPort}/login\nEmail: ${settings.email}\nPassword: ${settings.password}\n\nKeep this file private. Data directory:\n${directory}\n`,
    { mode: 0o600 },
  );

  const emptyEnv = path.join(directory, 'empty.env');
  fs.writeFileSync(emptyEnv, '# Configuration is supplied by the desktop launcher.\n');
  fs.mkdirSync(path.join(directory, 'private-files'), { recursive: true, mode: 0o700 });

  const appUrl = `http://127.0.0.1:${frontendPort}`;
  const apiUrl = `http://127.0.0.1:${backendPort}/api/v1`;
  const dbUrl = `postgresql://leadcrm_local:${settings.databasePassword}@127.0.0.1:${dbPort}/${settings.database}?connection_limit=8`;

  Object.assign(environment, {
    NODE_ENV: 'production',
    LEADCRM_DESKTOP: '1',
    DATABASE_URL: dbUrl,
    DIRECT_URL: dbUrl,
    PGPASSWORD: settings.databasePassword,
    JWT_SECRET: settings.jwtSecret,
    ENCRYPTION_KEY: settings.encryptionKey,
    DOTENV_CONFIG_PATH: emptyEnv,
    APP_URL: appUrl,
    ALLOWED_ORIGINS: `${appUrl},http://localhost:${frontendPort}`,
    TRUSTED_PROXIES: 'loopback',
    API_URL: apiUrl,
    HOST: '127.0.0.1',
    PORT: String(backendPort),
    NEXT_TELEMETRY_DISABLED: '1',
    NEXT_PUBLIC_USE_MOCK_AUTH: 'false',
    NEXT_PUBLIC_USE_MOCK_DATA: 'false',
    STORAGE_ROOT: path.join(directory, 'private-files'),
    AVATAR_STORAGE_BUCKET: 'avatars',
    RECORD_FILES_STORAGE_BUCKET: 'files',
    LEADCRM_LOCAL_SETTINGS: settingsFile,
  });

  postgresBin = resolvePostgresBin();
  const passwordFile = path.join(directory, 'postgres-password');
  if (!fs.existsSync(path.join(databaseDirectory, 'PG_VERSION'))) {
    if (fs.existsSync(databaseDirectory) && fs.readdirSync(databaseDirectory).length) {
      throw new Error('PostgreSQL data directory is nonempty but uninitialized.');
    }
    fs.writeFileSync(passwordFile, settings.databasePassword, { mode: 0o600 });
    run(postgres('initdb'), [
      '-D', databaseDirectory, '-U', 'leadcrm_local', '--auth=scram-sha-256',
      `--pwfile=${passwordFile}`, '--encoding=UTF8', '--locale=C',
    ]);
    fs.appendFileSync(
      path.join(databaseDirectory, 'postgresql.conf'),
      "\nlisten_addresses = '127.0.0.1'\nunix_socket_directories = ''\n",
    );
    fs.unlinkSync(passwordFile);
  }

  const status = spawnSync(postgres('pg_ctl'), ['-D', databaseDirectory, 'status'], {
    env: environment,
    windowsHide: true,
    stdio: 'ignore',
  });
  if (status.status !== 0) {
    await freePort(dbPort);
    run(postgres('pg_ctl'), [
      '-D', databaseDirectory, '-l', path.join(directory, 'postgres.log'),
      '-o', `-p ${dbPort} -c timezone=UTC`, '-w', 'start',
    ], { stdio: process.platform === 'win32' ? 'ignore' : 'pipe' });
    startedDatabase = true;
  }

  const psqlArgs = ['-X', '-h', '127.0.0.1', '-p', String(dbPort), '-U', 'leadcrm_local', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1'];
  const exists = run(postgres('psql'), [...psqlArgs, '-At', '-c', "SELECT 1 FROM pg_database WHERE datname='leadcrm_local_development'"]).trim() === '1';
  if (!exists) run(postgres('psql'), [...psqlArgs, '-c', 'CREATE DATABASE leadcrm_local_development']);

  log('Applying database migrations…');
  run(process.execPath, [
    path.join(root, 'node_modules/prisma/build/index.js'),
    'migrate', 'deploy',
    '--schema', path.join(root, 'backend/prisma/schema.prisma'),
  ]);

  const backendEntry = path.join(root, 'backend/dist/start.js');
  const standaloneServer = path.join(root, 'frontend/.next/standalone/frontend/server.js');
  if (!fs.existsSync(backendEntry)) throw new Error('Missing backend build. Run npm run build from the LeadCRM root.');
  if (!fs.existsSync(standaloneServer)) {
    throw new Error('Missing frontend standalone build. Run npm run desktop:prepare after build.');
  }

  run(process.execPath, [path.join(root, 'scripts/local-development-bootstrap.cjs')]);

  launch([backendEntry], path.join(root, 'backend'));

  let ready = false;
  for (let attempt = 0; attempt < 120; attempt++) {
    try {
      ready = (await fetch(`http://127.0.0.1:${backendPort}/health`, { signal: AbortSignal.timeout(1000) })).ok;
    } catch {}
    if (ready) break;
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  if (!ready) throw new Error('API did not become ready.');

  const standaloneRoot = path.join(root, 'frontend/.next/standalone');
  launch([standaloneServer], standaloneRoot, {
    HOSTNAME: '127.0.0.1',
    PORT: String(frontendPort),
    API_URL: apiUrl,
  });

  fs.writeFileSync(runtimeFile, JSON.stringify({
    pid: process.pid,
    startedAt: new Date().toISOString(),
    frontendPort,
    backendPort,
    dataDir: directory,
  }));
  if (fs.existsSync(stopFile)) fs.unlinkSync(stopFile);

  setInterval(() => {
    let request;
    try {
      request = JSON.parse(fs.readFileSync(stopFile, 'utf8'));
    } catch {
      return;
    }
    if (request.pid === process.pid) stop();
  }, 500);

  log(`LeadCRM desktop ready at ${appUrl}/login`);
  log(`Credentials: ${loginPath}`);
}

process.once('SIGINT', () => stop());
process.once('SIGTERM', () => stop());
main().catch(error => {
  log(error.message);
  stop(1);
});
