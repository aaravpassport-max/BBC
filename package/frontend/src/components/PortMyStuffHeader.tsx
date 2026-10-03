import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BRAND } from '../constants/brand';
import { getWallet } from '../api/bookings';
import { getNotificationInbox } from '../api/features';
import styles from './PortMyStuffHeader.module.css';

export function PortMyStuffHeader({ variant = 'app' }: { variant?: 'app' | 'auth' }) {
  const navigate = useNavigate();
  const [walletBalance, setWalletBalance] = useState<number | null>(null);
  const [unreadCount, setUnreadCount] = useState(0);

  useEffect(() => {
    if (variant !== 'app') return;
    getWallet()
      .then((w) => setWalletBalance(w.real_money_balance + w.promotional_credit_balance))
      .catch(() => undefined);
    getNotificationInbox()
      .then((items) => setUnreadCount(items.filter((n) => n.status !== 'read').length))
      .catch(() => undefined);
  }, [variant]);

  return (
    <header className={`${styles.header} ${variant === 'auth' ? styles.authHeader : ''}`}>
      <div className={styles.topRow}>
        <div>
          <h1 className={styles.brand}>{BRAND.name}</h1>
          <p className={styles.tagline}>{BRAND.tagline}</p>
        </div>
        {variant === 'app' && (
          <div className={styles.actions}>
            <button type="button" className={styles.actionBtn} onClick={() => navigate('/wallet')} aria-label="Wallet">
              <span className={styles.actionIcon}>💳</span>
              <span className={styles.actionLabel}>
                {walletBalance != null ? `₹${Math.round(walletBalance)}` : 'Wallet'}
              </span>
            </button>
            <button type="button" className={styles.actionBtn} onClick={() => navigate('/notifications')} aria-label="Notifications">
              <span className={styles.actionIcon}>🔔</span>
              {unreadCount > 0 && <span className={styles.badge}>{unreadCount > 9 ? '9+' : unreadCount}</span>}
            </button>
          </div>
        )}
      </div>
    </header>
  );
}
