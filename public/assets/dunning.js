/*
 * Faktura – dunning preview enhancement (external file, CSP script-src 'self', no libraries).
 * The preview works fully without this script ("Update preview" submits the GET form). With it, a
 * change of the level or of a toggle in form[data-autopreview] submits that GET form right away, so
 * the server-side breakdown and letter text follow the choice. Nothing is created or sent by this.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-autopreview]');
        if (!form) {
            return;
        }
        form.addEventListener('change', function (event) {
            var el = event.target;
            if (el && (el.tagName === 'SELECT' || el.type === 'checkbox')) {
                form.submit();
            }
        });
    });
}());
