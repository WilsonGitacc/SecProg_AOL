<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var string $csrfToken */
/** @var array $entries */
/** @var string $typeFilter */
/** @var string $dateFilter */
/** @var string $monthFilter */
/** @var array $classes */

$badgeCss = [
    'onsite_class' => 'nb-badge-onsite',
    'virtual_class' => 'nb-badge-virtual',
    'online' => 'nb-badge-online',
    'onsite_exam' => 'nb-badge-exam',
    'assignment' => 'nb-badge-assignment',
];
$monthFilter = $monthFilter ?? '';
?>
<div class="nb-card">
    <div class="nb-card-header">
        <div>
            <h1>MY SCHEDULE // OCT — NOV 30, 2026 (GMT+7)</h1>
            <div style="font-size: 0.82rem; font-weight: 800; margin-top: 0.2rem;">
                TODAY HIGHLIGHT: <span class="nb-badge nb-badge-orange">MON, 26 OCT 2026</span>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="/schedule" class="nb-btn <?= ($dateFilter === '' && $monthFilter === '') ? 'nb-btn-yellow' : '' ?>">ALL OCT–NOV 2026</a>
            <a href="/schedule?month=2026-10" class="nb-btn <?= $monthFilter === '2026-10' ? 'nb-btn-lime' : '' ?>">OCTOBER 2026</a>
            <a href="/schedule?month=2026-11" class="nb-btn <?= $monthFilter === '2026-11' ? 'nb-btn-lime' : '' ?>">NOVEMBER 2026</a>
            <a href="/schedule?date=2026-10-26" class="nb-btn <?= $dateFilter === '2026-10-26' ? 'nb-btn-lime' : '' ?>">MON 26 OCT (TODAY)</a>
            <a href="/schedule?date=2026-11-30" class="nb-btn <?= $dateFilter === '2026-11-30' ? 'nb-btn-lime' : '' ?>">MON 30 NOV</a>
        </div>
    </div>

    <!-- Event Type Filters -->
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        <a href="/schedule?type=all" class="nb-btn <?= $typeFilter === 'all' ? 'nb-btn-yellow' : '' ?>">ALL TYPES</a>
        <a href="/schedule?type=onsite_class" class="nb-btn nb-btn-lime">ONSITE CLASS</a>
        <a href="/schedule?type=virtual_class" class="nb-btn nb-btn-pink">VIRTUAL CLASS</a>
        <a href="/schedule?type=online" class="nb-btn nb-btn-blue">ONLINE</a>
        <a href="/schedule?type=onsite_exam" class="nb-btn">ONSITE EXAM</a>
        <a href="/schedule?type=assignment" class="nb-btn nb-btn-yellow">ASSIGNMENT DUE</a>
    </div>

    <div class="nb-grid-2" style="grid-template-columns: 2fr 1fr;">
        <!-- Agenda List -->
        <div>
            <?php if (empty($entries)): ?>
                <div class="nb-card">
                    <p>No scheduled sessions or assignments match the selected filter.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($entries as $item): ?>
                <?php
                $isToday = str_starts_with((string) $item['start_time'], '2026-10-26');
                $bClass = $badgeCss[$item['entry_type']] ?? 'nb-badge-yellow';
                ?>
                <article class="nb-card" style="<?= $isToday ? 'border-color: #FF6B00; border-width: 4px;' : '' ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                        <div>
                            <span class="nb-badge nb-badge-yellow">
                                <?= $e(date('D, d M Y', strtotime((string) $item['start_time']))) ?>
                            </span>
                            <strong style="margin-left: 0.4rem;">
                                <?= $e($item['class_code']) ?> - <?= $e($item['delivery_type']) ?>
                            </strong>
                        </div>
                        <span class="nb-badge <?= $bClass ?>"><?= $e($item['badge_label']) ?></span>
                    </div>

                    <h2 style="font-size: 1.1rem; margin-bottom: 0.25rem;">
                        <a href="/courses/<?= (int) $item['course_id'] ?><?= !empty($item['session_number']) ? '?session=' . (int) $item['session_number'] : '?tab=assignment' ?>"><?= $e($item['course_name']) ?></a>
                    </h2>
                    <div style="font-size: 0.88rem; font-weight: 800; margin-bottom: 0.35rem;">
                        <?= $e($item['title']) ?>
                    </div>

                    <div style="font-size: 0.8rem; font-weight: 700;">
                        <?php if (!empty($item['session_number'])): ?>
                            <span>Session <?= (int) $item['session_number'] ?> &bull; </span>
                        <?php endif; ?>
                        <span><?= $e(date('H:i', strtotime((string) $item['start_time']))) ?> - <?= $e(date('H:i', strtotime((string) $item['end_time']))) ?> GMT+7</span>
                        <?php if (!empty($item['room'])): ?>
                            <span> &bull; Room: <strong><?= $e($item['room']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($item['seat_number'])): ?>
                            <span> &bull; Seat: <strong><?= $e($item['seat_number']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Monthly Calendar Grids (Dynamically Driven by MySQL schedule_entries / course_sessions) -->
        <div>
            <?php
            $calendarDates = $calendarDates ?? [];
            $todayStr = date('Y-m-d');
            $renderMonthGrid = static function (int $year, int $month, string $title, string $badgeText, string $badgeClass) use ($calendarDates, $todayStr, $e): void {
                $firstDayTs = mktime(0, 0, 0, $month, 1, $year);
                $daysInMonth = (int) date('t', $firstDayTs);
                $startDow = (int) date('w', $firstDayTs); // 0 (Sun) - 6 (Sat)
                $bgByType = [
                    'onsite_class' => '#A3E635',
                    'virtual_class' => '#FF66B2',
                    'online' => '#3B82F6',
                    'onsite_exam' => '#FF6B00',
                    'assignment' => '#FFE600',
                ];
                ?>
                <section class="nb-card">
                    <div class="nb-card-header">
                        <h2><?= $e($title) ?></h2>
                        <span class="nb-badge <?= $e->attr($badgeClass) ?>"><?= $e($badgeText) ?></span>
                    </div>
                    <table class="nb-table" style="text-align: center;">
                        <thead>
                            <tr>
                                <th>S</th><th>M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>S</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $day = 1;
                            $cell = 0;
                            while ($day <= $daysInMonth):
                                echo '<tr>';
                                for ($col = 0; $col < 7; $col++, $cell++):
                                    if ($cell < $startDow || $day > $daysInMonth) {
                                        echo '<td></td>';
                                    } else {
                                        $dateIso = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                        $hasEvent = isset($calendarDates[$dateIso]);
                                        $isTodayCell = ($dateIso === $todayStr);
                                        if ($isTodayCell) {
                                            $bg = 'background: #FF6B00; color: #000; font-weight: 900;';
                                        } elseif ($hasEvent) {
                                            $etype = $calendarDates[$dateIso];
                                            $color = $bgByType[$etype] ?? '#A3E635';
                                            $fg = $etype === 'online' ? 'color: #fff;' : 'color: #000;';
                                            $bg = "background: {$color}; {$fg} font-weight: 900;";
                                        } else {
                                            $bg = '';
                                        }
                                        echo '<td style="' . $bg . '">';
                                        if ($hasEvent || $isTodayCell) {
                                            echo '<a href="/schedule?date=' . $e->attr($dateIso) . '">' . $day . ($isTodayCell ? '*' : '') . '</a>';
                                        } else {
                                            echo $day;
                                        }
                                        echo '</td>';
                                        $day++;
                                    }
                                endfor;
                                echo '</tr>';
                            endwhile;
                            ?>
                        </tbody>
                    </table>
                </section>
                <?php
            };
            $renderMonthGrid(2026, 10, 'OCTOBER 2026', 'DB SYNCED', 'nb-badge-orange');
            $renderMonthGrid(2026, 11, 'NOVEMBER 2026', 'DB SYNCED', 'nb-badge-lime');
            ?>
        </div>
    </div>
</div>
