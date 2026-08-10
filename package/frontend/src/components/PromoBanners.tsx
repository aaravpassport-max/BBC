import { useEffect, useState } from 'react';
import { getActiveBanners, getErrorMessage, type PromoBanner } from '../api';
import styles from './PromoBanners.module.css';

export function PromoBanners() {
  const [banners, setBanners] = useState<PromoBanner[]>([]);

  useEffect(() => {
    getActiveBanners()
      .then(setBanners)
      .catch(() => undefined);
  }, []);

  if (banners.length === 0) return null;

  return (
    <div className={styles.wrap}>
      {banners.map((b) => (
        <div key={b.id} className={styles.banner}>
          {b.image_url ? (
            <img src={b.image_url} alt="" className={styles.image} />
          ) : (
            <div className={styles.placeholder} />
          )}
          <div className={styles.content}>
            <div className={styles.headline}>{b.headline}</div>
            {b.cta_text && <div className={styles.cta}>{b.cta_text}</div>}
          </div>
        </div>
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
