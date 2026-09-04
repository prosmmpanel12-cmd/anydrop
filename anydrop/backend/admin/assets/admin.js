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
        // Desktop-only collapse-to-rail preference. Guarded to >640px so a
        // rail state saved on a desktop session never leaks onto a phone's
        // off-canvas drawer (drawer open/closed is the only mobile state —
        // "rail" has no meaning there). See admin.css mobile media query,
        // which also now defensively ignores .rail as a second layer.
        if (!isMobile() && localStorage.getItem(SIDEBAR_KEY) === '0') {
            shell.classList.add('rail');
        }
    }

    function isMobile() { return window.innerWidth <= 640; }

    document.addEventListener('click', function (e) {
        var menuBtn = e.target.closest('.menu-btn');
        if (menuBtn && shell) {
            // Close any open cselect/info-hint popovers first — they're
            // position:fixed and would otherwise sit stranded on screen
            // (wrong coordinates) once the sidebar drawer opens/closes
            // and the page layout shifts under them.
            document.querySelectorAll('.cselect.open, .info-hint.open').forEach(function (el) { el.classList.remove('open'); });
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
        // Rail is a desktop-only concept — strip it defensively if the
        // viewport crosses into mobile (e.g. rotating a tablet down, or
        // resizing a desktop browser window past 640px).
        if (isMobile() && shell && shell.classList.contains('rail')) {
            shell.classList.remove('rail');
        }
    });

    // ---------- Info-hint ("!") popovers ----------
    // position:fixed body — placed via JS right before it's shown so it
    // can be clamped to the actual viewport (button position varies:
    // sidebar hints, form-field hints, page-title hints all sit at
    // different horizontal spots, and a fixed left:0 in CSS alone runs
    // off the right edge on a narrow screen once the button isn't near
    // the left edge itself).
    function positionInfoHintBody(hint) {
        var btn = hint.querySelector('.info-hint-btn');
        var body = hint.querySelector('.info-hint-body');
        if (!btn || !body) return;
        var btnRect = btn.getBoundingClientRect();
        // Measure while temporarily visible-but-offscreen so offsetWidth
        // is accurate before final placement.
        body.style.left = '-9999px';
        body.style.top = '-9999px';
        body.style.display = 'block';
        var bodyW = body.offsetWidth;
        var bodyH = body.offsetHeight;
        body.style.display = '';

        var margin = 12;
        var left = btnRect.left;
        var maxLeft = window.innerWidth - bodyW - margin;
        if (left > maxLeft) left = maxLeft;
        if (left < margin) left = margin;

        var top = btnRect.bottom + 8;
        // If it would run off the bottom, flip to above the button instead.
        if (top + bodyH > window.innerHeight - margin) {
            top = btnRect.top - bodyH - 8;
        }
        if (top < margin) top = margin;

        body.style.left = left + 'px';
        body.style.top = top + 'px';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.info-hint-btn');
        if (btn) {
            var hint = btn.closest('.info-hint');
            var wasOpen = hint.classList.contains('open');
            // Close any other open hint first (only one popover at a time).
            document.querySelectorAll('.info-hint.open').forEach(function (el) { el.classList.remove('open'); });
            if (!wasOpen) {
                hint.classList.add('open');
                positionInfoHintBody(hint);
            }
            return;
        }
        // Click anywhere outside an open hint's button/body closes it.
        if (!e.target.closest('.info-hint-body')) {
            document.querySelectorAll('.info-hint.open').forEach(function (el) { el.classList.remove('open'); });
        }
    });

    // Reposition open popovers on resize/scroll so they don't drift off
    // an anchor that moved (e.g. rotating the phone, or scrolling a
    // long form with a hint mid-page).
    window.addEventListener('resize', function () {
        document.querySelectorAll('.info-hint.open').forEach(positionInfoHintBody);
    });
    window.addEventListener('scroll', function () {
        document.querySelectorAll('.info-hint.open').forEach(positionInfoHintBody);
    }, true);

    // ---------- Sidebar: collapsible nav groups ----------
    // .open state was already set server-side (_layout_head.php) for
    // whichever group contains $activeNav, so the current page's group
    // is expanded on first paint with no JS flash. This layer only
    // handles the click-to-toggle + remembering which groups the admin
    // left open, per group key, same one-key-per-thing convention as
    // THEME_KEY/SIDEBAR_KEY above.
    var NAV_GROUPS_KEY = 'anydrop_admin_nav_groups_open';

    function readOpenGroups() {
        try {
            return JSON.parse(localStorage.getItem(NAV_GROUPS_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    // Apply any remembered open/closed state on top of the server-side
    // "open the active group" default — an admin who explicitly closed
    // a group (even one with no active page in it right now) keeps it
    // closed on the next page load rather than resetting every nav.
    var savedGroups = readOpenGroups();
    document.querySelectorAll('.nav-group').forEach(function (el) {
        var key = el.getAttribute('data-nav-group');
        if (key in savedGroups) {
            el.classList.toggle('open', savedGroups[key]);
            var toggleBtn = el.querySelector('.nav-group-toggle');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', savedGroups[key] ? 'true' : 'false');
        }
    });

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.nav-group-toggle');
        if (!toggle) return;
        var group = toggle.closest('.nav-group');
        if (!group) return;
        var nowOpen = group.classList.toggle('open');
        toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
        var groups = readOpenGroups();
        groups[group.getAttribute('data-nav-group')] = nowOpen;
        localStorage.setItem(NAV_GROUPS_KEY, JSON.stringify(groups));
    });

    // ---------- Custom select (replaces native OS picker) ----------
    // Progressive enhancement: every real <select> on the page gets
    // wrapped in a .cselect with a styled trigger button + floating
    // panel; the original <select> stays in the DOM (display:none via
    // CSS, not removed) and is what the surrounding <form> actually
    // submits — clicking a .cselect-option just sets the real select's
    // .value and fires a 'change' event, so any page-specific JS
    // listening for that (auto-submitting filters, etc.) keeps working
    // unchanged. A <select multiple> or one with zero options is left
    // completely alone (native picker) — this only wraps the plain
    // single-choice case admin pages actually use.
    function enhanceSelects() {
        document.querySelectorAll('select').forEach(function (sel) {
            if (sel.multiple) return;
            if (sel.closest('.cselect')) return; // already enhanced
            if (sel.options.length === 0) return;
            buildCselect(sel);
        });
    }

    function optionLabel(opt) {
        return (opt.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function buildCselect(sel) {
        var wrap = document.createElement('div');
        wrap.className = 'cselect';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'cselect-trigger';
        trigger.innerHTML = '<span class="cselect-trigger-label"></span>' +
            '<svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
        wrap.appendChild(trigger);

        var panel = document.createElement('div');
        panel.className = 'cselect-panel';

        var useSearch = sel.options.length > 6;
        var searchInput = null;
        if (useSearch) {
            var searchWrap = document.createElement('div');
            searchWrap.className = 'cselect-search-wrap';
            searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'cselect-search';
            searchInput.placeholder = 'Search...';
            searchWrap.appendChild(searchInput);
            panel.appendChild(searchWrap);
        }

        var optionsList = document.createElement('div');
        optionsList.className = 'cselect-options';
        panel.appendChild(optionsList);
        wrap.appendChild(panel);

        function renderOptions(filter) {
            optionsList.innerHTML = '';
            var q = (filter || '').trim().toLowerCase();
            var shown = 0;
            Array.prototype.forEach.call(sel.options, function (opt, i) {
                var label = optionLabel(opt);
                if (q && label.toLowerCase().indexOf(q) === -1) return;
                shown++;
                var row = document.createElement('div');
                row.className = 'cselect-option' + (opt.selected ? ' selected' : '');
                row.textContent = label;
                row.dataset.index = i;
                optionsList.appendChild(row);
            });
            if (shown === 0) {
                var none = document.createElement('div');
                none.className = 'cselect-option no-match';
                none.textContent = 'No matches';
                optionsList.appendChild(none);
            }
        }

        function syncTriggerLabel() {
            var current = sel.options[sel.selectedIndex];
            trigger.querySelector('.cselect-trigger-label').textContent = current ? optionLabel(current) : '';
        }

        function closePanel() {
            wrap.classList.remove('open');
        }

        function openPanel() {
            document.querySelectorAll('.cselect.open').forEach(function (w) { if (w !== wrap) w.classList.remove('open'); });
            renderOptions('');
            if (searchInput) searchInput.value = '';
            wrap.classList.add('open');
            positionCselectPanel(wrap);
            if (searchInput) searchInput.focus();
        }

        trigger.addEventListener('click', function () {
            if (wrap.classList.contains('open')) closePanel(); else openPanel();
        });

        optionsList.addEventListener('click', function (e) {
            var row = e.target.closest('.cselect-option');
            if (!row || row.classList.contains('no-match')) return;
            var idx = parseInt(row.dataset.index, 10);
            if (sel.selectedIndex !== idx) {
                sel.selectedIndex = idx;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncTriggerLabel();
            closePanel();
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () { renderOptions(searchInput.value); });
            searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        // Native <select> changing under us (e.g. another script sets
        // .value directly, or a page does sel.value = '' on filter
        // reset) should keep the visible trigger label in sync.
        sel.addEventListener('change', syncTriggerLabel);

        syncTriggerLabel();
        wrap._cselectClose = closePanel;
    }

    function positionCselectPanel(wrap) {
        var trigger = wrap.querySelector('.cselect-trigger');
        var panel = wrap.querySelector('.cselect-panel');
        if (!trigger || !panel) return;
        var rect = trigger.getBoundingClientRect();
        var margin = 12;
        var maxPanelHeight = Math.min(320, window.innerHeight - margin * 2);
        panel.style.maxHeight = maxPanelHeight + 'px';
        panel.style.width = rect.width + 'px';

        var left = rect.left;
        var maxLeft = window.innerWidth - rect.width - margin;
        if (left > maxLeft) left = Math.max(margin, maxLeft);

        var top = rect.bottom + 6;
        var estHeight = panel.offsetHeight || maxPanelHeight;
        if (top + estHeight > window.innerHeight - margin) {
            var above = rect.top - estHeight - 6;
            top = above > margin ? above : margin;
        }
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.cselect')) return;
        document.querySelectorAll('.cselect.open').forEach(function (w) { w.classList.remove('open'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.cselect.open').forEach(function (w) { w.classList.remove('open'); });
    });
    window.addEventListener('resize', function () {
        document.querySelectorAll('.cselect.open').forEach(positionCselectPanel);
    });
    window.addEventListener('scroll', function () {
        document.querySelectorAll('.cselect.open').forEach(positionCselectPanel);
    }, true);

    document.addEventListener('DOMContentLoaded', enhanceSelects);

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
