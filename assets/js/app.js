/**
 * Small shared helpers used by every page's inline <script> block. No
 * framework, no build step — plain fetch() against actions/*.php, matching
 * the "simpler stack" goal of this rebuild.
 */

/**
 * Applies the saved theme (if any) immediately, before <body> renders — this
 * file is loaded as a blocking <script> in <head> (see includes/
 * layout_header.php's comment on why), so this runs before first paint and
 * there's no flash of the wrong theme. Bootstrap 5.3's own data-bs-theme
 * attribute drives it; assets/css/app.css's [data-bs-theme="dark"] block
 * redefines the --cus-* tokens the rest of the app's CSS already reads.
 */
(function () {
  try {
    if (localStorage.getItem('custodia-theme') === 'dark') {
      document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
  } catch (e) {} // localStorage unavailable (private mode, etc.) — just stay on the light default
})();

function custodiaToggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  try { localStorage.setItem('custodia-theme', next); } catch (e) {}
}

/**
 * Same "apply saved preference before first paint" trick as the theme IIFE
 * above — sets data-notif-sound="muted" on <html> immediately so the bell
 * dropdown's speaker icon (see assets/css/app.css) never flashes the wrong
 * state.
 */
(function () {
  try {
    if (localStorage.getItem('custodia-notification-sound') === 'muted') {
      document.documentElement.setAttribute('data-notif-sound', 'muted');
    }
  } catch (e) {}
})();

function custodiaNotificationSoundMuted() {
  return document.documentElement.getAttribute('data-notif-sound') === 'muted';
}

function custodiaToggleNotificationSound() {
  const html = document.documentElement;
  const muting = !custodiaNotificationSoundMuted();
  if (muting) {
    html.setAttribute('data-notif-sound', 'muted');
  } else {
    html.removeAttribute('data-notif-sound');
    custodiaWakeAudioContext();
    custodiaPlayNotificationSound(); // immediate feedback that sound is back on
  }
  try { localStorage.setItem('custodia-notification-sound', muting ? 'muted' : 'on'); } catch (e) {}
}

/**
 * A short two-note chime, synthesized with the Web Audio API instead of an
 * mp3/wav asset — no file to ship, no licensing to track down, consistent
 * with this app's "no external assets, no build step" approach elsewhere
 * (see the inline nav SVGs in includes/layout_header.php). Used by
 * custodiaPollNotifications() (includes/layout_header.php) whenever a poll
 * finds the unread count went up.
 *
 * Browsers refuse to start audio before the page has seen a user gesture,
 * so the AudioContext is created lazily on the page's first click/keydown
 * (custodiaWakeAudioContext below) rather than at load time; if no gesture
 * has happened yet, custodiaPlayNotificationSound() just quietly no-ops.
 */
let custodiaAudioCtx = null;
function custodiaWakeAudioContext() {
  const Ctor = window.AudioContext || window.webkitAudioContext;
  if (!Ctor) return;
  if (!custodiaAudioCtx) {
    custodiaAudioCtx = new Ctor();
  } else if (custodiaAudioCtx.state === 'suspended') {
    custodiaAudioCtx.resume().catch(() => {});
  }
}
document.addEventListener('click', custodiaWakeAudioContext);
document.addEventListener('keydown', custodiaWakeAudioContext);

function custodiaPlayNotificationSound() {
  if (custodiaNotificationSoundMuted() || !custodiaAudioCtx || custodiaAudioCtx.state === 'suspended') return;
  const ctx = custodiaAudioCtx;
  const now = ctx.currentTime;
  [[880, 0], [1318.51, 0.11]].forEach(([freq, delay]) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0, now + delay);
    gain.gain.linearRampToValueAtTime(0.18, now + delay + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.28);
    osc.connect(gain).connect(ctx.destination);
    osc.start(now + delay);
    osc.stop(now + delay + 0.3);
  });
}

function custodiaCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

/**
 * Escapes text for safe insertion into innerHTML — used wherever a page
 * builds an HTML string from server data that ultimately came from user
 * input (e.g. a name/reason from an uploaded bulk-import CSV) rather than
 * setting it via textContent. Handles null/undefined by returning ''.
 */
function custodiaEscapeHtml(value) {
  if (value === null || value === undefined) return '';
  const div = document.createElement('div');
  div.textContent = String(value);
  return div.innerHTML;
}

/**
 * Navigates to the current URL with one query param changed (or removed, if
 * `value` is empty) — used by the page-size <select> on list pages (see
 * includes/listing.php's custodia_pagination_bar()). Resets `page` back to 1
 * whenever anything other than `page` itself changes, since a stale page
 * number rarely still makes sense under a new page size.
 */
function custodiaSetQueryParam(name, value) {
  const url = new URL(window.location.href);
  if (value === '' || value === null || value === undefined) {
    url.searchParams.delete(name);
  } else {
    url.searchParams.set(name, value);
  }
  if (name !== 'page') {
    url.searchParams.set('page', '1');
  }
  window.location.href = url.toString();
}

/**
 * POSTs `data` (a plain object) to `url` as application/x-www-form-urlencoded,
 * automatically attaching the CSRF token. Returns the parsed JSON body.
 * Throws an Error with the server's {"error": "..."} message on failure.
 */
async function custodiaPost(url, data) {
  const body = new URLSearchParams({ ...data, csrf_token: custodiaCsrfToken() });
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  const json = await res.json().catch(() => ({ error: `Unexpected response (HTTP ${res.status}).` }));
  if (!res.ok || json.error) {
    throw new Error(json.error || `Request failed (HTTP ${res.status}).`);
  }
  return json.data;
}

/** Like custodiaPost but for <form enctype="multipart/form-data"> (file uploads) — sends FormData as-is. */
async function custodiaPostMultipart(url, formData) {
  formData.set('csrf_token', custodiaCsrfToken());
  const res = await fetch(url, { method: 'POST', body: formData });
  const json = await res.json().catch(() => ({ error: `Unexpected response (HTTP ${res.status}).` }));
  if (!res.ok || json.error) {
    throw new Error(json.error || `Request failed (HTTP ${res.status}).`);
  }
  return json.data;
}

async function custodiaGet(url) {
  const res = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
  const json = await res.json().catch(() => ({ error: `Unexpected response (HTTP ${res.status}).` }));
  if (!res.ok || json.error) {
    throw new Error(json.error || `Request failed (HTTP ${res.status}).`);
  }
  return json.data;
}

/**
 * Shows a dismissible Bootstrap alert at the top of the page's content area,
 * above any existing content. Targets .app-content (the actual wrapper in
 * includes/layout_header.php — there is no <main> element in this app's
 * markup, despite what an older version of this comment claimed) with a
 * couple of defensive fallbacks so a future layout change can't silently
 * turn every success message into a crash again.
 */
function custodiaFlash(message, type = 'success') {
  const container = document.querySelector('.app-content') || document.querySelector('main') || document.body;
  const div = document.createElement('div');
  div.className = `alert alert-${type} alert-dismissible fade show`;
  div.setAttribute('role', 'alert');
  div.textContent = message;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-close';
  btn.setAttribute('data-bs-dismiss', 'alert');
  div.appendChild(btn);
  container.prepend(div);
}

/**
 * Wires a <form data-action-url="..."> to POST via custodiaPost() on submit,
 * disabling its submit button meanwhile and closing the nearest .modal on
 * success. `onSuccess(data)` is called with the action's returned payload.
 */
function custodiaWireActionForm(form, onSuccess) {
  form.addEventListener('submit', async (evt) => {
    evt.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const original = submitBtn ? submitBtn.textContent : null;
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Working…';
    }
    const errorBox = form.querySelector('.form-error');
    if (errorBox) errorBox.classList.add('d-none');

    try {
      const formData = new FormData(form);
      const data = Object.fromEntries(formData.entries());
      const result = await custodiaPost(form.getAttribute('data-action-url'), data);
      const modalEl = form.closest('.modal');
      if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.hide();
      }
      if (onSuccess) onSuccess(result);
    } catch (err) {
      if (errorBox) {
        errorBox.textContent = err.message;
        errorBox.classList.remove('d-none');
      } else {
        custodiaFlash(err.message, 'danger');
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = original;
      }
    }
  });
}
