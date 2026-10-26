<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var array $upcomingClass */
/** @var array $latestForumPosts */
/** @var array $lecturerAttention */
/** @var array $operatorStats */
/** @var array $operatorTickets */
/** @var array $operatorReports */
?>
<?php if ($currentUser->role === 'student'): ?>
    <!-- STUDENT DASHBOARD: STRICTLY UPCOMING CLASS & LATEST FORUM POSTS ONLY -->
    <div class="nb-grid-2">
        <section class="nb-card" aria-labelledby="student-upcoming-heading">
            <div class="nb-card-header">
                <h1 id="student-upcoming-heading">UPCOMING CLASS</h1>
                <span class="nb-badge nb-badge-lime"><?= $e($upcomingClass['badge_label'] ?? 'Onsite Class') ?></span>
            </div>

            <?php if (!empty($upcomingClass)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span class="nb-badge nb-badge-orange">
                        <?= $e($upcomingClass['class_code']) ?> - <?= $e($upcomingClass['delivery_type']) ?>
                    </span>
                    <span class="nb-badge nb-badge-yellow">COUNTDOWN: 21h 09m</span>
                </div>

                <h2 style="font-size: 1.25rem; margin-bottom: 0.4rem;">
                    <?= $e($upcomingClass['course_name']) ?>
                </h2>

                <p style="font-size: 0.85rem; font-weight: 800; margin-bottom: 0.5rem;">
                    Facilitator: <?= $e($upcomingClass['lecturer_name'] ?? 'Prof. Assigned Faculty') ?>
                    <?= !empty($upcomingClass['academic_title']) ? '(' . $e($upcomingClass['academic_title']) . ')' : '' ?>
                </p>

                <ul style="list-style: none; font-size: 0.85rem; margin-bottom: 1rem;">
                    <li><strong>Mode:</strong> F2F</li>
                    <li><strong>Course Code:</strong> <?= $e($upcomingClass['course_code']) ?></li>
                    <li><strong>Session:</strong> Session <?= $e($upcomingClass['session_number'] ?? 7) ?></li>
                    <li><strong>Room / Link:</strong> <?= $e($upcomingClass['room'] ?? 'Anggrek - 413') ?></li>
                    <li><strong>Time:</strong> <?= $e(date('d M Y, H:i', strtotime((string) $upcomingClass['start_time']))) ?> - <?= $e(date('H:i', strtotime((string) $upcomingClass['end_time']))) ?> GMT+7</li>
                </ul>

                <a href="/courses/<?= $e($upcomingClass['course_id']) ?>?session=<?= $e($upcomingClass['session_number'] ?? 1) ?>" class="nb-btn" style="width: 100%; text-align: center;">
                    View things to do in session <?= $e($upcomingClass['session_number'] ?? 1) ?> &rarr;
                </a>
            <?php else: ?>
                <p>No upcoming class scheduled.</p>
            <?php endif; ?>
        </section>

        <section class="nb-card" aria-labelledby="student-forum-heading">
            <div class="nb-card-header">
                <h2 id="student-forum-heading">LATEST FORUM POSTS</h2>
                <span class="nb-badge nb-badge-blue">ALL ACTIVE CLASSES</span>
            </div>

            <?php foreach ($latestForumPosts as $fp): ?>
                <article style="border: 3px solid #000; padding: 0.85rem; margin-bottom: 0.75rem; box-shadow: 3px 3px 0 #000;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; margin-bottom: 0.3rem;">
                        <strong><?= $e($fp['author_alias']) ?></strong>
                        <span class="nb-badge nb-badge-orange"><?= $e(strtoupper((string) $fp['author_role'])) ?></span>
                    </div>
                    <div style="font-size: 0.75rem; margin-bottom: 0.35rem;">
                        <?= $e(date('d M Y, H:i', strtotime((string) $fp['created_at']))) ?> GMT+7
                    </div>
                    <h3 style="font-size: 1rem; margin-bottom: 0.25rem;">
                        <a href="/courses/<?= $e($fp['course_id']) ?>?tab=forum&amp;session=<?= (int) ($fp['session_number'] ?? 1) ?>"><?= $e($fp['title']) ?></a>
                    </h3>
                    <div style="font-size: 0.78rem; font-weight: 700;">
                        <?= $e($fp['course_code']) ?> - <?= $e($fp['delivery_type']) ?> - <?= $e($fp['course_name']) ?> (Session <?= (int) ($fp['session_number'] ?? 1) ?>)
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>

<?php elseif ($currentUser->role === 'lecturer'): ?>
    <!-- LECTURER DASHBOARD: NEXT CLASS TO TEACH + NEEDS ATTENTION + LATEST FORUM -->
    <div class="nb-grid-2">
        <section class="nb-card">
            <div class="nb-card-header">
                <h1>NEXT CLASS TO TEACH</h1>
                <span class="nb-badge nb-badge-lime">FACULTY CONSOLE</span>
            </div>
            <?php if (!empty($upcomingClass)): ?>
                <p><strong><?= $e($upcomingClass['class_code']) ?> - <?= $e($upcomingClass['course_code']) ?></strong></p>
                <h2 style="margin: 0.35rem 0;"><?= $e($upcomingClass['course_name']) ?> (Session <?= $e($upcomingClass['session_number'] ?? 7) ?>)</h2>
                <p><strong>Room / Link:</strong> <?= $e($upcomingClass['room'] ?? 'Anggrek - 605') ?></p>
                <p><strong>Time:</strong> <?= $e($upcomingClass['start_time']) ?> GMT+7</p>
                <div style="margin-top: 1rem; display: flex; gap: 0.6rem; flex-wrap: wrap;">
                    <a href="/courses/<?= $e($upcomingClass['course_id']) ?>?tab=attendance" class="nb-btn nb-btn-lime">START SESSION / OPEN ATTENDANCE</a>
                    <a href="/courses/<?= $e($upcomingClass['course_id']) ?>?tab=gradebook" class="nb-btn nb-btn-yellow">GRADEBOOK</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="nb-card">
            <div class="nb-card-header">
                <h2>NEEDS ATTENTION PANEL</h2>
                <span class="nb-badge nb-badge-pink">ACTION REQUIRED</span>
            </div>
            <div class="nb-grid-2">
                <div style="border: 3px solid #000; padding: 0.75rem;">
                    <div style="font-size: 1.5rem; font-weight: 900;"><?= (int) ($lecturerAttention['ungraded_submissions'] ?? 0) ?></div>
                    <div style="font-size: 0.8rem; font-weight: 800;">Ungraded Submissions</div>
                </div>
                <div style="border: 3px solid #000; padding: 0.75rem;">
                    <div style="font-size: 1.5rem; font-weight: 900;"><?= (int) ($lecturerAttention['reported_posts'] ?? 0) ?></div>
                    <div style="font-size: 0.8rem; font-weight: 800;">Reported Forum Posts</div>
                </div>
            </div>
        </section>
    </div>

<?php elseif ($currentUser->role === 'operator'): ?>
    <!-- OPERATOR DASHBOARD: STRICTLY THE 2 OPERATOR FUNCTIONALITIES ONLY -->
    <div class="nb-grid-2" style="margin-bottom: 1.25rem;">
        <div class="nb-card" style="margin-bottom: 0;">
            <h2>[1] STUDENT &amp; LECTURER DATA</h2>
            <p style="font-size: 0.85rem; margin: 0.35rem 0 0.75rem;">
                View and edit active Student &amp; Lecturer records (<?= count($users ?? []) ?> records).
            </p>
            <a href="#user-data" class="nb-btn nb-btn-lime">VIEW &amp; EDIT STUDENT / LECTURER DATA</a>
        </div>
        <div class="nb-card" style="margin-bottom: 0;">
            <h2>[2] COURSE SESSION DATES</h2>
            <p style="font-size: 0.85rem; margin: 0.35rem 0 0.75rem;">
                Change start/end date, time, and room for any course session (<?= count($sessions ?? []) ?> sessions).
            </p>
            <a href="#session-dates" class="nb-btn nb-btn-yellow">CHANGE COURSE SESSION DATE</a>
        </div>
    </div>

    <?php require dirname(__DIR__) . '/operator/users.php'; ?>
<?php endif; ?>
