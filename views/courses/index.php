<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var array $courses */
/** @var string $sortKey */
?>
<div class="nb-card">
    <div class="nb-card-header">
        <div>
            <h1>MY COURSES // RUNNING PERIOD: 2026, ODD SEMESTER</h1>
            <div style="font-size: 0.82rem; font-weight: 800; margin-top: 0.25rem;">
                FILTER: <span class="nb-badge nb-badge-orange">LEC ONLY (LAB SKIPPED)</span>
            </div>
        </div>
        <form action="/courses" method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
            <label for="course-sort" style="font-weight: 900; font-size: 0.8rem;">SORT BY:</label>
            <select id="course-sort" name="sort" class="nb-select" style="width: auto; margin: 0;">
                <option value="name" <?= $sortKey === 'name' ? 'selected' : '' ?>>Course Name (A-Z)</option>
                <option value="code" <?= $sortKey === 'code' ? 'selected' : '' ?>>Course Code</option>
            </select>
            <button type="submit" class="nb-btn nb-btn-yellow">SORT</button>
        </form>
    </div>

    <?php if ($currentUser->role === 'operator'): ?>
        <div style="border: 3px solid #000; background: #FFE600; padding: 0.85rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <strong>OPERATOR CONSOLE (2 FUNCTIONALITIES ONLY):</strong>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="/operator/users#user-data" class="nb-btn nb-btn-lime">[1] VIEW &amp; EDIT STUDENT / LECTURER DATA</a>
                <a href="/operator/users#session-dates" class="nb-btn nb-btn-yellow">[2] CHANGE COURSE SESSION DATE</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="nb-grid-3">
        <?php foreach ($courses as $c): ?>
            <article class="nb-card" style="margin-bottom: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span class="nb-badge nb-badge-yellow"><?= $e($c['code']) ?></span>
                    <span class="nb-badge nb-badge-lime"><?= $e($c['class_code']) ?> - <?= $e($c['delivery_type']) ?></span>
                </div>

                <h2 style="font-size: 1.1rem; min-height: 2.8rem; margin-bottom: 0.35rem;">
                    <a href="/courses/<?= (int) $c['course_id'] ?>"><?= $e($c['name']) ?></a>
                </h2>

                <div style="font-size: 0.78rem; font-weight: 700; margin-bottom: 0.75rem;">
                    Category: <?= $e($c['category']) ?> &bull; Credit: <?= (int) $c['credits_lecture'] ?>/<?= (int) $c['credits_lab'] ?>
                </div>

                <div style="margin-top: 0.85rem; display: flex; gap: 0.4rem; flex-wrap: wrap;">
                    <a href="/courses/<?= (int) $c['course_id'] ?>?tab=forum" class="nb-btn" style="font-size: 0.72rem; padding: 0.3rem 0.5rem;">FORUM</a>
                    <?php if ($currentUser->role !== 'operator'): ?>
                        <a href="/courses/<?= (int) $c['course_id'] ?>?tab=gradebook" class="nb-btn nb-btn-lime" style="font-size: 0.72rem; padding: 0.3rem 0.5rem;">GRADEBOOK</a>
                    <?php endif; ?>
                    <a href="/courses/<?= (int) $c['course_id'] ?>?tab=people" class="nb-btn nb-btn-yellow" style="font-size: 0.72rem; padding: 0.3rem 0.5rem;">PEOPLE</a>
                    <a href="/courses/<?= (int) $c['course_id'] ?>?tab=grouping" class="nb-btn nb-btn-pink" style="font-size: 0.72rem; padding: 0.3rem 0.5rem;">GROUPING</a>
                    <a href="/courses/<?= (int) $c['course_id'] ?>?tab=attendance" class="nb-btn nb-btn-blue" style="font-size: 0.72rem; padding: 0.3rem 0.5rem;">ATTENDANCE</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
