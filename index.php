<?php
// index.php - Login page
// Uses: Singleton (Database), Factory (UserFactory)

session_start();

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'backend/patterns/Database.php';
require_once 'backend/patterns/UserFactory.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db   = Database::getInstance()->conn;  // Singleton
    $stmt = $db->prepare(
        "SELECT * FROM users WHERE email = ? AND password = ?"
    );
    $stmt->bind_param('ss', $_POST['email'], $_POST['password']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        // Factory creates the correct User subclass based on role
        // UserFactory reads $row['role'] and returns Student/Instructor/Admin
        $userObj = UserFactory::create($row);

        // Store the raw DB row in session (simpler for PHP sessions)
        $_SESSION['user'] = $row;
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Incorrect email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Course Management System - Login</title>
<style>
  body {
    font-family: Arial, sans-serif;
    background: #f0f2f5;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    margin: 0;
  }
  .login-box {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    padding: 32px 36px;
    width: 340px;
  }
  h2 {
    margin: 0 0 4px;
    font-size: 1.3rem;
    color: #222;
  }
  .subtitle {
    color: #666;
    font-size: 0.85rem;
    margin-bottom: 24px;
  }
  label {
    display: block;
    font-size: 0.82rem;
    font-weight: bold;
    color: #444;
    margin-bottom: 4px;
    margin-top: 14px;
  }
  input[type=email], input[type=password] {
    width: 100%;
    padding: 9px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 0.9rem;
    box-sizing: border-box;
  }
  input[type=email]:focus, input[type=password]:focus {
    outline: none;
    border-color: #3a6bc8;
  }
  .btn-login {
    width: 100%;
    margin-top: 20px;
    padding: 10px;
    background: #3a6bc8;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 0.95rem;
    cursor: pointer;
  }
  .btn-login:hover { background: #2f58a8; }
  .error {
    background: #fdecea;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 9px 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    margin-bottom: 12px;
  }
  .demo-accounts {
    margin-top: 22px;
    border-top: 1px solid #eee;
    padding-top: 14px;
  }
  .demo-accounts p {
    font-size: 0.78rem;
    color: #888;
    margin: 0 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .demo-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
  }
  .demo-btn {
    flex: 1;
    margin: 0 3px;
    padding: 6px 4px;
    background: #f5f7fb;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
    text-align: center;
  }
  .demo-btn:hover { background: #e8edf7; }
</style>
</head>
<body>

<div class="login-box">
  <h2>Course Management System</h2>
  <p class="subtitle">Design Patterns Demo &mdash; Login to continue</p>

  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" id="loginForm">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" placeholder="email@example.com" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" placeholder="password" required>

    <button type="submit" class="btn-login">Log In</button>
  </form>

  <div class="demo-accounts">
    <p>Test accounts (all passwords: 123)</p>
    <div class="demo-row">
      <button class="demo-btn" onclick="fill('admin@test.com','123')">Admin</button>
      <button class="demo-btn" onclick="fill('inst@test.com','123')">Instructor</button>
      <button class="demo-btn" onclick="fill('alice@test.com','123')">Alice</button>
      <button class="demo-btn" onclick="fill('bob@test.com','123')">Bob</button>
    </div>
  </div>
</div>

<script>
function fill(email, pass) {
  document.getElementById('email').value    = email;
  document.getElementById('password').value = pass;
}
</script>

</body>
</html>
