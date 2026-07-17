<?php

require_once __DIR__ . '/admin_auth.php';

$title = 'Member Management - Admin';

$search_query = trim($_GET['q'] ?? '');

$sql = "SELECT id, name, email, profile_photo, created_at FROM users WHERE role = 'member'";
$params = [];

if ($search_query !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ?)";
    $search_term = "%{$search_query}%";
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY id ASC"; 

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// see if it is AJAX if yes then no need reload the pag
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    
    if (count($members) > 0) {
        foreach ($members as $user) {
            $avatar = $user['profile_photo'] === 'default-avatar.png' ? 'default-avatar.png' : $user['profile_photo'];
            echo '<tr>';
            echo '<td>#' . $user['id'] . '</td>';
            echo '<td><img src="/assets/uploads/avatars/' . htmlspecialchars($avatar) . '" alt="avatar" class="table-avatar"></td>';
            echo '<td><strong>' . htmlspecialchars($user['name']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($user['email']) . '</td>';
            echo '<td>' . date('d M Y', strtotime($user['created_at'])) . '</td>';
            echo '<td><a href="/admin/member_detail.php?id=' . $user['id'] . '" class="btn-outline btn-sm">View Detail</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">No members found.</td></tr>';
    }
    exit; 
}


include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Member Management</h2>
        
        <form action="members.php" method="GET" class="admin-search-form">
            <input type="text" name="q" placeholder="Search by name or email..." 
                   value="<?php echo htmlspecialchars($search_query); ?>" class="admin-search-input">
            <button type="submit" class="btn-primary">Search</button>
            <?php if($search_query): ?>
                <a href="members.php" class="btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card mt-4">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Joined Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($members) > 0): ?>
                        <?php foreach ($members as $user): ?>
                            <tr>
                                <td>#<?php echo $user['id']; ?></td>
                                <td>
                                    <?php $avatar = $user['profile_photo'] === 'default-avatar.png' ? 'default-avatar.png' : $user['profile_photo']; ?>
                                    <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($avatar); ?>" alt="avatar" class="table-avatar">
                                </td>
                                <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <a href="member_detail.php?id=<?php echo $user['id']; ?>" class="btn-outline btn-sm">View Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding: 30px; color: var(--text-muted);">
                                No members found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>