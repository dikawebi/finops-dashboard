@php
    $accountUser = auth()->user();
    $accountRole = $accountUser->hasAdministratorRole() ? 'Administrator' : ($accountUser->dashboardRole?->name ?? 'Viewer');
@endphp
<details class="account-menu" data-account-menu>
    <summary class="account-menu-trigger" aria-label="User menu">
        <span class="account-menu-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($accountUser->name, 0, 1)) }}</span>
        <span class="account-menu-trigger-copy"><b data-locale-ignore>{{ $accountUser->name }}</b><small data-locale-ignore>{{ $accountRole }}</small></span>
        <span class="account-menu-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="account-menu-popover">
        <div class="account-menu-identity">
            <b data-locale-ignore>{{ $accountUser->name }}</b>
            <small data-locale-ignore>{{ $accountUser->email }}</small>
            <span data-locale-ignore>{{ $accountRole }}</span>
        </div>
        <button type="button" class="account-menu-action" data-open-password-dialog>🔒 <span>Change password</span></button>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="account-menu-action account-logout">↪ <span>Sign out</span></button>
        </form>
    </div>
</details>
