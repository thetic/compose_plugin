/**
 * Compose Manager – Stack sortable (drag-and-drop reordering)
 *
 * Depends on globals provided by the page:
 *   caURL             – AJAX endpoint for exec.php
 *   composeClientDebug – debug/logging helper (common.js)
 *   $.cookie / $.removeCookie – jquery.cookie plugin
 *   $.fn.sortable     – jQuery UI Sortable
 */

// ── Sort-mode state ────────────────────────────────────────────────

function isComposeSortModeEnabled() {
    return $.cookie('lockbutton') != null;
}

// ── Lock / Unlock button UI ────────────────────────────────────────

function updateComposeLockButtonUI() {
    var unlocked = isComposeSortModeEnabled();
    var $button = $('div.nav-item.LockButton');
    if (!$button.length) {
        return;
    }

    if (unlocked) {
        $button.find('a').prop('title', 'Lock sortable items');
        $button.find('b').removeClass('icon-u-lock green-text').addClass('icon-u-lock-open red-text');
        $button.find('span').text('Lock sortable items');
    } else {
        $button.find('a').prop('title', 'Unlock sortable items');
        $button.find('b').removeClass('icon-u-lock-open red-text').addClass('icon-u-lock green-text');
        $button.find('span').text('Unlock sortable items');
    }
}

// ── Persist sort order ─────────────────────────────────────────────

function saveComposeSortOrder() {
    var projects = $('#compose_list > tr.compose-sortable').map(function() {
        return $(this).data('project');
    }).get();

    return $.post(caURL, {
        action: 'saveStackOrder',
        projects: projects
    }).fail(function(xhr) {
        composeClientDebug('[saveComposeSortOrder] failed', {
            status: xhr.status,
            response: xhr.responseText
        }, 'daemon', 'error');
    });
}

// ── Details-row helpers (detach during drag, reattach on drop) ─────

function getComposeDetailsRowForItem($item) {
    if (!$item || !$item.length) {
        return $();
    }

    var rowId = $item.attr('id') || '';
    if (rowId.indexOf('stack-row-') !== 0) {
        return $();
    }

    return $('#details-row-' + rowId.replace('stack-row-', ''));
}

function reattachComposeDetailsRow($item) {
    var $detailsRow = $item.data('compose-details-row');
    if ($detailsRow && $detailsRow.length) {
        $detailsRow.insertAfter($item);
        $item.removeData('compose-details-row');
    }
}

function normalizeComposeDetailsRowOrder($tbody) {
    if (!$tbody || !$tbody.length) {
        return;
    }

    $tbody.children('tr.compose-sortable').each(function() {
        var $stackRow = $(this);
        var $detailsRow = getComposeDetailsRowForItem($stackRow);
        if ($detailsRow.length) {
            $detailsRow.insertAfter($stackRow);
        }
    });
}

// ── jQuery UI Sortable initialisation ──────────────────────────────

function initComposeSortable() {
    var $tbody = $('#compose_list');
    if (!$tbody.length) {
        return;
    }

    if ($tbody.hasClass('ui-sortable')) {
        $tbody.sortable('destroy');
    }

    if (!isComposeSortModeEnabled()) {
        $tbody.removeClass('compose-sort-enabled');
        return;
    }

    $tbody.addClass('compose-sort-enabled');
    $tbody.sortable({
        helper: 'clone',
        items: '> tr.compose-sortable',
        cursor: 'grab',
        axis: 'y',
        containment: 'parent',
        cancel: '[data-stackid], .compose-updatecolumn a, .compose-updatecolumn .exec, .auto_start, .switchButton, a, button, input',
        delay: 100,
        opacity: 0.5,
        zIndex: 9999,
        forcePlaceholderSize: true,
        start: function(event, ui) {
            var $detailsRow = getComposeDetailsRowForItem(ui.item);
            if ($detailsRow.length) {
                ui.item.data('compose-details-row', $detailsRow.detach());
            }
        },
        update: function() {
            saveComposeSortOrder();
        },
        stop: function(event, ui) {
            reattachComposeDetailsRow(ui.item);
            normalizeComposeDetailsRowOrder($tbody);
        }
    });
}

// ── Sync all sort-mode UI (icons, class, sortable instance) ────────

function syncComposeSortModeUI() {
    var unlocked = isComposeSortModeEnabled();
    $('#compose_stacks tr.compose-sortable').each(function() {
        var $row = $(this);
        $row.find('.expand-icon').toggle(!unlocked);
        $row.find('.mover').toggle(unlocked);
    });

    $('#compose_list').toggleClass('compose-sort-enabled', unlocked);
    updateComposeLockButtonUI();
    initComposeSortable();
}

// ── Global entry-point called by Unraid navigation lock button ─────
// When embedded in the Docker tab, DockerContainers.page defines its own
// LockButton().  We save the previous implementation (if any) and chain
// to it so both Docker containers AND Compose stacks react to the button.
// IMPORTANT: We use window.LockButton assignment (not a function declaration)
// to avoid hoisting — a hoisted function declaration would overwrite the
// global LockButton before we can capture it.

var _composePrevLockButton = window.LockButton || null;

window.LockButton = function LockButton() {
    if (_composePrevLockButton) {
        // Let the Docker (or other) handler run first — it toggles the
        // cookie and manages its own sortable / UI state.
        _composePrevLockButton();
    } else {
        // Standalone page (header-menu mode) — we own the cookie.
        if (isComposeSortModeEnabled()) {
            $.removeCookie('lockbutton');
        } else {
            $.cookie('lockbutton', 'lockbutton');
        }
    }

    syncComposeSortModeUI();
};
