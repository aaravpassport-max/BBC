using Microsoft.Data.Sqlite;

namespace AttendanceAgent;

/// <summary>
/// Creates the agent-local SQLite schema (same tables as core migration 001 + 006).
/// The Windows agent uses its own .db file and must bootstrap on first run.
/// </summary>
internal static class AgentDatabaseBootstrap
{
    public static void EnsureSchema(SqliteConnection connection)
    {
        foreach (var statement in SplitStatements(InitSql))
        {
            if (string.IsNullOrWhiteSpace(statement)) continue;
            using var cmd = connection.CreateCommand();
            cmd.CommandText = statement;
            cmd.ExecuteNonQuery();
        }

        if (!ColumnExists(connection, "raw_events", "synced_at_utc"))
        {
            using var alter = connection.CreateCommand();
            alter.CommandText = "ALTER TABLE raw_events ADD COLUMN synced_at_utc TEXT;";
            alter.ExecuteNonQuery();
        }

        StampMigration(connection, 1);
        StampMigration(connection, 6);
    }

    public static void EnsureEmployeeAndDevice(SqliteConnection connection, string employeeId, string deviceId)
    {
        var now = DateTime.UtcNow.ToString("o");
        using var emp = connection.CreateCommand();
        emp.CommandText = @"
            INSERT INTO employees (employee_id, display_name, created_at_utc, updated_at_utc)
            VALUES ($id, $name, $now, $now)
            ON CONFLICT(employee_id) DO NOTHING";
        emp.Parameters.AddWithValue("$id", employeeId);
        emp.Parameters.AddWithValue("$name", employeeId);
        emp.Parameters.AddWithValue("$now", now);
        emp.ExecuteNonQuery();

        using var dev = connection.CreateCommand();
        dev.CommandText = @"
            INSERT INTO devices (device_id, hostname, created_at_utc)
            VALUES ($id, $host, $now)
            ON CONFLICT(device_id) DO NOTHING";
        dev.Parameters.AddWithValue("$id", deviceId);
        dev.Parameters.AddWithValue("$host", Environment.MachineName);
        dev.Parameters.AddWithValue("$now", now);
        dev.ExecuteNonQuery();
    }

    private static void StampMigration(SqliteConnection connection, int version)
    {
        using var cmd = connection.CreateCommand();
        cmd.CommandText = @"
            INSERT INTO schema_migrations (version, applied_at_utc)
            VALUES ($v, $at)
            ON CONFLICT(version) DO NOTHING";
        cmd.Parameters.AddWithValue("$v", version);
        cmd.Parameters.AddWithValue("$at", DateTime.UtcNow.ToString("o"));
        cmd.ExecuteNonQuery();
    }

    private static bool ColumnExists(SqliteConnection connection, string table, string column)
    {
        using var cmd = connection.CreateCommand();
        cmd.CommandText = $"PRAGMA table_info({table});";
        using var reader = cmd.ExecuteReader();
        while (reader.Read())
        {
            if (string.Equals(reader.GetString(1), column, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }

    private static IEnumerable<string> SplitStatements(string sql)
    {
        foreach (var part in sql.Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            var trimmed = part.Trim();
            if (trimmed.Length == 0 || trimmed.StartsWith("--", StringComparison.Ordinal)) continue;
            yield return trimmed;
        }
    }

    // Subset of core/src/db/migrations/001_init.sql — tables the agent reads/writes.
    private const string InitSql = """
        CREATE TABLE IF NOT EXISTS schema_migrations (
          version INTEGER PRIMARY KEY,
          applied_at_utc TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS employees (
          employee_id TEXT PRIMARY KEY,
          display_name TEXT NOT NULL,
          employee_code TEXT,
          department TEXT,
          designation TEXT,
          time_zone TEXT NOT NULL DEFAULT 'Asia/Kolkata',
          monitoring_status TEXT NOT NULL DEFAULT 'active',
          created_at_utc TEXT NOT NULL,
          updated_at_utc TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS devices (
          device_id TEXT PRIMARY KEY,
          hostname TEXT,
          os_version TEXT,
          agent_version TEXT,
          created_at_utc TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS notice_acknowledgements (
          employee_id TEXT NOT NULL,
          device_id TEXT NOT NULL,
          policy_version TEXT NOT NULL,
          first_shown_at_utc TEXT NOT NULL,
          acknowledged_at_utc TEXT,
          PRIMARY KEY (employee_id, device_id),
          FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
          FOREIGN KEY (device_id) REFERENCES devices(device_id)
        );

        CREATE TABLE IF NOT EXISTS attendance_sessions (
          session_id TEXT PRIMARY KEY,
          employee_id TEXT NOT NULL,
          device_id TEXT NOT NULL,
          start_utc TEXT NOT NULL,
          end_utc TEXT,
          end_reason TEXT,
          is_end_estimated INTEGER NOT NULL DEFAULT 0
        );

        CREATE INDEX IF NOT EXISTS idx_attendance_sessions_employee ON attendance_sessions(employee_id, start_utc);

        CREATE TABLE IF NOT EXISTS raw_events (
          event_id TEXT PRIMARY KEY,
          employee_id TEXT NOT NULL,
          device_id TEXT NOT NULL,
          session_id TEXT,
          event_type TEXT NOT NULL,
          timestamp_utc TEXT NOT NULL,
          source TEXT NOT NULL,
          is_estimated INTEGER NOT NULL DEFAULT 0,
          payload_json TEXT NOT NULL DEFAULT '{}',
          ingested_at_utc TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_raw_events_employee_time ON raw_events(employee_id, timestamp_utc);
        """;
}
