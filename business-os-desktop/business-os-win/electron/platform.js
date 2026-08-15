/**
 * Cross-platform binary resolution for Business OS Desktop.
 * Windows builds bundle PHP/MariaDB; Linux/macOS dev uses system installs.
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const IS_WIN = process.platform === 'win32';

function resolveBinary(candidates) {
  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate)) return candidate;
  }
  return null;
}

function which(cmd) {
  try {
    return execSync(IS_WIN ? `where ${cmd}` : `which ${cmd}`, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim().split(/\r?\n/)[0];
  } catch {
    return null;
  }
}

function getPhpBinary(phpDir) {
  return resolveBinary([
    path.join(phpDir, IS_WIN ? 'php.exe' : 'bin/php'),
    path.join(phpDir, 'php.exe'),
    path.join(phpDir, 'php'),
    which('php'),
  ]);
}

function getMysqldBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysqld.exe' : 'mysqld'),
    path.join(mariaDir, IS_WIN ? 'mysqld.exe' : 'mysqld'),
    which('mysqld'),
    which('mariadbd'),
  ]);
}

function getMysqlBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysql.exe' : 'mysql'),
    path.join(mariaDir, 'mysql.exe'),
    which('mysql'),
  ]);
}

function getMysqlAdminBinary(mariaDir) {
  return resolveBinary([
    path.join(mariaDir, 'bin', IS_WIN ? 'mysqladmin.exe' : 'mysqladmin'),
    path.join(mariaDir, 'mysqladmin.exe'),
    which('mysqladmin'),
  ]);
}

function getMariaBasedir(mariaDir, mysqld) {
  if (fs.existsSync(path.join(mariaDir, 'bin', IS_WIN ? 'mysqld.exe' : 'mysqld'))) {
    return mariaDir;
  }
  if (IS_WIN) return mariaDir;
  try {
    const help = execSync(`"${mysqld}" --verbose --help 2>/dev/null | head -1`, {
      encoding: 'utf8',
      shell: true,
    });
    const match = help.match(/Ver ([\d.]+)/);
    if (match) return '/usr';
  } catch {}
  return '/usr';
}

function initMariaDataDir(mysqld, basedir, dbDataDir, logDir) {
  const mysqlSystemDir = path.join(dbDataDir, 'mysql');
  if (fs.existsSync(mysqlSystemDir)) return;

  const installDb = resolveBinary([
    path.join(basedir, 'scripts', 'mariadb-install-db'),
    path.join(basedir, 'bin', 'mariadb-install-db'),
    which('mariadb-install-db'),
    which('mysql_install_db'),
  ]);

  if (installDb) {
    execSync(
      `"${installDb}" --datadir="${dbDataDir}" --basedir="${basedir}" --auth-root-authentication-method=normal`,
      { timeout: 120000, stdio: 'pipe' },
    );
    return;
  }

  execSync(`"${mysqld}" --initialize-insecure --datadir="${dbDataDir}" --basedir="${basedir}"`, {
    timeout: 120000,
    stdio: 'pipe',
  });
}

function sleep(seconds) {
  return new Promise((resolve) => setTimeout(resolve, seconds * 1000));
}

function mariaConfigPath(dataDir) {
  return path.join(dataDir, IS_WIN ? 'my.ini' : 'my.cnf');
}

function writeMariaConfig(dataDir, port, logDir, dbDataDir) {
  const cfgPath = mariaConfigPath(dataDir);
  const datadir = dbDataDir.replace(/\\/g, '/');
  const logError = path.join(logDir, 'mariadb.err').replace(/\\/g, '/');

  const content = IS_WIN
    ? `
[mysqld]
port=${port}
datadir="${datadir}"
socket=mysql.sock
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
skip-networking=0
bind-address=127.0.0.1
max_allowed_packet=64M
innodb_buffer_pool_size=128M
log_error="${logError}"
slow_query_log=0
general_log=0
[client]
port=${port}
character-set-server=utf8mb4
`
    : `
[mysqld]
port=${port}
datadir=${datadir}
socket=${path.join(dataDir, 'mysql.sock')}
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
bind-address=127.0.0.1
max_allowed_packet=64M
innodb_buffer_pool_size=128M
log_error=${logError}
slow_query_log=0
general_log=0
[client]
port=${port}
socket=${path.join(dataDir, 'mysql.sock')}
`;

  fs.writeFileSync(cfgPath, content.trim() + '\n');
  return cfgPath;
}

module.exports = {
  IS_WIN,
  getPhpBinary,
  getMysqldBinary,
  getMysqlBinary,
  getMysqlAdminBinary,
  getMariaBasedir,
  initMariaDataDir,
  sleep,
  mariaConfigPath,
  writeMariaConfig,
};
