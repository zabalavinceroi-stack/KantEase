/* ==========================================================================
   KantEase — authentication forms
   Loaded only by /login.php and /register.php.
   ==========================================================================

   Everything here is progressive enhancement. With JavaScript disabled the
   forms still work: they are ordinary POSTs with ordinary fields, the
   password meter simply stays hidden, and the submit button is never disabled.
   Nothing in this file is allowed to become a requirement for signing in.

   No inline handlers and no eval: the Content-Security-Policy is
   script-src 'self' with no 'unsafe-inline', so this has to be a real file,
   which is also why it can be cached.
   ======================================================================== */

(function () {
    'use strict';

    /* --------------------------------------------------------------------
       Show / hide password

       Typing a canteen password on a shared machine in front of other
       students is the main reason someone gets it wrong, so being able to
       check it is worth the two lines. The button is type="button" and
       sits outside the field's name, so pressing it can never submit the
       form or change what the server receives.
       -------------------------------------------------------------------- */

    function setupPasswordToggles(root) {
        var toggles = root.querySelectorAll('[data-password-toggle]');

        Array.prototype.forEach.call(toggles, function (button) {
            var selector = button.getAttribute('data-password-toggle');
            var field = selector ? root.querySelector(selector) : null;

            if (!field) {
                return;
            }

            button.addEventListener('click', function () {
                var showing = field.type === 'text';

                field.type = showing ? 'password' : 'text';
                button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                button.textContent = showing ? 'Show' : 'Hide';

                // Send focus back to the field so typing continues where the
                // student left off.
                field.focus();
            });
        });
    }

    /* --------------------------------------------------------------------
       Password strength

       Deliberately simple, and deliberately a hint rather than a gate.
       Length is the dominant factor, so it is counted first. The server
       enforces the real policy; this only stops a student choosing something
       that is going to be rejected after they submit.

       It never claims a password is good. The strongest label this can
       produce is "Strong", and the server is still the authority.
       -------------------------------------------------------------------- */

    var LABELS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];

    function scorePassword(value) {
        if (!value) {
            return -1;
        }

        var score = 0;

        // Length carries the most weight, one point per band.
        if (value.length >= 8) { score += 1; }
        if (value.length >= 12) { score += 1; }
        if (value.length >= 16) { score += 1; }

        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score += 1; }
        if (/[0-9]/.test(value)) { score += 1; }
        if (/[^A-Za-z0-9]/.test(value)) { score += 1; }

        // Clamp. A very long passphrase should not be labelled "Weak" merely
        // because it is all lowercase letters.
        return Math.max(0, Math.min(4, score - 1));
    }

    function setupStrengthMeter(root) {
        var meter = root.querySelector('[data-strength-meter]');

        if (!meter) {
            return;
        }

        var input = root.querySelector('[data-strength]');
        var fill = meter.querySelector('[data-strength-fill]');
        var label = meter.querySelector('[data-strength-label]');

        if (!input || !fill || !label) {
            return;
        }

        function update() {
            var score = scorePassword(input.value);

            if (score < 0) {
                meter.hidden = true;
                return;
            }

            meter.hidden = false;
            meter.dataset.level = String(score);
            fill.style.width = ((score + 1) / LABELS.length) * 100 + '%';
            label.textContent = LABELS[score];
        }

        input.addEventListener('input', update);

        // A browser that restores a password on back-navigation never fires
        // "input", so paint the current value once.
        update();
    }

    /* --------------------------------------------------------------------
       Submission guard

       Blocks a double click from sending the same registration twice. The
       button is re-enabled in a finally block: leaving it disabled after a
       failed post would trap the student on a dead button, and the page
       re-renders anyway when validation fails.

       The guard is a few milliseconds long. It prevents an impatient double
       click, which is the actual cause. It is not, and does not pretend to
       be, protection against a replayed request — that is what the CSRF
       token and the unique email constraint are for.
       -------------------------------------------------------------------- */

    function setupSubmitGuard(root) {
        var forms = root.querySelectorAll('form[data-form]');

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('[data-submit]');

                if (!button) {
                    return;
                }

                button.disabled = true;

                window.setTimeout(function () {
                    button.disabled = false;
                }, 1500);
            });
        });
    }

    /* --------------------------------------------------------------------
       Boot
       -------------------------------------------------------------------- */

    function boot() {
        var scope = document;

        setupPasswordToggles(scope);
        setupStrengthMeter(scope);
        setupSubmitGuard(scope);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();