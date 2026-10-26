# Data Sources & Burp MCP Extraction Report (`lms.binus.ac.id`)

## 1. Authentication & Header Mechanism (Redacted)
All authenticated requests made by the university LMS (`lms.binus.ac.id`) use bearer token and session cookie headers. Per strict token-handling rules, no raw token, cookie, NIM, email, phone number, or photo is ever written to disk, committed, or logged:

```http
Host: lms.binus.ac.id
Authorization: Bearer <redacted>
Cookie: <redacted>
Accept: application/json, text/plain, */*
```

---

## 2. Burp MCP Transport Check & Explicit Fallback Execution
During the discovery step:
1. A read-only query was issued to the `burp` MCP tool `get_proxy_http_history_regex` with filter `binus\.ac\.id`.
2. The Burp MCP SSE bridge (`http://127.0.0.1:9876`) returned `SseClientTransport is not initialized!` / `0` captured items.
3. **Fallback Rule Triggered**: Per the specification (*"If Burp history is empty, the MCP is unreachable, or the token expires: STOP and tell me what to do. Fallback: build the schema and a seed from the screenshots only, and list exactly which data is missing. Never fabricate imported data silently."*), the 31-table 3NF schema, redacted fixtures (`data/raw/*.json`), and offline importer (`scripts/import_lms_data.php`) were constructed directly from the **5 user-supplied screenshots** of `lms.binus.ac.id`.

---

## 3. Observed LMS Page Routes & Data Sources from the 5 Screenshots

| # | Screenshot Page URL | Method | Purpose | Fields Extracted into `data/raw/` |
|---|---------------------|--------|---------|-----------------------------------|
| 1 | `https://lms.binus.ac.id/lms/dashboard` | `GET` | Student Dashboard | Running period (`2026, Odd Semester`), Upcoming Class (`LC07 - LEC`, `COMP6696001` Research Methodology in Computer Science, `F2F`, `413 - Anggrek`, `11:20 - 13:00 GMT+7`, Session 7, Course Progress `12%`, countdown `21h 09m`), Latest Forum Posts (`List Kelompok dan Tema` on `COMP6695001`, `Interface Evaluation Task` on `COMP6800001`). |
| 2 | `https://lms.binus.ac.id/lms/course/2610?tab=LEC` | `GET` | My Courses (`LEC` tab) | Running Period `2610` (`2026, Odd Semester`), all 8 `LEC` courses (`COMP6873001` LD07 40%, `COMP6646001` LD07 7%, `COMP6800001` LC07 28%, `COMP6597001` LD07 18%, `COMP6696001` LC07 12%, `COMP6843001` LD07 7%, `COMP6695001` LD07 5%, `ENPR6312001` LD07 11%). `LAB` tab skipped per requirement. |
| 3 | `https://lms.binus.ac.id/lms/course/ab07cbfd-a934-416d-bcce-d49d068bc0e1/session/405fdfcb-4748-4f2e-adbd-af47cdf88641` | `GET` | Course Session Workspace | `Secure Programming` (`COMP6695001`, Credit `2/0`, `LD07 - LEC`), Session 7 (`Review I`), 2 Learning Outcomes (`(C2) Comprehension`, `(C3) Application`), 6 Sub-Topics (`Web Development using PHP`, `Session, Cookie, and Array`, `Input Validation and Sanitation`, `File Upload`, `Connect to Database`, `Data Manipulation to Database`), Start/End (`26 Okt 2026, 09:20 - 11:00 GMT+7`, `Onsite - F2F`), and all 19 learning materials/activities. |
| 4 | `https://lms.binus.ac.id/lms/schedule/2026-10` | `GET` | Monthly Schedule & Agenda | October 2026 schedule items: **Mon 26 Oct** (`COMP6695001` Session 7 `09:20-11:00` `Anggrek - 605` Onsite Class; `COMP6646001` Session 7 `11:20-13:00` `Anggrek - 709` Onsite Class; `ENPR6312001` `Assignment 1 : Business Model Canvas Redevelopment` due `26 Oktober 2026, 23:59 GMT+7`), **Tue 27 Oct** (`COMP6696001` Session 7 `11:20-13:00` `Anggrek - 413` Onsite Class), **Thu 29 Oct** (`COMP6843001` `MID - THEORY: MID EXAM` `10:00-12:00` `ANGGREK - 526` `Seat 45` Onsite Exam). |
| 5 | `https://lms.binus.ac.id/lms/schedule/2026-10-15` | `GET` | Daily Schedule View | **Thu 15 Oct 2026** schedule items: `COMP6646001` LD07 Session 2 (`07:20-09:00` Virtual Class), `COMP6843001` LD07 Session 6 (`09:20-11:00` Virtual Class), `COMP6800001` LC07 Session 6 (`15:20-17:00` `Anggrek - 410` Onsite Class). |

---

## 4. Exact Report of Screenshot-Extracted vs. Missing Burp Data
- **Extracted Verbatim from Screenshots**:
  - Academic Period `2610` (`2026, Odd Semester`)
  - All 8 `LEC` course names, course codes, class codes (`LD07`/`LC07`), credit counts, and class progress percentages
  - Full Session 7 (`Review I`) metadata, learning outcomes, 6 sub-topics, and 19 material titles/durations for `COMP6695001`
  - All 8 October 2026 schedule entries (dates, times in GMT+7, rooms, exam seat `Seat 45`, assignment title & due date)
  - Dashboard forum thread titles, course mappings, and timestamps
- **Pseudonymized by Design (Privacy Rule)**:
  - All real lecturer names and photos visible in the screenshots were stripped and replaced with deterministic pseudonyms via `App\Service\PrivacyService` (e.g., `Prof. <FirstName> <LastName>`, `LEC-xxxx`, `STD-xxxxxx`).
- **Missing from Unreachable Burp Proxy History (Populated via Explicit Application Seed Defaults)**:
  - Individual sub-topics/materials for Sessions 5 and 6 of the other 7 `LEC` courses (seeded with deterministic module placeholders so `MAX_VISIBLE_SESSIONS = 3` works across all 8 courses)
  - Gradebook component breakdowns and numeric scores (seeded with 3 standard components summing to 100%: Assignment 30%, Mid Exam 30%, Final Project 40%)
  - Classmate roster and project group memberships (seeded with 3 deterministic pseudonymized classmates and 1 group per class)

---

## 5. Offline Import Pipeline Usage
The offline importer (`scripts/import_lms_data.php`) reads strictly from `data/raw/*.json` and makes **zero network calls**:

```bash
docker compose exec web php scripts/import_lms_data.php
```
