<?php
class Auth {
  private static ?string $lastLoginFailure = null;

  public static function register(string $name, string $email, string $password): bool {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (strlen($password) < 6) return false;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    return $stmt->execute([$email, $hash, $name ?: $email, 'user', 'disabled']);
  }

  public static function login(string $email, string $password): bool {
    $user = self::verifyCredentials($email, $password);
    return $user ? self::completeLogin((int) $user['id']) : false;
  }

  public static function verifyCredentials(string $email, string $password): ?array {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $clientIp = LoginRateLimiter::clientIp();
    self::$lastLoginFailure = null;

    if (LoginRateLimiter::isBlocked($email, $clientIp)) {
      self::$lastLoginFailure = 'rate_limited';
      return null;
    }
    
    // Try LDAP authentication first if enabled
    $ldap = new LdapSync();
    if ($ldap->isEnabled()) {
      $ldapUser = $ldap->authenticate($email, $password);
      if ($ldapUser) {
        // LDAP auth successful - sync/create user in local DB
        $stmt = $pdo->prepare('SELECT * FROM users WHERE ldap_dn = ? LIMIT 1');
        $stmt->execute([$ldapUser['ldap_dn']]);
        $user = $stmt->fetch();
        
        if (!$user) {
          // Create new LDAP user
          $status = $ldapUser['role'] === 'admin' ? 'active' : 'disabled';
          $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status, ldap_synced, ldap_dn) VALUES (?, \'\', ?, ?, ?, 1, ?)');
          $stmt->execute([$ldapUser['email'], $ldapUser['display_name'], $ldapUser['role'], $status, $ldapUser['ldap_dn']]);
          $userId = (int)$pdo->lastInsertId();
          if ($status !== 'active') {
            self::$lastLoginFailure = 'site_access_disabled';
            return null;
          }
          $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
          $stmt->execute([$userId]);
          $user = $stmt->fetch();
        } else {
          $userId = (int)$user['id'];
          // Update user info from LDAP
          $stmt = $pdo->prepare('UPDATE users SET email = ?, name = ?, role = ? WHERE id = ?');
          $stmt->execute([$ldapUser['email'], $ldapUser['display_name'], $ldapUser['role'], $userId]);

          $user['email'] = $ldapUser['email'];
          $user['name'] = $ldapUser['display_name'];
          $user['role'] = $ldapUser['role'];
          if (!self::canAccessSite($user)) {
            self::$lastLoginFailure = 'site_access_disabled';
            return null;
          }
        }

        LoginRateLimiter::clearSuccessfulLogin($email, $clientIp);
        return $user ?: null;
      }
    }
    
    // Fallback to local DB authentication
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
      self::rejectInvalidLogin($email, $clientIp);
      return null;
    }
    if (!self::canAccessSite($user)) {
      self::$lastLoginFailure = 'site_access_disabled';
      return null;
    }
    LoginRateLimiter::clearSuccessfulLogin($email, $clientIp);
    return $user;
  }

  public static function completeLogin(int $userId): bool {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !self::canAccessSite($user)) {
      self::$lastLoginFailure = 'site_access_disabled';
      return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['pending_email_2fa']);
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    return true;
  }

  public static function logout(): void {
    unset($_SESSION['user_id'], $_SESSION['pending_email_2fa']);
    session_regenerate_id(true);
  }
  public static function check(): bool { return self::user() !== null; }

  public static function lastLoginFailure(): ?string {
    return self::$lastLoginFailure;
  }

  public static function loginRetryAfter(string $email): int {
    return LoginRateLimiter::secondsUntilAllowed($email, LoginRateLimiter::clientIp());
  }

  private static function rejectInvalidLogin(string $email, string $clientIp): bool {
    LoginRateLimiter::recordFailure($email, $clientIp);
    self::$lastLoginFailure = LoginRateLimiter::isBlocked($email, $clientIp)
      ? 'rate_limited'
      : 'invalid_credentials';
    return false;
  }

  public static function getUserByEmail(string $email): ?array {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ?: null;
  }

  public static function user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !self::canAccessSite($u)) {
      self::logout();
      return null;
    }
    return $u;
  }

  public static function canAccessSite(array $user): bool {
    return ($user['role'] ?? '') === 'admin' || ($user['status'] ?? 'active') === 'active';
  }

  public static function isAdmin(): bool {
    $u = self::user();
    return $u && ($u['role'] === 'admin');
  }

  public static function can(string $permission, ?array $user = null): bool {
    $user = $user ?: self::user();
    if (!$user || !self::canAccessSite($user)) return false;
    if (($user['role'] ?? '') === 'admin') return true;

    $userPermissions = [];

    return in_array($permission, $userPermissions, true);
  }

  public static function seedAdmin(string $email, string $password): void {
    $pdo = DB::conn();
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $hash, 'Administrator', 'admin', 'active']);
  }

  public static function listUsers(): array {
    $pdo = DB::conn();
    $stmt = $pdo->query('SELECT id, email, name, role, status, created_at, last_login_at FROM users ORDER BY id DESC');
    return $stmt->fetchAll();
  }

  public static function setRole(int $userId, string $role): bool {
    if (!in_array($role, ['admin','user'], true)) return false;
    $pdo = DB::conn();
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
    return $stmt->execute([$role, $userId]);
  }

  public static function saveSetting(?int $userId, string $namespace, string $key, string $valueJson): bool {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('INSERT INTO settings (user_id, namespace, `key`, `value`) VALUES (?, ?, ?, CAST(? AS JSON))
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
    return $stmt->execute([$userId, $namespace, $key, $valueJson]);
  }

  public static function getSetting(?int $userId, string $namespace, string $key): array {
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE user_id <=> ? AND namespace = ? AND `key` = ? LIMIT 1');
    $stmt->execute([$userId, $namespace, $key]);
    $val = $stmt->fetchColumn();
    if (!$val) return [];
    $decoded = json_decode($val, true);
    return is_array($decoded) ? $decoded : [];
  }
}
