# Project: Secure Neo-Brutalist LMS

## Architecture
The application is a secure, server-rendered Learning Management System (LMS) built with PHP 8.5/8.4, Apache 2.4.69, and MySQL 8.4.13 LTS, designed for university-level "Secure Programming" coursework complying with OWASP ASVS L2 and OWASP Top 10 (2021).

### Container & Network Topology
- **Web Container (`web`)**:
  - Base Image: `php:8.5.12-apache-trixie` (Debian Trixie minimal, Apache 2.4.69, PHP 8.5.12; fallback `php:8.4.27-apache-trixie`).
  - Hardening: Non-root execution (`user: "33:33"`), `cap_drop: [ALL]`, `security_opt: ["no-new-privileges:true"]`, `read_only: true` rootfs.
  - Tmpfs Mounts: `/tmp` (64M), `/var/run/apache2` (16M), `/var/lock/apache2` (16M), `/var/log/apache2` (32M).
  - Unprivileged Ports: 8080 (HTTP redirect/internal) and 8443 (HTTPS with TLS 1.2/1.3 self-signed cert and HSTS). Host mapped to 8080/8443 (or 80/443).
  - Apache Directives: `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`, `LimitExcept GET POST HEAD OPTIONS`, `a2dismod -f autoindex`, DocumentRoot restricted to `public/`.
  - PHP Directives: `expose_php = Off`, `display_errors = Off`, `open_basedir`, strict cookie attributes (`HttpOnly`, `Secure`, `SameSite=Lax`).
- **Database Container (`db`)**:
  - Base Image: `mysql:8.4.13` (MySQL 8.4.13 LTS).
  - Hardening: Non-root execution (`user: "999:999"`), `cap_drop: [ALL]`, `security_opt: ["no-new-privileges:true"]`.
  - Storage: Named volume `db_data` mounted at `/var/lib/mysql`.
  - Dual User Accounts: `lms_migrator` (DDL + DML for migrations/seeding) vs `lms_app` (DML only: SELECT, INSERT, UPDATE, DELETE).
- **Networks**:
  - `frontend`: Bridge network joined by `web` (exposed).
  - `backend`: `internal: true` bridge network joined by `web` and `db` only. Database port 3306 is NOT published to host. DNS null-routed for `lms.binus.ac.id`.

### Application Design & Core Patterns
- **PSR-4 Modular Architecture**: Lightweight native MVC pattern without bloated frameworks or third-party web dependencies.
- **Strict 3-Menu Navigation**: Sidebar restricted strictly to `Dashboard`, `Courses`, and `Schedule` across all roles. Profile, security settings, 2FA, session revocation, and data export reside in the top-right avatar popover.
- **Neo-Brutalist Design System**: Hand-written CSS, 3-4px black borders, hard offset shadows (`4px 4px 0 #000`, zero blur), bold high-contrast color palette, dark mode toggle, zero CDN dependencies.
- **Security & Authorization Model**:
  - Central deny-by-default policy layer (`src/Security/Policy.php`) enforcing RBAC and object-level ownership checks (BOLA/IDOR prevention).
  - Lecturer access strictly restricted to assigned classes (403 on unassigned).
  - Separation of Duties: Operator strictly forbidden (403) from editing grades or reading private lecturer notes.
  - Append-Only Audit Log: Immutable audit records for every state mutation (`who`, `what`, `when`, `IP`, `user_agent`).
  - Database row-locking (`SELECT ... FOR UPDATE`) in transactions to prevent group `max_size` race conditions.
  - Native PDO parameterization (`PDO::ATTR_EMULATE_PREPARES => false`) and allowlisted dynamic `ORDER BY` columns.
  - Contextual escaping helper and safe Markdown parser for user input; strict nonce-based CSP (`script-src 'self' 'nonce-...'`).
  - Safe 7-stage file upload pipeline storing randomized UUID files outside webroot in `storage/uploads/` with `AvScannerHook` and streaming download controller with `nosniff`.
  - CSV formula injection protection (prefixing `=`, `+`, `-`, `@`, `\t`, `\r` with `'`).

---

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Docker & Compose Stack | Apache 2.4.69 + PHP 8.5/8.4 + MySQL 8.4.13 LTS in compose.yaml | M1_INFRA_DB | R1 |
| 2 | Dual-Network Isolation | `frontend` exposed vs `backend internal: true`, DB unpublished | M1_INFRA_DB | R1 |
| 3 | Container Hardening | Non-root users (33:33, 999:999), cap_drop ALL, no-new-priv, read-only rootfs + tmpfs | M1_INFRA_DB | R1 |
| 4 | Web Server & PHP Hardening | ServerTokens Prod, autoindex disabled, HTTP method allowlist, TLS/HSTS, open_basedir | M1_INFRA_DB | R1 |
| 5 | Dual DB Accounts | `lms_migrator` (DDL+DML) vs `lms_app` (DML only) least-privilege users | M1_INFRA_DB | R1, R5 |
| 6 | 31-Table 3NF Relational Schema | Fully normalized MySQL schema for users, sessions, catalog, enrollments, schedule, forum, gradebook, groups, attendance, tickets, audit logs | M1_INFRA_DB | R2 |
| 7 | Migration Runner | CLI schema migration script (scripts/migrate.php) | M1_INFRA_DB | R1 |
| 8 | Reference Data Fixtures | Raw JSON snapshots in data/raw/ from 5 screenshots (Term 2610, 8 LEC courses, schedule, Session 7 detail) | M2_DATA_IMPORTER | R2 |
| 9 | Privacy Engine & Pseudonymization | Deterministic pseudonymizer for classmates and faculty, zero real PII or real photos | M2_DATA_IMPORTER | R2 |
| 10 | Offline CLI Importer & Seeder | scripts/import_lms_data.php: zero network calls, loads data/raw/, seeds 3 core role accounts (Student, Lecturer, Operator), outputs credentials once | M2_DATA_IMPORTER | R2 |
| 11 | Database Connection Factory | src/Core/Database.php with PDO, utf8mb4, and ATTR_EMULATE_PREPARES => false | M3_CORE_AUTH_RBAC | R5 |
| 12 | PSR-4 Router & Request/Response | Native router with path parameter extraction, HTTP method matching, middleware pipeline | M3_CORE_AUTH_RBAC | R3 |
| 13 | Argon2id Authentication | Secure password hashing, verification, and upgrade via PASSWORD_ARGON2ID | M3_CORE_AUTH_RBAC | R5 |
| 14 | Login Rate Limiting & Lockout | IP and account-based rate limiting with exponential backoff and account locking | M3_CORE_AUTH_RBAC | R5 |
| 15 | Session Lifecycle Security | HttpOnly, Secure, SameSite cookies, session regeneration on login/escalation, idle/absolute timeouts, server-side session revocation | M3_CORE_AUTH_RBAC | R5 |
| 16 | TOTP 2FA Engine | Two-factor authentication (optional for Student, mandatory for Lecturer and Operator) | M3_CORE_AUTH_RBAC | R4, R5 |
| 17 | CSRF Protection | Per-session/request tokens validated on all POST, PUT, DELETE requests | M3_CORE_AUTH_RBAC | R5 |
| 18 | Central Policy & RBAC Engine | Deny-by-default role and permission enforcement | M3_CORE_AUTH_RBAC | R4 |
| 19 | Object-Level Authorization (BOLA/IDOR) | Ownership and enrollment verification (student own data, lecturer assigned classes only) | M3_CORE_AUTH_RBAC | R4 |
| 20 | Separation of Duties Enforcer | Operator 403 on grade edits and private lecturer notes | M3_CORE_AUTH_RBAC | R4 |
| 21 | Append-Only Audit Service | Searchable, immutable audit logging for all state mutations | M3_CORE_AUTH_RBAC | R4 |
| 22 | Contextual Escaping Helper | Strict HTML, attribute, JS, and URL output escaping | M3_CORE_AUTH_RBAC | R5 |
| 23 | Safe Markdown Parser | Sanitized Markdown rendering for forum posts without external CDNs | M3_CORE_AUTH_RBAC | R5 |
| 24 | Dynamic CSP Nonce & Security Headers | Nonce-based Content-Security-Policy, nosniff, COOP, CORP, HSTS headers | M3_CORE_AUTH_RBAC | R5 |
| 25 | Safe File Upload Pipeline | Extension + MIME allowlist, 10MB cap, UUID storage outside webroot, AvScannerHook, secure streaming download | M3_CORE_AUTH_RBAC | R5 |
| 26 | CSV Formula Injection Defense | Neutralization of =, +, -, @, \t, \r prefixes in tabular data export | M3_CORE_AUTH_RBAC | R4, R5 |
| 27 | Concurrency Row-Locking | SELECT ... FOR UPDATE in DB transactions to prevent group max_size race conditions | M3_CORE_AUTH_RBAC | R4 |
| 28 | Neo-Brutalist Design System & Theme | Thick borders, hard offset shadows, high contrast palette, zero CDN fonts/icons | M4_APP_UI_FEATURES | R3 |
| 29 | Dark Mode Toggle | Neo-Brutalist dark theme maintaining border/shadow contrast | M4_APP_UI_FEATURES | R3 |
| 30 | Strict 3-Menu Sidebar | Only Dashboard, Courses, and Schedule in primary navigation | M4_APP_UI_FEATURES | R3 |
| 31 | Avatar Popover Card | Top-right profile, 2FA, active sessions, data export, and logout menu | M4_APP_UI_FEATURES | R3 |
| 32 | Student Dashboard | Strictly Upcoming Class and Latest Forum Posts ONLY | M4_APP_UI_FEATURES | R3 |
| 33 | Lecturer Dashboard | Next class to teach card and Needs Attention panel | M4_APP_UI_FEATURES | R3 |
| 34 | Operator Dashboard | System counters, escalated forum reports, and service ticket queue | M4_APP_UI_FEATURES | R3 |
| 35 | Courses Catalog View | 8 Term 2610 LEC courses only (skip LAB), class codes (LC07/LD07), progress bars | M4_APP_UI_FEATURES | R3 |
| 36 | Course Session View | Configurable MAX_VISIBLE_SESSIONS = 3, Session 7 detail (outcomes, 6 subtopics, 19 materials) | M4_APP_UI_FEATURES | R3 |
| 37 | Course Tab: Forum | Thread creation, replies, 15m edit/delete window, post reporting, lecturer moderation (pin/lock/soft-delete), operator escalation | M4_APP_UI_FEATURES | R3, R4 |
| 38 | Course Tab: Gradebook | Student view own published grades; Lecturer define weights, enter grades, CSV bulk import with preview, publish/lock, audit trail | M4_APP_UI_FEATURES | R3, R4 |
| 39 | Course Tab: People | Pseudonymized roster of classmates and faculty in enrolled class | M4_APP_UI_FEATURES | R3, R4 |
| 40 | Course Tab: Grouping | Group creation, join/leave before deadline, approval status, concurrency-safe capacity enforcement | M4_APP_UI_FEATURES | R3, R4 |
| 41 | Course Tab: Attendance | Student view records, submit excuse with file upload; Lecturer open/close window, mark attendance, approve excuses; summary alerts | M4_APP_UI_FEATURES | R3, R4 |
| 42 | Master Schedule View | Month calendar + agenda list in GMT+7, badge types (Onsite, Virtual, Online, Exam, Assignment) | M4_APP_UI_FEATURES | R3 |
| 43 | Schedule Filtering & ICS Export | Filter by class/exam/assignment type; download personal calendar ICS | M4_APP_UI_FEATURES | R3 |
| 44 | Assignments & Submissions | Lecturer create assignments (synced to schedule); Student file submission with validation | M4_APP_UI_FEATURES | R4 |
| 45 | Native Analytics Charts | Pure CSS/SVG charts for attendance trends, grade distribution, forum activity (zero CDN) | M4_APP_UI_FEATURES | R4 |
| 46 | Operator User Management | Create/disable/unlock users, role assignment, password reset tokens, force logout sessions | M4_APP_UI_FEATURES | R4 |
| 47 | Operator Enrollment Management | Assign/drop students and lecturers, bulk enroll CSV with validation and preview | M4_APP_UI_FEATURES | R4 |
| 48 | Operator Service Ticket Queue | Process tickets for attendance corrections, group disputes, lecturer room change requests | M4_APP_UI_FEATURES | R4 |
| 49 | Operator Schedule & Room Allocator | Create/reschedule classes, automatic conflict detection (room, lecturer, class double-booking) | M4_APP_UI_FEATURES | R4 |
| 50 | Operator Audit & Security View | Searchable audit trail, failed logins report, locked accounts, suspicious activity alerts | M4_APP_UI_FEATURES | R4 |
| 51 | Cross-Role Global Search | Role-scoped search with allowlisted sorting and server-side pagination | M4_APP_UI_FEATURES | R4 |
| 52 | GDPR/Data Privacy Export | Self-service JSON export of user's personal profile, enrollments, and submissions | M4_APP_UI_FEATURES | R4 |
| 53 | E2E Test Runner & Framework | scripts/run_tests.sh executing complete test suite in web container | E2E_TRACK | R5 |
| 54 | Tier 1: Feature Coverage Tests | ≥5 isolated tests per feature across all 60 features | E2E_TRACK | R5 |
| 55 | Tier 2: Boundary & Corner Tests | ≥5 boundary/edge case tests per feature | E2E_TRACK | R5 |
| 56 | Tier 3: Cross-Feature Interaction Tests | Pairwise combinatorial tests for multi-step workflows | E2E_TRACK | R5 |
| 57 | Tier 4: Real-World Scenarios | End-to-end semester workflows for Student, Lecturer, and Operator | E2E_TRACK | R5 |
| 58 | Role Matrix & BOLA/IDOR Automated Tests | Automated endpoint matrix verifying 401/403 across all roles and unassigned/unenrolled resources | E2E_TRACK | R5 |
| 59 | Security Controls Automated Tests | Automated tests for Argon2id, lockout, CSRF, SQLi/ORDER BY, XSS/CSP, safe uploads, formula injection, row locks | E2E_TRACK | R5 |
| 60 | Documentation & Security Audits | README.md, docs/data-sources.md, docs/security.md with STRIDE, ASVS L2, composer audit, and container scan | M5_DOCS_AUDIT | R5 |

---

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | M1_INFRA_DB | Hardened Docker Compose, Apache 2.4.69 + PHP 8.5/8.4 + MySQL 8.4.13 LTS, networks, security configs, 31-table 3NF schema, migration runner | none | DONE |
| M2 | M2_DATA_IMPORTER | Raw fixtures (data/raw/), Privacy Engine (pseudonymization), offline CLI seeder (scripts/import_lms_data.php) | M1 | IN_PROGRESS |
| M3 | M3_CORE_AUTH_RBAC | PDO factory, Router/Request/Response, Argon2id auth, rate limiting, session manager, TOTP 2FA, CSRF, central Policy RBAC/BOLA engine, audit logger, escaper/CSP nonce, safe upload pipeline | M1, M2 | PLANNED |
| M4 | M4_APP_UI_FEATURES | Neo-Brutalist UI & CSS, strict 3-menu sidebar, Dashboards, Courses (8 LEC, MAX_VISIBLE_SESSIONS=3, 5 tabs), Schedule & ICS, safe upload controller, Operator management & ticket queue, CSV import/export | M3 | PLANNED |
| M5 | M5_DOCS_AUDIT | README.md, docs/data-sources.md, docs/security.md (STRIDE, ASVS L2, composer audit, container vulnerability report) | M4 | PLANNED |
| M6 | M6_FINAL_TESTS | Final Milestone: Pass 100% of E2E test suite (Phase 1), then Tier 5 Adversarial Coverage Hardening (Phase 2) | M4, E2E_TRACK | PLANNED |
| E2E | E2E_TESTING_TRACK | Parallel Track: Test harness, Tiers 1-4 tests, Role Matrix & BOLA/IDOR tests, Security Controls tests, publishes TEST_READY.md | M1 | PLANNED |

---

## Interface Contracts

### 1. Database & Migrations Contract
- **Connection**: `src/Core/Database::getConnection(): PDO` returns configured PDO instance with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`, `PDO::ATTR_EMULATE_PREPARES => false`.
- **Environment Variables**:
  - `DB_HOST`: `db`
  - `DB_PORT`: `3306`
  - `DB_NAME`: `secure_lms`
  - `DB_USER`: `lms_app` (runtime) / `lms_migrator` (migrations/seeding)
  - `DB_PASS`: injected via `.env` or Docker secrets.
- **Migration CLI**: `php scripts/migrate.php` returns exit code 0 on success.

### 2. Privacy & Importer Contract
- **CLI Seeder**: `php scripts/import_lms_data.php`
  - Zero external network requests.
  - Idempotent execution (can run multiple times safely).
  - Reads `data/raw/*.json`.
  - Populates MySQL with 8 Term 2610 LEC courses, schedule entries, Session 7 detail, initial forum posts, and default accounts.
  - Generates random passwords, hashes with Argon2id, prints one-time credentials table to stdout, exits code 0.
- **Privacy Service**: `src/Service/PrivacyService::pseudonymizeName(string $realName, string $role): string` returns deterministic fake name (e.g. `Prof. Sarah Jenkins`).

### 3. Core Auth & Policy Contract
- **Authentication**: `src/Service/AuthService::authenticate(string $username, string $password, ?string $totpCode = null): AuthResult`
- **Session**: `src/Core/Session::start(): void`, `src/Core/Session::regenerate(): void`, `src/Core/Session::destroy(): void`, `src/Core/Session::getCsrfToken(): string`.
- **Policy Engine**: `src/Security/Policy::authorize(User $user, string $action, ?object $resource = null): bool`
  - Throws `AuthorizationException` (mapped to HTTP 403) or returns boolean.
  - Rule: Student access verified against enrollments (`course_id`, `class_id`, `user_id`).
  - Rule: Lecturer access verified against teaching assignments (`course_id`, `class_id`, `lecturer_id`). Unassigned -> 403.
  - Rule: Operator access forbidden on `grade.edit`, `grade.import`, `note.read_private` -> 403.
  - Rule: Audit logs cannot be updated or deleted by any user or role -> 403.
- **Audit Service**: `src/Service/AuditService::log(int $userId, string $action, string $entityType, ?int $entityId, ?array $details = null): void`

### 4. Upload & File Streaming Contract
- **Upload Handler**: `src/Service/UploadService::handleUpload(array $fileUpload, int $userId): UploadResult`
  - Validates extension in allowlist (`pdf`, `png`, `jpg`, `jpeg`, `txt`, `zip`).
  - Validates MIME via `finfo` in allowlist (`application/pdf`, `image/png`, `image/jpeg`, `text/plain`, `application/zip`).
  - Enforces size cap ≤ 10MB.
  - Stores file as `storage/uploads/<uuid4>.<ext>`.
  - Calls `AvScannerHook::scan(string $path): bool`.
- **Download Streamer**: `src/Controllers/UploadController::download(string $fileId)`
  - Verifies authentication and object permission.
  - Sends headers: `Content-Type: <mime>`, `Content-Disposition: attachment; filename="<sanitized_orig_name>"`, `X-Content-Type-Options: nosniff`.
  - Streams file content without buffering.

### 5. UI & View Layout Contract
- **Views**: Server-rendered PHP templates located in `views/`.
- **Escaping**: All variable output escaped via `<?= $e($val) ?>` (HTML context) or `$e->attr()`, `$e->js()`.
- **CSRF Token**: Injected in every form via `<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">`.
- **CSP Nonce**: Injected into all inline scripts via `<script nonce="<?= $cspNonce ?>">`.
- **Navigation**:
  - Exactly 3 sidebar links: `/dashboard`, `/courses`, `/schedule`.
  - Avatar menu: `/profile`, `/security/2fa`, `/security/sessions`, `/export-data`, `/logout`.

### 6. Test Suite Contract
- **Runner**: `scripts/run_tests.sh` or `docker compose exec web composer test`.
- **Semantics**: Exits code 0 if and only if 100% of tests pass.
- **Output**: Detailed progress reporting with test counts and assertions.
- **Signals**: Publishes `TEST_READY.md` upon completion of test suite development.

---

## Code Layout
```
/home/wkali/teamwork_projects/secure_lms/
├── compose.yaml
├── .env.example
├── .gitignore
├── composer.json
├── docker/
│   ├── web/
│   │   ├── Dockerfile
│   │   ├── security.conf
│   │   ├── default-ssl.conf
│   │   └── php.ini
│   └── db/
│       └── my.cnf
├── public/
│   ├── index.php
│   └── assets/
│       ├── css/
│       │   └── style.css
│       └── js/
│           └── app.js
├── src/
│   ├── Core/
│   │   ├── Database.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── Session.php
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── CourseController.php
│   │   ├── ScheduleController.php
│   │   ├── UploadController.php
│   │   └── OperatorController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Course.php
│   │   ├── Schedule.php
│   │   ├── Forum.php
│   │   ├── Grade.php
│   │   ├── Group.php
│   │   └── Attendance.php
│   ├── Security/
│   │   ├── Policy.php
│   │   ├── Csrf.php
│   │   ├── Escaper.php
│   │   ├── Markdown.php
│   │   └── AvScannerHook.php
│   ├── Service/
│   │   ├── AuthService.php
│   │   ├── PrivacyService.php
│   │   ├── AuditService.php
│   │   ├── UploadService.php
│   │   ├── CsvService.php
│   │   └── IcsExporter.php
│   └── Middleware/
│       ├── AuthMiddleware.php
│       └── SecurityHeadersMiddleware.php
├── views/
│   ├── layout/
│   │   ├── header.php
│   │   ├── sidebar.php
│   │   └── footer.php
│   ├── auth/
│   ├── dashboard/
│   ├── courses/
│   ├── schedule/
│   └── operator/
├── data/
│   └── raw/
│       ├── term_2610.json
│       ├── courses_lec.json
│       ├── schedule_oct2026.json
│       ├── session7_materials.json
│       └── seed_users.json
├── migrations/
│   └── 001_initial_schema.sql
├── scripts/
│   ├── migrate.php
│   ├── import_lms_data.php
│   └── run_tests.sh
├── storage/
│   ├── uploads/
│   └── logs/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── RoleMatrix/
│   ├── Security/
│   └── Concurrency/
├── docs/
│   ├── data-sources.md
│   └── security.md
└── README.md
```
