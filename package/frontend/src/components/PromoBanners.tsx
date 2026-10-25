import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { getActiveBanners, getErrorMessage, type PromoBanner } from '../api';
import styles from './PromoBanners.module.css';

function handleBannerNav(navigate: ReturnType<typeof useNavigate>, b: PromoBanner) {
  const link = b.cta_deep_link || '';
  if (link.startsWith('/')) {
    navigate(link);
    return;
  }
  if (link.startsWith('http')) {
    window.open(link, '_blank');
    return;
  }
  if (link.includes('wallet')) navigate('/wallet');
  else if (link.includes('referral')) navigate('/referral');
  else if (link.includes('subscription')) navigate('/subscription');
  else if (link.includes('corporate')) navigate('/corporate');
}

export function PromoBanners() {
  const navigate = useNavigate();
  const [banners, setBanners] = useState<PromoBanner[]>([]);
  const [index, setIndex] = useState(0);

  useEffect(() => {
    getActiveBanners()
      .then(setBanners)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (banners.length <= 1) return;
    const timer = setInterval(() => {
      setIndex((i) => (i + 1) % banners.length);
    }, 5000);
    return () => clearInterval(timer);
  }, [banners.length]);

  if (banners.length === 0) return null;

  const current = banners[index] ?? banners[0];

  return (
    <section className={styles.section} aria-label="Announcements">
      <div className={styles.header}>
        <h2 className={styles.title}>Announcements</h2>
        <button type="button" className={styles.viewAll} onClick={() => navigate('/announcements')}>
          View all ›
        </button>
      </div>

      <button
        type="button"
        className={styles.banner}
        onClick={() => handleBannerNav(navigate, current)}
      >
        <span className={styles.bell} aria-hidden>
          📢
        </span>
        <span className={styles.headline}>{current.headline}</span>
        <span className={styles.chevron} aria-hidden>
          ›
        </span>
      </button>

      {banners.length > 1 && (
        <div className={styles.dots} role="tablist" aria-label="Announcement pages">
          {banners.map((b, i) => (
            <button
              key={b.id}
              type="button"
              role="tab"
              aria-selected={i === index}
              className={`${styles.dot} ${i === index ? styles.dotActive : ''}`}
              onClick={() => setIndex(i)}
              aria-label={`Announcement ${i + 1}`}
            />
          ))}
        </div>
      )}
    </section>
  );
}

export function usePromoBanners() {
  const [banners, setBanners] = useState<PromoBanner[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    getActiveBanners()
      .then(setBanners)
      .catch((err) => setError(getErrorMessage(err, '')));
  }, []);

  return { banners, error };
}
