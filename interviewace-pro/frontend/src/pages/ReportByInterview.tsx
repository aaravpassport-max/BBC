import { useEffect, useState } from 'react';
import { Navigate, useParams } from 'react-router-dom';
import { reportsApi } from '@/api/reports';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

/** /history/:interviewId — resolves the interview's report id, then hands off to /report/:id so there's one canonical report view. */
export default function ReportByInterview() {
  const { interviewId } = useParams<{ interviewId: string }>();
  const [reportId, setReportId] = useState<number | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    reportsApi
      .byInterview(Number(interviewId))
      .then((r) => {
        if ('report' in r) setReportId(r.report.id);
        else setReportId(r.report_id);
      })
      .catch((err) => setError(err as ApiError));
  }, [interviewId]);

  if (error) return <div className="ia-page-wide"><ErrorBlock error={error} /></div>;
  if (reportId === null) return <div className="ia-page-wide"><LoadingBlock /></div>;
  return <Navigate to={`/report/${reportId}`} replace />;
}
