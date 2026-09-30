<div
    id="session-timeout-modal"
    class="session-timeout-overlay"
    data-session-lifetime-seconds="{{ config('session.lifetime') * 60 }}"
    hidden
>
    <div class="session-timeout-box" role="alertdialog" aria-modal="true" aria-labelledby="session-timeout-title" aria-describedby="session-timeout-desc">
        <div class="session-timeout-icon" aria-hidden="true">!</div>
        <h2 id="session-timeout-title">Sessão quase expirando!</h2>
        <p id="session-timeout-desc">
            Você está inativo há algum tempo. Por segurança, sua sessão será encerrada em
            <strong id="session-timeout-countdown">60</strong> segundos.
        </p>
        <div class="session-timeout-actions">
            <button type="button" id="session-timeout-stay" class="btn btn--primary">Continuar Conectado</button>
            <button type="button" id="session-timeout-leave" class="btn btn--danger">Sair Agora</button>
        </div>
    </div>
</div>

<style>
    .session-timeout-overlay {
        position: fixed; inset: 0; z-index: 9999;
        background: rgba(15, 18, 26, .55);
        display: flex; align-items: center; justify-content: center;
        padding: 1rem;
    }
    .session-timeout-overlay[hidden] { display: none; }
    .session-timeout-box {
        background: var(--color-surface, #fff);
        color: var(--color-text, #1a1a1a);
        border-radius: 12px;
        max-width: 420px; width: 100%;
        padding: 2rem 1.75rem 1.75rem;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
    }
    .session-timeout-icon {
        width: 56px; height: 56px; margin: 0 auto 1rem;
        border: 2px solid var(--color-warning, #f5a623);
        color: var(--color-warning, #f5a623);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.75rem; font-weight: 700;
    }
    .session-timeout-box h2 { margin: 0 0 .75rem; font-size: 1.25rem; }
    .session-timeout-box p { margin: 0 0 1.5rem; color: var(--color-text-muted, #6b7280); line-height: 1.5; }
    .session-timeout-actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
</style>

<script>
(() => {
    const overlay = document.getElementById('session-timeout-modal');
    if (!overlay) return;

    const lifetimeSeconds = parseInt(overlay.dataset.sessionLifetimeSeconds, 10) || 7200;
    // Warn 60s before expiry, but never more than half the lifetime (keeps short
    // SESSION_LIFETIME values, e.g. in staging, from warning immediately on load).
    const warningSeconds = Math.max(10, Math.min(60, Math.floor(lifetimeSeconds / 2)));
    const countdownEl = document.getElementById('session-timeout-countdown');
    const stayButton = document.getElementById('session-timeout-stay');
    const leaveButton = document.getElementById('session-timeout-leave');
    const logoutForm = document.getElementById('logout-form');
    const keepAliveUrl = '{{ route('session.keep-alive') }}';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    let inactivityTimer = null;
    let countdownTimer = null;
    let warningShown = false;

    function forceLogout() {
        if (logoutForm) {
            logoutForm.submit();
        } else {
            window.location.href = '{{ route('login') }}';
        }
    }

    function showWarning() {
        warningShown = true;
        overlay.hidden = false;
        let remaining = warningSeconds;
        countdownEl.textContent = remaining;
        countdownTimer = window.setInterval(() => {
            remaining -= 1;
            countdownEl.textContent = Math.max(remaining, 0);
            if (remaining <= 0) {
                window.clearInterval(countdownTimer);
                forceLogout();
            }
        }, 1000);
    }

    function scheduleWarning() {
        window.clearTimeout(inactivityTimer);
        inactivityTimer = window.setTimeout(showWarning, (lifetimeSeconds - warningSeconds) * 1000);
    }

    function resetTimers() {
        if (warningShown) return; // once the warning is up, only an explicit choice should dismiss it
        scheduleWarning();
    }

    async function keepAlive() {
        try {
            await fetch(keepAliveUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
        } catch (e) {
            // network hiccup on an inactivity ping is not worth surfacing to the user
        }
    }

    stayButton.addEventListener('click', async () => {
        window.clearInterval(countdownTimer);
        overlay.hidden = true;
        warningShown = false;
        await keepAlive();
        scheduleWarning();
    });

    leaveButton.addEventListener('click', forceLogout);

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, resetTimers, { passive: true });
    });

    scheduleWarning();
})();
</script>
