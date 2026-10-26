<?php
/** @var \App\Security\Escaper $e */
/** @var string $csrfToken */
?>
<div class="nb-card" style="max-width: 520px; margin: 2rem auto;">
    <div class="nb-card-header">
        <h1>AUTHENTICATION GATEWAY</h1>
        <span class="nb-badge nb-badge-orange">ARGON2ID</span>
    </div>

    <?php if (!empty($error)): ?>
        <div class="nb-alert nb-alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form action="/login" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $e->attr($csrfToken) ?>">

        <label for="login-email" style="font-weight: 900; display: block;">EMAIL ADDRESS</label>
        <input id="login-email" type="email" name="email" class="nb-input" required maxlength="120" value="<?= $e->attr($submittedEmail ?? '') ?>" placeholder="e.g. sora.bauer@lms.ac.id / adrian.keller@lms.ac.id">

        <label for="login-password" style="font-weight: 900; display: block;">PASSWORD</label>
        <input id="login-password" type="password" name="password" class="nb-input" required maxlength="128" placeholder="Enter your password">

        <button type="submit" class="nb-btn nb-btn-lime" style="width: 100%; padding: 0.75rem; font-size: 1rem; margin-top: 0.5rem;">
            SIGN IN TO SECURE LMS
        </button>
    </form>

    <div style="margin-top: 1.25rem; border-top: 3px solid #000; padding-top: 0.75rem; font-size: 0.8rem;">
        <strong>Security Controls Enforced:</strong> Argon2id (64MB, t=4), Account Lockout after 5 failed attempts (15m), Session Regeneration, HttpOnly + Secure + SameSite=Lax Cookies, Strict Nonce CSP.
    </div>
</div>
