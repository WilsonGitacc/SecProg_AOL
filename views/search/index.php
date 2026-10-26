<?php
/** @var \App\Security\Escaper $e */
/** @var string $query */
/** @var array $courseResults */
/** @var array $forumResults */
?>
<div class="nb-card">
    <div class="nb-card-header">
        <h1>ROLE-SCOPED GLOBAL SEARCH // QUERY: "<?= $e($query) ?>"</h1>
    </div>

    <h2>MATCHING LEC COURSES (<?= count($courseResults) ?>)</h2>
    <ul style="margin: 0.5rem 0 1.25rem 1.25rem;">
        <?php foreach ($courseResults as $cr): ?>
            <li><a href="/courses/<?= (int) $cr['id'] ?>"><?= $e($cr['code']) ?> — <?= $e($cr['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <h2>MATCHING FORUM THREADS IN ENROLLED/ASSIGNED CLASSES (<?= count($forumResults) ?>)</h2>
    <ul style="margin: 0.5rem 0 0 1.25rem;">
        <?php foreach ($forumResults as $fr): ?>
            <li><a href="/courses/<?= (int) $fr['course_id'] ?>?tab=forum"><?= $e($fr['title']) ?></a> (<?= $e($fr['course_code']) ?>)</li>
        <?php endforeach; ?>
    </ul>
</div>
