<?php
/**
 * Self-service password change — security review 2026-09-03, finding 1.1.
 * Reached automatically (custodia_require_login() in includes/auth.php)
 * whenever the signed-in user's must_reset_password flag is set: after an
 * admin creates their account, after an admin resets their password, or
 * after the one-time credential rotation in jobs/rotate_all_passwords.php.
 * No other page is reachable until this succeeds.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';

$user = custodia_require_login();
$pdo = custodia_db();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    custodia_require_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($current, $hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($new, $hash)) {
        $error = 'New password must be different from your current password.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :hash, must_reset_password = 0 WHERE id = :id')
                ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['id']]);
            custodia_audit_record($pdo, [
                'actorId' => $user['id'], 'actionType' => 'USER_PASSWORD_SELF_RESET', 'entityType' => 'USER', 'entityId' => $user['id'],
                'ipAddress' => custodia_client_ip(),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    }
}

custodia_start_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set your password · Custodia</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css?v=<?= e(custodia_asset_version('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center py-5" style="min-height: 100vh; background: #f8fafc;">
<main class="container" style="max-width: 460px;">
  <div class="text-center mb-4">
    <div style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #14b8a6, #0f766e); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
      <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="26" height="26"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <h1 class="h3 fw-bold mb-1">Set a new password</h1>
    <p class="text-muted small mb-2">Signed in as <?= e($user['email']) ?></p>
    <p class="text-muted small">Your password was set by an administrator (or reset as part of a security update) and must be changed before you can continue.</p>
  </div>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="change_password.php">
        <?= custodia_csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="current_password">Current (temporary) password</label>
          <input class="form-control" type="password" id="current_password" name="current_password" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="new_password">New password</label>
          <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required>
          <div class="form-text">At least 8 characters, and different from your current one. Pick something only you know.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="confirm_password">Confirm new password</label>
          <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Set password &amp; continue</button>
      </form>
    </div>
  </div>
  <div class="text-center mt-3">
    <a class="small text-muted" href="logout.php">Sign out</a>
  </div>
</main>
</body>
</html>
