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

    public static function sendConnection(array $settings, array $user, array $connection): void {
        $message = self::buildConnectionMessage($user, $connection);
        self::send(
            $settings,
            $message['recipient'],
            $message['recipient_name'],
            $message['subject'],
            $message['html'],
            $message['text'],
            $message['attachments'],
            $message['inline_images']
        );
    }

    public static function buildConnectionMessage(array $user, array $connection): array {
        $recipient = strtolower(trim((string) ($user['email'] ?? '')));
        $recipientName = trim((string) ($user['name'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('У владельца подключения не указан корректный адрес электронной почты');
        }

        $connectionName = trim((string) ($connection['name'] ?? ''));
        $config = (string) ($connection['config'] ?? '');
        if ($connectionName === '' || $config === '') {
            throw new RuntimeException('Название подключения и конфигурация обязательны для отправки письма');
        }

        $safeFileName = preg_replace('/[^A-Za-z0-9_-]/', '_', $connectionName);
        if (!is_string($safeFileName) || $safeFileName === '') {
            throw new RuntimeException('Не удалось сформировать имя файла конфигурации');
        }

        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $displayName = $recipientName !== '' ? $recipientName : $recipient;
        $serverName = trim((string) ($connection['server_name'] ?? ''));
        $protocolName = trim((string) ($connection['protocol_name'] ?? ''));
        $clientIp = trim((string) ($connection['client_ip'] ?? ''));

        $details = [
            'Название подключения' => $connectionName,
            'Сервер' => $serverName,
            'Протокол' => $protocolName,
            'IP-адрес' => $clientIp,
        ];
        $htmlDetails = '';
        $textDetails = [];
        foreach ($details as $label => $value) {
            if ($value === '') {
                continue;
            }
            $htmlDetails .= '<tr><td style="padding:6px 16px 6px 0;color:#64748b">' . $escape($label)
                . '</td><td style="padding:6px 0;font-weight:600">' . $escape($value) . '</td></tr>';
            $textDetails[] = $label . ': ' . $value;
        }

        $inlineImages = [];
        $qrHtml = '';
        $qrCount = 0;
        foreach (($connection['qr_codes'] ?? []) as $qrCode) {
            if (!is_array($qrCode)) {
                continue;
            }
            $decoded = self::decodeImageDataUri((string) ($qrCode['data_uri'] ?? ''));
            if ($decoded === null) {
                continue;
            }
            $qrCount++;
            $cid = 'connection-qr-' . $qrCount;
            $label = trim((string) ($qrCode['label'] ?? 'QR-код')) ?: 'QR-код';
            $extension = $decoded['mime'] === 'image/svg+xml' ? 'svg' : 'png';
            $inlineImages[] = [
                'content' => $decoded['content'],
                'cid' => $cid,
                'name' => $safeFileName . '-qr-' . $qrCount . '.' . $extension,
                'mime' => $decoded['mime'],
            ];
            $qrHtml .= '<div style="display:inline-block;vertical-align:top;margin:12px 20px 12px 0;text-align:center">'
                . '<div style="margin-bottom:8px;font-weight:600">' . $escape($label) . '</div>'
                . '<img src="cid:' . $cid . '" alt="' . $escape($label) . '" style="display:block;width:260px;max-width:100%;height:auto">'
                . '</div>';
        }

        if ($qrCount === 0) {
            throw new RuntimeException('Не удалось сформировать QR-коды подключения');
        }

        $subject = 'Подключение к ЗСПД';
        $html = '<h1 style="font-size:24px;margin:0 0 20px">Подключение к ЗСПД</h1>'
            . '<p>Здравствуйте, ' . $escape($displayName) . '.</p>'
            . '<p>Для вас создано VPN-подключение. Основная информация:</p>'
            . '<table style="border-collapse:collapse;margin:16px 0">' . $htmlDetails . '</table>'
            . '<p>Отсканируйте подходящий QR-код приложением или импортируйте приложенный файл <strong>'
            . $escape($safeFileName . '.conf') . '</strong>.</p>'
            . '<div>' . $qrHtml . '</div>'
            . '<p style="margin-top:20px;color:#b91c1c"><strong>Не пересылайте это письмо:</strong> конфигурация содержит приватный ключ доступа.</p>';
        $text = "Подключение к ЗСПД\n\nЗдравствуйте, {$displayName}.\n\n"
            . "Для вас создано VPN-подключение.\n"
            . implode("\n", $textDetails) . "\n\n"
            . "Импортируйте приложенный файл {$safeFileName}.conf или используйте QR-коды из HTML-версии письма.\n\n"
            . "Не пересылайте это письмо: конфигурация содержит приватный ключ доступа.";

        return [
            'recipient' => $recipient,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'attachments' => [[
                'content' => $config,
                'name' => $safeFileName . '.conf',
                'mime' => 'text/plain',
            ]],
            'inline_images' => $inlineImages,
        ];
    }

    private static function decodeImageDataUri(string $dataUri): ?array {
        if (!preg_match('#^data:(image/(?:png|svg\+xml));base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUri, $matches)) {
            return null;
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            return null;
        }
        return ['mime' => $matches[1], 'content' => $content];
    }

    private static function send(
        array $settings,
        string $recipient,
        string $recipientName,
        string $subject,
        string $html,
        string $text,
        array $attachments = [],
        array $inlineImages = []
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
        foreach ($attachments as $attachment) {
            $mail->addStringAttachment(
                (string) $attachment['content'],
                (string) $attachment['name'],
                'base64',
                (string) ($attachment['mime'] ?? 'application/octet-stream')
            );
        }
        foreach ($inlineImages as $image) {
            $mail->addStringEmbeddedImage(
                (string) $image['content'],
                (string) $image['cid'],
                (string) $image['name'],
                'base64',
                (string) ($image['mime'] ?? 'image/png'),
                'inline'
            );
        }
        $mail->send();
    }
}
