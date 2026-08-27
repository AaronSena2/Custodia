/**
 * Small shared helpers used by every page's inline <script> block. No
 * framework, no build step — plain fetch() against actions/*.php, matching
 * the "simpler stack" goal of this rebuild.
 */

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
