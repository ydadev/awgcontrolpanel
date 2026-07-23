<?php

class Branding {
    private const NAMESPACE = 'ui';
    private const KEY = 'branding';

    public static function defaults(?string $appName = null): array {
        return [
            'app_name' => $appName ?: Config::get('APP_NAME', 'AWG Control Panel'),
            'logo_icon' => 'fas fa-shield-alt',
            'logo_url' => '/assets/branding/duck-barrier-colored-complete.svg',
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

        $svgContent = null;
        if ($mime === 'image/svg+xml') {
            $rawSvg = @file_get_contents($tmpName);
            if (!is_string($rawSvg) || $rawSvg === '') {
                throw new RuntimeException('Cannot read uploaded SVG logo');
            }
            $svgContent = self::sanitizeSvg($rawSvg);
        }

        $uploadDir = dirname(__DIR__) . '/public/uploads/branding';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Cannot create logo upload directory');
        }
        if (!is_writable($uploadDir)) {
            throw new RuntimeException('Logo upload directory is not writable');
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $target = $uploadDir . '/' . $filename;
        $saved = $svgContent !== null
            ? @file_put_contents($target, $svgContent, LOCK_EX) !== false
            : @move_uploaded_file($tmpName, $target);
        if (!$saved) {
            throw new RuntimeException('Cannot save uploaded logo');
        }
        @chmod($target, 0644);

        return '/uploads/branding/' . $filename;
    }

    private static function sanitizeSvg(string $svg): string {
        if (strpos($svg, "\0") !== false || stripos($svg, '<!ENTITY') !== false) {
            throw new RuntimeException('SVG logo contains unsupported content');
        }

        $svg = preg_replace('/<!DOCTYPE[^>]*(?:\[[\s\S]*?\]\s*)?>/i', '', $svg);
        if (!is_string($svg) || !preg_match('/<svg\b/i', $svg)) {
            throw new RuntimeException('Logo must be a valid SVG image');
        }

        $dangerousPatterns = [
            '/<\s*(?:script|foreignObject|iframe|object|embed)\b/i',
            '/<\?xml-stylesheet\b/i',
            '/\son[a-z0-9_-]+\s*=/i',
            '/(?:javascript|vbscript)\s*:/i',
            '/\b(?:href|xlink:href)\s*=\s*([\'"])\s*(?:https?:|\/\/)/i',
            '/url\s*\(\s*[\'"]?\s*(?:https?:|\/\/|javascript:|data:text\/html)/i',
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $svg)) {
                throw new RuntimeException('SVG logo contains unsafe content');
            }
        }

        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $valid = $document->loadXML($svg, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$valid || !$document->documentElement || strtolower($document->documentElement->localName) !== 'svg') {
                throw new RuntimeException('Logo must be a valid SVG image');
            }
        }

        return trim($svg) . "\n";
    }

    private static function limitString(string $value, int $maxLength): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
