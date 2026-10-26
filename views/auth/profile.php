<?php
/** @var \App\Security\Escaper $e */
/** @var \App\Models\User $currentUser */
/** @var string $csrfToken */
?>
<div class="nb-card">
    <div class="nb-card-header">
        <h1>MY PROFILE</h1>
        <a href="/profile/export-data" class="nb-btn nb-btn-lime">DOWNLOAD MY DATA (JSON)</a>
    </div>
    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
        <div style="width: 68px; height: 68px; background: #F4F4F0; border: 4px solid #000; box-shadow: 4px 4px 0 #000; display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0;" title="No selfie uploaded yet">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4"></circle>
                <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>
            </svg>
            <span style="font-size: 0.5rem; font-weight: 900; color: #000; text-transform: uppercase;">NO SELFIE</span>
        </div>
        <div>
            <p style="margin-bottom: 0.3rem;">
                <strong>Pseudonymized Identity:</strong> <?= $e($currentUser->fullName) ?> |
                <strong>Email:</strong> <?= $e($currentUser->email) ?> |
                <strong>Role:</strong> <span class="nb-badge nb-badge-orange"><?= $e($currentUser->role) ?></span>
            </p>
            <p style="font-size: 0.8rem; color: var(--text-muted);">
                <strong>Profile Picture Status:</strong> Default profile icon active (user has not uploaded a selfie picture yet).
            </p>
        </div>
    </div>
</div>

<section class="nb-card">
    <div class="nb-card-header">
        <h2>CHANGE PASSWORD (RE-AUTH REQUIRED)</h2>
        <span class="nb-badge nb-badge-lime">ARGON2ID</span>
    </div>
    <form action="/security/password" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">
        <label for="curr-pass" style="font-weight: 800;">Current Password (Re-authentication)</label>
        <input id="curr-pass" type="password" name="current_password" class="nb-input" required>

        <label for="new-pass" style="font-weight: 800;">New Password (min 10 chars)</label>
        <input id="new-pass" type="password" name="new_password" class="nb-input" required minlength="10">

        <button type="submit" class="nb-btn nb-btn-orange">UPDATE PASSWORD</button>
    </form>
</section>
