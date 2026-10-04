import React from 'react'
import { Link } from 'react-router-dom'
import { resolveInternalDestination } from '@/lib/spa-navigation'

type SpaLinkProps = Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
  href: string
}

/** Internal paths use React Router; external / special schemes stay native anchors. */
export function SpaLink({ href, children, onClick, target, rel, ...rest }: SpaLinkProps) {
  const internal = resolveInternalDestination(href)

  if (internal && !target) {
    return (
      <Link to={internal} onClick={onClick} rel={rel} {...rest}>
        {children}
      </Link>
    )
  }

  return (
    <a href={href} onClick={onClick} target={target} rel={rel} {...rest}>
      {children}
    </a>
  )
}
