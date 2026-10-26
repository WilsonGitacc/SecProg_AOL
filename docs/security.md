# Security Architecture, Threat Model & OWASP Verification (`docs/security.md`)

## 1. Action × Role Authorization Matrix (RBAC + Object-Level BOLA/IDOR)

Every request passes through `App\Security\Policy::evaluateRoute()` (`public/index.php`) before controller dispatch. The policy layer is **deny-by-default** and enforces both role checks (RBAC) and object-level ownership/enrollment checks (BOLA/IDOR).

| Feature / Endpoint | Anonymous | Student (Enrolled) | Student (Unenrolled / Other ID) | Lecturer (Assigned Class) | Lecturer (Unassigned Class) | Operator | Object-Level & Separation of Duties Rule |
|--------------------|-----------|--------------------|---------------------------------|---------------------------|-----------------------------|----------|------------------------------------------|
| `GET /login`, `POST /login` | `200` | `302` | `302` | `302` | `302` | `302` | Rate-limited (lockout after 5 failed attempts for 15 mins). |
| `GET /dashboard` | `401` | `200` | `200` | `200` | `200` | `200` | Student sees ONLY Upcoming Class & Latest Forum Posts. |
| `GET /courses` | `401` | `200` | `200` | `200` | `200` | `200` | Displays Term `2610` `LEC` classes only (`LAB` skipped). |
| `GET /courses/{id}` | `401` | `200` | `403` (IDOR) | `200` | `403` (IDOR) | `200` | Verified against `course_enrollments` / `class_lecturers`. |
| `POST /courses/{id}/forum/threads` | `401` | `200` | `403` (IDOR) | `200` | `403` (IDOR) | `403` | Safe Markdown sanitization; CSRF token required. |
| `PUT/DELETE /courses/{id}/forum/posts/{pid}` | `401` | `200` (own &le;15m) | `403` (BOLA/>15m) | `200` | `403` (IDOR) | `403` | Student may only edit/delete own post within 900s window. |
| `POST /courses/{id}/forum/posts/{pid}/report` | `401` | `200` | `403` (IDOR) | `403` | `403` | `403` | Students flag posts for lecturer/operator moderation. |
| `GET /courses/{id}/gradebook` | `401` | `200` (own only) | `403` (BOLA/IDOR) | `200` | `403` (IDOR) | `403` (SoD) | **Separation of Duties**: Operator is strictly denied (`403`) gradebook access. Student sees own published grades only. |
| `POST /courses/{id}/gradebook/update` | `401` | `403` | `403` | `200` | `403` (IDOR) | `403` (SoD) | Assigned Lecturer only; writes immutable `grade_change_history` + `audit_logs`. |
| `GET /courses/{id}/gradebook/export` | `401` | `403` | `403` | `200` | `403` (IDOR) | `403` (SoD) | Formula-injection safe CSV (`=`, `+`, `-`, `@`, `\t`, `\r` prefixed with `'`). |
| `GET /courses/{id}/lecturer-notes` | `401` | `403` | `403` | `200` | `403` (IDOR) | `403` (SoD) | **Separation of Duties**: Operator cannot read private lecturer notes. |
| `POST /courses/{id}/grouping/join` | `401` | `200` | `403` (IDOR) | `403` | `403` | `403` | Uses `SELECT ... FOR UPDATE` row lock inside transaction (`409` on full). |
| `POST /courses/{id}/attendance/excuse` | `401` | `200` | `403` (IDOR) | `403` | `403` | `403` | Optional attachment validated through 7-stage `UploadService`. |
| `POST /courses/{id}/attendance/mark` | `401` | `403` | `403` | `200` | `403` (IDOR) | `403` | Mandatory edit reason logged to `audit_logs`. |
| `GET /schedule/export.ics` | `401` | `200` (own) | `403` (BOLA) | `200` (own) | `403` (BOLA) | `200` (own) | Exports only the authenticated user's own schedule (`ownerId === user->id`). |
| `POST /schedule/manage` | `401` | `403` | `403` | `403` | `403` | `200` | Operator only; enforces room/time double-booking conflict check. |
| `GET/POST /operator/*` | `401` | `403` | `403` | `403` | `403` | `200` | Operator only; password hashes excluded; self-elevation blocked. |
| `PUT/DELETE /audit-logs/*` | `403` | `403` | `403` | `403` | `403` | `403` | **Nobody** can edit or delete audit logs (enforced in app policy + MySQL `BEFORE UPDATE`/`BEFORE DELETE` triggers). |

---

## 2. STRIDE Threat Model

| Threat Category | Concrete Threat Scenario in LMS | Implemented Mitigation | Verification Oracle |
|-----------------|---------------------------------|------------------------|---------------------|
| **S — Spoofing** | Credential stuffing, brute-force login, or session hijacking via XSS/network sniffing. | `PASSWORD_ARGON2ID` (`m=65536, t=4, p=1`), account lockout after 5 failed attempts for 15 minutes, TOTP 2FA (`TotpService`), `HttpOnly; Secure; SameSite=Lax` cookies, session ID regeneration on login/password change, CSRF tokens (`hash_equals`). | `Argon2idAuthTest`, `RateLimiterLockoutTest`, `CsrfProtectionTest` |
| **T — Tampering** | Modifying grades without authorization, tampering with audit logs, or race-condition overflow on group joining. | Central `Policy` gate, immutable `grade_change_history`, MySQL triggers `trg_audit_logs_no_update` and `trg_audit_logs_no_delete` raising `SQLSTATE 45000`, and pessimistic `SELECT ... FOR UPDATE` row locking in `CourseController::joinGroup`. | `OperatorSeparationOfDutiesTest`, `ConcurrencyRowLockTest`, `DatabaseAndImporterTest` |
| **R — Repudiation** | Lecturer alters attendance/grades or Operator resets an account and denies the action. | Append-only `audit_logs` table recording `uuid`, `user_id`, `action`, `target_type`, `target_id`, `ip_address`, `user_agent`, `payload_json`, and timestamp on every state mutation. | `DatabaseAndImporterTest`, `OperatorSeparationOfDutiesTest` |
| **I — Information Disclosure** | BOLA/IDOR viewing another student's grades/ICS, Operator viewing password hashes or private lecturer notes, stack traces, or server version leaks. | Object-level ownership & enrollment checks (`Policy`), explicit column lists excluding `password_hash`, `display_errors = Off`, `ServerTokens Prod`, `ServerSignature Off`, `expose_php = Off`, and deterministic PII pseudonymization (`PrivacyService`). | `BolaIdorMatrixTest`, `OperatorSeparationOfDutiesTest` |
| **D — Denial of Service** | Uploading oversized files, exhausting container memory/CPU, or locking accounts indefinitely. | 10MB upload cap, CSV row cap (500 rows), Docker resource limits (`cpus: '2'`, `memory: 1024m`), time-bounded 15-minute lockout + Operator unlock capability. | `SafeUploadPipelineTest`, `RateLimiterLockoutTest` |
| **E — Elevation of Privilege** | Student calling Lecturer/Operator endpoints, Operator self-elevating or editing grades, executing uploaded `.php` webshells, or container breakout. | Deny-by-default RBAC, upload storage outside webroot (`/var/www/html/storage/uploads`) with UUID filenames and `finfo` MIME validation, runtime DB user `lms_app` restricted to DML only (no `DROP`/`ALTER`/`FILE`/`SUPER`), container non-root (`33:33`, `999:999`), `cap_drop: [ALL]`, `no-new-privileges: true`, `read_only: true`. | `StudentRoleMatrixTest`, `LecturerRoleMatrixTest`, `SafeUploadPipelineTest`, `HttpMethodAllowlistTest` |

---

## 3. OWASP Top 10 (2021) & ASVS L2 Mapping

1. **A01:2021 — Broken Access Control**: Mitigated via central deny-by-default `App\Security\Policy`, enrollment/assignment verification on every course route, BOLA `ownerId` checks on grades/profile/ICS, and strict Separation of Duties (Operator blocked from grades and lecturer notes).
2. **A02:2021 — Cryptographic Failures**: Mitigated via TLS 1.2/1.3 with `Strict-Transport-Security` (HSTS), `PASSWORD_ARGON2ID` (`64MB` memory cost), CSPRNG `random_bytes()` for tokens/UUIDs, and zero cleartext secrets in git (`.env` generated via `scripts/generate_env.php`).
3. **A03:2021 — Injection (SQLi, XSS, CSV Formula Injection)**:
   - *SQLi*: Native PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`) and strict allowlist maps for `ORDER BY` sort columns.
   - *XSS*: Contextual escaping via `App\Security\Escaper`, safe Markdown parser (`App\Security\Markdown`) stripping HTML and blocking `javascript:`/`data:` URLs, and per-response nonce-based `Content-Security-Policy`.
   - *CSV Formula Injection*: `App\Service\CsvService::sanitizeCell()` prefixes cells starting with `=`, `+`, `-`, `@`, `\t`, `\r` with `'`.
4. **A04:2021 — Insecure Design**: Threat-modeled separation of duties, transactional `SELECT ... FOR UPDATE` row locking on group capacity, and complete network isolation of MySQL (`backend` network `internal: true`, DNS null-route for `lms.binus.ac.id`).
5. **A05:2021 — Security Misconfiguration**: Hardened `docker/web/security.conf`, `default-ssl.conf` (`LimitExcept GET POST HEAD OPTIONS`, `a2dismod autoindex status`), `docker/web/php.ini` (`expose_php = Off`, `display_errors = Off`, `allow_url_include = Off`, `open_basedir`), and read-only container root filesystem.
6. **A06:2021 — Vulnerable and Outdated Components**: Pinned latest supported images (`php:8.5.12-apache-trixie`, `mysql:8.4.13`, `composer:2.8.12`), zero runtime third-party Composer dependencies, verified via `composer audit`.
7. **A07:2021 — Identification and Authentication Failures**: Argon2id hashing, anti-enumeration generic error messages, account lockout/backoff, TOTP 2FA, session regeneration on login/password change, and server-side session revocation (`user_sessions`).
8. **A08:2021 — Software and Data Integrity Failures**: Pinned `composer.lock`, CSRF validation on all state-changing requests, and immutable database triggers on `audit_logs`.
9. **A09:2021 — Security Logging and Monitoring Failures**: Centralized `AuditService::log()` writing to append-only `audit_logs`, plus Operator Security & Audit Log console highlighting failed logins and lockouts.
10. **A10:2021 — Server-Side Request Forgery (SSRF)**: Offline importer makes zero network calls; user-supplied URLs are never fetched server-side; `allow_url_fopen` / `allow_url_include` disabled; `lms.binus.ac.id` null-routed to `127.0.0.1` and `::1`.

---

## 4. Dependency & Container Vulnerability Audit Report

### A. `composer audit` Output
Executed inside `secure_lms_web`:
```text
No security vulnerability advisories found.
```
- **Runtime Dependencies**: `0` third-party packages (pure native PHP 8.5 + PDO + OpenSSL + Fileinfo).
- **Dev Dependencies**: `phpunit/phpunit` pinned in `composer.lock` with `0` advisories.

### B. Container Image Security Assessment (`secure-lms-web:8.5.12` & `mysql:8.4.13`)
- **Base Images**:
  - `php:8.5.12-apache-trixie` (Apache `2.4.69` + PHP `8.5.12`, patched against `CVE-2026-23918` and prior httpd 2.4 CVEs).
  - `mysql:8.4.13` LTS (October 2026 patch release).
- **Runtime Mitigations Applied**:
  - Both containers drop all Linux capabilities (`cap_drop: [ALL]`), enforce `no-new-privileges: true`, run as unprivileged UIDs (`33:33` and `999:999`), and `web` mounts its root filesystem as `read_only: true`. Even if a theoretical vulnerability existed in an underlying OS library, privilege escalation and filesystem persistence are blocked at the kernel namespace boundary.

---

## 5. Final Self-Review & Remaining Risk Checklist

- [x] **All 31 tables in 3NF with Foreign Keys & `utf8mb4`**: Verified via `scripts/migrate.php`.
- [x] **Zero real PII / tokens in repo, DB, or logs**: Verified; all names pseudonymized via `PrivacyService`.
- [x] **Strict 3-Menu Sidebar (`Dashboard`, `Courses`, `Schedule`)**: Verified across all roles.
- [x] **Student Dashboard shows ONLY Upcoming Class & Latest Forum Posts**: Verified.
- [x] **Courses page skips `LAB` and shows 8 `LEC` courses with `MAX_VISIBLE_SESSIONS = 3`**: Verified.
- [x] **100% Automated Role Matrix & Security Suite Passing**: Verified via `docker compose exec web composer test`.
- **Remaining Operational Risks & Recommendations for Production Deployment**:
  1. *Self-Signed TLS Certificate*: Local Docker uses an auto-generated self-signed X.509 certificate on port `8443`. In production, replace with an ACME / Let's Encrypt or institutional CA certificate.
  2. *Antivirus Hook (`AvScannerHook`)*: Currently performs synchronous signature & PHP-tag inspection in PHP. For high-throughput production, connect `AvScannerHook` to a dedicated ClamAV (`clamd`) daemon container on the internal `backend` network.
  3. *Rate-Limiting Storage*: Login lockout is persisted in MySQL (`users.failed_login_attempts`, `users.locked_until`). Under volumetric L7 DDoS, pair with an upstream reverse-proxy rate limiter.
