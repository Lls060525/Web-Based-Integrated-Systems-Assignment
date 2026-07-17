<?php
session_start();


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");


if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$title = 'Login - Mobile2U';
$is_auth_page = true;


require_once __DIR__ . '/../config/database.php';
include __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $errors[] = "Please fill in both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } else {
        
        $stmt = $pdo->prepare("SELECT id, password_hash, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];

           
            if ($user['role'] === 'admin') {
                header('Location: /admin/members.php');
            } else {
                header('Location: /member/home.php');
            }
            exit;
        } else {
            $errors[] = "Invalid email or password.";
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <h2>Welcome Back</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): echo htmlspecialchars($error); endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="/auth/login.php" method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-primary">Log In</button>
        </form>
        <div class="auth-links">Don't have an account? <a href="/auth/register.php">Sign up here</a></div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>