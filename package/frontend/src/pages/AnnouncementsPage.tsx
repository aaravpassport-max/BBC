import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { usePromoBanners } from '../components/PromoBanners';
import styles from './AnnouncementsPage.module.css';

export function AnnouncementsPage() {
  const navigate = useNavigate();
  const { banners, error } = usePromoBanners();

  return (
    <Screen eyebrow="Updates" title="Announcements" onBack={() => navigate('/home')} withNav>
      {error && <p className={styles.error}>{error}</p>}
      {banners.length === 0 && !error && (
        <p className={styles.empty}>No announcements right now. Check back soon.</p>
      )}
      <div className={styles.list}>
        {banners.map((b) => (
          <article key={b.id} className={styles.card}>
            {b.image_url ? (
              <img src={b.image_url} alt="" className={styles.image} />
            ) : (
              <div className={styles.placeholder} aria-hidden>
                📢
              </div>
            )}
            <div>
              <h3 className={styles.headline}>{b.headline}</h3>
              {b.cta_text && <p className={styles.cta}>{b.cta_text}</p>}
            </div>
          </article>
        ))}
      </div>
    </Screen>
  );
}
