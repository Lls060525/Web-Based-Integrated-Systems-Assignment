<?php
// ============================================================
// lib/mailer.php
// Outbound mail with a single switch between two modes:
//
//   MAIL_MODE = 'dev'   the message is written to a log file and
//                       the reset link is returned so the page can
//                       display it. No SMTP server required, so the
//                       demo can never fail because of the network.
//
//   MAIL_MODE = 'prod'  the message is sent over SMTP with PHPMailer.
//
// Change MAIL_MODE in lib/config.php - no page code changes.
// ============================================================

/**
 * Send an email.
 *
 * @param string[] $attachments absolute paths of files to attach
 *
 * @return array{sent: bool, mode: string, error: ?string}
 */
function send_mail(string $to, string $subject, string $htmlBody, array $attachments = []): array
{
    if (MAIL_MODE !== 'prod') {
        return send_mail_dev($to, $subject, $htmlBody, $attachments);
    }
    return send_mail_smtp($to, $subject, $htmlBody, $attachments);
}

/** Development transport: append the message to storage/mail.log. */
function send_mail_dev(string $to, string $subject, string $htmlBody, array $attachments = []): array
{
    $dir = DIR_ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $entry = str_repeat('=', 70) . PHP_EOL
           . 'Date   : ' . date('Y-m-d H:i:s') . PHP_EOL
           . 'To     : ' . $to . PHP_EOL
           . 'Subject: ' . $subject . PHP_EOL;

    if (count($attachments) > 0) {
        $entry .= 'Attach : ' . implode(', ', array_map('basename', $attachments)) . PHP_EOL;
    }

    $entry .= str_repeat('-', 70) . PHP_EOL
           . strip_tags($htmlBody) . PHP_EOL . PHP_EOL;

    @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);

    return ['sent' => true, 'mode' => 'dev', 'error' => null];
}

/** Production transport: PHPMailer over SMTP. */
function send_mail_smtp(string $to, string $subject, string $htmlBody, array $attachments = []): array
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return [
            'sent'  => false,
            'mode'  => 'prod',
            'error' => 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer',
        ];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        foreach ($attachments as $path) {
            if (is_file($path)) {
                $mail->addAttachment($path);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();

        return ['sent' => true, 'mode' => 'prod', 'error' => null];

    } catch (\Throwable $e) {
        error_log('Mail send failed: ' . $e->getMessage());
        return ['sent' => false, 'mode' => 'prod', 'error' => $e->getMessage()];
    }
}

/**
 * Send the password-reset link to a user.
 *
 * @return array{sent: bool, mode: string, error: ?string, link: string}
 *         In 'dev' mode the caller displays ['link'] on screen.
 */
function send_reset_link(string $email, string $name, string $token): array
{
    $link = reset_url($token);
    $mins = (int)(RESET_TOKEN_TTL / 60);

    $body = '<p>Hi ' . e($name) . ',</p>'
          . '<p>We received a request to reset the password for your '
          . e(APP_NAME) . ' account.</p>'
          . '<p><a href="' . e($link) . '">Click here to choose a new password</a></p>'
          . '<p>Or paste this address into your browser:<br>' . e($link) . '</p>'
          . '<p>This link expires in ' . $mins . ' minutes and can only be used once.</p>'
          . '<p>If you did not request a password reset, you can safely ignore this email.</p>';

    $result = send_mail($email, 'Reset your ' . APP_NAME . ' password', $body);
    $result['link'] = $link;

    return $result;
}
