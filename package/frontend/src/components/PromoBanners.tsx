import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { getActiveBanners, getErrorMessage, type PromoBanner } from '../api';
import styles from './PromoBanners.module.css';

export function PromoBanners() {
  const navigate = useNavigate();
  const [banners, setBanners] = useState<PromoBanner[]>([]);

  useEffect(() => {
    getActiveBanners()
      .then(setBanners)
      .catch(() => undefined);
  }, []);

  if (banners.length === 0) return null;

  function handleCta(b: PromoBanner) {
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
  }

  return (
    <div className={styles.wrap}>
      {banners.map((b) => (
        <button
          key={b.id}
          type="button"
          className={styles.banner}
          onClick={() => handleCta(b)}
          style={{ cursor: b.cta_text ? 'pointer' : 'default', border: 'none', width: '100%', textAlign: 'left' }}
        >
          {b.image_url ? (
            <img src={b.image_url} alt="" className={styles.image} />
          ) : (
            <div className={styles.placeholder} />
          )}
          <div className={styles.content}>
            <div className={styles.headline}>{b.headline}</div>
            {b.cta_text && <div className={styles.cta}>{b.cta_text} →</div>}
          </div>
        </button>
      ))}
    </div>
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
