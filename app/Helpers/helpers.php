<?php
declare(strict_types=1);

// ตัวโหลด (loader) — รวมไฟล์ helper ที่แยกตามหน้าที่เฉพาะแต่ละด้าน
// เก็บไฟล์นี้ไว้เพื่อให้บรรทัด `require .../helpers.php` เดิมใน bootstrap.php ยังทำงานได้ต่อไป
require __DIR__ . '/runtime.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/session.php';
require __DIR__ . '/security.php';
require __DIR__ . '/view.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/labels.php';
