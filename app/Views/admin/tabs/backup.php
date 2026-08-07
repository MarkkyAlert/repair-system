<?php
/** @var array $backup  view-model จาก BackupService::getStatus() */
$backup = $backup ?? [];
$restore = $backup['restore'] ?? [];
$dir = (string) ($restore['dir'] ?? 'storage/backups');
$dbName = (string) ($restore['db_name'] ?? 'repair_system');
$dbUser = (string) ($restore['db_user'] ?? 'root');
$newestGz = (string) ($restore['newest_file'] ?? 'db-YYYY-MM-DD_HHMMSS.sql.gz');
$newestSql = preg_replace('/\.gz$/', '', $newestGz);
$cron = $backup['cron'] ?? [];
$cronLine = (string) ($cron['line'] ?? '0 2 * * * php bin/backup-database.php');
$cronLog = (string) ($cron['log'] ?? 'storage/logs/backup.log');
$dbCharset = (string) ($restore['db_charset'] ?? 'utf8mb4');
$dbCollation = $dbCharset . '_unicode_ci'; // ตรงกับที่ schema.sql สร้างทุกตาราง

// สถานะรวม — ต้องมีไฟล์สำรองจริงถึงจะ "ปกติ": ไม่พบไฟล์ / ยังไม่เคยสำรอง / ค้างนาน / ปกติ
if (empty($backup['has_backups'])) {
    if ((string) ($backup['last_run_at'] ?? '') !== '') {
        // เคยบันทึกว่าสำรองแล้ว แต่ไม่พบไฟล์ในโฟลเดอร์ = ผิดปกติ (ไฟล์อาจถูกลบ/ดิสก์ไม่ถูก mount)
        $statusTone = 'danger';
        $statusLabel = 'ไม่พบไฟล์สำรอง';
    } else {
        $statusTone = 'default';
        $statusLabel = 'ยังไม่เคยสำรอง';
    }
} elseif (!empty($backup['is_stale'])) {
    $statusTone = 'warning';
    $statusLabel = 'สำรองข้อมูลค้างนาน';
} else {
    $statusTone = 'success';
    $statusLabel = 'ทำงานปกติ';
}

// คำสั่ง restore (สร้างเป็นสตริงแล้วให้ e() escape ตอนแสดง — < และ " จะปลอดภัย)
// ขั้นแรกต้องเป็นการสร้างฐานข้อมูล: ไฟล์สำรองมีแต่ตาราง ไม่มี CREATE DATABASE อยู่ข้างใน ถ้าเครื่องพังจนฐานข้อมูลหายไป
// ทั้งก้อน (หรือกู้ขึ้นเครื่องใหม่) การ import ตรง ๆ จะเจอ "Unknown database" แล้วไปต่อไม่ถูก
$cmdCreate = 'mysql -u ' . $dbUser . ' -p -e "CREATE DATABASE IF NOT EXISTS ' . $dbName
    . ' CHARACTER SET ' . $dbCharset . ' COLLATE ' . $dbCollation . '"';
$cmdUnzip = 'gzip -dk ' . $dir . '/' . $newestGz;
$cmdImport = 'mysql -u ' . $dbUser . ' -p ' . $dbName . ' < ' . $dir . '/' . $newestSql;
$cmdVerify = 'mysql -u ' . $dbUser . ' -p -e "SHOW TABLES" ' . $dbName;
?>
<section id="tab-backup" class="panel-card stack-md admin-tab-panel" role="tabpanel" aria-label="สำรองและกู้คืนข้อมูล">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">สำรอง &amp; กู้คืนข้อมูล</h2>
            <p class="field-hint">ดูสถานะการสำรองฐานข้อมูลอัตโนมัติ และวิธีกู้คืนเมื่อเกิดเหตุ</p>
        </div>
        <span class="badge badge-<?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
    </div>

    <div class="panel-card panel-card-indigo stack-md">
        <div class="panel-head">
            <div>
                <h3 class="panel-title panel-title-lg">สำรองทันที (ดาวน์โหลด)</h3>
                <p class="field-hint">สร้างไฟล์สำรองฐานข้อมูลแล้วดาวน์โหลดเก็บไว้ทันที — ทำงานได้แม้โฮสต์ปิดสิทธิ์ shell/mysqldump (ต่างจากการสำรองอัตโนมัติด้วย cron). แนะนำให้กดก่อนอัปเดตระบบทุกครั้ง</p>
            </div>
        </div>
        <form method="post" action="<?= e(url('/admin/backup/download')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">
                <?= lucide('download', 'h-4 w-4') ?>
                สำรอง &amp; ดาวน์โหลด (.sql.gz)
            </button>
        </form>
        <?php
        // ผลของการกดครั้งล่าสุด — ต้องบอกให้ชัดทั้งตอนสำเร็จและตอนไม่สำเร็จ เพราะไฟล์ที่ขาดครึ่งหน้าตาเหมือนไฟล์ที่ใช้ได้
        $onDemand = $backup['on_demand'] ?? [];
        $onDemandState = (string) ($onDemand['state'] ?? 'never');
        ?>
        <?php if ($onDemandState === 'ok'): ?>
            <p class="helper-text" style="display:flex;align-items:center;gap:6px;color:var(--success-700,#15803d)">
                <?= lucide('check-circle', 'h-4 w-4') ?>
                สำรองสำเร็จเมื่อ <?= e(human_date((string) ($onDemand['finished_at'] ?? ''))) ?> · ไฟล์ที่ดาวน์โหลด <?= e((string) ($onDemand['size_human'] ?? '')) ?>
            </p>
        <?php elseif ($onDemandState === 'interrupted'): ?>
            <p class="helper-text" style="display:flex;align-items:center;gap:6px;color:var(--danger-700,#be123c)">
                <?= lucide('triangle-alert', 'h-4 w-4') ?>
                การสำรองที่เริ่มเมื่อ <?= e(human_date((string) ($onDemand['started_at'] ?? ''))) ?> ไม่จบ — ไฟล์ที่ได้ไปใช้กู้คืนไม่ได้ ต้องกดสำรองใหม่
            </p>
        <?php elseif ($onDemandState === 'failed'): ?>
            <p class="helper-text" style="display:flex;align-items:center;gap:6px;color:var(--danger-700,#be123c)">
                <?= lucide('triangle-alert', 'h-4 w-4') ?>
                <?= e((string) ($onDemand['error'] ?? 'การสำรองไม่สำเร็จ')) ?> (<?= e(human_date((string) ($onDemand['finished_at'] ?? ''))) ?>)
            </p>
        <?php endif; ?>
    </div>

    <div class="panel-card panel-card-teal stack-md">
        <div class="panel-head">
            <div>
                <h3 class="panel-title panel-title-lg">สถานะการสำรอง</h3>
                <p class="field-hint">อ่านจากไฟล์จริงใน <code><?= e($dir) ?></code> และเวลาที่ cron รันล่าสุด</p>
            </div>
        </div>
        <dl class="description-list">
            <dt>สถานะ</dt><dd><span class="badge badge-<?= e($statusTone) ?>"><?= e($statusLabel) ?></span></dd>
            <dt>สำรองล่าสุด (cron)</dt>
            <dd><?= (string) ($backup['last_run_at'] ?? '') !== '' ? e(human_date((string) $backup['last_run_at'])) : '—' ?></dd>
            <dt>ไฟล์ล่าสุด</dt>
            <dd><?php if (!empty($backup['newest_file'])): ?><code><?= e((string) $backup['newest_file']) ?></code> · <?= e((string) $backup['newest_size']) ?><?php if (!empty($backup['newest_at'])): ?> · <?= e(human_date((string) $backup['newest_at'])) ?><?php endif; ?><?php else: ?>—<?php endif; ?></dd>
            <dt>จำนวนชุดที่เก็บ</dt><dd><?= (int) ($backup['file_count'] ?? 0) ?> ชุด · รวม <?= e((string) ($backup['total_size'] ?? '0 B')) ?></dd>
            <dt>นโยบายเก็บย้อนหลัง</dt><dd><?= (int) ($backup['retention'] ?? 14) ?> ชุดล่าสุด (เก่ากว่านั้นลบอัตโนมัติ)</dd>
            <dt>โฟลเดอร์</dt><dd><code><?= e($dir) ?></code></dd>
        </dl>
        <?php if (empty($backup['has_backups']) && (string) ($backup['last_run_at'] ?? '') !== ''): ?>
            <p class="helper-text" style="display:flex;align-items:center;gap:6px;color:var(--danger-700,#be123c)">
                <?= lucide('triangle-alert', 'h-4 w-4') ?>
                มีบันทึกว่าสำรองล่าสุดเมื่อ <?= e(human_date((string) $backup['last_run_at'])) ?> แต่ไม่พบไฟล์ใน <code><?= e($dir) ?></code> — ตรวจว่าโฟลเดอร์/ดิสก์ยังปกติ
            </p>
        <?php elseif (!empty($backup['is_stale'])): ?>
            <p class="helper-text" style="display:flex;align-items:center;gap:6px;color:var(--warning-700,#b45309)">
                <?= lucide('triangle-alert', 'h-4 w-4') ?>
                ไม่พบการสำรองภายใน <?= (int) ($backup['stale_hours'] ?? 48) ?> ชม.ล่าสุด — ตรวจว่า cron <code>bin/backup-database.php</code> ยังทำงานอยู่
            </p>
        <?php endif; ?>
    </div>

    <div class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h3 class="panel-title panel-title-lg">ไฟล์สำรองล่าสุด</h3>
                <p class="field-hint">เรียงจากใหม่ไปเก่า (สูงสุด 10 รายการ)</p>
            </div>
        </div>
        <?php if (empty($backup['files'])): ?>
            <?= render_partial('partials/components/empty-state', ['icon' => 'database', 'title' => 'ยังไม่มีไฟล์สำรอง', 'description' => 'เมื่อ cron สำรองข้อมูลทำงาน ไฟล์จะปรากฏที่นี่']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>ชื่อไฟล์</th><th>ขนาด</th><th>วันที่</th></tr></thead>
                    <tbody>
                        <?php foreach ($backup['files'] as $file): ?>
                            <tr>
                                <td><code><?= e((string) $file['name']) ?></code></td>
                                <td><?= e((string) $file['size_human']) ?></td>
                                <td><?= e(human_date((string) $file['date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel-card panel-card-indigo-dashed stack-md">
        <div class="panel-head">
            <div>
                <h3 class="panel-title panel-title-lg">วิธีกู้คืนข้อมูล (Restore)</h3>
                <p class="field-hint">ทำบนเครื่อง server ผ่าน command line — ต้องมีสิทธิ์เข้าถึงฐานข้อมูล</p>
            </div>
        </div>
        <div style="padding:1rem 1.25rem;border-radius:12px;background:var(--danger-50,#fef2f2);color:var(--danger-700,#be123c);border:1px solid var(--danger-200,#fecaca)">
            <strong>คำเตือน:</strong> การกู้คืนจะ<strong>เขียนทับข้อมูลปัจจุบันทั้งหมด</strong> — ควรสำรองชุดล่าสุดไว้ก่อนเสมอ
        </div>
        <ol class="stack-md" style="padding-left:1.25rem;margin:0">
            <li>สร้างฐานข้อมูล <code><?= e($dbName) ?></code> ให้มีอยู่ก่อน — ข้ามขั้นนี้ได้ถ้าฐานข้อมูลยังอยู่ (คำสั่งนี้ไม่ลบของเดิม)
                <pre class="code-block"><?= e($cmdCreate) ?></pre>
            </li>
            <li>แตกไฟล์ <code>.gz</code> ให้เป็น <code>.sql</code>
                <pre class="code-block"><?= e($cmdUnzip) ?></pre>
            </li>
            <li>นำเข้าไฟล์ <code>.sql</code> กลับเข้าฐานข้อมูล <code><?= e($dbName) ?></code> (ระบบจะถามรหัสผ่าน)
                <pre class="code-block"><?= e($cmdImport) ?></pre>
            </li>
            <li>ตรวจสอบว่าตารางถูกกู้คืนครบ
                <pre class="code-block"><?= e($cmdVerify) ?></pre>
            </li>
        </ol>
        <p class="field-hint">
            การสำรองทำงานอัตโนมัติผ่าน cron (แนะนำวันละครั้ง) — บรรทัดนี้เป็น path จริงของเครื่องนี้ ก็อปไปวางได้เลย
            (cron ไม่รู้จักคำสั่ง <code>php</code> สั้น ๆ และไม่ได้เริ่มจากโฟลเดอร์ระบบ จึงต้องเขียนเต็ม):
        </p>
        <pre class="code-block"><?= e($cronLine) ?></pre>
        <p class="field-hint">ผลการทำงานแต่ละคืนจะต่อท้ายไว้ที่ <code><?= e($cronLog) ?></code> — เปิดไฟล์นี้ดูได้เมื่อสงสัยว่า cron ไม่ทำงาน</p>
    </div>
</section>
