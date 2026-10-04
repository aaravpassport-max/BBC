/**
 * Admin design config normalization — keeps width layer maps as plain objects
 * so patch/save round-trips match PHP associative arrays (not JSON []).
 */
import { pruneInheritedWidthLayers } from '@/lib/width-inheritance'

type JsonRecord = Record<string, unknown>

function isPlainObject(v: unknown): v is JsonRecord {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

/** Recursively turn array maps (e.g. page_types: []) into object maps. */
function recordMap(v: unknown): JsonRecord {
  if (Array.isArray(v)) {
    const out: JsonRecord = {}
    for (const [k, val] of Object.entries(v)) {
      if (val !== undefined) out[k] = val
    }
    return out
  }
  if (isPlainObject(v)) return { ...v }
  return {}
}

/** Ensure width subtree uses object maps for all layer containers. */
export function normalizeAdminDesignConfig(config: JsonRecord): JsonRecord {
  const next = JSON.parse(JSON.stringify(config)) as JsonRecord
  const rawWidths = next.widths
  const widths = isPlainObject(rawWidths) ? { ...rawWidths } : {}

  widths.global = recordMap(widths.global)
  widths.page_types = recordMap(widths.page_types)
  widths.pages = recordMap(widths.pages)
  widths.sections = recordMap(widths.sections)

  const servicePage = recordMap(widths.service_page)
  servicePage.sections = recordMap(servicePage.sections)
  widths.service_page = servicePage

  const pageTypes = widths.page_types as JsonRecord
  for (const pt of Object.values(pageTypes)) {
    if (!isPlainObject(pt)) continue
    pt.sections = recordMap(pt.sections)
  }

  next.widths = widths
  return next
}

/** Normalize + sparse width overrides before publish. */
export function prepareDesignConfigForSave(config: JsonRecord): JsonRecord {
  const next = normalizeAdminDesignConfig(config)
  if (isPlainObject(next.widths)) {
    next.widths = pruneInheritedWidthLayers(next.widths)
  }
  return next
}

/** Walk patch path ensuring each segment is a plain object (not Array). */
export function ensurePatchPath(root: JsonRecord, path: string[]): JsonRecord {
  let cur = root
  for (let i = 0; i < path.length - 1; i++) {
    const k = path[i]
    cur[k] = recordMap(cur[k])
    cur = cur[k] as JsonRecord
  }
  return cur
}
