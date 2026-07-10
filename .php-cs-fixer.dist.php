<?php
declare(strict_types=1);

/**
 * PHP-CS-Fixer configuration — the style regression net for this codebase (runs in CI next to
 * PHPStan + phpcpd). The ruleset is deliberately tuned to the project's EXISTING de-facto style,
 * not to impose a new one, so it is (near) a no-op on the current code and only blocks future drift:
 *
 *   - Base @PSR12: the code is already written PSR-12-style (4-space indent, brace placement, etc.).
 *   - blank_line_after_opening_tag DISABLED: every file uses `<?php` immediately followed by
 *     `declare(strict_types=1);` with no blank line — that is the project standard; PSR-12's default
 *     would rewrite ~140 files for no benefit, so we keep the project's convention.
 *   - types_spaces = none: union types and multi-catch are written without spaces
 *     (`DomainException|RuntimeException`) everywhere except a handful of stragglers; this locks the
 *     majority style. It only touches type/catch `|`, never bitwise `|` (e.g. JSON_* flags).
 *   - app/Views excluded: those are HTML+PHP templates where statement-indentation rules produce
 *     noisy, meaningless diffs; template layout is not the concern of a code-style net.
 *
 * Buyers can override locally with their own `.php-cs-fixer.php` (this `.dist.` file is the default).
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/public',
        __DIR__ . '/tests',
    ])
    ->exclude('Views')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'blank_line_after_opening_tag' => false,
        'types_spaces' => ['space' => 'none'],
    ])
    ->setFinder($finder);
