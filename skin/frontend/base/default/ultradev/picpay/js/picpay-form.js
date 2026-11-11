/**
 * Ultradev PicPay - formulário do checkout (OpenMage onepage).
 *
 * - Abre/fecha as ramificações Cartão, Pix e Carteira (clicar de novo fecha).
 * - Máscaras e validadores (Prototype Validation).
 * - Tokenização do cartão no navegador com o SDK CheckoutTransparent da PicPay:
 *   número, validade e CVV NUNCA são enviados ao servidor (os campos não têm "name").
 */
(function (window, document) {
    'use strict';

    var CODE = 'ultradev_picpay';
    var TOKEN_TTL = 240000;          // o token vale 5 min na PicPay; renovamos com 4
    var sdkState = 'idle';           // idle | loading | ready | error
    var sdkQueue = [];
    var busy = false;

    function $id(id) { return document.getElementById(id); }
    function digits(v) { return String(v || '').replace(/\D/g, ''); }
    function root() { return $id('payment_form_' + CODE); }
    function field(suffix) { return $id(CODE + '_' + suffix); }

    function isMethodSelected() {
        var radio = $id('p_method_' + CODE);
        return !!(radio && radio.checked);
    }

    function currentType() {
        var input = field('type');
        return input ? input.value : '';
    }

    function wantsCard() {
        return !!root() && isMethodSelected() && currentType() === 'card';
    }

    // ---------------------------------------------------------------- UI

    // style.display inline: o Validation do Prototype só ignora campos ocultos assim.
    function selectType(type) {
        var r = root();
        if (!r) { return; }
        field('type').value = type;
        var buttons = r.querySelectorAll('.picpay-sub');
        for (var i = 0; i < buttons.length; i++) {
            var t = buttons[i].getAttribute('data-type');
            var on = (t === type);
            buttons[i].className = 'picpay-sub' + (on ? ' picpay-on' : '');
            buttons[i].setAttribute('aria-expanded', on ? 'true' : 'false');
            var panel = $id('picpay_panel_' + t);
            if (panel) { panel.style.display = on ? 'block' : 'none'; }
        }
        var wrap = $id('picpay_taxvat_wrap');
        if (wrap) { wrap.style.display = type ? 'block' : 'none'; }
        if (type === 'card') { loadSdk(function () {}, function () {}); } // pré-carrega
    }

    function closest(el, cls, stop) {
        while (el && el !== stop) {
            if (el.className && (' ' + el.className + ' ').indexOf(' ' + cls + ' ') !== -1) { return el; }
            el = el.parentNode;
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var r = root();
        if (!r || !r.contains(e.target)) { return; }
        var btn = closest(e.target, 'picpay-sub', r);
        if (!btn) { return; }
        var t = btn.getAttribute('data-type');
        selectType(currentType() === t ? '' : t); // clicar de novo fecha
    });

    // ---------------------------------------------------------------- máscaras

    document.addEventListener('input', function (e) {
        var r = root();
        if (!r || !r.contains(e.target)) { return; }
        var id = e.target.id || '';
        var v = digits(e.target.value);

        if (id === CODE + '_cc_number') {
            e.target.value = v.substring(0, 19).replace(/(.{4})/g, '$1 ').replace(/\s+$/, '');
        } else if (id === CODE + '_cc_exp') {
            v = v.substring(0, 4);
            e.target.value = v.length > 2 ? v.substring(0, 2) + '/' + v.substring(2) : v;
        } else if (id === CODE + '_cc_cid') {
            e.target.value = v.substring(0, 4);
        } else if (id === CODE + '_taxvat') {
            if (v.length <= 11) {
                e.target.value = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                v = v.substring(0, 14);
                e.target.value = v.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                    .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
            }
        }

        // qualquer alteração nos dados do cartão invalida o token já gerado
        if (id.indexOf(CODE + '_cc_') === 0 || id === CODE + '_taxvat') {
            if (id !== CODE + '_cc_installments') { clearToken(); }
        }
    });

    // ---------------------------------------------------------------- validadores

    function registerValidators() {
        if (typeof Validation === 'undefined' || !Validation.add) { return; }
        Validation.add('validate-picpay-cc', 'Número de cartão inválido.', function (v) {
            v = digits(v);
            if (v.length < 13) { return false; }
            var sum = 0, alt = false;
            for (var i = v.length - 1; i >= 0; i--) {
                var n = parseInt(v.charAt(i), 10);
                if (alt) { n *= 2; if (n > 9) { n -= 9; } }
                sum += n; alt = !alt;
            }
            return sum % 10 === 0;
        });
        Validation.add('validate-picpay-exp', 'Validade inválida.', function (v) {
            var m = /^(\d{2})\/(\d{2})$/.exec(v);
            if (!m) { return false; }
            var month = parseInt(m[1], 10), year = 2000 + parseInt(m[2], 10), now = new Date();
            return month >= 1 && month <= 12 &&
                (year > now.getFullYear() || (year === now.getFullYear() && month >= now.getMonth() + 1));
        });
        Validation.add('validate-picpay-doc', 'Informe um CPF ou CNPJ válido.', function (v) {
            var l = digits(v).length;
            return l === 11 || l === 14;
        });
    }

    // ---------------------------------------------------------------- SDK de tokenização (CDN)

    function loadSdk(ok, fail) {
        if (window.CheckoutTransparent) { sdkState = 'ready'; return ok(); }
        if (sdkState === 'loading') { sdkQueue.push({ ok: ok, fail: fail }); return; }
        var r = root();
        var url = r ? r.getAttribute('data-sdk-url') : '';
        if (!url) { return fail('SDK de tokenização não configurado.'); }

        sdkState = 'loading';
        sdkQueue.push({ ok: ok, fail: fail });
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = function () {
            sdkState = window.CheckoutTransparent ? 'ready' : 'error';
            flush(sdkState === 'ready' ? null : 'Não foi possível carregar o validador de cartão.');
        };
        script.onerror = function () {
            sdkState = 'error';
            flush('Não foi possível carregar o validador de cartão. Verifique sua conexão.');
        };
        document.getElementsByTagName('head')[0].appendChild(script);

        function flush(err) {
            var q = sdkQueue; sdkQueue = [];
            if (err) { sdkState = 'idle'; } // permite nova tentativa
            for (var i = 0; i < q.length; i++) { err ? q[i].fail(err) : q[i].ok(); }
        }
    }

    function sdkMessage(body, fallback) {
        try {
            if (body && body.errors && body.errors.length && body.errors[0].message) {
                return fallback + ' (' + body.errors[0].message + ')';
            }
        } catch (e) { /* ignore */ }
        return fallback;
    }

    // ---------------------------------------------------------------- token

    function clearToken() {
        ['card_token', 'card_brand', 'card_last4'].forEach(function (s) {
            var el = field(s);
            if (el) { el.value = ''; }
        });
        var r = root();
        if (r) { r.removeAttribute('data-token-at'); }
    }

    function tokenIsFresh() {
        var r = root(), el = field('card_token');
        if (!r || !el || !el.value) { return false; }
        var at = parseInt(r.getAttribute('data-token-at') || '0', 10);
        return at > 0 && (new Date().getTime() - at) < TOKEN_TTL;
    }

    function setBusy(on) {
        busy = on;
        var el = field('busy');
        if (el) { el.style.display = on ? 'block' : 'none'; }
    }

    function createToken(ok, fail) {
        var r = root();
        var number = digits(field('cc_number').value);
        var exp = digits(field('cc_exp').value);
        var year = parseInt(exp.substring(2), 10);
        year = year < 100 ? 2000 + year : year;

        loadSdk(function () {
            var sdk = window.CheckoutTransparent;
            sdk.setCredentials({
                merchantCredential: r.getAttribute('data-merchant'),
                transparentToken: r.getAttribute('data-transparent-token')
            });
            sdk.getCardBrand({
                bin: number.substring(0, 6),
                success: function (b) { create(b && b.brand); },
                error: function (e) { fail(sdkMessage(e, 'Não foi possível identificar a bandeira do cartão.')); }
            });

            function create(brand) {
                sdk.createTemporaryCard({
                    card: {
                        brand: brand,
                        number: number,
                        holderName: field('cc_owner').value.replace(/^\s+|\s+$/g, ''),
                        holderDocument: digits(field('taxvat').value),
                        expirationMonth: exp.substring(0, 2),
                        expirationYear: String(year),
                        cvv: digits(field('cc_cid').value)
                    },
                    success: function (body) {
                        if (!body || !body.temporaryToken) {
                            return fail('Não foi possível validar o cartão. Confira os dados.');
                        }
                        field('card_token').value = body.temporaryToken;
                        field('card_brand').value = brand || '';
                        field('card_last4').value = number.slice(-4);
                        r.setAttribute('data-token-at', String(new Date().getTime()));
                        ok();
                    },
                    error: function (e) { fail(sdkMessage(e, 'Não foi possível validar o cartão. Confira os dados.')); }
                });
            }
        }, fail);
    }

    // Garante um token válido e então executa "next" (o save original do checkout)
    function ensureToken(next, onFail) {
        if (tokenIsFresh()) { return next(); }
        if (busy) { return; }
        setBusy(true);
        createToken(function () { setBusy(false); next(); },
            function (msg) { setBusy(false); window.alert(msg); if (onFail) { onFail(); } });
    }

    // ---------------------------------------------------------------- integração com o onepage checkout

    function hookCheckout() {
        if (typeof Payment === 'undefined' || typeof Review === 'undefined' || Payment.prototype._picpayHooked) { return; }
        Payment.prototype._picpayHooked = true;

        var paymentSave = Payment.prototype.save;
        var reviewSave = Review.prototype.save;

        // "Continuar" do passo de pagamento
        Payment.prototype.save = function () {
            var self = this, args = arguments;
            if (!wantsCard()) { return paymentSave.apply(self, args); }
            if (window.checkout && window.checkout.loadWaiting !== false) { return; }
            // validação nativa primeiro (mostra os erros nos campos)
            if (!new Validation(self.form).validate()) { return; }
            ensureToken(function () { paymentSave.apply(self, args); });
        };

        // "Finalizar pedido" (o token é de uso único: sempre renovado se já foi enviado)
        Review.prototype.save = function () {
            var self = this, args = arguments;
            if (!wantsCard()) { return reviewSave.apply(self, args); }
            if (window.checkout && window.checkout.loadWaiting !== false) { return; }
            ensureToken(function () {
                reviewSave.apply(self, args);   // serializa o formulário (inclui o token)
                clearToken();                   // uso único
            }, function () {
                if (window.checkout && window.checkout.gotoSection) { window.checkout.gotoSection('payment', true); }
            });
        };
    }

    function init() {
        registerValidators();
        hookCheckout();
    }

    if (window.Event && Event.observe && typeof Prototype !== 'undefined') {
        document.observe('dom:loaded', init);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // exposto para testes
    window.UltradevPicpay = { init: init, selectType: selectType, createToken: createToken, tokenIsFresh: tokenIsFresh };
}(window, document));
