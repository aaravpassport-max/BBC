/**
 * Image helpers — matches M{} object and Ct() / $() functions in compiled app.js
 *
 * All images are served via WordPress query string:
 *   /?s2nri_img=<key>        → dynamic service images
 *   /?s2nri_img=avatar_N     → team/testimonial avatars
 *
 * Ct(slug) = service image by slug (used in service list)
 * $(key)   = avatar image (used in testimonials)
 */

const origin = window.location.origin

/** Fallback image URLs keyed by slug/name — matches M{} in compiled bundle */
export const IMAGES: Record<string, string> = {
  // Hero banners
  hero: [
    `${origin}/?s2nri_img=apostille`,
    `${origin}/?s2nri_img=property`,
    `${origin}/?s2nri_img=about`,
  ].join(','),

  // Service images
  property:    `${origin}/?s2nri_img=property`,
  housekeeping:`${origin}/?s2nri_img=property`,
  tenancy:     `${origin}/?s2nri_img=property`,
  rent:        `${origin}/?s2nri_img=property`,
  financial:   `${origin}/?s2nri_img=property`,
  financial2:  `${origin}/?s2nri_img=property`,
  tax:         `${origin}/?s2nri_img=tax`,
  epf:         `${origin}/?s2nri_img=property`,
  singlestatus:`${origin}/?s2nri_img=apostille`,
  birth:       `${origin}/?s2nri_img=apostille`,
  nabc:        `${origin}/?s2nri_img=apostille`,
  apostille:   `${origin}/?s2nri_img=apostille`,
  transcript:  `${origin}/?s2nri_img=apostille`,
  moi:         `${origin}/?s2nri_img=apostille`,
  degree:      `${origin}/?s2nri_img=apostille`,
  marksheet:   `${origin}/?s2nri_img=apostille`,

  // City images
  pune:       `${origin}/?s2nri_img=property`,
  mumbai:     `${origin}/?s2nri_img=property`,
  delhi:      `${origin}/?s2nri_img=property`,
  bangalore:  `${origin}/?s2nri_img=property`,
  hyderabad:  `${origin}/?s2nri_img=property`,
  chennai:    `${origin}/?s2nri_img=property`,
  ahmedabad:  `${origin}/?s2nri_img=property`,
  nagpur:     `${origin}/?s2nri_img=property`,

  // About
  about: `${origin}/?s2nri_img=about`,
}

/**
 * Get a service image by slug — matches Ct() in compiled app.js
 *
 * BUG FIX (found via screenshot: every Property Management service card
 * showing the same "Apostille" image regardless of the actual service):
 * this used to be an EXACT match against IMAGES's keys — but those keys
 * are generic categories (`property`, `tenancy`, `apostille`, etc.), while
 * real service slugs look like `property-registration-assistance`, which
 * never exactly equals `property`. Any service with no image_url of its
 * own (a content/database gap, not something fixable here) fell through
 * to the same hardcoded apostille fallback, regardless of what the service
 * actually was. Now tries a substring match against the real per-service
 * keys first — `property-registration-assistance`.includes(`property`) is
 * true — so a Property Management service at least gets a
 * property-appropriate fallback instead of a random, unrelated one.
 * Falls back to the apostille placeholder only if nothing matches at all.
 */
export function getServiceImage(slug: string): string {
  if (IMAGES[slug]) return IMAGES[slug]
  const categoryKeys = Object.keys(IMAGES).filter((key) => key !== 'hero' && key !== 'about')
  const match = categoryKeys.find((key) => slug.includes(key))
  return match ? IMAGES[match] : `${origin}/?s2nri_img=apostille`
}

/**
 * Get an avatar image by key — matches $() in compiled app.js
 */
export function getAvatarImage(key: string): string {
  return `${origin}/?s2nri_img=${key}`
}

/**
 * Parse hero banners from settings string (comma-separated URLs)
 */
export function parseHeroBanners(banners?: string): string[] {
  if (banners) {
    const parsed = banners.split(',').map(s => s.trim()).filter(Boolean)
    if (parsed.length > 0) return parsed
  }
  return [
    `${origin}/?s2nri_img=apostille`,
    `${origin}/?s2nri_img=property`,
    `${origin}/?s2nri_img=about`,
  ]
}
