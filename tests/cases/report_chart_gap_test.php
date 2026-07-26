<?php
declare(strict_types=1);

test('analytics chart: a null period breaks a line instead of connecting across missing data', function (): void {
    $js = file_get_contents(__DIR__ . '/../../public/assets/js/app.js');
    assert_true(is_string($js), 'chart production JavaScript is readable');
    assert_true(
        preg_match('/if\s*\(\s*chartType\s*===\s*[\'"]line[\'"]\s*\).*?spanGaps\s*=\s*false\s*;/s', $js) === 1,
        'line charts must set spanGaps=false so null means "ไม่มีข้อมูล", not an invented connecting line'
    );
});
