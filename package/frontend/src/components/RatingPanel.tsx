import { useState } from 'react';
import { Button } from './Button';
import { NEGATIVE_RATING_TAGS, POSITIVE_RATING_TAGS } from '../constants/porter';

export function RatingPanel({
  onSubmit,
  submitting,
}: {
  onSubmit: (stars: number, tags: string[], comment: string) => Promise<void>;
  submitting?: boolean;
}) {
  const [stars, setStars] = useState(0);
  const [tags, setTags] = useState<string[]>([]);
  const [comment, setComment] = useState('');

  const availableTags = stars >= 4 ? POSITIVE_RATING_TAGS : stars > 0 ? NEGATIVE_RATING_TAGS : [];

  function toggleTag(tag: string) {
    setTags((prev) => (prev.includes(tag) ? prev.filter((t) => t !== tag) : prev.length < 3 ? [...prev, tag] : prev));
  }

  return (
    <div style={{ textAlign: 'center', paddingTop: 8 }}>
      <div style={{ fontSize: 14, color: 'var(--text-muted)', marginBottom: 10 }}>How was your delivery?</div>
      <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginBottom: 14 }}>
        {[1, 2, 3, 4, 5].map((star) => (
          <button
            key={star}
            type="button"
            onClick={() => {
              setStars(star);
              setTags([]);
            }}
            disabled={submitting}
            aria-label={`Rate ${star} star${star > 1 ? 's' : ''}`}
            style={{
              background: 'none',
              border: 'none',
              fontSize: 32,
              cursor: 'pointer',
              color: star <= stars ? 'var(--accent)' : 'var(--border)',
              lineHeight: 1,
            }}
          >
            ★
          </button>
        ))}
      </div>

      {stars > 0 && (
        <>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, justifyContent: 'center', marginBottom: 12 }}>
            {availableTags.map((tag) => (
              <button
                key={tag}
                type="button"
                onClick={() => toggleTag(tag)}
                style={{
                  border: `1px solid ${tags.includes(tag) ? 'var(--accent)' : 'var(--border)'}`,
                  background: tags.includes(tag) ? 'var(--accent-soft)' : 'var(--surface)',
                  color: tags.includes(tag) ? 'var(--accent-strong)' : 'var(--text-muted)',
                  borderRadius: 20,
                  padding: '6px 12px',
                  fontSize: 12,
                  cursor: 'pointer',
                }}
              >
                {tag}
              </button>
            ))}
          </div>
          <textarea
            placeholder="Add a comment (optional)"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            maxLength={500}
            style={{
              width: '100%',
              minHeight: 64,
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: 10,
              fontSize: 13,
              marginBottom: 12,
              resize: 'vertical',
            }}
          />
          <Button loading={submitting} onClick={() => void onSubmit(stars, tags, comment)}>
            Submit rating
          </Button>
        </>
      )}
    </div>
  );
}
