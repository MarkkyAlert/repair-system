<?php
declare(strict_types=1);

use App\Core\View;
use App\Repositories\EmailTemplateRepository;
use App\Services\EmailTemplateService;

// EmailTemplateService owns the write + validation for admin template overrides (moved out of
// EmailTemplateController so every write goes through the service and the rules are unit-testable):
//   - saveOverrides validates the template key against the registry, then persists each REGISTERED
//     field (trimmed); fields outside the registry are ignored.
//   - resetOverrides validates the key, then drops all overrides for it.
// Both reject an unknown key with the DomainException message the controller flashes.

function etpl_service(): EmailTemplateService
{
    return tvm_container()->get(EmailTemplateService::class);
}

function etpl_repo(): EmailTemplateRepository
{
    return tvm_container()->get(EmailTemplateRepository::class);
}

test('EmailTemplateService.saveOverrides: persists registered fields (trimmed) for a valid key, then resetOverrides clears them', function (): void {
    $service = etpl_service();
    $repo = etpl_repo();
    $key = 'system_announcement';
    // A real user id: updated_by carries a FK to users(id).
    $editorId = (int) tvm_container()->get(PDO::class)
        ->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
        ->fetchColumn();

    try {
        $repo->resetTemplate($key); // clean slate (table is not seeded)

        $service->saveOverrides($key, [
            'heading' => '  ประกาศทดสอบ  ', // leading/trailing space → trimmed
            'intro' => 'เนื้อหาทดสอบ',
            'footer_note' => '',            // empty is allowed for a registered field
            'not_a_field' => 'ignored',     // outside the registry → must not be written
        ], $editorId);

        $stored = $repo->getByKey($key);
        assert_same('ประกาศทดสอบ', $stored['heading'] ?? null, 'heading trimmed + saved');
        assert_same('เนื้อหาทดสอบ', $stored['intro'] ?? null, 'intro saved');
        assert_true(array_key_exists('footer_note', $stored), 'registered field written even when empty');
        assert_false(array_key_exists('not_a_field', $stored), 'unregistered field ignored');

        $service->resetOverrides($key);
        assert_same([], $repo->getByKey($key), 'resetOverrides drops all overrides for the key');
    } finally {
        $repo->resetTemplate($key); // leave the table as we found it
    }
});

test('EmailTemplateService: unknown template key is rejected on both save and reset', function (): void {
    $service = etpl_service();

    $saveErr = '';
    try {
        $service->saveOverrides('nope_not_real', ['heading' => 'x'], 1);
    } catch (DomainException $exception) {
        $saveErr = $exception->getMessage();
    }
    assert_same('ไม่พบ template ที่ต้องการบันทึก', $saveErr, 'save rejects unknown key');

    $resetErr = '';
    try {
        $service->resetOverrides('nope_not_real');
    } catch (DomainException $exception) {
        $resetErr = $exception->getMessage();
    }
    assert_same('ไม่พบ template ที่ต้องการรีเซ็ต', $resetErr, 'reset rejects unknown key');
});

// The HTML email header kicker is driven by the app_tagline setting (template-review S1). Both HTML
// templates share the same block: EmailTemplateService passes $appTagline, the template escapes it and
// hides the element entirely when empty. Rendered directly (no setting() cache) so a value shows escaped
// and an empty value hides — guards against the kicker being hard-coded back or losing its e()/empty guard.
test('email HTML kicker renders app_tagline (escaped) and hides when empty', function (): void {
    $templates = ['emails/html/notification', 'emails/html/password-reset'];
    foreach ($templates as $tpl) {
        $shown = View::capture($tpl, ['appTagline' => 'ทดสอบ & แบรนด์']);
        assert_true(
            str_contains($shown, '<div class="kicker">ทดสอบ &amp; แบรนด์</div>'),
            "$tpl: kicker shows the app_tagline, HTML-escaped"
        );
        assert_false(
            str_contains($shown, 'ทดสอบ & แบรนด์<'),
            "$tpl: the raw (unescaped) tagline must not reach the output"
        );

        $hidden = View::capture($tpl, ['appTagline' => '']);
        assert_false(
            str_contains($hidden, '<div class="kicker">'),
            "$tpl: an empty app_tagline hides the kicker element entirely"
        );
    }
});

// ── ⭐ atomicity (F3): a template override save is all-or-nothing ──
// saveOverrides writes every registered field of a template; a mid-save DB failure must not leave the template
// half-updated (e.g. a new subject with the old body). upsertFields wraps the per-field upserts in one
// transaction. Inject a failure on the SECOND email_template_overrides write (skip=1) → assert NOTHING persisted.
// Remove the transaction and the first field commits → red (power-proof).
test('emailTemplate(atomicity): a failing field rolls back the whole override save — no half-save (F3)', function (): void {
    $key = 'ET-F3-' . bin2hex(random_bytes(4));
    $threw = false;

    with_failing_pdo('email_template_overrides', function () use ($key, &$threw): void {
        try {
            tvm_container()->get(EmailTemplateRepository::class)
                ->upsertFields($key, ['subject' => 'new subject', 'body' => 'new body'], 1);
        } catch (Throwable) {
            $threw = true;
        }
    }, 1); // let the first field write through, fail the second

    $pdo = tvm_container()->get(PDO::class);
    try {
        assert_true($threw, 'the injected second-field failure must surface');
        $count = $pdo->prepare('SELECT COUNT(*) FROM email_template_overrides WHERE template_key = ?');
        $count->execute([$key]);
        assert_same(0, (int) $count->fetchColumn(), 'no field persisted — the override save is all-or-nothing');
    } finally {
        $pdo->prepare('DELETE FROM email_template_overrides WHERE template_key = ?')->execute([$key]);
    }
});
