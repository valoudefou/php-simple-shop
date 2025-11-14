<?php
$flagshipLogs = $_SESSION['flagship_logs'] ?? [];
$logJson = json_encode(
    $flagshipLogs,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>
<div
  id="log-panel"
  class="log-panel"
  aria-live="polite"
  aria-hidden="true"
>
  <div class="log-panel__header">
    <strong>AB Tasty Logs</strong>
    <div class="log-panel__actions">
      <input
        type="search"
        class="log-panel__search"
        placeholder="Search logs…"
        aria-label="Filter logs"
        data-log-search
      />
      <button type="button" class="ghost-btn log-panel__close" data-log-close>
        Close
      </button>
    </div>
  </div>
  <div class="log-panel__body" data-log-list>
    <p class="muted">No Flagship logs yet.</p>
  </div>
  <button type="button" class="log-panel__floating" data-log-toggle>
    LOGS · PRESS L
  </button>
</div>
<script>
  window.__FLAGSHIP_LOGS__ = <?= $logJson ?: '[]' ?>;
  (function () {
    const logs = (window.__FLAGSHIP_LOGS__ || []).slice().reverse();
    const panel = document.getElementById('log-panel');
    if (!panel) {
      return;
    }
    const listEl = panel.querySelector('[data-log-list]');
    const searchEl = panel.querySelector('[data-log-search]');
    const closeBtn = panel.querySelector('[data-log-close]');
    const toggleBtn = panel.querySelector('[data-log-toggle]');

    const escapeHtml = (value = '') =>
      String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const render = (term = '') => {
      const query = term.trim().toLowerCase();
      const filtered = logs.filter((entry) => {
        const haystack = [
          entry.timestamp,
          entry.level,
          entry.message,
          JSON.stringify(entry.context || {})
        ]
          .join(' ')
          .toLowerCase();
        return haystack.includes(query);
      });

      if (!filtered.length) {
        listEl.innerHTML = '<p class="muted">No logs match that search.</p>';
        return;
      }

      listEl.innerHTML = filtered
        .map(
          (entry) => `
          <article class="log-entry">
            <header>
              <span class="log-entry__level">${escapeHtml(entry.level)}</span>
              <time>${escapeHtml(entry.timestamp)}</time>
            </header>
            <p>${escapeHtml(entry.message)}</p>
            ${
              entry.context && Object.keys(entry.context).length
                ? `<pre>${escapeHtml(JSON.stringify(entry.context, null, 2))}</pre>`
                : ''
            }
          </article>`
        )
        .join('');
    };

    const togglePanel = (force) => {
      const shouldOpen =
        typeof force === 'boolean'
          ? force
          : !panel.classList.contains('is-open');
      panel.classList.toggle('is-open', shouldOpen);
      panel.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
      if (shouldOpen) {
        searchEl?.focus();
      }
    };

    document.addEventListener('keydown', (event) => {
      if (event.key.toLowerCase() !== 'l') {
        return;
      }
      if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
      }
      const tag = (event.target?.tagName || '').toLowerCase();
      if (['input', 'textarea'].includes(tag)) {
        event.preventDefault();
      }
      togglePanel();
    });

    searchEl?.addEventListener('input', (event) => {
      render(event.target.value);
    });

    closeBtn?.addEventListener('click', () => togglePanel(false));
    toggleBtn?.addEventListener('click', () => togglePanel());

    render('');
  })();
</script>
