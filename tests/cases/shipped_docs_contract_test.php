<?php

declare(strict_types=1);

// The release script hands the buyer a subset of the repository: it strips the test suite, the docs/ folder,
// the E2E project and the dev tooling. Every .md at the top level ships as-is, which makes it easy to write a
// perfectly true sentence that stops being true the moment it is packaged — the file it points at was removed
// on the way out of the door.
//
// That is exactly what happened: a line was added telling a buyer to read docs/testing-guide.md and run the
// suite before and after hiring someone to make a change. Both are correct in the repository and neither exists
// in the box. A buyer following it hits a missing file, which reads as "this product is broken" long before it
// reads as "that sentence was out of date".
//
// README is the counter-example worth preserving: it lists the test commands and shows tests/ in the tree, but
// says plainly in its testing section that those live in the source repository and are not in the package. That
// is honest, so the rule here is not "never mention them" — it is "never point a buyer at one without saying
// where it actually is".

/** @return list<string> paths the release script deletes from the packaged copy */
function sdc_stripped_paths(): array
{
    $script = (string) file_get_contents(BASE_PATH . '/bin/package-release.sh');
    assert_true($script !== '', 'the release script still exists');

    // scan every rm -rf line, not just the first: the script also uses one to clean its own staging directory,
    // and keep only the tokens that name a real folder in this repository
    preg_match_all('/rm -rf ([^\n]+)/', $script, $m);
    $names = [];
    foreach ($m[1] ?? [] as $line) {
        foreach (preg_split('/\s+/', trim($line)) ?: [] as $token) {
            $token = trim($token, "\"'\\");
            if ($token !== '' && $token !== '.git' && is_dir(BASE_PATH . '/' . $token)) {
                $names[$token] = true;
            }
        }
    }

    return array_keys($names);
}

/** @return list<string> the .md files a buyer receives */
function sdc_shipped_docs(): array
{
    $shipped = [];
    foreach (glob(BASE_PATH . '/*.md') ?: [] as $path) {
        $name = basename($path);
        if (in_array($name, ['handoff.md', 'prompt.md'], true)) {
            continue; // stripped by the release script
        }
        $shipped[] = $name;
    }

    return $shipped;
}

test('shipped docs: nothing the release script strips is referenced without saying it is not in the package', function (): void {
    $stripped = sdc_stripped_paths();
    assert_true($stripped !== [], 'the release script still removes dev-only folders (found: ' . implode(', ', $stripped) . ')');
    assert_true(in_array('tests', $stripped, true), 'the test suite is one of them');
    assert_true(in_array('docs', $stripped, true), 'so is the docs folder');

    // a doc may mention a stripped path only if it also tells the reader it is not in what they bought
    $disclosures = ['ไม่รวมในแพ็กเกจ', 'ไม่ได้รวมมาในแพ็กเกจ', 'ซอร์สโค้ดต้นทาง', 'dev repo'];

    foreach (sdc_shipped_docs() as $doc) {
        $text = (string) file_get_contents(BASE_PATH . '/' . $doc);
        $mentions = [];
        foreach ($stripped as $dir) {
            if (preg_match('#(^|[\s`(])' . preg_quote($dir, '#') . '/[A-Za-z0-9_.\-/]+#', $text) === 1) {
                $mentions[] = $dir;
            }
        }
        if ($mentions === []) {
            continue;
        }

        $discloses = false;
        foreach ($disclosures as $phrase) {
            if (str_contains($text, $phrase)) {
                $discloses = true;
            }
        }
        assert_true(
            $discloses,
            $doc . ' points a buyer at ' . implode('/', $mentions) . '/… which the release script removes, '
            . 'without anywhere saying it is not in the package — the buyer opens the box and the file is missing'
        );
    }
});

test('shipped docs: the advice for hiring a developer only uses what a buyer actually has', function (): void {
    $customize = (string) file_get_contents(BASE_PATH . '/CUSTOMIZE.md');

    // the three steps must be things the packaged system can do on its own
    assert_contains_str('สำรอง & ดาวน์โหลด', $customize, 'it tells them to take a backup first, using the button that is in the product');
    assert_contains_str('สำเนา', $customize, 'and to work on a copy rather than the live system');
    assert_contains_str('ไล่เช็คด้วยมือ', $customize, 'and to walk the main flow by hand afterwards, since the suite is not in the box');

    // it may still mention the suite exists — but only alongside where it actually lives
    if (str_contains($customize, 'ชุดตรวจอัตโนมัติ')) {
        assert_contains_str(
            'ไม่ได้รวมมาในแพ็กเกจ',
            $customize,
            'mentioning the automated suite is fine, but it must say in the same breath that it is not included'
        );
    }
});
