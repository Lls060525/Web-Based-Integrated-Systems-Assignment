<?php
// ============================================================
// admin/mail_test.php - E-Receipt diagnostics
//
// Shows exactly which piece of the email + PDF pipeline is ready
// and which is not, and lets an admin fire a real test message.
// Use this instead of guessing why a receipt did not arrive.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

$title = 'E-Receipt Diagnostics - Admin';

$testResult = null;

if (is_post()) {
    csrf_check();

    $to = post('to');

    if (v_required('to', $to, 'Email address')) {
        v_email('to', $to);
    }

    if (no_err()) {
        $body = '<p>This is a test message from ' . e(APP_NAME) . '.</p>'
              . '<p>If you are reading this in an inbox, SMTP is configured correctly.</p>'
              . '<p>Sent at ' . e(date('Y-m-d H:i:s')) . '.</p>';

        // Attach a sample PDF when Dompdf is available, so the test
        // proves the attachment path works too.
        $attachments = [];
        $sampleOrder = db_value('SELECT id FROM orders ORDER BY id DESC LIMIT 1');

        if ($sampleOrder && pdf_engine_available()) {
            $data = receipt_data((int)$sampleOrder);
            if ($data !== null) {
                $path = store_receipt_pdf($data);
                if ($path !== null) {
                    $attachments[] = $path;
                }
            }
        }

        $testResult = send_mail($to, APP_NAME . ' - SMTP test', $body, $attachments);
        $testResult['attached'] = count($attachments) > 0;
    }
}

// ---------- Environment checks ----------
$checks = [
    [
        'label' => 'Composer autoloader loaded',
        'ok'    => class_exists(\Composer\Autoload\ClassLoader::class),
        'hint'  => 'Run "composer install" in the project folder.',
    ],
    [
        'label' => 'Dompdf installed (PDF generation)',
        'ok'    => pdf_engine_available(),
        'hint'  => 'Run: composer require dompdf/dompdf',
    ],
    [
        'label' => 'PHPMailer installed (SMTP sending)',
        'ok'    => class_exists(\PHPMailer\PHPMailer\PHPMailer::class),
        'hint'  => 'Run: composer require phpmailer/phpmailer',
    ],
    [
        'label' => 'PHP openssl extension enabled',
        'ok'    => extension_loaded('openssl'),
        'hint'  => 'Uncomment ";extension=openssl" in php.ini, then restart Apache.',
    ],
    [
        'label' => 'MAIL_MODE is "prod" (really sends email)',
        'ok'    => MAIL_MODE === 'prod',
        'hint'  => 'Currently "' . MAIL_MODE . '". Messages are written to storage/mail.log instead of being sent. '
                 . 'Change MAIL_MODE in lib/config.php to start sending for real.',
        'warn'  => true,   // not an error, just not sending yet
    ],
    [
        'label' => 'From address matches the SMTP account',
        'ok'    => MAIL_MODE !== 'prod' || MAIL_FROM === SMTP_USER,
        'hint'  => 'Gmail rejects a From address that is not the authenticated account.',
    ],
    [
        'label' => 'storage/receipts is writable',
        'ok'    => (is_dir(DIR_RECEIPTS) && is_writable(DIR_RECEIPTS))
                   || (!is_dir(DIR_RECEIPTS) && is_writable(dirname(DIR_RECEIPTS))),
        'hint'  => 'Give the web server write permission to ' . DIR_RECEIPTS,
    ],
    [
        'label' => 'Receipt columns exist in the database',
        'ok'    => receipt_columns_ready(),
        'hint'  => 'Run database/migration_password_reset.sql in phpMyAdmin.',
    ],
];

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container admin-container-narrow">
    <div class="admin-header">
        <h2>E-Receipt Diagnostics</h2>
        <a href="/admin/orders.php" class="btn-outline">&larr; Back to Orders</a>
    </div>

    <?php err_summary(); ?>

    <?php if ($testResult !== null): ?>
        <?php if ($testResult['sent'] && $testResult['mode'] === 'prod'): ?>
            <div class="alert alert-success">
                <strong>Sent.</strong> The message left the server
                <?= $testResult['attached'] ? 'with a PDF attached' : 'without an attachment' ?>.
                Check the inbox, and the spam folder if it is not there.
            </div>
        <?php elseif ($testResult['sent']): ?>
            <div class="alert alert-info">
                <strong>Written to the log, not sent.</strong>
                MAIL_MODE is <code>dev</code>, so the message went to
                <code>storage/mail.log</code>. Set it to <code>prod</code> to send for real.
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                <strong>Send failed.</strong>
                <?= e($testResult['error'] ?? 'No detail was returned.') ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card card-padded">
        <h3>Environment</h3>
        <p class="muted small-note">Everything below must be green before a receipt can reach an inbox.</p>

        <ul class="check-list">
            <?php foreach ($checks as $c): ?>
                <li class="check-item <?= $c['ok'] ? 'is-ok' : (!empty($c['warn']) ? 'is-warn' : 'is-bad') ?>">
                    <span class="check-icon">
                        <i class="fas <?= $c['ok'] ? 'fa-check' : (!empty($c['warn']) ? 'fa-circle-exclamation' : 'fa-xmark') ?>"></i>
                    </span>
                    <div>
                        <strong><?= e($c['label']) ?></strong>
                        <?php if (!$c['ok']): ?>
                            <div class="muted small-note"><?= e($c['hint']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card card-padded mt-4">
        <h3>Current settings</h3>
        <dl class="detail-list">
            <dt>MAIL_MODE</dt>
            <dd><code><?= e(MAIL_MODE) ?></code></dd>

            <dt>From</dt>
            <dd><code><?= e(MAIL_FROM) ?></code> (<?= e(MAIL_FROM_NAME) ?>)</dd>

            <dt>SMTP host</dt>
            <dd><code><?= e(SMTP_HOST) ?>:<?= (int)SMTP_PORT ?></code> over <?= e(strtoupper(SMTP_SECURE)) ?></dd>

            <dt>SMTP user</dt>
            <dd><code><?= e(SMTP_USER) ?></code></dd>

            <dt>SMTP password</dt>
            <dd><code><?= SMTP_PASS === '' ? 'not set' : str_repeat('*', 12) ?></code></dd>
        </dl>
    </div>

    <div class="card card-padded mt-4">
        <h3>Send a test message</h3>
        <p class="muted small-note">
            Attaches a PDF of the most recent order when Dompdf is installed,
            so this exercises the same path a real receipt takes.
        </p>

        <form action="/admin/mail_test.php" method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('to', 'Send to', function () {
                html_email('to', current_user()['email'] ?? '', ['required' => true]);
            }, true); ?>

            <?php html_submit('Send Test Message'); ?>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
