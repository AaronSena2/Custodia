<?php
/** Small formatting/display helpers, mirroring src/lib/format.ts from the earlier frontend. */

/**
 * Cache-busting query value for a local static asset (assets/css/app.css,
 * assets/js/app.js) — the file's own mtime, so every edit automatically
 * produces a new URL the browser has never cached, without a build step or
 * manually bumping a version number. Apache doesn't send any Cache-Control
 * header for static files here, so without this, a browser that already
 * loaded the old file can keep serving it from cache long after a fix is
 * deployed, looking exactly like the fix didn't take effect.
 */
function custodia_asset_version(string $relativePath): string
{
    $fullPath = __DIR__ . '/../' . $relativePath;
    return is_file($fullPath) ? (string) filemtime($fullPath) : '1';
}

function custodia_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return (new DateTimeImmutable($value))->format('M j, Y g:i A');
}

function custodia_format_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return (new DateTimeImmutable($value))->format('M j, Y');
}

function custodia_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    $first = $parts[0][0] ?? '';
    $last = $parts[count($parts) - 1][0] ?? '';
    return strtoupper($first . $last);
}

/** Always returns at least 1 — callers must separately check whether dueBackAt is actually in the past. */
function custodia_days_overdue(string $dueBackAt): int
{
    $diffSeconds = time() - (new DateTimeImmutable($dueBackAt))->getTimestamp();
    return max(1, (int) ceil($diffSeconds / 86400));
}

// Role catalog (custodia_role_label(), custodia_list_roles(), custodia_role_keys())
// moved to includes/roles.php — roles are admin-extensible now, not a fixed list.

const CUSTODIA_STATUS_BADGES = [
    'IN_REGISTRY' => 'success',
    'CHECKED_OUT' => 'info',
    'IN_TRANSIT' => 'warning',
    'OFFSITE_ARCHIVE' => 'secondary',
    'PENDING_DESTRUCTION' => 'danger',
    'DESTROYED' => 'dark',
    'PENDING_APPROVAL' => 'warning',
    'PENDING_CONFIRMATION' => 'info',
    'APPROVED' => 'success',
    'REJECTED' => 'danger',
    'COMPLETED' => 'success',
    'OVERDUE' => 'danger',
    'ACTIVE' => 'success',
    'OPEN' => 'success',
    'ON_HOLD' => 'warning',
    'CLOSED' => 'secondary',
    'ARCHIVED' => 'dark',
    'PENDING' => 'warning',
    'DENIED' => 'danger',
];

/** Display-only overrides for CUSTODIA_STATUS_BADGES — the stored enum value is unchanged, only what's rendered. */
const CUSTODIA_STATUS_LABEL_OVERRIDES = [
    'CHECKED_OUT' => 'Issued',
];

function custodia_status_badge(string $status): string
{
    $variant = CUSTODIA_STATUS_BADGES[$status] ?? 'secondary';
    $label = CUSTODIA_STATUS_LABEL_OVERRIDES[$status] ?? htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $status))));
    return "<span class=\"badge text-bg-{$variant}\">{$label}</span>";
}

/** "Issue"/"Return"/etc. for a custody_movements.movement_type value — used wherever a movement is shown to a user (Physical Files "Last Movement" column, Approvals queue). */
const CUSTODIA_MOVEMENT_TYPE_LABELS = [
    'CHECK_OUT' => 'Issue',
    'CHECK_IN' => 'Return',
    'TRANSFER' => 'Transfer',
    'ARCHIVE' => 'Archive',
    'RECALL' => 'Recall',
];

function custodia_movement_type_label(string $movementType): string
{
    return CUSTODIA_MOVEMENT_TYPE_LABELS[$movementType] ?? ucwords(strtolower(str_replace('_', ' ', $movementType)));
}

function custodia_confidentiality_badge(string $tier): string
{
    $variant = in_array($tier, ['PRIVILEGED', 'RESTRICTED'], true) ? 'purple' : 'secondary';
    return "<span class=\"badge text-bg-{$variant}\">" . htmlspecialchars($tier) . "</span>";
}

/** CSS class per confidentiality tier for custodia_matter_number_chip() below — three distinct colors (unlike custodia_confidentiality_badge()'s two-way STANDARD-vs-not split), since a small chip reads fine at a glance with more granularity than a text badge needs. Colors/tints/dark-mode variants all live in assets/css/app.css. */
const CUSTODIA_CONFIDENTIALITY_ICON_CLASS = [
    'STANDARD' => 'matter-tier-standard',
    'RESTRICTED' => 'matter-tier-restricted',
    'PRIVILEGED' => 'matter-tier-privileged',
];

/**
 * Two-tone filled folder glyph — a back panel at partial opacity plus a
 * solid front pocket, both painted from `currentColor`, so it picks up
 * whatever tier color its wrapping .matter-tier-* class sets without any
 * per-tier markup of its own. Dependency-free inline SVG, matching every
 * other icon in this app (see includes/layout_header.php's CUSTODIA_NAV_ICONS),
 * just filled rather than outlined for a bit more visual weight here.
 */
function custodia_matter_folder_icon(): string
{
    return '<svg class="matter-folder-icon" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">'
        . '<path fill="currentColor" fill-opacity="0.35" d="M2.5 6.3A2 2 0 0 1 4.5 4.3h4.6l2.1 2.3H19.5a2 2 0 0 1 2 2v9.1a2 2 0 0 1-2 2h-15a2 2 0 0 1-2-2Z"/>'
        . '<path fill="currentColor" d="M2.5 9.1h19v8.6a2 2 0 0 1-2 2h-15a2 2 0 0 1-2-2Z"/>'
        . '</svg>';
}

/**
 * Matter number rendered as a small color-coded pill — folder icon + the
 * number itself, tinted background/border/text all keyed to the matter's
 * confidentiality tier (gray/amber/red for STANDARD/RESTRICTED/PRIVILEGED).
 * This is what every render site uses now instead of a bare icon next to
 * plain text; see .matter-number-chip/.matter-tier-* in assets/css/app.css
 * for the actual styling. Falls back to the STANDARD tier's look for any
 * unrecognized confidentiality value rather than failing, since the visual
 * here is decorative — custodia_confidentiality_badge() elsewhere is still
 * the source of truth for the tier's name.
 */
function custodia_matter_number_chip(string $matterNumber, string $confidentiality): string
{
    $class = CUSTODIA_CONFIDENTIALITY_ICON_CLASS[$confidentiality] ?? CUSTODIA_CONFIDENTIALITY_ICON_CLASS['STANDARD'];
    return '<span class="matter-number-chip ' . $class . '">'
        . custodia_matter_folder_icon()
        . '<span class="matter-number-text">' . htmlspecialchars($matterNumber) . '</span>'
        . '</span>';
}

/** Badge for a digital_documents.is_protected row — view permission required, download disabled. */
function custodia_protected_badge(): string
{
    return '<span class="badge text-bg-danger" title="Requires view permission; downloads disabled">🔒 Protected</span>';
}

/** Badge shown to a user who holds this document only via a peer "Share Document" grant — view-only, no download/print/upload/reshare/edit-profile. */
function custodia_shared_view_only_badge(): string
{
    return '<span class="badge text-bg-warning" title="Shared with you as view-only: no download, print, upload, share, or profile edits">👁 Shared (view-only)</span>';
}

/** Color map for the audit log's action-type pills (matches the mockup's per-action coloring). */
const CUSTODIA_ACTION_BADGE_COLORS = [
    'DOWNLOAD' => 'teal',
    'CHECK_OUT' => 'gray',
    'CHECK_IN' => 'green',
    'OVERRIDE_CHECK_IN' => 'amber',
    'ACCESS_APPROVED' => 'purple',
    'ACCESS_DENIED' => 'red',
    'SHARE_LINK_ACCESSED' => 'gray',
    'SHARE_LINK_CREATED' => 'teal',
    'EDIT_CHECKIN' => 'green',
    'CHECKOUT_LOCK' => 'amber',
    'ETHICAL_WALL_BYPASS' => 'red',
    'TRANSFER_APPROVED' => 'gray',
    'TRANSFER_REQUESTED' => 'blue',
    'TRANSFER_INITIATED' => 'blue', // pre-existing audit rows from before transfers became request-based
    'TRANSFER_CONFIRMED' => 'gray', // pre-existing audit rows from the old dual-confirmation flow
    'TRANSFER_REJECTED' => 'red',
    'MOVEMENT_APPROVED' => 'gray',
    'MOVEMENT_REJECTED' => 'red',
    'AUDIT_EXPORT' => 'blue',
    'LOGIN_FAIL' => 'red',
    'VIEW' => 'gray',
    'USER_CREATED' => 'teal',
    'USER_UPDATED' => 'blue',
    'USER_DEACTIVATED' => 'red',
    'USER_REACTIVATED' => 'green',
    'USER_PASSWORD_RESET' => 'amber',
    'PERMISSIONS_UPDATED' => 'blue',
    'ROLE_CREATED' => 'teal',
    'ROLE_DELETED' => 'red',
    'CLIENTS_BULK_IMPORTED' => 'teal',
    'USERS_BULK_IMPORTED' => 'teal',
    'AUDIT_CHAIN_VERIFIED' => 'green',
    'AUDIT_CHAIN_BROKEN' => 'red',
    'OVERDUE_SWEEP_RUN' => 'gray',
    'RETENTION_SWEEP_RUN' => 'gray',
    'RETENTION_POLICY_CREATED' => 'teal',
    // Security review 2026-09-03, finding 2.1 — firm-wide default retention policy.
    'RETENTION_POLICY_DEFAULT_SET' => 'blue',
];

/** Display-only overrides for the audit log's action-type pills — the stored action_type string is unchanged. */
const CUSTODIA_ACTION_BADGE_LABEL_OVERRIDES = [
    'CHECK_OUT' => 'ISSUED',
    'CHECK_OUT_REQUESTED' => 'ISSUE REQUESTED',
    'CHECK_OUT_APPROVED' => 'ISSUE APPROVED',
    'CHECK_IN' => 'RETURNED',
    'OVERRIDE_CHECK_IN' => 'OVERRIDE RETURN',
];

function custodia_action_badge(string $actionType): string
{
    $color = CUSTODIA_ACTION_BADGE_COLORS[$actionType] ?? 'gray';
    $label = htmlspecialchars(CUSTODIA_ACTION_BADGE_LABEL_OVERRIDES[$actionType] ?? $actionType);
    return "<span class=\"action-badge action-badge-{$color}\">{$label}</span>";
}

/** iManage-style permanent document label, e.g. "CUS-000142.3" (doc 142, version 3). */
const CUSTODIA_DOC_PREFIX = 'CUS';

function custodia_doc_label(int $docNumber, ?int $versionNumber = null): string
{
    $label = CUSTODIA_DOC_PREFIX . '-' . str_pad((string) $docNumber, 6, '0', STR_PAD_LEFT);
    return $versionNumber !== null ? "{$label}.{$versionNumber}" : $label;
}

const CUSTODIA_EXTRACTION_STATUS_LABELS = [
    'PENDING' => 'Not indexed yet',
    'DONE' => 'Indexed for search',
    'UNSUPPORTED' => 'File type not indexed',
    'FAILED' => 'No extractable text',
    // Security review 2026-09-03, finding 4.4: distinct from plain FAILED —
    // see CUSTODIA_SCANNABLE_EXTRACTION_EXTENSIONS in text_extract.php.
    'NO_TEXT_LAYER' => 'Scanned — not searchable (needs OCR)',
];

/** Badge color per extraction_status — success is quiet, everything that means "you can't find this by searching" is amber/red and visible, not a same-color muted table cell (finding 4.4: the old plain-text rendering read as invisible). */
const CUSTODIA_EXTRACTION_STATUS_BADGE_COLORS = [
    'PENDING' => 'gray',
    'DONE' => 'green',
    'UNSUPPORTED' => 'gray',
    'FAILED' => 'red',
    'NO_TEXT_LAYER' => 'amber',
];

function custodia_extraction_status_label(string $status): string
{
    return CUSTODIA_EXTRACTION_STATUS_LABELS[$status] ?? $status;
}

/** Visible badge (not plain muted text) for a document_versions.extraction_status value — see custodia_extraction_status_label(). */
function custodia_extraction_status_badge(string $status): string
{
    $color = CUSTODIA_EXTRACTION_STATUS_BADGE_COLORS[$status] ?? 'gray';
    $label = htmlspecialchars(custodia_extraction_status_label($status));
    $title = $status === 'NO_TEXT_LAYER'
        ? ' title="This looks like a scanned page with no text layer, so full-text search won\'t find it. Re-upload a text-based version, or run OCR, to make it searchable."'
        : '';
    return "<span class=\"action-badge action-badge-{$color}\"{$title}>{$label}</span>";
}

/**
 * MIME types safe to render inline in the browser — shared by the document
 * preview modal (documents.php/matter.php) and actions/download_document.php's
 * ?view=1 mode. Deliberately excludes text/html, image/svg+xml, and similar:
 * inline-rendering an uploaded file of those types from the app's own origin
 * would be a stored-XSS vector, so those always fall back to attachment.
 */
const CUSTODIA_PREVIEWABLE_MIME_TYPES = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff',
    'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/webm',
    'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
    'text/plain',
];

function custodia_is_previewable_mime(?string $mimeType): bool
{
    return $mimeType !== null && in_array($mimeType, CUSTODIA_PREVIEWABLE_MIME_TYPES, true);
}

/** "3:24" for under an hour, "1:02:15" once it reaches an hour — standard media-player style. */
function custodia_format_duration(?float $seconds): ?string
{
    if ($seconds === null) {
        return null;
    }
    $totalSeconds = (int) round($seconds);
    $h = intdiv($totalSeconds, 3600);
    $m = intdiv($totalSeconds % 3600, 60);
    $s = $totalSeconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function custodia_flash_set(string $message, string $type = 'success'): void
{
    custodia_start_session();
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function custodia_flash_render(): string
{
    custodia_start_session();
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = $flash['type'] === 'error' ? 'danger' : $flash['type'];
    return '<div class="alert alert-' . e($type) . ' alert-dismissible fade show" role="alert">'
        . e($flash['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}
