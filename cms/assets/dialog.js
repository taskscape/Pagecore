/* Shared accessible modal-dialog lifecycle for admin pages and the inline editor. */
(function (global) {
    'use strict';

    var activeDialogs = [];
    var focusableSelector = [
        'a[href]', 'area[href]', 'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])',
        'textarea:not([disabled])', 'iframe', 'object', 'embed',
        '[contenteditable="true"]', '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    function focusableElements(dialog) {
        return Array.prototype.filter.call(dialog.querySelectorAll(focusableSelector), function (element) {
            return !element.hidden && element.getAttribute('aria-hidden') !== 'true'
                && element.getClientRects().length > 0;
        });
    }

    function makeBackgroundInert(dialog, permittedElements) {
        var permitted = permittedElements || [];
        var changed = [];
        var current = dialog;
        while (current && current.parentElement) {
            Array.prototype.forEach.call(current.parentElement.children, function (sibling) {
                if (sibling === current || permitted.indexOf(sibling) !== -1) return;
                changed.push({
                    element: sibling,
                    inert: sibling.hasAttribute('inert'),
                    ariaHidden: sibling.getAttribute('aria-hidden')
                });
                sibling.setAttribute('inert', '');
                sibling.setAttribute('aria-hidden', 'true');
            });
            current = current.parentElement;
        }
        return changed;
    }

    function restoreBackground(changed) {
        changed.forEach(function (entry) {
            if (!entry.inert) entry.element.removeAttribute('inert');
            if (entry.ariaHidden === null) entry.element.removeAttribute('aria-hidden');
            else entry.element.setAttribute('aria-hidden', entry.ariaHidden);
        });
    }

    function restoreFocus(opener) {
        if (!opener || !opener.isConnected || typeof opener.focus !== 'function') return;
        var inlineDisplay = opener.style && opener.style.display;
        var hidden = global.getComputedStyle && global.getComputedStyle(opener).display === 'none';
        // A hover-revealed opener may become display:none while the modal is covering it.
        // Make it focusable for the instant in which :focus restores its authored display rule.
        if (hidden && opener.style) opener.style.display = 'inline-flex';
        opener.focus();
        if (hidden && opener.style) opener.style.display = inlineDisplay;
    }

    function activate(dialog, options) {
        options = options || {};
        if (!dialog) throw new Error('A dialog element is required.');

        var opener = options.opener || document.activeElement;
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        if (!dialog.hasAttribute('tabindex')) dialog.setAttribute('tabindex', '-1');

        var changed = makeBackgroundInert(dialog, options.permittedElements || []);
        var controller = { dialog: dialog, active: true };
        activeDialogs.push(controller);

        function keydown(event) {
            if (!controller.active || activeDialogs[activeDialogs.length - 1] !== controller) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                if (options.onRequestClose) options.onRequestClose();
                return;
            }
            if (event.key !== 'Tab') return;
            var focusable = focusableElements(dialog);
            if (!focusable.length) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }

        document.addEventListener('keydown', keydown, true);
        controller.deactivate = function () {
            if (!controller.active) return;
            controller.active = false;
            document.removeEventListener('keydown', keydown, true);
            var index = activeDialogs.indexOf(controller);
            if (index !== -1) activeDialogs.splice(index, 1);
            restoreBackground(changed);
            global.setTimeout(function () { restoreFocus(opener); }, 0);
        };

        var initialFocus = options.initialFocus || focusableElements(dialog)[0] || dialog;
        initialFocus.focus();
        return controller;
    }

    global.PagecoreDialog = { activate: activate };
})(window);
