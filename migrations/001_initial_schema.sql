-- =============================================================================
-- Migration: 001_initial_schema.sql
-- Description: Initial 31-table 3NF schema for Secure Neo-Brutalist LMS
-- Database Engine: MySQL 8.4 LTS (InnoDB)
-- Default Charset: utf8mb4
-- Default Collation: utf8mb4_unicode_ci
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 0. Schema Migrations Tracking
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Academic Periods (Semesters)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academic_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,          -- e.g. '2610'
    name VARCHAR(100) NOT NULL,                 -- e.g. '2026, Odd Semester'
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_period_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Users (RBAC & Authentication)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    role ENUM('student', 'lecturer', 'operator') NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,        -- Argon2id
    full_name VARCHAR(150) NOT NULL,            -- Pseudonymized display name
    student_id_alias VARCHAR(50) NULL UNIQUE,   -- Pseudonymized NIM alias
    lecturer_code_alias VARCHAR(50) NULL UNIQUE,-- Pseudonymized faculty alias
    academic_title VARCHAR(100) NULL,
    totp_secret VARCHAR(64) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    password_reset_token_hash CHAR(64) NULL,
    password_reset_expires_at TIMESTAMP NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_role (role),
    INDEX idx_user_email (email),
    INDEX idx_user_username (username),
    INDEX idx_user_lockout (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. User Sessions (Revocable Server-Side Session Tracking)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,                -- SHA-256 hash of session token
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (user_id),
    INDEX idx_session_activity (last_activity_at),
    INDEX idx_session_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Courses Catalog (Master 8 LEC Courses)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    code VARCHAR(20) NOT NULL UNIQUE,          -- e.g. 'COMP6695001'
    name VARCHAR(150) NOT NULL,                 -- e.g. 'Secure Programming'
    credits_lecture INT NOT NULL DEFAULT 2,
    credits_lab INT NOT NULL DEFAULT 0,
    category VARCHAR(100) NOT NULL DEFAULT 'Core Courses',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_course_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Classes (Sections e.g. LD07, LC07 for Term 2610)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    course_id BIGINT UNSIGNED NOT NULL,
    academic_period_id INT UNSIGNED NOT NULL,
    class_code VARCHAR(20) NOT NULL,            -- e.g. 'LD07', 'LC07'
    delivery_type ENUM('LEC', 'LAB') NOT NULL DEFAULT 'LEC',
    class_progress_pct INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_class (course_id, academic_period_id, class_code, delivery_type),
    CONSTRAINT fk_class_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_class_period FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE RESTRICT,
    INDEX idx_class_course (course_id),
    INDEX idx_class_period (academic_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Course Enrollments (Student Enrolled in Classes)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    status ENUM('enrolled', 'dropped', 'completed') NOT NULL DEFAULT 'enrolled',
    enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_enrollment (user_id, class_id),
    CONSTRAINT fk_enrollment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    INDEX idx_enrollment_class (class_id),
    INDEX idx_enrollment_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Class Lecturers (Faculty Assigned to Classes)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS class_lecturers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    role ENUM('lead_lecturer', 'co_lecturer', 'facilitator') NOT NULL DEFAULT 'lead_lecturer',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lecturer_assignment (user_id, class_id),
    CONSTRAINT fk_lecturer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lecturer_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    INDEX idx_lecturer_class (class_id),
    INDEX idx_lecturer_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Uploaded Files (Centralized File Storage Outside Webroot)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploaded_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    original_filename VARCHAR(255) NOT NULL,
    storage_filename VARCHAR(255) NOT NULL UNIQUE,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NOT NULL,
    sha256_hash CHAR(64) NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    av_scan_status ENUM('pending', 'clean', 'infected') NOT NULL DEFAULT 'clean',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upload_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_upload_user (uploaded_by),
    INDEX idx_upload_hash (sha256_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Course Sessions (Sessions 1-13; MAX_VISIBLE_SESSIONS=3 in UI)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    session_number INT NOT NULL,               -- e.g. 7
    title VARCHAR(200) NOT NULL,               -- e.g. 'Review I'
    delivery_mode VARCHAR(50) NOT NULL DEFAULT 'Onsite - F2F',
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    room VARCHAR(100) NULL,                    -- e.g. 'Anggrek - 605'
    status ENUM('planned', 'ongoing', 'completed') NOT NULL DEFAULT 'planned',
    attendance_window_open TINYINT(1) NOT NULL DEFAULT 0,
    attendance_opened_at DATETIME NULL,
    attendance_closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_session (class_id, session_number),
    CONSTRAINT fk_session_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    INDEX idx_session_class_num (class_id, session_number),
    INDEX idx_session_times (start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Session Learning Outcomes (Bloom's Taxonomy Breakdown)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS session_learning_outcomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    bloom_level VARCHAR(10) NOT NULL,          -- e.g. '(C2)', '(C3)'
    bloom_category VARCHAR(50) NOT NULL,       -- e.g. 'Comprehension', 'Application'
    description TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_outcome_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE CASCADE,
    INDEX idx_outcome_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Session Sub Topics
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS session_sub_topics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    topic_name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subtopic_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE CASCADE,
    INDEX idx_subtopic_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Session Materials & Activities (The 19 Items for Session 7)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS session_materials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    material_type ENUM('attendance', 'slide', 'link', 'cheat_sheet', 'document', 'assignment') NOT NULL,
    duration_label VARCHAR(50) NOT NULL,       -- e.g. '1h 40m', '10m'
    duration_minutes INT NOT NULL DEFAULT 10,
    url VARCHAR(500) NULL,
    file_id BIGINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_material_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_file FOREIGN KEY (file_id) REFERENCES uploaded_files(id) ON DELETE SET NULL,
    INDEX idx_material_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. Assignments
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    due_date DATETIME NOT NULL,
    late_policy TEXT NULL,
    max_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignment_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_assignment_class (class_id),
    INDEX idx_assignment_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. Assignment Submissions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignment_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    file_id BIGINT UNSIGNED NULL,
    score DECIMAL(5,2) NULL,
    feedback TEXT NULL,
    graded_by BIGINT UNSIGNED NULL,
    graded_at DATETIME NULL,
    UNIQUE KEY uq_assignment_student (assignment_id, student_id),
    CONSTRAINT fk_sub_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_file FOREIGN KEY (file_id) REFERENCES uploaded_files(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_grader FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sub_assignment (assignment_id),
    INDEX idx_sub_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. Schedule Entries (Calendar & Agenda Events in GMT+7)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedule_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    entry_type ENUM('onsite_class', 'virtual_class', 'online', 'onsite_exam', 'assignment') NOT NULL,
    badge_label VARCHAR(50) NOT NULL,          -- e.g. 'Onsite Class', 'Virtual Class', 'Assignment', 'Onsite Exam'
    title VARCHAR(255) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Jakarta',
    room VARCHAR(100) NULL,
    seat_number VARCHAR(50) NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sched_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sched_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_sched_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE SET NULL,
    INDEX idx_sched_times (start_time, end_time),
    INDEX idx_sched_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. Session Attendance
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS session_attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    status ENUM('present', 'late', 'absent', 'excused') NOT NULL DEFAULT 'absent',
    marked_by BIGINT UNSIGNED NULL,
    marked_at DATETIME NULL,
    edit_reason VARCHAR(255) NULL,             -- Mandatory for manual lecturer adjustments
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_student_att (session_id, student_id),
    CONSTRAINT fk_att_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_marker FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_att_session (session_id),
    INDEX idx_att_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 17. Attendance Excuse Requests
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_excuse_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    attachment_file_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    review_notes TEXT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_excuse_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_excuse_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_excuse_attachment FOREIGN KEY (attachment_file_id) REFERENCES uploaded_files(id) ON DELETE SET NULL,
    CONSTRAINT fk_excuse_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_excuse_session (session_id),
    INDEX idx_excuse_student (student_id),
    INDEX idx_excuse_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18. Forum Categories
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_forum_cat_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    INDEX idx_forum_cat_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 19. Forum Threads
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_threads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    deleted_by BIGINT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_thread_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_thread_session FOREIGN KEY (session_id) REFERENCES course_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_category FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_thread_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_thread_deleter FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_thread_list (class_id, session_id, is_pinned, created_at),
    INDEX idx_forum_thread_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 20. Forum Posts (Markdown Raw + Sanitized HTML)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    thread_id BIGINT UNSIGNED NOT NULL,
    parent_post_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    body_raw TEXT NOT NULL,
    body_html TEXT NOT NULL,
    is_accepted_answer TINYINT(1) NOT NULL DEFAULT 0,
    edit_count INT NOT NULL DEFAULT 0,
    last_edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by BIGINT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_thread FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_post_parent FOREIGN KEY (parent_post_id) REFERENCES forum_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_post_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_post_deleter FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_forum_posts_thread (thread_id, created_at),
    INDEX idx_forum_posts_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 21. Forum Thread Read Status
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_thread_reads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_thread_read (user_id, thread_id),
    CONSTRAINT fk_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_read_thread FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    INDEX idx_read_user (user_id),
    INDEX idx_read_thread (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 22. Forum Post Reports (Student Flagging -> Lecturer -> Operator Escalation)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_post_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    reporter_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending', 'resolved', 'escalated_to_operator', 'dismissed') NOT NULL DEFAULT 'pending',
    action_taken VARCHAR(255) NULL,
    handled_by BIGINT UNSIGNED NULL,
    handled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_report_post FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_report_handler FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_post_reports_status (status),
    INDEX idx_post_reports_post (post_id),
    INDEX idx_post_reports_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 23. Grade Components (Weights Summing to 100%)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grade_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,                -- e.g. 'Assignment 1', 'Mid Exam'
    weight_pct DECIMAL(5,2) NOT NULL,          -- 0.00 to 100.00
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_grade_comp_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_comp_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_grade_comp_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 24. Student Grades (Scores 0.00 to 100.00)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_grades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    score DECIMAL(5,2) NOT NULL,               -- 0.00 to 100.00
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_component_grade (component_id, student_id),
    CONSTRAINT fk_grade_component FOREIGN KEY (component_id) REFERENCES grade_components(id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_grades_student (student_id),
    INDEX idx_grades_component (component_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 25. Grade Change History (Immutable Score Audit Trail)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grade_change_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grade_id BIGINT UNSIGNED NOT NULL,
    old_score DECIMAL(5,2) NULL,
    new_score DECIMAL(5,2) NOT NULL,
    change_reason VARCHAR(500) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hist_grade FOREIGN KEY (grade_id) REFERENCES student_grades(id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_changer FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_grade_hist (grade_id),
    INDEX idx_grade_changer (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 26. Grouping Sets (Configurable Group Projects)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grouping_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    class_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,                -- e.g. 'Project Teams'
    min_members INT NOT NULL DEFAULT 1,
    max_members INT NOT NULL DEFAULT 5,
    window_open_at DATETIME NOT NULL,
    window_close_at DATETIME NOT NULL,
    is_self_formed TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('open', 'closed', 'finalized') NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_set_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_group_set_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_group_set_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 27. Student Groups (Groups created within a set; avoids reserved word GROUPS)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    grouping_set_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    status ENUM('pending_approval', 'approved', 'rejected') NOT NULL DEFAULT 'pending_approval',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_set FOREIGN KEY (grouping_set_id) REFERENCES grouping_sets(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_group_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_group_set (grouping_set_id),
    INDEX idx_group_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 28. Group Members (Roster with concurrency protection)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS group_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    role ENUM('leader', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_member (group_id, student_id),
    CONSTRAINT fk_member_group FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_member_student (student_id),
    INDEX idx_member_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 29. Service Tickets (Operator Queue & SLAs)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(30) NOT NULL UNIQUE, -- e.g. 'TICK-2026-0001'
    requester_id BIGINT UNSIGNED NOT NULL,
    category ENUM('attendance_correction', 'group_issue', 'account_problem', 'schedule_change_request', 'other') NOT NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'in_review', 'resolved', 'rejected') NOT NULL DEFAULT 'open',
    priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    internal_notes TEXT NULL,                  -- Operator only
    assigned_operator_id BIGINT UNSIGNED NULL,
    sla_due_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_operator FOREIGN KEY (assigned_operator_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ticket_status (status),
    INDEX idx_ticket_assigned (assigned_operator_id),
    INDEX idx_ticket_requester (requester_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 30. Service Ticket Messages
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_internal_note TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_msg_ticket FOREIGN KEY (ticket_id) REFERENCES service_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_ticket_msg_ticket (ticket_id),
    INDEX idx_ticket_msg_sender (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 31. Audit Logs (Immutable, Append-Only)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,             -- e.g. 'auth.login.success', 'grade.edit'
    target_type VARCHAR(50) NULL,             -- e.g. 'student_grades', 'service_tickets'
    target_id VARCHAR(50) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action),
    INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Database-Level Immutability Enforcement for audit_logs
-- -----------------------------------------------------------------------------
DELIMITER $$

DROP TRIGGER IF EXISTS trg_audit_logs_no_update$$
CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Security Policy Violation: audit_logs is an immutable append-only table (UPDATE prohibited).';
END$$

DROP TRIGGER IF EXISTS trg_audit_logs_no_delete$$
CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Security Policy Violation: audit_logs is an immutable append-only table (DELETE prohibited).';
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
