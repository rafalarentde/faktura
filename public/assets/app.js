/*
 * Faktura – the only script (loaded in <head> without defer, CSP script-src 'self').
 * 1. Theme: applies the saved theme (localStorage) or the system preference to
 *    <html data-theme> before the body renders, and wires the [data-theme-set] buttons.
 * 2. Small screens: the [data-nav-toggle] button folds the sidebar panel open/closed.
 * Storage can be unavailable (private mode, blocked cookies): every access is guarded.
 */
(function () {
    'use strict';
    var KEY = 'faktura-theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function saved() {
        try {
            var v = window.localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : null;
        } catch (e) {
            return null;
        }
    }

    function store(theme) {
        try {
            window.localStorage.setItem(KEY, theme);
        } catch (e) {
            /* not persisted: the choice still applies to this page */
        }
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        var buttons = document.querySelectorAll('[data-theme-set]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-theme-set') === theme ? 'true' : 'false');
        }
    }

    function systemTheme() {
        return media && media.matches ? 'dark' : 'light';
    }

    root.classList.add('js');
    apply(saved() || systemTheme());

    if (media) {
        var follow = function () {
            if (!saved()) {
                apply(systemTheme());
            }
        };
        if (media.addEventListener) {
            media.addEventListener('change', follow);
        } else if (media.addListener) {
            media.addListener(follow);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        apply(root.getAttribute('data-theme') || systemTheme());

        document.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }
            var themeButton = target.closest('[data-theme-set]');
            if (themeButton) {
                var theme = themeButton.getAttribute('data-theme-set') === 'dark' ? 'dark' : 'light';
                store(theme);
                apply(theme);
                return;
            }
            var toggle = target.closest('[data-nav-toggle]');
            if (toggle) {
                var side = document.getElementById('side');
                if (side) {
                    var open = side.classList.toggle('is-open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            var side = document.getElementById('side');
            if (event.key === 'Escape' && side && side.classList.contains('is-open')) {
                side.classList.remove('is-open');
                var toggle = side.querySelector('[data-nav-toggle]');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.focus();
                }
            }
        });
    });
})();
