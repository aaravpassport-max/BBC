/** SVG placeholders for /?s2nri_img= keys (WordPress serves these in production). */
export function placeholderImageSvg(key = 'img') {
  const safe = String(key).replace(/[^a-z0-9_-]/gi, '').slice(0, 32) || 'img'
  const hue = (safe.split('').reduce((a, c) => a + c.charCodeAt(0), 0) % 360)
  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="520" viewBox="0 0 800 520">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0%" stop-color="hsl(${hue},45%,42%)"/>
    <stop offset="100%" stop-color="hsl(${(hue + 40) % 360},50%,28%)"/>
  </linearGradient></defs>
  <rect width="800" height="520" fill="url(#g)"/>
  <text x="400" y="270" text-anchor="middle" fill="#fff" font-family="system-ui,sans-serif" font-size="28" font-weight="600">${safe}</text>
</svg>`
}
