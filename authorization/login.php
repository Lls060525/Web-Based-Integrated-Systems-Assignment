<?php
session_start();

// If user is already logged in, push them to the dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/home.php');
    exit;
}

$title = 'Login - Mobile2U';
$is_auth_page = true;
include __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // --- 1. Validation ---
    if (empty($email) || empty($password)) {
        $errors[] = "Please fill in both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } else {
        // --- 2. Database Check ---
        $conn = new mysqli("127.0.0.1", "root", "", "mobile2u");

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        // 【修改 1】：在 SELECT 中加入 role 字段
        $stmt = $conn->prepare("SELECT id, password_hash, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password_hash'])) {
                // Success! Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role']; // 【修改 2】：把角色存入 Session 供全局调用

                // 【修改 3】：智能路由 - Admin 去 admin 包，Member 去 member 包
                if ($user['role'] === 'admin') {
                    header('Location: /admin/members.php');
                } else {
                    header('Location: /member/home.php');
                }
                exit;
            } else {
                $errors[] = "Invalid password.";
            }
        } else {
            $errors[] = "No account found with that email.";
        }

        $stmt->close();
        $conn->close();
    }
}
?>

    <div class="auth-container">
        <div class="auth-card">
            <h2>Welcome Back</h2>

            <?php if (!empty($errors)): ?>
                <div class="error-messages" style="background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px;">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="/authorization/login.php" method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="john@example.com" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-primary">Log In</button>
            </form>

            <div class="auth-links">
                Don't have an account? <a href="/authorization/register.php">Sign up here</a>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../includes/footer.php'; ?>