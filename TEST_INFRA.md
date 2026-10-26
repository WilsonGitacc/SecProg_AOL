# Test Infrastructure & Specification Guide
**Project**: Secure Neo-Brutalist Learning Management System (LMS)  
**Standard**: OWASP ASVS v4.0.3 Level 2, OWASP Top 10 (2021)  
**Methodology**: Dual-Track Testing (Requirement-Driven, Opaque-Box, Category-Partition, BVA, Pairwise, Workload)  
**Status**: Authoritative Test Architecture  

---

## 1. Testing Philosophy & Guiding Principles

### 1.1 Opaque-Box & Requirement-Driven Architecture
The automated test suite for the Secure Neo-Brutalist LMS is designed strictly as an **opaque-box, requirement-driven specification harness**. The tests do NOT inspect or depend on the internal private implementation details of classes or methods; instead, tests verify observable behavior against authoritative requirements defined in `ORIGINAL_REQUEST.md`, `PROJECT.md`, and `spec_report.md`:
- **Interface Contracts**: Tests validate input parameters, output schemas, HTTP status codes (`200`, `201`, `400`, `401`, `403`, `409`, `422`), database mutations, and security headers.
- **Independence & Isolation**: Every test case sets up its own state, executes within its own transactional boundary or isolated scope, and leaves zero persistent side effects. Tests execute in any order without inter-test dependencies.
- **Zero Façade Tests**: Every test exercises real logic, real assertion constraints, and genuine security gates. Façade assertions that always return true without executing underlying business rules are strictly prohibited.
- **Progressive Testability**: Tests are runnable across development milestones using pure PHP CLI without requiring external networks, external CDNs, or live remote services.

### 1.2 Expected Output Derivation & Verification Oracles
Expected test outputs are strictly derived from the following authoritative sources:
1. **Screen Reference & Fixtures**: Authoritative screenshots of Term 2610 from `lms.binus.ac.id` (8 LEC courses, schedule entries, Session 7 detail, Bloom taxonomy C2/C3 outcomes, 19 materials).
2. **OWASP ASVS L2 & Top 10 (2021)**: Exact security parameters (Argon2id memory cost 64MB/time cost 4, 15-minute brute force lockout on 5 failures, cryptographically secure 256-bit CSRF tokens, strict nonce-based CSP, 10MB upload cap with MIME magic verification, CSV formula neutralization).
3. **Database & Relational Invariants**: 31-table 3NF schema constraints, foreign key integrity, `SELECT ... FOR UPDATE` pessimistic row locking preventing race conditions on group capacity.
4. **Role Matrix & Separation of Duties**: Deny-by-default RBAC and ABAC rules enforcing 401 for anonymous access, 403 for unenrolled/unassigned class access, 403 for Operator access to grading and private notes, and database-level immutability of audit logs.

---

## 2. Comprehensive 4-Tier Test Architecture

The testing framework organizes all 60 features and 20 edge cases into four hierarchical tiers:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   Tier 4: Real-World Scenarios                         │
│       (Full-Semester Student, Lecturer, and Operator Workflows)        │
├────────────────────────────────────────────────────────────────────────┤
│             Tier 3: Cross-Feature Combinations (Pairwise)              │
│       (Multi-step workflows, Excuse -> Approval -> Attendance %)       │
├────────────────────────────────────────────────────────────────────────┤
│            Tier 2: Boundary & Corner Cases (>=5 / feature)             │
│    (Limits, Null/Empty, Special Chars, MIME Spoofing, Overflow, Race)  │
├────────────────────────────────────────────────────────────────────────┤
│              Tier 1: Feature Coverage (>=5 / feature)                  │
│       (Nominal paths, primary behaviors, CRUD, schema validation)      │
├────────────────────────────────────────────────────────────────────────┤
│                 Role Matrix & Security Controls Suites                 │
│      (401/403 Matrix, IDOR Defense, Argon2id, CSRF, CSP, Row Locks)    │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Mapping of All 60 Features to Test Tiers

Each of the 60 project features is assigned to test tiers with concrete test specifications (minimum 5 tests per feature in Tier 1 and minimum 5 tests in Tier 2):

| # | Feature Name | Tier 1: Feature Coverage Tests (Nominal) | Tier 2: Boundary & Corner Tests | Tier 3: Interactions | Tier 4: Scenarios |
|---|---|---|---|---|---|
| **1** | Docker & Compose Stack | Container healthcheck pass; port 8080/8443 listening; non-root user verification; tmpfs mount read/write; restart policy | OOM limit trigger; readonly rootfs write rejection; privilege escalation drop; missing secret fallback; signal handling SIGTERM | Network separation between web and db | Cold stack boot to healthy status |
| **2** | Dual-Network Isolation | Web connects to db; db resolves web; web reachable from host; db unreachable from host; egress block to lms.binus.ac.id | DNS null-route resolution; raw socket connect failure to host network; packet filtering; subnet collision check; spoofed internal IP | Inter-service PDO query across internal bridge | End-to-end isolated data processing |
| **3** | Container Hardening | UID 33:33 for web; UID 999:999 for db; cap_drop ALL verified; no-new-privileges flag verified; read-only rootfs | Setuid execution attempt; chown/chmod restricted; raw socket creation attempt; dev filesystem access blocked; kernel ring buffer blocked | Tmpfs storage write during upload | Security scan audit verification |
| **4** | Web Server & PHP Hardening | ServerTokens Prod; Directory listing 403; HTTP method allowlist (GET/POST/HEAD/OPTIONS); TLS 1.3 negotiation; HSTS header | TRACE method returns 405/403; DELETE/PUT without route returns 405; long URI buffer handling; chunked transfer encoding; TLS 1.0/1.1 rejected | HTTPS redirect from port 8080 to 8443 | Full TLS handshake and header check |
| **5** | Dual DB Accounts | lms_migrator connects with DDL; lms_app connects with DML; lms_app SELECT/INSERT/UPDATE works; lms_migrator table creation | lms_app DROP TABLE fails; lms_app ALTER TABLE fails; lms_app DELETE audit_logs fails; lms_app GRANT fails; lms_migrator empty pass rejected | Migration execution under migrator -> app query under app | Schema upgrade followed by user workflow |
| **6** | 31-Table 3NF Schema | Table existence (31 tables); primary key integrity; foreign key constraints; utf8mb4 collation; default timestamps | Duplicate unique key rejection; foreign key orphan rejection; null constraint violations; max varchar length truncation; unsigned int overflow | Cascade delete restrictions on critical logs | Complete schema migration and integrity check |
| **7** | Migration Runner | CLI script executes with code 0; version table updated; rollback on SQL failure; idempotency check; batch execution | Migration with invalid SQL triggers rollback; missing migration file handling; interrupted migration lock; duplicate migration version | Database seeding immediately after migration | Migration from empty DB to final schema |
| **8** | Reference Data Fixtures | Raw JSON loading from data/raw/; 8 LEC courses structure; Session 7 detail structure; Schedule entries structure; Seed users structure | Missing JSON key handling; corrupt JSON syntax; empty JSON array; unexpected schema version; special unicode character support | Privacy engine parsing raw fixture | Snapshot integrity verification |
| **9** | Privacy & Pseudonymization | Student names pseudonymized; lecturer names pseudonymized; deterministic hash mapping; generic avatar assignment; zero PII leak | Empty name string handling; hyphenated/accented name handling; duplicate name collision; non-alphanumeric input; extreme name length | Import runner pseudonymization pipe | Full roster pseudonymization check |
| **10** | Offline CLI Importer | Script runs offline code 0; inserts 8 LEC courses; seeds Student, Lecturer, Operator; prints passwords once; zero network calls | Running importer twice (idempotency); importer with missing raw files; importer with existing DB records; importer under non-root user; signal interrupt | Seeder populates data for all subsequent tests | End-to-end import to login readiness |
| **11** | Database Connection Factory | Returns PDO instance; ERRMODE_EXCEPTION active; DEFAULT_FETCH_MODE ASSOC; EMULATE_PREPARES false; charset utf8mb4 | Invalid DB credentials throw exception; unreachable host throws exception; timeout handling; persistent connection handling; transaction rollback on failure | Router middleware database injection | Connection pool reuse across requests |
| **12** | PSR-4 Router & Dispatcher | Route matching GET/POST; path parameter extraction; 404 on missing route; 405 on invalid method; middleware execution pipeline | Multiple path variables `/courses/{id}/sessions/{sid}`; trailing slash handling; URL encoded path segments; query string preservation; extreme path length | Auth middleware to Controller dispatch | Request lifecycle through middleware stack |
| **13** | Argon2id Authentication | Hash password with Argon2id; verify valid password; reject invalid password; password_needs_rehash check; timing consistency | Memory cost 65536 verification; time cost 4 verification; empty password rejection; 1000-char password handling; null byte in password | User login to session establishment | User registration, login, and rehash |
| **14** | Login Rate Limiter & Lockout | Failed login count increment; allow under 5 attempts; lock on 5th failure; lockout duration 15m; unlock expired lock | Brute force from single IP; brute force across multiple usernames; reset counter on success; concurrent login attempts; clock skew handling | Rate limiter middleware blocking request | 5 failed logins -> 429 -> wait -> unlock |
| **15** | Session Lifecycle Security | Set-Cookie HttpOnly; Set-Cookie Secure; Set-Cookie SameSite=Lax; session_regenerate_id on login; server session table entry | Idle timeout at 900s; absolute timeout at 43200s; session fixation attempt; invalid session ID injection; concurrent sessions limit | Session authentication check in Policy | Login -> Activity -> Idle timeout -> Logout |
| **16** | TOTP 2FA Engine | Generate base32 secret; generate QR/URI; verify valid 6-digit TOTP; reject invalid TOTP; replay window rejection | Clock drift ±1 step (30s); past code rejection; future code beyond window; non-numeric code rejection; empty code handling | 2FA challenge during login flow | Enable 2FA -> Logout -> Login with 2FA |
| **17** | CSRF Protection | Generate 32-byte hex token; embed in forms; validate on POST; validate on PUT/DELETE; rotate token | Missing token returns 403; forged token returns 403; expired session token; token from different session; timing-safe hash_equals check | Form submission in forum and gradebook | Complete state-changing POST workflow |
| **18** | Central Policy & RBAC | Deny-by-default on unregistered action; allow Student on student action; allow Lecturer on lecturer action; allow Operator; 403 on role mismatch | Null user object throws 401; user with disabled account returns 403; unknown action string throws exception; circular role inheritance check; empty permission set | Policy check in every controller endpoint | RBAC enforcement across 3 roles |
| **19** | Object-Level Auth (BOLA/IDOR) | Student access own gradebook; Lecturer access assigned class; Student access enrolled forum; Student download own data; 403 on other student data | Student access other student grades (IDOR); Lecturer access unassigned class (IDOR); Parameter tampering in URL IDs; Negative ID parameter; Non-existent object ID | Policy verifying enrollment ownership | Cross-student access attempt blocked |
| **20** | Separation of Duties | Operator denied gradebook edit; Operator denied private lecturer notes; Operator denied grade publish; Operator denied grade import; Audit log entry created | Operator crafted POST to `/courses/{id}/gradebook/update`; Operator GET `/courses/{id}/lecturer-notes`; Parameter pollution in operator role; Operator token replay | Operator ticket processing vs grade block | Operator auditing grades without edit rights |
| **21** | Append-Only Audit Service | Log action with user ID; log IP address; log user agent; log timestamp; log JSON payload details | Null user ID for system actions; special chars in action description; large details JSON payload; rapid sequential audit writes; audit log search pagination | Controller write action triggers audit log | Audit trail inspection after operations |
| **22** | Contextual Escaper | HTML body escaping `htmlspecialchars`; HTML attribute escaping; JavaScript context `json_encode`; URL query escaping; UTF-8 preservation | XSS `<script>alert(1)</script>`; Single quote `' OR '1'='1`; Double quote `" onmouseover=`; Event handler payload `onload=`; Backtick injection | Escaper rendering in Blade/PHP view | User post rendered in forum view |
| **23** | Safe Markdown Parser | Bold/italic formatting; markdown headings; code blocks; list formatting; sanitized link parsing | Strip raw `<script>` tags; strip `<iframe src=...>`; strip `<img src=x onerror=...>`; sanitize `javascript:` URI; strip data: URIs | Forum post markdown to HTML rendering | Student writes markdown post -> Safe view |
| **24** | Dynamic CSP Nonce & Headers | Generate 16-byte random nonce; inject in CSP header; inject in `<script nonce="...">`; X-Content-Type-Options nosniff; X-Frame-Options DENY | Inline script without nonce blocked; script with wrong nonce blocked; script with recycled nonce blocked; external CDN domain blocked; object-src none check | Nonce generator middleware -> View engine | Browser receives page with active CSP |
| **25** | Safe File Upload Pipeline | Upload valid PDF; upload valid PNG; upload valid TXT; upload valid ZIP; file saved outside webroot as UUID | Reject `.php` extension; reject `.phtml` extension; reject MIME spoof (`GIF89a<?php`); reject file > 10MB; reject null byte filename `shell.php\0.png` | Upload service -> AvScannerHook | Student submits assignment file |
| **26** | CSV Formula Injection Defense | Export clean CSV; prefix `=` with `'`; prefix `+` with `'`; prefix `-` with `'`; prefix `@` with `'` | Prefix `\t` (tab) with `'`; prefix `\r` (carriage return) with `'`; nested quotes in cell; multibyte characters in cell; numeric cell preservation | Gradebook export action | Lecturer exports grades to safe CSV |
| **27** | Concurrency Row-Locking | Join group with available capacity; increment member count; atomic transaction commit; roll back on error; lock acquisition | Concurrent join on 1 remaining slot; join already full group; leave group decrements count; concurrent leave and join; dead lock prevention | Group join endpoint inside DB transaction | 2 concurrent students joining 1 slot |
| **28** | Neo-Brutalist Design System | 4px black borders rendered; 4px hard shadows rendered; brand orange accent present; high contrast color tokens; WCAG AA contrast check | Zero border-radius verification; zero box-shadow blur verification; responsive breakpoint layout; high DPI rendering; missing CSS token fallback | Asset loader rendering styles | Complete page view aesthetic validation |
| **29** | Dark Mode Toggle | Server renders `data-theme="dark"`; toggle cookie set; dark mode palette applied; contrast ratio preserved; dark shadow rendered | Missing theme cookie defaults light; invalid theme value fallback; rapid theme toggle; local storage sync script; system preference match | View layout header theme switcher | User toggles dark mode -> Page reloads |
| **30** | Strict 3-Menu Sidebar | Sidebar contains Dashboard; Sidebar contains Courses; Sidebar contains Schedule; exactly 3 nav links; correct active state | Attempt to inject 4th nav link; mobile responsive nav; role-independent 3-menu integrity; screen reader aria labels; keyboard tab navigation | Layout sidebar template rendering | Navigation across Dashboard, Courses, Schedule |
| **31** | Top-Right Avatar Popover | Render avatar card; show user name; show role badge; show security settings links; show logout button | Popover toggle click; escape key dismiss; outside click dismiss; keyboard navigation focus trap; unauthenticated view has no avatar | Avatar menu links to `/security`, `/profile` | User opens avatar menu and clicks Logout |
| **32** | Student Dashboard | Render Upcoming Class widget; render Latest Forum Posts widget; exactly 2 widgets present; show countdown timer; link to session | Student with zero classes enrolled; student with no upcoming classes; student with no forum posts; session starting now countdown; multiple posts ordering | Course and schedule services feeding data | Student logs in and views dashboard |
| **33** | Lecturer Dashboard | Render Next Class to Teach; render Needs Attention panel; display pending excuse count; display ungraded count; display reported posts | Lecturer with no teaching assigned; all counts zero empty state; high count badge formatting; one-click drilldown navigation; urgent report alert | Attendance and forum services feeding data | Lecturer logs in and handles attention items |
| **34** | Operator Dashboard | Render System Counters; render Escalated Forum Reports; render Pending Request Queue; live SLA timer; counters accuracy | Zero active users edge state; 100+ pending tickets pagination; overdue SLA indicator; high failed login count warning; real-time counter refresh | Audit and ticket services feeding data | Operator logs in and reviews system health |
| **35** | Courses Catalog View | Show 8 LEC courses; show course codes (COMP...); show class codes (LD07/LC07); show progress bar percentage; filter excludes LAB | Student enrolled in 1 course; empty course catalog; 100% progress display; 0% progress display; courses ordered by code | Catalog service querying enrollments | Student browses semester courses |
| **36** | Course Session View | Display exactly 3 sessions (MAX_VISIBLE_SESSIONS); show Session 7 detail; show C2/C3 outcomes; show 6 subtopics; show 19 materials | Slider navigation to older sessions; session with no materials; session with completed status; session with ongoing status; out of bounds session index | Session service querying syllabus | Student studies Session 7 materials |
| **37** | Course Tab: Forum | List course threads; create thread; reply to thread; 15-minute edit window; report post | Edit attempt at 16 minutes (rejected); soft-delete thread preserves replies; pin thread to top; locked thread rejects replies; search forum query | Forum service interacting with Policy | Student posts question -> Lecturer answers |
| **38** | Course Tab: Gradebook | Student view own grades; Lecturer view class gradebook; add grade component; enter grade (0-100); publish grades | Grade score < 0 rejected; grade score > 100 rejected; component weights sum != 100% rejected; CSV import with invalid format; Operator access 403 | Grade service calculating weighted total | Lecturer grades class and publishes |
| **39** | Course Tab: People | List classmates; list lecturers; pseudonymized names displayed; generic avatar displayed; role badge displayed | Class with 1 student; class with 100 students; special characters in pseudonym; search by name in roster; unenrolled user viewing roster (403) | Privacy engine masking real student data | Student checks classmate roster |
| **40** | Course Tab: Grouping | List group sets; create group; join group; leave group; lecturer approve group | Join closed group (rejected); join group past deadline (rejected); leave locked group (rejected); min_size not met alert; max_size overflow prevented | Concurrency row lock in group join | Students form project group for review |
| **41** | Course Tab: Attendance | View attendance records; submit excuse request; upload excuse document; lecturer mark attendance; approve excuse | Submit excuse for future session (rejected); submit excuse without reason (rejected); edit attendance without reason (rejected); attendance < 75% alert | Upload pipeline storing doctor note | Student misses class -> Excused by lecturer |
| **42** | Master Schedule View | Month calendar grid; agenda list view; GMT+7 timezone conversion; 5 badge types; today date highlight | Schedule spanning month boundary; day with 5+ classes; day with zero classes; leap year date handling; timezone offset verification | Schedule service querying DB | Student reviews upcoming exam date |
| **43** | Schedule Filtering & ICS | Filter by Onsite Class; filter by Virtual Class; filter by Exam; filter by Assignment; export RFC 5545 ICS file | Deselect all filters; export ICS with zero events; export ICS with multi-day events; ICS escaping of special characters; BOLA check on export | Calendar generator formatting VEVENT | Student downloads ICS to calendar app |
| **44** | Assignments & Submissions | Lecturer create assignment; set due date; set late policy; student submit file; auto-sync to Schedule | File submission past due date with no late policy; file upload exceeding size cap; resubmission before deadline; grade assignment score; download submission | Upload service handling file submission | Lecturer creates assignment -> Student submits |
| **45** | Native Analytics Charts | Pure CSS attendance bar chart; pure SVG grade distribution; pure SVG forum activity; zero external JS/CDN; accessible tables | Chart with zero data points; chart with 100% attendance; chart with all failing grades; responsive SVG viewBox scaling; high-contrast print styles | Analytics service aggregating course metrics | Lecturer analyzes class pass rate |
| **46** | Operator User Management | Create new user; disable active user; unlock locked user; reset password token; force logout sessions | Create duplicate username (rejected); weak password rejection; operator self-elevation attempt (403); operator disabling own account (rejected) | Auth and session services | Operator unlocks student account |
| **47** | Operator Enrollment Mgmt | Assign student to class; drop student from class; assign lecturer; bulk enroll CSV; dry-run preview | Assign student to full class (capacity limit); assign non-existent student (rejected); duplicate enrollment (rejected); bulk CSV with corrupt rows | Enrollment service managing relationships | Operator bulk enrolls semester intake |
| **48** | Operator Service Tickets | List open tickets; change ticket status; add internal operator note; send student reply; track SLA timestamp | Resolve already resolved ticket; ticket with empty reply; filter tickets by category; search tickets by keyword; ticket created by student | Ticket service updating state and audit | Student requests room fix -> Operator resolves |
| **49** | Operator Schedule Allocator | Create schedule session; select room; select lecturer; detect room double-booking; detect lecturer double-booking | Double booking in same room returns 409; double booking same lecturer returns 409; scheduling outside term dates; room capacity check | Schedule conflict detection engine | Operator schedules mid-term exam room |
| **50** | Operator Audit & Security | View audit logs; filter by action; filter by user; view failed logins; view locked accounts | Search audit logs by date range; export audit logs; audit log pagination with 10,000+ entries; attempt to delete audit log (rejected); IP lookup | Audit service querying immutable log | Security officer investigates login anomaly |
| **51** | Cross-Role Global Search | Search courses; search forum topics; search schedule; enforce role scoping; highlight matches | Search query with SQLi syntax; search query with XSS `<script>`; empty search query; search query > 100 chars; search returning 0 results | Search service filtering by policy | Student searches for "Review I" |
| **52** | GDPR / Data Export | Generate JSON export; export profile; export enrollments; export submissions; export grades | Export when user has zero submissions; export with large attachment history; BOLA check on user ID parameter; verify JSON valid syntax | Export service assembling user data | Student downloads personal data package |
| **53** | E2E Test Runner Harness | Run all test suites; exit code 0 on 100% pass; colorized ANSI output; execution timing report; assertion counter | Run specific test suite; run with failing test exits code 1; run in container environment; run in CI environment; catch uncaught exceptions | Test runner orchestrating all test files | scripts/run_tests.sh execution |
| **54** | Tier 1: Feature Coverage | >=5 tests for features 1-30; verify nominal inputs; verify expected outputs; verify return types; verify HTTP codes | Null parameter handling; default argument verification; return type assertion; state mutation assertion; event emission assertion | Tier 1 suite verifying baseline features | Automated test suite execution |
| **55** | Tier 2: Boundary & Corner | >=5 boundary tests for features 1-30; zero/null boundaries; string length limits; integer overflow; character encoding | Max file size boundary (10MB ± 1 byte); 15m edit window boundary (900s ± 1s); grade range (0, 100, -1, 101); session limits | Tier 2 suite testing edge conditions | Automated boundary verification |
| **56** | Tier 3: Interactions | Pairwise feature tests; excuse -> mark attendance; create assignment -> schedule sync; enroll -> course visible; post -> notify | Database rollback on multi-step failure; stale state detection; cache invalidation; out-of-order execution prevention; event consistency | Inter-module workflow testing | Multi-step feature integration tests |
| **57** | Tier 4: Real-World Scenarios | Student 14-week semester workflow; Lecturer grading cycle workflow; Operator term setup workflow; emergency reschedule scenario | Mid-semester student drop; grade dispute resolution; lecturer absence substitution; sudden room maintenance reschedule | Full system operational flow | End-to-end semester life cycle tests |
| **58** | Role Matrix & IDOR Suite | 6 personas tested on all routes; Anonymous 401; Student 403 on other data; Lecturer 403 on other class; Operator 403 on grades | Parameter tampering on every route ID; method spoofing on protected routes; forged auth headers; unauthenticated session injection | Central Policy engine testing | Comprehensive Role Matrix test run |
| **59** | Security Controls Suite | Argon2id cost verification; 5-attempt lockout test; CSRF token validation; SQLi & ORDER BY allowlist; XSS escaper & CSP nonce | Upload polyglot PHP rejection; CSV formula injection neutralization; Concurrent group join row locking (`FOR UPDATE`); DB least privilege | Security middleware and services | Automated security controls test run |
| **60** | Documentation & Audits | README setup guide; architecture diagrams; data-sources mapping; security.md STRIDE model; composer audit report | Verify documentation links; verify version EOL table; verify threat model completeness; verify zero high/critical vulnerabilities | Documentation validation tools | Final audit compliance verification |

---

## 4. Mapping of All 20 Edge Cases to Test Tiers

| # | Edge Case Scenario | Test Tier | Targeted Test Suite | Verification Assertion / Expected Oracle |
|---|---|---|---|---|
| **EC-1** | Group Join Concurrency (2 users on 1 slot) | Tier 2 & Security | `tests/Security/ConcurrencyRowLockTest.php` | `SELECT ... FOR UPDATE` acquires row lock. User 1 commits successfully (200). User 2 receives `409 Conflict` ("Group is already full"). Capacity never exceeds `max_size`. |
| **EC-2** | Student Gradebook BOLA (`?student_id=99`) | Tier 2 & RoleMatrix | `tests/RoleMatrix/BolaIdorMatrixTest.php` | Student A requesting Student B's grades receives `403 Forbidden`. No grade data returned. |
| **EC-3** | Lecturer Class BOLA (LC07 lecturer accesses LD07) | Tier 2 & RoleMatrix | `tests/RoleMatrix/LecturerRoleMatrixTest.php` | Lecturer unassigned to class attempting to view/edit attendance receives `403 Forbidden`. |
| **EC-4** | Operator Separation of Duties (Grade modification) | Tier 2 & RoleMatrix | `tests/RoleMatrix/OperatorSeparationOfDutiesTest.php` | Operator attempting to POST `/courses/{id}/gradebook/update` receives `403 Forbidden`. Audit log records unauthorized attempt. |
| **EC-5** | Audit Log Tampering (`DELETE FROM audit_logs`) | Tier 2 & Security | `tests/Security/AuditLogImmutabilityTest.php` | App DB user `lms_app` executing `DELETE` or `UPDATE` on `audit_logs` triggers MySQL Error 1142 / Access Denied. Logs remain immutable. |
| **EC-6** | Forum Edit Window Expiration (16 minutes) | Tier 2 & Unit | `tests/Unit/Forum/ForumEditWindowTest.php` | Student editing post at 901 seconds receives `403 Forbidden` ("Edit window expired"). Post content unchanged. |
| **EC-7** | File Upload Extension Bypass (`shell.php.png`, `.phtml`) | Tier 2 & Security | `tests/Security/SafeUploadPipelineTest.php` | Upload of `.phtml` or `.php.png` rejected with `422 Unprocessable Entity`. Stored files assigned UUIDv4 without original extension. |
| **EC-8** | File Upload Polyglot / MIME Spoof (`GIF89a<?php`) | Tier 2 & Security | `tests/Security/SafeUploadPipelineTest.php` | Upload with disguised header inspected via `finfo_open` magic bytes. Mismatched extension/MIME returns `422 Unprocessable Entity`. |
| **EC-9** | SVG Stored XSS Upload (`<script>` in SVG) | Tier 2 & Security | `tests/Security/SafeUploadPipelineTest.php` | SVG extension not in allowlist (`pdf`, `png`, `jpg`, `jpeg`, `txt`, `zip`). Rejected with `422 Unprocessable Entity`. |
| **EC-10**| CSV Formula Injection (`=cmd|'...'`, `+SUM`, `@`, `\t`) | Tier 2 & Security | `tests/Security/CsvFormulaInjectionTest.php` | CSV exporter prefixes leading `=`, `+`, `-`, `@`, `\t`, `\r` with `'`. Formula payload rendered as inert text string. |
| **EC-11**| SQL Injection in `ORDER BY` (`sort=id;DROP TABLE`) | Tier 2 & Security | `tests/Security/SqlInjectionAndOrderByTest.php` | Dynamic sort column checked against strict array allowlist. Unlisted column returns `400 Bad Request`. Database untouched. |
| **EC-12**| CSRF Token Missing or Reused across sessions | Tier 2 & Security | `tests/Security/CsrfProtectionTest.php` | Missing or foreign session CSRF token on state-changing verb returns `403 Forbidden`. Transaction aborted. |
| **EC-13**| Session Fixation on Authentication | Tier 2 & Security | `tests/Security/SessionLifecycleTest.php` | Pre-existing session ID regenerated to new cryptographically random ID via `session_regenerate_id(true)` immediately on login. |
| **EC-14**| Session Idle Timeout (Inactivity > 15m) | Tier 2 & Security | `tests/Security/SessionLifecycleTest.php` | Request with `last_activity` > 900s destroys session, clears cookie, and redirects to `/login?reason=timeout`. |
| **EC-15**| Session Absolute Lifetime (> 12h) | Tier 2 & Security | `tests/Security/SessionLifecycleTest.php` | Request with `created_at` > 43200s destroys session regardless of recent activity, demanding re-authentication. |
| **EC-16**| Login Brute Force Lockout (5 failed attempts) | Tier 2 & Security | `tests/Security/RateLimiterLockoutTest.php` | 5th consecutive failed attempt locks account for 15 minutes. 6th attempt returns `429 Too Many Requests`. |
| **EC-17**| Forum Markdown Stored XSS (`<script>`, `javascript:`) | Tier 2 & Security | `tests/Security/XssAndCspTest.php` | Markdown parser strips raw HTML and blocks non-http/https protocols. CSP blocks execution even if injected. |
| **EC-18**| Room Double Booking Conflict | Tier 2 & Integration | `tests/Integration/ScheduleConflictTest.php` | Operator booking Room `Anggrek - 605` during overlapping time slot returns `409 Conflict`. Double booking prevented. |
| **EC-19**| ICS Calendar BOLA (`export.ics?user_id=2`) | Tier 2 & RoleMatrix | `tests/RoleMatrix/BolaIdorMatrixTest.php` | ICS export ignores external `user_id` query param and exports strictly authenticated user's schedule. |
| **EC-20**| Bulk Grade CSV Mismatched Class NIM | Tier 2 & Integration | `tests/Integration/BulkGradeImportTest.php` | CSV upload containing un-enrolled NIM flagged in dry-run preview. Transaction aborted; zero grades written to DB. |

---

## 5. Test Directory Layout

```
tests/
├── Support/
│   ├── TestCase.php              # Base test case with assertions & test lifecycle
│   ├── TestRunner.php            # Standalone test runner engine
│   ├── HttpSimulator.php         # In-memory HTTP request/response simulator
│   ├── DatabaseFixture.php       # In-memory / transactional DB fixture helper
│   └── MockSession.php           # Isolated session mock for security testing
├── RoleMatrix/
│   ├── AnonymousAccessTest.php   # 401 verification across all protected endpoints
│   ├── StudentRoleMatrixTest.php # Enrolled permissions vs unassigned 403
│   ├── LecturerRoleMatrixTest.php# Assigned permissions vs unassigned 403
│   ├── OperatorSeparationOfDutiesTest.php # Separation of duties (grades/notes 403)
│   └── BolaIdorMatrixTest.php    # Object-level ownership & parameter tampering
├── Security/
│   ├── Argon2idAuthTest.php      # Password hashing parameters and verification
│   ├── RateLimiterLockoutTest.php# Exponential backoff and account locking
│   ├── CsrfProtectionTest.php    # Token generation, validation, and mismatch 403
│   ├── SqlInjectionAndOrderByTest.php # Prepared statements & sort allowlisting
│   ├── XssAndCspTest.php         # Contextual escaping, safe Markdown, CSP nonce
│   ├── SafeUploadPipelineTest.php# 7-stage upload verification & MIME inspection
│   ├── CsvFormulaInjectionTest.php # Formula injection neutralization
│   ├── ConcurrencyRowLockTest.php# Pessimistic locking (SELECT ... FOR UPDATE)
│   └── AuditLogImmutabilityTest.php # Immutability of audit logs
├── Unit/                         # Tier 1 & Tier 2 unit test suites
├── Integration/                  # Tier 3 multi-feature interaction test suites
└── E2E/                          # Tier 4 full-semester workflow scenarios
```

---

## 6. Test Runner Mechanics & Execution Harness

### 6.1 Test Runner Script (`scripts/run_tests.sh`)
The test runner is a standalone, colorized shell script executing tests across the framework:
- Can be executed directly on the host CLI: `./scripts/run_tests.sh`
- Can be executed inside the web container: `docker compose exec web ./scripts/run_tests.sh`
- Supports filtering: `./scripts/run_tests.sh RoleMatrix`, `./scripts/run_tests.sh Security`, `./scripts/run_tests.sh --filter=Argon2id`
- Exit Code Semantics:
  * Returns **`0`** if and only if **100% of tests pass**.
  * Returns **`1`** immediately if any test fails or an uncaught error occurs.

### 6.2 Output Reporting Format
The runner reports:
1. Header with environment information (PHP version, OS, timestamp, test target).
2. Per-test progress indicators with test names and execution status.
3. Detailed failure diagnostics: file path, line number, expected vs actual values, assertion failure message.
4. Summary box with total test files, total test cases, total assertions, passed count, failed count, and execution time.
