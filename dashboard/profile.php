<?php
session_start();

// 【安全衔接点】未来与 Login 模块对接：
// 如果没有登录，跳转到登录页。这里为了你现在能测试，我们先强制模拟一个登录状态。
if (!isset($_SESSION['user_id'])) {
    // TODO: 之后改成 header('Location: /login.php'); exit;
    $_SESSION['user_id'] = 1; // 临时模拟 ID 为 1 的用户已登录
}

$user_id = $_SESSION['user_id'];
$title = 'My Profile - Mobile2U';

// 1. 数据库连接 (PDO) - 建议以后抽取到单独的 db.php
$host = '127.0.0.1';
$db   = 'mobile2u'; // 替换为你的数据库名
$user = 'root';
$pass = ''; // 你的数据库密码
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

$success_msg = '';
$error_msg = '';

// 2. 处理表单提交 (使用 PRG 模式与 Session Flash Message)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- A. 更新基本资料 ---
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $_SESSION['error_msg'] = 'Name and Email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error_msg'] = 'Invalid email format.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            if ($stmt->execute([$name, $email, $user_id])) {
                $_SESSION['success_msg'] = 'Profile updated successfully.';
            }
        }
    }

    // --- B. 更新密码 ---
    if ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password)) {
            $_SESSION['error_msg'] = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error_msg'] = 'New password and confirm password do not match.';
        } elseif (strlen($new_password) < 6) {
            $_SESSION['error_msg'] = 'New password must be at least 6 characters.';
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch();

            if (password_verify($current_password, $user_data['password_hash'])) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update_stmt->execute([$new_hash, $user_id]);
                $_SESSION['success_msg'] = 'Password changed successfully.';
            } else {
                $_SESSION['error_msg'] = 'Incorrect current password.';
            }
        }
    }

    // --- C. 上传头像 ---
    if ($action === 'upload_photo' && isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $max_size = 2 * 1024 * 1024; // 2MB
            $upload_dir = __DIR__ . '/../assets/uploads/avatars/';
            
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            if ($file['size'] > $max_size) {
                $_SESSION['error_msg'] = 'File size exceeds the 2MB limit.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                
                if (in_array($mime_type, $allowed_mimes)) {
                    $ext = match ($mime_type) {
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                    };

                    $new_filename = 'avatar_uid' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destination = $upload_dir . $new_filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        
                        $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $old_photo = $stmt->fetchColumn();
                        
                        if ($old_photo && $old_photo !== 'default-avatar.png') {
                            $old_path = $upload_dir . $old_photo;
                            if (file_exists($old_path)) unlink($old_path);
                        }

                        $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                        $stmt->execute([$new_filename, $user_id]);
                        
                        $_SESSION['success_msg'] = 'Profile photo updated successfully.';
                    } else {
                        $_SESSION['error_msg'] = 'Server Error: Failed to save the uploaded file.';
                    }
                } else {
                    $_SESSION['error_msg'] = 'Security Alert: Invalid file format.';
                }
            }
        } else {
            $_SESSION['error_msg'] = 'Error uploading file. Code: ' . $file['error'];
        }
    }

    // ==========================================
    // 核心安全逻辑：PRG Pattern (重定向以清除 POST 状态)
    // ==========================================
    header('Location: profile.php');
    exit;
}

// ==========================================
// 提取闪存消息 (Flash Messages) 并立刻销毁
// ==========================================
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// 3. 获取当前用户最新数据渲染页面
$stmt = $pdo->prepare("SELECT name, email, role, profile_photo FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$currentUser = $stmt->fetch();

// 处理默认头像
$avatar_path = !empty($currentUser['profile_photo']) && $currentUser['profile_photo'] !== 'default-avatar.png' 
    ? '/assets/uploads/avatars/' . htmlspecialchars($currentUser['profile_photo']) 
    : '/assets/images/default-avatar.png'; // 你可以在 images 放一个默认图片

include __DIR__ . '/../includes/header.php';
?>

<div class="profile-container">
    
    <?php if ($success_msg): ?>
        <div class="toast-message toast-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="toast-message toast-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="profile-layout">
        
        <aside class="profile-sidebar">
            <div class="profile-avatar-section">
                <img src="<?php echo $avatar_path; ?>" alt="Profile Photo" id="avatarPreview" class="avatar-img">
                <form action="profile.php" method="POST" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="action" value="upload_photo">
                    <label class="btn-outline" for="photoInput">Select Image</label>
                    <input type="file" id="photoInput" name="profile_photo" accept="image/*" style="display:none;">
                    <button type="submit" class="btn-primary btn-sm mt-2" id="uploadBtn" style="display:none;">Upload</button>
                </form>
            </div>
            <nav class="profile-nav">
                <a href="#profile-info" class="active">My Profile</a>
                <a href="#profile-password">Change Password</a>
                <a href="/orders.php">My Orders</a> </nav>
        </aside>

        <main class="profile-content">
            
            <div class="card" id="profile-info">
                <div class="card-header">
                    <h2>My Profile</h2>
                    <p>Manage and protect your account</p>
                </div>
                <div class="card-body">
                    <form action="profile.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name" id="profileName" 
                                   value="<?php echo htmlspecialchars($currentUser['name']); ?>" 
                                   data-original="<?php echo htmlspecialchars($currentUser['name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="profileEmail" 
                                   value="<?php echo htmlspecialchars($currentUser['email']); ?>" 
                                   data-original="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" value="<?php echo htmlspecialchars(ucfirst($currentUser['role'])); ?>" readonly>
                        </div>
                        
                        <div class="form-actions text-right">
                            <button type="submit" class="btn-primary" id="saveProfileBtn" style="display: none;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-4" id="profile-password">
                <div class="card-header">
                    <h2>Change Password</h2>
                    <p>For your account's security, do not share your password with anyone else</p>
                </div>
                <div class="card-body">
                    <form action="profile.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="update_password">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required>
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn-primary">Update Password</button>
                    </form>
                </div>
            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>