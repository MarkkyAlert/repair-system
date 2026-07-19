<?php
declare(strict_types=1);

/**
 * CSP nonce ต่อหนึ่ง request สร้างครั้งเดียวแล้ว cache ไว้ตลอดอายุของ request เพื่อให้ค่าที่ส่งออกใน
 * header Content-Security-Policy เป็นค่าเดียวกับที่ประทับบน <script> แบบ inline (theme-init) การเรียก HTTP
 * แต่ละครั้งเป็น PHP process ใหม่ ดังนั้น static cache จึงเป็นแบบต่อ request โดยธรรมชาติ
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Content-Security-Policy สำหรับ response ที่เป็น HTML
 *
 * script-src พก nonce ต่อ request (สำหรับ inline script theme-init ตัวเดียว) รวมทั้ง CDN ของ Chart.js และ
 * จงใจไม่ใส่ 'unsafe-inline' — ซึ่งเป็นหัวใจของเรื่องนี้: <script> ที่ถูกแทรกเข้ามาโดยไม่มี nonce จะรันไม่ได้
 * ส่วน style-src ยังคง 'unsafe-inline' ไว้เพราะ attribute style="" แบบ inline มีอยู่ทั่วไปใน view และ
 * ใส่ nonce ไม่ได้ (การแทรก style เสี่ยงต่ำกว่าการรัน script มาก) รวมทั้ง stylesheet ของ Google Fonts ด้วย
 */
function content_security_policy(string $nonce): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
}

/**
 * security response header แบบคงที่ (ไม่ใช่ CSP) ชุดนี้สะท้อนบรรทัด `Header always set` ใน public/.htaccess
 * แต่การที่มันอยู่ในโค้ดแอปหมายความว่าผู้ซื้อที่ deploy หลัง nginx หรือบน Apache ที่ตั้ง AllowOverride None
 * ก็ยังได้รับมันอยู่ — สำเนาใน .htaccess เป็นแค่การกันเหนียวสองชั้นสำหรับ Apache ไม่ใช่แหล่งเดียว ส่วน CSP ถูกส่งออก
 * แยกต่างหาก (View::render) เพราะมันพก nonce ต่อ request ส่วนสามตัวนี้เป็นค่าคงที่จึงอยู่ที่นี่
 *
 * @return array<string, string>
 */
function security_headers(): array
{
    return [
        // กัน browser ไม่ให้เดา (MIME-sniff) response ไปเป็นชนิดที่อันตรายกว่า (เช่น ไฟล์แนบแบบ inline → HTML)
        'X-Content-Type-Options' => 'nosniff',
        // การป้องกัน clickjacking แบบเก่า; CSP frame-ancestors 'self' ครอบคลุม browser สมัยใหม่ ส่วนตัวนี้ครอบคลุมที่เหลือ
        'X-Frame-Options' => 'SAMEORIGIN',
        // ไม่ปล่อย URL เต็ม (ที่มี id/token) รั่วไปใน Referer ไปยัง origin อื่น
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];
}

/**
 * ส่ง security_headers() ออกไปกับ response ปัจจุบัน เรียกครั้งเดียวต่อ request (public/index.php) เพื่อให้ response
 * ทุกชนิด — HTML, JSON, การดาวน์โหลดไฟล์, redirect — ได้รับ header เหล่านี้ ไม่ว่าจะใช้ web server ตัวใด จะไม่ทำอะไร
 * เมื่อเริ่มส่ง output ไปแล้ว (CLI/tests) เข้าชุดกับการป้องกัน CSP ใน View::render
 */
function emit_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    foreach (security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
}
