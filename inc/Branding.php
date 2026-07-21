<?php

class Branding {
    private const NAMESPACE = 'ui';
    private const KEY = 'branding';

    public static function defaults(?string $appName = null): array {
        return [
            'app_name' => $appName ?: Config::get('APP_NAME', 'AWG Control Panel'),
            'logo_icon' => 'fas fa-shield-alt',
            'logo_url' => '',
            'primary_color' => '#6d28d9',
            'secondary_color' => '#4f46e5',
            'login_subtitle' => 'Sign in to manage your VPN servers',
            'footer_text' => 'Open Source VPN Management Panel',
        ];
    }

    public static function get(?string $appName = null): array {
        $defaults = self::defaults($appName);

        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare(
                'SELECT value FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([self::NAMESPACE, self::KEY]);
            $value = $stmt->fetchColumn();
            if (!$value) {
                return $defaults;
            }

            $stored = json_decode($value, true);
            if (!is_array($stored)) {
                return $defaults;
            }

            return array_merge($defaults, array_intersect_key($stored, $defaults));
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    public static function save(array $settings, ?array $logoFile = null): array {
        $current = self::get();
        $next = [
            'app_name' => self::cleanText($settings['app_name'] ?? '', 80, $current['app_name']),
            'logo_icon' => self::cleanIconClass($settings['logo_icon'] ?? '', $current['logo_icon']),
            'logo_url' => $current['logo_url'],
            'primary_color' => self::cleanHexColor($settings['primary_color'] ?? '', $current['primary_color']),
            'secondary_color' => self::cleanHexColor($settings['secondary_color'] ?? '', $current['secondary_color']),
            'login_subtitle' => self::cleanText($settings['login_subtitle'] ?? '', 160, $current['login_subtitle']),
            'footer_text' => self::cleanText($settings['footer_text'] ?? '', 160, $current['footer_text']),
        ];

        if (!empty($settings['remove_logo'])) {
            $next['logo_url'] = '';
        } elseif ($logoFile && isset($logoFile['error']) && (int)$logoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $next['logo_url'] = self::storeLogo($logoFile);
        }

        self::saveRaw($next);
        return $next;
    }

    private static function saveRaw(array $settings): void {
        $pdo = DB::conn();
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare(
            'SELECT id FROM settings WHERE user_id IS NULL AND namespace = ? AND `key` = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([self::NAMESPACE, self::KEY]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $update = $pdo->prepare('UPDATE settings SET value = CAST(? AS JSON), updated_at = NOW() WHERE id = ?');
            $update->execute([$json, $id]);
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO settings (user_id, namespace, `key`, value) VALUES (NULL, ?, ?, CAST(? AS JSON))'
        );
        $insert->execute([self::NAMESPACE, self::KEY, $json]);
    }

    private static function cleanText(string $value, int $maxLength, string $fallback): string {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return $fallback;
        }

        return self::limitString($value, $maxLength);
    }

    private static function cleanIconClass(string $value, string $fallback): string {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (!preg_match('/^[a-z0-9\\-\\s]+$/i', $value)) {
            return $fallback;
        }

        return self::limitString($value, 80);
    }

    private static function cleanHexColor(string $value, string $fallback): string {
        $value = trim($value);
        if (preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            return strtolower($value);
        }

        return $fallback;
    }

    private static function storeLogo(array $file): string {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload failed');
        }
        if ((int)$file['size'] > 1024 * 1024) {
            throw new RuntimeException('Logo file must be 1 MB or smaller');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpName);

        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Logo format must be PNG, JPG, GIF, WEBP, or SVG');
        }
        if ($mime !== 'image/svg+xml' && !@getimagesize($tmpName)) {
            throw new RuntimeException('Logo must be a valid image');
        }

        $uploadDir = dirname(__DIR__) . '/public/uploads/branding';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Cannot create logo upload directory');
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $target = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Cannot save uploaded logo');
        }

        return '/uploads/branding/' . $filename;
    }

    private static function limitString(string $value, int $maxLength): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
