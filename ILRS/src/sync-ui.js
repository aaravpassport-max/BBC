// ILRS — Multi-computer sync settings UI (phase 0)
(function () {
  function formatSyncTime(iso) {
    if (!iso) return '—';
    try {
      const d = new Date(iso);
      if (Number.isNaN(d.getTime())) return iso;
      return d.toLocaleString();
    } catch (_) {
      return iso;
    }
  }

  function syncSettingsCardHtml(s) {
    const enabled = s.sync_enabled === '1';
    const auto = s.sync_auto !== '0';
    const interval = parseInt(s.sync_interval_seconds, 10) || 120;
    const folder = s.sync_folder_path || '';
    const deviceName = s.sync_device_name || '';
    const office = s.sync_office_label || 'Main Office';
    const deviceId = s.sync_device_id || '—';
    const driveBackup = s.sync_drive_backup === '1';
    const backupRetention = parseInt(s.sync_drive_backup_retention, 10) || 14;

    return `
      <div class="card" style="margin-bottom:16px" id="sync-settings-card">
        <div class="settings-section-title">☁️ Multi-computer sync</div>
        <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;line-height:1.45">
          Uses a folder synced by <strong>Google Drive for Desktop</strong> (no API, no server).
          Each PC keeps its own database; changes are exchanged as small event files.
        </p>
        <div id="sync-status-panel" class="sync-status-panel" style="padding:12px;border-radius:8px;background:var(--bg-elevated);margin-bottom:12px;font-size:13px">
          Loading sync status…
        </div>
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Sync enabled</div>
          </div>
          <div class="toggle ${enabled ? 'on' : ''}" id="t-sync-enabled" onclick="this.classList.toggle('on')"></div>
        </div>
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Automatic sync</div>
            <div class="setting-desc">Background sync on an interval</div>
          </div>
          <div class="toggle ${auto ? 'on' : ''}" id="t-sync-auto" onclick="this.classList.toggle('on')"></div>
        </div>
        <div class="setting-row">
          <div class="setting-info"><div class="setting-label">Sync interval (seconds)</div></div>
          <input type="number" class="form-input" style="width:100px" id="s-sync-interval" value="${interval}" min="30" max="3600"/>
        </div>
        <div class="form-group" style="margin:12px 0">
          <label class="form-label">Shared folder (local path)</label>
          <input type="text" class="form-input" id="s-sync-folder" value="${folder.replace(/"/g, '&quot;')}" placeholder="e.g. G:\\My Drive\\ILRS" readonly/>
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-ghost btn-sm" onclick="ILRSSyncUI.pickFolder()">📁 Select folder</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="ILRSSyncUI.verifyFolder()">✓ Verify folder</button>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:12px">
          <label class="form-label">Office / location</label>
          <input type="text" class="form-input" id="s-sync-office" value="${office.replace(/"/g, '&quot;')}" placeholder="Delhi Office"/>
        </div>
        <div class="form-group" style="margin-bottom:12px">
          <label class="form-label">This computer name</label>
          <input type="text" class="form-input" id="s-sync-device-name" value="${deviceName.replace(/"/g, '&quot;')}" placeholder="Reception PC"/>
        </div>
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Device ID</div>
            <div class="setting-desc" style="font-family:var(--font-mono)">${deviceId}</div>
          </div>
        </div>
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Drive backup copies</div>
            <div class="setting-desc">Versioned .db files in ILRS/Backups (separate from change sync)</div>
          </div>
          <div class="toggle ${driveBackup ? 'on' : ''}" id="t-sync-drive-backup" onclick="this.classList.toggle('on')"></div>
        </div>
        <div class="setting-row">
          <div class="setting-info"><div class="setting-label">Keep backups (count)</div></div>
          <input type="number" class="form-input" style="width:80px" id="s-sync-backup-retention" value="${backupRetention}" min="3" max="60"/>
        </div>
        <div id="sync-conflicts-panel" style="margin-top:12px;font-size:12px;color:var(--text-secondary)"></div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-top:12px">
          <button type="button" class="btn btn-primary btn-sm" onclick="ILRSSyncUI.syncNow()">↻ Sync now</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="ILRSSyncUI.runDriveBackup()">🗄️ Backup to Drive folder</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="ILRSSyncUI.publishBootstrap()">📦 Publish bootstrap snapshot</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="ILRSSyncUI.resetState()">Reset local sync state</button>
        </div>
      </div>
    `;
  }

  function renderStatusPanel(status) {
    const el = document.getElementById('sync-status-panel');
    if (!el || !status) return;

    const dot = status.online ? '🟢' : (status.settings?.folder_path ? '🟡' : '⚪');
    const last = formatSyncTime(status.settings?.last_run_at);
    const pending = (status.outbox?.pending || 0) + (status.outbox?.failed || 0);
    const incoming = status.inbound_pending_apply || 0;
    const conflicts = status.open_conflicts || 0;
    const err = status.settings?.last_error;

    el.innerHTML = `
      <div style="font-weight:600;margin-bottom:6px">${dot} ${status.status_label || 'Unknown'}</div>
      <div style="color:var(--text-secondary);line-height:1.5">
        Pending upload: <strong>${pending}</strong><br/>
        Waiting on dependencies: <strong>${incoming}</strong><br/>
        Open conflicts: <strong>${conflicts}</strong><br/>
        Last sync: <strong>${last}</strong>
        ${status.sync_root ? `<br/>Sync root: <span style="font-family:var(--font-mono);font-size:11px">${status.sync_root}</span>` : ''}
        ${err ? `<br/><span style="color:var(--critical)">Last error: ${err}</span>` : ''}
      </div>
    `;

    const badge = document.getElementById('sync-topbar-badge');
    if (badge) {
      const attention = pending + incoming + conflicts;
      badge.textContent = attention > 0 ? String(attention) : '';
      badge.style.display = attention > 0 ? 'inline' : 'none';
    }
  }

  async function refreshConflictsPanel() {
    const panel = document.getElementById('sync-conflicts-panel');
    if (!panel) return;
    const res = await window.ilrs?.listSyncConflicts?.();
    const rows = res?.conflicts || [];
    if (!rows.length) {
      panel.innerHTML = '<div style="color:var(--normal)">No open sync conflicts.</div>';
      return;
    }
    panel.innerHTML = `
      <div style="font-weight:600;margin-bottom:6px;color:var(--critical)">Sync conflicts (${rows.length})</div>
      <ul style="margin:0;padding-left:18px;line-height:1.6">
        ${rows.slice(0, 8).map((c) => `
          <li style="margin-bottom:6px">
            <strong>${c.entity}</strong> · <span style="font-family:var(--font-mono);font-size:11px">${c.record_id}</span>
            <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px">
              <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 6px"
                onclick="ILRSSyncUI.resolveConflict('${c.id}', 'keep_local')">Keep this PC</button>
              <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 6px"
                onclick="ILRSSyncUI.resolveConflict('${c.id}', 'keep_remote')">Use other PC</button>
              <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 6px"
                onclick="ILRSSyncUI.resolveConflict('${c.id}')">Mark resolved</button>
            </div>
          </li>
        `).join('')}
      </ul>
      <p style="margin:8px 0 0;font-size:11px">Choose which copy to keep, or fix the record manually and mark resolved.</p>
    `;
  }

  async function refreshStatus() {
    const res = await window.ilrs?.getSyncStatus?.();
    if (res?.success) renderStatusPanel(res.status);
    await refreshConflictsPanel();
    return res;
  }

  async function pickFolder() {
    const res = await window.ilrs?.selectSyncFolder?.();
    if (res?.canceled) return;
    if (res?.success) {
      const input = document.getElementById('s-sync-folder');
      if (input && res.root) input.value = res.root;
      document.getElementById('t-sync-enabled')?.classList.add('on');
      toast('✅ Sync folder configured');
      await refreshStatus();
    } else {
      toast(res?.error || 'Could not set folder', 'warning');
    }
  }

  async function verifyFolder() {
    const path = document.getElementById('s-sync-folder')?.value;
    const res = await window.ilrs?.verifySyncFolder?.(path);
    if (res?.success) toast('✅ Sync folder is valid and writable');
    else toast(res?.error || 'Folder verification failed', 'warning');
  }

  async function syncNow() {
    const res = await window.ilrs?.syncNow?.();
    if (res?.success) {
      toast(`↻ Sync complete · published ${res.published ?? 0}`);
    } else if (res?.skipped) {
      toast('Sync is disabled', 'warning');
    } else {
      toast(res?.error || 'Sync failed', 'warning');
    }
    await refreshStatus();
  }

  async function resetState() {
    if (!confirm('Reset local sync metadata? Your business data is kept. A backup is created first.')) return;
    const res = await window.ilrs?.resetSyncState?.();
    if (res?.success) {
      toast(res.message || 'Sync state reset');
      await refreshStatus();
    } else {
      toast(res?.error || 'Reset failed', 'warning');
    }
  }

  async function resolveConflict(conflictId, strategy) {
    const res = await window.ilrs?.resolveSyncConflict?.(conflictId, strategy);
    if (res?.success) {
      const label = strategy === 'keep_local' ? 'Kept this PC copy'
        : strategy === 'keep_remote' ? 'Applied other PC copy'
          : 'Conflict marked resolved';
      toast(label);
      await refreshStatus();
    } else {
      toast(res?.error || 'Could not resolve', 'warning');
    }
  }

  async function publishBootstrap() {
    const res = await window.ilrs?.publishBootstrapSnapshot?.();
    if (res?.success) {
      toast('Bootstrap snapshot saved for new computers');
    } else {
      toast(res?.error || 'Could not publish snapshot', 'warning');
    }
  }

  async function runDriveBackup() {
    const res = await window.ilrs?.runDriveBackup?.();
    if (res?.success) {
      toast(res.warning ? 'Drive backup saved (sync not fully settled)' : 'Drive backup saved');
    } else if (res?.skipped) {
      toast('Enable Drive backup and set a sync folder first', 'warning');
    } else {
      toast(res?.error || 'Backup failed', 'warning');
    }
  }

  function collectSettingsFromForm() {
    return {
      enabled: document.getElementById('t-sync-enabled')?.classList.contains('on'),
      auto: document.getElementById('t-sync-auto')?.classList.contains('on'),
      interval_seconds: document.getElementById('s-sync-interval')?.value || '120',
      device_name: document.getElementById('s-sync-device-name')?.value?.trim(),
      office_label: document.getElementById('s-sync-office')?.value?.trim(),
      drive_backup: document.getElementById('t-sync-drive-backup')?.classList.contains('on'),
      drive_backup_retention: document.getElementById('s-sync-backup-retention')?.value || '14',
    };
  }

  async function saveFromSettingsPage() {
    const payload = collectSettingsFromForm();
    return window.ilrs?.saveSyncSettings?.(payload);
  }

  async function refreshTopbarBadge() {
    const res = await window.ilrs?.getSyncStatus?.();
    if (res?.success) renderStatusPanel(res.status);
  }

  window.ILRSSyncUI = {
    syncSettingsCardHtml,
    refreshStatus,
    pickFolder,
    verifyFolder,
    syncNow,
    resetState,
    resolveConflict,
    runDriveBackup,
    publishBootstrap,
    collectSettingsFromForm,
    saveFromSettingsPage,
    refreshTopbarBadge,
  };
})();
