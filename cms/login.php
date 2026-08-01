<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

$next = cms_safe_redirect_target(isset($_GET['next']) ? $_GET['next'] : '/');
$error = '';

if (cms_is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = isset($_POST['username']) ? $_POST['username'] : '';
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    if (cms_login($user, $pass)) {
        header('Location: ' . $next);
        exit;
    }
    if (cms_is_locked_out($user)) {
        http_response_code(429);
        header('Retry-After: ' . cms_login_retry_after());
        $error = 'Too many failed attempts. Try again in a few minutes.';
    } else {
        $error = 'Invalid username or password.';
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pagecore CMS sign in</title>
  <!-- Open Sans establishes the shared default font before an editor enters the CMS. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,1,0&display=swap" rel="stylesheet">
  <style nonce="<?= htmlspecialchars(cms_csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: #f5f1e8;
      color: #2b2620;
      font: 15px/1.5 "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    main {
      width: min(360px, calc(100vw - 32px));
      padding: 28px;
      background: #fff;
      border: 1px solid #ded7ca;
      border-radius: 8px;
      box-shadow: 0 10px 28px rgba(43, 38, 32, .14);
    }
    h1 { margin: 0 0 18px; font-size: 22px; line-height: 1.2; }
    label { display: block; margin: 12px 0 5px; font-weight: 650; font-size: 13px; }
    input {
      box-sizing: border-box;
      width: 100%;
      padding: 10px 11px;
      border: 1px solid #d8d2c4;
      border-radius: 4px;
      font: inherit;
    }
    button {
      width: 100%;
      margin-top: 18px;
      padding: 10px 14px;
      border: 0;
      border-radius: 4px;
      background: #9c3f2e;
      color: #fff;
      font: 700 14px/1.4 inherit;
      cursor: pointer;
    }
    .error {
      margin: 0 0 12px;
      padding: 9px 10px;
      border: 1px solid #e0b6aa;
      background: #fff5f2;
      color: #8c2f1c;
      border-radius: 4px;
      font-size: 13px;
    }
  </style>
  <link rel="stylesheet" href="/cms/assets/admin.css">
</head>
<body class="pc-admin pc-login">
  <div class="pc-login-shell">
    <section class="pc-login-intro" aria-label="Pagecore introduction">
      <div>
        <div class="pc-brand">
          <span class="pc-brand-mark"><span class="material-symbols-rounded" aria-hidden="true">check</span></span>
          <span class="pc-brand-copy"><strong>Pagecore</strong><span>Content workspace</span></span>
        </div>
        <h2>Your site, without the clutter.</h2>
        <p>Edit pages, publish posts, and manage media from one focused workspace.</p>
      </div>
      <span class="pc-login-note">Simple, file-backed content management.</span>
    </section>
    <section class="pc-login-form">
      <main>
        <p class="pc-eyebrow">Administration</p>
        <h1>Welcome back</h1>
        <p class="pc-login-sub">Sign in to manage your website.</p>
        <?php if ($error !== ''): ?>
          <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post" action="/cms/login.php?next=<?= rawurlencode($next) ?>">
          <label for="cms-login-username">Username</label>
          <input id="cms-login-username" name="username" autocomplete="username" placeholder="Enter your username" required autofocus>
          <label for="cms-login-password">Password</label>
          <input id="cms-login-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
          <button type="submit">Sign in to Pagecore</button>
        </form>
      </main>
    </section>
  </div>
</body>
</html>
