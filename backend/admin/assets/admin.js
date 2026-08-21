/**
 * Anydrop Admin — shared UI behaviors (no build step, plain JS).
 * Included once via _layout_foot.php on every admin/*.php page.
 */
(function () {
    'use strict';

    var shell = document.querySelector('.app-shell');
    var THEME_KEY = 'anydrop_admin_theme';
    var SIDEBAR_KEY = 'anydrop_admin_sidebar_expanded';

    // ---------- Theme (light/dark) ----------
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        var btn = document.getElementById('themeToggleBtn');
        if (btn) btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }

    var savedTheme = localStorage.getItem(THEME_KEY);
    if (!savedTheme) {
        savedTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    applyTheme(savedTheme);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('#themeToggleBtn');
        if (!btn) return;
        var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem(THEME_KEY, next);
    });

    // ---------- Sidebar: desktop collapse / tablet expand / mobile drawer ----------
    if (shell) {
        // Desktop: remember collapsed-to-rail preference.
        if (localStorage.getItem(SIDEBAR_KEY) === '0') {
            shell.classList.add('rail');
        }
    }

    function isMobile() { return window.innerWidth <= 640; }

    document.addEventListener('click', function (e) {
        var menuBtn = e.target.closest('.menu-btn');
        if (menuBtn && shell) {
            if (isMobile()) {
                shell.classList.toggle('drawer-open');
                toggleOverlay(shell.classList.contains('drawer-open'));
            } else {
                // Tablet: temporarily expand the rail.
                shell.classList.toggle('expanded');
            }
            return;
        }

        var collapseBtn = e.target.closest('#sidebarCollapseBtn');
        if (collapseBtn && shell) {
            var nowRail = shell.classList.toggle('rail');
            localStorage.setItem(SIDEBAR_KEY, nowRail ? '0' : '1');
            return;
        }

        var overlay = e.target.closest('.sidebar-overlay');
        if (overlay && shell) {
            shell.classList.remove('drawer-open');
            toggleOverlay(false);
        }
    });

    function toggleOverlay(show) {
        var overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) return;
        overlay.classList.toggle('show', show);
    }

    window.addEventListener('resize', function () {
        if (!isMobile() && shell && shell.classList.contains('drawer-open')) {
            shell.classList.remove('drawer-open');
            toggleOverlay(false);
        }
    });

    // ---------- Toasts ----------
    function ensureToastStack() {
        var stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        return stack;
    }

    window.showToast = function (message, type) {
        if (!message) return;
        var stack = ensureToastStack();
        var el = document.createElement('div');
        el.className = 'toast' + (type ? ' ' + type : '');
        el.textContent = message;
        stack.appendChild(el);
        setTimeout(function () {
            el.classList.add('leaving');
            setTimeout(function () { el.remove(); }, 200);
        }, 4200);
    };

    // Flash message set server-side (see _layout_head.php) fires once on load.
    document.addEventListener('DOMContentLoaded', function () {
        var flashEl = document.getElementById('serverFlash');
        if (flashEl && flashEl.dataset.message) {
            window.showToast(flashEl.dataset.message, flashEl.dataset.type || 'success');
        }
    });

    // ---------- Confirm dialog (replaces window.confirm on forms) ----------
    // Usage: <button data-confirm-title="Delete Osian?" data-confirm-text="This can't be undone.">Delete</button>
    // inside a <form>. On click, shows the shared dialog; only submits the
    // form if the person actually confirms.
    function ensureConfirmDialog() {
        var dlg = document.getElementById('sharedConfirmDialog');
        if (dlg) return dlg;
        dlg = document.createElement('dialog');
        dlg.id = 'sharedConfirmDialog';
        dlg.className = 'modal';
        dlg.innerHTML =
            '<div class="modal-body">' +
            '  <h3 class="modal-title" id="sharedConfirmTitle">Are you sure?</h3>' +
            '  <p class="modal-text" id="sharedConfirmText"></p>' +
            '  <div class="modal-actions">' +
            '    <button type="button" class="btn btn-outline" id="sharedConfirmCancel">Cancel</button>' +
            '    <button type="button" class="btn btn-outline danger" id="sharedConfirmOk">Confirm</button>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(dlg);
        return dlg;
    }

    var pendingForm = null;
    var pendingButton = null;

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-confirm-text], [data-confirm-title]');
        if (!trigger) return;
        var form = trigger.closest('form');
        if (!form) return;

        e.preventDefault();
        var dlg = ensureConfirmDialog();
        dlg.querySelector('#sharedConfirmTitle').textContent = trigger.dataset.confirmTitle || 'Are you sure?';
        dlg.querySelector('#sharedConfirmText').textContent = trigger.dataset.confirmText || '';
        var okBtn = dlg.querySelector('#sharedConfirmOk');
        okBtn.textContent = trigger.dataset.confirmOkLabel || 'Confirm';
        pendingForm = form;
        pendingButton = trigger;
        dlg.showModal();
    });

    document.addEventListener('click', function (e) {
        if (e.target.id === 'sharedConfirmCancel') {
            document.getElementById('sharedConfirmDialog').close();
            pendingForm = null; pendingButton = null;
        }
        if (e.target.id === 'sharedConfirmOk') {
            document.getElementById('sharedConfirmDialog').close();
            if (pendingForm) {
                if (pendingButton) setButtonLoading(pendingButton, true);
                pendingForm.submit();
            }
            pendingForm = null; pendingButton = null;
        }
    });

    // Close any <dialog class="modal"> when clicking its backdrop.
    document.addEventListener('click', function (e) {
        var dlg = e.target.closest('dialog.modal');
        if (dlg && e.target === dlg) dlg.close();
    });

    // ---------- Generic open/close for feature dialogs (e.g. Add Item) ----------
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-open-dialog]');
        if (opener) {
            var target = document.getElementById(opener.getAttribute('data-open-dialog'));
            if (target && target.showModal) target.showModal();
        }
        var closer = e.target.closest('[data-close-dialog]');
        if (closer) {
            var dlg2 = closer.closest('dialog.modal');
            if (dlg2) dlg2.close();
        }
    });

    // ---------- Button loading state on normal form submits ----------
    function setButtonLoading(btn, on) {
        if (!btn) return;
        btn.classList.toggle('is-loading', on);
        btn.disabled = on;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.tagName !== 'FORM') return;
        var submitBtn = form.querySelector('button[type="submit"]:not([data-no-loading])');
        if (submitBtn) setButtonLoading(submitBtn, true);
    });
})();
