<?php
/**
 * ตั้งค่า dark-mode เริ่มต้น — ต้องรันแบบ inline ใน <head> ก่อนการวาดหน้าจอครั้งแรก (first paint) เพื่อกัน
 * อาการธีมสว่างกะพริบแวบขึ้นมา. ใช้ร่วมกันโดย layouts/app.php และ layouts/guest.php.
 */
?>
<script nonce="<?= e(csp_nonce()) ?>">
    (() => {
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>
