import styles from './MenuRow.module.css';

export function MenuRow({
  icon,
  label,
  hint,
  onClick,
  danger,
}: {
  icon: string;
  label: string;
  hint?: string;
  onClick: () => void;
  danger?: boolean;
}) {
  return (
    <button type="button" className={`${styles.row} ${danger ? styles.danger : ''}`} onClick={onClick}>
      <span className={styles.icon} aria-hidden>
        {icon}
      </span>
      <span className={styles.text}>
        <span className={styles.label}>{label}</span>
        {hint && <span className={styles.hint}>{hint}</span>}
      </span>
      <span className={styles.chevron} aria-hidden>
        ›
      </span>
    </button>
  );
}
