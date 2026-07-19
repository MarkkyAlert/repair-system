<?php
/**
 * ตั้งค่า dark-mode เริ่มต้น — ต้องรัน inline ใน <head> ให้เสร็จก่อน first paint ไม่งั้น
 * ธีมสว่างจะกะพริบแวบขึ้นมาก่อนแล้วค่อยสลับเป็นมืด. ใช้ร่วมกันทั้ง layouts/app.php และ layouts/guest.php.
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
