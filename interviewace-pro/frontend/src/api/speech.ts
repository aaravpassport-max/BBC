import { apiFetch } from './client';

/**
 * RESTORED: server-side speech proxy client. The backend endpoints
 * (api/class-api-speech.php — /speech/stt, /speech/tts-token) were never
 * removed; only the frontend calls that actually drive a voice
 * conversation with them had been dropped when LiveInterview.tsx was
 * rebuilt as a plain text chat. See LiveInterview.tsx for the root-cause
 * note and how these are used.
 */
export const speechApi = {
  /** Sends a recorded audio blob to Deepgram (server-side) and gets the transcript back. */
  transcribe: (audioBlob: Blob, interviewId: number): Promise<{ success: true; transcript: string; duration_seconds: number }> => {
    const form = new FormData();
    form.append('audio', audioBlob, 'answer.webm');
    form.append('interview_id', String(interviewId));
    return apiFetch('/speech/stt', { method: 'POST', body: form });
  },

  /** Synthesizes `text` as Priya's voice (ElevenLabs, server-side) and returns it as base64 MP3. */
  synthesize: (text: string, interviewId: number): Promise<{ success: true; audio_base64: string; mime: string }> =>
    apiFetch('/speech/tts-token', {
      method: 'POST',
      body: JSON.stringify({ text, interview_id: interviewId }),
    }),

  /** Short-lived Deepgram listen-only token for secure browser WebSocket streaming. */
  streamToken: (interviewId: number): Promise<{ success: true; token: string; expires_in: number }> =>
    apiFetch('/speech/stt-stream-token', {
      method: 'POST',
      body: JSON.stringify({ interview_id: interviewId }),
    }),
};
