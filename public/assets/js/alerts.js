/* alerts.js — Price alert subscription form */
(function () {
    'use strict';

    const form    = document.getElementById('alertForm');
    const msgEl   = document.getElementById('alertFormMsg');

    if (!form) return;

    form.addEventListener('submit', async e => {
        e.preventDefault();
        msgEl.hidden = true;
        msgEl.className = 'alert-form__msg';

        const data = {
            email:        form.email.value.trim(),
            fuel:         form.fuel.value,
            target_price: parseFloat(form.target_price.value),
            city:         form.city?.value || '',
        };

        try {
            const res = await fetch('/api/alerts', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(data),
            });

            const json = await res.json();

            if (res.ok) {
                msgEl.classList.add('alert-form__msg--success');
                msgEl.textContent = json.message || 'Signalas sukurtas! Patikrinkite el. paštą.';
                form.reset();
            } else {
                const errs = json.errors || [json.error || 'Klaida.'];
                msgEl.classList.add('alert-form__msg--error');
                msgEl.textContent = errs.join(' ');
            }
        } catch {
            msgEl.classList.add('alert-form__msg--error');
            msgEl.textContent = 'Tinklo klaida. Bandykite dar kartą.';
        }

        msgEl.hidden = false;
    });
})();
