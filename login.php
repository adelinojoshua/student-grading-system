<?php
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/db.php';

$error = '';
$blockedUntil = (int) ($_SESSION['login_block_until'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'The form expired. Refresh the page and try again.';
    } elseif ($blockedUntil > time()) {
        $error = 'Too many attempts. Please try again in one minute.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $account = null;
        try {
            $stmt = $conn->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $error = 'No administrator account is configured yet. Follow the setup instructions.';
        }

        if ($account && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $account['id'];
            $_SESSION['admin_username'] = (string) $account['username'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
            unset($_SESSION['login_attempts'], $_SESSION['login_block_until']);
            header('Location: index.php');
            exit;
        }
        if ($error === '') {
            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_block_until'] = time() + 60;
                $_SESSION['login_attempts'] = 0;
            }
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login | Student Grading System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height:100vh; margin:0; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; background:linear-gradient(125deg,#0f172a,#1e2a54 60%,#4f46e5); display:grid; place-items:center; padding:22px; }
    .login-card { width:min(100%,430px); border:0; border-radius:20px; overflow:hidden; box-shadow:0 25px 80px rgba(1,5,30,.38); }
    .logo { display:grid; place-items:center; width:52px; height:52px; border-radius:14px; background:#5458e9; color:#fff; font-weight:800; font-size:24px; }
    .muted { color:#64748b; }
    .btn-login { background:#4f46e5; border-color:#4f46e5; padding:12px; font-weight:650; }
    .btn-login:hover { background:#3f36ce; border-color:#3f36ce; }
  </style>
</head>
<body>
  <main class="card login-card">
    <div class="card-body p-4 p-md-5">
      <div class="logo mb-3">SG</div>
      <h1 class="h3 fw-bold mb-1">Administrator Login</h1>
      <p class="muted mb-4">Student Grading System · Academic Workspace</p>
      <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Admin account created. Please log in.</div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="mb-3"><label for="username" class="form-label fw-semibold">Username</label><input id="username" name="username" class="form-control form-control-lg" autocomplete="username" required maxlength="32"></div>
        <div class="mb-4"><label for="password" class="form-label fw-semibold">Password</label><input id="password" name="password" type="password" class="form-control form-control-lg" autocomplete="current-password" required></div>
        <button type="submit" class="btn btn-primary btn-login w-100">Sign In</button>
      </form>
      <div class="small muted mt-4">For your first local setup, create your administrator account first.
        <?php if (is_local_request()): ?><a href="setup_admin.php">Create admin account</a><?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
