    <section id="tab-security" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ประวัติการเข้าสู่ระบบ</h2>
                <p class="field-hint">บันทึกการพยายามเข้าสู่ระบบทั้งสำเร็จและล้มเหลว — ใช้ตรวจสอบ brute-force หรือบัญชีต้องสงสัย</p>
            </div>
            <?php
            $recentFailures = (int) ($loginAttemptStats['recent_failures'] ?? 0);
            ?>
            <span class="badge badge-<?= $recentFailures > 0 ? 'danger' : 'success' ?>">
                <?= e((string) $recentFailures) ?> failed (60 นาทีที่ผ่านมา)
            </span>
        </div>

        <?php $attempts = is_array($loginAttempts ?? null) ? $loginAttempts : []; ?>
        <?php if ($attempts === []): ?>
            <div class="panel-card panel-card-sky stack-md">
                <p class="field-hint">ยังไม่มีบันทึกการเข้าสู่ระบบ — เมื่อมีคน login เข้ามา รายการจะปรากฏที่นี่</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="insight-table" data-mobile-card>
                    <thead>
                        <tr>
                            <th scope="col">เวลา</th>
                            <th scope="col">สถานะ</th>
                            <th scope="col">บัญชีที่ใช้เข้า</th>
                            <th scope="col">IP</th>
                            <th scope="col">เหตุผล</th>
                            <th scope="col">User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $attempt):
                            $isSuccess = !empty($attempt['success']);
                            $reason = (string) ($attempt['failure_reason'] ?? '');
                            $reasonLabel = match ($reason) {
                                'rate_limited' => 'ถูก rate limit',
                                'empty_credentials' => 'ไม่ได้กรอกข้อมูล',
                                'wrong_password' => 'รหัสผ่านผิด',
                                'unknown_user' => 'ไม่พบบัญชีนี้',
                                'account_disabled' => 'บัญชีถูกปิดใช้งาน',
                                default => $reason,
                            };
                            $matchedName = (string) ($attempt['user_full_name'] ?? $attempt['user_username'] ?? '');
                        ?>
                            <tr>
                                <td data-label="เวลา"><?= e((string) ($attempt['created_at'] ?? '-')) ?></td>
                                <td data-label="สถานะ">
                                    <span class="badge badge-<?= $isSuccess ? 'success' : 'danger' ?>">
                                        <?= $isSuccess ? 'สำเร็จ' : 'ล้มเหลว' ?>
                                    </span>
                                </td>
                                <td data-label="บัญชี">
                                    <strong><?= e((string) ($attempt['attempted_login'] ?? '-')) ?></strong>
                                    <?php if ($matchedName !== ''): ?>
                                        <div class="field-hint" style="margin-top:.15rem"><?= e($matchedName) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="IP"><code><?= e((string) ($attempt['ip_address'] ?? '-')) ?></code></td>
                                <td data-label="เหตุผล"><?= $reasonLabel !== '' ? e($reasonLabel) : '—' ?></td>
                                <td data-label="User Agent" class="text-truncate" style="max-width:280px" title="<?= e((string) ($attempt['user_agent'] ?? '')) ?>">
                                    <?= e(mb_substr((string) ($attempt['user_agent'] ?? '-'), 0, 60)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="field-hint">แสดง <?= count($attempts) ?> รายการล่าสุด · เก่ากว่านี้ดูได้ผ่าน DB</p>
        <?php endif; ?>
    </section>
