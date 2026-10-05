/**
 * Extra element nodes for the Elements inspector when content lives in the Service Registry
 * or other admin tools rather than platform settings.
 */
import type { CmsElementNode } from '@/lib/design-element-tree'

function registryElement(
  id: string,
  label: string,
  detail: string,
): CmsElementNode {
  return {
    id,
    label,
    description: `${detail} — edit per service in Admin → Service Registry → Page builder.`,
    controls: [
      {
        id: `${id}__registry_source`,
        label: 'Content source',
        group: 'content',
      },
    ],
  }
}

export function sectionElementPresets(pageId: string, sectionKey: string): CmsElementNode[] {
  if (pageId !== 'service') return []

  switch (sectionKey) {
    case 'hero':
      return [
        registryElement('badge', 'Category badge', 'Category badge'),
        registryElement('heading', 'Hero title', 'Hero title & background'),
        registryElement('subtitle', 'Hero subtitle', 'Hero subtitle'),
        registryElement('primary_button', 'Primary CTA', 'Primary CTA button'),
        registryElement('secondary_button', 'Secondary CTA', 'Secondary CTA button'),
        registryElement('meta_chips', 'Meta chips row', 'Turnaround / price chips'),
      ]
    case 'trust_badges':
      return [registryElement('collection', 'Trust badge cards', 'Trust badges band')]
    case 'description':
      return [registryElement('body', 'Prose content', 'Description section')]
    case 'features':
      return [
        registryElement('heading', 'Section heading', 'Why choose block heading'),
        registryElement('collection', 'Feature cards', 'Feature cards'),
      ]
    case 'process':
      return [registryElement('collection', 'Process steps', 'Process steps')]
    case 'documents':
      return [registryElement('collection', 'Documents list', 'Documents & eligibility')]
    case 'benefits':
      return [registryElement('collection', 'Benefits list', 'Benefits list')]
    case 'faq':
      return [registryElement('collection', 'FAQ items', 'FAQ block')]
    case 'pricing':
      return [registryElement('collection', 'Pricing rows', 'Pricing / charges')]
    case 'testimonials':
      return [registryElement('collection', 'Testimonials', 'Testimonials')]
    case 'related':
      return [registryElement('collection', 'Related services', 'Related services grid')]
    case 'cta':
      return [
        registryElement('heading', 'CTA heading', 'Bottom CTA band heading'),
        registryElement('primary_button', 'CTA button', 'CTA button'),
      ]
    case 'wizard':
      return [
        registryElement('service_summary', 'Service summary header', 'Wizard header (service name)'),
        registryElement('step_tracker', 'Step tracker', 'Step tracker labels'),
        registryElement('step_heading', 'Active step title', 'Form schema step labels'),
        registryElement('form_fields', 'Form fields', 'Form builder fields'),
      ]
    default:
      return []
  }
}
