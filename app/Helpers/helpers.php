<?php
declare(strict_types=1);

// ตัวโหลด รวมไฟล์ helper ที่แยกไว้ตามหน้าที่แต่ละด้าน
// เก็บไฟล์นี้ไว้เพื่อให้บรรทัด `require .../helpers.php` เดิมใน bootstrap.php ยังใช้ได้ต่อไป
require __DIR__ . '/runtime.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/session.php';
require __DIR__ . '/security.php';
require __DIR__ . '/view.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/labels.php';
