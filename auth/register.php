<?php
session_start();
$title = 'Register - Mobile2U';
$is_auth_page = true;

require_once __DIR__ . '/../config/database.php';
include __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if (empty($name) || empty($email) || empty($password)) {
        $errors[] = "All fields are required.";
    } elseif ($password !== $password_confirm) {
        $errors[] = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    } else {
        // 检查邮箱是否存在
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        
        if ($check->fetch()) {
            $errors[] = "This email is already registered.";
        } else {
            // 插入新用户 (默认 role 为 member)
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'member')");
            
            if ($stmt->execute([$name, $email, $hashed])) {
                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['role'] = 'member';
                header('Location: /member/home.php');
                exit;
            } else {
                $errors[] = "Registration failed.";
            }
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Create an Account</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): echo htmlspecialchars($error); endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="/auth/register.php" method="POST">
            <div class="form-group"><label>Full Name</label><input type="text" name="name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" name="password_confirm" required></div>
            <button type="submit" class="btn-primary">Sign Up</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>