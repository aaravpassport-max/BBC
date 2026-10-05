#!/usr/bin/env node
/**
 * Builds assets/india-locations/india-locations.json from authoritative open sources.
 *
 * Sources:
 * - dr5hn/countries-states-cities-database (states/UTs, ISO 3166-2, ODbL)
 * - GeoNames.org export (districts admin2, cities/towns, CC-BY-4.0)
 *
 * Re-run when administrative boundaries change: npm run build:india-locations
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import https from 'https';
import { createWriteStream } from 'fs';
import { pipeline } from 'stream/promises';
import { execSync } from 'child_process';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.join(ROOT, '..', 'src-tauri', 'assets', 'india-locations');
const SCHEMA_VERSION = 1;

const SOURCES = {
  dr5hnStates:
    'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/json/states.json',
  geonamesAdmin1: 'https://download.geonames.org/export/dump/admin1CodesASCII.txt',
  geonamesAdmin2: 'https://download.geonames.org/export/dump/admin2Codes.txt',
  geonamesIN: 'https://download.geonames.org/export/dump/IN.zip',
};

function normalizeKey(s) {
  return s
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]/g, '');
}

function slugPart(s) {
  return normalizeKey(s).slice(0, 48) || 'x';
}

async function download(url, dest) {
  await fs.promises.mkdir(path.dirname(dest), { recursive: true });
  return new Promise((resolve, reject) => {
    const file = createWriteStream(dest);
    https
      .get(url, { headers: { 'User-Agent': 'google-maps-scraper-desktop-build/1.0' } }, (res) => {
        if (res.statusCode === 301 || res.statusCode === 302) {
          file.close();
          fs.unlinkSync(dest);
          return download(res.headers.location, dest).then(resolve, reject);
        }
        if (res.statusCode !== 200) {
          reject(new Error(`HTTP ${res.statusCode} for ${url}`));
          return;
        }
        res.pipe(file);
        file.on('finish', () => {
          file.close();
          resolve();
        });
      })
      .on('error', reject);
  });
}

function parseAdminLines(text, prefix) {
  const map = new Map();
  for (const line of text.split('\n')) {
    if (!line.startsWith(prefix)) continue;
    const parts = line.split('\t');
    const code = parts[0];
    const name = parts[1];
    map.set(code, name);
  }
  return map;
}

function placeType(featureCode, population) {
  if (featureCode === 'PPLC') return 'capital';
  if (featureCode.startsWith('PPLA')) return 'admin_seat';
  if (population >= 100000) return 'city';
  if (population >= 20000) return 'town';
  return 'town';
}

async function main() {
  const tmp = path.join(OUT_DIR, '.build-tmp');
  await fs.promises.mkdir(tmp, { recursive: true });

  const statesPath = path.join(tmp, 'states.json');
  const admin1Path = path.join(tmp, 'admin1.txt');
  const admin2Path = path.join(tmp, 'admin2.txt');
  const inZip = path.join(tmp, 'IN.zip');

  console.log('Downloading sources…');
  await download(SOURCES.dr5hnStates, statesPath);
  await download(SOURCES.geonamesAdmin1, admin1Path);
  await download(SOURCES.geonamesAdmin2, admin2Path);
  await download(SOURCES.geonamesIN, inZip);

  execSync(`unzip -o -q ${JSON.stringify(inZip)} -d ${JSON.stringify(tmp)}`);

  const dr5hnStates = JSON.parse(await fs.promises.readFile(statesPath, 'utf8')).filter(
    (s) => s.country_code === 'IN',
  );
  const admin1Raw = parseAdminLines(await fs.promises.readFile(admin1Path, 'utf8'), 'IN.');
  const admin2Raw = parseAdminLines(await fs.promises.readFile(admin2Path, 'utf8'), 'IN.');

  const geonamesAdmin1ByKey = new Map();
  for (const [code, name] of admin1Raw) {
    geonamesAdmin1ByKey.set(normalizeKey(name), { code: code.split('.')[1], name, fullCode: code });
  }

  const aliasAdmin1 = {
    andamanandnicobarislands: 'andamanandnicobar',
    dadraandnagarhavelianddamananddiu: 'dadraandnagarhavelianddamananddiu',
    jammuandkashmir: 'jammuandkashmir',
  };

  const states = [];
  const unmatched = [];

  for (const st of dr5hnStates.sort((a, b) => a.name.localeCompare(b.name))) {
    let key = normalizeKey(st.name);
    if (aliasAdmin1[key]) key = aliasAdmin1[key];
    let gn = geonamesAdmin1ByKey.get(key);
    if (!gn) {
      for (const [k, v] of geonamesAdmin1ByKey) {
        if (k.startsWith(key.slice(0, 8)) || key.startsWith(k.slice(0, 8))) {
          gn = v;
          break;
        }
      }
    }
    if (!gn) {
      unmatched.push(st.name);
      continue;
    }

    const stateId = st.iso3166_2 || `IN-${st.iso2}`;
    const admin1Num = gn.code;
    const districtsMap = new Map();

    for (const [a2code, dname] of admin2Raw) {
      const bits = a2code.split('.');
      if (bits.length !== 3 || bits[0] !== 'IN' || bits[1] !== admin1Num) continue;
      const districtId = `${stateId}-${slugPart(dname)}`;
      districtsMap.set(a2code, {
        id: districtId,
        name: dname,
        geonamesAdmin2: a2code,
        places: [],
      });
    }

    states.push({
      id: stateId,
      name: st.name,
      type: (st.type || 'state').includes('union') ? 'union_territory' : 'state',
      iso3166_2: st.iso3166_2 || stateId,
      iso2: st.iso2,
      geonamesAdmin1: admin1Num,
      districts: [...districtsMap.values()].sort((a, b) => a.name.localeCompare(b.name)),
      _districtsByAdmin2: districtsMap,
    });
  }

  if (unmatched.length) {
    console.warn('Warning: could not match GeoNames admin1 for:', unmatched.join(', '));
  }

  let placeCount = 0;
  const inTxt = path.join(tmp, 'IN.txt');
  const inLines = (await fs.promises.readFile(inTxt, 'utf8')).split('\n');
  for (const line of inLines) {
    if (!line.trim()) continue;
    const p = line.split('\t');
    if (p.length < 19) continue;
    const geonameId = p[0];
    const name = p[1];
    const fc = p[6];
    const fcode = p[7];
    const admin1 = p[10];
    const admin2 = p[11];
    const popStr = p[14];
    const lat = p[4];
    const lon = p[5];
    if (fc !== 'P') continue;
    const population = parseInt(popStr, 10) || 0;
    if (population < 5000 && !fcode.startsWith('PPLA')) continue;
    if (!admin1 || !admin2) continue;

    const st = states.find((s) => s.geonamesAdmin1 === admin1);
    if (!st) continue;
    const a2key = `IN.${admin1}.${admin2}`;
    const dist = st._districtsByAdmin2.get(a2key);
    if (!dist) continue;

    const aliases = [];
    if (name.includes('Bengaluru') && !aliases.includes('Bangalore')) aliases.push('Bangalore');
    if (name.includes('Bangalore') && !aliases.includes('Bengaluru')) aliases.push('Bengaluru');

    dist.places.push({
      id: `gn-${geonameId}`,
      name,
      type: placeType(fcode, population),
      population,
      latitude: lat,
      longitude: lon,
      geonameId,
      ...(aliases.length ? { aliases } : {}),
    });
    placeCount++;
  }

  for (const st of states) {
    delete st._districtsByAdmin2;
    for (const d of st.districts) {
      const seen = new Set();
      d.places = d.places
        .sort((a, b) => (b.population || 0) - (a.population || 0) || a.name.localeCompare(b.name))
        .filter((pl) => {
          const k = normalizeKey(pl.name);
          if (seen.has(k)) return false;
          seen.add(k);
          return true;
        });
    }
  }

  states.sort((a, b) => a.name.localeCompare(b.name));
  const districtCount = states.reduce((n, s) => n + s.districts.length, 0);

  const generatedAt = new Date().toISOString();
  const manifest = {
    schemaVersion: SCHEMA_VERSION,
    generatedAt,
    counts: {
      statesAndUnionTerritories: states.length,
      districts: districtCount,
      places: placeCount,
    },
    sources: [
      {
        name: 'dr5hn/countries-states-cities-database',
        url: SOURCES.dr5hnStates,
        license: 'ODbL 1.0',
        role: 'States and union territories (ISO 3166-2 names and types)',
      },
      {
        name: 'GeoNames',
        url: 'https://www.geonames.org/',
        license: 'CC-BY-4.0 (attribution required)',
        role: 'Districts (admin2) and populated places (cities/towns)',
        files: ['admin1CodesASCII.txt', 'admin2Codes.txt', 'IN.zip'],
      },
    ],
    updateInstructions:
      'Run `npm run build:india-locations` from google-maps-scraper-desktop after upstream releases. Verify counts in this manifest.',
  };

  const dataset = {
    schemaVersion: SCHEMA_VERSION,
    generatedAt,
    states,
  };

  await fs.promises.mkdir(OUT_DIR, { recursive: true });
  await fs.promises.writeFile(path.join(OUT_DIR, 'manifest.json'), JSON.stringify(manifest, null, 2));
  await fs.promises.writeFile(
    path.join(OUT_DIR, 'india-locations.json'),
    JSON.stringify(dataset),
  );
  await fs.promises.writeFile(path.join(OUT_DIR, 'ATTRIBUTION.md'), `# India location data attribution

Generated by \`scripts/build-india-locations.mjs\` on ${generatedAt}.

- **States/UTs**: [dr5hn/countries-states-cities-database](https://github.com/dr5hn/countries-states-cities-database) (ODbL)
- **Districts & places**: [GeoNames](https://www.geonames.org/) (CC BY 4.0)

Include GeoNames attribution in product documentation when redistributing data files.
`);

  await fs.promises.rm(tmp, { recursive: true, force: true });

  console.log('Wrote', path.join(OUT_DIR, 'india-locations.json'));
  console.log(manifest.counts);
  if (states.length !== 36) {
    console.warn(`Expected 36 states/UTs, got ${states.length}`);
    process.exitCode = 1;
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
