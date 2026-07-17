<?php
require_once __DIR__ . '/admin_auth.php';

// validate id
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    die("Invalid Member ID.");
}

// validate the user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'member'");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    die("Member not found or you do not have permission to view this user.");
}

$title = 'Member Detail - ' . htmlspecialchars($member['name']);
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Member Detail</h2>
        <a href="members.php" class="btn-outline">&larr; Back to List</a>
    </div>

    <div class="profile-layout mt-4">
        <aside class="profile-sidebar">
            <div class="profile-avatar-section" style="border: none;">
                <?php $avatar = $member['profile_photo'] === 'default-avatar.png' ? 'default-avatar.png' : $member['profile_photo']; ?>
                <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($avatar); ?>" alt="avatar" class="avatar-img">
                <h3 style="margin: 10px 0 5px 0;"><?php echo htmlspecialchars($member['name']); ?></h3>
                <span class="badge">Member</span>
            </div>
        </aside>

        <main class="profile-content">
            <div class="card" style="display: block; padding: 30px;">
                <div class="card-header">
                    <h2>Account Information</h2>
                    <p>System data for this member</p>
                </div>
                
                <div class="form-standard">
                    <div class="form-group">
                        <label>User ID</label>
                        <input type="text" value="#<?php echo $member['id']; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($member['name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($member['email']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Account Created On</label>
                        <input type="text" value="<?php echo date('F j, Y, g:i a', strtotime($member['created_at'])); ?>" readonly>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>