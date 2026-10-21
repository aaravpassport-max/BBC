using Microsoft.Data.Sqlite;

namespace AttendanceAgent;

/// <summary>
/// Thrown when the agent tries to write activity events for an
/// employee/device pair that has no acknowledged monitoring notice on
/// file. Mirrors TransparencyGateError in core/src/db/repository.ts —
/// the same refusal exists on both sides of the pipe deliberately, so a
/// bug or a tampered config on one side doesn't silently bypass the
/// other.
/// </summary>
public sealed class TransparencyGateException : Exception
{
    public TransparencyGateException(string employeeId, string deviceId)
        : base($"Refusing to write events for employee={employeeId} device={deviceId}: " +
               "no acknowledged monitoring notice on file for this pair.")
    { }
}

/// <summary>
/// Writes raw events into the exact schema defined by
/// core/src/db/migrations/001_init.sql. Deliberately thin: this class
/// does no time-calculation or reconciliation — it only gets timestamped
/// facts into raw_events, idempotently, behind the same transparency gate
/// the TypeScript repository enforces. The reconciler that turns these
/// into state_intervals/break_intervals is the TS engine; this agent does
/// not re-implement it.
///
/// NOTE ON CONCURRENT ACCESS: this agent and any process reading the same
/// .db file (the admin console, a scheduled recompute job) must agree on
/// SQLite's WAL journal mode, set once here. Two WRITER PROCESSES to the
/// same file is still a real risk this design has NOT addressed — see
/// README.md item 3; this agent remains the only writing *process*.
/// Stage 2 DOES add a second writing *thread* within this same process
/// (UsageIngestPipeServer, handling browser-extension events on its own
/// thread per pipe connection, alongside SessionMonitor's poll-timer
/// thread) — System.Data.Sqlite's SqliteConnection is not documented as
/// safe for concurrent use from multiple threads, so every write path
/// below is serialized through _writeLock. This is a real lock around a
/// single shared connection, not a performance optimization: correctness
/// here matters more than write throughput, which is trivially low for
/// this workload (a handful of events per minute at most).
/// </summary>
public sealed class SqliteRepository : IDisposable
{
    private readonly SqliteConnection _connection;
    private readonly object _writeLock = new();

    public SqliteRepository(string databasePath)
    {
        _connection = new SqliteConnection($"Data Source={databasePath}");
        _connection.Open();
        using var pragma1 = _connection.CreateCommand();
        pragma1.CommandText = "PRAGMA journal_mode = WAL;";
        pragma1.ExecuteNonQuery();
        using var pragma2 = _connection.CreateCommand();
        pragma2.CommandText = "PRAGMA foreign_keys = ON;";
        pragma2.ExecuteNonQuery();
        lock (_writeLock)
        {
            AgentDatabaseBootstrap.EnsureSchema(_connection);
        }
    }

    public bool HasAcknowledgedNotice(string employeeId, string deviceId)
    {
        lock (_writeLock)
        {
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                SELECT acknowledged_at_utc FROM notice_acknowledgements
                WHERE employee_id = $employeeId AND device_id = $deviceId";
            cmd.Parameters.AddWithValue("$employeeId", employeeId);
            cmd.Parameters.AddWithValue("$deviceId", deviceId);
            var result = cmd.ExecuteScalar();
            return result is not null && result is not DBNull;
        }
    }

    /// <summary>
    /// Ensures an attendance_sessions row exists for this session, same
    /// idempotent "create on first sight" behavior as
    /// ensureAttendanceSession in repository.ts. Required before any
    /// state_intervals/break_intervals row can reference this session
    /// (foreign key), though this agent itself never writes those tables
    /// — the TS reconciler does, reading raw_events back out.
    /// </summary>
    public void EnsureAttendanceSession(string sessionId, string employeeId, string deviceId, string startUtc)
    {
        lock (_writeLock)
        {
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                INSERT INTO attendance_sessions (session_id, employee_id, device_id, start_utc, end_utc, end_reason, is_end_estimated)
                VALUES ($sessionId, $employeeId, $deviceId, $startUtc, NULL, NULL, 0)
                ON CONFLICT(session_id) DO NOTHING";
            cmd.Parameters.AddWithValue("$sessionId", sessionId);
            cmd.Parameters.AddWithValue("$employeeId", employeeId);
            cmd.Parameters.AddWithValue("$deviceId", deviceId);
            cmd.Parameters.AddWithValue("$startUtc", startUtc);
            cmd.ExecuteNonQuery();
        }
    }

    /// <summary>
    /// Idempotent insert — same ON CONFLICT(event_id) DO NOTHING pattern
    /// as the TS ingestRawEvent, so re-delivering an event after an
    /// offline-sync retry is a safe no-op, never a duplicate row.
    /// Returns true if a new row was actually inserted.
    /// </summary>
    public bool InsertRawEvent(RawEvent evt)
    {
        // The whole method is one critical section — gate check, session
        // ensure, and the insert itself — so a pipe-server thread and the
        // poll-timer thread can never interleave on the same connection,
        // and so the gate check can never pass on one thread while another
        // thread's write slips in before the corresponding ensure.
        lock (_writeLock)
        {
            if (!HasAcknowledgedNotice(evt.EmployeeId, evt.DeviceId))
            {
                throw new TransparencyGateException(evt.EmployeeId, evt.DeviceId);
            }

            if (evt.SessionId is not null)
            {
                EnsureAttendanceSession(evt.SessionId, evt.EmployeeId, evt.DeviceId, evt.TimestampUtc);
            }

            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                INSERT INTO raw_events (event_id, employee_id, device_id, session_id, event_type, timestamp_utc, source, is_estimated, payload_json, ingested_at_utc)
                VALUES ($eventId, $employeeId, $deviceId, $sessionId, $eventType, $timestampUtc, $source, $isEstimated, $payloadJson, $ingestedAt)
                ON CONFLICT(event_id) DO NOTHING";
            cmd.Parameters.AddWithValue("$eventId", evt.EventId);
            cmd.Parameters.AddWithValue("$employeeId", evt.EmployeeId);
            cmd.Parameters.AddWithValue("$deviceId", evt.DeviceId);
            cmd.Parameters.AddWithValue("$sessionId", evt.SessionId ?? (object)DBNull.Value);
            cmd.Parameters.AddWithValue("$eventType", evt.EventType);
            cmd.Parameters.AddWithValue("$timestampUtc", evt.TimestampUtc);
            cmd.Parameters.AddWithValue("$source", evt.Source);
            cmd.Parameters.AddWithValue("$isEstimated", evt.IsEstimated ? 1 : 0);
            cmd.Parameters.AddWithValue("$payloadJson", evt.PayloadJson);
            cmd.Parameters.AddWithValue("$ingestedAt", DateTime.UtcNow.ToString("o"));
            var rowsAffected = cmd.ExecuteNonQuery();
            return rowsAffected > 0;
        }
    }

    public void RecordNoticeShown(string employeeId, string deviceId, string policyVersion)
    {
        lock (_writeLock)
        {
            AgentDatabaseBootstrap.EnsureEmployeeAndDevice(_connection, employeeId, deviceId);
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                INSERT INTO notice_acknowledgements (employee_id, device_id, policy_version, first_shown_at_utc, acknowledged_at_utc)
                VALUES ($employeeId, $deviceId, $policyVersion, $shownAt, NULL)
                ON CONFLICT(employee_id, device_id) DO NOTHING";
            cmd.Parameters.AddWithValue("$employeeId", employeeId);
            cmd.Parameters.AddWithValue("$deviceId", deviceId);
            cmd.Parameters.AddWithValue("$policyVersion", policyVersion);
            cmd.Parameters.AddWithValue("$shownAt", DateTime.UtcNow.ToString("o"));
            cmd.ExecuteNonQuery();
        }
    }

    /// <summary>
    /// The ONLY thing that opens the transparency gate. Called exclusively
    /// from NoticeForm's dismiss handler — never from startup code, a
    /// config default, or a command-line flag. There is intentionally no
    /// other call site in this agent.
    /// </summary>
    public void RecordNoticeAcknowledged(string employeeId, string deviceId)
    {
        lock (_writeLock)
        {
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                UPDATE notice_acknowledgements SET acknowledged_at_utc = $ackAt
                WHERE employee_id = $employeeId AND device_id = $deviceId AND acknowledged_at_utc IS NULL";
            cmd.Parameters.AddWithValue("$employeeId", employeeId);
            cmd.Parameters.AddWithValue("$deviceId", deviceId);
            cmd.Parameters.AddWithValue("$ackAt", DateTime.UtcNow.ToString("o"));
            var rows = cmd.ExecuteNonQuery();
            if (rows == 0)
            {
                throw new InvalidOperationException(
                    $"Cannot acknowledge notice for employee={employeeId} device={deviceId}: " +
                    "no pending (unacknowledged) notice row found. Call RecordNoticeShown first.");
            }
        }
    }

    /// <summary>
    /// Minimal Stage 1 employee self-view: today's raw event count by
    /// type, plus the most recent few timestamps. This reads raw_events
    /// directly rather than state_intervals because the derived tables
    /// are only populated when the TS reconciler's recompute job runs —
    /// which, in Stage 1, happens centrally, not on this machine. A real
    /// Stage 3 build either runs recomputeSession locally too, or has
    /// this form query the central store; this is a named simplification,
    /// not a finished local-reconciliation story.
    /// </summary>
    public IReadOnlyList<string> GetTodaySummaryLines(string employeeId, string deviceId)
    {
        lock (_writeLock)
        {
            var lines = new List<string>();
            var todayStartUtc = DateTime.UtcNow.Date.ToString("o");

            using var countCmd = _connection.CreateCommand();
            countCmd.CommandText = @"
                SELECT event_type, COUNT(*) as cnt FROM raw_events
                WHERE employee_id = $employeeId AND device_id = $deviceId AND timestamp_utc >= $todayStart
                GROUP BY event_type ORDER BY cnt DESC";
            countCmd.Parameters.AddWithValue("$employeeId", employeeId);
            countCmd.Parameters.AddWithValue("$deviceId", deviceId);
            countCmd.Parameters.AddWithValue("$todayStart", todayStartUtc);
            using var reader = countCmd.ExecuteReader();
            while (reader.Read())
            {
                lines.Add($"{reader.GetString(0)}: {reader.GetInt64(1)} recorded today");
            }

            if (lines.Count == 0)
            {
                lines.Add("No activity recorded yet today.");
            }
            return lines;
        }
    }

    /// <summary>
    /// The read side of the sync queue (see core/src/db/migrations/
    /// 006_sync.sql's synced_at_utc column): the oldest not-yet-synced
    /// rows, oldest first, so a long offline backlog drains in the order
    /// it happened rather than newest-first. Returns the same shape
    /// POST /api/events/ingest expects on the wire — EventSyncClient
    /// serializes this directly, no extra mapping step to get wrong.
    /// </summary>
    public IReadOnlyList<SyncableEvent> GetUnsyncedEvents(int limit)
    {
        lock (_writeLock)
        {
            var results = new List<SyncableEvent>();
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = @"
                SELECT event_id, employee_id, device_id, session_id, event_type, timestamp_utc, source, is_estimated, payload_json
                FROM raw_events
                WHERE synced_at_utc IS NULL
                ORDER BY timestamp_utc ASC, rowid ASC
                LIMIT $limit";
            cmd.Parameters.AddWithValue("$limit", limit);
            using var reader = cmd.ExecuteReader();
            while (reader.Read())
            {
                results.Add(new SyncableEvent(
                    EventId: reader.GetString(0),
                    EmployeeId: reader.GetString(1),
                    DeviceId: reader.GetString(2),
                    SessionId: reader.IsDBNull(3) ? null : reader.GetString(3),
                    Type: reader.GetString(4),
                    TimestampUtc: reader.GetString(5),
                    Source: reader.GetString(6),
                    IsEstimated: reader.GetInt64(7) != 0,
                    PayloadJson: reader.GetString(8)
                ));
            }
            return results;
        }
    }

    /// <summary>
    /// Marks a batch synced ONLY after the central server has confirmed
    /// receipt (EventSyncClient calls this strictly after a successful
    /// POST /api/events/ingest response, never optimistically before
    /// sending) — so a crash or lost connection mid-send just means the
    /// same rows are picked up by GetUnsyncedEvents again next time,
    /// never silently marked synced when they weren't. Re-sending an
    /// already-synced row to the server is itself harmless (the
    /// server's own ON CONFLICT(event_id) DO NOTHING), so erring toward
    /// "mark synced late" over "mark synced early" costs nothing but a
    /// redundant upload.
    /// </summary>
    public void MarkEventsSynced(IReadOnlyCollection<string> eventIds)
    {
        if (eventIds.Count == 0) return;
        lock (_writeLock)
        {
            using var transaction = _connection.BeginTransaction();
            using var cmd = _connection.CreateCommand();
            cmd.CommandText = "UPDATE raw_events SET synced_at_utc = $syncedAt WHERE event_id = $eventId";
            var syncedAtParam = cmd.Parameters.AddWithValue("$syncedAt", DateTime.UtcNow.ToString("o"));
            var eventIdParam = cmd.Parameters.Add("$eventId", Microsoft.Data.Sqlite.SqliteType.Text);
            foreach (var id in eventIds)
            {
                eventIdParam.Value = id;
                cmd.ExecuteNonQuery();
            }
            transaction.Commit();
        }
    }

    public void Dispose() => _connection.Dispose();
}

/// <summary>
/// The shape EventSyncClient sends on the wire to POST
/// /api/events/ingest — field names are camelCase there (JSON) even
/// though this record's C# property names are PascalCase; the JSON
/// serialization step in EventSyncClient is what bridges that, not a
/// coincidence of naming.
/// </summary>
public sealed record SyncableEvent(
    string EventId,
    string EmployeeId,
    string DeviceId,
    string? SessionId,
    string Type,
    string TimestampUtc,
    string Source,
    bool IsEstimated,
    string PayloadJson
);

public sealed record RawEvent(
    string EventId,
    string EmployeeId,
    string DeviceId,
    string? SessionId,
    string EventType,
    string TimestampUtc,
    string Source,
    bool IsEstimated,
    string PayloadJson
);
