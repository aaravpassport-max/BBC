/**
 * Per-element visual styling stored under design config:
 * overrides.sections.{sectionKey}.elements.{elementId}.{token}
 */
export type ElementStyleRole =
  | 'heading'
  | 'body'
  | 'eyebrow'
  | 'subtitle'
  | 'primary_button'
  | 'secondary_button'
  | 'link'
  | 'image'
  | 'icon'
  | 'collection'
  | 'generic'

export type ElementStyleFieldType = 'color' | 'px' | 'text' | 'select'

export type ElementStyleFieldDef = {
  key: string
  label: string
  type: ElementStyleFieldType
  options?: string[]
  /** Which element ids get this control (`*` = all). */
  roles: (ElementStyleRole | '*')[]
  cssProperty: string
}

export const ELEMENT_STYLE_FIELDS: ElementStyleFieldDef[] = [
  { key: 'color', label: 'Text color', type: 'color', roles: ['*'], cssProperty: 'color' },
  { key: 'background', label: 'Background', type: 'color', roles: ['primary_button', 'secondary_button', 'eyebrow', 'collection', 'icon', 'generic'], cssProperty: 'background-color' },
  { key: 'font_size', label: 'Font size', type: 'px', roles: ['heading', 'body', 'eyebrow', 'subtitle', 'link', 'generic'], cssProperty: 'font-size' },
  { key: 'font_weight', label: 'Font weight', type: 'select', options: ['400', '500', '600', '700', '800'], roles: ['heading', 'body', 'eyebrow', 'subtitle', 'link', 'primary_button', 'secondary_button', 'generic'], cssProperty: 'font-weight' },
  { key: 'line_height', label: 'Line height', type: 'text', roles: ['heading', 'body', 'subtitle', 'generic'], cssProperty: 'line-height' },
  { key: 'letter_spacing', label: 'Letter spacing', type: 'text', roles: ['heading', 'eyebrow', 'subtitle', 'generic'], cssProperty: 'letter-spacing' },
  { key: 'text_align', label: 'Text align', type: 'select', options: ['left', 'center', 'right'], roles: ['heading', 'body', 'subtitle', 'collection', 'generic'], cssProperty: 'text-align' },
  { key: 'text_transform', label: 'Text transform', type: 'select', options: ['none', 'uppercase', 'capitalize', 'lowercase'], roles: ['heading', 'eyebrow', 'subtitle', 'primary_button', 'secondary_button', 'generic'], cssProperty: 'text-transform' },
  { key: 'margin_top', label: 'Margin top', type: 'px', roles: ['*'], cssProperty: 'margin-top' },
  { key: 'margin_bottom', label: 'Margin bottom', type: 'px', roles: ['*'], cssProperty: 'margin-bottom' },
  { key: 'padding_x', label: 'Padding horizontal', type: 'px', roles: ['primary_button', 'secondary_button', 'eyebrow', 'collection', 'generic'], cssProperty: 'padding-inline' },
  { key: 'padding_y', label: 'Padding vertical', type: 'px', roles: ['primary_button', 'secondary_button', 'eyebrow', 'collection', 'generic'], cssProperty: 'padding-block' },
  { key: 'border_radius', label: 'Corner radius', type: 'px', roles: ['primary_button', 'secondary_button', 'image', 'collection', 'generic'], cssProperty: 'border-radius' },
  { key: 'border_width', label: 'Border width', type: 'px', roles: ['primary_button', 'secondary_button', 'image', 'generic'], cssProperty: 'border-width' },
  { key: 'border_color', label: 'Border color', type: 'color', roles: ['primary_button', 'secondary_button', 'image', 'generic'], cssProperty: 'border-color' },
  { key: 'max_width', label: 'Max width', type: 'px', roles: ['heading', 'body', 'subtitle', 'image', 'generic'], cssProperty: 'max-width' },
  { key: 'opacity', label: 'Opacity (0–1)', type: 'text', roles: ['*'], cssProperty: 'opacity' },
]

export function elementStyleRoleFromId(elementId: string): ElementStyleRole {
  const known: ElementStyleRole[] = [
    'heading',
    'body',
    'eyebrow',
    'subtitle',
    'primary_button',
    'secondary_button',
    'link',
    'image',
    'icon',
    'collection',
  ]
  if (known.includes(elementId as ElementStyleRole)) return elementId as ElementStyleRole
  if (elementId.includes('secondary') || elementId.includes('cta2')) return 'secondary_button'
  if (elementId.includes('button') || elementId.includes('cta')) return 'primary_button'
  if (elementId.includes('eyebrow')) return 'eyebrow'
  if (elementId.includes('subtitle') || elementId.includes('sub')) return 'subtitle'
  if (elementId.includes('heading') || elementId.includes('title')) return 'heading'
  if (elementId.includes('body') || elementId.includes('text')) return 'body'
  if (elementId.includes('link')) return 'link'
  if (elementId.includes('image') || elementId.includes('img')) return 'image'
  if (elementId.includes('collection') || elementId.includes('json')) return 'collection'
  if (/_icon$/i.test(elementId) || elementId === 'icon') return 'icon'
  if (/_desc$/i.test(elementId)) return 'body'
  if (/_title$/i.test(elementId)) return 'heading'
  if (/^step_\d+$/i.test(elementId)) return 'generic'
  return 'generic'
}

export function styleFieldsForElement(elementId: string): ElementStyleFieldDef[] {
  const role = elementStyleRoleFromId(elementId)
  return ELEMENT_STYLE_FIELDS.filter((f) => f.roles.includes('*') || f.roles.includes(role))
}

export function elementStylePath(sectionKey: string, elementId: string, fieldKey: string): string[] {
  return ['overrides', 'sections', sectionKey, 'elements', elementId, fieldKey]
}

/** Build CSS declaration block from stored element style tokens. */
export function elementStyleCssDeclarations(
  styles: Record<string, unknown>,
  resolveColor: (v: string) => string,
): string[] {
  const lines: string[] = []
  for (const def of ELEMENT_STYLE_FIELDS) {
    const raw = styles[def.key]
    if (raw === undefined || raw === null || raw === '') continue
    let val = String(raw).trim()
    if (!val) continue
    if (def.type === 'px' && /^\d+$/.test(val)) val = `${val}px`
    if (def.key === 'color' || def.key === 'background' || def.key === 'border_color') {
      val = resolveColor(val)
    }
    const important =
      def.cssProperty === 'color' ||
      def.cssProperty === 'font-size' ||
      def.cssProperty === 'font-weight' ||
      def.cssProperty === 'line-height' ||
      def.cssProperty === 'background-color'
    lines.push(`${def.cssProperty}:${val}${important ? ' !important' : ''}`)
  }
  return lines
}
