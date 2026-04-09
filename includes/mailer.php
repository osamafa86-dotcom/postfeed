<?php
/**
 * Minimal mailer helper.
 *
 * Defaults to PHP's built-in mail() — works on most shared hosts
 * (GoDaddy included) without any extra config. Falls back to SMTP
 * over fsockopen if `mail_transport` is set to 'smtp' in the
 * settings table (so admins can opt in to a real SMTP relay later
 * without dragging in PHPMailer).
 *
 * From-name / From-email come from the settings table:
 *   mail_from_email — defaults to noreply@<host>
 *   mail_from_name  — defaults to SITE_NAME
 *
 * Returns true on send success, false otherwise. Errors are written
 * to error_log so cron failures show up in the host control panel.
 */

// Last error from a mailer_send call. Lets the admin "test send"
// button surface the real reason instead of a useless "false".
$GLOBALS['__nf_mail_last_error'] = '';

if (!function_exists('mailer_last_error')) {
    function mailer_last_error(): string {
        return (string)($GLOBALS['__nf_mail_last_error'] ?? '');
    }
}

if (!function_exists('mailer_set_error')) {
    function mailer_set_error(string $msg): void {
        $GLOBALS['__nf_mail_last_error'] = $msg;
        error_log('mailer: ' . $msg);
    }
}

if (!function_exists('mailer_send')) {
    function mailer_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $GLOBALS['__nf_mail_last_error'] = '';
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            mailer_set_error("recipient invalid: '$to'");
            return false;
        }

        $fromEmail = getSetting('mail_from_email', '') ?: ('noreply@' . preg_replace('#^https?://#', '', SITE_URL));
        $fromName  = getSetting('mail_from_name', '')  ?: SITE_NAME;
        $transport = strtolower((string)getSetting('mail_transport', 'mail'));

        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
        }

        // Encode subject + from name as RFC 2047 so Arabic survives
        // older mail servers that aren't 8-bit clean.
        $encSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        if ($transport === 'smtp') {
            return mailer_send_smtp($to, $encSubject, $htmlBody, $textBody, $fromEmail, $encFromName);
        }
        return mailer_send_phpmail($to, $encSubject, $htmlBody, $textBody, $fromEmail, $encFromName);
    }
}

if (!function_exists('mailer_send_phpmail')) {
    function mailer_send_phpmail(string $to, string $encSubject, string $htmlBody, string $textBody, string $fromEmail, string $encFromName): bool {
        $boundary = '=_nf_' . bin2hex(random_bytes(8));
        $headers  = [];
        $headers[] = 'From: ' . $encFromName . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: NewsFlow';

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--$boundary--\r\n";

        // Some hosts block the -f param. Try with it first, fall back
        // without if it returns false (covers both modes safely).
        $ok = @mail($to, $encSubject, $body, implode("\r\n", $headers), '-f' . $fromEmail);
        if (!$ok) {
            $ok = @mail($to, $encSubject, $body, implode("\r\n", $headers));
        }
        if (!$ok) {
            $err = error_get_last();
            $msg = $err && !empty($err['message']) ? $err['message'] : 'mail() returned false';
            mailer_set_error("PHP mail() failed: $msg");
        }
        return (bool)$ok;
    }
}

if (!function_exists('mailer_send_smtp')) {
    /**
     * Tiny SMTP client over fsockopen. AUTH LOGIN only.
     * Settings used:
     *   smtp_host, smtp_port (default 587), smtp_user, smtp_pass,
     *   smtp_secure ('tls' | 'ssl' | '')
     */
    function mailer_send_smtp(string $to, string $encSubject, string $htmlBody, string $textBody, string $fromEmail, string $encFromName): bool {
        $host = (string)getSetting('smtp_host', '');
        $port = (int)getSetting('smtp_port', 587);
        $user = (string)getSetting('smtp_user', '');
        $pass = (string)getSetting('smtp_pass', '');
        $sec  = strtolower((string)getSetting('smtp_secure', 'tls'));
        if ($host === '') { mailer_set_error('smtp_host is empty — fill it in panel/newsletter.php'); return false; }

        $remote = ($sec === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0; $errstr = '';
        $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
        if (!$fp) { mailer_set_error("connect $remote:$port failed: $errstr ($errno)"); return false; }
        stream_set_timeout($fp, 15);

        $expect = function($code) use ($fp) {
            $line = '';
            while (($l = fgets($fp, 515)) !== false) {
                $line .= $l;
                if (isset($l[3]) && $l[3] === ' ') break;
            }
            if ((int)substr($line, 0, 3) !== $code) {
                mailer_set_error("expected $code, got: " . trim($line));
                return false;
            }
            return true;
        };
        $send = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };

        if (!$expect(220)) { fclose($fp); return false; }
        $send('EHLO ' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost'));
        if (!$expect(250)) { fclose($fp); return false; }

        if ($sec === 'tls') {
            $send('STARTTLS');
            if (!$expect(220)) { fclose($fp); return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                mailer_set_error('STARTTLS handshake failed'); fclose($fp); return false;
            }
            $send('EHLO ' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost'));
            if (!$expect(250)) { fclose($fp); return false; }
        }

        if ($user !== '') {
            $send('AUTH LOGIN');
            if (!$expect(334)) { fclose($fp); return false; }
            $send(base64_encode($user));
            if (!$expect(334)) { fclose($fp); return false; }
            $send(base64_encode($pass));
            if (!$expect(235)) { fclose($fp); return false; }
        }

        $send('MAIL FROM:<' . $fromEmail . '>');
        if (!$expect(250)) { fclose($fp); return false; }
        $send('RCPT TO:<' . $to . '>');
        if (!$expect(250)) { fclose($fp); return false; }
        $send('DATA');
        if (!$expect(354)) { fclose($fp); return false; }

        $boundary = '=_nf_' . bin2hex(random_bytes(8));
        $headers  = "From: $encFromName <$fromEmail>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: $encSubject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost') . ">\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--$boundary--\r\n";

        // Dot-stuff any line starting with '.'
        $body = preg_replace('/^\./m', '..', $body);

        fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
        if (!$expect(250)) { fclose($fp); return false; }
        $send('QUIT');
        fclose($fp);
        return true;
    }
}

if (!function_exists('newsletter_email_html')) {
    /**
     * HTML envelope for newsletter emails. Includes header, body slot,
     * and footer with the per-subscriber unsubscribe link.
     */
    function newsletter_email_html(string $title, string $bodyHtml, string $unsubscribeUrl): string {
        $siteName = e(getSetting('site_name', SITE_NAME));
        $brand    = '#1a5c5c';
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<title>' . e($title) . '</title></head>'
             . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Tajawal,Tahoma,Arial,sans-serif;color:#0f172a;">'
             . '<div style="max-width:640px;margin:0 auto;background:#ffffff;">'
             . '<div style="background:' . $brand . ';padding:24px 32px;text-align:center;">'
             . '<div style="color:#fff;font-size:24px;font-weight:800;letter-spacing:.3px;">' . $siteName . '</div>'
             . '<div style="color:#cbd5e1;font-size:13px;margin-top:4px;">' . e($title) . '</div>'
             . '</div>'
             . '<div style="padding:28px 32px;line-height:1.7;font-size:15px;">'
             . $bodyHtml
             . '</div>'
             . '<div style="background:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;text-align:center;color:#64748b;font-size:12px;">'
             . '<div>هذه الرسالة من <a href="' . e(SITE_URL) . '" style="color:' . $brand . ';text-decoration:none;font-weight:700;">' . $siteName . '</a></div>'
             . '<div style="margin-top:8px;">'
             . '<a href="' . e($unsubscribeUrl) . '" style="color:#94a3b8;text-decoration:underline;">إلغاء الاشتراك</a>'
             . '</div>'
             . '</div>'
             . '</div></body></html>';
    }
}
