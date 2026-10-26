<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var string $csrfToken */
/** @var string $cspNonce */
/** @var callable $t */
/** @var string $content */
$activeNav = $activeNav ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="<?= $e->attr($lang ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($pageTitle ?? 'Secure Neo-Brutalist LMS') ?> — SECURE LMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="nb-shell">
    <!-- STRICT 3-MENU SIDEBAR: Dashboard, Courses, Schedule ONLY -->
    <aside class="nb-sidebar" aria-label="Primary Navigation">
        <a href="/dashboard" class="nb-brand">SECURE LMS</a>

        <?php if (!$currentUser->isAnonymous()): ?>
            <div class="nb-role-box">
                <div style="font-size: 0.72rem; text-transform: uppercase; color: #FFE600; font-weight: 900;">Active Role</div>
                <div style="font-size: 1rem; font-weight: 900; text-transform: uppercase; margin-top: 0.15rem;">
                    <?= $e($currentUser->role) ?>
                </div>
                <div style="font-size: 0.8rem; margin-top: 0.2rem;">
                    <?= $e($currentUser->fullName) ?>
                </div>
                <?php if ($currentUser->studentIdAlias): ?>
                    <div style="font-size: 0.75rem; color: #A3E635;"><?= $e($currentUser->studentIdAlias) ?> &bull; Undergraduate</div>
                <?php elseif ($currentUser->lecturerCodeAlias): ?>
                    <div style="font-size: 0.75rem; color: #FF66B2;"><?= $e($currentUser->lecturerCodeAlias) ?> &bull; Faculty</div>
                <?php endif; ?>
            </div>

            <nav aria-label="Main Menu">
                <ul class="nb-nav">
                    <li>
                        <a href="/dashboard" class="nb-nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                            [1] <?= $e($t('dashboard')) ?>
                        </a>
                    </li>
                    <li>
                        <a href="/courses" class="nb-nav-link <?= $activeNav === 'courses' ? 'active' : '' ?>">
                            [2] <?= $e($t('courses')) ?>
                        </a>
                    </li>
                    <?php if ($currentUser->role !== 'operator'): ?>
                        <li>
                            <a href="/schedule" class="nb-nav-link <?= $activeNav === 'schedule' ? 'active' : '' ?>">
                                [3] <?= $e($t('schedule')) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

        <div style="margin-top: auto; font-size: 0.72rem; border-top: 2px solid #444; padding-top: 0.75rem;">
            OWASP ASVS L2 COMPLIANT<br>
            2026 ODD SEMESTER
        </div>
    </aside>

    <div class="nb-main-wrap">
        <header class="nb-topbar">
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span class="nb-badge nb-badge-orange">2026, Odd Semester</span>
                <?php if (!$currentUser->isAnonymous()): ?>
                    <form action="/search" method="GET" style="display: flex; gap: 0.4rem; align-items: center;">
                        <label for="global-search-q" style="font-weight: 800; font-size: 0.8rem;">SEARCH:</label>
                        <input id="global-search-q" type="search" name="q" value="<?= $e->attr($_GET['q'] ?? '') ?>" placeholder="Courses, forum..." style="border: 3px solid #000; padding: 0.3rem 0.5rem; font-family: inherit; font-weight: 700;">
                        <button type="submit" class="nb-btn" style="padding: 0.3rem 0.6rem;">GO</button>
                    </form>
                <?php endif; ?>
            </div>

            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <!-- 1. Day & Night Theme Switch Logo -->
                <button type="button" id="nb-theme-toggle" class="nb-btn nb-btn-yellow nb-icon-btn" title="Switch Day / Night Theme" aria-label="Toggle Day and Night Mode">
                    <svg class="nb-theme-icon-sun" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <line x1="12" y1="2" x2="12" y2="5"></line>
                        <line x1="12" y1="19" x2="12" y2="22"></line>
                        <line x1="4.93" y1="4.93" x2="7.05" y2="7.05"></line>
                        <line x1="16.95" y1="16.95" x2="19.07" y2="19.07"></line>
                        <line x1="2" y1="12" x2="5" y2="12"></line>
                        <line x1="19" y1="12" x2="22" y2="12"></line>
                        <line x1="4.93" y1="19.07" x2="7.05" y2="16.95"></line>
                        <line x1="16.95" y1="7.05" x2="19.07" y2="4.93"></line>
                    </svg>
                    <svg class="nb-theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                </button>

                <?php if (!$currentUser->isAnonymous()): ?>
                    <!-- 2. Notification Bell Logo (Lecturer Threads & Assignments Only) -->
                    <a href="/notifications" class="nb-btn nb-btn-lime nb-icon-btn" title="Notifications (Lecturer Threads &amp; Assignments)" aria-label="Notifications" style="position: relative;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <span style="position: absolute; top: 4px; right: 4px; width: 9px; height: 9px; background: #FF6B00; border: 2px solid #000; border-radius: 50%;" aria-hidden="true"></span>
                    </a>

                    <!-- 3. Default Profile Avatar Icon (No Selfie Uploaded Yet) -->
                    <div class="nb-avatar-menu">
                        <button type="button" id="nb-avatar-btn" class="nb-btn nb-btn-pink nb-icon-btn" title="<?= $e->attr($currentUser->fullName . ' (' . strtoupper($currentUser->role) . ') — No selfie uploaded yet') ?>" aria-label="User Profile Menu" aria-expanded="false" aria-controls="nb-avatar-popover">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>
                            </svg>
                        </button>
                        <div id="nb-avatar-popover" class="nb-popover" role="menu">
                            <div style="display: flex; align-items: center; gap: 0.65rem; border-bottom: 3px solid #000; padding-bottom: 0.6rem; margin-bottom: 0.65rem;">
                                <div style="width: 44px; height: 44px; background: #F4F4F0; border: 3px solid #000; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" title="No selfie uploaded yet">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="12" cy="8" r="4"></circle>
                                        <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>
                                    </svg>
                                </div>
                                <div style="font-weight: 900; line-height: 1.25;">
                                    <div><?= $e($currentUser->fullName) ?></div>
                                    <div style="font-size: 0.72rem; font-weight: 700; color: #333;"><?= $e($currentUser->email) ?></div>
                                    <span class="nb-badge nb-badge-orange" style="font-size: 0.62rem; margin-top: 0.2rem;"><?= $e(strtoupper($currentUser->role)) ?> &bull; NO SELFIE YET</span>
                                </div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.45rem;">
                                <a href="/profile" class="nb-btn nb-btn-yellow" style="text-align: left;">Profile &amp; Change Password</a>
                                <a href="/profile/export-data" class="nb-btn" style="text-align: left;">Download My Data (JSON)</a>
                                <form action="/logout" method="POST" style="margin-top: 0.25rem;">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <button type="submit" class="nb-btn nb-btn-pink" style="width: 100%;">Logout Session</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <main class="nb-content">
            <?php if (!empty($flashSuccess)): ?>
                <div class="nb-alert nb-alert-success" role="status"><?= $e($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($flashError)): ?>
                <div class="nb-alert nb-alert-error" role="alert"><?= $e($flashError) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>
<script src="/assets/js/app.js" nonce="<?= $e->attr($cspNonce) ?>"></script>
</body>
</html>
