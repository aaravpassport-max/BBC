import styles from './Skeleton.module.css';

/**
 * A real animated loading placeholder — closes the specific gap flagged
 * in the Porter comparison ("no skeleton loaders... functionally correct,
 * visually flat compared to a decade-polished product"). Replaces plain
 * "Loading…" text with a shimmering shape that hints at the real content's
 * layout before it arrives, matching the pattern every mature consumer
 * app in this category uses.
 */
export function Skeleton({
  width = '100%',
  height = 16,
  radius = 6,
  style,
}: {
  width?: string | number;
  height?: number;
  radius?: number;
  style?: React.CSSProperties;
}) {
  return <div className={styles.shimmer} style={{ width, height, borderRadius: radius, ...style }} />;
}

/** A pre-composed skeleton for a single list row (History, Wallet
 * transactions, Fleet drivers, etc.) — matches the real row layout those
 * screens use so the loading state doesn't jump/reflow once real data
 * replaces it. */
export function SkeletonRow() {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        border: '1px solid var(--border)',
        borderRadius: 12,
        background: 'var(--surface)',
        padding: '14px 16px',
      }}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        <Skeleton width={110} height={13} />
        <Skeleton width={70} height={11} />
      </div>
      <Skeleton width={60} height={16} />
    </div>
  );
}

export function SkeletonRowList({ count = 3 }: { count?: number }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonRow key={i} />
      ))}
    </div>
  );
}

/** A skeleton shaped for a real <table> with N columns — the admin
 * console's tables (Drivers, Support tickets, RBAC roles, etc.) can't use
 * the card-style SkeletonRow above without looking visually inconsistent
 * with the real table that replaces it. Renders real <tr>/<td> so it sits
 * correctly inside the same <table>/<tbody> the real rows use. */
export function SkeletonTableRows({ columns, rows = 4 }: { columns: number; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r}>
          {Array.from({ length: columns }).map((_, c) => (
            <td key={c} style={{ padding: '10px 8px' }}>
              <Skeleton width={c === columns - 1 ? 40 : '70%'} height={13} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
