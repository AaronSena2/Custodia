<?php
require_once __DIR__ . '/chatbot.php';
$chatbotChips = custodia_chatbot_suggested_chips($pdo, $user);
?>
  </div>
</div>

<button type="button" class="chatbot-fab" id="chatbotFab" aria-label="Open guide chat" aria-expanded="false" aria-controls="chatbotPanel">
  <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg>
</button>

<div class="chatbot-panel d-none" id="chatbotPanel" role="dialog" aria-label="Custodia Guide chat">
  <div class="chatbot-panel-header">
    <span>Custodia Guide</span>
    <button type="button" class="chatbot-close" id="chatbotClose" aria-label="Close chat">&times;</button>
  </div>
  <div class="chatbot-messages" id="chatbotMessages"></div>
  <div class="chatbot-chips" id="chatbotChips"></div>
  <form id="chatbotForm" class="chatbot-input-row">
    <input type="text" id="chatbotInput" maxlength="300" autocomplete="off" placeholder="Ask a question…" aria-label="Type a question">
    <button type="submit" class="chatbot-send" aria-label="Send">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
    </button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// .app-sidebar sets overflow-y: auto — per the CSS spec that silently forces
// its overflow-x to auto too (you can't scroll one axis and stay "visible"
// on the other), so a dropdown-menu wider than the 260px sidebar was getting
// clipped/scrolled inside the sidebar instead of floating over the page.
// Forcing Popper's positioning strategy to "fixed" (viewport-relative, not
// relative to the scrolling sidebar) escapes that clipping entirely — sticky
// positioning on .app-sidebar doesn't establish a containing block for
// fixed-position descendants, so this is a real fix, not a workaround.
const notifBellToggle = document.getElementById('notificationBellToggle');
if (notifBellToggle) {
  bootstrap.Dropdown.getOrCreateInstance(notifBellToggle, { popperConfig: { strategy: 'fixed' } });
}
</script>
<script>
const CUSTODIA_CHATBOT_USER_ID = <?= json_encode((string) $user['id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const CUSTODIA_CHATBOT_DEFAULT_CHIPS = <?= json_encode($chatbotChips, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

(function () {
  const STORAGE_HISTORY_KEY = 'custodiaChatbot_' + CUSTODIA_CHATBOT_USER_ID + '_history';
  const STORAGE_OPEN_KEY = 'custodiaChatbot_' + CUSTODIA_CHATBOT_USER_ID + '_open';
  const MAX_HISTORY = 20;

  const fab = document.getElementById('chatbotFab');
  const panel = document.getElementById('chatbotPanel');
  const closeBtn = document.getElementById('chatbotClose');
  const messagesEl = document.getElementById('chatbotMessages');
  const chipsEl = document.getElementById('chatbotChips');
  const form = document.getElementById('chatbotForm');
  const input = document.getElementById('chatbotInput');

  function loadHistory() {
    try {
      const raw = sessionStorage.getItem(STORAGE_HISTORY_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) { return []; }
  }

  function saveHistory() {
    try { sessionStorage.setItem(STORAGE_HISTORY_KEY, JSON.stringify(history.slice(-MAX_HISTORY))); }
    catch (e) { /* storage unavailable (private mode etc.) — chat still works, just won't persist */ }
  }

  let history = loadHistory();

  // Every message is built via DOM APIs (createElement/textContent), never
  // innerHTML — sidesteps escaping entirely rather than relying on
  // custodiaEscapeHtml() being called correctly at every insertion point,
  // since one of these bubbles echoes arbitrary user-typed text.
  function buildBubble(msg) {
    const bubble = document.createElement('div');
    bubble.className = 'chatbot-bubble chatbot-bubble-' + msg.role + (msg.error ? ' chatbot-bubble-error' : '');
    const text = document.createElement('div');
    text.textContent = msg.text;
    bubble.appendChild(text);
    if (msg.link) {
      const link = document.createElement('a');
      link.href = msg.link;
      link.className = 'chatbot-bubble-link';
      link.textContent = msg.sectionLabel ? ('Read more: ' + msg.sectionLabel + ' →') : 'Open the User Manual →';
      bubble.appendChild(link);
    }
    return bubble;
  }

  function renderMessages() {
    messagesEl.innerHTML = '';
    if (history.length === 0) {
      const greeting = document.createElement('div');
      greeting.className = 'chatbot-bubble chatbot-bubble-bot';
      const text = document.createElement('div');
      text.textContent = 'Hi — I can help you find your way around Custodia. Ask a question, or try one of these:';
      greeting.appendChild(text);
      messagesEl.appendChild(greeting);
    } else {
      history.forEach(msg => messagesEl.appendChild(buildBubble(msg)));
    }
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function renderChips(chips) {
    chipsEl.innerHTML = '';
    (chips || []).forEach(chip => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'chatbot-chip';
      btn.textContent = chip.question;
      btn.addEventListener('click', () => sendMessage(chip.question));
      chipsEl.appendChild(btn);
    });
  }

  async function sendMessage(text) {
    text = text.trim();
    if (!text) return;

    history.push({ role: 'user', text });
    renderMessages();
    saveHistory();

    try {
      const data = await custodiaPost('actions/chatbot_query.php', { message: text });
      history.push({ role: 'bot', text: data.answer, link: data.link, sectionLabel: data.sectionLabel, followUps: data.followUps });
      renderMessages();
      saveHistory();
      renderChips(data.followUps && data.followUps.length ? data.followUps : CUSTODIA_CHATBOT_DEFAULT_CHIPS);
    } catch (err) {
      history.push({ role: 'bot', text: err.message || 'Something went wrong — please try again.', error: true });
      renderMessages();
      saveHistory();
    }
  }

  function openPanel() {
    panel.classList.remove('d-none');
    fab.setAttribute('aria-expanded', 'true');
    try { sessionStorage.setItem(STORAGE_OPEN_KEY, '1'); } catch (e) {}
    input.focus();
  }

  function closePanel() {
    panel.classList.add('d-none');
    fab.setAttribute('aria-expanded', 'false');
    try { sessionStorage.setItem(STORAGE_OPEN_KEY, '0'); } catch (e) {}
  }

  fab.addEventListener('click', () => {
    panel.classList.contains('d-none') ? openPanel() : closePanel();
  });
  closeBtn.addEventListener('click', closePanel);
  document.addEventListener('keydown', evt => {
    if (evt.key === 'Escape' && !panel.classList.contains('d-none')) closePanel();
  });

  form.addEventListener('submit', evt => {
    evt.preventDefault();
    const text = input.value;
    input.value = '';
    sendMessage(text);
  });

  renderMessages();
  renderChips(history.length === 0 ? CUSTODIA_CHATBOT_DEFAULT_CHIPS
    : (history[history.length - 1].followUps || CUSTODIA_CHATBOT_DEFAULT_CHIPS));

  let wasOpen = false;
  try { wasOpen = sessionStorage.getItem(STORAGE_OPEN_KEY) === '1'; } catch (e) {}
  if (wasOpen) openPanel();
})();
</script>
</body>
</html>
