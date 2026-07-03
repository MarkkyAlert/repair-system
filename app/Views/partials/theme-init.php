<?php
/**
 * Dark-mode init — must run inline in <head> BEFORE first paint to avoid a
 * flash of the light theme. Shared by layouts/app.php and layouts/guest.php.
 */
?>
<script>
    (() => {
        const storedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>
