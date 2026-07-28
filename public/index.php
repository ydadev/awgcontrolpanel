<?php
/**
 * Amnezia VPN Web Panel
 * Main entry point
 */

// Suppress errors for API endpoints to prevent HTML output
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    @ini_set('display_errors', '0');
    error_reporting(0);
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name(getenv('SESSION_NAME') ?: 'amnezia_panel_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/LoginRateLimiter.php';
require_once __DIR__ . '/../inc/Auth.php';
require_once __DIR__ . '/../inc/Branding.php';
require_once __DIR__ . '/../inc/Csrf.php';
require_once __DIR__ . '/../inc/EmailTwoFactorSettings.php';
require_once __DIR__ . '/../inc/EmailTwoFactorMailer.php';
require_once __DIR__ . '/../inc/EmailTwoFactorAuth.php';
require_once __DIR__ . '/../inc/Router.php';
require_once __DIR__ . '/../inc/View.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/UserServerAccess.php';
require_once __DIR__ . '/../inc/Translator.php';
require_once __DIR__ . '/../inc/JWT.php';
require_once __DIR__ . '/../inc/PanelImporter.php';
require_once __DIR__ . '/../inc/ServerMonitoring.php';
require_once __DIR__ . '/../inc/BackupLibrary.php';
require_once __DIR__ . '/../inc/InstallProtocolManager.php';
require_once __DIR__ . '/../inc/ProtocolService.php';
require_once __DIR__ . '/../inc/OpenRouterService.php';
require_once __DIR__ . '/../inc/Routing/RoutingValidator.php';
require_once __DIR__ . '/../inc/Routing/RoutingAuditService.php';
require_once __DIR__ . '/../inc/Routing/RoutingGroupRepository.php';
require_once __DIR__ . '/../inc/Routing/RoutingPermissionService.php';
require_once __DIR__ . '/../inc/Routing/RoutingRepository.php';
require_once __DIR__ . '/../inc/Routing/RoutingCompiler.php';
require_once __DIR__ . '/../inc/Routing/RoutingConfigBuilder.php';
require_once __DIR__ . '/../inc/Routing/RoutingDeliveryService.php';
require_once __DIR__ . '/../inc/Routing/RoutingAgentClient.php';
require_once __DIR__ . '/../inc/Routing/IpPoolManager.php';
require_once __DIR__ . '/../inc/Routing/ServerLinkManager.php';
require_once __DIR__ . '/../inc/Routing/RoutingPolicyResolver.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

// Test database connection
try {
    DB::conn();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

// Seed admin user if not exists
try {
    $adminEmail = Config::get('ADMIN_EMAIL');
    $adminPass = Config::get('ADMIN_PASSWORD');
    if ($adminEmail && $adminPass) {
        Auth::seedAdmin($adminEmail, $adminPass);
    }
} catch (Throwable $e) {
    // Ignore errors
}

// Initialize translator
Translator::init();
InstallProtocolManager::ensureDefaults();

// Initialize template engine
$user = Auth::user();
$branding = Branding::get(Config::get('APP_NAME', 'AWG Control Panel'));
$appName = $branding['app_name'];

/**
 * Helper function to authenticate user from JWT or session
 * Returns user array or null if unauthorized
 */
function authenticateRequest(): ?array
{
    // Check JWT token in Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        $user = JWT::verify($token);
        if ($user) {
            return $user;
        }
    }

    // Fallback to session
    if (isset($_SESSION['user_id'])) {
        return Auth::user();
    }

    return null;
}

View::init(__DIR__ . '/../templates', [
    'app_name' => $appName,
    'branding' => $branding,
    'user' => $user,
    'current_language' => Translator::getCurrentLanguage(),
    'languages' => Translator::getSupportedLanguages(),
    'current_uri' => $_SERVER['REQUEST_URI'] ?? '/dashboard',
    'current_year' => date('Y'),
    'csrf_token' => Csrf::token(),
    't' => function ($key, $params = []) {
        return Translator::t($key, $params);
    }
]);

// Helper function for redirects
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

// Helper function to require authentication
function requireAuth(): void
{
    if (!Auth::check()) {
        redirect('/login');
    }
}

// Helper function to require admin
function requireAdmin(): void
{
    requireAuth();
    if (!Auth::isAdmin()) {
        http_response_code(403);
        echo 'Forbidden: Admin access required';
        exit;
    }
}

function userCanViewServer(array $user, int $serverId): bool
{
    return ($user['role'] ?? '') === 'admin' || UserServerAccess::canViewServer((int) $user['id'], $serverId);
}

function userCanCreateClients(array $user, int $serverId): bool
{
    return ($user['role'] ?? '') === 'admin' || UserServerAccess::canCreateClients((int) $user['id'], $serverId);
}

function listConnectionOwnerOptions(int $serverId, array $currentUser): array
{
    if (($currentUser['role'] ?? '') !== 'admin') {
        return [[
            'id' => (int) $currentUser['id'],
            'email' => $currentUser['email'] ?? '',
            'name' => $currentUser['name'] ?? ($currentUser['email'] ?? ''),
            'role' => $currentUser['role'] ?? 'user',
            'has_server_access' => 1,
        ]];
    }

    $pdo = DB::conn();
    // Site access controls login only; admins can provision for users who cannot log in.
    $stmt = $pdo->prepare('
        SELECT
            u.id,
            u.email,
            u.name,
            u.role,
            u.status,
            CASE
                WHEN u.role = \'admin\' THEN 1
                WHEN usa.can_view = 1 THEN 1
                ELSE 0
            END AS has_server_access
        FROM users u
        LEFT JOIN user_server_access usa
            ON usa.user_id = u.id
            AND usa.server_id = ?
            AND usa.can_view = 1
        ORDER BY CASE WHEN u.role = \'admin\' THEN 0 ELSE 1 END, u.email ASC
    ');
    $stmt->execute([$serverId]);
    return $stmt->fetchAll();
}

function resolveConnectionOwnerForCreateById(array $currentUser, int $serverId, int $targetUserId): array
{
    if (($currentUser['role'] ?? '') !== 'admin') {
        return $currentUser;
    }

    if ($targetUserId <= 0) {
        throw new Exception('Select a user for this connection');
    }

    $pdo = DB::conn();
    // Do not reject a target whose web login is disabled.
    $stmt = $pdo->prepare('
        SELECT
            u.*,
            CASE
                WHEN u.role = \'admin\' THEN 1
                WHEN usa.can_view = 1 THEN 1
                ELSE 0
            END AS has_server_access
        FROM users u
        LEFT JOIN user_server_access usa
            ON usa.user_id = u.id
            AND usa.server_id = ?
            AND usa.can_view = 1
        WHERE u.id = ?
        LIMIT 1
    ');
    $stmt->execute([$serverId, $targetUserId]);
    $owner = $stmt->fetch();

    if (!$owner) {
        throw new Exception('Selected user was not found');
    }

    if (($owner['role'] ?? '') !== 'admin' && (int) ($owner['has_server_access'] ?? 0) !== 1) {
        throw new Exception('Selected user does not have access to this server');
    }

    return $owner;
}

function resolveConnectionOwnerForCreate(array $currentUser, int $serverId): array
{
    return resolveConnectionOwnerForCreateById($currentUser, $serverId, (int) ($_POST['user_id'] ?? 0));
}

function userCanAccessClient(array $user, array $clientData): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if ((int) ($clientData['user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return false;
    }

    return UserServerAccess::canViewServer((int) $user['id'], (int) ($clientData['server_id'] ?? 0));
}

function debugRoutesEnabled(): bool
{
    $val = strtolower((string) (getenv('ENABLE_DEBUG_ROUTES') ?: ''));
    return in_array($val, ['1', 'true', 'yes', 'on'], true);
}

function requireDebugEnabledOrAdmin(): void
{
    requireAuth();

    if (Auth::isAdmin()) {
        return;
    }

    if (!debugRoutesEnabled()) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
}

// Helper function to get authenticated user (JWT or session)
function getAuthUser(): ?array
{
    // Try JWT first
    $token = JWT::getTokenFromHeader();
    if ($token !== null) {
        $user = JWT::verify($token);
        if ($user !== null) {
            return $user;
        }
    }

    // Fall back to session
    if (Auth::check()) {
        return Auth::user();
    }

    return null;
}

// Helper function to require authentication (JWT or session) for API
function requireApiAuth(): ?array
{
    $user = getAuthUser();

    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        return null;
    }

    return $user;
}

/**
 * PUBLIC ROUTES
 */

// Home page
Router::get('/', function () {
    if (!Auth::check()) {
        redirect('/login');
    }
    redirect('/dashboard');
});

// Login page
Router::get('/login', function () {
    if (Auth::check()) {
        redirect('/dashboard');
    }
    if (EmailTwoFactorAuth::pending()) {
        redirect('/login/verify');
    }

    $data = [];
    if (isset($_SESSION['login_error'])) {
        $data['error'] = $_SESSION['login_error'];
        unset($_SESSION['login_error']);
    }
    View::render('login.twig', $data);
});

Router::post('/login', function () {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = Auth::verifyCredentials($email, $password);
    if ($user) {
        if (!EmailTwoFactorSettings::isEnabled()) {
            Auth::completeLogin((int) $user['id']);
            redirect('/dashboard');
        }

        try {
            session_regenerate_id(true);
            EmailTwoFactorAuth::begin($user);
            redirect('/login/verify');
        } catch (Throwable $e) {
            error_log('Email 2FA delivery failed: ' . $e->getMessage());
            View::render('login.twig', [
                'error' => 'Не удалось отправить код подтверждения. Обратитесь к администратору.',
            ]);
            return;
        }
    }

    if (Auth::lastLoginFailure() === 'rate_limited') {
        $retryAfter = max(1, Auth::loginRetryAfter($email));
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        View::render('login.twig', ['error' => 'Too many failed sign-in attempts. Try again in one hour.']);
        return;
    }

    View::render('login.twig', ['error' => 'Invalid credentials']);
});

Router::get('/login/verify', function () {
    if (Auth::check()) {
        redirect('/dashboard');
    }

    $pending = EmailTwoFactorAuth::pending();
    if (!$pending) {
        redirect('/login');
    }

    $data = ['two_factor' => $pending];
    if (isset($_SESSION['two_factor_success'])) {
        $data['success'] = $_SESSION['two_factor_success'];
        unset($_SESSION['two_factor_success']);
    }
    if (isset($_SESSION['two_factor_error'])) {
        $data['error'] = $_SESSION['two_factor_error'];
        unset($_SESSION['two_factor_error']);
    }
    View::render('login_verify.twig', $data);
});

Router::post('/login/verify', function () {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        View::render('login_verify.twig', [
            'error' => 'Сессия формы устарела. Начните вход заново.',
            'two_factor' => EmailTwoFactorAuth::pending(),
        ]);
        return;
    }

    $result = EmailTwoFactorAuth::verify(trim((string) ($_POST['code'] ?? '')));
    if (!empty($result['success']) && Auth::completeLogin((int) $result['user_id'])) {
        redirect('/dashboard');
    }

    $reason = $result['reason'] ?? 'invalid';
    if (in_array($reason, ['missing', 'expired', 'attempts_exhausted', 'access_disabled'], true)) {
        $_SESSION['login_error'] = match ($reason) {
            'expired' => 'Срок действия кода истёк. Войдите снова.',
            'attempts_exhausted' => 'Лимит попыток исчерпан. Войдите снова, чтобы получить новый код.',
            'access_disabled' => 'Доступ к сайту отключён.',
            default => 'Проверка входа устарела. Войдите снова.',
        };
        redirect('/login');
    }

    $pending = EmailTwoFactorAuth::pending();
    View::render('login_verify.twig', [
        'error' => 'Неверный код. Осталось попыток: ' . (int) ($result['attempts_left'] ?? 0) . '.',
        'two_factor' => $pending,
    ]);
});

Router::post('/login/verify/resend', function () {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $_SESSION['two_factor_error'] = 'Сессия формы устарела. Начните вход заново.';
        redirect('/login');
    }

    try {
        $result = EmailTwoFactorAuth::resend();
        if (!empty($result['success'])) {
            $_SESSION['two_factor_success'] = 'Новый код отправлен.';
        } elseif (($result['reason'] ?? '') === 'rate_limited') {
            $_SESSION['two_factor_error'] = 'Повторная отправка будет доступна через '
                . (int) ($result['retry_after'] ?? 60) . ' сек.';
        } else {
            $_SESSION['login_error'] = 'Нужно заново ввести логин и пароль.';
            redirect('/login');
        }
    } catch (Throwable $e) {
        error_log('Email 2FA resend failed: ' . $e->getMessage());
        $_SESSION['login_error'] = 'Не удалось повторно отправить код. Войдите снова.';
        redirect('/login');
    }

    redirect('/login/verify');
});

Router::post('/login/verify/cancel', function () {
    if (Csrf::validate($_POST['csrf_token'] ?? null)) {
        EmailTwoFactorAuth::cancel();
    }
    redirect('/login');
});

// Register page
Router::get('/register', function () {
    if (Auth::check()) {
        redirect('/dashboard');
    }
    View::render('register.twig');
});

Router::post('/register', function () {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        View::render('register.twig', ['error' => 'Invalid email address']);
        return;
    }

    if (strlen($password) < 6) {
        View::render('register.twig', ['error' => 'Password must be at least 6 characters']);
        return;
    }

    try {
        $success = Auth::register($name, $email, $password);
        if ($success) {
            View::render('login.twig', ['success' => 'Account created. An administrator must enable site access before you can sign in.']);
            return;
        }
    } catch (Throwable $e) {
        // Email already exists or other error
    }

    View::render('register.twig', ['error' => 'Registration failed. Email may already be in use.']);
});

// Logout
Router::get('/logout', function () {
    Auth::logout();
    redirect('/login');
});

/**
 * AUTHENTICATED ROUTES
 */

// Dashboard
Router::get('/dashboard', function () {
    requireAuth();
    $user = Auth::user();

    // Get user's servers
    $servers = (($user['role'] ?? '') === 'admin')
        ? VpnServer::listAll()
        : VpnServer::listByUser($user['id']);

    // Get user's clients
    $clients = VpnClient::listByUser($user['id']);

    // Get real-time online clients count from Xray API
    $onlineData = ServerMonitoring::countOnlineClients();

    // Also count clients with recent handshake (within 5 minutes) for WireGuard/AWG
    $pdo = DB::conn();
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt FROM vpn_clients 
        WHERE last_han…38101 tokens truncated…tingsController();
    $controller->index();
});

Router::get('/settings/protocols', function () {
    requireAdmin();
    $params = [];
    if (isset($_GET['id'])) {
        $params[] = 'id=' . urlencode($_GET['id']);
    }
    if (isset($_GET['new'])) {
        $params[] = 'new=1';
    }
    $query = empty($params) ? '' : ('?' . implode('&', $params));
    redirect('/settings' . $query . '#protocols');
});

// Legacy protocol routes removed in favor of ProtocolManagementController and /api/protocols endpoints

// NEW PROTOCOL MANAGEMENT ROUTES
Router::get('/settings/protocols-management', function () {
    requireAdmin();
    redirect('/settings#protocols');
});
Router::get('/settings/protocols/new', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $_GET['new'] = 1;
    $controller->index();
});

Router::get('/settings/protocols/{id}/edit', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $_GET['id'] = $params['id'];
    $controller->index();
});

Router::get('/settings/protocols/{id}/template', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    // This will render the template editor component
    $_GET['id'] = $params['id'];
    $_GET['template'] = 1;
    $controller->index();
});

// POST route to save/update protocol
Router::post('/settings/protocols/save', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->save();
});

// API ROUTES FOR PROTOCOLS
Router::get('/api/protocols', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiGetProtocols();
});

Router::get('/api/protocols/{id}', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiGetProtocol((int) $params['id']);
});

Router::post('/api/protocols', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiCreateProtocol();
});

Router::put('/api/protocols/{id}', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiUpdateProtocol((int) $params['id']);
});

Router::delete('/api/protocols/{id}', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiDeleteProtocol((int) $params['id']);
});

Router::post('/api/protocols/{id}/test-install', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiTestInstallProtocol((int) $params['id']);
});

Router::get('/api/protocols/{id}/test-install/stream', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiTestInstallProtocolStream((int) $params['id']);
});

Router::get('/api/protocols/{id}/test-uninstall/stream', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    $controller = new ProtocolManagementController();
    $controller->apiTestUninstallProtocolStream((int) $params['id']);
});

// AI ASSISTANT ROUTES
Router::post('/api/ai/assist', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->assist();
});

Router::get('/api/ai/models', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->getModels();
});

Router::post('/api/ai/test-model', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->testModel();
});

Router::get('/api/protocols/{id}/ai-history', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->getGenerationHistory((int) $params['id']);
});

Router::post('/api/ai/generations/{id}/apply', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->applyGeneration((int) $params['id']);
});

Router::get('/ai/preview/{id}', function ($params) {
    requireAdmin();
    require_once __DIR__ . '/../controllers/AIController.php';
    $controller = new AIController();
    $controller->previewGeneration((int) $params['id']);
});

// Save API key
Router::post('/settings/api-key', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveApiKey();
});

// Change password
Router::post('/settings/change-password', function () {
    requireAuth();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->changePassword();
});

// Admin password reset for another user
Router::post('/settings/users/{id}/password', function ($params) {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->changeUserPassword($params['id']);
});

// Update profile
Router::post('/settings/profile', function () {
    requireAuth();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->updateProfile();
});

// Add user
Router::post('/settings/add-user', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->addUser();
});

// Delete user
Router::post('/settings/delete-user/{id}', function ($params) {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->deleteUser($params['id']);
});

// Update regular user's server access
Router::post('/settings/users/{id}/server-access', function ($params) {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveUserServerAccess($params['id']);
});

// Enable or disable panel access for a regular user
Router::post('/settings/users/{id}/site-access', function ($params) {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveUserSiteAccess($params['id']);
});

// Save UI branding
Router::post('/settings/branding', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveBranding();
});

Router::post('/settings/two-factor', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->saveEmailTwoFactor();
});

Router::post('/settings/two-factor/test', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    $controller = new SettingsController();
    $controller->testEmailTwoFactor();
});

// LDAP settings page
Router::get('/settings/ldap', function () {
    requireAdmin();
    redirect('/settings#ldap');
});

// Save LDAP settings
Router::post('/settings/ldap/save', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    require_once __DIR__ . '/../inc/LdapSync.php';
    $controller = new SettingsController();
    $controller->saveLdapSettings();
});

// Test LDAP connection
Router::post('/settings/ldap/test', function () {
    requireAdmin();

    require_once __DIR__ . '/../controllers/SettingsController.php';
    require_once __DIR__ . '/../inc/LdapSync.php';
    $controller = new SettingsController();
    $controller->testLdapConnection();
});

/**
 * LANGUAGE ROUTES
 */

// Change language
Router::post('/language/change', function () {
    $lang = $_POST['language'] ?? '';

    if (Translator::setLanguage($lang)) {
        $_SESSION['success'] = 'Language changed successfully';
    } else {
        $_SESSION['error'] = 'Invalid language';
    }

    $redirect = $_POST['redirect'] ?? '/dashboard';
    redirect($redirect);
});

Router::get('/language/change', function () {
    redirect('/dashboard');
});

// API: Get translation statistics
Router::get('/api/translations/stats', function () {
    header('Content-Type: application/json');

    $user = JWT::requireAuth();
    if (!$user)
        return;

    $stats = Translator::getStatistics();
    echo json_encode(['stats' => $stats]);
});

// API: Auto-translate missing keys
Router::post('/api/translations/auto-translate', function () {
    header('Content-Type: application/json');

    $user = requireApiAuth();
    if (!$user)
        return;
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $targetLang = $data['language'] ?? '';

    if (empty($targetLang)) {
        http_response_code(400);
        echo json_encode(['error' => 'Language is required']);
        return;
    }

    try {
        $stats = Translator::translateMissingKeys($targetLang);
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// API: Export translations
Router::get('/api/translations/export/{lang}', function ($params) {
    header('Content-Type: application/json');

    $user = JWT::requireAuth();
    if (!$user)
        return;

    $lang = $params['lang'];

    try {
        $json = Translator::exportToJson($lang);
        header('Content-Disposition: attachment; filename="translations_' . $lang . '.json"');
        echo $json;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

// ===== Scenario Management Routes (Admin Only) =====

// List scenarios
Router::get('/admin/scenarios', function () {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->listScenarios();
});

// Create scenario form
Router::get('/admin/scenario/create', function () {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->createScenarioForm();
});

// View scenario
Router::get('/admin/scenario/{id}', function ($params) {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->viewScenario((int) $params['id']);
});

// Edit scenario form
Router::get('/admin/scenario/{id}/edit', function ($params) {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->editScenarioForm((int) $params['id']);
});

// Save scenario (create/update)
Router::post('/admin/scenario', function () {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->saveScenario();
});

// Delete scenario
Router::post('/admin/scenario/{id}/delete', function ($params) {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->deleteScenario((int) $params['id']);
});

// Test scenario
Router::post('/admin/scenario/{id}/test', function ($params) {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->testScenario((int) $params['id']);
});

// Export scenario
Router::get('/admin/scenario/{id}/export', function ($params) {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->exportScenario((int) $params['id']);
});

// Import scenario
Router::post('/admin/scenario/import', function () {
    requireAdmin();
    $controller = new ScenarioController();
    $controller->importScenario();
});

// ===== Logs Management Routes (Admin Only) =====

// ===== Routing Management Routes =====

Router::get('/routing', function () {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->index();
});

Router::post('/routing/ingresses', function () {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->saveIngress();
});

Router::post('/routing/links', function () {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->createLink();
});

Router::post('/routing/ip-lists', function () {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->createIpList();
});

Router::post('/routing/groups', function () {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->createGroup();
});

Router::post('/routing/groups/{group_id}/members', function ($params) {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->saveGroupMembers((int) $params['group_id']);
});

Router::post('/routing/groups/{group_id}/permissions', function ($params) {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->saveGroupPermission((int) $params['group_id']);
});

Router::post('/routing/servers/{server_id}/revision', function ($params) {
    require_once __DIR__ . '/../controllers/AdminRoutingController.php';
    $controller = new AdminRoutingController();
    $controller->createRevision((int) $params['server_id']);
});

Router::get('/my/routes', function () {
    require_once __DIR__ . '/../controllers/UserRoutingController.php';
    $controller = new UserRoutingController();
    $controller->index();
});

Router::post('/my/routes/ip-lists', function () {
    require_once __DIR__ . '/../controllers/UserRoutingController.php';
    $controller = new UserRoutingController();
    $controller->createIpList();
});

Router::get('/api/routing/status', function () {
    require_once __DIR__ . '/../controllers/RoutingApiController.php';
    $controller = new RoutingApiController();
    $controller->status();
});

Router::post('/api/routing/servers/{server_id}/revision', function ($params) {
    require_once __DIR__ . '/../controllers/RoutingApiController.php';
    $controller = new RoutingApiController();
    $controller->buildRevision($params);
});

// List and view logs
Router::get('/admin/logs', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->index();
});

// Download log file
Router::get('/admin/logs/download', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->download();
});

// Delete log file
Router::post('/admin/logs/delete', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->delete();
});

// Clear all logs
Router::post('/admin/logs/clear-all', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->clearAll();
});

// Search logs
Router::post('/admin/logs/search', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->search();
});

// Get log statistics
Router::post('/admin/logs/stats', function () {
    requireAdmin();
    require_once __DIR__ . '/../controllers/LogsController.php';
    $controller = new LogsController();
    $controller->stats();
});

// Dispatch router
Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
