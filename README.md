<p align="center">
  <img src="public/images/storage/cloud-hero.svg" alt="StuWebStorage" width="120">
</p>

<h1 align="center">StuWebStorage</h1>

<p align="center">
  <strong>Encrypted cloud storage platform with controlled sharing and multi-user administration.</strong><br>
  Built for self-hosting, secure by default, and tested like a real product.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white" alt="Symfony 8.1">
  <img src="https://img.shields.io/badge/Doctrine-ORM_3-F56D39?logo=doctrine&logoColor=white" alt="Doctrine ORM 3">
  <img src="https://img.shields.io/badge/Tests-269_passing-16a34a?logo=phpunit&logoColor=white" alt="269 tests">
  <img src="https://img.shields.io/badge/Static_analysis-PHPStan-6f42c1" alt="PHPStan">
  <img src="https://img.shields.io/badge/i18n-5_locales-0ea5e9" alt="5 locales">
</p>

---

## Why this project exists

StuWebStorage addresses a concrete need: **host your own files** without relying on a SaaS, while keeping guarantees close to a commercial product — encryption at rest, granular sharing, hardened authentication, per-user quotas, and a full admin console.

This is not a demo CRUD. It is a **production-minded** application: 117 business classes, 26 migrations, 269 automated tests, end-to-end HTTP contracts, and a service-oriented, testable architecture.

---

## Visual overview

> Screenshots below are listed in [`docs/screenshots/screenshot.md`](docs/screenshots/screenshot.md).  
> Drop your PNG files in once ready — the README is already wired to display them.

### Home & authentication

| Home (light) | Login | TOTP verification |
|:---:|:---:|:---:|
| ![Home](docs/screenshots/01-home-hero-light.png) | ![Login](docs/screenshots/02-login-form.png) | ![TOTP](docs/screenshots/03-login-totp.png) |

### Files space

| Table view | Grid view | Media preview |
|:---:|:---:|:---:|
| ![Table](docs/screenshots/10-files-table-view-light.png) | ![Grid](docs/screenshots/11-files-grid-view.png) | ![Preview](docs/screenshots/20-files-media-preview.png) |

### Public sharing

| File landing | Password protection | Folder landing |
|:---:|:---:|:---:|
| ![Public file](docs/screenshots/40-public-file-landing.png) | ![Password](docs/screenshots/41-public-file-landing-password.png) | ![Public folder](docs/screenshots/43-public-folder-landing.png) |

### Administration (Godview)

| All-users view | User management | Site settings |
|:---:|:---:|:---:|
| ![Godview](docs/screenshots/50-admin-godview-all-users.png) | ![Users](docs/screenshots/52-admin-users-list.png) | ![Settings](docs/screenshots/55-admin-settings-access-gate.png) |

---

## Key features

### Secure storage

- **AES-256-CBC encryption at rest** — segmented v2 format for large files, streaming without memory blow-up
- **Chunked upload** (8 MB chunks) — reliable transfer of large files
- **Full folder tree** — create, rename, move, delete, properties
- **Per-user quota** — real-time tracking of used and remaining space
- **Advanced search** — text filter + glob patterns (`*.pdf`, `report*`)

### Controlled sharing

- **Public links** — files and folders, with configurable expiration
- **Share password** — generation, secure vault, rate limiting
- **Email verification (TOTP)** — challenge before public download
- **Friends sharing** — named grants with fine-grained revocation
- **Dedicated landing pages** — polished UX for anonymous recipients
- **Folder ZIP** — bundled download (configurable limits)

### Authentication & security

- **Email / password login** with remember-me policy
- **Email TOTP** on every login (can be disabled per user)
- **Trusted devices** — controlled memorization, admin revocation
- **Session invalidation** — server-side session versioning
- **Site access gate** — public lock with secret bypass note
- **Symfony rate limiters** — brute-force protection on sensitive endpoints
- **Granular roles** — `ROLE_SHARE`, `ROLE_SHARE_SEND`, `ROLE_SHARE_PUBLIC`, `ROLE_SHARE_FRIENDS`

### Administration

- **Godview** — cross-user view of all storage spaces
- **User management** — invitation, roles, soft/hard delete with restorable snapshot
- **Site settings** — access gate, email templates
- **Download audit** — access traceability

### User experience

- **5 languages** — FR, EN, DE, LT, NO
- **Light / dark theme** — persisted per device
- **UI preferences** — table/grid view, columns, sort order remembered
- **Responsive interface** — mobile-first files space
- **Toasts & modals** — immediate feedback, no unnecessary reloads

---

## Architecture

```mermaid
flowchart TB
    subgraph Client
        Browser[Browser]
        PublicLanding[Public landing /p/]
    end

    subgraph Symfony["Symfony 8 — HTTP layer"]
        Controllers[Controllers]
        Security[Security + Authenticator]
        Subscribers[Event Subscribers]
    end

    subgraph Services["Business layer"]
        FileSvc[FileEncryptionService\nChunkedUploadService\nUserStorageQuotaService]
        ShareSvc[PublicShareService\nFriendsShareService\nShareAuthorizationService]
        AuthSvc[TotpChallengeService\nTrustedDeviceService]
        AdminSvc[UserManagementService\nRoleGovernanceService]
    end

    subgraph Persistence
        Doctrine[(Doctrine ORM / MySQL)]
        Disk[(Encrypted files\nvar/storage)]
    end

    Browser --> Controllers
    PublicLanding --> Controllers
    Controllers --> Security
    Controllers --> Services
    Services --> Doctrine
    FileSvc --> Disk
    Subscribers --> Controllers
```

### Design principles

| Principle | Implementation |
|-----------|----------------|
| **Testable services** | Business logic outside controllers, dependency injection |
| **HTTP contracts** | Functional tests locking markup and routes |
| **DTO / ViewModels** | `FilesPageViewModel`, `UserFilesCapabilities` — decoupled templates |
| **Event subscribers** | Locale, theme, storage gate, request correlation |
| **Versioned migrations** | 26 Doctrine migrations, schema validated in CI |
| **Externalized config** | `.env` for keys, quotas, TTLs, rate limits |

---

## Tech stack

| Layer | Technologies |
|-------|-------------|
| **Backend** | PHP 8.4+, Symfony 8.1, Doctrine ORM 3, DBAL 4 |
| **Frontend** | Twig, Bootstrap 5, modular vanilla JavaScript |
| **Security** | Symfony Security, OpenSSL AES-256-CBC, HTML Sanitizer |
| **Quality** | PHPUnit 11 (269 tests, 1785 assertions), PHPStan 2 |
| **Ops** | Monolog, Migrations, Rate Limiter, Mailer |

---

## Quick start

### Prerequisites

- PHP ≥ 8.4 with `ctype`, `iconv`, `gd`, `openssl` extensions
- Composer 2
- MySQL / MariaDB or PostgreSQL
- Node.js **not required** — static assets served from `public/`

### Installation

```bash
git clone <repo-url> stuwebstorage
cd stuwebstorage

composer install

cp .env .env.local
# Configure DATABASE_URL, APP_FILE_ENCRYPTION_KEY, mailer, etc.

php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear
```

Open `/setup` to create the first administrator, then `/` to access the platform.

### Essential environment variables

| Variable | Purpose |
|----------|---------|
| `DATABASE_URL` | Database connection |
| `APP_FILE_ENCRYPTION_KEY` | AES-256 key for file encryption |
| `APP_TOTP_EMAIL_FROM` | Sender address for TOTP codes |
| `MAILER_DSN` | Email transport (SMTP, etc.) |

---

## Tests & quality

```bash
# Full suite
./vendor/bin/phpunit

# Static analysis
composer analyse
```

| Metric | Value |
|--------|-------|
| Test files | 89 |
| Tests run | 269 |
| Assertions | 1,800 |
| Functional coverage | Files, Share, Admin, Home, Infrastructure |

**Functional** tests validate HTTP contracts (routes, markup, translations, permissions). **Unit** tests cover encryption, quotas, rate limiting, and admin scope resolution.

---

## Project structure

```
StuWebStorage/
├── config/              # Symfony, security, services, rate limiters
├── migrations/          # Doctrine schema (26 versions)
├── public/              # Entry point, CSS/JS, assets
├── src/
│   ├── Controller/      # HTTP — Files, Admin, Public, Profile, Setup
│   ├── Entity/          # User, SharedFile, Folder, ShareGrant, …
│   ├── Service/         # Business logic (File, Share, Auth, Admin, Site)
│   ├── Security/        # Authenticator, password holders
│   ├── EventSubscriber/ # Locale, theme, gates, session
│   └── Dto/             # ViewModels for Twig
├── templates/           # Twig — files, admin, public landing, emails
├── tests/               # Functional + Unit (269 tests)
├── translations/        # messages.{fr,en,de,lt,no}.yaml
└── docs/screenshots/    # README screenshots (see screenshot.md)
```

---

## Security notes

- Never commit `.env` or `APP_FILE_ENCRYPTION_KEY`
- Key rotation requires re-encrypting existing files (not automated)
- The site access gate is an **additional** control, not a substitute for authentication
- Public shares expire — check TTLs in configuration

---

## Roadmap

- [ ] REST API / WebDAV for desktop client integration
- [ ] Optional end-to-end encryption (user-held key)
- [ ] Upload antivirus scanning
- [ ] Admin analytics dashboard
- [ ] GitHub Actions CI (PHPUnit + PHPStan + deploy)

---

## Author

**Stéphane H.** — Full-stack PHP / Symfony development  
Personal project focused on software engineering, security, and UX.

---

<p align="center">
  <sub>StuWebStorage — Encrypted storage, controlled sharing, tested code.</sub>
</p>
