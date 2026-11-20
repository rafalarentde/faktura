/*
 * Faktura – invoice editor enhancements (external file, CSP script-src 'self', no libraries).
 * The editor works fully without this script. With it:
 *  1. Live totals: on input/change in form[data-recalc] (debounced 250 ms) the form is POSTed to
 *     its data-recalc URL (POST /invoices/recalc) with the CSRF token in the X-CSRF-Token header;
 *     the server computes everything (Totals::compute) and returns pre-formatted strings, which
 *     replace the [data-total] cells, the VAT rows and the per-line nets. On any error the
 *     server-rendered totals stay as they are.
 *  2. Currency: choosing EUR hides and disables the exchange-rate field (not sent), other
 *     currencies show it.
 *  3. form[data-confirm]: asks before submitting (e.g. deleting a draft).
 */
(function () {
    'use strict';

    var DELAY = 250;

    function text(el, value) {
        if (el) {
            el.textContent = value === null || value === undefined ? '' : String(value);
        }
    }

    function row(label, amount) {
        var div = document.createElement('div');
        div.className = 'totals-row';
        var a = document.createElement('span');
        a.textContent = label;
        var b = document.createElement('span');
        b.className = 'num';
        b.textContent = amount;
        div.appendChild(a);
        div.appendChild(b);
        return div;
    }

    function apply(form, data) {
        var box = document.querySelector('[data-totals]');
        if (!box || !data || !data.formatted) {
            return;
        }
        var f = data.formatted;
        text(box.querySelector('[data-total="net"]'), f.net);
        text(box.querySelector('[data-total="gross"]'), f.gross);
        var vat = box.querySelector('[data-total-vat]');
        if (vat && Array.isArray(f.vat_rows)) {
            while (vat.firstChild) {
                vat.removeChild(vat.firstChild);
            }
            f.vat_rows.forEach(function (v) {
                vat.appendChild(row(v.label, v.amount));
            });
        }
        var eurRow = box.querySelector('[data-total-row="vat_eur"]');
        if (eurRow) {
            eurRow.hidden = !f.vat_eur;
            text(eurRow.querySelector('[data-total="vat_eur"]'), f.vat_eur || '');
        }
        if (Array.isArray(f.lines)) {
            f.lines.forEach(function (value, i) {
                text(form.querySelector('[data-line-net="' + i + '"]'), value);
            });
        }
    }

    function recalc(form) {
        var url = form.getAttribute('data-recalc');
        var tokenField = form.querySelector('input[name="_csrf"]');
        if (!url || !tokenField || !window.fetch || !window.FormData) {
            return;
        }
        var body = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (typeof value === 'string') {
                body.append(key, value);
            }
        });
        form.__recalcSeq = (form.__recalcSeq || 0) + 1;
        var seq = form.__recalcSeq;
        window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': tokenField.value, 'Accept': 'application/json' },
            body: body
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (data && seq === form.__recalcSeq) {
                apply(form, data);
            }
        }).catch(function () {
            /* keep the server-rendered totals */
        });
    }

    function syncRate(form) {
        var currency = form.querySelector('[data-currency]');
        var field = form.querySelector('[data-rate-field]');
        if (!currency || !field) {
            return;
        }
        var eur = currency.value === 'EUR';
        field.hidden = eur;
        var input = field.querySelector('input');
        if (input) {
            input.disabled = eur;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-recalc]');
        if (form) {
            var timer = null;
            var schedule = function (event) {
                if (event.target && event.target.matches && event.target.matches('[data-currency]')) {
                    syncRate(form);
                }
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    recalc(form);
                }, DELAY);
            };
            form.addEventListener('input', schedule);
            form.addEventListener('change', schedule);
        }
        var confirmForms = document.querySelectorAll('form[data-confirm]');
        for (var i = 0; i < confirmForms.length; i++) {
            confirmForms[i].addEventListener('submit', function (event) {
                if (!window.confirm(this.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        }
    });
})();
