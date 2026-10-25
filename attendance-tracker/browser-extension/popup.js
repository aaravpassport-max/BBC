const STATUS_TEXT = {
  connected: "Connected — reporting active tab",
  connecting: "Checking connection…",
  agent_unreachable: "Agent not reachable (is the tracking agent running and has the on-device notice been acknowledged?)",
};

function render(status) {
  const box = document.getElementById("statusBox");
  const text = document.getElementById("statusText");
  box.className = "status " + (STATUS_TEXT[status] ? status : "connecting");
  text.textContent = STATUS_TEXT[status] ?? STATUS_TEXT.connecting;
}

chrome.runtime.sendMessage({ type: "get-status" }, (response) => {
  render(response?.connectionStatus ?? "connecting");
});
