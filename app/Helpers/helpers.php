<?php
declare(strict_types=1);

// Loader — helpers split into focused concern files.
// Kept here so bootstrap.php's existing `require .../helpers.php` continues to work.
require __DIR__ . '/runtime.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/session.php';
require __DIR__ . '/view.php';
require __DIR__ . '/icons.php';
