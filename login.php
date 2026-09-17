<?php
session_start();
require 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$registered = isset($_GET['registered']);
$username = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                $_SESSION['user_id'] = $row['id'];
                header("Location: dashboard.php");
                exit;
            }
        }
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Accident Alerts</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">🛰️</div>
      <h1>Welcome Back</h1>
      <p class="login-sub">Sign in to the accident alert command center</p>

      <?php if ($error): ?>
        <div class="alert-msg alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($registered): ?>
        <div class="alert-msg" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;">✅ Account created successfully. Please sign in.</div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required autofocus>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary login-btn">Sign In</button>
      </form>

      <p class="login-footer">New to the system? <a href="register.php" style="color:var(--primary);font-weight:600;">Create an account</a></p>
    </div>
  </div>
</body>
</html>
