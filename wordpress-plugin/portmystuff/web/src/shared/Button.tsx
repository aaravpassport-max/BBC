import type { ButtonHTMLAttributes, ReactNode } from 'react';
import styles from './Button.module.css';

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'danger';
  children: ReactNode;
};

export function Button({ variant = 'primary', className = '', children, ...rest }: Props) {
  return (
    <button className={`${styles.btn} ${styles[variant]} ${className}`} {...rest}>
      {children}
    </button>
  );
}
