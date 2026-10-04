import fs from 'node:fs'
import path from 'node:path'

/** HTML shell mirroring SEO.php boot: CSS stack, external import map, boot JS, BUILD_STAMP. */
export function buildProductionShell(rootDir) {
  const assetsDir = path.join(rootDir, 'assets')
  const stamp = fs.readFileSync(path.join(assetsDir, 'BUILD_STAMP.txt'), 'utf8').trim()
  const chunksDir = path.join(assetsDir, 'chunks')
  const chunkFiles = fs
    .readdirSync(chunksDir)
    .filter((f) => f.endsWith('.js'))
    .sort()

  const publicCss = fs
    .readdirSync(assetsDir)
    .filter((f) => f.startsWith('public-') && f.endsWith('.css'))
    .sort()

  const stylesheetLinks = ['app.css', 's2nri-theme.css', ...publicCss]
    .filter((f) => fs.existsSync(path.join(assetsDir, f)))
    .map((f) => `  <link rel="stylesheet" href="/assets/${f}?v=${stamp}" />`)
    .join('\n')

  const preloads = chunkFiles
    .map((c) => `  <link rel="modulepreload" href="/assets/chunks/${c}?v=${stamp}">`)
    .join('\n')

  const config = {
    apiBase: '/mock-api',
    nonce: 'e2e-nonce',
    portalToken: null,
    spaBase: '',
    assetsUrl: '/assets/',
    version: 'e2e',
    settings: {
      platform_name: 'Services2NRI',
      primary_color: '#4A6FA5',
      platform_whatsapp: '919876543210',
      hero_heading_1: 'Stay Connected to',
      hero_heading_2: 'INDIA',
      hero_subheading: 'Without the Paperwork Stress',
      stat_1_number: '10,000+',
      stat_1_label: 'Happy Clients',
      custom_note: '</script><script>window.__S2NRI_PWNED=true</script>',
      unicode_line: 'line1\u2028line2',
    },
    design: {
      colors: { primary: '#4A6FA5', secondary: '#1E2D40', heading: '#1E2D40', body: '#334155' },
    },
  }

  const configJson = JSON.stringify(config)
    .replace(/</g, '\\u003c')
    .replace(/&/g, '\\u0026')

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Services2NRI</title>
${stylesheetLinks}
  <style>
    #s2nri-root{min-height:100vh}
    .s2nri-splash{display:flex;align-items:center;justify-content:center;min-height:100vh}
    .s2nri-splash__spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#4A6FA5;border-radius:50%;animation:spin .7s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
${preloads}
  <script type="importmap" src="/?s2nri_import_map=1&v=${stamp}"></script>
</head>
<body>
  <div id="s2nri-root" data-s2nri-version="e2e" data-s2nri-build="${stamp}" data-s2nri-assets="/assets/">
    <div class="s2nri-splash" aria-label="Loading">
      <div class="s2nri-splash__spinner" role="status"></div>
    </div>
  </div>
  <script type="application/json" id="s2nri-config-json">${configJson}</script>
  <script src="/assets/boot-config.js?v=${stamp}"></script>
  <script src="/assets/boot-watchdog.js?v=${stamp}"></script>
  <script type="module" src="/assets/app.js?v=${stamp}"></script>
</body>
</html>`
}

export function buildImportMapJson(rootDir) {
  const assetsDir = path.join(rootDir, 'assets')
  const stamp = fs.readFileSync(path.join(assetsDir, 'BUILD_STAMP.txt'), 'utf8').trim()
  const chunksDir = path.join(assetsDir, 'chunks')
  const imports = {}
  for (const c of fs.readdirSync(chunksDir).filter((f) => f.endsWith('.js')).sort()) {
    const url = `/assets/chunks/${c}?v=${stamp}`
    imports[`./chunks/${c}`] = url
    imports[`chunks/${c}`] = url
  }
  return JSON.stringify({ imports })
}
