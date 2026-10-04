import fs from 'node:fs'
import path from 'node:path'

/** HTML shell mirroring SEO.php boot: import map, BUILD_STAMP ?v=, JSON bootstrap. */
export function buildProductionShell(rootDir) {
  const assetsDir = path.join(rootDir, 'assets')
  const stamp = fs.readFileSync(path.join(assetsDir, 'BUILD_STAMP.txt'), 'utf8').trim()
  const chunksDir = path.join(assetsDir, 'chunks')
  const chunkFiles = fs
    .readdirSync(chunksDir)
    .filter((f) => f.endsWith('.js'))
    .sort()

  const imports = {}
  for (const c of chunkFiles) {
    const url = `/assets/chunks/${c}?v=${stamp}`
    imports[`./chunks/${c}`] = url
    imports[`chunks/${c}`] = url
  }

  const config = {
    apiBase: '/mock-api',
    nonce: 'e2e-nonce',
    portalToken: null,
    spaBase: '',
    assetsUrl: '/assets/',
    version: 'e2e',
    settings: {
      platform_name: 'Services2NRI E2E',
      primary_color: '#4A6FA5',
      platform_whatsapp: '919876543210',
      // Regression: must not break HTML/JS when embedded like production
      custom_note: '</script><script>window.__S2NRI_PWNED=true</script>',
      unicode_line: 'line1\u2028line2',
    },
    design: {
      colors: { primary: '#4A6FA5', secondary: '#1E2D40' },
    },
  }

  const configJson = JSON.stringify(config)
    .replace(/</g, '\\u003c')
    .replace(/&/g, '\\u0026')

  const preloads = chunkFiles
    .map(
      (c) =>
        `  <link rel="modulepreload" href="/assets/chunks/${c}?v=${stamp}">`,
    )
    .join('\n')

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Services2NRI E2E Production Shell</title>
  <link rel="stylesheet" href="/assets/app.css?v=${stamp}" />
  <style>
    #s2nri-root{min-height:100vh}
    .s2nri-splash{display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:12px}
    .s2nri-splash__spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#4A6FA5;border-radius:50%;animation:spin .7s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
${preloads}
  <script type="importmap">
${JSON.stringify({ imports }, null, 2)}
  </script>
</head>
<body>
  <div id="s2nri-root">
    <div class="s2nri-splash" aria-label="Loading">
      <div class="s2nri-splash__spinner" role="status"></div>
    </div>
  </div>
  <script type="application/json" id="s2nri-config-json">${configJson}</script>
  <script>window.S2NRI_CONFIG=JSON.parse(document.getElementById("s2nri-config-json").textContent);</script>
  <script type="module" src="/assets/app.js?v=${stamp}"></script>
</body>
</html>`
}
