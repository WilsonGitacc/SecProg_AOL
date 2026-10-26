<?php
/** @var \App\Security\Escaper $e */
/** @var string $csrfToken */
/** @var array $users */
/** @var array $sessions */
?>
<div class="nb-card" id="user-data">
    <div class="nb-card-header">
        <div>
            <h1>[1] VIEW &amp; EDIT STUDENT DATA &amp; LECTURER DATA</h1>
            <div style="font-size: 0.82rem; font-weight: 800; margin-top: 0.2rem;">
                OPERATOR FUNCTIONALITY 1 OF 2 &bull; STUDENT &amp; LECTURER DIRECTORY
            </div>
        </div>
        <span class="nb-badge nb-badge-orange">PASSWORD HASHES REDACTED &bull; NO GRADE ACCESS</span>
    </div>

    <div tabindex="0" role="region" aria-labelledby="operator-users-caption" style="overflow-x: auto; max-inline-size: 100%;">
        <table class="nb-table">
            <caption id="operator-users-caption" style="text-align: left; font-weight: 900; margin-bottom: 0.5rem;">
                Active Student &amp; Lecturer Records (<?= count($users) ?>)
            </caption>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Role</th>
                    <th scope="col">Full Name (Editable)</th>
                    <th scope="col">Institutional Email (Editable)</th>
                    <th scope="col">Student ID / Lecturer Code (Editable)</th>
                    <th scope="col">Academic Title / Level (Editable)</th>
                    <th scope="col">Save Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $uid = (int) $u['id'];
                    $isLecturer = $u['role'] === 'lecturer';
                    $aliasVal = $isLecturer ? ($u['lecturer_code_alias'] ?? '') : ($u['student_id_alias'] ?? '');
                    $formId = 'op-user-form-' . $uid;
                    ?>
                    <tr>
                        <th scope="row">#<?= $uid ?></th>
                        <td>
                            <span class="nb-badge <?= $isLecturer ? 'nb-badge-orange' : 'nb-badge-lime' ?>">
                                <?= $e(strtoupper((string) $u['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <form id="<?= $e->attr($formId) ?>" action="/operator/users/manage" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                                <input type="hidden" name="op_action" value="edit_data">
                                <input type="hidden" name="redirect_to" value="/operator/users">
                            </form>
                            <label for="fn-<?= $uid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">Full Name</label>
                            <input
                                id="fn-<?= $uid ?>"
                                form="<?= $e->attr($formId) ?>"
                                type="text"
                                name="full_name"
                                value="<?= $e->attr($u['full_name']) ?>"
                                required
                                maxlength="120"
                                autocomplete="name"
                                style="border: 2px solid #000; padding: 0.35rem 0.5rem; font-weight: 700; width: 100%; min-width: 170px;"
                            >
                        </td>
                        <td>
                            <label for="em-<?= $uid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">Email Credential</label>
                            <input
                                id="em-<?= $uid ?>"
                                form="<?= $e->attr($formId) ?>"
                                type="email"
                                name="email"
                                value="<?= $e->attr($u['email']) ?>"
                                required
                                maxlength="190"
                                autocomplete="email"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                style="border: 2px solid #000; padding: 0.35rem 0.5rem; font-weight: 700; width: 100%; min-width: 210px;"
                            >
                        </td>
                        <td>
                            <label for="al-<?= $uid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">
                                <?= $isLecturer ? 'Lecturer Code' : 'Student ID' ?>
                            </label>
                            <input
                                id="al-<?= $uid ?>"
                                form="<?= $e->attr($formId) ?>"
                                type="text"
                                name="alias_code"
                                value="<?= $e->attr($aliasVal) ?>"
                                required
                                maxlength="64"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                style="border: 2px solid #000; padding: 0.35rem 0.5rem; font-weight: 700; width: 100%; min-width: 130px;"
                            >
                        </td>
                        <td>
                            <label for="at-<?= $uid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">Academic Title</label>
                            <input
                                id="at-<?= $uid ?>"
                                form="<?= $e->attr($formId) ?>"
                                type="text"
                                name="academic_title"
                                value="<?= $e->attr($u['academic_title'] ?? ($isLecturer ? 'Faculty Lecturer' : 'Undergraduate Student')) ?>"
                                maxlength="100"
                                style="border: 2px solid #000; padding: 0.35rem 0.5rem; font-weight: 700; width: 100%; min-width: 150px;"
                            >
                        </td>
                        <td style="vertical-align: bottom;">
                            <button type="submit" form="<?= $e->attr($formId) ?>" class="nb-btn nb-btn-lime" style="font-size: 0.75rem; padding: 0.45rem 0.75rem; width: 100%;">
                                SAVE DATA
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$coursesByCode = [];
foreach ($sessions ?? [] as $sRow) {
    $cCode = (string) $sRow['course_code'];
    if (!isset($coursesByCode[$cCode])) {
        $coursesByCode[$cCode] = [
            'course_code' => $cCode,
            'course_name' => (string) $sRow['course_name'],
            'class_code' => (string) $sRow['class_code'],
            'sessions' => [],
        ];
    }
    $coursesByCode[$cCode]['sessions'][] = $sRow;
}

$selectedCourseCode = $selectedCourseCode ?? '';
if ($selectedCourseCode === '' || !isset($coursesByCode[$selectedCourseCode])) {
    $selectedCourseCode = isset($coursesByCode['COMP6695001'])
        ? 'COMP6695001'
        : (string) array_key_first($coursesByCode);
}
$filteredCourse = $coursesByCode[$selectedCourseCode] ?? ['course_code' => '', 'course_name' => '', 'class_code' => '', 'sessions' => []];
$filteredSessions = $filteredCourse['sessions'];
$currentBasePath = ($activeNav ?? '') === 'dashboard' ? '/dashboard' : '/operator/users';
?>
<div class="nb-card" id="session-dates">
    <div class="nb-card-header">
        <div>
            <h2>[2] CHANGE COURSE SESSION DATE</h2>
            <div style="font-size: 0.82rem; font-weight: 800; margin-top: 0.2rem;">
                OPERATOR FUNCTIONALITY 2 OF 2 &bull; FIXED 100-MINUTE SHIFTS (09:20, 11:20, 13:20, 15:20, 17:20)
            </div>
        </div>
        <span class="nb-badge nb-badge-yellow">
            <?= $e($filteredCourse['course_code']) ?> &bull; <?= count($filteredSessions) ?> SESSIONS SHOWN
        </span>
    </div>

    <!-- Course Filter Selector (Shows Only the 6 Sessions of the Selected Course) -->
    <div style="border: 3px solid #000; background: #fff9db; padding: 0.85rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
        <form action="<?= $e->attr($currentBasePath) ?>#session-dates" method="GET" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin: 0;">
            <label for="operator-course-filter" style="font-weight: 900; font-size: 0.85rem;">SELECT COURSE:</label>
            <select id="operator-course-filter" name="course_code" class="nb-select" style="width: auto; margin: 0; min-width: 310px;">
                <?php foreach ($coursesByCode as $cCode => $cMeta): ?>
                    <option value="<?= $e->attr($cCode) ?>" <?= $cCode === $selectedCourseCode ? 'selected' : '' ?>>
                        <?= $e($cCode) ?> (<?= $e($cMeta['class_code']) ?>) — <?= $e($cMeta['course_name']) ?> (<?= count($cMeta['sessions']) ?> Sessions)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="nb-btn nb-btn-lime">SHOW COURSE SESSIONS</button>
        </form>

        <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
            <?php foreach ($coursesByCode as $cCode => $cMeta): ?>
                <a
                    href="<?= $e->attr($currentBasePath) ?>?course_code=<?= $e->attr($cCode) ?>#session-dates"
                    class="nb-btn <?= $cCode === $selectedCourseCode ? 'nb-btn-yellow' : '' ?>"
                    style="font-size: 0.72rem; padding: 0.35rem 0.6rem;"
                >
                    <?= $e($cCode) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div tabindex="0" role="region" aria-labelledby="operator-sessions-caption" style="overflow-x: auto; max-inline-size: 100%;">
        <table class="nb-table">
            <caption id="operator-sessions-caption" style="text-align: left; font-weight: 900; margin-bottom: 0.5rem;">
                <?= $e($filteredCourse['course_code']) ?> — <?= $e($filteredCourse['course_name']) ?> (<?= $e($filteredCourse['class_code']) ?> &bull; <?= count($filteredSessions) ?> Sessions &bull; 100 Minutes per Session)
            </caption>
            <thead>
                <tr>
                    <th scope="col">Course &amp; Class</th>
                    <th scope="col">Session</th>
                    <th scope="col">Mode</th>
                    <th scope="col">Session Date</th>
                    <th scope="col">Lecture Shift (100 Mins)</th>
                    <th scope="col">Room / Venue</th>
                    <th scope="col">Update Session Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $shiftOptions = [
                    '09:20' => '09:20 (09:20 – 11:00)',
                    '11:20' => '11:20 (11:20 – 13:00)',
                    '13:20' => '13:20 (13:20 – 15:00)',
                    '15:20' => '15:20 (15:20 – 17:00)',
                    '17:20' => '17:20 (17:20 – 19:00)',
                ];
                ?>
                <?php foreach ($filteredSessions as $sess): ?>
                    <?php
                    $sid = (int) $sess['id'];
                    $sFormId = 'op-sess-form-' . $sid;
                    $startTs = strtotime((string) $sess['start_time']);
                    $sessionDateVal = date('Y-m-d', $startTs);
                    $currentSlot = date('H:i', $startTs);
                    if (!isset($shiftOptions[$currentSlot])) {
                        $currentSlot = '09:20';
                    }
                    ?>
                    <tr>
                        <th scope="row">
                            <span class="nb-badge nb-badge-yellow"><?= $e($sess['course_code']) ?></span>
                            <div style="font-size: 0.82rem; margin-top: 0.2rem;"><?= $e($sess['course_name']) ?> (<?= $e($sess['class_code']) ?>)</div>
                        </th>
                        <td>
                            <strong>Session <?= (int) $sess['session_number'] ?></strong>
                            <div style="font-size: 0.78rem;"><?= $e($sess['title']) ?></div>
                        </td>
                        <td>
                            <span class="nb-badge nb-badge-blue"><?= $e($sess['delivery_mode']) ?></span>
                        </td>
                        <td>
                            <form id="<?= $e->attr($sFormId) ?>" action="/operator/sessions/update" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <input type="hidden" name="redirect_to" value="<?= $e->attr($currentBasePath . '?course_code=' . $selectedCourseCode . '#session-dates') ?>">
                            </form>
                            <label for="sd-<?= $sid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">Session Date</label>
                            <input
                                id="sd-<?= $sid ?>"
                                form="<?= $e->attr($sFormId) ?>"
                                type="date"
                                name="session_date"
                                value="<?= $e->attr($sessionDateVal) ?>"
                                required
                                style="border: 2px solid #000; padding: 0.3rem 0.45rem; font-weight: 700;"
                            >
                        </td>
                        <td>
                            <label for="sl-<?= $sid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">100-Min Shift</label>
                            <select
                                id="sl-<?= $sid ?>"
                                form="<?= $e->attr($sFormId) ?>"
                                name="session_slot"
                                required
                                style="border: 2px solid #000; padding: 0.3rem 0.45rem; font-weight: 700; background: #fff;"
                            >
                                <?php foreach ($shiftOptions as $slotVal => $slotLabel): ?>
                                    <option value="<?= $e->attr($slotVal) ?>" <?= $currentSlot === $slotVal ? 'selected' : '' ?>>
                                        <?= $e($slotLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <label for="rm-<?= $sid ?>" style="font-size: 0.7rem; font-weight: 800; display: block;">Room</label>
                            <input
                                id="rm-<?= $sid ?>"
                                form="<?= $e->attr($sFormId) ?>"
                                type="text"
                                name="room"
                                value="<?= $e->attr($sess['room'] ?? 'Anggrek - 605') ?>"
                                required
                                maxlength="100"
                                style="border: 2px solid #000; padding: 0.3rem 0.45rem; font-weight: 700; width: 140px;"
                            >
                        </td>
                        <td style="vertical-align: bottom;">
                            <button type="submit" form="<?= $e->attr($sFormId) ?>" class="nb-btn nb-btn-yellow" style="font-size: 0.74rem; padding: 0.4rem 0.65rem; width: 100%;">
                                CHANGE SESSION DATE
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
