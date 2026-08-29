<?php
/**
 * Guide chatbot — an interactive front door to help.php's User Manual.
 * Deliberately pure workflow/navigation guidance only: how do I do X, what
 * does term Y mean, where do I find Z. NOTHING in this file ever takes a
 * PDO connection in its answer-matching path — that's not an oversight, it's
 * the whole safety property: this bot must never be able to grow into a
 * real data lookup (matter/client/document content) by someone innocently
 * adding "just one more" query later. The one function that does take a
 * PDO ($pdo) — custodia_chatbot_suggested_chips() — only uses it to check
 * the asking user's own permissions (same as help.php's own personalization
 * panel), never to fetch business data.
 *
 * Matching is a small dependency-free keyword scorer — no external NLP
 * library, consistent with this codebase's dependency-free stance. Each KB
 * entry links back to a help.php accordion section (via ?..#secX) for the
 * full prose rather than duplicating it — help.php stays the exhaustive
 * reference, this is the fast front door.
 */

require_once __DIR__ . '/permissions.php';

/** @return array<int, array{id:string,section:string,sectionLabel:string,question:string,keywords:string[],answer:string,permission:?string,chipRank:int}> */
function custodia_chatbot_knowledge_base(): array
{
    return [
        // --- Getting Started ---
        ['id' => 'start_dashboard', 'section' => 'secStart', 'sectionLabel' => 'Getting Started',
         'question' => 'What does the Dashboard show me?',
         'keywords' => ['dashboard', 'home screen', 'overdue files', 'pending approvals', 'analytics'],
         'answer' => 'The Dashboard is your home screen: overdue physical files, pending approvals waiting on you, matters pending destruction review, and activity analytics — all scoped to what you can see.',
         'permission' => null, 'chipRank' => 10],

        ['id' => 'start_search', 'section' => 'secStart', 'sectionLabel' => 'Getting Started',
         'question' => 'How do I search for a document or file?',
         'keywords' => ['search', 'find document', 'find file', 'look up', 'document number', 'cus-'],
         'answer' => "Use the search box in the dashboard header, or search.php. It does full-text search across document titles, descriptions, and extracted content — including a permanent document number like 142 or CUS-000142.",
         'permission' => null, 'chipRank' => 20],

        ['id' => 'start_list_pages', 'section' => 'secStart', 'sectionLabel' => 'Getting Started',
         'question' => 'How do list pages like Matters and Clients work?',
         'keywords' => ['sort', 'filter', 'per page', 'pagination', 'list page', 'columns'],
         'answer' => "List pages (Matters, Clients, a matter's Physical Files/Digital Documents, the Audit Log) all share the same controls: a search/filter bar, click-to-sort column headers, and a Per Page selector next to the pager.",
         'permission' => null, 'chipRank' => 90],

        // --- Matters & Access Control ---
        ['id' => 'matters_why_hidden', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => "Why can't I see a matter?",
         'keywords' => ["can't see matter", 'ethical wall', 'confidentiality tier', 'restricted matter', 'privileged matter', 'hidden matter'],
         'answer' => 'Three independent layers all must pass: an ethical wall (a hard per-user block), the confidentiality tier (Standard vs Restricted/Privileged), and assignment (being on the team, or a firm-wide role). Any one of them can hide a matter from you.',
         'permission' => null, 'chipRank' => 30],

        ['id' => 'matters_request_access', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => 'How do I request access to a matter?',
         'keywords' => ['request access', 'need access', 'access request', "can't view matter"],
         'answer' => 'Use "Request Access" on the matter, where available. A firm-wide role or that matter\'s incharge will see it on their Approvals page.',
         'permission' => null, 'chipRank' => 40],

        ['id' => 'matters_create', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => 'How do I create a new matter?',
         'keywords' => ['create matter', 'new matter', 'open matter', 'add matter'],
         'answer' => 'Needs the Create Matters permission. Pick an existing Client and Practice Area from dropdowns — a Partner must name themselves as incharge.',
         'permission' => 'create_matters', 'chipRank' => 15],

        ['id' => 'matters_edit_deactivate', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => 'How do I edit or close a matter?',
         'keywords' => ['edit matter', 'close matter', 'deactivate matter', 'reopen matter', 'reactivate matter'],
         'answer' => 'Edit Matter Details lets you change a matter\'s fields; a separate Deactivate/Reactivate Matters permission toggles its Status between Closed and Active without opening the full edit form.',
         'permission' => 'edit_matters', 'chipRank' => 60],

        ['id' => 'matters_team_tab', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => "How do I add someone to a matter's team?",
         'keywords' => ['team & access', 'add team member', 'ethical wall setup', "matter's team"],
         'answer' => "A matter's Team & Access tab lists who's assigned and configures ethical walls — needs a firm-wide role, or (for team membership) being that matter's incharge.",
         'permission' => null, 'chipRank' => 70],

        ['id' => 'generic_confidentiality_tier', 'section' => 'secMatters', 'sectionLabel' => 'Matters & Access Control',
         'question' => 'What is a confidentiality tier?',
         'keywords' => ['confidentiality tier', 'standard restricted privileged'],
         'answer' => 'Standard matters are visible to anyone who passes assignment; Restricted and Privileged matters are invisible to everyone except firm-wide roles, the matter\'s team, or an approved access request.',
         'permission' => null, 'chipRank' => 48],

        // --- Clients ---
        ['id' => 'clients_directory', 'section' => 'secClients', 'sectionLabel' => 'Clients',
         'question' => "Where do I find a client's details?",
         'keywords' => ['client directory', 'client profile', "client's matters", 'find client'],
         'answer' => "The Clients page is a firm-wide directory. Click through to a client's profile for its full details and the matters under it, filtered to what you're allowed to see.",
         'permission' => null, 'chipRank' => 50],

        ['id' => 'clients_create_edit', 'section' => 'secClients', 'sectionLabel' => 'Clients',
         'question' => 'How do I add a new client?',
         'keywords' => ['create client', 'new client', 'add client', 'edit client'],
         'answer' => 'Needs the Create Clients permission. A client has to exist here before it can be picked when opening a matter, since that form uses a dropdown, not free text.',
         'permission' => 'create_clients', 'chipRank' => 25],

        ['id' => 'clients_bulk_import', 'section' => 'secClients', 'sectionLabel' => 'Clients',
         'question' => 'How do I bulk import clients?',
         'keywords' => ['bulk import', 'csv import', 'import clients'],
         'answer' => 'Use "Bulk Import" next to "+ New Client" (same permission) — one row per client, first row a header. Bad rows are skipped with a reason; everything else still imports.',
         'permission' => 'create_clients', 'chipRank' => 80],

        // --- Physical File Custody ---
        ['id' => 'custody_numbers', 'section' => 'secCustody', 'sectionLabel' => 'Physical File Custody',
         'question' => "What's the difference between a Physical File Number and a Matter Number?",
         'keywords' => ['physical file number', 'matter number', 'file number vs matter'],
         'answer' => "A Matter Number identifies the engagement itself; a Physical File Number identifies one physical file/box under it — a matter can have several physical files.",
         'permission' => null, 'chipRank' => 45],

        ['id' => 'custody_issue_return', 'section' => 'secCustody', 'sectionLabel' => 'Physical File Custody',
         'question' => 'How do I issue or return a physical file?',
         'keywords' => ['issue file', 'return file', 'check out file', 'scan station'],
         'answer' => "Use Scan Station (scan.php) or a matter's Physical Files tab. Issue completes immediately if your role has Auto-Approved Issue, otherwise it goes to Pending Approval. Return is done by the current custodian at any time.",
         'permission' => null, 'chipRank' => 35],

        ['id' => 'custody_transfer', 'section' => 'secCustody', 'sectionLabel' => 'Physical File Custody',
         'question' => 'How do I transfer a checked-out physical file?',
         'keywords' => ['transfer file', 'request transfer', 'approve transfer'],
         'answer' => "Request Transfer asks the current custodian for a file they hold. It stays with them, unchanged, until they approve the request from their own Approvals page — only they can approve or reject it.",
         'permission' => null, 'chipRank' => 55],

        ['id' => 'custody_override_return', 'section' => 'secCustody', 'sectionLabel' => 'Physical File Custody',
         'question' => 'How do I force-return a file someone else has?',
         'keywords' => ['override return', 'force return'],
         'answer' => 'Override Return force-returns a file issued to someone else. Needs the Override Custody permission.',
         'permission' => 'override_custody', 'chipRank' => 85],

        ['id' => 'custody_vs_status', 'section' => 'secCustody', 'sectionLabel' => 'Physical File Custody',
         'question' => "What's the difference between Custody and Status?",
         'keywords' => ['custody vs status', 'custody status difference'],
         'answer' => "Custody is where the file physically is (In Registry, Issued, Offsite Archive, etc.). Status is whether it's still an active working file (Open/Closed). They're independent — a file can be Issued and Closed at once.",
         'permission' => null, 'chipRank' => 65],

        // --- Digital Documents ---
        ['id' => 'docs_upload_version', 'section' => 'secDocs', 'sectionLabel' => 'Digital Documents',
         'question' => 'How do I upload a new version of a document?',
         'keywords' => ['upload document', 'new version', 'upload version'],
         'answer' => 'Upload accepts PDF, Office formats, text, images, audio, and video. Text is extracted immediately so it becomes searchable right away.',
         'permission' => null, 'chipRank' => 12],

        ['id' => 'docs_preview_download', 'section' => 'secDocs', 'sectionLabel' => 'Digital Documents',
         'question' => 'How do I preview or download a document?',
         'keywords' => ['preview document', 'download document'],
         'answer' => 'Preview opens PDFs, images, audio, and video in the browser; Download always saves the file. Both respect the same matter-access rules as everything else.',
         'permission' => null, 'chipRank' => 42],

        ['id' => 'docs_lock', 'section' => 'secDocs', 'sectionLabel' => 'Digital Documents',
         'question' => "How do I lock a document or release someone else's lock?",
         'keywords' => ['lock document', 'release lock', 'unlock document'],
         'answer' => "Lock reserves a document for editing. Release Lock is available to the lock holder, or to anyone with the Override Document Locks permission.",
         'permission' => null, 'chipRank' => 75],

        ['id' => 'docs_compare', 'section' => 'secDocs', 'sectionLabel' => 'Digital Documents',
         'question' => 'How do I compare two versions of a document?',
         'keywords' => ['compare document', 'redline', 'version comparison'],
         'answer' => 'Compare (once a document has 2+ versions) shows a paragraph-and-word-level redline between any two versions.',
         'permission' => null, 'chipRank' => 95],

        ['id' => 'docs_share_link', 'section' => 'secDocs', 'sectionLabel' => 'Digital Documents',
         'question' => 'How do I share a document with someone outside the firm?',
         'keywords' => ['share link', 'external share', 'share document'],
         'answer' => 'Share Link generates a secure, expiring link for someone outside your normal access path. Every open of that link is recorded in the audit trail.',
         'permission' => null, 'chipRank' => 100],

        // --- Approvals ---
        ['id' => 'approvals_queues', 'section' => 'secApprovals', 'sectionLabel' => 'Approvals',
         'question' => 'What shows up on my Approvals page?',
         'keywords' => ['approvals page', 'approval queue'],
         'answer' => 'Two independent queues: Custody Transfers (transfer requests for files you hold, plus issue requests if you have Approve Custody Movements) and Confidential Access Requests (Restricted/Privileged matter access).',
         'permission' => null, 'chipRank' => 22],

        ['id' => 'approvals_who_decides', 'section' => 'secApprovals', 'sectionLabel' => 'Approvals',
         'question' => 'Who approves a custody transfer or access request?',
         'keywords' => ['who approves', 'who decides access'],
         'answer' => "A transfer is decided only by the current custodian. An access request is decided by anyone with Decide Access Requests, or that matter's incharge.",
         'permission' => null, 'chipRank' => 72],

        // --- Audit Log ---
        ['id' => 'audit_visibility', 'section' => 'secAudit', 'sectionLabel' => 'Audit Log',
         'question' => 'Who can see what on the Audit Log?',
         'keywords' => ['who can see the audit log', 'audit visibility by role', 'see my own actions only'],
         'answer' => 'The Audit Log page itself needs the View Audit Log permission — off by default for everyone but System Administrator. For whoever has it: Associate/Paralegal see only their own actions, a Partner sees activity on matters they manage, and firm-wide roles see everything.',
         'permission' => null, 'chipRank' => 62],

        ['id' => 'audit_export_verify', 'section' => 'secAudit', 'sectionLabel' => 'Audit Log',
         'question' => 'How do I export the audit log or verify its integrity?',
         'keywords' => ['export audit', 'verify chain', 'audit csv'],
         'answer' => 'Export Report (needs Export Audit Log) downloads the filtered view as CSV. Verify Chain Integrity (needs Verify Audit Chain) recomputes every entry\'s hash from the beginning.',
         'permission' => 'export_audit_log', 'chipRank' => 82],

        // --- Admin ---
        ['id' => 'admin_retention', 'section' => 'secAdmin', 'sectionLabel' => 'Admin',
         'question' => 'How do I configure retention policies?',
         'keywords' => ['retention policy', 'retention window'],
         'answer' => 'Needs Manage Retention Policies. Configures how long each practice area\'s matters are kept before review/archive/destruction.',
         'permission' => 'manage_retention_policies', 'chipRank' => 78],

        ['id' => 'admin_users', 'section' => 'secAdmin', 'sectionLabel' => 'Admin',
         'question' => 'How do I create or manage user accounts?',
         'keywords' => ['create user', 'manage user', 'reset password', 'bulk import users'],
         'answer' => 'Needs Manage User Accounts. Create accounts, edit profiles/roles, deactivate/reactivate, and reset passwords — there\'s no self-service "forgot password."',
         'permission' => 'manage_users', 'chipRank' => 18],

        ['id' => 'admin_permissions', 'section' => 'secAdmin', 'sectionLabel' => 'Admin',
         'question' => 'How do I change what a role can do?',
         'keywords' => ['permissions matrix', 'role permissions', 'create role', 'custom role'],
         'answer' => 'Admin → Permissions is a role × capability matrix — also needs Manage User Accounts. You can create or delete roles beyond the six built-in ones.',
         'permission' => 'manage_users', 'chipRank' => 28],

        ['id' => 'admin_practice_areas', 'section' => 'secAdmin', 'sectionLabel' => 'Admin',
         'question' => 'How do I add or rename a practice area?',
         'keywords' => ['practice area', 'rename practice area', 'practice group'],
         'answer' => 'Needs Manage Practice Groups. Renaming updates every matter, user, and retention policy currently using the old name.',
         'permission' => 'manage_practice_groups', 'chipRank' => 88],
    ];
}

const CUSTODIA_CHATBOT_MIN_SCORE = 2.0;

const CUSTODIA_CHATBOT_STOPWORDS = [
    'a', 'an', 'the', 'is', 'are', 'do', 'does', 'how', 'what', 'where', 'who', 'i',
    'to', 'of', 'for', 'my', 'in', 'on', 'can', 'it', 'and', 'or', 'with', 'me', 'this',
];

/** Lowercase, strip punctuation, split on whitespace, drop stopwords. */
function custodia_chatbot_tokenize(string $text): array
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_diff($words, CUSTODIA_CHATBOT_STOPWORDS));
}

/**
 * Scores one KB entry against a tokenized+raw query. A whole keyword phrase
 * found verbatim in the query is a strong signal (+3); otherwise individual
 * shared tokens between the query and that phrase count for less (+1 each).
 * A small bonus (+0.5/token) for overlap with the entry's own canonical
 * question text, since users often echo it closely.
 */
function custodia_chatbot_score_entry(array $queryTokens, string $rawQueryLower, array $entry): float
{
    $score = 0.0;
    foreach ($entry['keywords'] as $phrase) {
        $phraseLower = strtolower($phrase);
        if ($phraseLower !== '' && str_contains($rawQueryLower, $phraseLower)) {
            $score += 3.0;
            continue;
        }
        $phraseTokens = custodia_chatbot_tokenize($phrase);
        $score += count(array_intersect($queryTokens, $phraseTokens));
    }
    $questionTokens = custodia_chatbot_tokenize($entry['question']);
    $score += 0.5 * count(array_intersect($queryTokens, $questionTokens));
    return $score;
}

/**
 * Top-level orchestrator — the exact shape actions/chatbot_query.php hands
 * back as `data`. Deliberately takes no PDO: this function can only ever
 * answer from the static knowledge base above, never look anything up.
 */
function custodia_chatbot_find_answer(string $message): array
{
    $kb = custodia_chatbot_knowledge_base();
    $rawLower = strtolower(trim($message));
    $tokens = custodia_chatbot_tokenize($message);

    $scored = [];
    foreach ($kb as $entry) {
        $scored[] = ['entry' => $entry, 'score' => custodia_chatbot_score_entry($tokens, $rawLower, $entry)];
    }
    usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

    $best = $scored[0] ?? null;

    if (!$best || $best['score'] < CUSTODIA_CHATBOT_MIN_SCORE) {
        $followUps = array_filter(array_slice($scored, 0, 2), fn ($s) => $s['score'] > 0);
        return [
            'matched' => false,
            'answer' => "I couldn't find a specific answer to that. Try rephrasing, or browse the full User Manual.",
            'sectionLabel' => null,
            'link' => 'help.php',
            'followUps' => array_values(array_map(fn ($s) => ['id' => $s['entry']['id'], 'question' => $s['entry']['question']], $followUps)),
        ];
    }

    $entry = $best['entry'];
    $followUps = array_slice(array_values(array_filter(
        $scored,
        fn ($s) => $s['entry']['id'] !== $entry['id'] && $s['score'] > 0
    )), 0, 2);

    return [
        'matched' => true,
        'answer' => $entry['answer'],
        'sectionLabel' => $entry['sectionLabel'],
        'link' => 'help.php#' . $entry['section'],
        'followUps' => array_map(fn ($s) => ['id' => $s['entry']['id'], 'question' => $s['entry']['question']], $followUps),
    ];
}

/**
 * Default chips shown when the chat panel first opens — role-aware via a
 * live custodia_user_has_permission() check, same discipline help.php's own
 * "Your Access at a Glance" panel already follows, so this works correctly
 * for admin-created custom roles too, not just the 6 built-ins.
 */
function custodia_chatbot_suggested_chips(PDO $pdo, array $user, int $limit = 6): array
{
    $kb = custodia_chatbot_knowledge_base();
    $eligible = array_values(array_filter($kb, function ($entry) use ($pdo, $user) {
        return $entry['permission'] === null || custodia_user_has_permission($pdo, $user, $entry['permission']);
    }));
    usort($eligible, fn ($a, $b) => $a['chipRank'] <=> $b['chipRank']);
    $eligible = array_slice($eligible, 0, $limit);
    return array_map(fn ($e) => ['id' => $e['id'], 'question' => $e['question']], $eligible);
}
