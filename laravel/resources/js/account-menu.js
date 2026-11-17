const passwordDialog = document.getElementById('changePasswordDialog');
const passwordForm = document.getElementById('changePasswordForm');
const passwordStatus = document.getElementById('changePasswordStatus');

document.querySelectorAll('[data-open-password-dialog]').forEach((button) => {
    button.addEventListener('click', () => {
        button.closest('[data-account-menu]')?.removeAttribute('open');
        if (passwordStatus) passwordStatus.textContent = '';
        passwordDialog?.showModal();
        passwordForm?.querySelector('[name="current_password"]')?.focus();
    });
});

document.querySelectorAll('[data-close-password-dialog]').forEach((button) => {
    button.addEventListener('click', () => passwordDialog?.close());
});

passwordDialog?.addEventListener('click', (event) => {
    if (event.target === passwordDialog) passwordDialog.close();
});

passwordForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = passwordForm.querySelector('[type="submit"]');
    const originalText = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Updating…';
    passwordStatus.className = 'password-dialog-status';
    passwordStatus.textContent = '';

    try {
        const response = await fetch(passwordForm.action, {
            method: 'POST',
            body: new FormData(passwordForm),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const result = await response.json();
        if (!response.ok) {
            const errors = Object.values(result.errors || {}).flat();
            throw new Error(errors.join(' ') || result.message || 'Could not update password.');
        }
        passwordStatus.classList.add('is-success');
        passwordStatus.textContent = result.message || 'Password updated successfully.';
        passwordForm.reset();
        window.setTimeout(() => passwordDialog.close(), 1100);
    } catch (error) {
        passwordStatus.classList.add('is-error');
        passwordStatus.textContent = error.message;
    } finally {
        submit.disabled = false;
        submit.textContent = originalText;
    }
});
