<?php
declare(strict_types=1);

/**
 * Central test double for the SAPI-only is_uploaded_file(), declared ONCE before any case file loads
 * (tests/run.php requires this immediately after harness.php, ahead of the cases/*.php glob).
 *
 * Under the CLI SAPI is_uploaded_file() always returns false, which trips every "is this a real upload?"
 * guard (AttachmentService::validateUploads, SystemSettingsService::updateLogo, ParsesCsvUpload) before the
 * real validation/security logic can run. Shadowing it in the App\Services namespace — via PHP's fallback to
 * the same-namespace function before the global one — lets those checks execute against real temp files.
 *
 * Behaviour is a TOGGLE, not a hardcoded true: by default it mirrors the origin check with is_file() (only
 * files that actually exist pass). A test that needs a fixed answer calls fake_is_uploaded_file(true|false)
 * and clears it with reset_is_uploaded_file(). Declaring it here — once, guarded — removes the old per-file
 * shadows, so test run order no longer matters (no "Cannot redeclare" landmine, no alphabetical juggling).
 */

namespace App\Services {
    if (!function_exists('App\\Services\\is_uploaded_file')) {
        function is_uploaded_file(string $filename): bool
        {
            $override = $GLOBALS['__shadow_is_uploaded_file'] ?? null;
            return $override === null ? \is_file($filename) : (bool) $override;
        }
    }

    // Companion shadow for move_uploaded_file(): under CLI the native call always fails (no real HTTP upload),
    // which makes AttachmentService::storeValidated() untestable. Move the temp file with rename()/copy()
    // instead so the store + partial-write-cleanup path can run against real files.
    if (!function_exists('App\\Services\\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            if (@\rename($from, $to)) {
                return true;
            }
            if (\copy($from, $to)) { // cross-filesystem fallback
                @\unlink($from);
                return true;
            }

            return false;
        }
    }
}

namespace {
    /** Force the is_uploaded_file shadow to a fixed result for the current test (remember to reset in finally). */
    function fake_is_uploaded_file(bool $result): void
    {
        $GLOBALS['__shadow_is_uploaded_file'] = $result;
    }

    /** Restore the default is_uploaded_file shadow behaviour (fall back to is_file()). */
    function reset_is_uploaded_file(): void
    {
        unset($GLOBALS['__shadow_is_uploaded_file']);
    }
}
