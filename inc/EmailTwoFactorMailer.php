<?php

use PHPMailer\PHPMailer\PHPMailer;

class EmailTwoFactorMailer {
    public static function sendCode(array $settings, array $user, string $code, int $ttlMinutes): void {
        $appName = Branding::get(Config::get('APP_NAME', 'AWG Control Panel'))['app_name'];
        $name = trim((string) ($user['name'] ?? ''));
        $recipient = strtolower(trim((string) ($user['email'] ?? '')));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The user does not have a valid email address');
        }

        $subject = $appName . ': код подтверждения входа';
        $safeName = htmlspecialchars($name !== '' ? $name : $recipient, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeApp = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $html = '<p>Здравствуйте, ' . $safeName . '.</p>'
            . '<p>Код для входа в ' . $safeApp . ':</p>'
            . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . $safeCode . '</p>'
            . '<p>Код действует ' . $ttlMinutes . ' минут. Если вы не пытались войти, просто проигнорируйте это письмо.</p>';
        $text = "Код для входа в {$appName}: {$code}\n\n"
            . "Код действует {$ttlMinutes} минут. Если вы не пытались войти, проигнорируйте это письмо.";

        self::send($settings, $recipient, $name, $subject, $html, $text);
    }

    public static function sendTest(array $settings, string $recipient): void {
        $appName = Branding::get(Config::get('APP_NAME', 'AWG Control Panel'))['app_name'];
        $safeApp = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        self::send(
            $settings,
            $recipient,
            '',
            $appName . ': проверка SMTP',
            '<p>Тестовое письмо от ' . $safeApp . ' успешно отправлено.</p>'
                . '<p>Теперь двухфакторную авторизацию можно включить в настройках панели.</p>',
            "Тестовое письмо от {$appName} успешно отправлено.\n"
                . "Теперь двухфакторную авторизацию можно включить в настройках панели."
        );
    }

    private static function send(
        array $settings,
        string $recipient,
        string $recipientName,
        string $subject,
        string $html,
        string $text
    ): void {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = (string) $settings['smtp_host'];
        $mail->Port = (int) $settings['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $settings['smtp_username'];
        $mail->Password = (string) $settings['smtp_password'];
        $mail->Timeout = 15;
        $mail->SMTPDebug = 0;
        $mail->SMTPSecure = $settings['smtp_encryption'] === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->setFrom((string) $settings['from_email'], (string) $settings['from_name']);
        $mail->addAddress($recipient, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    }
}
