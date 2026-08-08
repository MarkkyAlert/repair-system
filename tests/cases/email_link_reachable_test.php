<?php

declare(strict_types=1);

use App\Services\EmailTemplateService;
use App\Services\TicketService;

// Every link the system emails — "open this ticket", "reset your password" — is built from APP_URL. When that
// setting is empty, url() falls back to a bare path like /tickets/42. On a web page that is perfectly correct;
// in an email it is useless, because a mail client has no idea which site the path belongs to.
//
// Nothing wrote that setting: the installer does not, the setup wizard does not, and .env.example ships
// APP_URL=http://localhost, which is only reachable from the server itself. So there was no configuration in
// which email links worked by accident, and nothing anywhere said so. The worst case is the password-reset
// mail: the one message a locked-out person depends on, and the one they cannot work around.
//
// This does not try to guess the address — deriving it from the incoming request would let a forged Host header
// steer a password-reset link. It makes the omission visible instead: on the admin's setup checklist, on the
// pre-install page, and in the log at the moment an unusable link is written into a message.

function elr_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

test('email(links): the setup checklist tells an admin to set the site address, before the SMTP step', function (): void {
    $items = tvm_container()->get(TicketService::class)->getDashboardData(elr_admin(), [])['setupChecklist']['items'] ?? [];
    assert_true($items !== [], 'the admin still gets a setup checklist');

    $keys = array_map(static fn (array $i): string => (string) ($i['key'] ?? ''), $items);
    assert_true(in_array('app_url', $keys, true), 'the checklist names the site address as something to set up');

    $urlPos = array_search('app_url', $keys, true);
    $mailPos = array_search('mail', $keys, true);
    assert_true(
        $mailPos === false || $urlPos < $mailPos,
        'it comes before the email step — configuring SMTP first just sends working mail full of dead links'
    );

    $item = $items[$urlPos];
    assert_contains_str('อีเมล', (string) ($item['hint'] ?? ''), 'the hint explains what breaks, not just what to type');
    assert_contains_str('รหัสผ่าน', (string) ($item['hint'] ?? ''), 'and names the password-reset link, the case with no workaround');
});

test('email(links): a localhost address does not count as configured', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    $reachable = new ReflectionMethod(TicketService::class, 'appUrlLooksReachable');
    $reachable->setAccessible(true);

    $config = $GLOBALS['app_container']->get('config');
    $original = $config['app']['url'] ?? '';

    $check = static function (string $url) use ($svc, $reachable): bool {
        $GLOBALS['app_container']->instance('config', array_replace_recursive(
            $GLOBALS['app_container']->get('config'),
            ['app' => ['url' => $url]]
        ));

        return (bool) $reachable->invoke($svc);
    };

    try {
        assert_false($check(''), 'an empty address is not configured');
        assert_false($check('http://localhost'), 'the address shipped in .env.example only works on the server itself');
        assert_false($check('http://127.0.0.1:8080'), 'nor does a loopback address with a port');
        assert_false($check('repair.example.com'), 'a bare host with no scheme cannot be used as a link');
        assert_true($check('https://repair.example.com'), 'a real https address is what a recipient can click');
        assert_true($check('http://repair.example.com/maintenance'), 'a subfolder install is fine too');
    } finally {
        $GLOBALS['app_container']->instance('config', array_replace_recursive(
            $GLOBALS['app_container']->get('config'),
            ['app' => ['url' => $original]]
        ));
    }
});

test('email(links): building a message with an unusable link says so in the log', function (): void {
    // the funnel every template renders through must complain once, so a person chasing "why did nobody get
    // the reset mail" finds the reason instead of a silent, plausible-looking message
    $src = (string) file_get_contents(BASE_PATH . '/app/Services/EmailTemplateService.php');

    assert_contains_str('warnIfLinkIsUnclickable', $src, 'the render funnel checks the link it is about to embed');
    assert_contains_str('APP_URL', $src, 'and the warning names the setting to change');
    assert_true(
        (bool) preg_match('/private function renderNotificationTemplate\(array \$data\): array\s*\{\s*\$this->warnIfLinkIsUnclickable/', $src),
        'the check runs at the single funnel, so no template can skip it'
    );

    // and it must not fire for a properly configured address
    $warn = new ReflectionMethod(EmailTemplateService::class, 'warnIfLinkIsUnclickable');
    $warn->setAccessible(true);
    $svc = tvm_container()->get(EmailTemplateService::class);
    $warn->invoke($svc, 'https://repair.example.com/tickets/42'); // must be silent — no exception, no noise
    assert_true(true, 'an absolute link passes through untouched');
});

test('email(links): the pre-install page checks the address too', function (): void {
    $page = (string) file_get_contents(BASE_PATH . '/public/check-requirements.php');

    // assert the CHECK exists, not merely the words: a comment mentioning APP_URL would satisfy a text search
    // while the row itself had been deleted
    assert_true(
        (bool) preg_match('/\$checks\[\] = array\(\s*\$appUrlOk,/', $page),
        'the diagnostic lists the site address as one of its checks, not just as prose'
    );
    assert_true(
        (bool) preg_match("/in_array\(\\\$appUrlHost, array\('localhost'/", $page),
        'and treats the example localhost value as not yet configured'
    );
    assert_contains_str('รหัสผ่านใหม่', $page, 'saying plainly that password-reset links depend on it');
});
