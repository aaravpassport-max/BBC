import { type InputHTMLAttributes, forwardRef, useId } from 'react';
import styles from './Input.module.css';

interface Props extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  prefix?: string;
}

export const Input = forwardRef<HTMLInputElement, Props>(({ label, error, prefix, className, ...rest }, ref) => {
  const errorId = useId();
  return (
    <label className={styles.wrapper}>
      {label && <span className={styles.label}>{label}</span>}
      <div className={`${styles.inputRow} ${error ? styles.errorRow : ''}`}>
        {prefix && <span className={styles.prefix}>{prefix}</span>}
        <input
          ref={ref}
          className={`${styles.input} ${className || ''}`}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          {...rest}
        />
      </div>
      {error && (
        <span className={styles.error} id={errorId} role="alert">
          {error}
        </span>
      )}
    </label>
  );
});
Input.displayName = 'Input';
