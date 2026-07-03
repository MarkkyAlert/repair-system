(function () {
    var form = document.querySelector('form.auth-card');
    if (!form) return;
    var email = document.getElementById('guest_email');
    var phone = document.getElementById('guest_phone');
    if (!email || !phone) return;

    // Inline error styled like the server-side alert; created only when JS is available.
    var alertBox = document.createElement('div');
    alertBox.className = 'auth-alert auth-alert-danger';
    alertBox.setAttribute('role', 'alert');
    alertBox.style.display = 'none';
    alertBox.innerHTML = '<span class="auth-alert-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path></svg></span><p>กรุณากรอกอีเมลหรือเบอร์โทรอย่างน้อย 1 ช่อง เพื่อให้ทีมงานติดต่อกลับได้</p>';
    var emailGroup = email.closest('.field-group');
    if (emailGroup && emailGroup.parentNode) {
        emailGroup.parentNode.insertBefore(alertBox, emailGroup);
    }

    var clearError = function () {
        alertBox.style.display = 'none';
        email.removeAttribute('aria-invalid');
        phone.removeAttribute('aria-invalid');
    };
    email.addEventListener('input', clearError);
    phone.addEventListener('input', clearError);

    form.addEventListener('submit', function (e) {
        if (email.value.trim() === '' && phone.value.trim() === '') {
            e.preventDefault();
            e.stopImmediatePropagation(); // keep the loading handler from firing on a blocked submit
            alertBox.style.display = '';
            email.setAttribute('aria-invalid', 'true');
            phone.setAttribute('aria-invalid', 'true');
            email.focus();
            // Defensive: if the loading handler ran first, re-enable the submit button.
            setTimeout(function () {
                var btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.classList.remove('is-loading'); btn.disabled = false; }
            }, 0);
        }
    });
})();
