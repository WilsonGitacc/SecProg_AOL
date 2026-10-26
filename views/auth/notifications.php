<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var array $notifications */
$threadCount = 0;
$assignmentCount = 0;
foreach ($notifications as $n) {
    if ($n['notif_type'] === 'thread') {
        $threadCount++;
    } else {
        $assignmentCount++;
    }
}
?>
<div class="nb-card">
    <div class="nb-card-header">
        <div>
            <h1>NOTIFICATION CENTER (BELL)</h1>
            <p style="font-size: 0.85rem; font-weight: 700; margin-top: 0.25rem;">
                Showing official announcements: <strong>Lecturer Forum Threads</strong> and <strong>Lecturer Assignments</strong> only.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <span class="nb-badge nb-badge-lime"><?= $threadCount ?> LECTURER THREADS</span>
            <span class="nb-badge nb-badge-orange"><?= $assignmentCount ?> LECTURER ASSIGNMENTS</span>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <p>No lecturer threads or assignments have been posted yet.</p>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.85rem;">
            <?php foreach ($notifications as $notif): ?>
                <?php
                $isAssignment = $notif['notif_type'] === 'assignment';
                $courseId = (int) $notif['course_id'];
                $sessNum = (int) ($notif['session_number'] ?? 1);
                $targetUrl = $isAssignment
                    ? "/courses/{$courseId}?tab=assignment"
                    : "/courses/{$courseId}?tab=forum&session={$sessNum}";
                ?>
                <article style="background: <?= $isAssignment ? '#fff9db' : '#f0fff4' ?>; border: 3px solid #000; padding: 0.95rem; box-shadow: 4px 4px 0 #000; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-bottom: 0.35rem;">
                            <?php if ($isAssignment): ?>
                                <span class="nb-badge nb-badge-orange">NEW ASSIGNMENT</span>
                            <?php else: ?>
                                <span class="nb-badge nb-badge-lime">LECTURER FORUM THREAD</span>
                            <?php endif; ?>
                            <span class="nb-badge nb-badge-yellow"><?= $e($notif['course_code']) ?> &bull; <?= $e($notif['class_code']) ?></span>
                            <span style="font-size: 0.78rem; font-weight: 800;"><?= $e($notif['course_name']) ?></span>
                        </div>
                        <div style="font-size: 1.05rem; font-weight: 900; margin-bottom: 0.25rem;">
                            <?= $e($notif['title']) ?>
                        </div>
                        <div style="font-size: 0.78rem; font-weight: 700; color: #222;">
                            Posted by <strong><?= $e($notif['lecturer_name']) ?></strong> [LECTURER] &bull; <?= $e($notif['created_at']) ?> GMT+7
                            <?php if ($isAssignment && !empty($notif['due_date'])): ?>
                                &bull; <span style="color: #b91c1c; font-weight: 900;">Due: <?= $e($notif['due_date']) ?> GMT+7</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?= $e->attr($targetUrl) ?>" class="nb-btn <?= $isAssignment ? 'nb-btn-orange' : 'nb-btn-lime' ?>">
                        <?= $isAssignment ? 'OPEN ASSIGNMENT &rarr;' : 'VIEW THREAD &rarr;' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
