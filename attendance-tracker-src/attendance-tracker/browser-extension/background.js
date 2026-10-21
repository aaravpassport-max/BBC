/**
 * Background service worker. Emits exactly the event vocabulary
 * core/src/domain/events.ts and the usage-reconciler (core/src/engine/
 * usage-reconciler.ts, proven by its own executed test suite) expect:
 * browser.tab_active_start, browser.tab_active_end, browser.navigation.
 *
 * ARCHITECTURE: this file does NOT talk to the agent directly — Manifest
 * V3 extensions have no filesystem or raw-socket access. It relays
 * through chrome.runtime.connectNative(), which Chrome fulfils by
 * spawning AttendanceAgentNativeHost.exe (../native-host), which in turn
 * forwards to the main agent's named pipe. See
 * browser-extension/README.md for what is and isn't verified about this
 * chain, and why it's built this way rather than as a loopback HTTP call.
 *
 * MV3 SERVICE WORKER LIFETIME: this script can be unloaded and restarted
 * by the browser at any time between events — it must not rely on
 * in-memory state surviving between calls. State that needs to survive is
 * kept in chrome.storage.session (cleared on browser restart, which is
 * the correct lifetime here: a stale "active tab" from a previous browser
 * session is not a fact worth carrying forward).
 */

const NATIVE_HOST = "com.attendancetracker.agent";

let port = null;
let connectionStatus = "connecting"; // "connecting" | "connected" | "agent_unreachable"

function ensurePort() {
  if (port) return port;
  try {
    port = chrome.runtime.connectNative(NATIVE_HOST);
  } catch (err) {
    connectionStatus = "agent_unreachable";
    port = null;
    return null;
  }
  port.onMessage.addListener((msg) => {
    if (msg && typeof msg.status === "string") {
      connectionStatus = msg.status;
      chrome.storage.session.set({ connectionStatus }).catch(() => {});
    }
  });
  port.onDisconnect.addListener(() => {
    // chrome.runtime.lastError is expected here (e.g. "Native host has
    // exited") and is not logged as an error — a disconnected native
    // host is a normal, recoverable state (the agent isn't running right
    // now, or the employee hasn't acknowledged the notice screen yet),
    // not an extension bug.
    port = null;
    connectionStatus = "agent_unreachable";
    chrome.storage.session.set({ connectionStatus }).catch(() => {});
  });
  connectionStatus = "connecting";
  return port;
}

function send(eventType, timestampUtc, payload) {
  const p = ensurePort();
  if (!p) return;
  try {
    p.postMessage({ type: eventType, timestampUtc, payload });
  } catch {
    // postMessage throws if the port already disconnected between
    // ensurePort() returning it and this call — treat the same as any
    // other unreachable-host case rather than crashing the service
    // worker over one dropped event.
    port = null;
    connectionStatus = "agent_unreachable";
  }
}

/** Only http/https pages are reported — browser-internal pages
 * (chrome://, about:, file://, the extension's own pages) are
 * deliberately out of scope for "which websites were visited". */
function trackableUrl(url) {
  if (!url) return false;
  try {
    const u = new URL(url);
    return u.protocol === "http:" || u.protocol === "https:";
  } catch {
    return false;
  }
}

function domainOf(url) {
  try { return new URL(url).hostname; } catch { return ""; }
}

async function getActiveTabState() {
  return (await chrome.storage.session.get("activeTab")).activeTab ?? null;
  // Shape: { tabId, browserName: "chrome", tabIdStr, url, domain } | null
}

async function setActiveTabState(state) {
  await chrome.storage.session.set({ activeTab: state });
}

function nowUtc() {
  return new Date().toISOString();
}

async function endCurrentActiveTab() {
  const current = await getActiveTabState();
  if (!current) return;
  send("browser.tab_active_end", nowUtc(), { tabId: current.tabIdStr });
  await setActiveTabState(null);
}

async function startActiveTab(tab) {
  if (!tab || !trackableUrl(tab.url)) {
    // Switched TO a non-trackable page (e.g. a chrome:// settings tab) —
    // still correctly ends whatever WAS active, just doesn't open a new
    // interval for the untrackable one.
    await endCurrentActiveTab();
    return;
  }
  const current = await getActiveTabState();
  const tabIdStr = String(tab.id);
  if (current && current.tabIdStr === tabIdStr) {
    return; // already the active tab — e.g. a title-only update firing onActivated-adjacent noise
  }
  if (current) {
    send("browser.tab_active_end", nowUtc(), { tabId: current.tabIdStr });
  }
  const domain = domainOf(tab.url);
  const state = { tabIdStr, browserName: "chrome", url: tab.url, domain };
  await setActiveTabState(state);
  send("browser.tab_active_start", nowUtc(), {
    browserName: "chrome",
    tabId: tabIdStr,
    url: tab.url,
    domain,
    pageTitle: tab.title ?? null,
  });
}

// ---- Event wiring ----

chrome.tabs.onActivated.addListener(async ({ tabId }) => {
  try {
    const tab = await chrome.tabs.get(tabId);
    const win = await chrome.windows.get(tab.windowId);
    if (win.focused) await startActiveTab(tab);
  } catch {
    // Tab closed between the event firing and chrome.tabs.get resolving
    // — nothing to report for a tab that no longer exists.
  }
});

chrome.windows.onFocusChanged.addListener(async (windowId) => {
  if (windowId === chrome.windows.WINDOW_ID_NONE) {
    // Focus left the browser entirely (another application is now
    // foreground). SessionMonitor's own WinEventHook will correctly
    // record THAT app as foreground on the Windows-agent side; this
    // extension's job is only to stop counting the previously-active
    // tab's URL as active browse time while the browser itself isn't
    // even focused.
    await endCurrentActiveTab();
    return;
  }
  try {
    const [activeTab] = await chrome.tabs.query({ active: true, windowId });
    if (activeTab) await startActiveTab(activeTab);
  } catch {
    /* window/tab may have closed mid-query — nothing to report */
  }
});

chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
  if (!changeInfo.url) return; // only genuine navigations, not title/favicon-only updates
  const current = await getActiveTabState();
  const tabIdStr = String(tabId);
  if (!current || current.tabIdStr !== tabIdStr) return; // navigation in a background tab — out of scope, see usage-reconciler.ts
  if (!trackableUrl(changeInfo.url)) {
    // Navigated away from http/https into a non-trackable scheme (rare,
    // but e.g. a download triggering an about:blank) — end cleanly
    // rather than emitting a navigation event the engine wouldn't expect
    // a trackable interval to continue from.
    await endCurrentActiveTab();
    return;
  }
  const domain = domainOf(changeInfo.url);
  await setActiveTabState({ tabIdStr, browserName: "chrome", url: changeInfo.url, domain });
  send("browser.navigation", nowUtc(), {
    browserName: "chrome",
    tabId: tabIdStr,
    url: changeInfo.url,
    domain,
    pageTitle: tab.title ?? null,
  });
});

chrome.tabs.onRemoved.addListener(async (tabId) => {
  const current = await getActiveTabState();
  if (current && current.tabIdStr === String(tabId)) {
    await endCurrentActiveTab();
  }
});

// Service worker can be restarted at any point; re-establish the native
// connection and re-derive current state from the real browser rather
// than assuming anything survived.
chrome.runtime.onStartup.addListener(() => { ensurePort(); });
ensurePort();

// Lets popup.html ask for current connection status without needing its
// own native-messaging connection.
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg?.type === "get-status") {
    sendResponse({ connectionStatus });
    return true;
  }
  return false;
});
