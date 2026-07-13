<?php
declare(strict_types=1);

// Rendered-output XSS guard — defence in depth beside the e()/CSP unit tests. e() is unit-tested in isolation
// (security_helpers_test), but that doesn't prove the VIEWS actually route attacker-controlled text through it.
// This renders the comment partial — the main sink for user free text (a comment body + the author's display
// name) — with a live <script> payload and asserts it comes out entity-encoded, never as an executable tag.
// Replace the e()-wrapped body echo in the partial with a raw echo and this reddens. (gap: rendered XSS sinks)

test('xss(render): a comment body + author name are HTML-escaped in the rendered comment partial', function (): void {
    $payload = '<script>alert(document.cookie)</script>';

    $html = render_partial('partials/tickets/comment-item', [
        'ticketId' => 1,
        'comment' => [
            'id' => 1,
            'author_name' => $payload,
            'author_role' => 'requester',
            'created_at' => '2026-07-13 09:00:00',
            'visibility_label' => 'สาธารณะ',
            'visibility_tone' => 'default',
            'body' => $payload,
            'is_internal' => false,
            'can_manage' => false,
            'attachments' => [],
        ],
    ]);

    assert_true($html !== '', 'the comment partial rendered some HTML');
    assert_false(
        str_contains($html, '<script>alert'),
        'the raw <script> payload must NOT survive into the output — that would be stored XSS'
    );
    assert_contains_str(
        '&lt;script&gt;alert(document.cookie)&lt;/script&gt;',
        $html,
        'the comment body is entity-encoded via e() when rendered'
    );
});
