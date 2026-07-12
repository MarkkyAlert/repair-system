<?php
declare(strict_types=1);

use App\Core\Router;

// A malformed numeric route id ("/tickets/12junk/approve") used to match the generic [^/]+ placeholder and
// dispatch "12junk", which the controller (int)-cast to ticket 12 — silently acting on the wrong record.
// {…Id} placeholders now match digits only; {token}/{templateKey} keep [^/]+. (round: route id)

function rni_match(string $routePath, string $requestPath): ?array
{
    return call_private(new Router(), 'match', [$routePath, $requestPath]);
}

test('router: a numeric-id placeholder matches digits only — a malformed "/tickets/12junk/..." 404s', function (): void {
    assert_same(['ticketId' => '12'], rni_match('/tickets/{ticketId}/approve', '/tickets/12/approve'), 'a clean numeric id resolves');
    assert_true(rni_match('/tickets/{ticketId}/approve', '/tickets/12junk/approve') === null, 'a malformed "12junk" id does not match → 404, never reaches the (int) cast');
    assert_true(rni_match('/admin/users/{userId}', '/admin/users/7x') === null, 'a malformed userId does not match either');
    // non-id placeholders still accept any non-slash string
    assert_same(['token' => 'abc-XYZ_9'], rni_match('/scan/{token}', '/scan/abc-XYZ_9'), 'a {token} placeholder keeps [^/]+');
    assert_same(['templateKey' => 'ticket.assigned'], rni_match('/admin/email-templates/{templateKey}', '/admin/email-templates/ticket.assigned'), 'a {templateKey} placeholder keeps [^/]+');
});
