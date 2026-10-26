<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var string $csrfToken */
/** @var array $course */
/** @var array $sessions */
/** @var array|null $currentSession */
/** @var array $outcomes */
/** @var array $subTopics */
/** @var array $materials */
/** @var string $activeTab */
/** @var string $searchQ */
/** @var array $threads */
/** @var array $postsByThread */
/** @var array $grades */
/** @var array $people */
/** @var array $groups */
/** @var array $groupMembers */
/** @var array $attendanceList */
$courseId = (int) $course['id'];
?>
<div class="nb-card">
    <div class="nb-card-header">
        <div>
            <h1><?= $e($course['name']) ?></h1>
            <div style="font-size: 0.85rem; font-weight: 800; margin-top: 0.25rem;">
                <span class="nb-badge nb-badge-yellow"><?= $e($course['code']) ?></span>
                <span class="nb-badge nb-badge-lime">Credit <?= (int) $course['credits_lecture'] ?>/<?= (int) $course['credits_lab'] ?></span>
                <span class="nb-badge nb-badge-orange"><?= $e($course['class_code']) ?> - <?= $e($course['delivery_type']) ?></span>
            </div>
        </div>
        <a href="/courses" class="nb-btn nb-btn-yellow">&larr; ALL LEC COURSES</a>
    </div>

<?php
$selectedSessionNum = $currentSession ? (int) $currentSession['session_number'] : 1;
$threadCountsBySessionNum = $threadCountsBySessionNum ?? [];
?>
    <!-- Course Workspace Tabs (Session, Assignment, Forum, Gradebook, People, Grouping, Attendance) -->
    <nav class="nb-tabs" aria-label="Course Workspace Tabs">
        <a href="/courses/<?= $courseId ?>?tab=session&amp;session=<?= $selectedSessionNum ?>" class="nb-btn <?= $activeTab === 'session' ? 'nb-btn-yellow' : '' ?>">SESSION</a>
        <a href="/courses/<?= $courseId ?>?tab=assignment" class="nb-btn <?= $activeTab === 'assignment' ? 'nb-btn-yellow' : '' ?>">ASSIGNMENT (<?= count($assignments ?? []) ?>)</a>
        <a href="/courses/<?= $courseId ?>?tab=forum&amp;session=<?= $selectedSessionNum ?>" class="nb-btn <?= $activeTab === 'forum' ? 'nb-btn-yellow' : '' ?>">FORUM</a>
        <?php if ($currentUser->role !== 'operator'): ?>
            <a href="/courses/<?= $courseId ?>?tab=gradebook" class="nb-btn <?= $activeTab === 'gradebook' ? 'nb-btn-yellow' : '' ?>">GRADEBOOK</a>
        <?php endif; ?>
        <a href="/courses/<?= $courseId ?>?tab=people" class="nb-btn <?= $activeTab === 'people' ? 'nb-btn-yellow' : '' ?>">PEOPLE</a>
        <a href="/courses/<?= $courseId ?>?tab=grouping" class="nb-btn <?= $activeTab === 'grouping' ? 'nb-btn-yellow' : '' ?>">GROUPING</a>
        <a href="/courses/<?= $courseId ?>?tab=attendance" class="nb-btn <?= $activeTab === 'attendance' ? 'nb-btn-yellow' : '' ?>">ATTENDANCE</a>
    </nav>

    <?php if ($activeTab === 'session'): ?>
        <!-- 6 SESSIONS SHOWN PER COURSE -->
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
            <?php foreach ($sessions as $s): ?>
                <a href="/courses/<?= $courseId ?>?tab=session&amp;session=<?= (int) $s['session_number'] ?>"
                   class="nb-btn <?= ($currentSession && (int) $currentSession['id'] === (int) $s['id']) ? 'nb-btn-lime' : '' ?>">
                    Session <?= (int) $s['session_number'] ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($currentSession): ?>
            <div class="nb-grid-2">
                <div>
                    <h2 style="font-size: 1.35rem; margin-bottom: 0.75rem;">
                        <?= $e($currentSession['title']) ?>
                    </h2>

                    <?php if (!empty($outcomes)): ?>
                        <h3 style="margin-bottom: 0.4rem;">Learning Outcome</h3>
                        <ul style="margin-left: 1.25rem; margin-bottom: 1rem; font-weight: 700;">
                            <?php foreach ($outcomes as $lo): ?>
                                <li><?= $e($lo['bloom_level']) ?> <?= $e($lo['bloom_category']) ?> : <?= $e($lo['description']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($subTopics)): ?>
                        <h3 style="margin-bottom: 0.4rem;">Sub Topic</h3>
                        <ul style="margin-left: 1.25rem; margin-bottom: 1rem; font-weight: 700;">
                            <?php foreach ($subTopics as $st): ?>
                                <li><?= $e($st['topic_name']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div style="border-top: 3px solid #000; padding-top: 0.75rem; font-size: 0.85rem;">
                        <p><strong>Start:</strong> <?= $e($currentSession['start_time']) ?> GMT+7</p>
                        <p><strong>End:</strong> <?= $e($currentSession['end_time']) ?> GMT+7</p>
                        <p><strong>Delivery Mode:</strong> <?= $e($currentSession['delivery_mode']) ?></p>
                        <p><strong>Room:</strong> <?= $e($currentSession['room'] ?? 'Anggrek - 605') ?></p>
                    </div>
                </div>

                <div class="nb-materials-panel">
                    <h3 style="margin-bottom: 0.75rem; color: #000;">Things to do in this session (<?= count($materials) ?>)</h3>
                    <?php foreach ($materials as $m): ?>
                        <div class="nb-material-item">
                            <span><?= $e($m['title']) ?></span>
                            <span class="nb-badge nb-badge-yellow"><?= $e($m['duration_label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php elseif ($activeTab === 'forum'): ?>
        <!-- EACH SESSION (1..6) HAS ITS OWN SEPARATE FORUM -->
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <?php foreach ($sessions as $s): ?>
                <?php
                $sNum = (int) $s['session_number'];
                $sCnt = (int) ($threadCountsBySessionNum[$sNum] ?? 0);
                ?>
                <a href="/courses/<?= $courseId ?>?tab=forum&amp;session=<?= $sNum ?>"
                   class="nb-btn <?= ($currentSession && (int) $currentSession['id'] === (int) $s['id']) ? 'nb-btn-lime' : '' ?>">
                    Session <?= $sNum ?> Forum (<?= $sCnt ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">
            <div>
                <h2>SESSION <?= $selectedSessionNum ?> FORUM &mdash; <?= $e($currentSession['title'] ?? '') ?> (<?= count($threads) ?>)</h2>
                <div style="font-size: 0.8rem; font-weight: 800; margin-top: 0.2rem;">
                    DEDICATED FORUM FOR SESSION <?= $selectedSessionNum ?> ONLY
                </div>
            </div>
            <form action="/courses/<?= $courseId ?>" method="GET" style="display: flex; gap: 0.4rem;">
                <input type="hidden" name="tab" value="forum">
                <input type="hidden" name="session" value="<?= $selectedSessionNum ?>">
                <input type="search" name="q" value="<?= $e->attr($searchQ ?? '') ?>" placeholder="Search Session <?= $selectedSessionNum ?> threads..." class="nb-input" style="margin: 0; width: 220px;">
                <button type="submit" class="nb-btn nb-btn-yellow">FILTER</button>
            </form>
        </div>

        <div class="nb-grid-2">
            <div>
                <?php if (empty($threads)): ?>
                    <div class="nb-card">
                        <p>No forum threads found in this class yet. Use the form on the right to start the first discussion thread.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($threads as $threadRow): ?>
                    <?php
                    $tid = (int) $threadRow['id'];
                    $threadPosts = $postsByThread[$tid] ?? [];
                    $mainPost = $threadPosts[0] ?? null;
                    $replyPosts = array_slice($threadPosts, 1);
                    ?>
                    <article class="nb-card" style="margin-bottom: 1.5rem; border: 3px solid #000; box-shadow: 6px 6px 0 #000;">
                        <!-- MAIN THREAD HEADER -->
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.4rem; border-bottom: 3px solid #000; padding-bottom: 0.55rem;">
                            <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                                <span class="nb-badge nb-badge-lime">MAIN THREAD</span>
                                <?php if ((int) $threadRow['is_pinned'] === 1): ?>
                                    <span class="nb-badge nb-badge-yellow">PINNED</span>
                                <?php endif; ?>
                                <?php if ((int) $threadRow['is_locked'] === 1): ?>
                                    <span class="nb-badge nb-badge-pink">LOCKED</span>
                                <?php endif; ?>
                                <strong style="font-size: 1.15rem;"><?= $e($threadRow['title']) ?></strong>
                            </div>
                            <span class="nb-badge nb-badge-orange"><?= $e($threadRow['author_name']) ?> (<?= $e(strtoupper((string) $threadRow['author_role'])) ?>)</span>
                        </div>

                        <?php if ($currentUser->role === 'lecturer'): ?>
                            <div style="display: flex; gap: 0.35rem; margin-top: 0.55rem; flex-wrap: wrap;">
                                <form action="/courses/<?= $courseId ?>/forum/threads/<?= $tid ?>/moderate" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <input type="hidden" name="mod_action" value="toggle_pin">
                                    <button type="submit" class="nb-btn nb-btn-yellow" style="font-size: 0.68rem; padding: 0.2rem 0.45rem;">
                                        <?= (int) $threadRow['is_pinned'] === 1 ? 'UNPIN' : 'PIN' ?>
                                    </button>
                                </form>
                                <form action="/courses/<?= $courseId ?>/forum/threads/<?= $tid ?>/moderate" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <input type="hidden" name="mod_action" value="toggle_lock">
                                    <button type="submit" class="nb-btn nb-btn-blue" style="font-size: 0.68rem; padding: 0.2rem 0.45rem;">
                                        <?= (int) $threadRow['is_locked'] === 1 ? 'UNLOCK' : 'LOCK' ?>
                                    </button>
                                </form>
                                <form action="/courses/<?= $courseId ?>/forum/threads/<?= $tid ?>/moderate" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <input type="hidden" name="mod_action" value="soft_delete">
                                    <button type="submit" class="nb-btn nb-btn-pink" style="font-size: 0.68rem; padding: 0.2rem 0.45rem;">SOFT DELETE THREAD</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- MAIN THREAD ORIGINAL POST BOX -->
                        <?php if ($mainPost): ?>
                            <div style="background: #fff9db; border: 3px solid #000; padding: 0.85rem; margin-top: 0.65rem; box-shadow: 4px 4px 0 #000;">
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.76rem; font-weight: 900; margin-bottom: 0.45rem; border-bottom: 2px dashed #000; padding-bottom: 0.3rem;">
                                    <span>ORIGINAL POST BY <?= $e(strtoupper((string) $mainPost['author_name'])) ?> [<?= $e(strtoupper((string) $mainPost['author_role'])) ?>]</span>
                                    <span><?= $e($mainPost['created_at']) ?> GMT+7</span>
                                </div>
                                <div style="font-size: 0.98rem; line-height: 1.5;">
                                    <?= $mainPost['body_html'] /* Sanitized via Markdown::render */ ?>
                                </div>
                                <div style="display: flex; gap: 0.4rem; margin-top: 0.55rem; flex-wrap: wrap;">
                                    <?php if ($currentUser->role === 'student'): ?>
                                        <form action="/courses/<?= $courseId ?>/forum/posts/<?= (int) $mainPost['id'] ?>/report" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                            <input type="hidden" name="reason" value="Flagged main thread for lecturer moderation review">
                                            <button type="submit" class="nb-btn nb-btn-pink" style="font-size: 0.68rem; padding: 0.18rem 0.45rem;">REPORT MAIN THREAD</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $mainPost['author_id'] === $currentUser->id || $currentUser->role === 'lecturer'): ?>
                                        <form action="/courses/<?= $courseId ?>/forum/posts/<?= (int) $mainPost['id'] ?>/delete" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                            <button type="submit" class="nb-btn nb-btn-yellow" style="font-size: 0.68rem; padding: 0.18rem 0.45rem;">DELETE MAIN THREAD (&le;15m)</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- INDENTED REPLIES SECTION -->
                        <div style="margin-top: 0.85rem;">
                            <div style="font-size: 0.8rem; font-weight: 900; letter-spacing: 0.03em; margin-bottom: 0.45rem;">
                                &rdsh; REPLIES TO THIS THREAD (<?= count($replyPosts) ?>)
                            </div>

                            <?php if (empty($replyPosts)): ?>
                                <div style="margin-left: 1.75rem; font-size: 0.8rem; color: #444; font-style: italic; padding: 0.35rem 0;">
                                    No replies yet. Use the reply box below to respond to the main thread.
                                </div>
                            <?php else: ?>
                                <div style="margin-left: 1.75rem; border-left: 5px solid #000; padding-left: 0.9rem; display: flex; flex-direction: column; gap: 0.65rem;">
                                    <?php foreach ($replyPosts as $rIdx => $p): ?>
                                        <div style="background: #eef2f6; border: 2px solid #000; padding: 0.65rem; box-shadow: 3px 3px 0 #000;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.35rem; border-bottom: 1px solid #000; padding-bottom: 0.25rem;">
                                                <div>
                                                    <span class="nb-badge nb-badge-blue" style="font-size: 0.68rem;">&rdsh; REPLY #<?= $rIdx + 1 ?></span>
                                                    <strong style="font-size: 0.82rem; margin-left: 0.25rem;"><?= $e($p['author_name']) ?></strong>
                                                    <span style="font-size: 0.72rem; font-weight: 800;">[<?= $e(strtoupper((string) $p['author_role'])) ?>]</span>
                                                </div>
                                                <span style="font-size: 0.72rem; font-weight: 700;"><?= $e($p['created_at']) ?> GMT+7</span>
                                            </div>
                                            <div style="font-size: 0.9rem;">
                                                <?= $p['body_html'] /* Sanitized via Markdown::render */ ?>
                                            </div>
                                            <div style="display: flex; gap: 0.4rem; margin-top: 0.45rem; flex-wrap: wrap;">
                                                <?php if ($currentUser->role === 'student'): ?>
                                                    <form action="/courses/<?= $courseId ?>/forum/posts/<?= (int) $p['id'] ?>/report" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                                        <input type="hidden" name="reason" value="Flagged reply for lecturer moderation review">
                                                        <button type="submit" class="nb-btn nb-btn-pink" style="font-size: 0.65rem; padding: 0.15rem 0.4rem;">REPORT REPLY</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ((int) $p['author_id'] === $currentUser->id || $currentUser->role === 'lecturer'): ?>
                                                    <form action="/courses/<?= $courseId ?>/forum/posts/<?= (int) $p['id'] ?>/delete" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                                        <button type="submit" class="nb-btn nb-btn-yellow" style="font-size: 0.65rem; padding: 0.15rem 0.4rem;">DELETE REPLY (&le;15m)</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($currentUser->role !== 'operator' && ((int) $threadRow['is_locked'] === 0 || $currentUser->role === 'lecturer')): ?>
                            <form action="/courses/<?= $courseId ?>/forum/threads/<?= $tid ?>/reply" method="POST" style="border-top: 3px solid #000; margin-top: 0.85rem; padding-top: 0.65rem; margin-left: 1.75rem;">
                                <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                <label style="font-weight: 900; font-size: 0.78rem; display: block; margin-bottom: 0.25rem;">&rdsh; ADD A REPLY TO &ldquo;<?= $e($threadRow['title']) ?>&rdquo; (SAFE MARKDOWN)</label>
                                <textarea name="body_raw" class="nb-textarea" rows="2" required placeholder="Write your reply to this thread..."></textarea>
                                <button type="submit" class="nb-btn nb-btn-lime" style="font-size: 0.75rem; padding: 0.3rem 0.65rem;">POST REPLY</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($currentUser->role !== 'operator'): ?>
                <div>
                    <h2>START NEW THREAD IN SESSION <?= $selectedSessionNum ?> FORUM</h2>
                    <form action="/courses/<?= $courseId ?>/forum/threads" method="POST" class="nb-card" style="margin-top: 0.75rem;">
                        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                        <input type="hidden" name="session_number" value="<?= $selectedSessionNum ?>">
                        <label for="thread-title" style="font-weight: 800;">Thread Title (Session <?= $selectedSessionNum ?>)</label>
                        <input id="thread-title" type="text" name="title" class="nb-input" required maxlength="255" placeholder="e.g. Question about Session <?= $selectedSessionNum ?> topic">

                        <label for="thread-body" style="font-weight: 800;">Content (Safe Markdown Subset)</label>
                        <textarea id="thread-body" name="body_raw" class="nb-textarea" rows="4" required placeholder="Supports **bold**, *italic*, `code`, and https:// links..."></textarea>

                        <button type="submit" class="nb-btn nb-btn-lime">POST NEW THREAD TO SESSION <?= $selectedSessionNum ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($activeTab === 'gradebook'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <h2>GRADEBOOK (<?= $currentUser->role === 'student' ? 'OWN PUBLISHED GRADES ONLY' : 'LECTURER GRADE CONSOLE' ?>)</h2>
            <?php if ($currentUser->role === 'lecturer'): ?>
                <a href="/courses/<?= $courseId ?>/gradebook/export" class="nb-btn nb-btn-lime">EXPORT CSV (FORMULA-SAFE)</a>
            <?php endif; ?>
        </div>

        <?php if ($currentUser->role === 'student'): ?>
            <?php
            $totalWeight = 0.0;
            $totalWeighted = 0.0;
            foreach ($grades as $g) {
                $w = (float) $g['weight_pct'];
                $totalWeight += $w;
                if ($g['score'] !== null) {
                    $clampedScore = min(100.0, max(0.0, (float) $g['score']));
                    $totalWeighted += $clampedScore * ($w / 100.0);
                }
            }
            if ($totalWeight > 100.0 && $totalWeight > 0.0) {
                $totalWeighted = ($totalWeighted / $totalWeight) * 100.0;
            }
            $totalWeighted = min(100.0, max(0.0, $totalWeighted));
            ?>
            <table class="nb-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Weight (%)</th>
                        <th>Raw Score (0&ndash;100)</th>
                        <th>Weighted Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $g): ?>
                        <?php
                        $w = (float) $g['weight_pct'];
                        $rawScore = $g['score'] !== null ? min(100.0, max(0.0, (float) $g['score'])) : null;
                        $contrib = $rawScore !== null ? ($rawScore * ($w / 100.0)) : null;
                        ?>
                        <tr>
                            <td><strong><?= $e($g['name']) ?></strong></td>
                            <td><?= number_format($w, 2) ?>%</td>
                            <td><span class="nb-badge nb-badge-lime"><?= $rawScore !== null ? number_format($rawScore, 2) : 'Pending' ?></span></td>
                            <td><strong><?= $contrib !== null ? number_format($contrib, 2) . ' / ' . number_format($w, 2) : '-' ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">CALCULATED WEIGHTED FINAL GRADE (MAX 100%)</th>
                        <th><?= number_format($totalWeighted, 2) ?>% (<?= number_format($totalWeighted, 2) ?> / 100.00)</th>
                    </tr>
                </tfoot>
            </table>
        <?php elseif ($currentUser->role === 'lecturer'): ?>
            <table class="nb-table">
                <thead>
                    <tr>
                        <th>Student Alias</th>
                        <th>Pseudonym</th>
                        <th>Component (Weight)</th>
                        <th>Score</th>
                        <th>Update Grade (Audited)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $g): ?>
                        <tr>
                            <td><?= $e($g['student_id_alias']) ?></td>
                            <td><?= $e($g['student_name']) ?></td>
                            <td><?= $e($g['name']) ?> (<?= $e($g['weight_pct']) ?>%)</td>
                            <td><strong><?= $e($g['score'] ?? '-') ?></strong></td>
                            <td>
                                <form action="/courses/<?= $courseId ?>/gradebook/update" method="POST" style="display: flex; gap: 0.35rem; align-items: center;">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <input type="hidden" name="component_id" value="<?= (int) $g['component_id'] ?>">
                                    <input type="hidden" name="student_id" value="<?= (int) $g['student_id'] ?>">
                                    <input type="number" name="score" step="0.01" min="0" max="100" value="<?= $e->attr($g['score'] ?? '90.00') ?>" style="width: 85px; border: 2px solid #000; padding: 0.2rem;">
                                    <input type="text" name="reason" placeholder="Audit reason" required style="width: 140px; border: 2px solid #000; padding: 0.2rem;">
                                    <button type="submit" class="nb-btn nb-btn-lime" style="padding: 0.2rem 0.5rem; font-size: 0.72rem;">SAVE</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($activeTab === 'people'): ?>
        <h2>CLASS ROSTER (DETERMINISTICALLY PSEUDONYMIZED — ZERO REAL PII)</h2>
        <table class="nb-table">
            <thead>
                <tr>
                    <th>Pseudonymized Name</th>
                    <th>Role</th>
                    <th>Alias ID</th>
                    <th>Academic Title</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($people as $p): ?>
                    <tr>
                        <td><strong><?= $e($p['full_name']) ?></strong></td>
                        <td><span class="nb-badge <?= $p['role'] === 'lecturer' ? 'nb-badge-orange' : 'nb-badge-lime' ?>"><?= $e($p['role']) ?></span></td>
                        <td><code><?= $e($p['student_id_alias'] ?? $p['lecturer_code_alias'] ?? '-') ?></code></td>
                        <td><?= $e($p['academic_title'] ?? 'Undergraduate Student') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($activeTab === 'grouping'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
            <h2>PROJECT GROUPING SETS (<?= count($groups) ?> GROUPS AVAILABLE &bull; MAX 1 GROUP PER STUDENT)</h2>
            <?php if ($currentUser->role === 'student'): ?>
                <?php if (!empty($myGroupId)): ?>
                    <span class="nb-badge nb-badge-lime">ENROLLED IN GROUP #<?= (int) $myGroupId ?> (1 GROUP LIMIT ENFORCED)</span>
                <?php else: ?>
                    <span class="nb-badge nb-badge-yellow">NOT IN A GROUP YET — SELECT ONE BELOW</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($currentUser->role === 'lecturer'): ?>
            <div class="nb-card" style="background: #fff9db; border: 3px solid #000; margin-top: 0.75rem; margin-bottom: 1rem;">
                <form action="/courses/<?= $courseId ?>/grouping/create" method="POST" style="display: flex; gap: 0.65rem; align-items: flex-end; flex-wrap: wrap;">
                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                    <div style="flex: 1; min-width: 260px;">
                        <label for="new-group-name" style="font-weight: 900; font-size: 0.85rem; display: block;">CREATE NEW GROUP SLOT (LECTURER)</label>
                        <input id="new-group-name" type="text" name="group_name" class="nb-input" required maxlength="120" value="Group <?= count($groups) + 1 ?> — RedTeam Delta" style="margin-bottom: 0;">
                    </div>
                    <button type="submit" class="nb-btn nb-btn-lime" style="padding: 0.6rem 1.1rem;">+ ADD NEW GROUP SLOT</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="nb-grid-2" style="margin-top: 0.75rem;">
            <?php foreach ($groups as $g): ?>
                <?php
                $gid = (int) $g['id'];
                $isMyGroup = !empty($myGroupId) && (int) $myGroupId === $gid;
                $isFull = (int) $g['member_count'] >= (int) $g['max_members'];
                ?>
                <div class="nb-card" style="margin-bottom: 0; <?= $isMyGroup ? 'background: #f0fff4; border: 4px solid #000;' : '' ?>">
                    <div class="nb-card-header">
                        <div>
                            <h3><?= $e($g['name']) ?></h3>
                            <?php if ($isMyGroup): ?>
                                <span class="nb-badge nb-badge-yellow" style="margin-top: 0.25rem;">YOUR CURRENT GROUP</span>
                            <?php endif; ?>
                        </div>
                        <span class="nb-badge <?= $isFull ? 'nb-badge-pink' : 'nb-badge-lime' ?>">
                            <?= (int) $g['member_count'] ?> / <?= (int) $g['max_members'] ?> MEMBERS
                        </span>
                    </div>
                    <p style="font-size: 0.8rem; margin-bottom: 0.5rem;">
                        Set: <?= $e($g['set_name']) ?> &bull; Slot: <strong><?= $isFull ? 'FULL' : 'OPEN FOR JOINING' ?></strong> &bull; Deadline: <?= $e($g['window_close_at']) ?>
                    </p>
                    <ul style="margin-left: 1.2rem; margin-bottom: 0.75rem; font-size: 0.85rem;">
                        <?php if (empty($groupMembers[$gid])): ?>
                            <li style="font-style: italic; color: #555;">No members enrolled yet — open for joining.</li>
                        <?php else: ?>
                            <?php foreach ($groupMembers[$gid] as $gm): ?>
                                <li><?= $e($gm['full_name']) ?> (<?= $e($gm['student_id_alias']) ?>) — <strong><?= $e($gm['role']) ?></strong></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                    <?php if ($currentUser->role === 'student'): ?>
                        <?php if ($isMyGroup): ?>
                            <form action="/courses/<?= $courseId ?>/grouping/leave" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="nb-btn nb-btn-pink">LEAVE THIS GROUP</button>
                            </form>
                        <?php elseif (!empty($myGroupId)): ?>
                            <button type="button" class="nb-btn" disabled style="opacity: 0.55; cursor: not-allowed;">
                                ALREADY IN ANOTHER GROUP (MAX 1 GROUP PER COURSE)
                            </button>
                        <?php elseif ($isFull): ?>
                            <button type="button" class="nb-btn nb-btn-pink" disabled style="opacity: 0.7; cursor: not-allowed;">
                                GROUP FULL (<?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?>)
                            </button>
                        <?php else: ?>
                            <form action="/courses/<?= $courseId ?>/grouping/join" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="nb-btn nb-btn-yellow">JOIN GROUP</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($activeTab === 'attendance'): ?>
        <div>
            <h2>SESSION ATTENDANCE RECORDS</h2>
            <table class="nb-table">
                <thead>
                    <tr>
                        <th>Session</th>
                        <?php if ($currentUser->role !== 'student'): ?>
                            <th>Student</th>
                        <?php endif; ?>
                        <th>Date / Time</th>
                        <th>Status</th>
                        <?php if ($currentUser->role === 'lecturer'): ?>
                            <th>Lecturer Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendanceList as $att): ?>
                        <?php
                        $rawStatus = $att['status'] ?? null;
                        $hasStarted = strtotime((string) $att['start_time']) <= time();
                        ?>
                        <tr>
                            <td>Session <?= (int) $att['session_number'] ?>: <?= $e($att['title']) ?></td>
                            <?php if ($currentUser->role !== 'student'): ?>
                                <td><?= $e($att['student_name'] ?? '') ?></td>
                            <?php endif; ?>
                            <td><?= $e($att['start_time']) ?></td>
                            <td>
                                <?php if (empty($rawStatus)): ?>
                                    <span class="nb-badge nb-badge-yellow">NOT MARKED YET</span>
                                <?php else: ?>
                                    <span class="nb-badge nb-badge-lime"><?= $e(strtoupper((string) $rawStatus)) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($currentUser->role === 'lecturer'): ?>
                                <td>
                                    <?php if (!$hasStarted): ?>
                                        <span class="nb-badge nb-badge-pink">LOCKED — SESSION NOT STARTED YET</span>
                                    <?php else: ?>
                                        <form action="/courses/<?= $courseId ?>/attendance/mark" method="POST" style="display: flex; gap: 0.3rem;">
                                            <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                            <input type="hidden" name="session_id" value="<?= (int) $att['session_id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= (int) ($att['student_id'] ?? 0) ?>">
                                            <select name="status" required style="border: 2px solid #000; font-weight: 700;">
                                                <option value="" disabled <?= empty($rawStatus) ? 'selected' : '' ?>>-- Select Status --</option>
                                                <option value="present" <?= $rawStatus === 'present' ? 'selected' : '' ?>>present</option>
                                                <option value="late" <?= $rawStatus === 'late' ? 'selected' : '' ?>>late</option>
                                                <option value="absent" <?= $rawStatus === 'absent' ? 'selected' : '' ?>>absent</option>
                                                <option value="excused" <?= $rawStatus === 'excused' ? 'selected' : '' ?>>excused</option>
                                            </select>
                                            <input type="hidden" name="edit_reason" value="Lecturer attendance update">
                                            <button type="submit" class="nb-btn nb-btn-yellow" style="padding: 0.2rem 0.45rem; font-size: 0.72rem;">MARK</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($activeTab === 'assignment'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">
            <h2>COURSE ASSIGNMENTS (<?= count($assignments ?? []) ?>)</h2>
            <span class="nb-badge nb-badge-orange">NEW ASSIGNMENTS ENTER NOTIFICATION BELL</span>
        </div>

        <div class="<?= $currentUser->role === 'lecturer' ? 'nb-grid-2' : '' ?>">
            <div>
                <?php if (empty($assignments)): ?>
                    <div class="nb-card">
                        <p>No assignments have been published for this course yet.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($assignments ?? [] as $a): ?>
                    <?php
                    $aid = (int) $a['id'];
                    $mySub = $mySubmissions[$aid] ?? null;
                    $subList = $submissionsByAssignment[$aid] ?? [];
                    ?>
                    <article class="nb-card" style="background: #fff9db; border: 3px solid #000; box-shadow: 5px 5px 0 #000; margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.4rem; border-bottom: 3px solid #000; padding-bottom: 0.5rem; margin-bottom: 0.65rem;">
                            <div>
                                <span class="nb-badge nb-badge-orange">ASSIGNMENT</span>
                                <strong style="font-size: 1.1rem; margin-left: 0.35rem;"><?= $e($a['title']) ?></strong>
                            </div>
                            <span class="nb-badge nb-badge-yellow">MAX SCORE: <?= number_format((float) $a['max_score'], 2) ?></span>
                        </div>

                        <p style="font-size: 0.92rem; margin-bottom: 0.65rem;">
                            <?= $e($a['description']) ?>
                        </p>

                        <div style="font-size: 0.8rem; font-weight: 800; background: #fff; border: 2px solid #000; padding: 0.55rem; margin-bottom: 0.75rem;">
                            <div><strong>Published By:</strong> <?= $e($a['creator_name']) ?> [LECTURER] &bull; <?= $e($a['created_at']) ?> GMT+7</div>
                            <div style="color: #b91c1c; margin-top: 0.2rem;"><strong>Due Date:</strong> <?= $e($a['due_date']) ?> GMT+7</div>
                            <?php if (!empty($a['late_policy'])): ?>
                                <div style="margin-top: 0.2rem;"><strong>Late Policy:</strong> <?= $e($a['late_policy']) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if ($currentUser->role === 'student'): ?>
                            <div style="border-top: 2px dashed #000; padding-top: 0.65rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <strong>Your Submission Status:</strong>
                                    <?php if ($mySub): ?>
                                        <span class="nb-badge nb-badge-lime">SUBMITTED AT <?= $e($mySub['submitted_at']) ?></span>
                                    <?php else: ?>
                                        <span class="nb-badge nb-badge-pink">PENDING SUBMISSION</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($mySub && !empty($mySub['file_uuid'])): ?>
                                    <p style="font-size: 0.8rem; margin-bottom: 0.5rem;">
                                        Uploaded File: <a href="/files/<?= $e->attr($mySub['file_uuid']) ?>"><?= $e($mySub['file_name']) ?></a>
                                    </p>
                                <?php endif; ?>
                                <form action="/courses/<?= $courseId ?>/assignments/<?= $aid ?>/submit" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                    <label style="font-weight: 800; font-size: 0.8rem;">Upload Solution File (PDF/PNG/JPG/TXT &le; 10MB)</label>
                                    <input type="file" name="attachment" class="nb-input" accept=".pdf,.png,.jpg,.jpeg,.txt">
                                    <button type="submit" class="nb-btn nb-btn-lime" style="font-size: 0.78rem;">
                                        <?= $mySub ? 'UPDATE SUBMISSION' : 'SUBMIT ASSIGNMENT' ?>
                                    </button>
                                </form>
                            </div>
                        <?php elseif ($currentUser->role === 'lecturer'): ?>
                            <div style="border-top: 2px dashed #000; padding-top: 0.65rem;">
                                <strong style="font-size: 0.85rem;">Student Submissions (<?= count($subList) ?>):</strong>
                                <?php if (empty($subList)): ?>
                                    <p style="font-size: 0.8rem; font-style: italic; margin-top: 0.25rem;">No student submissions yet.</p>
                                <?php else: ?>
                                    <ul style="margin-left: 1.2rem; margin-top: 0.35rem; font-size: 0.82rem;">
                                        <?php foreach ($subList as $sub): ?>
                                            <li>
                                                <strong><?= $e($sub['student_name']) ?></strong> (<?= $e($sub['student_id_alias']) ?>) &bull; Submitted: <?= $e($sub['submitted_at']) ?>
                                                <?php if (!empty($sub['file_uuid'])): ?>
                                                    &bull; <a href="/files/<?= $e->attr($sub['file_uuid']) ?>"><?= $e($sub['file_name']) ?></a>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($currentUser->role === 'lecturer'): ?>
                <div>
                    <h2>PUBLISH NEW ASSIGNMENT (BROADCASTS TO BELL)</h2>
                    <form action="/courses/<?= $courseId ?>/assignments/create" method="POST" class="nb-card" style="margin-top: 0.75rem;">
                        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">

                        <label for="assign-title" style="font-weight: 800;">Assignment Title</label>
                        <input id="assign-title" type="text" name="title" class="nb-input" required maxlength="255" placeholder="e.g. Assignment 2: Threat Modeling & RBAC Audit">

                        <label for="assign-desc" style="font-weight: 800;">Description &amp; Deliverables</label>
                        <textarea id="assign-desc" name="description" class="nb-textarea" rows="4" required placeholder="Describe the assignment requirements and grading rubric..."></textarea>

                        <label for="assign-due" style="font-weight: 800;">Due Date &amp; Time (GMT+7)</label>
                        <input id="assign-due" type="datetime-local" name="due_date" class="nb-input" required value="2026-11-05T23:59">

                        <label for="assign-late" style="font-weight: 800;">Late Submission Policy</label>
                        <input id="assign-late" type="text" name="late_policy" class="nb-input" value="Strict deadline — 10% deduction per day late (max 3 days)">

                        <button type="submit" class="nb-btn nb-btn-orange" style="width: 100%; padding: 0.7rem;">
                            PUBLISH ASSIGNMENT &amp; NOTIFY STUDENTS
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
