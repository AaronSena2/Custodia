<?php
/** Digital documents — PHP port of DocumentsService (blueprint Sections 4.5–4.7). */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/matter_access.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/upload_policy.php';
require_once __DIR__ . '/text_extract.php';
require_once __DIR__ . '/media_metadata.php';
require_once __DIR__ . '/text_diff.php';
require_once __DIR__ . '/matters.php';

const CUSTODIA_CHECKOUT_TTL_SECONDS = 4 * 60 * 60; // 4 hours, per Section 4.6

function custodia_list_documents_for_matter(PDO $pdo, array $user, string $matterId): array
{
    custodia_assert_matter_access($pdo, $user, $matterId);
    $stmt = $pdo->prepare(
        'SELECT dd.*, au.full_name AS author_name, pf.barcode AS linked_file_barcode, pf.jacket_label AS linked_file_jacket_label
         FROM digital_documents dd
         LEFT JOIN users au ON au.id = dd.author_id
         LEFT JOIN physical_files pf ON pf.id = dd.linked_physical_file_id
         WHERE dd.matter_id = :mid ORDER BY dd.created_at DESC'
    );
    $stmt->execute(['mid' => $matterId]);
    $docs = $stmt->fetchAll();

    foreach ($docs as &$doc) {
        $vStmt = $pdo->prepare('SELECT * FROM document_versions WHERE document_id = :id ORDER BY version_number DESC LIMIT 1');
        $vStmt->execute(['id' => $doc['id']]);
        $doc['latest_version'] = $vStmt->fetch() ?: null;

        $lockStmt = $pdo->prepare(
            'SELECT dc.*, u.full_name FROM document_checkouts dc JOIN users u ON u.id = dc.user_id
             WHERE dc.document_id = :id AND dc.released_at IS NULL LIMIT 1'
        );
        $lockStmt->execute(['id' => $doc['id']]);
        $doc['active_lock'] = $lockStmt->fetch() ?: null;
    }
    unset($doc);

    return $docs;
}

// ── Document protection (view permission, timed grants, no download) ────
/** Does this user hold a currently-active (unexpired, approved) view grant on this specific document? */
function custodia_user_has_active_document_view_grant(PDO $pdo, string $userId, string $documentId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM access_requests
         WHERE requester_id = :uid AND entity_type = 'DIGITAL_DOCUMENT' AND entity_id = :did AND status = 'APPROVED'
           AND (expires_at IS NULL OR expires_at > NOW(6))
         LIMIT 1"
    );
    $stmt->execute(['uid' => $userId, 'did' => $documentId]);
    return (bool) $stmt->fetch();
}

/** Matter access is always required first; a Protected document additionally requires its own grant (or the override permission). */
function custodia_assert_document_view_access(PDO $pdo, array $user, array $doc): void
{
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);

    if (!$doc['is_protected']) {
        return;
    }
    if (in_array($user['role'], custodia_firm_wide_roles(), true)) {
        return;
    }
    if (custodia_user_has_permission($pdo, $user, 'override_document_protection')) {
        return;
    }
    if (custodia_user_has_active_document_view_grant($pdo, $user['id'], $doc['id'])) {
        return;
    }
    throw custodia_forbidden('This document requires view permission. Request access before viewing it.');
}

/** Did this user receive this specific document via a peer "Share Document" grant (custodia_share_document_with_user's 'SHARED' access_requests row), as opposed to an admin decision or plain matter access? */
function custodia_user_has_share_grant(PDO $pdo, string $userId, string $documentId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM access_requests
         WHERE requester_id = :uid AND entity_type = 'DIGITAL_DOCUMENT' AND entity_id = :did
           AND request_type = 'SHARED' AND status = 'APPROVED'
           AND (expires_at IS NULL OR expires_at > NOW(6))
         LIMIT 1"
    );
    $stmt->execute(['uid' => $userId, 'did' => $documentId]);
    return (bool) $stmt->fetch();
}

/**
 * True when $user was handed this document via "Share Document" and holds
 * none of the protection escape hatches (firm-wide role, or the
 * override_document_protection permission). Such a user is capped to a
 * watermarked, no-download/no-print view of this one document — no
 * uploading a new version, creating a share link, or editing its profile —
 * regardless of whatever broader team/matter access they separately hold.
 * The cap is per-document: it never touches their standing on anything else.
 */
function custodia_document_actor_is_restricted(PDO $pdo, array $user, array $doc): bool
{
    if (in_array($user['role'], custodia_firm_wide_roles(), true)) {
        return false;
    }
    if (custodia_user_has_permission($pdo, $user, 'override_document_protection')) {
        return false;
    }
    return custodia_user_has_share_grant($pdo, $user['id'], $doc['id']);
}

function custodia_set_document_protected(PDO $pdo, array $actor, string $documentId, bool $protected, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'override_document_protection');

    $stmt = $pdo->prepare('SELECT id, is_protected FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE digital_documents SET is_protected = :p WHERE id = :id')->execute(['p' => $protected ? 1 : 0, 'id' => $documentId]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => $protected ? 'DOCUMENT_PROTECTION_ENABLED' : 'DOCUMENT_PROTECTION_DISABLED',
            'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId, 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $documentId, 'isProtected' => $protected];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_find_document(PDO $pdo, array $user, string $id, string $ipAddress): array
{
    $stmt = $pdo->prepare(
        'SELECT dd.*, au.full_name AS author_name FROM digital_documents dd
         LEFT JOIN users au ON au.id = dd.author_id WHERE dd.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_document_view_access($pdo, $user, $doc);

    $vStmt = $pdo->prepare(
        'SELECT dv.id, dv.document_id, dv.version_number, dv.storage_key, dv.sha256_hash, dv.file_size_bytes,
                dv.original_filename, dv.mime_type, dv.duration_seconds,
                dv.ocr_status, dv.extraction_status, dv.uploaded_by_id, dv.uploaded_at, u.full_name AS uploaded_by_name
         FROM document_versions dv JOIN users u ON u.id = dv.uploaded_by_id
         WHERE dv.document_id = :id ORDER BY dv.version_number DESC'
    );
    $vStmt->execute(['id' => $id]);
    $doc['versions'] = $vStmt->fetchAll();

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, ['actorId' => $user['id'], 'actionType' => 'VIEW', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $id, 'ipAddress' => $ipAddress]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $doc;
}

function custodia_create_document(PDO $pdo, array $user, string $matterId, string $title, string $docType, string $confidentiality, ?string $linkedPhysicalFileId, ?string $description, string $ipAddress): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        throw custodia_forbidden('Guests may not create documents.');
    }
    custodia_assert_matter_access($pdo, $user, $matterId);

    if ($linkedPhysicalFileId !== null) {
        $pfStmt = $pdo->prepare('SELECT id FROM physical_files WHERE id = :id AND matter_id = :mid');
        $pfStmt->execute(['id' => $linkedPhysicalFileId, 'mid' => $matterId]);
        if (!$pfStmt->fetch()) {
            throw custodia_bad_request('Selected physical file does not belong to this matter.');
        }
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO digital_documents (id, matter_id, title, description, doc_type, confidentiality, linked_physical_file_id, author_id, created_by_id)
             VALUES (:id, :mid, :title, :desc, :type, :conf, :linked, :author, :creator)'
        )->execute([
            'id' => $id, 'mid' => $matterId, 'title' => $title, 'desc' => $description, 'type' => $docType,
            'conf' => $confidentiality, 'linked' => $linkedPhysicalFileId, 'author' => $user['id'], 'creator' => $user['id'],
        ]);
        // doc_number is an AUTO_INCREMENT column — read back what MySQL assigned.
        $docNumberStmt = $pdo->prepare('SELECT doc_number FROM digital_documents WHERE id = :id');
        $docNumberStmt->execute(['id' => $id]);
        $docNumber = (int) $docNumberStmt->fetchColumn();

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'DOCUMENT_CREATED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['docNumber' => $docNumber],
        ]);
        $pdo->commit();
        return ['id' => $id, 'docNumber' => $docNumber, 'label' => custodia_doc_label($docNumber)];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Editable profile fields — title, description, doc type, confidentiality, the "Author" metadata field, and the linked physical file. */
function custodia_update_document_profile(PDO $pdo, array $user, string $documentId, array $fields, string $ipAddress): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        throw custodia_forbidden('Guests may not edit document profiles.');
    }
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);
    if (custodia_document_actor_is_restricted($pdo, $user, $doc)) {
        throw custodia_forbidden('This document was shared with you as view-only — you cannot edit its profile.');
    }

    if (!empty($fields['authorId'])) {
        $authorCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id');
        $authorCheck->execute(['id' => $fields['authorId']]);
        if (!$authorCheck->fetch()) {
            throw custodia_bad_request('Selected author does not exist.');
        }
    }

    // Unlike the other fields (which fall back to the existing value when
    // omitted/blank), the linked-physical-file <select> always posts —
    // including its blank "— None —" option — so a submitted key here means
    // "set to this", where '' explicitly means "clear the link", not "leave
    // it alone".
    $linkedPhysicalFileId = $doc['linked_physical_file_id'];
    if (array_key_exists('linkedPhysicalFileId', $fields)) {
        $linkedPhysicalFileId = $fields['linkedPhysicalFileId'] ?: null;
        if ($linkedPhysicalFileId !== null) {
            $pfStmt = $pdo->prepare('SELECT id FROM physical_files WHERE id = :id AND matter_id = :mid');
            $pfStmt->execute(['id' => $linkedPhysicalFileId, 'mid' => $doc['matter_id']]);
            if (!$pfStmt->fetch()) {
                throw custodia_bad_request('Selected physical file does not belong to this matter.');
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE digital_documents SET title = :title, description = :desc, doc_type = :type, confidentiality = :conf, author_id = :author, linked_physical_file_id = :linked WHERE id = :id'
        )->execute([
            'title' => $fields['title'] ?? $doc['title'],
            'desc' => $fields['description'] ?? $doc['description'],
            'type' => $fields['docType'] ?? $doc['doc_type'],
            'conf' => $fields['confidentiality'] ?? $doc['confidentiality'],
            'author' => $fields['authorId'] ?? $doc['author_id'],
            'linked' => $linkedPhysicalFileId,
            'id' => $documentId,
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'DOCUMENT_PROFILE_UPDATED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $documentId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @param array{name:string,tmp_name:string} $file A single $_FILES[...] entry. */
function custodia_upload_document_version(PDO $pdo, array $user, string $documentId, array $file, string $ipAddress): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);
    if (custodia_document_actor_is_restricted($pdo, $user, $doc)) {
        throw custodia_forbidden('This document was shared with you as view-only — you cannot upload a new version.');
    }

    $lockStmt = $pdo->prepare('SELECT * FROM document_checkouts WHERE document_id = :id AND released_at IS NULL LIMIT 1');
    $lockStmt->execute(['id' => $documentId]);
    $activeLock = $lockStmt->fetch();
    if ($activeLock && $activeLock['user_id'] !== $user['id']) {
        throw custodia_forbidden('This document is checked out for editing by someone else.');
    }

    custodia_validate_upload($file['name'], $file['tmp_name'], (int) $file['size']);

    $stored = custodia_storage_save($doc['matter_id'], $file['name'], $file['tmp_name']);
    $nextVersionNumber = (int) $doc['current_version_no'] + 1;

    // Real (not stubbed) text extraction, run inline — see includes/text_extract.php.
    // This is separate from the OCR pipeline (still a stub swap point, see README),
    // which exists for scanned/image-only pages that have no text layer at all.
    $extracted = custodia_extract_text_for_upload(custodia_storage_path($stored['storageKey']), $file['name']);
    $durationSeconds = custodia_extract_media_duration(custodia_storage_path($stored['storageKey']), $file['name']);

    $pdo->beginTransaction();
    try {
        $versionId = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO document_versions (id, document_id, version_number, storage_key, sha256_hash, file_size_bytes, original_filename, mime_type, duration_seconds, extracted_text, extraction_status, uploaded_by_id)
             VALUES (:id, :doc, :vn, :key, :hash, :size, :orig_name, :mime, :duration, :text, :status, :uid)'
        )->execute([
            'id' => $versionId, 'doc' => $documentId, 'vn' => $nextVersionNumber, 'key' => $stored['storageKey'],
            'hash' => $stored['sha256Hash'], 'size' => $stored['fileSizeBytes'],
            'orig_name' => $stored['originalFilename'], 'mime' => $stored['mimeType'], 'duration' => $durationSeconds,
            'text' => $extracted['text'], 'status' => $extracted['status'], 'uid' => $user['id'],
        ]);
        $pdo->prepare('UPDATE digital_documents SET current_version_no = :vn WHERE id = :id')->execute(['vn' => $nextVersionNumber, 'id' => $documentId]);

        // Uploading closes out any lock the uploader was holding.
        if ($activeLock) {
            $pdo->prepare('UPDATE document_checkouts SET released_at = NOW(6) WHERE id = :id')->execute(['id' => $activeLock['id']]);
        }

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'NEW_VERSION_UPLOADED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'ipAddress' => $ipAddress, 'metadata' => ['versionNumber' => $nextVersionNumber, 'sha256Hash' => $stored['sha256Hash'], 'extractionStatus' => $extracted['status']],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['id' => $versionId, 'versionNumber' => $nextVersionNumber, 'extractionStatus' => $extracted['status']];
}

function custodia_download_document_version(PDO $pdo, array $user, string $documentId, ?string $versionId, bool $requestedInline, string $ipAddress): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);

    if ($versionId) {
        $vStmt = $pdo->prepare('SELECT * FROM document_versions WHERE id = :id');
        $vStmt->execute(['id' => $versionId]);
    } else {
        $vStmt = $pdo->prepare('SELECT * FROM document_versions WHERE document_id = :id ORDER BY version_number DESC LIMIT 1');
        $vStmt->execute(['id' => $documentId]);
    }
    $version = $vStmt->fetch();
    if (!$version) {
        throw custodia_not_found('Version not found.');
    }

    // Same "always fall to attachment unless the caller asked for inline AND
    // the mime type is on the previewable allow-list" rule the download
    // action used to compute itself after the fact — centralized here so the
    // Protected-document gate below and the action's Content-Disposition
    // header can never disagree about which mode this is.
    $inline = $requestedInline && custodia_is_previewable_mime($version['mime_type']);

    if ($doc['is_protected']) {
        $hasOverride = in_array($user['role'], custodia_firm_wide_roles(), true)
            || custodia_user_has_permission($pdo, $user, 'override_document_protection');
        if (!$hasOverride) {
            if (!$inline) {
                throw custodia_forbidden('Downloads are disabled for this protected document.');
            }
            if (!custodia_user_has_active_document_view_grant($pdo, $user['id'], $documentId)) {
                throw custodia_forbidden('This document requires view permission. Request access before viewing it.');
            }
        }
    } elseif (!$inline && custodia_document_actor_is_restricted($pdo, $user, $doc)) {
        // Not Protected, but this recipient only has it via "Share Document" —
        // downloads stay off for them even though inline viewing is fine.
        throw custodia_forbidden('Downloads are disabled for documents shared with you as view-only.');
    }

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => $inline ? 'VIEW' : 'DOWNLOAD', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'ipAddress' => $ipAddress, 'metadata' => ['versionId' => $version['id'], 'versionNumber' => $version['version_number']],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['path' => custodia_storage_path($version['storage_key']), 'version' => $version, 'inline' => $inline];
}

// ── 4.6 Digital "checkout" edit lock ────────────────────────────────
function custodia_checkout_document_for_edit(PDO $pdo, array $user, string $documentId, string $ipAddress): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);

    $existingStmt = $pdo->prepare('SELECT * FROM document_checkouts WHERE document_id = :id AND released_at IS NULL LIMIT 1');
    $existingStmt->execute(['id' => $documentId]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        if ($existing['user_id'] === $user['id']) {
            return $existing; // already holds the lock
        }
        throw custodia_bad_request('Document is already checked out for editing by someone else.');
    }

    $pdo->beginTransaction();
    try {
        $lockId = custodia_uuid();
        $expiresAt = (new DateTimeImmutable("+" . CUSTODIA_CHECKOUT_TTL_SECONDS . " seconds"))->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO document_checkouts (id, document_id, user_id, expires_at) VALUES (:id, :doc, :uid, :exp)')
            ->execute(['id' => $lockId, 'doc' => $documentId, 'uid' => $user['id'], 'exp' => $expiresAt]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'DOCUMENT_LOCKED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'ipAddress' => $ipAddress, 'metadata' => ['checkoutId' => $lockId],
        ]);
        $pdo->commit();
        return ['id' => $lockId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_release_document_checkout(PDO $pdo, array $user, string $documentId, string $ipAddress): array
{
    $lockStmt = $pdo->prepare('SELECT * FROM document_checkouts WHERE document_id = :id AND released_at IS NULL LIMIT 1');
    $lockStmt->execute(['id' => $documentId]);
    $lock = $lockStmt->fetch();
    if (!$lock) {
        throw custodia_bad_request('Document is not currently checked out.');
    }

    $isOwner = $lock['user_id'] === $user['id'];
    $isOverride = !$isOwner && custodia_user_has_permission($pdo, $user, 'override_document_locks');
    if (!$isOwner && !$isOverride) {
        throw custodia_forbidden('Only the lock holder, Records Manager, or Admin may release this lock.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE document_checkouts SET released_at = NOW(6) WHERE id = :id')->execute(['id' => $lock['id']]);
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => $isOverride ? 'DOCUMENT_LOCK_OVERRIDE_RELEASED' : 'DOCUMENT_UNLOCKED',
            'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId, 'ipAddress' => $ipAddress,
            'metadata' => ['checkoutId' => $lock['id'], 'originalHolderId' => $lock['user_id']],
        ]);
        $pdo->commit();
        return ['id' => $lock['id']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 4.7 Share links ──────────────────────────────────────────────────
function custodia_create_share_link(PDO $pdo, array $user, string $documentId, string $permission, ?string $recipientEmail, int $expiresInHours, string $ipAddress): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);
    if (custodia_document_actor_is_restricted($pdo, $user, $doc)) {
        throw custodia_forbidden('This document was shared with you as view-only — you cannot create a share link for it.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $token = bin2hex(random_bytes(24));
        $expiresAt = (new DateTimeImmutable("+{$expiresInHours} hours"))->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'INSERT INTO share_links (id, document_id, token, created_by_id, permission, recipient_email, expires_at) VALUES (:id, :doc, :token, :uid, :perm, :email, :exp)'
        )->execute(['id' => $id, 'doc' => $documentId, 'token' => $token, 'uid' => $user['id'], 'perm' => $permission, 'email' => $recipientEmail, 'exp' => $expiresAt]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'SHARE_LINK_CREATED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'ipAddress' => $ipAddress, 'metadata' => ['shareLinkId' => $id, 'permission' => $permission],
        ]);
        $pdo->commit();
        return ['id' => $id, 'token' => $token];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Full-text search ─────────────────────────────────────────────────
/**
 * Searches document title, description, iManage-style doc label, and the
 * extracted text of each document's CURRENT version — respecting the same
 * three-layer RBAC as everywhere else (a user only ever sees hits inside
 * matters they can already access; ethical walls and confidentiality tiers
 * apply exactly as they do browsing matter-by-matter).
 */
function custodia_search_documents(PDO $pdo, array $user, string $queryText): array
{
    $queryText = trim($queryText);
    $isNumeric = $queryText !== '' && ctype_digit($queryText);
    if ($queryText === '' || (mb_strlen($queryText) < 2 && !$isNumeric)) {
        return [];
    }

    $matters = custodia_list_matters_for_user($pdo, $user);
    $matterIds = array_column($matters, 'id');
    if (empty($matterIds)) {
        return [];
    }
    $matterById = array_combine($matterIds, $matters);

    $placeholders = [];
    $params = [];
    foreach ($matterIds as $i => $mid) {
        $key = "m{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $mid;
    }

    // A leading-digit query (or one that parses as a bare number) is treated as
    // a possible doc-number lookup too, e.g. searching "142" or "CUS-000142".
    // Native (non-emulated) PDO/MySQL prepares reject a named placeholder used
    // more than once in a single query, so each LIKE/MATCH usage below gets
    // its own placeholder, all bound to the same value.
    $numericGuess = preg_replace('/[^0-9]/', '', $queryText);
    $params['qlike1'] = '%' . $queryText . '%';
    $params['qlike2'] = '%' . $queryText . '%';
    $params['qlike3'] = '%' . $queryText . '%';
    $params['docnum'] = $numericGuess !== '' ? (int) $numericGuess : -1;

    // BOOLEAN MODE with every term required (+prefix, trailing * for a forgiving
    // prefix match) gives AND-like "all these words" search behavior, which is
    // what people expect from a multi-word query — MySQL's default NATURAL
    // LANGUAGE MODE instead ranks by ANY-word overlap, which reads as noisy
    // false positives for a document search feature.
    $booleanTerms = [];
    foreach (preg_split('/\s+/', $queryText) as $word) {
        $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        if ($clean !== '' && mb_strlen($clean) >= 2) {
            $booleanTerms[] = '+' . $clean . '*';
        }
    }
    $booleanQuery = implode(' ', $booleanTerms);
    $matchClause = '';
    if ($booleanQuery !== '') {
        $params['qfull'] = $booleanQuery;
        $matchClause = "OR (dv.extracted_text IS NOT NULL AND MATCH(dv.extracted_text) AGAINST (:qfull IN BOOLEAN MODE))";
    }

    $sql = "SELECT dd.*, dv.version_number AS matched_version_number, dv.extracted_text AS matched_text, dv.extraction_status
            FROM digital_documents dd
            LEFT JOIN document_versions dv ON dv.document_id = dd.id AND dv.version_number = dd.current_version_no
            WHERE dd.matter_id IN (" . implode(',', $placeholders) . ")
              AND (
                dd.title LIKE :qlike1
                OR dd.description LIKE :qlike2
                OR dd.doc_type LIKE :qlike3
                OR dd.doc_number = :docnum
                {$matchClause}
              )
            ORDER BY dd.created_at DESC
            LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['matter'] = $matterById[$row['matter_id']] ?? null;
        $row['snippet'] = custodia_build_search_snippet($row['matched_text'] ?? null, $queryText);
    }
    unset($row);

    return $rows;
}

/** Plain-text excerpt centered on the first case-insensitive match, for search results. */
function custodia_build_search_snippet(?string $text, string $query, int $radius = 120): ?string
{
    if ($text === null || $text === '') {
        return null;
    }
    $pos = mb_stripos($text, $query);
    if ($pos === false) {
        return mb_strlen($text) > $radius * 2 ? mb_substr($text, 0, $radius * 2) . '…' : $text;
    }
    $start = max(0, $pos - $radius);
    $length = $radius * 2 + mb_strlen($query);
    $excerpt = mb_substr($text, $start, $length);
    return ($start > 0 ? '…' : '') . trim($excerpt) . '…';
}

// ── Version comparison ───────────────────────────────────────────────
/**
 * @return array{versionA: array, versionB: array, diff: array, comparable: bool, reason: ?string}
 */
function custodia_compare_document_versions(PDO $pdo, array $user, string $documentId, int $versionNumberA, int $versionNumberB): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    custodia_assert_matter_access($pdo, $user, $doc['matter_id']);

    $vStmt = $pdo->prepare('SELECT * FROM document_versions WHERE document_id = :id AND version_number IN (:a, :b)');
    $vStmt->execute(['id' => $documentId, 'a' => $versionNumberA, 'b' => $versionNumberB]);
    $versions = $vStmt->fetchAll();
    $byNumber = [];
    foreach ($versions as $v) {
        $byNumber[(int) $v['version_number']] = $v;
    }
    $versionA = $byNumber[$versionNumberA] ?? null;
    $versionB = $byNumber[$versionNumberB] ?? null;
    if (!$versionA || !$versionB) {
        throw custodia_not_found('One or both versions were not found.');
    }

    if ($versionA['extraction_status'] !== 'DONE' || $versionB['extraction_status'] !== 'DONE') {
        return [
            'versionA' => $versionA, 'versionB' => $versionB, 'diff' => null, 'comparable' => false,
            'reason' => 'One or both of these versions has no extracted text (' .
                custodia_extraction_status_label($versionA['extraction_status']) . ' / ' .
                custodia_extraction_status_label($versionB['extraction_status']) . '), so a redline comparison isn\'t available.',
        ];
    }

    $diff = custodia_diff_versions($versionA['extracted_text'], $versionB['extracted_text']);

    return ['versionA' => $versionA, 'versionB' => $versionB, 'diff' => $diff, 'comparable' => true, 'reason' => null];
}
