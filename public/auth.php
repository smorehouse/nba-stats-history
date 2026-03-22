<?php
/**
 * Simple password gate.
 * Include this at the top of every page to require authentication.
 *
 * Set APP_PASSWORD env var to enable. If not set, auth is skipped (local dev).
 */
$app_password = getenv('APP_PASSWORD');

// Skip auth if no password is configured (local dev)
if ($app_password === false || $app_password === '') {
    return;
}

session_start();

// Handle login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($app_password, $_POST['password'])) {
        $_SESSION['authenticated'] = true;
        // Redirect to avoid form resubmission
        $redirect = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . $redirect);
        exit;
    } else {
        $auth_error = 'Incorrect password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Check if already authenticated
if (!empty($_SESSION['authenticated'])) {
    return;
}

// Show login form
http_response_code(401);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; NBA Stats History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); width: 320px; }
        .login-box h1 { font-size: 1.3rem; margin-bottom: 1rem; color: #1d428a; }
        .login-box input[type="password"] { width: 100%; padding: 0.6rem; font-size: 1rem; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 1rem; }
        .login-box button { width: 100%; padding: 0.6rem; font-size: 1rem; background: #1d428a; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .login-box button:hover { background: #163570; }
        .error { color: #c62828; font-size: 0.9rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>NBA Stats History</h1>
        <?php if (!empty($auth_error)): ?>
            <p class="error"><?= htmlspecialchars($auth_error) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="Password" autofocus required>
            <button type="submit">Log in</button>
        </form>
    </div>
</body>
</html>
<?php
exit;
