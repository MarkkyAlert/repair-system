<?php

declare(strict_types=1);

// ux-review F1: Chart.js paints legend/tick/grid text onto a <canvas>, so toggling the CSS theme (light→dark)
// left the charts with their creation-time colours — light #334155 text on the dark canvas is ~1.7:1, well
// below AA, and Axe can't see canvas text so nothing caught it. applyTheme() now re-themes every chart live.
// (Chart behaviour is JS, exercised live in the browser; this locks the wiring so a refactor can't drop it.)

test('dashboard-charts(F1): applyTheme re-themes the charts, and the sync recolours legend + axes', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

    // applyTheme must trigger the chart re-theme on every toggle.
    assert_true(
        preg_match('/const applyTheme = \([^)]*\) => \{.*?syncDashboardChartTheme\(\);.*?\};/s', $js) === 1,
        'applyTheme() must call syncDashboardChartTheme() so charts re-colour on a light/dark toggle'
    );

    // Charts must be registered so the sync can reach them (the instances were previously discarded).
    assert_true(str_contains($js, 'dashboardCharts.push(chart)'), 'each created chart must be registered in dashboardCharts');

    // The sync must recolour the theme-dependent surfaces Chart.js draws on the canvas.
    assert_true(preg_match('/const syncDashboardChartTheme = \(\) => \{/', $js) === 1, 'syncDashboardChartTheme must exist');
    $sync = (string) (preg_split('/const syncDashboardChartTheme = \(\) => \{/', $js)[1] ?? '');
    assert_true(str_contains($sync, 'legend'), 'the re-theme must update the legend label colour');
    assert_true(str_contains($sync, 'ticks'), 'the re-theme must update the axis tick colour');
    assert_true(str_contains($sync, 'grid'), 'the re-theme must update the grid colour');
    assert_true(str_contains($sync, "chart.update('none')"), 'the re-theme must apply changes via chart.update');

    // Both creation and the re-theme must read the SAME colour source (no drift between them).
    assert_true(
        substr_count($js, 'dashboardChartColors()') >= 2,
        'chart creation and the re-theme must share dashboardChartColors() as a single source of truth'
    );
    // The dark text colour must clear AA on the dark canvas (the light #334155 that used to stick did not).
    assert_true(str_contains($js, "'#cbd5e1'"), 'dark-mode chart text must use the AA-safe #cbd5e1');
});
