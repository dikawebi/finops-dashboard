<dialog class="account-password-dialog" id="changePasswordDialog" aria-labelledby="changePasswordTitle">
    <form id="changePasswordForm" action="{{ route('account.password') }}" method="post">
        @csrf
        <button type="button" class="password-dialog-close" data-close-password-dialog aria-label="Close">×</button>
        <span class="password-dialog-eyebrow">ACCOUNT SECURITY</span>
        <h2 id="changePasswordTitle">Change password</h2>
        <p>Enter your current password, then choose a new one.</p>
        <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>New password<input name="password" type="password" minlength="12" autocomplete="new-password" required><small>Use at least 12 characters.</small></label>
        <label>Confirm new password<input name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required></label>
        <p class="password-dialog-status" id="changePasswordStatus" role="status" aria-live="polite"></p>
        <div class="password-dialog-actions"><button type="button" class="soft-button" data-close-password-dialog>Cancel</button><button type="submit" class="primary-button">Update password</button></div>
    </form>
</dialog>
