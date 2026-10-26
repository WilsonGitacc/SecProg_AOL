<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\Escaper;
use App\Service\AuthService;

final class View
{
    private const I18N = [
        'en' => [
            'dashboard' => 'Dashboard',
            'courses' => 'Courses',
            'schedule' => 'Schedule',
            'upcoming_class' => 'Upcoming Class',
            'latest_forum_posts' => 'Latest Forum Posts',
            'my_courses' => 'My Courses',
            'my_schedule' => 'My Schedule',
            'logout' => 'Logout',
        ],
        'id' => [
            'dashboard' => 'Dasbor',
            'courses' => 'Mata Kuliah',
            'schedule' => 'Jadwal',
            'upcoming_class' => 'Kelas Berikutnya',
            'latest_forum_posts' => 'Pos Forum Terbaru',
            'my_courses' => 'Mata Kuliah Saya',
            'my_schedule' => 'Jadwal Saya',
            'logout' => 'Keluar',
        ],
    ];

    public static function render(string $template, array $data = [], int $status = 200, bool $withLayout = true): Response
    {
        Session::start();
        $e = new Escaper();
        $csrfToken = Session::getCsrfToken();
        $cspNonce = Session::getCspNonce();
        $currentUser = $data['currentUser'] ?? AuthService::currentUser();
        $lang = ($_SESSION['lang'] ?? 'en') === 'id' ? 'id' : 'en';
        $t = static fn (string $key): string => self::I18N[$lang][$key] ?? (self::I18N['en'][$key] ?? $key);

        $flashSuccess = Session::flash('success');
        $flashError = Session::flash('error');

        $vars = array_merge($data, [
            'e' => $e,
            'csrfToken' => $csrfToken,
            'cspNonce' => $cspNonce,
            'currentUser' => $currentUser,
            'lang' => $lang,
            't' => $t,
            'flashSuccess' => $flashSuccess,
            'flashError' => $flashError,
        ]);

        $viewsDir = dirname(__DIR__, 2) . '/views';
        $templateFile = $viewsDir . '/' . ltrim($template, '/') . '.php';

        // Render inner template in isolated function scope so loop variables never overwrite $t or $e
        $content = self::renderIsolated($templateFile, $vars);

        if ($withLayout) {
            $layoutVars = $vars;
            $layoutVars['content'] = $content;
            $html = self::renderIsolated($viewsDir . '/layout/app.php', $layoutVars);
        } else {
            $html = $content;
        }

        $cspHeader = "default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

        return Response::html($html, $status, [
            'Content-Security-Policy' => $cspHeader,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private static function renderIsolated(string $__templatePath, array $__vars): string
    {
        ob_start();
        extract($__vars, EXTR_SKIP);
        require $__templatePath;
        return (string) ob_get_clean();
    }
}
