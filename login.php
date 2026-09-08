<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$existingUser = custodia_current_user();
if ($existingUser) {
    header('Location: dashboard.php');
    exit;
}

$pdo = custodia_db();

$notice = $_SESSION['login_notice'] ?? null;
unset($_SESSION['login_notice']);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    custodia_require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $user = custodia_attempt_login($email, $password);
    if ($user) {
        custodia_start_session();
        $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    }

    // Security review 2026-09-03, finding 1.6: a locked account gets a
    // distinct message (and the password is never even checked while
    // locked — see custodia_attempt_login()) rather than the generic
    // "Incorrect email or password" every other failure shows.
    $lockMinutes = custodia_account_lock_remaining_minutes($pdo, $email);
    $error = $lockMinutes !== null
        ? 'Too many failed attempts. This account is temporarily locked — try again in '
            . $lockMinutes . ' minute' . ($lockMinutes === 1 ? '' : 's') . '.'
        : 'Incorrect email or password.';
}

custodia_start_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · Custodia</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css?v=<?= e(custodia_asset_version('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center py-5" style="min-height: 100vh; background: #f8fafc;">
<main class="container" style="max-width: 420px;">
  <div class="text-center mb-4">
    <div style="width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #14b8a6, #0f766e); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
      <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="26" height="26"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 4v16"/></svg>
    </div>
    <h1 class="h3 fw-bold mb-1">Custodia</h1>
    <p class="text-muted">File Registry &amp; Movement Tracking for Every Business</p>
  </div>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <?php if ($notice): ?>
        <div class="alert alert-info py-2"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="login.php">
        <?= custodia_csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" type="email" id="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" type="password" id="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
      </form>
    </div>
  </div>
</main>
</body>
</html>
