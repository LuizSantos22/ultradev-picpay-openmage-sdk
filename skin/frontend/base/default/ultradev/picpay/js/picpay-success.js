/**
 * Ultradev PicPay - página de sucesso (Pix / Carteira QR Code / Cartão).
 * Lê os dados de #picpay-success (data-paid, data-seconds, data-status-url).
 */
(function (window, document) {
    'use strict';

    function init() {
        var box = document.getElementById('picpay-success');
        if (!box) { return; }

        var paid = box.getAttribute('data-paid') === '1';
        var seconds = parseInt(box.getAttribute('data-seconds') || '0', 10);
        var statusUrl = box.getAttribute('data-status-url');

        function showPaid(redirect) {
            var pending = document.getElementById('picpay-pending');
            if (pending) { pending.style.display = 'none'; }
            document.getElementById('picpay-paid').style.display = 'block';
            if (redirect) { setTimeout(function () { window.location.href = redirect; }, 2500); }
        }

        // Copiar código
        var copyBtn = document.getElementById('picpay-copy');
        var codeInput = document.getElementById('picpay-code');
        if (codeInput) {
            codeInput.addEventListener('click', function () { codeInput.select(); });
        }
        if (copyBtn && codeInput) {
            copyBtn.addEventListener('click', function () {
                codeInput.select();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(codeInput.value);
                } else {
                    document.execCommand('copy');
                }
                var label = copyBtn.getElementsByTagName('span');
                if (label.length) { label[label.length - 1].innerHTML = 'Copiado!'; }
            });
        }

        // Contador de expiração (Pix)
        var timerEl = document.getElementById('picpay-timer');
        if (timerEl && seconds > 0) {
            var tick = setInterval(function () {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(tick);
                    timerEl.innerHTML = '0:00';
                    if (!paid) { document.getElementById('picpay-expired').style.display = 'block'; }
                    return;
                }
                var m = Math.floor(seconds / 60), s = seconds % 60;
                timerEl.innerHTML = m + ':' + (s < 10 ? '0' : '') + s;
            }, 1000);
        }

        // Polling do status
        if (paid || !statusUrl || !window.fetch) { return; }
        var attempts = 0;
        var poll = setInterval(function () {
            if (++attempts > 240) { clearInterval(poll); return; } // ~20 min
            window.fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.paid) { clearInterval(poll); paid = true; showPaid(d.redirect); }
                    else if (d && d.canceled) { clearInterval(poll); }
                })
                .catch(function () { /* tenta de novo no próximo ciclo */ });
        }, 5000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}(window, document));
