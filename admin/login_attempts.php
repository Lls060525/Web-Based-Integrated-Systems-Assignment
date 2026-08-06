<?php
// ============================================================
// admin/login_attempts.php - Login security (Admin)
//
// Shows who is currently locked out, lets an admin release a lock,
// and keeps an audit trail of every sign-in attempt.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$title = 'Login Security - Admin';

if (!login_guard_ready()) {
    flash_error('Login blocking is not available yet: run database/migration_11_login_blocking.sql.');
    redirect('/admin/dashboard.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'unlock') {
        $email = post('email');

        if ($email === '') {
            flash_error('Invalid request.');
        } else {
            $cleared = clear_login_attempts($email);
            flash_success('Unlocked ' . $email . '. ' . $cleared . ' failed attempt(s) cleared.');
        }

    } elseif ($action === 'prune') {
        $removed = prune_login_attempts();
        flash_success($removed . ' record(s) older than '
            . LOGIN_ATTEMPT_RETENTION_DAYS . ' days removed.');

    } else {
        flash_error('Invalid request.');
    }

    redirect('/admin/login_attempts.php');
}

$q       = get('q');
$locked  = locked_accounts();
$recent  = recent_login_attempts(100, $q);

$stats = db_one(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successes,
        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures
     FROM login_attempts
     WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
);

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Login Security</h2>

        <form action="/admin/login_attempts.php" method="POST" class="inline-form"
              data-confirm="Delete audit records older than <?= LOGIN_ATTEMPT_RETENTION_DAYS ?> days?">
            <?php csrf_field(); ?>
            <?php html_hidden('action', 'prune'); ?>
            <?php html_submit('Prune Old Records', ['class' => 'btn-outline']); ?>
        </form>
    </div>

    <!-- Policy summary -->
    <div class="alert alert-info mt-4">
        <strong>Current policy.</strong>
        An account locks after <strong><?= LOGIN_MAX_ATTEMPTS ?></strong> failed attempts
        within <?= LOGIN_ATTEMPT_WINDOW_MINUTES ?> minutes, and stays locked for
        <strong><?= LOGIN_LOCKOUT_MINUTES ?> minutes</strong> after the last failure.
        A single device is blocked after <?= LOGIN_MAX_ATTEMPTS_PER_IP ?> failures.
        A successful sign-in clears the count. Change these in <code>lib/config.php</code>.
    </div>

    <!-- Last 24 hours -->
    <div class="stat-grid">
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-right-to-bracket"></i></span>
            <span class="stat-value"><?= (int)$stats['total'] ?></span>
            <span class="stat-label">Attempts, Last 24 Hours</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-circle-check"></i></span>
            <span class="stat-value"><?= (int)$stats['successes'] ?></span>
            <span class="stat-label">Successful</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-circle-xmark"></i></span>
            <span class="stat-value"><?= (int)$stats['failures'] ?></span>
            <span class="stat-label">Failed</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-icon"><i class="fas fa-lock"></i></span>
            <span class="stat-value"><?= count($locked) ?></span>
            <span class="stat-label">Locked Right Now</span>
        </div>
    </div>

    <!-- Currently locked -->
    <div class="card mt-4">
        <div class="card-header card-padded">
            <h3>Currently Locked Accounts</h3>
            <p class="muted small-note">
                These unlock by themselves once the lockout period passes. Use Unlock
                only when a genuine user is stuck.
            </p>
        </div>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Account</th>
                        <th>Failed Attempts</th>
                        <th>Last Attempt</th>
                        <th>Last IP</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($locked) === 0): ?>
                    <tr><td colspan="6" class="table-empty">No accounts are locked.</td></tr>
                <?php else: ?>
                    <?php foreach ($locked as $row): ?>
                        <tr>
                            <td><strong><?= e($row['email']) ?></strong></td>
                            <td>
                                <?php if (!empty($row['user_id'])): ?>
                                    <a href="/admin/member_detail.php?id=<?= (int)$row['user_id'] ?>">
                                        <?= e($row['user_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted">No such account</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-danger"><?= (int)$row['fails'] ?></span></td>
                            <td><?= e(fmt_datetime($row['last_attempt'])) ?></td>
                            <td><code><?= e($row['last_ip']) ?></code></td>
                            <td>
                                <form action="/admin/login_attempts.php" method="POST" class="inline-form"
                                      data-confirm="Unlock <?= e($row['email']) ?> now?">
                                    <?php csrf_field(); ?>
                                    <?php html_hidden('action', 'unlock'); ?>
                                    <?php html_hidden('email', $row['email']); ?>
                                    <?php html_submit('Unlock', ['class' => 'btn-outline btn-sm btn-success']); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Audit trail -->
    <div class="card mt-4">
        <div class="card-header card-padded section-head">
            <h3>Recent Attempts</h3>

            <form action="/admin/login_attempts.php" method="GET" class="admin-search-form">
                <input type="text" name="q" value="<?= e($q) ?>"
                       placeholder="Filter by email or IP..." class="admin-search-input">
                <?php html_submit('Search'); ?>
                <?php if ($q !== ''): ?>
                    <a href="/admin/login_attempts.php" class="btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Email</th>
                        <th>IP Address</th>
                        <th>Result</th>
                        <th>Browser</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($recent) === 0): ?>
                    <tr><td colspan="5" class="table-empty">No attempts recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent as $a): ?>
                        <tr class="<?= (int)$a['success'] === 1 ? '' : 'row-muted' ?>">
                            <td><?= e(fmt_datetime($a['attempted_at'])) ?></td>
                            <td><?= e($a['email']) ?></td>
                            <td><code><?= e($a['ip_address']) ?></code></td>
                            <td>
                                <?php if ((int)$a['success'] === 1): ?>
                                    <span class="badge badge-success">Success</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted small-note ua-cell"><?= e($a['user_agent'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
