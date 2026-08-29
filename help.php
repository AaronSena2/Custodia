<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matter_access.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/permissions.php';

$user = custodia_require_login();
$pdo = custodia_db();

/** Mirrors the exact tiering in includes/matters.php's custodia_list_matters_for_user() and matter_access.php — kept in sync by hand since it's prose, not logic. */
function custodia_manual_matter_tier(string $role): string
{
    if ($role === 'GUEST_AUDITOR') {
        return 'No matter-level access at all — only whatever a specific share link explicitly grants.';
    }
    if (in_array($role, custodia_firm_wide_roles(), true)) {
        return "Firm-wide — sees every matter that doesn't have an ethical wall against them, regardless of assignment.";
    }
    if ($role === 'PARTNER') {
        return "Matters they manage, matters in their own practice area(s), plus anything they're explicitly assigned to.";
    }
    return "Assigned matters only — sees a matter only after being added to its team, or after an approved access request.";
}

function custodia_manual_role_permissions(PDO $pdo, string $role): array
{
    $stmt = $pdo->prepare('SELECT permission_key FROM role_permissions WHERE role = :role');
    $stmt->execute(['role' => $role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$myRole = $user['role'];
$myRoleLabel = custodia_role_label($pdo, $myRole);
$myTier = custodia_manual_matter_tier($myRole);
$myPermKeys = custodia_manual_role_permissions($pdo, $myRole);

$canSeeAllRoles = custodia_user_has_permission($pdo, $user, 'manage_users');
$allRolesForReference = $canSeeAllRoles ? custodia_list_roles($pdo) : [];

$pageTitle = 'User Manual';
$activeNav = 'help';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">User Manual</div>
    <div class="page-subtitle">How Custodia works, and what your role can do</div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header bg-white fw-semibold">Your Access at a Glance</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-muted small mb-1">Signed in as</div>
        <div class="fw-semibold"><?= e($user['full_name']) ?></div>
        <div class="small text-muted"><?= e($myRoleLabel) ?></div>
      </div>
      <div class="col-md-4">
        <div class="text-muted small mb-1">Matter visibility</div>
        <div class="small"><?= e($myTier) ?></div>
      </div>
      <div class="col-md-4">
        <div class="text-muted small mb-1">Administrative permissions</div>
        <?php if (empty($myPermKeys)): ?>
          <div class="small text-muted">None — standard workflow access only (issuing files, upload, search, etc.), no admin-configurable capabilities.</div>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($myPermKeys as $key): ?>
              <span class="badge text-bg-light border"><?= e(CUSTODIA_PERMISSIONS[$key]['label'] ?? $key) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <p class="text-muted small mt-3 mb-0">
      This panel reflects your role's <em>current</em> settings in Admin → Permissions — it updates automatically
      whenever an administrator changes what your role can do, so it never goes stale.
    </p>
  </div>
</div>

<div class="accordion" id="manualAccordion">

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#secStart">Getting Started</button>
    </h2>
    <div id="secStart" class="accordion-collapse collapse show" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p><strong>Dashboard</strong> is your home screen after signing in: overdue physical files, pending approvals waiting on you, matters pending destruction review, active matters grouped by practice area, and a live analytics section (upload activity, file types, audit activity, and your most-accessed documents — all scoped to what you can see).</p>
        <p><strong>Search</strong> (the search box in the dashboard header, or <code>search.php</code>) does full-text search across document titles, descriptions, and extracted content — including a document's permanent number like <code>142</code> or <code>CUS-000142</code> — scoped to the same matters you can otherwise access.</p>
        <p><strong>Sidebar</strong> links only appear for pages your role can use — Admin only shows up if you have any administrative permission, for instance.</p>
        <p><strong>List pages</strong> (Matters, Clients, User Accounts, a matter's Physical Files/Digital Documents, the Audit Log, and a client's Matters) all share the same controls: a search/filter bar, click-to-sort column headers, and a Per Page selector next to the pager at the bottom — so once you've learned one, you know them all.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secMatters">Matters &amp; Access Control</button>
    </h2>
    <div id="secMatters" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Every matter (a client engagement) is protected by three independent layers, all of which must pass:</p>
        <ol>
          <li><strong>Ethical wall</strong> — a hard, per-user block on a specific matter. If you're walled from a matter, nothing else on this list matters; you can't see it at all, with one exception: a System Administrator can explicitly bypass a wall, which writes its own separately-flagged audit entry every time.</li>
          <li><strong>Confidentiality tier</strong> — Standard matters are visible to anyone who clears the assignment layer below; Restricted and Privileged matters are invisible to everyone except firm-wide roles, the matter's own team, or someone with an approved access request.</li>
          <li><strong>Assignment</strong> — firm-wide roles (System Admin, Records Manager) see everything; a Partner also sees matters in their own practice area; everyone else needs to be added to the matter's team, or have a request approved.</li>
        </ol>
        <p>If you can't see a matter you believe you should have access to, use <strong>"Request Access"</strong> on the matter (where available) — an approver (a firm-wide role, or that matter's own managing partner) will see it on their Approvals page.</p>
        <p>Opening a brand-new matter requires the <strong>Create Matters</strong> permission, and needs a <strong>Client</strong> and <strong>Practice Area</strong> picked from dropdowns rather than typed — both come from their own admin-managed lists (see Clients, below, and Admin → Practice Areas). A Partner creating a matter must name themselves as the managing partner. Editing an existing matter's details afterward is a separate <strong>Edit Matter Details</strong> permission; a one-click <strong>Deactivate/Reactivate Matters</strong> permission lets you close or reopen a matter (sets its Status to Closed/Active) without opening the full edit form.</p>
        <p>A matter's <strong>Team &amp; Access</strong> tab lists who's assigned and lets you configure ethical walls — both require a firm-wide role or (for team membership) being that matter's managing partner. When adding someone, "Role on Matter" is picked from the same role list as Admin → Permissions (including any custom roles), not typed freely.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secClients">Clients</button>
    </h2>
    <div id="secClients" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Every matter belongs to a <strong>Client</strong> — its own record with a name, email, contact, and address, separate from the matter itself. The <strong>Clients</strong> page is a firm-wide directory (client names aren't treated as confidential the way a matter's contents are — Guest/Auditor is the only role that sees none of it); the list itself shows just name and status, click through to a client's profile for its full bio data and the matters under it (filtered to what you're allowed to see, same rules as the main Matters list).</p>
        <p><strong>Create Clients</strong> adds a new client from the directory — a client has to exist here before it can be picked when opening or editing a matter, since that form is a dropdown, not free text. <strong>Edit Clients</strong> updates a client's details; <strong>Deactivate/Reactivate Clients</strong> marks one inactive or active again — purely informational, an inactive client can still be picked for a matter.</p>
        <p><strong>Bulk Import</strong> (next to "+ New Client", same permission) adds many clients at once from a CSV file — one row per client, first row a header naming the columns. A single bad row (missing name, a duplicate) is skipped with a reason rather than failing the whole file; everything else still imports.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secCustody">Physical File Custody</button>
    </h2>
    <div id="secCustody" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Every physical file/box has its own <strong>Physical File Number</strong> (distinct from the matter's own Matter Number — a matter can hold several physical files, e.g. separate volumes). <strong>Scan Station</strong> (<code>scan.php</code>) is the counter for looking one up — scan or type its number, then issue it, return it, or transfer it. The same actions are also available from a matter's Physical Files tab.</p>
        <ul>
          <li><strong>Issue</strong> — if your role has the <strong>Auto-Approved Issue</strong> permission, it completes immediately; otherwise it goes to <em>Pending Approval</em> until someone with the <strong>Approve Custody Movements</strong> permission (or that matter's managing partner) signs off.</li>
          <li><strong>Return</strong> — the current custodian returns a file themselves at any time.</li>
          <li><strong>Request Transfer</strong> — anyone with matter access who isn't already the custodian can ask for a checked-out file. The file stays with its current custodian, unchanged, until that custodian approves the request from their Approvals page — only they can approve or reject it.</li>
          <li><strong>Override Return</strong> — force-returns a file someone else still has issued to them; needs the <strong>Override Return</strong> permission.</li>
        </ul>
        <p>A physical file carries two independent indicators, shown as separate columns/badges: <strong>Custody</strong> (In Registry, Issued, In Transit, Offsite Archive, Pending Destruction, Destroyed — where the file physically is) and <strong>Status</strong> (Open/Closed — whether it's still an active working file). They don't move together — a file can be Issued and Closed at the same time. Toggling Status needs the <strong>Close/Reopen Physical Files</strong> permission and doesn't touch Custody at all.</p>
        <p>A physical file can optionally be linked to a corresponding <strong>digital file</strong> (e.g. a scanned copy) — set from the digital document's own New Document or Edit Profile form, and shown on both the physical file's row and the document's row once linked. It's purely informational; neither side requires the other.</p>
        <p>Registering a brand-new physical file/box and generating its Physical File Number needs the <strong>Register Physical Files</strong> permission, from a matter's Physical Files tab.</p>
        <p>Files overdue for return surface automatically on the Dashboard — there's no separate step to flag them.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secDocs">Digital Documents</button>
    </h2>
    <div id="secDocs" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Every document gets a permanent number the moment it's created (e.g. <code>CUS-000142</code>) that never changes, plus an editable profile (title, description, type, confidentiality, author, and an optional link to a corresponding physical file — see Physical File Custody, above).</p>
        <p><strong>Uploading a version</strong> accepts PDF, Word/Excel/PowerPoint, plain text, images, audio, and video — validated against both extension and actual file content, with per-type size limits (video up to 1GB). Text content (and OCR'd text from images, if Tesseract is installed) is extracted immediately so the document becomes full-text searchable right away.</p>
        <p><strong>Preview</strong> opens PDFs, images, audio, and video right in the browser; <strong>Download</strong> always saves the file. Both respect the same matter-access rules as everything else.</p>
        <p><strong>Lock</strong> reserves a document for editing so two people don't overwrite each other; <strong>Release Lock</strong> is available to the lock holder, or to anyone with the <strong>Override Document Locks</strong> permission.</p>
        <p><strong>Compare</strong> (once a document has 2+ versions) shows a paragraph-and-word-level redline between any two versions.</p>
        <p><strong>Share Link</strong> generates a secure, expiring link for someone outside your normal access path — every open is recorded in the audit trail.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secApprovals">Approvals</button>
    </h2>
    <div id="secApprovals" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Your Approvals page (with a live count badge in the sidebar) has two independent queues:</p>
        <ul>
          <li><strong>Custody Transfers</strong> — transfer requests for files you currently hold custody of (only you can approve or reject these, regardless of role or permissions), plus issue requests waiting on your approval if you hold the <strong>Approve Custody Movements</strong> permission (or manage the matter in question).</li>
          <li><strong>Confidential Access Requests</strong> — requests to access a Restricted/Privileged matter, decided by anyone with the <strong>Decide Access Requests</strong> permission or that matter's managing partner.</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secAudit">Audit Log</button>
    </h2>
    <div id="secAudit" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Every state-changing action in Custodia — file issues, approvals, uploads, permission changes, everything — writes an append-only, hash-chained audit entry that can't be edited after the fact. What you see on <code>audit.php</code> depends on your role: Guest/Auditor sees nothing, Associate/Paralegal see only their own actions, a Partner sees activity on matters they manage, and firm-wide roles see everything.</p>
        <p><strong>Export Report</strong> (needs the <strong>Export Audit Log</strong> permission) downloads the currently filtered view as CSV.</p>
        <p><strong>Verify Chain Integrity</strong> (needs the <strong>Verify Audit Chain</strong> permission) recomputes every entry's hash from the beginning and confirms nothing has been tampered with.</p>
      </div>
    </div>
  </div>

  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secAdmin">Admin</button>
    </h2>
    <div id="secAdmin" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
      <div class="accordion-body">
        <p>Reachable only if you have at least one of the permissions below — each tab below independently checks for its own permission.</p>
        <ul>
          <li><strong>Retention Policies</strong> — needs <strong>Manage Retention Policies</strong>. Configures how long each practice area's matters are kept before review/archive/destruction. This is configuration only — there's no automated nightly sweep yet that actually flags matters once their retention window passes; that's a planned addition, not something this screen does today.</li>
          <li><strong>User Accounts</strong> — needs <strong>Manage User Accounts</strong>. Create accounts, edit profiles and roles, deactivate/reactivate, and reset passwords. There's no self-service "forgot password" — an admin sets it and shares it with you directly. <strong>Bulk Import</strong> creates many accounts at once from a CSV file, generating a temporary password for each — copy them from the results table shown immediately after, they aren't shown again. Every one of these actions is recorded in the audit trail.</li>
          <li><strong>Permissions</strong> — also needs <strong>Manage User Accounts</strong>. A role × capability matrix — toggle which of the <?= count(CUSTODIA_PERMISSIONS) ?> administrative permissions each role has, and create or delete roles beyond the six the app ships with. System Admin can never lose Manage User Accounts here, so this screen can never be locked away from every admin at once. A Partner can always act on matters they personally manage (approving custody requests, deciding access requests) regardless of what's checked here — that's tied to the matter's own managing-partner assignment, not a role-wide privilege, so it isn't a checkbox on this screen. A newly created role always starts with every capability unchecked, and — as covered above — sees only matters it's explicitly assigned to; it can't become "firm-wide" the way System Admin/Records Manager are.</li>
          <li><strong>Practice Areas</strong> — needs <strong>Manage Practice Areas</strong>. Add new practice areas and rename existing ones; every matter, user, and retention policy currently using the old name is updated to match. This is the same list every practice-area dropdown in the app (matters, retention policies, user profiles) draws from.</li>
        </ul>
      </div>
    </div>
  </div>

  <?php if ($canSeeAllRoles): ?>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#secRoles">Role Reference <span class="badge text-bg-light border ms-2">Visible to you because you can Manage User Accounts</span></button>
      </h2>
      <div id="secRoles" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
        <div class="accordion-body">
          <p class="text-muted small">Every role currently in the system, live from the database — this is the same data Admin → Permissions edits, so it's always accurate.</p>
          <?php foreach ($allRolesForReference as $r): ?>
            <?php $rolePerms = custodia_manual_role_permissions($pdo, $r['role_key']); ?>
            <div class="border-bottom py-2">
              <div class="fw-semibold small">
                <?= e($r['label']) ?>
                <?php if (!$r['is_builtin']): ?><span class="badge text-bg-purple ms-1">Custom</span><?php endif; ?>
              </div>
              <div class="text-muted small mb-1"><?= e(custodia_manual_matter_tier($r['role_key'])) ?></div>
              <?php if (empty($rolePerms)): ?>
                <div class="small text-muted">No administrative permissions.</div>
              <?php else: ?>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach ($rolePerms as $key): ?>
                    <span class="badge text-bg-light border small"><?= e(CUSTODIA_PERMISSIONS[$key]['label'] ?? $key) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// The chatbot's "Read more" links point at help.php#secX, but Bootstrap's
// accordion sections are collapsed by default (only #secStart starts open)
// — a bare #hash link would land on an invisible, height-0 section. Expand
// whichever section the hash names, on both a fresh page load AND a
// same-page hash change (clicking a second "Read more" link while already
// on help.php only fires hashchange, not another page load).
function custodiaExpandHelpSection() {
  const id = location.hash.slice(1);
  if (!id) return;
  const target = document.getElementById(id);
  if (!target || !target.classList.contains('accordion-collapse')) return;
  bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
document.addEventListener('DOMContentLoaded', custodiaExpandHelpSection);
window.addEventListener('hashchange', custodiaExpandHelpSection);
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
