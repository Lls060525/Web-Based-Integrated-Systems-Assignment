<?php
session_start();
$title = 'Register - Mobile2U';
$is_auth_page = true;
include __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    // --- 1. Validation ---
    if (empty($name) || empty($email) || empty($password) || empty($password_confirm)) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    if ($password !== $password_confirm) {
        $errors[] = "Passwords do not match.";
    }

    // --- 2. Database Insertion ---
    if (empty($errors)) {
        // Update credentials if your local DB uses a password
        $conn = new mysqli("127.0.0.1", "root", "", "mobile2u");

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0) {
            $errors[] = "This email is already registered.";
        } else {
            // Hash password and insert
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
            $insert_stmt->bind_param("sss", $name, $email, $hashed_password);

            if ($insert_stmt->execute()) {
                // Grab the automatically generated User ID from the database
                $new_user_id = $insert_stmt->insert_id;

                // Set the session and redirect to dashboard (Auto-login)
                $_SESSION['user_id'] = $new_user_id;
                header('Location: /dashboard/home.php');
                exit;
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
        $conn->close();
    }
}
?>

    <div class="auth-container">
        <div class="auth-card">
            <h2>Create an Account</h2>

            <?php if (!empty($errors)): ?>
                <div class="error-messages" style="background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px;">
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="/authorization/register.php" method="POST">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="John Doe" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="john@example.com" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a strong password" required>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" placeholder="Confirm your password" required>
                </div>

                <button type="submit" class="btn-primary">Sign Up</button>
            </form>

            <div class="auth-links">
                Already have an account? <a href="/authorization/login.php">Log in here</a>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../includes/footer.php'; ?>