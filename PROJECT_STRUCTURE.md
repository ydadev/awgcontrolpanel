# Project Structure

Complete file structure of Amnezia VPN Web Panel with descriptions.

```
amnezia-web-panel/
│
├── 📄 README.md                    # Main project documentation
├── 📄 CHANGELOG.md                 # Version history and changes
├── 📄 LICENSE                      # MIT License
├── 📄 TESTING.md                   # Testing guide
├── 📄 DEVELOPER.md                 # Developer documentation
├── 📄 .gitignore                   # Git ignore rules
├── 📄 .env.example                 # Environment template
├── 📄 .env                         # Environment variables (not in git)
│
├── 🐳 Docker Files
│   ├── docker-compose.yml          # Docker orchestration
│   ├── Dockerfile                  # PHP 8.2 Apache image
│   └── apache.conf                 # Apache configuration
│
├── 📦 Dependencies
│   ├── composer.json               # PHP dependencies
│   └── composer.lock               # Locked versions (generated)
│
├── 💾 Database
│   └── migrations/
│       └── 001_init.sql            # Initial schema (users, servers, clients, etc.)
│
├── 🎨 Frontend (Public)
│   └── public/
│       ├── index.php               # Main entry point & router
│       └── .htaccess               # Apache URL rewriting
│
├── 🧩 Backend (Core Classes)
│   └── inc/
│       ├── Router.php              # URL routing system
│       ├── DB.php                  # Database connection (PDO)
│       ├── Auth.php                # Authentication & sessions
│       ├── View.php                # Twig template rendering
│       ├── Config.php              # Configuration loader
│       ├── VpnServer.php           # Server management & deployment
│       ├── VpnClient.php           # Client config & QR generation
│       └── QrUtil.php              # Amnezia QR encoding utility
│
├── 🖼️ Templates (Views)
│   └── templates/
│       ├── layout.twig             # Base layout (header, nav, footer)
│       ├── login.twig              # Login page
│       ├── register.twig           # Registration page
│       ├── dashboard.twig          # User dashboard
│       ├── servers/
│       │   ├── index.twig          # Server list
│       │   ├── create.twig         # Add server form
│       │   ├── deploy.twig         # Deployment progress
│       │   └── view.twig           # Server details & client management
│       └── clients/
│           └── view.twig           # Client config & QR code
│
└── 🧪 Testing
    ├── test_qr.php                 # QR code generation test
    └── test_qr.png                 # Generated test QR (not in git)
```

## File Descriptions

### Root Configuration Files

#### `README.md`
Main project documentation with:
- Feature overview
- Quick start guide
- Installation instructions
- Usage examples
- Technology stack
- Contributing guidelines

#### `CHANGELOG.md`
Version history following [Keep a Changelog](https://keepachangelog.com/) format:
- v1.0.0 initial release features
- Known issues
- Planned features

#### `LICENSE`
MIT License - open source, commercial use allowed.

#### `TESTING.md`
Comprehensive testing guide:
- Unit tests
- Integration tests
- Security tests
- Browser compatibility
- Troubleshooting

#### `DEVELOPER.md`
Developer documentation:
- Development setup
- Architecture overview
- Code style guidelines
- Security best practices
- API development
- Contribution guide

#### `.gitignore`
Git exclusions:
- Environment files (.env)
- Dependencies (vendor/)
- Database data (db_data/)
- OS files (.DS_Store)
- Logs (*.log)
- IDE configs

#### `.env.example`
Environment template:
```env
MYSQL_ROOT_PASSWORD=replace-with-random-root-password
MYSQL_DATABASE=amnezia_panel
MYSQL_USER=amnezia
MYSQL_PASSWORD=replace-with-random-db-password
```

### Docker Files

#### `docker-compose.yml`
Two services:
- **web**: PHP 8.2 Apache container
  - Mounts project directory
  - Exposes port 8082
  - Depends on database
- **db**: MySQL 8.0 container
  - Persistent volume (db_data/)
  - Runs init migrations

#### `Dockerfile`
PHP 8.2 Apache image with:
- PHP extensions: pdo_mysql, gd, sodium, curl
- Composer installed
- sshpass for SSH deployment
- Apache mod_rewrite enabled

#### `apache.conf`
Virtual host configuration:
- Document root: /var/www/html/public
- AllowOverride All for .htaccess
- Directory permissions

### Database

#### `migrations/001_init.sql`
Initial schema:

**Tables**:
1. `users` - User accounts (id, name, email, password, role, created_at)
2. `vpn_servers` - VPN servers (id, user_id, name, host, port, status, keys, AWG params, etc.)
3. `vpn_clients` - VPN clients (id, server_id, user_id, name, IP, keys, config, QR code, etc.)
4. `api_tokens` - API authentication (id, user_id, token, expires_at)
5. `settings` - Application settings (key-value store)

**Indexes**:
- Email uniqueness
- Server-client relationships
- Status filtering

**Default Data**:
- Admin user is created at runtime from `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env`.

### Frontend (Public)

#### `public/index.php`
Main application entry point:
- Autoloader (Composer)
- Error handling
- Route definitions:
  - `/` - Home (redirect to dashboard)
  - `/login` - Login page
  - `/register` - Registration page
  - `/logout` - Logout action
  - `/dashboard` - User dashboard
  - `/servers` - Server list
  - `/servers/create` - Add server
  - `/servers/{id}` - Server details
  - `/servers/{id}/clients/create` - Create client
  - `/clients/{id}` - Client details
  - `/clients/{id}/download` - Download config
  - `/clients/{id}/delete` - Delete client
  - API routes (future)

#### `public/.htaccess`
Apache URL rewriting:
- Route all requests to index.php
- Preserve query strings
- Allow static files

### Backend (Core)

#### `inc/Router.php`
Simple pattern-matching router:
- `Router::get($path, $handler)` - GET routes
- `Router::post($path, $handler)` - POST routes
- Pattern variables: `/path/{id}`
- 404 handling

#### `inc/DB.php`
Database singleton:
- `DB::conn()` - Get PDO connection
- MySQL configuration
- UTF8MB4 charset
- Exception mode

#### `inc/Auth.php`
Authentication system:
- `Auth::login($email, $password)` - Authenticate user
- `Auth::logout()` - Clear session
- `Auth::user()` - Get current user
- `Auth::isLoggedIn()` - Check if logged in
- `Auth::isAdmin()` - Check admin role
- Bcrypt password hashing

#### `inc/View.php`
Template rendering:
- `View::render($template, $data)` - Render Twig template
- Template caching
- Auto-escaping enabled
- Global variables (user, isAdmin)

#### `inc/Config.php`
Configuration loader:
- Database settings
- Application settings
- Environment-based config

#### `inc/VpnServer.php`
Server management:
- `VpnServer::create(...)` - Create server record
- `$server->deploy()` - Deploy to remote server via SSH:
  - Install Docker
  - Create AWG container
  - Generate server keys
  - Configure firewall
  - Start VPN service
- `$server->getData()` - Get server info
- `VpnServer::listAll()` - List all servers
- `VpnServer::listByUser($userId)` - User's servers

**Deployment Steps**:
1. Connect via SSH (sshpass)
2. Check/install Docker
3. Create AWG container from image
4. Generate WireGuard keys (private, public, preshared)
5. Generate AWG obfuscation params (Jc, Jmin, Jmax, S1, S2, H1-H4)
6. Create wg0.conf configuration
7. Start WireGuard interface
8. Configure iptables NAT
9. Enable IP forwarding
10. Open firewall port

#### `inc/VpnClient.php`
Client management:
- `VpnClient::create($serverId, $userId, $name)` - Create client:
  - Generate client keys
  - Assign IP from subnet
  - Build WireGuard config
  - Add peer to server
  - Generate QR code
- `$client->getConfig()` - Get config text
- `$client->getQRCode()` - Get QR code PNG data URI
- `VpnClient::listByServer($serverId)` - Server's clients
- `VpnClient::listByUser($userId)` - User's clients

#### `inc/QrUtil.php`
**Critical: Amnezia-compatible QR encoding**

From `/Users/oleg/Documents/amnezia/QrUtil.php` (tested, working format):

Methods:
- `QrUtil::encodeOldPayloadFromConf($config)` - Encode config to Amnezia format:
  - Parse WireGuard config
  - Build JSON envelope with AWG params
  - Compress with gzcompress
  - Add Qt/QDataStream headers
  - URL-safe Base64 encode
- `QrUtil::pngBase64($payload)` - Generate QR code PNG:
  - Uses Endroid\QrCode library v5.x
  - Returns data URI: `data:image/png;base64,...`
  - Fallback to SVG if GD not available

**Format Details**:
- Header: Version (0x07C00100), compressed length, uncompressed length
- Payload: gzcompress(JSON, level 9)
- Encoding: URL-safe Base64 (+ → -, / → _, = trimmed)
- Structure: Qt QDataStream compatible

### Templates

#### `templates/layout.twig`
Base layout:
- HTML5 structure
- Tailwind CSS CDN
- Font Awesome icons
- Navigation menu
- User info (if logged in)
- Logout link
- Content block

#### `templates/login.twig`
Login form:
- Email input
- Password input
- Error display
- Link to register

#### `templates/register.twig`
Registration form:
- Name input
- Email input
- Password input
- Success/error display

#### `templates/dashboard.twig`
User dashboard:
- Servers overview (card grid)
- Clients overview (table)
- Quick actions
- Statistics (future)

#### `templates/servers/index.twig`
Server list:
- Table view
- Status badges
- Actions (view, edit, delete)
- Add server button

#### `templates/servers/create.twig`
Add server form:
- Server details (name, host, port)
- SSH credentials (username, password)
- Validation

#### `templates/servers/deploy.twig`
Deployment progress:
- Real-time log updates
- Progress indicator
- Success/error status
- Redirect to server view

#### `templates/servers/view.twig`
Server details:
- Server info (status, port, subnet)
- Create client form
- Client list table
- Actions (download config, view QR)

#### `templates/clients/view.twig`
Client details:
- Client info (IP, created date)
- QR code image
- Download button
- Delete button

### Testing

#### `test_qr.php`
QR code generation test:
- Sample WireGuard config
- Generate payload
- Generate QR PNG
- Save to file
- Verify output

**Usage**:
```bash
docker compose exec web php test_qr.php
```

**Expected Output**:
```
✅ Success! QR code generation working correctly.
✅ QR code saved to: /var/www/html/test_qr.png
```

## Data Flow

### Server Deployment Flow

```
User submits form
    ↓
Router: POST /servers/create
    ↓
VpnServer::create() - Insert to DB
    ↓
Redirect to /servers/{id}/deploy
    ↓
VpnServer->deploy()
    ↓
SSH to remote server
    ↓
Execute deployment commands:
  - Install Docker
  - Pull AWG image
  - Generate keys
  - Create config
  - Start container
    ↓
Update DB with server details
    ↓
Redirect to /servers/{id}
```

### Client Creation Flow

```
User submits client name
    ↓
Router: POST /servers/{id}/clients/create
    ↓
VpnClient::create($serverId, $userId, $name)
    ↓
Steps:
  1. Get server data
  2. Generate client keys (SSH exec)
  3. Get next free IP
  4. Build config text
  5. Add peer to server (append wg0.conf, wg syncconf)
  6. Generate QR code (QrUtil)
  7. Insert to DB
    ↓
Redirect to /clients/{id}
    ↓
Display config + QR code
```

### QR Code Generation Flow

```
WireGuard config text
    ↓
QrUtil::encodeOldPayloadFromConf($config)
    ↓
Parse config (regex):
  - Interface params
  - Peer params
  - AWG params (H1-H4, Jc, Jmin, Jmax, S1, S2)
    ↓
Build JSON envelope:
  - containers[]
    - awg (params)
    - container: "amnezia-awg"
  - defaultContainer
  - description
  - dns1, dns2
  - hostName
    ↓
JSON encode (pretty print)
    ↓
gzcompress(JSON, level 9)
    ↓
Add header: pack('N3', version, compLen, uncompLen)
    ↓
URL-safe Base64 encode
    ↓
QrUtil::pngBase64($payload)
    ↓
Generate QR with Endroid\QrCode
    ↓
Return data URI: "data:image/png;base64,..."
```

## Dependencies

### PHP (Composer)

```json
{
  "require": {
    "php": ">=8.0",
    "twig/twig": "^3.8",           // Template engine
    "endroid/qr-code": "^5.0",     // QR code generation
    "ext-pdo": "*",                // Database
    "ext-json": "*",               // JSON encoding
    "ext-curl": "*",               // HTTP requests
    "ext-gd": "*",                 // Image processing
    "ext-sodium": "*"              // Crypto (key derivation)
  }
}
```

### System (Docker)

- **PHP 8.2**: Modern PHP with types, enums, attributes
- **Apache 2.4**: Web server with mod_rewrite
- **MySQL 8.0**: Relational database
- **sshpass**: Non-interactive SSH password auth
- **Docker CLI**: Container management (on remote servers)

## Security Considerations

### Implemented

✅ Password hashing (bcrypt)  
✅ SQL injection prevention (prepared statements)  
✅ XSS prevention (Twig auto-escape)  
✅ Session-based authentication  
✅ Role-based access control  

### TODO

⚠️ CSRF protection (tokens)  
⚠️ Rate limiting (API)  
⚠️ JWT authentication (API)  
⚠️ Input sanitization (comprehensive)  
⚠️ HTTPS enforcement  
⚠️ Security headers (CSP, HSTS, etc.)  

## Performance

### Optimizations

- Singleton DB connection
- Template caching (Twig)
- Lazy loading (models)
- Indexed database queries

### Future

- Redis caching
- Database connection pooling
- CDN for static assets
- Minified CSS/JS
- Gzip compression

## Monitoring

### Logs

- Apache access logs: `/var/log/apache2/access.log`
- Apache error logs: `/var/log/apache2/error.log`
- PHP error logs: `error_log()` function
- MySQL slow query log

### Health Checks

```bash
# Container status
docker compose ps

# Application health
curl http://localhost:8082/

# Database health
docker compose exec db mysql -u amnezia -p -e "SELECT 1"
```

## Backup & Recovery

### Database Backup

```bash
# Backup
docker compose exec db sh -lc 'mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"' > backup.sql

# Restore
docker compose exec -T db sh -lc 'mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"' < backup.sql
```

### Full Backup

```bash
# Backup everything
tar -czf amnezia-backup-$(date +%Y%m%d).tar.gz \
  --exclude=vendor \
  --exclude=db_data \
  amnezia-web-panel/

# Also backup database
docker compose exec db sh -lc 'mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"' > db-backup-$(date +%Y%m%d).sql
```

---

**Last Updated**: 2024-11-05  
**Version**: 1.0.0  
**Maintainer**: Amnezia VPN Community
