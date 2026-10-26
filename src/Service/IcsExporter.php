<?php

declare(strict_types=1);

namespace App\Service;

/**
 * RFC 5545 iCalendar (.ics) Personal Schedule Exporter.
 */
final class IcsExporter
{
    public static function generate(array $entries, string $ownerUsername): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Secure Neo-Brutalist LMS//Term 2610//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Secure LMS Schedule (' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ownerUsername) . ')',
            'X-WR-TIMEZONE:Asia/Jakarta',
        ];

        foreach ($entries as $e) {
            $uid = ($e['uuid'] ?? bin2hex(random_bytes(8))) . '@secure-lms.local';
            $dtStart = gmdate('Ymd\THis\Z', strtotime((string) $e['start_time'] . ' Asia/Jakarta'));
            $dtEnd = gmdate('Ymd\THis\Z', strtotime((string) $e['end_time'] . ' Asia/Jakarta'));
            $summary = self::escapeIcs((string) ($e['course_code'] ?? '') . ' - ' . (string) ($e['title'] ?? 'Session'));
            $location = self::escapeIcs((string) ($e['room'] ?? 'Online / Virtual'));
            $desc = self::escapeIcs((string) ($e['badge_label'] ?? '') . ' | ' . (string) ($e['details'] ?? ''));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $dtStart;
            $lines[] = 'DTEND:' . $dtEnd;
            $lines[] = 'SUMMARY:' . $summary;
            $lines[] = 'LOCATION:' . $location;
            $lines[] = 'DESCRIPTION:' . $desc;
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function escapeIcs(string $text): string
    {
        return str_replace(["\\", ";", ",", "\r", "\n"], ["\\\\", "\\;", "\\,", "", "\\n"], $text);
    }
}
