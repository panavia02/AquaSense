<?php
/**
 * Minimal email sending wrapper around PHP's built-in mail().
 *
 * HEADS UP: PHP's mail() relies on the server having a working local MTA
 * (sendmail/postfix) configured, and mail sent this way is very often
 * flagged as spam or rejected outright by Gmail/Outlook/etc. because it
 * doesn't carry SPF/DKIM authentication for your domain.
 *
 * TODO: for anything beyond quick testing, swap this out for a real transactional
 * email service over SMTP (e.g. PHPMailer + your host's SMTP credentials,
 * or a service like Postmark/SendGrid/Mailgun/Amazon SES). The rest of the
 * app only calls sendEmail() below, so that's the one function you'd need
 * to change.
 */

function sendEmail(string $to, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";

    $sent = mail($to, $subject, $htmlBody, $headers);

    if (!$sent && ini_get('display_errors')) {
        error_log("sendEmail() failed to send to $to with subject '$subject'");
    }

    return $sent;
}

function verificationEmailBody(string $username, string $link): string {
    $safeLink = htmlspecialchars($link, ENT_QUOTES);
    return "<p>Hi $username,</p>"
        . "<p>Please confirm your email address for your quaSense account by clicking the link below:</p>"
        . "<p><a href=\"$safeLink\">Verify my email</a></p>"
        . "<p>This link expires in 24 hours. If you didn't request this, you can ignore this email.</p>";
}

function passwordResetEmailBody(string $username, string $link): string {
    $safeLink = htmlspecialchars($link, ENT_QUOTES);
    return "<p>Hi $username,</p>"
        . "<p>We received a request to reset your quaSense password. Click the link below to choose a new one:</p>"
        . "<p><a href=\"$safeLink\">Reset my password</a></p>"
        . "<p>This link expires in 1 hour. If you didn't request this, you can safely ignore this email — your password won't be changed.</p>";
}
