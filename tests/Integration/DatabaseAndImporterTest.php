<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\User;
use App\Security\AvScannerHook;
use App\Security\Csrf;
use App\Security\Escaper;
use App\Security\Markdown;
use App\Security\Policy;
use App\Service\CsvService;
use App\Service\IcsExporter;
use App\Service\PrivacyService;
use App\Service\TotpService;
use PDO;
use Tests\Support\TestCase;

final class DatabaseAndImporterTest extends TestCase
{
    public function testPrivacyServiceDeterministicPseudonymization(): void
    {
        $name1 = PrivacyService::pseudonymizeName('lecturer_comp6695001', 'lecturer');
        $name2 = PrivacyService::pseudonymizeName('lecturer_comp6695001', 'lecturer');

        $this->assertSame($name1, $name2, 'Pseudonymization must be deterministic for the same seed.');
        $this->assertStringStartsWith('Prof. ', $name1);

        $stuAlias = PrivacyService::pseudonymizeStudentAlias('primary_student_account');
        $this->assertStringStartsWith('STD-', $stuAlias);
    }

    public function testTotpGenerationAndVerification(): void
    {
        $secret = TotpService::generateSecret();
        $this->assertSame(16, strlen($secret));

        $code = TotpService::getCode($secret);
        $this->assertSame(6, strlen($code));
        $this->assertTrue(TotpService::verifyCode($secret, $code));
        $this->assertFalse(TotpService::verifyCode($secret, '000000') && $code !== '000000');
    }

    public function testProductionPolicyBolaAndSeparationOfDuties(): void
    {
        $student = new User(
            id: 10,
            uuid: 'u-stu',
            role: 'student',
            username: 'student',
            email: 'student@lms.local',
            fullName: 'Student Alias',
            enrolledCourseIds: [1, 2, 7]
        );
        $lecturer = new User(
            id: 20,
            uuid: 'u-lec',
            role: 'lecturer',
            username: 'lecturer',
            email: 'lecturer@lms.local',
            fullName: 'Prof. Alias',
            assignedCourseIds: [7]
        );
        $operator = new User(
            id: 30,
            uuid: 'u-op',
            role: 'operator',
            username: 'operator',
            email: 'operator@lms.local',
            fullName: 'Ops. Alias'
        );

        // Student can view enrolled course 7, blocked from unenrolled course 99 (IDOR)
        $this->assertSame(200, Policy::evaluateRoute($student, '/courses/7'));
        $this->assertSame(403, Policy::evaluateRoute($student, '/courses/99'));

        // Student blocked from viewing another student's gradebook (BOLA)
        $this->assertSame(403, Policy::evaluateRoute($student, '/courses/7/gradebook', 'GET', 999));
        $this->assertSame(200, Policy::evaluateRoute($student, '/courses/7/gradebook', 'GET', 10));

        // Lecturer can manage assigned course 7, blocked from unassigned course 2 (IDOR)
        $this->assertSame(200, Policy::evaluateRoute($lecturer, '/courses/7/gradebook/update', 'POST'));
        $this->assertSame(403, Policy::evaluateRoute($lecturer, '/courses/2/gradebook/update', 'POST'));

        // Separation of Duties: Operator blocked from gradebook and private lecturer notes
        $this->assertSame(403, Policy::evaluateRoute($operator, '/courses/7/gradebook'));
        $this->assertSame(403, Policy::evaluateRoute($operator, '/courses/7/gradebook/update', 'POST'));
        $this->assertSame(403, Policy::evaluateRoute($operator, '/courses/7/lecturer-notes'));
        $this->assertSame(200, Policy::evaluateRoute($operator, '/operator/users'));

        // Audit logs cannot be mutated by any role
        $this->assertSame(403, Policy::evaluateRoute($operator, '/audit-logs/1', 'DELETE'));
    }

    public function testMarkdownAndEscaperNeutralizeXss(): void
    {
        $html = Markdown::render("Hello **World** <script>alert(1)</script> [bad](javascript:alert(1)) [ok](https://example.com)");
        $this->assertStringContainsString('<strong>World</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertSame('#unsafe-url-blocked', Escaper::url('javascript:alert(1)'));
    }

    public function testCsvAndIcsServices(): void
    {
        $csv = CsvService::exportTable(['Name', 'Formula'], [['Alice', '=cmd|calc']]);
        $this->assertStringContainsString("Alice,'=cmd|calc", $csv);

        $ics = IcsExporter::generate([
            [
                'uuid' => 'e100',
                'course_code' => 'COMP6695001',
                'title' => 'Review I',
                'start_time' => '2026-10-26 09:20:00',
                'end_time' => '2026-10-26 11:00:00',
                'room' => 'Anggrek - 605',
                'badge_label' => 'Onsite Class',
                'details' => 'Session 7',
            ],
        ], 'student');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:COMP6695001 - Review I', $ics);
    }

    public function testLiveDatabaseSeededDataAndImmutability(): void
    {
        try {
            $pdo = Database::getConnection();
        } catch (\Throwable) {
            // Skip live DB assertions if running outside container network
            $this->assertTrue(true);
            return;
        }

        // Verify 4 LEC courses seeded (2 per lecturer)
        $lecCount = (int) $pdo->query("SELECT COUNT(*) FROM classes WHERE delivery_type = 'LEC'")->fetchColumn();
        $this->assertSame(4, $lecCount, 'Must have exactly 4 LEC classes seeded for Term 2610.');

        // Verify Session 1 of COMP6695001 has 2 learning outcomes, 6 subtopics, and 19 materials
        $s7Id = (int) $pdo->query("
            SELECT cs.id FROM course_sessions cs
            JOIN classes cls ON cls.id = cs.class_id
            JOIN courses c ON c.id = cls.course_id
            WHERE c.code = 'COMP6695001' AND cs.session_number = 1
            LIMIT 1
        ")->fetchColumn();
        $this->assertGreaterThan(0, $s7Id);

        $loCount = (int) $pdo->query("SELECT COUNT(*) FROM session_learning_outcomes WHERE session_id = {$s7Id}")->fetchColumn();
        $stCount = (int) $pdo->query("SELECT COUNT(*) FROM session_sub_topics WHERE session_id = {$s7Id}")->fetchColumn();
        $matCount = (int) $pdo->query("SELECT COUNT(*) FROM session_materials WHERE session_id = {$s7Id}")->fetchColumn();

        $this->assertSame(2, $loCount, 'Session 7 must have 2 learning outcomes.');
        $this->assertSame(6, $stCount, 'Session 7 must have 6 sub-topics.');
        $this->assertSame(19, $matCount, 'Session 7 must have 19 learning materials.');

        // Verify audit_logs immutability trigger rejects DELETE
        $deleteBlocked = false;
        try {
            $pdo->exec("DELETE FROM audit_logs LIMIT 1");
        } catch (\PDOException $e) {
            $deleteBlocked = true;
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }
        $this->assertTrue($deleteBlocked, 'audit_logs trigger must reject DELETE.');
    }
}
