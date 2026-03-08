<?PHP

/**
 * Compose Manager Main Page
 * The stack list is loaded asynchronously via AJAX for better UX
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

// Load plugin config
$cfg = parse_plugin_cfg($sName);
$autoCheckUpdates = ($cfg['AUTO_CHECK_UPDATES'] ?? 'false') === 'true';
$autoCheckDays = floatval($cfg['AUTO_CHECK_UPDATES_DAYS'] ?? '1');
$showComposeOnTop = ($cfg['SHOW_COMPOSE_ON_TOP'] ?? 'false') === 'true';
$hideComposeFromDocker = ($cfg['HIDE_COMPOSE_FROM_DOCKER'] ?? 'false') === 'true';

// Get Docker Compose CLI version
$composeVersion = trim(shell_exec('docker compose version --short 2>/dev/null') ?? '');

// Note: Stack list is now loaded asynchronously via compose_list.php
// This improves page load time by deferring expensive docker commands
?>

<?php /* ── Critical inline CSS ──────────────────────────────────────────────
   Guarantees table-layout, column widths, and advanced/basic visibility
   are applied synchronously BEFORE any HTML renders — prevents FOUC.
   Non-critical styles remain in comboButton.css loaded via <link>. */ ?>
<style>
    /* Table structure — always fixed layout */
    #compose_stacks {
        width: 100%;
        table-layout: fixed
    }

    /* Clip overflowing content in fixed-layout cells */
    #compose_stacks th,
    #compose_stacks td {
        overflow: hidden;
        text-overflow: ellipsis
    }

    /* Basic-view column widths (5 visible columns → 100%)
   Middle 3 columns equal width; Stack + Autostart bookend. */
    #compose_stacks thead th.col-stack {
        width: 33%
    }

    #compose_stacks thead th.col-update {
        width: 18%
    }

    #compose_stacks thead th.col-containers {
        width: 18%
    }

    #compose_stacks thead th.col-uptime {
        width: 18%
    }

    #compose_stacks thead th.col-autostart {
        width: 13%
    }

    /* Advanced-view column widths (8 visible columns → 100%)
   Description + Path get the most space; compact everything else. */
    #compose_stacks.cm-advanced-view thead th.col-stack {
        width: 14%
    }

    #compose_stacks.cm-advanced-view thead th.col-update {
        width: 9%
    }

    #compose_stacks.cm-advanced-view thead th.col-containers {
        width: 5%
    }

    #compose_stacks.cm-advanced-view thead th.col-uptime {
        width: 8%
    }

    #compose_stacks.cm-advanced-view thead th.col-description {
        width: 26%
    }

    #compose_stacks.cm-advanced-view thead th.col-path {
        width: 32%
    }

    #compose_stacks.cm-advanced-view thead th.col-autostart {
        width: 6%
    }

    /* Center the Containers column */
    #compose_stacks thead th.col-containers,
    #compose_stacks tbody td:nth-child(3) {
        text-align: center
    }

    /* Autostart column: right-align content to push toggles to edge */
    #compose_stacks thead th.col-autostart,
    #compose_stacks>tbody>tr>td:last-child {
        text-align: right
    }

    /* Advanced/basic visibility — CSS-only so no flash of hidden content */
    #compose_stacks .cm-advanced {
        display: none
    }

    #compose_stacks.cm-advanced-view .cm-advanced {
        display: table-cell
    }

    #compose_stacks.cm-advanced-view div.cm-advanced {
        display: block
    }

    /* Detail row */
    #compose_stacks .stack-details-cell {
        width: auto !important
    }

    #compose_stacks tbody tr.stack-details-row {
        background-color: rgba(0, 0, 0, 0.08) !important
    }

    /* Autostart cell */
    #compose_stacks td.nine {
        white-space: nowrap;
        padding-right: 20px
    }

    /* Container sub-table source column: left-align (override theme) */
    .compose-ct-table th:nth-child(3),
    .compose-ct-table td:nth-child(3) {
        text-align: left !important
    }
</style>

<script src="/plugins/compose.manager/javascript/ace/ace.js" type="text/javascript"></script>
<script src="/plugins/compose.manager/javascript/js-yaml/js-yaml.min.js" type="text/javascript"></script>
<script src="/plugins/compose.manager/javascript/common.js" type="text/javascript"></script>
<script>
    var compose_root = <?php echo json_encode($compose_root); ?>;
    var caURL = "/plugins/compose.manager/php/exec.php";
    var compURL = "/plugins/compose.manager/php/compose_util.php";
    var aceTheme = <?php echo (in_array($theme, ['black', 'gray']) ? json_encode('ace/theme/tomorrow_night') : json_encode('ace/theme/tomorrow')); ?>;
    const icon_label = <?php echo json_encode($docker_label_icon); ?>;
    const webui_label = <?php echo json_encode($docker_label_webui); ?>;
    const shell_label = <?php echo json_encode($docker_label_shell); ?>;

    // Auto-check settings from config
    var autoCheckUpdates = <?php echo json_encode($autoCheckUpdates); ?>;
    var autoCheckDays = <?php echo json_encode($autoCheckDays); ?>;
    var showComposeOnTop = <?php echo json_encode($showComposeOnTop); ?>;
    var hideComposeFromDocker = <?php echo json_encode($hideComposeFromDocker); ?>;
    var composeCliVersion = <?php echo json_encode($composeVersion); ?>;

    // ═══════════════════════════════════════════════════════════════════
    // Standard factory functions for container and stack identity objects
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Create a normalized container info object from any raw source.
     * Handles PascalCase→camelCase, resolves name from multiple field aliases,
     * and derives hasUpdate/isPinned when not explicitly set.
     *
     * @param {Object} raw - Raw container object (from server, cache, or update response)
     * @returns {Object} Normalized container info
     */
    function createContainerInfo(raw) {
        if (!raw) return null;
        var name = raw.name || raw.Name || raw.container || raw.Service || raw.service || '';
        var service = raw.service || raw.Service || name;
        var updateStatus = raw.updateStatus || raw.UpdateStatus || '';
        var hasUpdate = (raw.hasUpdate !== undefined) ? !!raw.hasUpdate : (updateStatus === 'update-available');

        return {
            name: name,
            service: service,
            image: raw.image || raw.Image || '',
            state: raw.state || raw.State || '',
            isRunning: (raw.state || raw.State || '') === 'running',
            hasUpdate: hasUpdate,
            updateStatus: updateStatus,
            localSha: raw.localSha || raw.LocalSha || '',
            remoteSha: raw.remoteSha || raw.RemoteSha || '',
            isPinned: (raw.isPinned !== undefined) ? !!raw.isPinned : false,
            pinnedDigest: raw.pinnedDigest || raw.PinnedDigest || '',
            icon: raw.icon || raw.Icon || '',
            shell: raw.shell || raw.Shell || '',
            webUI: raw.webUI || raw.WebUI || '',
            ports: raw.ports || raw.Ports || '',
            networks: raw.networks || raw.Networks || '',
            volumes: raw.volumes || raw.Volumes || '',
            id: raw.id || raw.Id || '',
            created: raw.created || raw.Created || '',
            startedAt: raw.startedAt || raw.StartedAt || ''
        };
    }

    /**
     * Create a normalized stack info object.
     *
     * @param {string} project - The project/stack folder name
     * @param {Array} containers - Array of raw container objects (will be normalized)
     * @param {Object} [opts] - Optional overrides (totalServices, lastChecked, etc.)
     * @returns {Object} Normalized stack info
     */
    function createStackInfo(project, containers, opts) {
        opts = opts || {};
        var normalized = (containers || []).map(createContainerInfo).filter(Boolean);
        var isRunning = normalized.some(function(c) { return c.isRunning; });
        var hasUpdate = normalized.some(function(c) { return c.hasUpdate; });

        return {
            projectName: opts.projectName || project,
            containers: normalized,
            isRunning: (opts.isRunning !== undefined) ? opts.isRunning : isRunning,
            hasUpdate: (opts.hasUpdate !== undefined) ? opts.hasUpdate : hasUpdate,
            totalServices: opts.totalServices || normalized.length,
            lastChecked: opts.lastChecked || null
        };
    }

    /**
     * Merge update status info from a previous stackInfo into a new one.
     * Matches containers by name and copies update fields.
     *
     * @param {Object} stackInfo - The target stack info (mutated in place)
     * @param {Object} prevStatus - Previously saved stack update status
     * @returns {Object} The mutated stackInfo
     */
    function mergeStackUpdateStatus(stackInfo, prevStatus) {
        if (!prevStatus) return stackInfo;

        // Copy stack-level fields
        ['lastChecked', 'updateAvailable', 'checking', 'checked'].forEach(function(k) {
            if (typeof prevStatus[k] !== 'undefined') stackInfo[k] = prevStatus[k];
        });

        // Merge container-level update data
        if (prevStatus.containers && stackInfo.containers) {
            stackInfo.containers.forEach(function(c) {
                var cName = c.name;
                prevStatus.containers.forEach(function(pc) {
                    var prev = (typeof pc.name === 'string') ? pc : createContainerInfo(pc);
                    if (cName === prev.name) {
                        if (prev.hasUpdate && !c.hasUpdate) c.hasUpdate = prev.hasUpdate;
                        if (prev.updateStatus && !c.updateStatus) c.updateStatus = prev.updateStatus;
                        if (prev.localSha && !c.localSha) c.localSha = prev.localSha;
                        if (prev.remoteSha && !c.remoteSha) c.remoteSha = prev.remoteSha;
                        if (prev.isPinned !== undefined) c.isPinned = prev.isPinned;
                    }
                });
            });
            // Recompute stack-level hasUpdate from merged containers
            stackInfo.hasUpdate = stackInfo.containers.some(function(c) { return c.hasUpdate; });
        }

        return stackInfo;
    }

    // ═══════════════════════════════════════════════════════════════════

    // Timers for async operations (plugin-specific to avoid collision with Unraid's global timers)
    var composeTimers = {};

    // Load stack list asynchronously (namespaced to avoid conflict with Docker tab's loadlist)
    function composeLoadlist() {
        // Return a Promise so callers can reliably .then() / .catch() on completion
        return new Promise(function(resolve, reject) {
            composeClientDebug('[composeLoadlist] start', null, 'daemon', 'debug');
            // Ensure local spinner exists and show it after a short delay to avoid flash on fast loads
            if ($('#compose-local-spinner').length === 0) {
                // place spinner just above the list and ensure parent can position overlay if needed
                $('#compose_list').before('<div id="compose-local-spinner" class="compose-local-spinner" style="display:none"><i class="fa fa-spin fa-circle-o-notch"></i> <span class="compose-local-spinner-text">Loading stack list...</span></div>');
                $('#compose_list').parent().css('position', 'relative');
            }
            composeTimers.load = setTimeout(function() {
                $('#compose-local-spinner').fadeIn('fast');
            }, 500);

            $.get('/plugins/compose.manager/php/compose_list.php')
            .done(function(data) {
                try {
                    composeClientDebug('[composeLoadlist] success', null, 'daemon', 'debug');
                } catch (e) {}
                clearTimeout(composeTimers.load);

                // Insert the loaded content
                $('#compose_list').html(data);

                // Initialize UI components for the newly loaded content
                initStackListUI();

                // Debug: log initial per-stack rendered status icons (data-status attribute)
                try {
                    var initialStatuses = [];
                    $('#compose_stacks tr.compose-sortable').each(function() {
                        var project = $(this).data('project');
                        var icon = $(this).find('.compose-status-icon').first();
                        var status = icon.attr('data-status') || icon.attr('class') || '';
                        initialStatuses.push({
                            project: project,
                            status: status
                        });
                    });
                    composeClientDebug('[composeLoadlist] initial-stack-statuses', {
                        stacks: initialStatuses
                    }, 'daemon', 'debug');
                } catch (e) {}

                // Normalize icons based on state text to ensure server-side render and
                // client-side update logic agree (workaround for caching or older server HTML)
                try {
                    $('#compose_stacks tr.compose-sortable').each(function() {
                        var $row = $(this);
                        var stateText = $row.find('.state').text().toLowerCase();
                        var $icon = $row.find('.compose-status-icon').first();
                        if (!$icon.length) return;
                        var desiredShape = null;
                        var desiredColor = 'grey-text';
                        if (stateText.indexOf('partial') !== -1) {
                            desiredShape = 'exclamation-circle';
                            desiredColor = 'orange-text';
                        } else if (stateText.indexOf('started') !== -1) {
                            desiredShape = 'play';
                            desiredColor = 'green-text';
                        } else if (stateText.indexOf('paused') !== -1) {
                            desiredShape = 'pause';
                            desiredColor = 'orange-text';
                        } else {
                            desiredShape = 'square';
                            desiredColor = 'grey-text';
                        }

                        // If icon already matches, skip
                        if (($icon.hasClass('fa-' + desiredShape) && $icon.hasClass(desiredColor))) return;

                        composeClientDebug('[composeLoadlist] normalize-icon', {
                            project: $row.data('project'),
                            stateText: stateText,
                            desiredShape: desiredShape,
                            desiredColor: desiredColor,
                            before: $icon.attr('class')
                        }, 'daemon', 'debug');
                        // Remove old fa-* classes, color classes and apply desired ones
                        $icon.removeClass(function(i, cls) {
                            return (cls.match(/fa-[^\s]+/g) || []).join(' ');
                        });
                        $icon.removeClass('green-text orange-text grey-text cyan-text');
                        $icon.addClass('fa fa-' + desiredShape + ' ' + desiredColor + ' compose-status-icon');
                        composeClientDebug('[composeLoadlist] normalize-icon-done', {
                            project: $row.data('project'),
                            after: $icon.attr('class')
                        }, 'daemon', 'debug');
                    });
                } catch (e) {}

                // Cleanup any temporary per-container spinners or leftover in-progress state
                try {
                    $('#compose_list').find('.compose-container-spinner').each(function() {
                        var $sp = $(this);
                        var $wrap = $sp.closest('.hand');
                        $sp.remove();
                        $wrap.find('img').css('opacity', 1);
                    });
                    // Restore any state text preserved by setStackActionInProgress
                    $('#compose_stacks .state').each(function() {
                        var $s = $(this);
                        if ($s.data('orig-text')) {
                            $s.text($s.data('orig-text'));
                            $s.removeData('orig-text');
                        }
                    });
                } catch (e) {}

                // Hide local spinner
                $('#compose-local-spinner').fadeOut('fast');

                // Show buttons now that content is loaded
                $('input[type=button]').show();

                // Notify other features (e.g. hide-from-docker) that compose list is ready
                $(document).trigger('compose-list-loaded');

                // Resolve the promise so callers know the list has been loaded
                try { resolve(data); } catch (e) { resolve(); }
            })
            .fail(function(xhr, status, error) {
                composeClientDebug('[composeLoadlist] failed', {
                    status: status,
                    error: error
                }, 'daemon', 'error');
                clearTimeout(composeTimers.load);
                $('#compose-local-spinner').fadeOut('fast');
                $('#compose_list').html('<tr><td colspan="7" style="text-align:center;padding:20px;color:#c00;">Failed to load stack list. Please refresh the page.</td></tr>');

                // Reject the promise so callers can handle the error
                try { reject({xhr: xhr, status: status, error: error}); } catch (e) { reject(error); }
            });
        });
    }

    // Initialize UI components after stack list is loaded
    function initStackListUI() {
        // Initialize autostart switches - scope to compose_list to avoid conflict with Docker tab
        // Avoid re-initializing switchButton on elements that may be re-rendered.
        $('#compose_list .auto_start').each(function() {
            var $el = $(this);
            if ($el.data('switchbutton-initialized')) return;
            $el.switchButton({
                labels_placement: 'right',
                on_label: "On",
                off_label: "Off",
                clear: false
            });
            $el.data('switchbutton-initialized', true);
        });
        // Ensure change handler is bound only once
        $('#compose_list').off('change', '.auto_start').on('change', '.auto_start', function() {
            var script = $(this).attr("data-scriptname");
            var auto = $(this).prop('checked');
            $.post(caURL, {
                action: 'updateAutostart',
                script: script,
                autostart: auto
            });
        });

        // Initialize context menus for stack icons
        $('[id^="stack-"][data-stackid]').each(function() {
            addComposeStackContext(this.id);
        });

        // Apply readmore to descriptions - scope to compose_stacks, exclude container detail rows
        $('#compose_stacks .docker_readmore').not('.stack-details-container .docker_readmore').readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });

        // Apply current view mode (advanced/basic) via CSS class on table
        var advanced = $.cookie('compose_listview_mode') === 'advanced';
        if (advanced) {
            $('#compose_stacks').addClass('cm-advanced-view');
        } else {
            $('#compose_stacks').removeClass('cm-advanced-view');
        }

        // Seed expandedStacks from any rows rendered expanded server-side
        $('.stack-details-row:visible').each(function() {
            var stackId = this.id.replace('details-row-', '');
            expandedStacks[stackId] = true;
        });

        // Load saved update status after list is loaded
        loadSavedUpdateStatus();
    }

    // Load external stylesheets (non-critical styles — critical ones are inline above)
    (function() {
        var base = '<? autov("/plugins/compose.manager/styles/comboButton.css"); ?>';
        var editor = '<? autov("/plugins/compose.manager/styles/editorModal.css"); ?>';
        if (!$('link[href="' + base + '"]').length)
            $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', base));
        if (!$('link[href="' + editor + '"]').length)
            $('head').append($('<link rel="stylesheet" type="text/css" />').attr('href', editor));
    })();

    function basename(path) {
        return path.replace(/\\/g, '/').replace(/.*\//, '');
    }

    function dirname(path) {
        return path.replace(/\\/g, '/').replace(/\/[^\/]*$/, '');
    }

    // Editor modal state
    var editorModal = {
        editors: {},
        currentTab: 'compose',
        originalContent: {},
        modifiedTabs: new Set(),
        currentProject: null,
        validationTimeout: null,
        // Settings state
        originalSettings: {},
        modifiedSettings: new Set(),
        // Labels state
        originalLabels: {},
        modifiedLabels: new Set(),
        labelsData: null // Stores the parsed compose and override data
    };

    // Debounce helper for validation
    function debounceValidation(type, content) {
        if (editorModal.validationTimeout) {
            clearTimeout(editorModal.validationTimeout);
        }
        editorModal.validationTimeout = setTimeout(function() {
            validateYaml(type, content);
        }, 300);
    }

    // Calculate unRAID header offset dynamically
    function updateModalOffset() {
        var headerOffset = 0;
        var header = document.getElementById('header');
        var menu = document.getElementById('menu');
        var tabs = document.querySelector('div.tabs');

        if (header) {
            headerOffset += header.offsetHeight;
        }
        if (menu) {
            headerOffset += menu.offsetHeight;
        }
        if (tabs) {
            headerOffset += tabs.offsetHeight;
        }

        // Add a small buffer
        headerOffset += 10;

        // Set CSS custom property
        document.documentElement.style.setProperty('--unraid-header-offset', headerOffset + 'px');
        var overlay = document.getElementById('editor-modal-overlay');
        if (overlay) {
            overlay.style.setProperty('--unraid-header-offset', headerOffset + 'px');
        }
    }

    // Initialize editor modal
    function initEditorModal() {
        // Initialize Ace editors for compose and env tabs only
        ['compose', 'env'].forEach(function(type) {
            var editor = ace.edit('editor-' + type);
            editor.setTheme(aceTheme);
            editor.setShowPrintMargin(false);
            editor.setOptions({
                fontSize: '1.1rem',
                tabSize: 2,
                useSoftTabs: true,
                wrap: true
            });

            // Set mode based on type
            if (type === 'env') {
                editor.getSession().setMode('ace/mode/sh');
            } else {
                editor.getSession().setMode('ace/mode/yaml');
            }

            // Track modifications
            editor.on('change', function() {
                var currentContent = editor.getValue();
                var originalContent = editorModal.originalContent[type] || '';
                var tabEl = $('#editor-tab-' + type);

                if (currentContent !== originalContent) {
                    editorModal.modifiedTabs.add(type);
                    tabEl.addClass('modified');
                } else {
                    editorModal.modifiedTabs.delete(type);
                    tabEl.removeClass('modified');
                }

                updateSaveButtonState();
                updateTabModifiedState();
                debounceValidation(type, currentContent);
            });

            editorModal.editors[type] = editor;
        });

        // Initialize settings field change tracking
        $('#settings-name, #settings-description, #settings-icon-url, #settings-webui-url, #settings-env-path, #settings-default-profile, #settings-external-compose-path').on('input', function() {
            var fieldId = this.id.replace('settings-', '');
            var currentValue = $(this).val();
            var originalValue = editorModal.originalSettings[fieldId] || '';

            if (currentValue !== originalValue) {
                editorModal.modifiedSettings.add(fieldId);
            } else {
                editorModal.modifiedSettings.delete(fieldId);
            }

            updateSaveButtonState();
            updateTabModifiedState();
        });

        // Icon preview update with debounce
        var settingsIconDebounce = null;
        $('#settings-icon-url').on('input', function() {
            var $input = $(this);
            clearTimeout(settingsIconDebounce);
            settingsIconDebounce = setTimeout(function() {
                var url = $input.val().trim();
                if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
                    $('#settings-icon-preview-img').attr('src', url);
                    $('#settings-icon-preview').show();
                } else {
                    $('#settings-icon-preview').hide();
                }
            }, 300);
        });

        // External compose path info toggle
        $('#settings-external-compose-path').on('input', function() {
            var path = $(this).val().trim();
            if (path) {
                $('#settings-external-compose-info').show();
            } else {
                $('#settings-external-compose-info').hide();
            }
        });

        // Keyboard shortcuts - use namespaced event to avoid duplicates
        $(document).off('keydown.editorModal').on('keydown.editorModal', function(e) {
            if ($('#editor-modal-overlay').hasClass('active')) {
                // Ctrl+S or Cmd+S to save current
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveCurrentTab();
                }
                // Escape to close
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeEditorModal();
                }
                // Arrow key navigation for tabs
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    var $activeTab = $('.editor-tab.active');
                    if ($activeTab.is(':focus') || $activeTab.parent().find(':focus').length) {
                        e.preventDefault();
                        var tabs = ['compose', 'env', 'labels', 'settings'];
                        var currentIdx = tabs.indexOf(editorModal.currentTab);
                        var newIdx;
                        if (e.key === 'ArrowLeft') {
                            newIdx = currentIdx > 0 ? currentIdx - 1 : tabs.length - 1;
                        } else {
                            newIdx = currentIdx < tabs.length - 1 ? currentIdx + 1 : 0;
                        }
                        switchTab(tabs[newIdx]);
                        $('#editor-tab-' + tabs[newIdx]).focus();
                    }
                }
                // Focus trapping
                if (e.key === 'Tab') {
                    var $modal = $('#editor-modal-overlay');
                    var $focusable = $modal.find('a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])').filter(':visible:not(:disabled)');
                    if ($focusable.length === 0) return;
                    var first = $focusable[0];
                    var last = $focusable[$focusable.length - 1];
                    var activeElement = document.activeElement;

                    if (!$.contains($modal[0], activeElement)) {
                        e.preventDefault();
                        first.focus();
                        return;
                    }

                    if (!e.shiftKey && activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    } else if (e.shiftKey && activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                }
            }
        });
    }

    // Switch between tabs (compose / env / labels / settings)
    function switchTab(tabName) {
        var validTabs = ['compose', 'env', 'labels', 'settings'];
        if (validTabs.indexOf(tabName) === -1) {
            console.error('Invalid tab name: ' + tabName);
            return;
        }

        // Update tab buttons
        $('.editor-tab').removeClass('active').attr('aria-selected', 'false');
        $('#editor-tab-' + tabName).addClass('active').attr('aria-selected', 'true');

        // Update panels
        $('.editor-panel').removeClass('active');
        $('#editor-panel-' + tabName).addClass('active');

        editorModal.currentTab = tabName;

        // Resize and focus editor if switching to compose or env tab
        if ((tabName === 'compose' || tabName === 'env') && editorModal.editors[tabName]) {
            editorModal.editors[tabName].resize();
            editorModal.editors[tabName].focus();
        }

        // Load labels data if switching to labels tab for the first time
        if (tabName === 'labels' && !editorModal.labelsData) {
            loadLabelsData();
        }
    }

    // Update the modified indicator on tabs
    function updateTabModifiedState() {
        // Compose tab
        if (editorModal.modifiedTabs.has('compose')) {
            $('#editor-tab-compose').addClass('modified');
        } else {
            $('#editor-tab-compose').removeClass('modified');
        }

        // Env tab
        if (editorModal.modifiedTabs.has('env')) {
            $('#editor-tab-env').addClass('modified');
        } else {
            $('#editor-tab-env').removeClass('modified');
        }

        // Labels tab
        if (editorModal.modifiedLabels.size > 0) {
            $('#editor-tab-labels').addClass('modified');
        } else {
            $('#editor-tab-labels').removeClass('modified');
        }

        // Settings tab
        if (editorModal.modifiedSettings.size > 0) {
            $('#editor-tab-settings').addClass('modified');
        } else {
            $('#editor-tab-settings').removeClass('modified');
        }
    }

    // HTML escape function to prevent XSS
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Escape for HTML attributes (more strict)
    function escapeAttr(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Update status cache per stack
    var stackUpdateStatus = {};

    // Load saved update status from server (called on page load)
    // If auto-check is enabled and interval has elapsed, trigger a fresh check
    // Also checks for pending rechecks from recent update operations
    function loadSavedUpdateStatus() {
        $.post(caURL, {
            action: 'getSavedUpdateStatus'
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.stacks) {
                        stackUpdateStatus = response.stacks;

                        // Update the UI for each stack with saved status
                        for (var stackName in response.stacks) {
                            var stackInfo = response.stacks[stackName];
                            updateStackUpdateUI(stackName, stackInfo);
                        }

                        // Enable/disable Update All button based on saved status
                        updateUpdateAllButton();

                        // Check for pending rechecks first (from recent updates)
                        // This takes priority over auto-check interval
                        checkPendingRechecks(function(hadPendingRechecks) {
                            // Only run auto-check if there were no pending rechecks
                            if (!hadPendingRechecks && autoCheckUpdates) {
                                checkAutoUpdateIfNeeded(response.stacks);
                            }
                        });
                    } else {
                        // No saved status, check for pending rechecks or run auto-check
                        checkPendingRechecks(function(hadPendingRechecks) {
                            if (!hadPendingRechecks && autoCheckUpdates) {
                                checkAllUpdates();
                            }
                        });
                    }
                } catch (e) {
                    console.error('Failed to load saved update status:', e);
                    checkPendingRechecks(function(hadPendingRechecks) {
                        if (!hadPendingRechecks && autoCheckUpdates) {
                            checkAllUpdates();
                        }
                    });
                }
            } else {
                // No data, check for pending rechecks or run auto-check
                checkPendingRechecks(function(hadPendingRechecks) {
                    if (!hadPendingRechecks && autoCheckUpdates) {
                        checkAllUpdates();
                    }
                });
            }
        });
    }

    // Check for pending rechecks from recent update operations
    // These stacks need to be rechecked regardless of auto-check interval
    function checkPendingRechecks(callback) {
        $.post(caURL, {
            action: 'getPendingRecheckStacks'
        }, function(data) {
            var hadPendingRechecks = false;
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.pending) {
                        var pendingStacks = Object.keys(response.pending);
                        if (pendingStacks.length > 0) {
                            hadPendingRechecks = true;
                            composeClientDebug('checkPendingRechecks:found', {
                                pendingStacks: pendingStacks
                            }, 'daemon', 'info');

                            // Check each pending stack
                            pendingStacks.forEach(function(stackName) {
                                composeClientDebug('Running recheck for recently updated stack:', {
                                    stackName: stackName
                                }, 'daemon', 'info');
                                checkStackUpdates(stackName);
                            });
                        }
                    }
                } catch (e) {
                    composeClientDebug('checkPendingRechecks:failed', {
                        error: e
                    }, 'daemon', 'error');
                }
            }
            if (callback) callback(hadPendingRechecks);
        });
    }

    // Check if auto-update check is needed based on lastChecked timestamp
    function checkAutoUpdateIfNeeded(stacks) {
        if (!autoCheckUpdates) return;

        var now = Math.floor(Date.now() / 1000); // Current time in seconds
        var intervalSeconds = autoCheckDays * 24 * 60 * 60; // Convert days to seconds
        var needsCheck = true;

        // Find the most recent lastChecked timestamp across all stacks
        var latestCheck = 0;
        for (var stackName in stacks) {
            if (stacks[stackName].lastChecked && stacks[stackName].lastChecked > latestCheck) {
                latestCheck = stacks[stackName].lastChecked;
            }
        }

        // If we have a lastChecked time and it's within the interval, don't check
        if (latestCheck > 0 && (now - latestCheck) < intervalSeconds) {
            needsCheck = false;
            composeClientDebug('[Updates] Last check was ' + Math.round((now - latestCheck) / 60) + ' minutes ago, interval is ' + Math.round(intervalSeconds / 60) + ' minutes. Skipping.', null, 'daemon', 'info');
        }

        if (needsCheck) {
            composeClientDebug('[Updates] Running automatic update check...', null, 'daemon', 'info');
            checkAllUpdates();
        }
    }

    // Check for updates for all stacks
    function checkAllUpdates() {
        $('#checkUpdatesBtn').prop('disabled', true).val('Checking...');
        $('#updateAllBtn').prop('disabled', true);

        // Show checking indicator only on running stack update columns (not stopped ones)
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var isRunning = $row.find('.state').text().indexOf('started') !== -1 ||
                $row.find('.state').text().indexOf('partial') !== -1;
            if (isRunning) {
                var $updateCell = $row.find('.compose-updatecolumn');
                $updateCell.html('<span style="color:#267CA8"><i class="fa fa-refresh fa-spin"></i> checking...</span>');
            }
        });

        $.post(caURL, {
            action: 'checkAllStacksUpdates'
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success' && response.stacks) {
                        stackUpdateStatus = response.stacks;

                        // Update the UI for each stack
                        for (var stackName in response.stacks) {
                            var stackInfo = response.stacks[stackName];
                            updateStackUpdateUI(stackName, stackInfo);
                        }

                        // Enable/disable Update All button based on available updates
                        updateUpdateAllButton();
                    }
                } catch (e) {
                    console.error('Failed to parse update check response:', e);
                }
            }
            $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
        }).fail(function() {
            $('#checkUpdatesBtn').prop('disabled', false).val('Check for Updates');
            $('#updateAllBtn').prop('disabled', true);
            // Reset update columns to not checked state - scope to compose_stacks
            $('#compose_stacks .compose-updatecolumn').each(function() {
                var $cell = $(this);
                if (!$cell.find('.grey-text').length || $cell.find('.fa-docker').length === 0) {
                    // Only reset running stacks (not the "stopped" ones)
                    $cell.html('<span class="grey-text" style="white-space:nowrap;cursor:default;"><i class="fa fa-exclamation-circle fa-fw"></i> check failed</span>');
                }
            });
        });
    }

    // Check how many stacks have updates and enable/disable the Update All button
    function updateUpdateAllButton() {
        var stacksWithUpdates = 0;
        for (var stackName in stackUpdateStatus) {
            var stackInfo = stackUpdateStatus[stackName];
            if (stackInfo.hasUpdate && stackInfo.isRunning) {
                stacksWithUpdates++;
            }
        }
        $('#updateAllBtn').prop('disabled', stacksWithUpdates === 0);
    }

    // Update All Stacks - updates all stacks that have pending updates
    function updateAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks with updates
        for (var stackName in stackUpdateStatus) {
            var stackInfo = stackUpdateStatus[stackName];
            if (stackInfo.hasUpdate && stackInfo.isRunning) {
                var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
                if ($stackRow.length === 0) continue;

                var autostart = $stackRow.find('.auto_start').is(':checked');

                // Skip if autostart only mode and autostart is not enabled
                if (autostartOnly && !autostart) continue;

                var path = $stackRow.data('path');
                var projectName = $stackRow.data('projectname');

                stacks.push({
                    project: stackName,
                    projectName: projectName,
                    path: path
                });
            }
        }

        if (stacks.length === 0) {
            swal({
                title: 'No Updates Available',
                text: autostartOnly ? 'No stacks with Autostart enabled have updates available.' : 'No stacks have updates available.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return escapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Update Autostart Stacks?' : 'Update All Stacks?';
        var confirmText = 'Yes, update ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        swal({
            title: title,
            html: true,
            text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be updated:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p style="color:#f80;"><i class="fa fa-warning"></i> This will pull new images and recreate containers.</p></div>',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                executeUpdateAllStacks(stacks);
            }
        });
    }

    function executeUpdateAllStacks(stacks) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to update
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Track all stacks for update check when dialog closes
        var stackNames = [];
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingUpdateCheckStacks.indexOf(stackName) === -1) {
                pendingUpdateCheckStacks.push(stackName);
            }
            stackNames.push(stackName);
        });

        // Mark stacks for recheck server-side (persists across page reload)
        $.post(caURL, {
            action: 'markStackForRecheck',
            stacks: JSON.stringify(stackNames)
        }, function() {
            $.post(compURL, {
                action: 'composeUpdateMultiple',
                paths: JSON.stringify(paths)
            }, function(data) {
                if (data) {
                    openBox(data, 'Update All Stacks', height, width, true);
                }
            });
        });
    }

    // Update UI for a single stack's update status
    function updateStackUpdateUI(stackName, stackInfo) {
        // Find the stack row by project name (scoped to compose_stacks to avoid Docker tab conflicts)
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;

        var stackId = $stackRow.attr('id').replace('stack-row-', '');
        var $updateCell = $stackRow.find('.compose-updatecolumn');

        // Check if the stack is running - use server response or DOM state
        var isRunning = stackInfo.isRunning;
        if (isRunning === undefined) {
            // Fallback to DOM state check
            var stateText = $stackRow.find('.state').text();
            isRunning = stateText.indexOf('started') !== -1 || stateText.indexOf('partial') !== -1;
        }

        // If the stack is stopped and we have no previously-checked update
        // data, show "stopped".  But if a prior update check produced valid
        // container info (images are still on disk), display it — the SHA
        // comparison is still accurate even when the stack isn't running.
        var hasCheckedData = stackInfo.containers && stackInfo.containers.length > 0 &&
            stackInfo.containers.some(function(ct) {
                return ct.hasUpdate !== undefined || ct.localSha || ct.updateStatus;
            });

        if (!isRunning && !hasCheckedData) {
            // Stack is not running and no prior update info - show stopped
            $updateCell.html('<span class="grey-text" style="white-space:nowrap;"><i class="fa fa-stop fa-fw"></i> stopped</span>');
            return;
        }

        // Count updates and pinned containers
        var updateCount = 0;
        var pinnedCount = 0;
        var totalContainers = stackInfo.containers ? stackInfo.containers.length : 0;

        if (stackInfo.containers) {
            stackInfo.containers.forEach(function(ct) {
                if (ct.hasUpdate) updateCount++;
                if (ct.isPinned) pinnedCount++;
            });
        }

        // Update the stack row's update column (match Docker tab style)
        if (updateCount > 0) {
            // Updates available - orange "update ready" style with clickable link and SHA info
            var updateHtml = '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');">';
            updateHtml += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> ' + updateCount + ' update' + (updateCount > 1 ? 's' : '') + '</span>';
            updateHtml += '</a>';

            // Show first container's SHA diff if only one update, or indicate multiple
            if (stackInfo.containers) {
                var updatesWithSha = stackInfo.containers.filter(function(ct) {
                    return ct.hasUpdate && ct.localSha && ct.remoteSha;
                });
                if (updatesWithSha.length === 1) {
                    // Single update - show the SHA diff inline
                    var ct = updatesWithSha[0];
                    updateHtml += '<div style="font-family:monospace;font-size:0.8em;margin-top:2px;">';
                    updateHtml += '<span style="color:#f80;" title="' + escapeAttr(ct.localSha) + '">' + escapeHtml(ct.localSha.substring(0, 8)) + '</span>';
                    updateHtml += ' <i class="fa fa-arrow-right" style="margin:0 2px;color:#3c3;font-size:0.9em;"></i> ';
                    updateHtml += '<span style="color:#3c3;" title="' + escapeAttr(ct.remoteSha) + '">' + escapeHtml(ct.remoteSha.substring(0, 8)) + '</span>';
                    updateHtml += '</div>';
                } else if (updatesWithSha.length > 1) {
                    // Multiple updates - show expand hint
                    updateHtml += '<div class="cm-advanced" style="font-size:0.8em;color:#999;margin-top:2px;">Expand for details</div>';
                }
            }

            // Also show pinned count if any containers are pinned
            if (pinnedCount > 0) {
                updateHtml += '<div style="font-size:0.8em;color:#17a2b8;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
            }
            $updateCell.html(updateHtml);
        } else if (totalContainers > 0) {
            // No updates - check if all are pinned or up-to-date
            if (pinnedCount > 0 && pinnedCount === totalContainers) {
                // All containers are pinned
                var html = '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> all pinned</span>';
                $updateCell.html(html);
            } else if (pinnedCount > 0) {
                // Some containers pinned, rest up-to-date
                var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                html += '<div style="font-size:0.8em;color:#17a2b8;margin-top:2px;"><i class="fa fa-thumb-tack fa-fw"></i> ' + pinnedCount + ' pinned</div>';
                html += '<div class="cm-advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
                $updateCell.html(html);
            } else {
                // No updates, no pinned - green "up-to-date" style (like Docker tab)
                // Basic view: just shows up-to-date
                // Advanced view: shows force update link
                var html = '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                html += '<div class="cm-advanced"><a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><span style="white-space:nowrap;"><i class="fa fa-cloud-download fa-fw"></i> force update</span></a></div>';
                $updateCell.html(html);
            }
        } else {
            // No containers found - show pull updates as clickable (for stacks that aren't running)
            $updateCell.html('<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(stackName) + '\', \'' + escapeAttr(stackId) + '\');"><i class="fa fa-cloud-download fa-fw"></i> pull updates</a>');
        }

        // Apply current view mode — cm-advanced elements are controlled by
        // the .cm-advanced-view class on #compose_stacks (CSS-only, no need to
        // show/hide individual elements here since CSS handles visibility).

        // Rebuild context menus to reflect update status (only target icon spans with data-stackid, not the row)
        $('[id^="stack-"][data-stackid][data-project="' + stackName + '"]').each(function() {
            addComposeStackContext(this.id);
        });

        // Also update the cached container data with update status and SHA
        if (stackContainersCache[stackId] && stackInfo.containers) {
            stackContainersCache[stackId].forEach(function(cached) {
                stackInfo.containers.forEach(function(updated) {
                    if (cached.name === updated.name) {
                        cached.hasUpdate = updated.hasUpdate;
                        cached.updateStatus = updated.updateStatus;
                        cached.localSha = updated.localSha || '';
                        cached.remoteSha = updated.remoteSha || '';
                        cached.isPinned = updated.isPinned || false;
                        cached.pinnedDigest = updated.pinnedDigest || '';
                    }
                });
            });
        }

        // If details are expanded, refresh them. However, avoid immediate
        // refresh if a load is already in progress or we've just rendered to
        // prevent a render->update->render loop.
        if (expandedStacks[stackId]) {
            if (stackDetailsLoading[stackId] || stackDetailsJustRendered[stackId]) {
                composeClientDebug('[updateStackUpdateUI] skip-refresh', {
                    stackId: stackId,
                    stackName: stackName,
                    loading: !!stackDetailsLoading[stackId],
                    justRendered: !!stackDetailsJustRendered[stackId]
                }, 'daemon', 'info');
            } else {
                loadStackContainerDetails(stackId, stackName);
            }
        }
    }

    // Check updates for a single stack
    function checkStackUpdates(stackName) {
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;

        var $updateCell = $stackRow.find('.compose-updatecolumn');
        $updateCell.html('<span style="color:#267CA8"><i class="fa fa-refresh fa-spin"></i> checking...</span>');

        $.post(caURL, {
            action: 'checkStackUpdates',
            script: stackName
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success') {
                        var stackInfo = createStackInfo(stackName, response.updates, {
                            projectName: response.projectName,
                            isRunning: true
                        });
                        stackUpdateStatus[stackName] = stackInfo;
                        updateStackUpdateUI(stackName, stackInfo);
                        // Update the Update All button state
                        updateUpdateAllButton();

                        // Clear the pending recheck flag for this stack (if any)
                        $.post(caURL, {
                            action: 'clearStackRecheck',
                            stacks: JSON.stringify([stackName])
                        });
                    }
                } catch (e) {
                    console.error('Failed to parse update check response:', e);
                }
            }
        });
    }

    // Validate URL scheme for WebUI links
    function isValidWebUIUrl(url) {
        if (!url) return false;
        var lowerUrl = url.toLowerCase().trim();
        return lowerUrl.startsWith('http://') || lowerUrl.startsWith('https://');
    }

    // Process WebUI URL placeholders for stack-level WebUI (where no container context exists)
    // For container-level WebUI, resolution is done server-side in exec.php
    function processWebUIUrl(url) {
        if (!url) return url;
        // Replace [IP] with the server hostname/IP (stack-level only)
        url = url.replace(/\[IP\]/gi, window.location.hostname);
        // Replace [PORT:xxxx] with the specified port as-is (no container port mapping at stack level)
        url = url.replace(/\[PORT:(\d+)\]/gi, '$1');
        return url;
    }

    // Apply advanced/basic view based on cookie (used after async load)
    // Scoped to compose_stacks to avoid affecting Docker tab when tabs are joined.
    // When animate=true (user clicked toggle), run a phased transition.
    // When false (page load), instant class toggle.
    // Uses compose-specific 'cm-advanced' / 'cm-advanced-view' classes
    // so Docker tab's own '.advanced' toggle cannot interfere.
    function applyListView(animate) {
        var advanced = $.cookie('compose_listview_mode') === 'advanced';
        var $table = $('#compose_stacks');

        if (animate) {
            var $changing = $table.find('.cm-advanced');

            if (advanced) {
                // Showing advanced columns: make visible at opacity 0, then fade in
                var startHeight = $table.outerHeight();
                $changing.css('opacity', 0);
                $table.addClass('cm-advanced-view');
                var endHeight = $table.outerHeight();

                $table.css({
                        height: startHeight,
                        overflow: 'hidden'
                    })
                    .animate({
                        height: endHeight
                    }, 400);
                $changing.animate({
                    opacity: 1
                }, 400).promise().done(function() {
                    $table.css({
                        height: '',
                        overflow: ''
                    });
                    $changing.css('opacity', '');
                });
            } else {
                // Hiding advanced columns: fade out, then remove class
                var startHeight = $table.outerHeight();
                $changing.animate({
                    opacity: 0
                }, 300).promise().done(function() {
                    $table.removeClass('cm-advanced-view');
                    var endHeight = $table.outerHeight();

                    $table.css({
                            height: startHeight,
                            overflow: 'hidden'
                        })
                        .animate({
                            height: endHeight
                        }, 400, function() {
                            $table.css({
                                height: '',
                                overflow: ''
                            });
                            $changing.css('opacity', '');
                        });
                });
            }
        } else {
            if (advanced) {
                $table.addClass('cm-advanced-view');
            } else {
                $table.removeClass('cm-advanced-view');
            }
        }
        // Apply readmore to descriptions — exclude container detail rows to avoid double-application
        $('#compose_stacks .docker_readmore').not('.stack-details-container .docker_readmore').readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });
    }

    $(function() {
        $(".tipsterallowed").show();
        $('.ca_nameEdit').tooltipster({
            trigger: 'custom',
            triggerOpen: {
                click: true,
                touchstart: true,
                mouseenter: true
            },
            triggerClose: {
                click: true,
                scroll: false,
                mouseleave: true
            },
            delay: 1000,
            contentAsHTML: true,
            animation: 'grow',
            interactive: true,
            viewportAware: true,
            functionBefore: function(instance, helper) {
                var origin = $(helper.origin);
                var myID = origin.attr('id');
                var name = $("#" + myID).html();
                var disabled = $("#" + myID).attr('data-isup') == "1" ? "disabled" : "";
                var notdisabled = $("#" + myID).attr('data-isup') == "1" ? "" : "disabled";
                var stackName = $("#" + myID).attr("data-scriptname");
                instance.content(escapeHtml(stackName) + "<br> \
                                    <center> \
                                    <input type='button' onclick='editName(&quot;" + myID + "&quot;);' value='Edit Name' " + disabled + "> \
                                    <input type='button' onclick='editDesc(&quot;" + myID + "&quot;);' value='Edit Description' > \
                                    <input type='button' onclick='editStack(&quot;" + myID + "&quot;);' value='Edit Stack'> \
                                    <input type='button' onclick='deleteStack(&quot;" + myID + "&quot;);' value='Delete Stack' " + disabled + "> \
                                    <input type='button' onclick='ComposeLogs(&quot;" + myID + "&quot;);' value='Logs' " + notdisabled + "> \
                                    </center>");
            }
        });

        // Add Advanced View toggle (like Docker tab)
        // Use compose-specific class to avoid conflict with Docker tab's advancedview when tabs are joined
        // Check if .tabs exists (joined under Docker tab) or create standalone toggle (own tab under Tasks)
        var toggleHtml = '<span class="status compose-view-toggle"><span><input type="checkbox" class="compose-advancedview"></span></span>';
        if ($(".tabs").length) {
            $(".tabs").append(toggleHtml);
        } else {
            // Standalone page (xmenu under Tasks) - add toggle before the container area
            // Style it to float right above the table for consistent positioning
            var standaloneToggle = $('<div class="ToggleViewMode"></div>').html(toggleHtml);
            var $tableWrapper = $('#compose_stacks').closest('.TableContainer');
            if ($tableWrapper.length) {
                $tableWrapper.before(standaloneToggle);
            } else {
                $('#compose_stacks').before(standaloneToggle);
            }
        }
        // Inject Compose CLI version next to the page title
        if (composeCliVersion) {
            $('div.content').children('div.title').each(function() {
                var txt = $(this).text().trim();
                if (/Compose/i.test(txt) && !/Docker\s*Containers/i.test(txt)) {
                    $(this).append(' <span style="font-size:0.75em;color:#606060;font-weight:normal;vertical-align:middle;">v' + escapeHtml(composeCliVersion) + '</span>');
                }
            });
        }

        // Initialize the Advanced/Basic view toggle.
        // labels_placement:'left' puts both labels to the left of the slider.
        // The plugin shows only the active label: "Basic View" (white) when
        // unchecked, "Advanced View" (blue / class 'on') when checked.
        var isAdvanced = $.cookie('compose_listview_mode') === 'advanced';
        $('.compose-advancedview').switchButton({
            labels_placement: 'left',
            on_label: 'Advanced View',
            off_label: 'Basic View',
            checked: isAdvanced
        });
        // Apply the current cookie state immediately so columns match the toggle.
        applyListView();
        $('.compose-advancedview').change(function() {
            // Persist selection and apply view consistently via applyListView()
            $.cookie('compose_listview_mode', $('.compose-advancedview').is(':checked') ? 'advanced' : 'basic', {
                expires: 3650
            });
            applyListView(true);
        });

        // Set up MutationObserver to detect when ebox (progress dialog) closes
        // This is used to trigger update check after an update operation completes
        var eboxObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.removedNodes.length > 0) {
                    mutation.removedNodes.forEach(function(node) {
                        // Check if the removed node is the ebox or contains it
                        if (node.id === 'ebox' || (node.querySelector && node.querySelector('#ebox'))) {
                            // If there are stacks queued for update checks, process them
                            if (pendingUpdateCheckStacks.length > 0) {
                                // Copy and clear the list before processing
                                var stacksToCheck = pendingUpdateCheckStacks.slice();
                                pendingUpdateCheckStacks = [];

                                // Delay slightly to let page state settle
                                setTimeout(function() {
                                    composeClientDebug('Update completed, running check for updates on stacks:', {
                                        stacks: stacksToCheck
                                    }, 'daemon', 'debug');
                                    // Check each stack that was updated
                                    stacksToCheck.forEach(function(stackName) {
                                        checkStackUpdates(stackName);
                                    });
                                }, 1000);
                            }

                            // If there are stacks queued for compose list reload (start/stop), trigger a reload
                            if (pendingComposeReloadStacks.length > 0) {
                                // Immediately replace stale update-column text with a
                                // loading spinner so the user never sees outdated "stopped"
                                pendingComposeReloadStacks.forEach(function(project) {
                                    var $row = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
                                    if ($row.length) {
                                        $row.find('.compose-updatecolumn').html(
                                            '<span class="grey-text" style="white-space:nowrap;"><i class="fa fa-refresh fa-spin fa-fw"></i> loading…</span>'
                                        );
                                    }
                                });
                                // Tell the loadlist hook to skip composeLoadlist once
                                skipNextComposeLoadlist = true;
                                // Schedule a debounced processor to handle pending compose reloads
                                composeClientDebug('[eboxObserver] pending-compose-reloads', {
                                    pending: pendingComposeReloadStacks.slice()
                                }, 'daemon', 'debug');
                                schedulePendingComposeReloads(800);
                            }

                            // Sync Unraid's Docker containers widget after compose actions
                            // (compose up/down/update changes containers that Docker's view needs to reflect)
                            if (typeof window.loadlist === 'function') {
                                setTimeout(function() {
                                    composeClientDebug('[eboxObserver] calling-loadlist-for-docker-sync', {
                                        pendingComposeReloadStacks: pendingComposeReloadStacks.slice()
                                    }, 'daemon', 'debug');
                                    window.loadlist();
                                }, 1500);
                            }
                        }
                    });
                }
            });
        });

        // Start observing the body for changes (ebox gets added/removed from body)
        eboxObserver.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Load the stack list asynchronously (like Docker tab)
        // This defers the expensive docker commands to after the page renders
        composeLoadlist().then(function() {
            getConfig().then(function(config) {
                if (config['STACKS_DEFAULT_EXPANDED'] == 'true') {
                    // Expand all stacks if the default is set to expanded
                    $('#compose_stacks tr.compose-sortable').each(function() {
                        if ($(this).data('isup') != "1" && config['ONLY_EXPAND_RUNNING_STACKS'] == 'true') {
                            return; // Skip stopped stacks if ONLY_EXPAND_RUNNING_STACKS is true
                        }
                        var stackId = $(this).attr('id').replace('stack-row-', '');
                        toggleStackDetails(stackId);
                    });
                }
            });
        });
        // ── Cross-widget sync ──────────────────────────────────────────
        // On the Docker page the Compose stacks list is rendered below
        // the built-in Docker containers table (non-tabbed) or in a
        // separate tab (tabbed mode).  When Docker's loadlist() fires
        // (container start/stop/restart/etc.), the compose list must
        // also refresh so state stays in sync.
        (function hookLoadlist() {
            var composeRefreshTimer = null;
            var composeDataStale = false;

            // For tabbed mode: detect if our panel is hidden so we can
            // defer the refresh until the user switches to the compose tab.
            var composeTable = document.getElementById('compose_stacks');
            var tabPanel = composeTable ? composeTable.closest('[role="tabpanel"]') : null;
            var isTabbed = tabPanel !== null;

            function isComposePanelVisible() {
                if (!isTabbed) return true; // non-tabbed: always visible
                return tabPanel.style.display !== 'none';
            }

            function wrapLoadlist() {
                if (typeof window.loadlist === 'function' && !window.loadlist._composeTabHooked) {
                    var originalLoadlist = window.loadlist;
                    window.loadlist = function() {
                        originalLoadlist.apply(this, arguments);

                        // Skip compose reload if refreshStackRow is already handling it
                        if (pendingComposeRefreshCount > 0 || skipNextComposeLoadlist) {
                            composeClientDebug('[hookLoadlist]  suppressed composeLoadlist (pending=' + pendingComposeRefreshCount + ', skip=' + skipNextComposeLoadlist + ')', null, 'daemon', 'debug');
                            skipNextComposeLoadlist = false;
                            return;
                        }

                        if (isComposePanelVisible()) {
                            // Visible — refresh with debounce
                            clearTimeout(composeRefreshTimer);
                            composeRefreshTimer = setTimeout(function() {
                                composeLoadlist();
                            }, 2000);
                        } else {
                            // Hidden tab — mark stale, refresh on tab switch
                            composeDataStale = true;
                        }
                    };
                    window.loadlist._composeTabHooked = true;
                    composeClientDebug('[hookLoadlist]  hooked loadlist() for cross-widget sync, tabbed=' + isTabbed, null, 'daemon', 'info');
                    return true;
                }
                return false;
            }

            // loadlist may not exist yet — retry every 500ms
            if (!wrapLoadlist()) {
                var hookInterval = setInterval(function() {
                    if (wrapLoadlist()) clearInterval(hookInterval);
                }, 500);
                // Give up after 30s
                setTimeout(function() {
                    clearInterval(hookInterval);
                }, 30000);
            }

            // Tabbed mode: watch for tab switches to flush stale data
            if (isTabbed) {
                var panelObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'style' && isComposePanelVisible() && composeDataStale) {
                            composeDataStale = false;
                            composeLoadlist();
                        }
                    });
                });
                panelObserver.observe(tabPanel, {
                    attributes: true,
                    attributeFilter: ['style']
                });
            }
        })();
    });

    function addStack() {
        // Show custom modal for stack creation
        var modalHtml = `
            <div id="compose-stack-modal-overlay" class="compose-modal-overlay" style="display:flex;">
                <div class="compose-modal" role="dialog" aria-modal="true" aria-labelledby="compose-stack-modal-title" aria-describedby="compose-stack-modal-desc" tabindex="-1">
                    <div class="compose-modal-header">
                        <span id="compose-stack-modal-title">Add New Compose Stack</span>
                        <button type="button" class="editor-btn editor-btn-cancel" onclick="closeComposeStackModal()" aria-label="Close modal"><i class="fa fa-times"></i></button>
                    </div>
                    <div class="compose-modal-body">
                        <div style="font-weight:bold;margin-bottom:8px;">Stack Name</div>
                        <input type="text" id="compose-stack-name" placeholder="Stack Name" autofocus>
                        <div id="compose-stack-modal-desc" style="font-weight:bold;margin-bottom:8px;">Description (optional)</div>
                        <input type="text" id="compose-stack-desc" placeholder="Description">
                        <div id="compose-stack-modal-error" style="color:#f44336;margin-bottom:8px;display:none;"></div>
                    
                        <details>
                            <summary>Advanced Options</summary></br>
                            <div style="font-weight:bold;margin-bottom:8px;">Indirect Path</div>
                            <input type="text" id="compose-stack-indirect" placeholder="/mnt/user/compose/stackFolder">
                        </details>
                    
                    </div>
                    <div class="compose-modal-footer">
                        <button class="editor-btn editor-btn-cancel" onclick="closeComposeStackModal()">Cancel</button>
                        <button class="editor-btn editor-btn-save-all" onclick="submitComposeStackModal()">Create</button>
                    </div>
                </div>
            </div>
        `;
        // Remove any existing modal
        var existingOverlay = document.getElementById('compose-stack-modal-overlay');
        if (existingOverlay) {
            existingOverlay.remove();
        }
        // Insert modal into body
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = modalHtml;
        document.body.appendChild(tempDiv.firstElementChild);

        window.closeComposeStackModal = function() {
            var overlay = document.getElementById('compose-stack-modal-overlay');
            if (overlay) {
                overlay.remove();
            }
        };

        window.submitComposeStackModal = function() {
            var name = document.getElementById('compose-stack-name').value.trim();
            var desc = document.getElementById('compose-stack-desc').value.trim();
            var indirect = document.getElementById('compose-stack-indirect').value.trim();
            var errorDiv = document.getElementById('compose-stack-modal-error');
            if (!name) {
                errorDiv.textContent = "Please enter a stack name.";
                errorDiv.style.display = "block";
                return;
            }
            errorDiv.style.display = "none";
            // Disable all buttons in the modal
            var modal = document.getElementById('compose-stack-modal-overlay');
            if (modal) {
                var btns = modal.querySelectorAll('button');
                btns.forEach(function(btn) {
                    btn.disabled = true;
                });
            }
            $.post(
                caURL, {
                    action: 'addStack',
                    stackName: name,
                    stackDesc: desc,
                    stackPath: indirect
                },
                function(data) {
                    window.closeComposeStackModal();
                    if (data) {
                        var response;
                        try {
                            response = JSON.parse(data);
                        } catch (e) {
                            // Handle invalid or unexpected JSON response
                            if (window.console && console.error) {
                                console.error("Failed to parse addStack response:", e, data);
                            }
                            swal({
                                title: "Failed to create stack",
                                text: "Unexpected response from server",
                                type: "error"
                            });
                            return;
                        }
                        if (response.result == "success") {
                            openEditorModalByProject(response.project, response.projectName);
                            composeLoadlist();
                        } else {
                            swal({
                                title: "Failed to create stack",
                                text: response.message || "An error occurred",
                                type: "error"
                            });
                        }
                    } else {
                        swal({
                            title: "Failed to create stack",
                            text: "No response from server",
                            type: "error"
                        });
                    }
                }
            ).fail(function() {
                window.closeComposeStackModal();
                swal({
                    title: "Failed to create stack",
                    text: "Request failed",
                    type: "error"
                });
            });
        };
    }



    function stripTags(string) {
        return string.replace(/(<([^>]+)>)/ig, "");
    }

    function editName(myID) {
        var currentName = $("#" + myID).attr("data-namename");
        $("#" + myID).attr("data-originalName", currentName);
        var $el = $("#" + myID);
        $el.empty();
        var $input = $("<input type='text'>").attr('id', 'newName' + myID).val(currentName);
        var $cancel = $("<i class='fa fa-times' aria-hidden='true' style='cursor:pointer;color:red;font-size:1.2em'></i>").on('click', function() {
            cancelName(myID);
        });
        var $apply = $("<i class='fa fa-check' aria-hidden='true' style='cursor:pointer;color:green;font-size:1.2em'></i>").on('click', function() {
            applyName(myID);
        });
        $el.append($input).append($("<br>")).append($cancel).append("&nbsp;&nbsp;").append($apply);
        $el.tooltipster("close");
        $el.tooltipster("disable");
    }

    function editDesc(myID) {
        var origID = myID;
        $("#" + myID).tooltipster("close");
        myID = myID.replace("name", "desc");
        var currentDesc = $("#" + myID).text();
        $("#" + myID).attr("data-originaldescription", currentDesc);
        var $el = $("#" + myID);
        $el.empty();
        var $textarea = $("<textarea cols='40' rows='5'></textarea>").attr('id', 'newDesc' + myID).val(currentDesc);
        var $cancel = $("<i class='fa fa-times' aria-hidden='true' style='cursor:pointer;color:red;font-size:1.2em'></i>").on('click', function() {
            cancelDesc(myID);
        });
        var $apply = $("<i class='fa fa-check' aria-hidden='true' style='cursor:pointer;color:green;font-size:1.2em'></i>").on('click', function() {
            applyDesc(myID);
        });
        $el.append($textarea).append($("<br>")).append($cancel).append("&nbsp;&nbsp;").append($apply);
        $("#" + origID).tooltipster("enable");
    }

    function applyName(myID) {
        var newName = $("#newName" + myID).val();
        var project = $("#" + myID).attr("data-scriptname");
        $("#" + myID).text(newName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
        $.post(caURL, {
            action: 'changeName',
            script: project,
            newName: newName
        }, function(data) {
            refreshStackByProject(project);
        });
    }

    function cancelName(myID) {
        var oldName = $("#" + myID).attr("data-originalName");
        $("#" + myID).text(oldName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
    }

    function cancelDesc(myID) {
        var oldName = $("#" + myID).attr("data-originaldescription");
        $("#" + myID).text(oldName);
        $("#" + myID).tooltipster("enable");
        $("#" + myID).tooltipster("close");
    }

    function applyDesc(myID) {
        var newDesc = $("#newDesc" + myID).val();
        var project = $("#" + myID).attr("data-scriptname");
        // Use .text() with CSS white-space to avoid .html() XSS risk
        $("#" + myID).text(newDesc).css('white-space', 'pre-line');
        $.post(caURL, {
            action: 'changeDesc',
            script: project,
            newDesc: newDesc
        });
    }

    // Opens editor modal directly using myID element (from tooltipster)
    function editStack(myID) {
        $("#" + myID).tooltipster("close");
        var project = $("#" + myID).attr("data-scriptname");
        var projectName = $("#" + myID).attr("data-namename");
        openEditorModalByProject(project, projectName);
    }

    function generateProfiles(myID, myProject = null) {
        var project = myProject;
        if (myID) {
            $("#" + myID).tooltipster("close");
            project = $("#" + myID).attr("data-scriptname");
        }

        $.post(caURL, {
            action: 'getYml',
            script: project
        }, function(rawComposefile) {
            var project_profiles = new Set();
            if (rawComposefile) {
                var rawComposefile = JSON.parse(rawComposefile);

                if ((rawComposefile.result == 'success')) {
                    var main_doc = jsyaml.load(rawComposefile.content);

                    for (var service_key in main_doc.services) {
                        var service = main_doc.services[service_key];
                        if (service.hasOwnProperty("profiles")) {
                            for (const profile of service.profiles) {
                                project_profiles.add(profile);
                            }
                        }
                    }

                    var rawProfiles = JSON.stringify(Array.from(project_profiles));
                    $.post(caURL, {
                        action: "saveProfiles",
                        script: project,
                        scriptContents: rawProfiles
                    }, function(data) {
                        if (!data) {
                            swal({
                                title: "Failed to update profiles.",
                                type: "error"
                            });
                            composeClientDebug('Failed to update profiles.', {
                                project: project,
                                rawProfiles: rawProfiles,
                                response: data
                            }, 'daemon', 'error');
                        }
                    });
                }
            }
        });
    }

    function editStackSettings(myID) {
        var project = $("#" + myID).attr("data-scriptname");

        $.post(caURL, {
            action: 'getEnvPath',
            script: project
        }, function(rawEnvPath) {
            if (rawEnvPath) {
                var rawEnvPath = JSON.parse(rawEnvPath);
                if (rawEnvPath.result == 'success') {
                    var formHtml = `<div class="swal-text" style="font-weight: bold; padding-left: 0px; margin-top: 0px;">ENV File Path</div>`;
                    formHtml += `<br>`;
                    formHtml += `<input type='text' id='env_path' class='swal-content__input' pattern="(\/mnt\/.*\/.+)" oninput="this.reportValidity()" title="A path under /mnt/user/ or /mnt/cache/ or /mnt/pool/" placeholder=Default value='${rawEnvPath.content}'>`;
                    swal({
                        title: "Stack Settings",
                        text: formHtml,
                        html: true,
                        showCancelButton: true,
                        confirmButtonText: "Save",
                        closeOnConfirm: false
                    }, function(confirmed) {
                        if (confirmed) {
                            var new_env_path = document.getElementById("env_path").value;
                            $.post(caURL, {
                                action: 'setEnvPath',
                                envPath: new_env_path,
                                script: project
                            }, function(data) {
                                var title = "Failed to set stack settings.";
                                var message = "";
                                var type = "error";
                                if (data) {
                                    var response = JSON.parse(data);
                                    if (response.result == "success") {
                                        title = "Success";
                                    }
                                    message = response.message;
                                    type = response.result;
                                }
                                swal({
                                    title: title,
                                    text: message,
                                    type: type
                                }, function() {
                                    refreshStackByProject(project);
                                });
                            });
                        }
                    });
                }
            }
        });
    }

    // Unified update warning dialog - called from stack row and container table
    function showUpdateWarning(project, stackId) {
        var path = compose_root + '/' + project;
        // Use the existing UpdateStack function which already has the warning dialog
        UpdateStack(path, "");
    }

    // Confirmed action handlers (no dialog, just execute)
    function ComposeUpConfirmed(path, profile = "") {
        var height = 800;
        var width = 1200;

        // Mark stack for local reload and show per-row spinner
        var stackNameForReload = basename(path);
        if (pendingComposeReloadStacks.indexOf(stackNameForReload) === -1) pendingComposeReloadStacks.push(stackNameForReload);
        setStackActionInProgress(stackNameForReload, true);

        $.post(compURL, {
            action: 'composeUp',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                openBox(data, "Stack " + basename(path) + " Up", height, width, true);
            }
        })
    }

    // Recreate containers without pulling (for label changes)
    function ComposeRecreateConfirmed(path, profile = "") {
        var height = 800;
        var width = 1200;

        $.post(compURL, {
            action: 'composeUpRecreate',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                openBox(data, "Recreate Stack " + basename(path), height, width, true);
            }
        })
    }

    function ComposeUp(path, profile = "") {
        showStackActionDialog('up', path, profile);
    }

    function ComposeDownConfirmed(path, profile = "") {
        var height = 800;
        var width = 1200;

        // Mark stack for local reload and show per-row spinner
        var stackNameForReloadDown = basename(path);
        if (pendingComposeReloadStacks.indexOf(stackNameForReloadDown) === -1) pendingComposeReloadStacks.push(stackNameForReloadDown);
        setStackActionInProgress(stackNameForReloadDown, true);

        $.post(compURL, {
            action: 'composeDown',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                openBox(data, "Stack " + basename(path) + " Down", height, width, true);
            }
        })
    }

    function ComposeDown(path, profile = "") {
        showStackActionDialog('down', path, profile);
    }

    // Prompt user to recreate containers after label changes
    function promptRecreateContainers() {
        var project = editorModal.currentProject;
        if (!project) {
            swal({
                title: "Saved!",
                text: "All changes have been saved.",
                type: "success",
                timer: 1500,
                showConfirmButton: false
            });
            return;
        }

        // Find the stack row and check if it's running
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        var isUp = $stackRow.length > 0 && $stackRow.data('isup') == "1";

        if (!isUp) {
            // Stack is not running, close editor and show saved message
            doCloseEditorModal();
            swal({
                title: "Saved!",
                text: "All changes have been saved. Container labels will take effect when you start the stack.",
                type: "success"
            }, function() {
                refreshStackByProject(project);
            });
            return;
        }

        // Stack is running, ask if user wants to recreate
        var path = compose_root + '/' + project;
        swal({
            title: "Recreate Containers?",
            text: '<div style="text-align:left;max-width:400px;margin:0 auto;">' +
                '<p>Container labels (icon, WebUI) have been saved.</p>' +
                '<p style="color:#f80;"><i class="fa fa-exclamation-triangle"></i> <strong>Containers must be recreated</strong> for these changes to take effect.</p>' +
                '<p style="font-size:0.9em;color:#999;">This will briefly restart the affected containers. Your data will be preserved.</p>' +
                '</div>',
            html: true,
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Recreate Now",
            cancelButtonText: "Later",
            closeOnConfirm: true
        }, function(confirmed) {
            doCloseEditorModal();
            if (confirmed) {
                // Use setTimeout to ensure swal is fully closed before opening ttyd dialog
                setTimeout(function() {
                    ComposeRecreateConfirmed(path, "");
                }, 300);
            } else {
                swal({
                    title: "Saved!",
                    text: "Changes saved. Remember to restart or recreate containers to apply label changes.",
                    type: "info",
                    timer: 2000,
                    showConfirmButton: false
                }, function() {
                    refreshStackByProject(project);
                });
            }
        });
    }

    // Track stacks that need update check after operation completes
    // Using array to support Update All Stacks operation
    var pendingUpdateCheckStacks = [];

    // >0 while refreshStackRow AJAX calls are in-flight; the loadlist
    // hook skips composeLoadlist until all pending refreshes complete.
    var pendingComposeRefreshCount = 0;

    // One-shot flag: consumed by the loadlist hook so the very next
    // loadlist call skips composeLoadlist even if refreshStackRow
    // already completed before loadlist fires.
    var skipNextComposeLoadlist = false;

    // Track stacks that need a full compose list reload after start/stop operations
    var pendingComposeReloadStacks = [];
    // Timer for batching compose reloads to avoid duplicate refreshes
    var pendingComposeReloadTimer = null;

    // Schedule processing of pending compose reloads (debounced)
    function schedulePendingComposeReloads(ms) {
        ms = ms || 500;
        if (pendingComposeReloadTimer) {
            clearTimeout(pendingComposeReloadTimer);
        }
        pendingComposeReloadTimer = setTimeout(function() {
            processPendingComposeReloads();
        }, ms);
    }

    // Process pending compose reloads: update parent rows from cache where possible,
    // otherwise fall back to a full composeLoadlist(). This centralizes reloads
    // so multiple triggers collapse into a single update.

    function processPendingComposeReloads() {
        if (pendingComposeReloadTimer) {
            clearTimeout(pendingComposeReloadTimer);
            pendingComposeReloadTimer = null;
        }
        if (!pendingComposeReloadStacks || pendingComposeReloadStacks.length === 0) return;
        var reloadStacks = pendingComposeReloadStacks.slice();
        // Clear queue immediately to avoid re-entrancy
        pendingComposeReloadStacks = [];
        composeClientDebug('processPendingComposeReloads', {
            stacks: reloadStacks.slice()
        }, 'daemon', 'info');

        // If any of the target rows are missing from DOM, fallback to full reload
        var anyMissing = reloadStacks.some(function(project) {
            return $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]').length === 0;
        });
        if (anyMissing) {
            // Give docker a moment to settle then reload whole list
            setTimeout(function() {
                composeLoadlist();
            }, 400);
            return;
        }

        // Fetch fresh container data from server, then update each parent row.
        // The cache is stale after compose up/down so we must re-fetch.
        reloadStacks.forEach(function(project) {
            try {
                var stackId = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]').attr('id').replace('stack-row-', '');
                refreshStackRow(stackId, project);
            } catch (e) {
                composeClientDebug('[processPendingComposeReloads] update-failed', {
                    project: project,
                    err: e.toString()
                }, 'daemon', 'error');
            }
        });
    }

    // Helper to refresh a single stack by project name (wrapper for refreshStackRow)
    function refreshStackByProject(project) {
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        if ($stackRow.length > 0) {
            var stackId = $stackRow.attr('id').replace('stack-row-', '');
            refreshStackRow(stackId, project);
        }
    }

    // Fetch fresh container data from server and update the parent stack row.
    // Unlike updateParentStackFromContainers() which uses stale cache, this
    // always makes an AJAX call to get current container states.
    function refreshStackRow(stackId, project) {
        pendingComposeRefreshCount++;
        $.post(caURL, {
            action: 'getStackContainers',
            script: project
        }, function(data) {
            if (data) {
                try {
                    var response = JSON.parse(data);
                    if (response.result === 'success') {
                        var containers = response.containers || [];
                        // Normalize all containers via factory function (PascalCase→camelCase)
                        containers = containers.map(function(c) {
                            var info = createContainerInfo(c);
                            // Preserve original keys for renderContainerDetails compatibility
                            return Object.assign({}, c, info);
                        });
                        // Merge saved update status so we don't lose checked info
                        mergeUpdateStatus(containers, project);
                        // Update cache with fresh data
                        stackContainersCache[stackId] = containers;
                        stackDefinedServicesCache[stackId] = response.definedServices || containers.length;
                        // Now update the row using the fresh cache
                        updateParentStackFromContainers(stackId, project);
                        // If details are expanded, refresh them too
                        if (expandedStacks[stackId]) {
                            renderContainerDetails(stackId, containers, project);
                        }
                    }
                } catch (e) {
                    composeClientDebug('[refreshStackRow] parse-error', {
                        project: project,
                        err: e.toString()
                    }, 'daemon', 'error');
                    // Fallback: update from whatever cache we have
                    updateParentStackFromContainers(stackId, project);
                }
            }
            pendingComposeRefreshCount = Math.max(0, pendingComposeRefreshCount - 1);
        }).fail(function() {
            // On network failure, fall back to cache-based update
            updateParentStackFromContainers(stackId, project);
            pendingComposeRefreshCount = Math.max(0, pendingComposeRefreshCount - 1);
        });
    }

    // Toggle per-stack action-in-progress UI (replace status icon with spinner)
    function setStackActionInProgress(stackName, inProgress, text) {
        composeClientDebug('setStackActionInProgress', {
            stack: stackName,
            inProgress: inProgress,
            text: text
        }, 'daemon', 'info');
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + stackName + '"]');
        if ($stackRow.length === 0) return;
        var $icon = $stackRow.find('.compose-status-icon');
        var $state = $stackRow.find('.state');
        if (inProgress) {
            // Save original icon classes so we can restore them later
            if (!$icon.data('orig-class')) {
                $icon.data('orig-class', $icon.attr('class'));
            }
            // Use same spinner as containers to keep UI consistent
            $icon.removeClass().addClass('fa fa-refresh fa-spin compose-status-spinner compose-status-icon');
            $state.data('orig-text', $state.text());
            if (text) {
                $state.text(text);
            } else {
                $state.text('checking...');
            }
        } else {
            // Restore original icon classes if we saved them
            if ($icon.data('orig-class')) {
                $icon.removeClass().addClass($icon.data('orig-class'));
                $icon.removeData('orig-class');
            }
            // Restore original state text if present
            if ($state.data('orig-text')) {
                $state.text($state.data('orig-text'));
                $state.removeData('orig-text');
            }
        }
    }

    function UpdateStackConfirmed(path, profile = "") {
        var height = 800;
        var width = 1200;

        // Track this stack for update check when dialog closes
        var stackName = basename(path);
        if (pendingUpdateCheckStacks.indexOf(stackName) === -1) {
            pendingUpdateCheckStacks.push(stackName);
        }

        // Mark stack for recheck server-side (persists across page reload)
        $.post(caURL, {
            action: 'markStackForRecheck',
            stacks: JSON.stringify([stackName])
        }, function() {
            $.post(compURL, {
                action: 'composeUpPullBuild',
                path: path,
                profile: profile
            }, function(data) {
                if (data) {
                    openBox(data, "Update Stack " + basename(path), height, width, true);
                }
            });
        });
    }

    function UpdateStack(path, profile = "") {
        showStackActionDialog('update', path, profile);
    }

    // Start All Stacks function
    function startAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks from the table
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var project = $row.data('project');
            var projectName = $row.data('projectname');
            var path = $row.data('path');
            var isUp = $row.data('isup');
            var autostart = $row.find('.auto_start').is(':checked');

            // Skip if autostart only mode and autostart is not enabled
            if (autostartOnly && !autostart) return;

            // Only include stopped stacks
            var $stateEl = $row.find('.state');
            var stateText = $stateEl.text();
            if (stateText === 'stopped' || !isUp) {
                stacks.push({
                    project: project,
                    projectName: projectName,
                    path: path
                });
            }
        });

        if (stacks.length === 0) {
            swal({
                title: 'No Stacks to Start',
                text: autostartOnly ? 'No stopped stacks with Autostart enabled found.' : 'No stopped stacks found.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return escapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Start Autostart Stacks?' : 'Start All Stacks?';
        var confirmText = autostartOnly ? 'Yes, start ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, start ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        swal({
            title: title,
            html: true,
            text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be started:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div></div>',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                executeStartAllStacks(stacks);
            }
        });
    }

    function executeStartAllStacks(stacks) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to start
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Mark stacks for local reload and show per-row spinners
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingComposeReloadStacks.indexOf(stackName) === -1) pendingComposeReloadStacks.push(stackName);
            composeClientDebug('[executeStartAllStacks] queued', {
                stack: stackName,
                pending: pendingComposeReloadStacks.slice()
            }, 'daemon', 'info');
            setStackActionInProgress(stackName, true);
        });

        $.post(compURL, {
            action: 'composeUpMultiple',
            paths: JSON.stringify(paths)
        }, function(data) {
            if (data) {
                openBox(data, 'Start All Stacks', height, width, true);
            }
        });
    }

    // Stop All Stacks function
    function stopAllStacks() {
        var autostartOnly = $('#autostartOnlyToggle').is(':checked');
        var stacks = [];

        // Collect all stacks from the table
        $('#compose_stacks tr.compose-sortable').each(function() {
            var $row = $(this);
            var project = $row.data('project');
            var projectName = $row.data('projectname');
            var path = $row.data('path');
            var isUp = $row.data('isup');
            var autostart = $row.find('.auto_start').is(':checked');

            // Skip if autostart only mode and autostart is not enabled
            if (autostartOnly && !autostart) return;

            // Only include running stacks
            var $stateEl = $row.find('.state');
            var stateText = $stateEl.text();
            if (stateText !== 'stopped' && isUp) {
                stacks.push({
                    project: project,
                    projectName: projectName,
                    path: path
                });
            }
        });

        if (stacks.length === 0) {
            swal({
                title: 'No Stacks to Stop',
                text: autostartOnly ? 'No running stacks with Autostart enabled found.' : 'No running stacks found.',
                type: 'info'
            });
            return;
        }

        var stackNames = stacks.map(function(s) {
            return escapeHtml(s.projectName);
        }).join('<br>');
        var title = autostartOnly ? 'Stop Autostart Stacks?' : 'Stop All Stacks?';
        var confirmText = autostartOnly ? 'Yes, stop ' + stacks.length + ' autostart stack' + (stacks.length > 1 ? 's' : '') : 'Yes, stop ' + stacks.length + ' stack' + (stacks.length > 1 ? 's' : '');

        swal({
            title: title,
            html: true,
            text: '<div style="text-align:left;max-width:400px;margin:0 auto;"><p>The following stacks will be stopped:</p><div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:4px;max-height:200px;overflow-y:auto;margin:10px 0;">' + stackNames + '</div><p style="color:#f80;margin-top:10px;"><i class="fa fa-exclamation-triangle"></i> Containers will be stopped and removed. Data in volumes will be preserved.</p></div>',
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                executeStopAllStacks(stacks);
            }
        });
    }

    function executeStopAllStacks(stacks) {
        var height = 800;
        var width = 1200;

        // Create a list of paths to stop
        var paths = stacks.map(function(s) {
            return s.path;
        });

        // Mark stacks for local reload and show per-row spinners
        stacks.forEach(function(s) {
            var stackName = s.project;
            if (pendingComposeReloadStacks.indexOf(stackName) === -1) pendingComposeReloadStacks.push(stackName);
            composeClientDebug('[executeStopAllStacks] queued', {
                stack: stackName,
                pending: pendingComposeReloadStacks.slice()
            }, 'daemon', 'info');
            setStackActionInProgress(stackName, true);
        });

        $.post(compURL, {
            action: 'composeDownMultiple',
            paths: JSON.stringify(paths)
        }, function(data) {
            if (data) {
                openBox(data, 'Stop All Stacks', height, width, true);
            }
        });
    }

    // Helper to merge update status into containers array
    // Uses createContainerInfo for consistent name resolution
    function mergeUpdateStatus(containers, project) {
        if (!containers || !stackUpdateStatus[project] || !stackUpdateStatus[project].containers) {
            return containers;
        }
        containers.forEach(function(container) {
            var cInfo = createContainerInfo(container);
            stackUpdateStatus[project].containers.forEach(function(update) {
                var uInfo = createContainerInfo(update);
                if (cInfo.name === uInfo.name) {
                    container.hasUpdate = uInfo.hasUpdate;
                    container.updateStatus = uInfo.updateStatus;
                    container.localSha = uInfo.localSha;
                    container.remoteSha = uInfo.remoteSha;
                }
            });
        });
        return containers;
    }

    // Unified stack action dialog - handles up, down, and update actions
    function showStackActionDialog(action, path, profile) {
        var stackName = basename(path);
        var project = stackName;

        // Find the stack row (scoped to compose_stacks)
        var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
        var stackId = '';
        if ($stackRow.length > 0) {
            stackId = $stackRow.attr('id').replace('stack-row-', '');
        }

        // Check if we have cached container data
        if (stackId && stackContainersCache[stackId] && stackContainersCache[stackId].length > 0) {
            // Merge update status into cached data before rendering
            var containers = mergeUpdateStatus(stackContainersCache[stackId], project);
            renderStackActionDialog(action, stackName, path, profile, containers);
        } else {
            // Fetch container details first
            $.post(caURL, {
                action: 'getStackContainers',
                script: project
            }, function(data) {
                var containers = [];
                if (data) {
                    try {
                        var response = JSON.parse(data);
                        if (response.result === 'success' && response.containers) {
                            containers = response.containers;
                            if (stackId) {
                                stackContainersCache[stackId] = containers;
                            }
                        }
                    } catch (e) {}
                }
                // Merge update status into freshly fetched data
                containers = mergeUpdateStatus(containers, project);
                renderStackActionDialog(action, stackName, path, profile, containers);
            }).fail(function() {
                renderStackActionDialog(action, stackName, path, profile, []);
            });
        }
    }

    function renderStackActionDialog(action, stackName, path, profile, containers) {
        // Action-specific configuration
        var config = {
            'up': {
                title: 'Start ' + escapeHtml(stackName) + '?',
                description: 'This will start all containers in <b>' + escapeHtml(stackName) + '</b>.',
                listTitle: 'CONTAINERS TO START',
                warning: 'Images will be pulled if not present locally.',
                warningIcon: 'info-circle',
                warningColor: '#08f',
                confirmText: 'Start Stack',
                showVersionArrow: false,
                confirmedFn: ComposeUpConfirmed
            },
            'down': {
                title: 'Stop ' + escapeHtml(stackName) + '?',
                description: 'This will shut down all containers in <b>' + escapeHtml(stackName) + '</b>.',
                listTitle: 'CONTAINERS TO STOP',
                warning: 'Containers will be removed but data in volumes is preserved.',
                warningIcon: 'exclamation-triangle',
                warningColor: '#f80',
                confirmText: 'Stop Stack',
                showVersionArrow: false,
                confirmedFn: ComposeDownConfirmed
            },
            'update': {
                title: 'Update ' + escapeHtml(stackName) + '?',
                description: 'This will pull the latest images and recreate containers in <b>' + escapeHtml(stackName) + '</b>.',
                listTitle: 'CONTAINERS TO UPDATE',
                warning: 'Running containers will be recreated with the latest images.',
                warningIcon: 'exclamation-triangle',
                warningColor: '#f80',
                confirmText: 'Update Stack',
                showVersionArrow: true,
                confirmedFn: UpdateStackConfirmed
            }
        };

        var cfg = config[action];
        if (!cfg) return;

        // Build HTML content for the dialog
        var html = '<div style="text-align:left;max-width:450px;margin:0 auto;">';
        html += '<div style="margin-bottom:18px;">' + cfg.description + '</div>';

        // Container list with icons
        if (containers && containers.length > 0) {
            html += '<div style="background:rgba(0,0,0,0.2);border-radius:6px;padding:12px 14px;margin:12px 0;">';
            html += '<div style="font-weight:bold;margin-bottom:10px;font-size:0.9em;color:#999;"><i class="fa fa-cubes"></i> ' + cfg.listTitle + '</div>';

            containers.forEach(function(container, index) {
                var containerName = container.name || container.service || 'Unknown';
                var shortName = container.service || containerName.replace(/^[^-]+-/, '');
                var image = container.image || '';
                var imageParts = image.split(':');
                var imageName = imageParts[0].split('/').pop();
                var imageTag = imageParts[1] || 'latest';
                var state = container.state || 'unknown';
                var stateColor = state === 'running' ? '#3c3' : (state === 'paused' ? '#f80' : '#888');
                var stateIcon = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');

                // Check if this container has an update available
                var hasUpdate = container.hasUpdate || false;
                var updateStatus = container.updateStatus || 'unknown';
                var localSha = container.localSha || '';
                var remoteSha = container.remoteSha || '';

                var iconSrc = (container.icon && (container.icon.indexOf('http://') === 0 || container.icon.indexOf('https://') === 0 || container.icon.indexOf('data:image/') === 0)) ?
                    escapeAttr(container.icon) :
                    '/plugins/dynamix.docker.manager/images/question.png';

                // Grey out containers without updates when showing update dialog
                var rowOpacity = (cfg.showVersionArrow && !hasUpdate && updateStatus === 'up-to-date') ? '0.5' : '1';
                var isLast = (index === containers.length - 1);
                var borderStyle = isLast ? '' : 'border-bottom:1px solid rgba(255,255,255,0.1);';

                html += '<div style="display:flex;align-items:center;padding:8px 4px;' + borderStyle + 'opacity:' + rowOpacity + ';">';
                html += '<img src="' + iconSrc + '" style="width:28px;height:28px;margin-right:10px;border-radius:4px;" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
                html += '<div style="flex:1;">';
                html += '<div style="font-weight:bold;">' + escapeHtml(shortName);
                // Show update badge if update is available (for update action)
                if (cfg.showVersionArrow && hasUpdate) {
                    html += ' <span style="background:#f80;color:#fff;font-size:0.7em;padding:2px 6px;border-radius:3px;margin-left:6px;">UPDATE</span>';
                } else if (cfg.showVersionArrow && updateStatus === 'up-to-date') {
                    html += ' <span style="color:#3c3;font-size:0.8em;margin-left:6px;"><i class="fa fa-check"></i></span>';
                }
                html += '</div>';
                html += '<div style="font-size:0.85em;color:#999;margin-top:2px;">';
                html += '<i class="fa fa-' + stateIcon + '" style="color:' + stateColor + ';margin-right:4px;"></i>';
                html += escapeHtml(imageName) + ' : <span style="color:#f0a000;">' + escapeHtml(imageTag) + '</span>';

                // Show SHA info for update action
                if (cfg.showVersionArrow) {
                    if (hasUpdate && localSha && remoteSha) {
                        // Has update - show current SHA → new SHA
                        html += '<div style="font-family:monospace;font-size:0.9em;margin-top:2px;">';
                        html += '<span style="color:#f80;" title="' + escapeAttr(localSha) + '">' + escapeHtml(localSha.substring(0, 8)) + '</span>';
                        html += ' <i class="fa fa-arrow-right" style="margin:0 4px;color:#3c3;"></i> ';
                        html += '<span style="color:#3c3;" title="' + escapeAttr(remoteSha) + '">' + escapeHtml(remoteSha.substring(0, 8)) + '</span>';
                        html += '</div>';
                    } else if (localSha) {
                        // No update - just show current SHA (greyed)
                        html += '<div style="font-family:monospace;font-size:0.9em;color:#666;margin-top:2px;" title="' + escapeAttr(localSha) + '">' + escapeHtml(localSha.substring(0, 8)) + '</div>';
                    }
                }
                html += '</div></div></div>';
            });

            html += '</div>';
        }

        // Warning/info text
        html += '<div style="color:' + cfg.warningColor + ';margin-top:14px;font-size:0.9em;"><i class="fa fa-' + cfg.warningIcon + '"></i> ' + cfg.warning + '</div>';
        html += '</div>';

        // Use native swal (SweetAlert 1.x) with callback style
        swal({
            title: cfg.title,
            text: html,
            html: true,
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: cfg.confirmText,
            cancelButtonText: 'Cancel'
        }, function(confirmed) {
            if (confirmed) {
                cfg.confirmedFn(path, profile);
            }
        });
    }

    function ComposePull(path, profile = "") {
        var height = 800;
        var width = 1200;
        $.post(compURL, {
            action: 'composePull',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                openBox(data, "Stack " + basename(path) + " Pull", height, width, true);
            }
        })
    }

    function ComposeLogs(pathOrProject, profile = "") {
        var height = Math.min(screen.availHeight, 800);
        var width = Math.min(screen.availWidth, 1200);
        // Support both project name (legacy) and path
        var path = pathOrProject.includes('/') ? pathOrProject : compose_root + "/" + pathOrProject;
        $.post(compURL, {
            action: 'composeLogs',
            path: path,
            profile: profile
        }, function(data) {
            if (data) {
                window.open(data, 'Logs_' + basename(path),
                    'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
            }
        })
    }

    // ============================================
    // Stack Actions Menu Functions
    // ============================================
    var currentStackId = null;
    var expandedStacks = {};
    var stackContainersCache = {};
    var stackDefinedServicesCache = {}; // Cache for defined service counts
    // Track stacks currently loading details to prevent concurrent reloads
    var stackDetailsLoading = {};
    // Suppress immediate refresh after a render to avoid loops
    var stackDetailsJustRendered = {};

    function openStackActionsMenu(event, stackId) {
        event.stopPropagation();
        currentStackId = stackId;

        var $row = $('#stack-row-' + stackId);
        var projectName = $row.data('projectname');
        var isUp = $row.data('isup') == "1";

        // Update modal title
        $('.stack-actions-modal-title').text(projectName);

        // Show/hide certain actions based on state
        // Delete is disabled when stack is running
        var $deleteBtn = $('.stack-action-item:contains("Delete Stack")');
        if (isUp) {
            $deleteBtn.addClass('disabled').prop('disabled', true);
        } else {
            $deleteBtn.removeClass('disabled').prop('disabled', false);
        }

        // Position and show modal
        var $modal = $('#stack-actions-modal');
        var $overlay = $('#stack-actions-overlay');

        // Get button position for modal placement
        var $btn = $('#kebab-' + stackId);
        var btnOffset = $btn.offset();
        var btnHeight = $btn.outerHeight();

        // Position modal near the button
        $modal.css({
            top: btnOffset.top + btnHeight + 5,
            right: $(window).width() - btnOffset.left - $btn.outerWidth()
        });

        $overlay.show();
        $modal.show();
    }

    function closeStackActionsMenu() {
        $('#stack-actions-modal').hide();
        $('#stack-actions-overlay').hide();
        currentStackId = null;
    }

    function executeStackAction(action) {
        if (!currentStackId) return;

        var $row = $('#stack-row-' + currentStackId);
        var project = $row.data('project');
        var projectName = $row.data('projectname');
        var path = $row.data('path');
        var profiles = $row.data('profiles') || [];
        var isUp = $row.data('isup') == "1";

        closeStackActionsMenu();

        // Handle profile selection if profiles exist and action supports it
        var profileSupportedActions = ['up', 'down', 'update', 'pull', 'logs'];
        if (profiles.length > 0 && profileSupportedActions.includes(action)) {
            showProfileSelector(action, path, profiles);
            return;
        }

        switch (action) {
            case 'up':
                ComposeUp(path);
                break;
            case 'down':
                ComposeDown(path);
                break;
            case 'update':
                UpdateStack(path);
                break;
            case 'pull':
                ComposePull(path);
                break;
            case 'logs':
                ComposeLogs(path);
                break;
            case 'edit':
                openEditorModalByProject(project, projectName);
                break;
            case 'delete':
                if (!isUp) {
                    deleteStackByProject(project, projectName);
                }
                break;
        }
    }

    function showProfileSelector(action, path, profiles) {
        var actionNames = {
            'up': 'Compose Up',
            'down': 'Compose Down',
            'update': 'Update Stack',
            'pull': 'Pull Images',
            'logs': 'View Logs'
        };

        // Build profile selection HTML with checkboxes for multi-select
        var profileHtml = '<div style="text-align: left;">';
        profileHtml += '<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid rgba(128,128,128,0.3);">';
        profileHtml += '<label style="font-weight:bold;"><input type="checkbox" id="profile_all" checked onchange="toggleAllProfiles(this)"> All Services (no profile filter)</label>';
        profileHtml += '</div>';
        profileHtml += '<div id="profile_list">';
        profiles.forEach(function(profile) {
            profileHtml += '<label style="display:block;margin:5px 0;"><input type="checkbox" class="profile_checkbox" value="' + escapeHtml(profile) + '" disabled> ' + escapeHtml(profile) + '</label>';
        });
        profileHtml += '</div>';
        profileHtml += '<div style="margin-top:10px;font-size:0.9em;color:#888;"><i class="fa fa-info-circle"></i> Select multiple profiles to include services from each.</div>';
        profileHtml += '</div>';

        swal({
            title: "Select Profiles",
            text: "Choose which profiles to use for " + actionNames[action] + "<br><br>" + profileHtml,
            html: true,
            showCancelButton: true,
            confirmButtonText: "Continue",
            cancelButtonText: "Cancel"
        }, function(confirmed) {
            if (confirmed) {
                var selectedProfiles = [];
                if (!$('#profile_all').is(':checked')) {
                    $('.profile_checkbox:checked').each(function() {
                        selectedProfiles.push($(this).val());
                    });
                }
                // Join profiles with comma for multi-profile support
                var profileStr = selectedProfiles.join(',');
                switch (action) {
                    case 'up':
                        ComposeUp(path, profileStr);
                        break;
                    case 'down':
                        ComposeDown(path, profileStr);
                        break;
                    case 'update':
                        UpdateStack(path, profileStr);
                        break;
                    case 'pull':
                        ComposePull(path, profileStr);
                        break;
                    case 'logs':
                        ComposeLogs(path, profileStr);
                        break;
                }
            }
        });
    }

    // Toggle profile checkboxes when "All Services" is checked/unchecked
    function toggleAllProfiles(checkbox) {
        var disabled = checkbox.checked;
        $('.profile_checkbox').prop('disabled', disabled).prop('checked', false);
    }

    function openEditorModalByProject(project, projectName, initialTab) {
        editorModal.currentProject = project;
        editorModal.modifiedTabs = new Set();
        editorModal.modifiedSettings = new Set();
        editorModal.modifiedLabels = new Set();
        editorModal.originalContent = {};
        editorModal.originalSettings = {};
        editorModal.originalLabels = {};
        editorModal.labelsData = null;

        // Reset all tabs to unmodified state
        $('.editor-tab').removeClass('modified active');
        $('.editor-main-tab').removeClass('modified active');
        $('.editor-container').removeClass('active');
        $('.editor-panel').removeClass('active');

        // Set modal title
        $('#editor-modal-title').text('Editing: ' + projectName);
        $('#editor-file-info').text(compose_root + '/' + project);

        // Show loading state
        $('#editor-modal-overlay').addClass('active');
        $('#editor-validation-compose').html('<i class="fa fa-spinner fa-spin editor-validation-icon"></i> Loading files...').removeClass('valid error warning');

        // Load all files and settings
        loadEditorFiles(project);
        loadSettingsData(project, projectName);

        // Switch to appropriate initial tab (default to 'compose')
        var targetTab = initialTab || 'compose';
        switchTab(targetTab);
    }

    function loadEditorFiles(project) {
        var loadPromises = [];

        // Load compose file
        loadPromises.push(
            $.post(caURL, {
                action: 'getYml',
                script: project
            }).then(function(data) {
                if (data) {
                    var response = jQuery.parseJSON(data);
                    editorModal.originalContent['compose'] = response.content || '';
                    editorModal.editors['compose'].setValue(response.content || '', -1);
                }
            }).fail(function() {
                var errorContent = '# Error loading file';
                editorModal.originalContent['compose'] = errorContent;
                editorModal.editors['compose'].setValue(errorContent, -1);
            })
        );

        // Load env file
        loadPromises.push(
            $.post(caURL, {
                action: 'getEnv',
                script: project
            }).then(function(data) {
                if (data) {
                    var response = jQuery.parseJSON(data);
                    editorModal.originalContent['env'] = response.content || '';
                    editorModal.editors['env'].setValue(response.content || '', -1);
                }
            }).fail(function() {
                var errorContent = '# Error loading file';
                editorModal.originalContent['env'] = errorContent;
                editorModal.editors['env'].setValue(errorContent, -1);
            })
        );

        // When all files are loaded
        $.when.apply($, loadPromises).then(function() {
            // Run validation on compose file
            validateYaml('compose', editorModal.editors['compose'].getValue());
        }).fail(function() {
            $('#editor-validation-compose').html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Error loading some files').removeClass('valid').addClass('error');
        });
    }

    // Load settings data into the settings panel
    function loadSettingsData(project, projectName) {
        // Set the name from projectName (display name)
        $('#settings-name').val(projectName || '');
        editorModal.originalSettings['name'] = projectName || '';

        // Load description
        $.post(caURL, {
            action: 'getDescription',
            script: project
        }).then(function(data) {
            if (data) {
                var response = JSON.parse(data);
                var desc = (response.content || '').replace(/<br>/g, '\n');
                $('#settings-description').val(desc);
                editorModal.originalSettings['description'] = desc;
            }
        }).fail(function() {
            $('#settings-description').val('');
            editorModal.originalSettings['description'] = '';
        });

        // Load stack settings (icon URL and env path)
        $.post(caURL, {
            action: 'getStackSettings',
            script: project
        }).then(function(data) {
            if (data) {
                var response = JSON.parse(data);
                if (response.result === 'success') {
                    // Icon URL
                    var iconUrl = response.iconUrl || '';
                    $('#settings-icon-url').val(iconUrl);
                    editorModal.originalSettings['icon-url'] = iconUrl;
                    if (iconUrl && (iconUrl.startsWith('http://') || iconUrl.startsWith('https://'))) {
                        $('#settings-icon-preview-img').attr('src', iconUrl);
                        $('#settings-icon-preview').show();
                    } else {
                        $('#settings-icon-preview').hide();
                    }

                    // WebUI URL
                    var webuiUrl = response.webuiUrl || '';
                    $('#settings-webui-url').val(webuiUrl);
                    editorModal.originalSettings['webui-url'] = webuiUrl;

                    // ENV path
                    var envPath = response.envPath || '';
                    $('#settings-env-path').val(envPath);
                    editorModal.originalSettings['env-path'] = envPath;

                    // External compose path
                    var externalComposePath = response.externalComposePath || '';
                    $('#settings-external-compose-path').val(externalComposePath);
                    editorModal.originalSettings['external-compose-path'] = externalComposePath;
                    if (externalComposePath) {
                        $('#settings-external-compose-info').show();
                    } else {
                        $('#settings-external-compose-info').hide();
                    }

                    // Default profile
                    var defaultProfile = response.defaultProfile || '';
                    $('#settings-default-profile').val(defaultProfile);
                    editorModal.originalSettings['default-profile'] = defaultProfile;

                    // Available profiles (from the profiles file)
                    var availableProfiles = response.availableProfiles || [];
                    if (availableProfiles.length > 0) {
                        $('#settings-profiles-list').text(availableProfiles.join(', '));
                        $('#settings-available-profiles').show();
                    } else {
                        $('#settings-available-profiles').hide();
                    }
                }
            }
        }).fail(function() {
            $('#settings-icon-url').val('');
            $('#settings-webui-url').val('');
            $('#settings-env-path').val('');
            $('#settings-default-profile').val('');
            $('#settings-external-compose-path').val('');
            editorModal.originalSettings['icon-url'] = '';
            editorModal.originalSettings['webui-url'] = '';
            editorModal.originalSettings['env-path'] = '';
            editorModal.originalSettings['default-profile'] = '';
            editorModal.originalSettings['external-compose-path'] = '';
            $('#settings-icon-preview').hide();
            $('#settings-available-profiles').hide();
            $('#settings-external-compose-info').hide();
        });
    }

    // Load labels data for the WebUI Labels panel
    function loadLabelsData() {
        var project = editorModal.currentProject;
        if (!project) return;

        $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-spinner fa-spin"></i> Loading services...</div>');

        // Load both compose file and override file to build the labels UI
        var composePromise = $.post(caURL, {
            action: 'getYml',
            script: project
        });
        var overridePromise = $.post(caURL, {
            action: 'getOverride',
            script: project
        });

        $.when(composePromise, overridePromise).then(function(composeResult, overrideResult) {
            try {
                var composeData = JSON.parse(composeResult[0]);
                var overrideData = JSON.parse(overrideResult[0]);

                if (composeData.result !== 'success') {
                    throw new Error('Failed to load compose file');
                }

                var mainDoc = jsyaml.load(composeData.content) || {
                    services: {}
                };
                var overrideDoc = jsyaml.load(overrideData.content || '') || {
                    services: {}
                };

                // Ensure override has services object
                if (!overrideDoc.services) {
                    overrideDoc.services = {};
                }

                editorModal.labelsData = {
                    mainDoc: mainDoc,
                    overrideDoc: overrideDoc
                };

                renderLabelsUI(mainDoc, overrideDoc);

            } catch (e) {
                console.error('Failed to parse compose files for labels:', e);
                $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Error loading services: ' + escapeHtml(e.message) + '</div>');
            }
        }).fail(function() {
            $('#labels-services-container').html('<div class="labels-empty-state"><i class="fa fa-exclamation-triangle"></i> Failed to load compose files</div>');
        });
    }

    // Render the WebUI Labels UI
    function renderLabelsUI(mainDoc, overrideDoc) {
        var html = '';
        var deletedHtml = '';
        var hasServices = false;
        var hasDeletedServices = false;

        // Process services from main compose file
        for (var serviceKey in mainDoc.services) {
            hasServices = true;
            var service = mainDoc.services[serviceKey];
            var overrideService = overrideDoc.services[serviceKey] || {
                labels: {}
            };

            // Ensure override service has proper structure
            if (!overrideService.labels) {
                overrideDoc.services[serviceKey] = overrideDoc.services[serviceKey] || {};
                overrideDoc.services[serviceKey].labels = overrideDoc.services[serviceKey].labels || {};
                overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
                overrideService = overrideDoc.services[serviceKey];
            }

            var containerName = service.container_name || serviceKey;
            var iconValue = findLabelValue(overrideService, service, icon_label);
            var webuiValue = findLabelValue(overrideService, service, webui_label);
            var shellValue = findLabelValue(overrideService, service, shell_label);

            // Store original values
            editorModal.originalLabels[serviceKey + '_icon'] = iconValue;
            editorModal.originalLabels[serviceKey + '_webui'] = webuiValue;
            editorModal.originalLabels[serviceKey + '_shell'] = shellValue;

            var iconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
            html += '<div class="labels-service" data-service="' + escapeAttr(serviceKey) + '">';
            html += '<div class="labels-service-header">';
            html += '<img class="labels-service-icon" id="label-icon-preview-' + escapeAttr(serviceKey) + '" src="' + escapeAttr(iconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
            html += '<span class="labels-service-name">' + escapeHtml(containerName) + '</span>';
            html += '</div>';
            html += '<div class="labels-service-fields">';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-picture-o"></i> Icon URL</label>';
            html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-icon" value="' + escapeAttr(iconValue) + '" placeholder="https://example.com/icon.png" data-service="' + escapeAttr(serviceKey) + '" data-field="icon">';
            html += '</div>';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-globe"></i> WebUI URL</label>';
            html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-webui" value="' + escapeAttr(webuiValue) + '" placeholder="http://[IP]:[PORT:8080]/" data-service="' + escapeAttr(serviceKey) + '" data-field="webui">';
            html += '</div>';
            html += '<div class="labels-field">';
            html += '<label><i class="fa fa-terminal"></i> Shell</label>';
            html += '<input type="text" id="label-' + escapeAttr(serviceKey) + '-shell" value="' + escapeAttr(shellValue) + '" placeholder="/bin/bash" data-service="' + escapeAttr(serviceKey) + '" data-field="shell">';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Check for deleted services in override that aren't in main
        for (var serviceKey in overrideDoc.services) {
            if (!(serviceKey in mainDoc.services)) {
                hasDeletedServices = true;
                var overrideService = overrideDoc.services[serviceKey];
                var containerName = (overrideService && overrideService.container_name) || serviceKey;
                var iconValue = findLabelValue(overrideService, {}, icon_label);
                var webuiValue = findLabelValue(overrideService, {}, webui_label);
                var shellValue = findLabelValue(overrideService, {}, shell_label);

                var deletedIconSrc = iconValue || '/plugins/dynamix.docker.manager/images/question.png';
                deletedHtml += '<div class="labels-service deleted" data-service="' + escapeAttr(serviceKey) + '" data-deleted="true">';
                deletedHtml += '<div class="labels-service-header">';
                deletedHtml += '<img class="labels-service-icon" src="' + escapeAttr(deletedIconSrc) + '" alt="" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
                deletedHtml += '<span class="labels-service-name">' + escapeHtml(containerName) + ' <span style="color:#f44336;font-size:0.8em;">(will be removed)</span></span>';
                deletedHtml += '</div>';
                deletedHtml += '<div class="labels-service-fields">';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-picture-o"></i> Icon</label><input type="text" value="' + escapeAttr(iconValue) + '" disabled></div>';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-globe"></i> WebUI</label><input type="text" value="' + escapeAttr(webuiValue) + '" disabled></div>';
                deletedHtml += '<div class="labels-field"><label><i class="fa fa-terminal"></i> Shell</label><input type="text" value="' + escapeAttr(shellValue) + '" disabled></div>';
                deletedHtml += '</div>';
                deletedHtml += '</div>';
            }
        }

        if (!hasServices) {
            html = '<div class="labels-empty-state"><i class="fa fa-cubes"></i> No services defined in compose file</div>';
        }

        if (hasDeletedServices) {
            html += '<div class="labels-deleted-section">';
            html += '<div class="labels-deleted-title" onclick="toggleDeletedServices(this)"><i class="fa fa-chevron-right"></i> Orphaned Services (will be removed on save)</div>';
            html += '<div class="labels-deleted-services">' + deletedHtml + '</div>';
            html += '</div>';
        }

        $('#labels-services-container').html(html);

        // Attach change handlers to label inputs
        $('#labels-services-container').find('input[data-service]').on('input', function() {
            var service = $(this).data('service');
            var field = $(this).data('field');
            var key = service + '_' + field;
            var currentValue = $(this).val();
            var originalValue = editorModal.originalLabels[key] || '';

            if (currentValue !== originalValue) {
                editorModal.modifiedLabels.add(key);
            } else {
                editorModal.modifiedLabels.delete(key);
            }

            // Live icon preview with debounce
            if (field === 'icon') {
                var $input = $(this);
                clearTimeout($input.data('iconDebounce'));
                $input.data('iconDebounce', setTimeout(function() {
                    var iconUrl = $input.val().trim();
                    var $preview = $('#label-icon-preview-' + service);
                    if (iconUrl) {
                        $preview.attr('src', iconUrl);
                    } else {
                        $preview.attr('src', '/plugins/dynamix.docker.manager/images/question.png');
                    }
                }, 300));
            }

            updateSaveButtonState();
            updateTabModifiedState();
        });
    }

    // Helper to find label value from override or main service
    function findLabelValue(overrideService, mainService, labelKey) {
        if (overrideService && overrideService.labels && overrideService.labels[labelKey]) {
            return overrideService.labels[labelKey];
        }
        if (mainService && mainService.labels && mainService.labels[labelKey]) {
            return mainService.labels[labelKey];
        }
        return '';
    }

    // Toggle deleted services visibility
    function toggleDeletedServices(el) {
        var $title = $(el);
        var $services = $title.next('.labels-deleted-services');
        $title.toggleClass('expanded');
        $services.toggleClass('visible');
    }

    function validateYaml(type, content) {
        if (type === 'env') {
            // Basic validation for env files
            updateValidation(type, content);
            return;
        }

        try {
            if (content.trim()) {
                jsyaml.load(content);
            }
            updateValidation(type, content, true);
        } catch (e) {
            updateValidation(type, content, false, e.message);
        }
    }

    function updateValidation(type, content, isValid, errorMsg) {
        var validationEl = $('#editor-validation-' + type);

        // Handle env files separately (no YAML validation needed)
        if (type === 'env') {
            var lines = content.split('\n').filter(l => l.trim() && !l.trim().startsWith('#'));
            validationEl.html('<i class="fa fa-info-circle editor-validation-icon"></i> ' + lines.length + ' environment variable(s)');
            validationEl.removeClass('error warning').addClass('valid');
            return;
        }

        // If isValid is undefined, run actual YAML validation
        if (isValid === undefined) {
            validateYaml(type, content);
            return;
        }

        if (isValid) {
            validationEl.html('<i class="fa fa-check editor-validation-icon"></i> YAML syntax is valid');
            validationEl.removeClass('error warning').addClass('valid');
        } else {
            // Truncate error message to first line for cleaner display
            var shortError = errorMsg.split('\n')[0].substring(0, 100);
            if (errorMsg.length > 100) shortError += '...';
            // Use text node to prevent XSS from malicious YAML content
            validationEl.empty()
                .append('<i class="fa fa-times editor-validation-icon"></i> YAML Error: ')
                .append(document.createTextNode(shortError));
            validationEl.removeClass('valid warning').addClass('error');
        }
    }

    function updateSaveButtonState() {
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
        var hasChanges = totalChanges > 0;
        $('#editor-btn-save-all').prop('disabled', !hasChanges);

        if (hasChanges) {
            $('#editor-btn-save-all').text('Save All (' + totalChanges + ')');
        } else {
            $('#editor-btn-save-all').text('Save All');
        }
    }

    function saveCurrentTab() {
        var currentTab = editorModal.currentTab;
        if (!currentTab) return;

        // Only save editor tabs
        if (currentTab !== 'compose' && currentTab !== 'env') return;
        if (!editorModal.modifiedTabs.has(currentTab)) return;

        saveTab(currentTab).then(function(result) {
            if (result === true) {
                // Brief feedback in validation panel
                $('#editor-validation-' + currentTab).html('<i class="fa fa-check editor-validation-icon"></i> Saved!').removeClass('error warning').addClass('valid');
                setTimeout(function() {
                    validateYaml(currentTab, editorModal.editors[currentTab].getValue());
                }, 1500);
            } else {
                $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
            }
        }).catch(function() {
            $('#editor-validation-' + currentTab).html('<i class="fa fa-exclamation-triangle editor-validation-icon"></i> Save failed').removeClass('valid warning').addClass('error');
        });
    }

    function saveTab(tabName, saveErrors) {
        var content = editorModal.editors[tabName].getValue();
        var project = editorModal.currentProject;
        var actionStr = null;

        switch (tabName) {
            case 'compose':
                actionStr = 'saveYml';
                break;
            case 'env':
                actionStr = 'saveEnv';
                break;
            default:
                return Promise.reject('Unknown tab');
        }

        return $.post(caURL, {
            action: actionStr,
            script: project,
            scriptContents: content
        }).then(function(data) {
            if (data) {
                editorModal.originalContent[tabName] = content;
                editorModal.modifiedTabs.delete(tabName);
                $('#editor-tab-' + tabName).removeClass('modified');
                updateSaveButtonState();
                updateTabModifiedState();

                // Regenerate profiles if compose file was saved
                if (tabName === 'compose') {
                    generateProfiles(null, project);
                }

                return true;
            }
            return false;
        }).fail(function() {
            if (saveErrors) saveErrors.push('Failed to save ' + tabName + ' file.');
            return false;
        });
    }

    // Save all modified changes (files, settings, and labels)
    function saveAllChanges() {
        var savePromises = [];
        var saveErrors = [];
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;

        if (totalChanges === 0) {
            return;
        }

        // Track if labels are being modified (need to offer recreate)
        var labelsWereModified = editorModal.modifiedLabels.size > 0;

        // Save modified file tabs
        editorModal.modifiedTabs.forEach(function(tabName) {
            savePromises.push(saveTab(tabName, saveErrors));
        });

        // Save settings if modified
        if (editorModal.modifiedSettings.size > 0) {
            savePromises.push(saveSettings(saveErrors));
        }

        // Save labels if modified
        if (editorModal.modifiedLabels.size > 0) {
            savePromises.push(saveLabels());
        }

        $.when.apply($, savePromises).then(function() {
            var results = Array.prototype.slice.call(arguments);
            var allSucceeded = results.every(function(result) {
                return result === true;
            });

            if (allSucceeded) {
                // Check if we should offer to recreate containers
                if (labelsWereModified) {
                    promptRecreateContainers();
                } else {
                    // Close editor and refresh stack locally
                    doCloseEditorModal();
                    swal({
                        title: "Saved!",
                        text: "All changes have been saved.",
                        type: "success",
                        timer: 1500,
                        showConfirmButton: false
                    });
                    setTimeout(function() {
                        refreshStackByProject(editorModal.currentProject);
                    }, 1600);
                }
            } else {
                var errorText = saveErrors.length > 0 ?
                    saveErrors.join('\n') :
                    'Some items could not be saved. Please try again.';
                swal({
                    title: "Save Failed",
                    text: errorText,
                    type: "error"
                });
            }
        }).fail(function() {
            swal({
                title: "Save Failed",
                text: "An error occurred while saving. Please try again.",
                type: "error"
            });
        });
    }

    // Save settings
    function saveSettings(saveErrors) {
        var project = editorModal.currentProject;
        var savePromises = [];
        var needsReload = false;

        // Save name if modified
        if (editorModal.modifiedSettings.has('name')) {
            var newName = $('#settings-name').val();
            savePromises.push(
                $.post(caURL, {
                    action: 'changeName',
                    script: project,
                    newName: newName
                }).then(function() {
                    editorModal.originalSettings['name'] = newName;
                    editorModal.modifiedSettings.delete('name');
                    needsReload = true;
                    return true;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save stack name.');
                    return false;
                })
            );
        }

        // Save description if modified
        if (editorModal.modifiedSettings.has('description')) {
            var newDesc = $('#settings-description').val().replace(/\n/g, '<br>');
            savePromises.push(
                $.post(caURL, {
                    action: 'changeDesc',
                    script: project,
                    newDesc: newDesc
                }).then(function() {
                    editorModal.originalSettings['description'] = $('#settings-description').val();
                    editorModal.modifiedSettings.delete('description');
                    return true;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save description.');
                    return false;
                })
            );
        }

        // Save icon URL, webui URL, env path, default profile, and external compose path if any are modified
        if (editorModal.modifiedSettings.has('icon-url') || editorModal.modifiedSettings.has('webui-url') || editorModal.modifiedSettings.has('env-path') || editorModal.modifiedSettings.has('default-profile') || editorModal.modifiedSettings.has('external-compose-path')) {
            var iconUrl = $('#settings-icon-url').val();
            var webuiUrl = $('#settings-webui-url').val();
            var envPath = $('#settings-env-path').val();
            var defaultProfile = $('#settings-default-profile').val();
            var externalComposePath = $('#settings-external-compose-path').val();
            savePromises.push(
                $.post(caURL, {
                    action: 'setStackSettings',
                    script: project,
                    iconUrl: iconUrl,
                    webuiUrl: webuiUrl,
                    envPath: envPath,
                    defaultProfile: defaultProfile,
                    externalComposePath: externalComposePath
                }).then(function(data) {
                    if (data) {
                        var response = JSON.parse(data);
                        if (response.result === 'success') {
                            editorModal.originalSettings['icon-url'] = iconUrl;
                            editorModal.originalSettings['webui-url'] = webuiUrl;
                            editorModal.originalSettings['env-path'] = envPath;
                            editorModal.originalSettings['default-profile'] = defaultProfile;
                            editorModal.originalSettings['external-compose-path'] = externalComposePath;
                            editorModal.modifiedSettings.delete('icon-url');
                            editorModal.modifiedSettings.delete('webui-url');
                            editorModal.modifiedSettings.delete('env-path');
                            editorModal.modifiedSettings.delete('default-profile');
                            editorModal.modifiedSettings.delete('external-compose-path');
                            needsReload = true;
                            return true;
                        } else {
                            // Collect error message from server
                            if (saveErrors) saveErrors.push(response.message || 'Failed to save stack settings.');
                        }
                    }
                    return false;
                }).fail(function() {
                    if (saveErrors) saveErrors.push('Failed to save stack settings (network error).');
                    return false;
                })
            );
        }

        return $.when.apply($, savePromises).then(function() {
            var results = Array.prototype.slice.call(arguments);
            var allSucceeded = savePromises.length === 0 || results.every(function(result) {
                return result === true;
            });

            updateTabModifiedState();
            updateSaveButtonState();
            return allSucceeded;
        });
    }

    // Save labels to override file
    function saveLabels() {
        var project = editorModal.currentProject;

        if (!editorModal.labelsData) {
            return $.Deferred().reject().promise();
        }

        var mainDoc = editorModal.labelsData.mainDoc;
        var overrideDoc = editorModal.labelsData.overrideDoc;

        // Update override doc with values from the form
        for (var serviceKey in mainDoc.services) {
            if (!(serviceKey in overrideDoc.services)) {
                overrideDoc.services[serviceKey] = {
                    labels: {}
                };
                overrideDoc.services[serviceKey].labels[<?php echo json_encode($docker_label_managed); ?>] = <?php echo json_encode($docker_label_managed_name); ?>;
            }

            var iconValue = $('#label-' + serviceKey + '-icon').val() || '';
            var webuiValue = $('#label-' + serviceKey + '-webui').val() || '';
            var shellValue = $('#label-' + serviceKey + '-shell').val() || '';

            if (!overrideDoc.services[serviceKey].labels) {
                overrideDoc.services[serviceKey].labels = {};
            }

            overrideDoc.services[serviceKey].labels[icon_label] = iconValue;
            overrideDoc.services[serviceKey].labels[webui_label] = webuiValue;
            overrideDoc.services[serviceKey].labels[shell_label] = shellValue;
        }

        // Remove services from override that are no longer in main
        for (var serviceKey in overrideDoc.services) {
            if (!(serviceKey in mainDoc.services)) {
                delete overrideDoc.services[serviceKey];
            }
        }

        // Convert to YAML and save
        var rawOverride = jsyaml.dump(overrideDoc, {
            'forceQuotes': true
        });

        return $.post(caURL, {
            action: 'saveOverride',
            script: project,
            scriptContents: rawOverride
        }).then(function(data) {
            if (data) {
                // Update original labels to match current values
                for (var serviceKey in mainDoc.services) {
                    editorModal.originalLabels[serviceKey + '_icon'] = $('#label-' + serviceKey + '-icon').val() || '';
                    editorModal.originalLabels[serviceKey + '_webui'] = $('#label-' + serviceKey + '-webui').val() || '';
                    editorModal.originalLabels[serviceKey + '_shell'] = $('#label-' + serviceKey + '-shell').val() || '';
                }
                editorModal.modifiedLabels.clear();
                updateTabModifiedState();
                updateSaveButtonState();
                return true;
            }
            return false;
        }).fail(function() {
            swal({
                title: "Save Failed",
                text: "Failed to save WebUI labels. Please try again.",
                type: "error"
            });
            return false;
        });
    }

    // Keep saveAllTabs for backwards compatibility
    function saveAllTabs() {
        saveAllChanges();
    }

    function closeEditorModal() {
        var totalChanges = editorModal.modifiedTabs.size + editorModal.modifiedSettings.size + editorModal.modifiedLabels.size;
        if (totalChanges > 0) {
            swal({
                title: "Unsaved Changes",
                text: "You have unsaved changes. Are you sure you want to close?",
                type: "warning",
                showCancelButton: true,
                confirmButtonText: "Discard Changes",
                cancelButtonText: "Cancel"
            }, function(confirmed) {
                if (confirmed) {
                    doCloseEditorModal();
                }
            });
        } else {
            doCloseEditorModal();
        }
    }

    function doCloseEditorModal() {
        $('#editor-modal-overlay').removeClass('active');
        editorModal.currentProject = null;
        editorModal.currentTab = 'compose';
        editorModal.modifiedTabs = new Set();
        editorModal.modifiedSettings = new Set();
        editorModal.modifiedLabels = new Set();
        editorModal.originalContent = {};
        editorModal.originalSettings = {};
        editorModal.originalLabels = {};
        editorModal.labelsData = null;

        // Clear editor content to avoid showing stale content on next open
        ['compose', 'env'].forEach(function(type) {
            if (editorModal.editors[type]) {
                editorModal.editors[type].setValue('', -1);
            }
        });

        // Reset settings fields
        $('#settings-name').val('');
        $('#settings-description').val('');
        $('#settings-icon-url').val('');
        $('#settings-webui-url').val('');
        $('#settings-env-path').val('');
        $('#settings-default-profile').val('');
        $('#settings-external-compose-path').val('');
        $('#settings-icon-preview').hide();
        $('#settings-available-profiles').hide();
        $('#settings-external-compose-info').hide();

        // Clear labels container
        $('#labels-services-container').html('');

        // Reset tab states
        $('.editor-tab').removeClass('modified');
    }

    function deleteStackByProject(project, projectName) {
        var msgHtml = "Are you sure you want to delete <font color='red'><b>" + escapeHtml(projectName) + "</b></font> (<font color='green'>" + escapeHtml(compose_root) + "/" + escapeHtml(project) + "</font>)?";
        swal({
            title: "Delete Stack?",
            text: msgHtml,
            html: true,
            type: "warning",
            showCancelButton: true,
            confirmButtonText: "Delete",
            cancelButtonText: "Cancel"
        }, function(confirmed) {
            if (confirmed) {
                setStackActionInProgress(project, true, 'Deleting stack...');
                $.post(caURL, {
                    action: 'deleteStack',
                    stackName: project
                }, function(data) {
                    try {
                        if (data) {
                            var response = JSON.parse(data);
                            if (response.result == "warning") {
                                setTimeout(function() {
                                    swal({
                                        title: "Files remain on disk.",
                                        text: response.message,
                                        type: "warning"
                                    }, function() {
                                        composeLoadlist();
                                    });
                                }, 100);
                                return;
                            }
                        }
                    } catch (e) {
                        composeClientDebug('[Delete response parse error] ', {
                            project: project,
                            error: e
                        }, 'daemon', 'error');
                    }
                }).fail(function() {
                    composeClientDebug('[Delete request failed for project] ', {
                        project: project
                    }, 'daemon', 'error');
                });
                composeLoadlist();
            }
        });
    }

    // ============================================
    // Expandable Stack Details Functions
    // ============================================
    function toggleStackDetails(stackId) {
        var $row = $('#stack-row-' + stackId);
        var $detailsRow = $('#details-row-' + stackId);
        var $expandIcon = $('#expand-icon-' + stackId);
        var project = $row.data('project');

        if (expandedStacks[stackId]) {
            // Collapse
            $detailsRow.slideUp(200);
            $expandIcon.removeClass('expanded');
            expandedStacks[stackId] = false;
        } else {
            $expandIcon.addClass('expanded');
            expandedStacks[stackId] = true;

            if (stackContainersCache[stackId]) {
                // Cached — content is already rendered in the DOM from last load.
                // Just slide down without re-fetching to avoid flash/layout shift.
                $detailsRow.slideDown(200);
            } else {
                // First load: fetch data, row stays hidden until render completes
                loadStackContainerDetails(stackId, project);
            }
        }
    }

    function loadStackContainerDetails(stackId, project) {
        var $container = $('#details-container-' + stackId);

        // Prevent parallel loads for same stack
        if (stackDetailsLoading[stackId]) {
            composeClientDebug('[loadStackContainerDetails] already-loading', {
                stackId: stackId,
                project: project
            }, 'daemon', 'warning');
            return;
        }
        stackDetailsLoading[stackId] = true;
        composeClientDebug('[loadStackContainerDetails] start', {
            stackId: stackId,
            project: project
        }, 'daemon', 'info');

        // Show loading state
        $container.html('<div class="stack-details-loading"><i class="fa fa-spinner fa-spin"></i> Loading container details...</div>');

        $.post(caURL, {
            action: 'getStackContainers',
            script: project
        }, function(data) {
            if (data) {
                var response = JSON.parse(data);
                if (response.result === 'success') {
                    var containers = response.containers;

                    // Normalize all containers via factory function (PascalCase→camelCase)
                    containers = containers.map(function(c) {
                        var info = createContainerInfo(c);
                        return Object.assign({}, c, info);
                    });

                    // Merge update status from stackUpdateStatus if available
                    mergeUpdateStatus(containers, project);

                    stackContainersCache[stackId] = containers;
                    stackDefinedServicesCache[stackId] = response.definedServices || containers.length;
                    composeClientDebug('[loadStackContainerDetails] success', {
                        stackId: stackId,
                        project: project,
                        containers: containers.length
                    }, 'daemon', 'info');
                    renderContainerDetails(stackId, containers, project);
                    // Slide down details row now that content is rendered
                    $('#details-row-' + stackId).slideDown(200);
                } else {
                    // Escape error message to prevent XSS
                    var errorMsg = escapeHtml(response.message || 'Failed to load container details');
                    $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> ' + errorMsg + '</div>');
                    $('#details-row-' + stackId).slideDown(200);
                    stackDetailsLoading[stackId] = false;
                    composeClientDebug('[loadStackContainerDetails] error', {
                        stackId: stackId,
                        project: project,
                        message: errorMsg
                    }, 'daemon', 'error');
                }
            } else {
                $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
                $('#details-row-' + stackId).slideDown(200);
                stackDetailsLoading[stackId] = false;
                composeClientDebug('[loadStackContainerDetails] empty-response', {
                    stackId: stackId,
                    project: project
                }, 'daemon', 'warning');
            }
        }).fail(function() {
            $container.html('<div class="stack-details-error"><i class="fa fa-exclamation-triangle"></i> Failed to load container details</div>');
            $('#details-row-' + stackId).slideDown(200);
            stackDetailsLoading[stackId] = false;
            composeClientDebug('[loadStackContainerDetails] failed', {
                stackId: stackId,
                project: project
            }, 'daemon', 'error');
        });
    }

    function renderContainerDetails(stackId, containers, project) {
        var $container = $('#details-container-' + stackId);

        if (!containers || containers.length === 0) {
            $container.html('<div class="stack-details-empty"><i class="fa fa-info-circle"></i> No containers found. Stack may not be running.</div>');
            return;
        }

        // Mini Docker table - matches Docker tab columns
        var html = '<table class="tablesorter shift compose-ct-table">';
        html += '<thead><tr>';
        html += '<th class="ct-col-name">Container</th>';
        html += '<th class="ct-col-update">Update</th>';
        html += '<th class="ct-col-source">Source</th>';
        html += '<th class="ct-col-tag">Tag</th>';
        html += '<th class="ct-col-net">Network</th>';
        html += '<th class="ct-col-ip">Container IP</th>';
        html += '<th class="ct-col-cport">Container Port</th>';
        html += '<th class="ct-col-lport">LAN IP:Port</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        containers.forEach(function(container, idx) {
            var containerName = container.name || container.service || 'Unknown';
            var shortName = container.service || containerName.replace(/^[^-]+-/, ''); // Prefer service name; fall back to stripping project prefix
            var image = container.image || '';

            // Parse image - handle docker.io/ prefix and @sha256: digest
            // Format could be: docker.io/library/redis:6.2-alpine@sha256:abc123...
            var imageForParsing = image;
            if (imageForParsing.indexOf('docker.io/') === 0) {
                imageForParsing = imageForParsing.substring(10);
            }

            // Check for @sha256: digest suffix
            var digestSuffix = '';
            var digestPos = imageForParsing.indexOf('@sha256:');
            if (digestPos !== -1) {
                digestSuffix = '@' + imageForParsing.substring(digestPos + 1, digestPos + 20); // @sha256:xxxx (first 12 chars of digest)
                imageForParsing = imageForParsing.substring(0, digestPos);
            }

            // Now split by : for tag
            var imageParts = imageForParsing.split(':');
            var imageSource = imageParts[0] || ''; // Image name without tag
            var imageTag = (imageParts[1] || 'latest') + digestSuffix; // Include digest suffix if present
            var state = container.state || 'unknown';
            var containerId = (container.id || containerName).substring(0, 12);
            var uniqueId = 'ct-' + stackId + '-' + idx;

            // Status like Docker tab
            var shape = state === 'running' ? 'play' : (state === 'paused' ? 'pause' : 'square');
            var statusText = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');
            var color = state === 'running' ? 'green-text' : (state === 'paused' ? 'orange-text' : 'grey-text');
            var outerClass = state === 'running' ? 'started' : (state === 'paused' ? 'paused' : 'stopped');

            // Get networks and IPs
            var networkNames = [];
            var ipAddresses = [];
            if (container.networks && container.networks.length > 0) {
                container.networks.forEach(function(net) {
                    networkNames.push(net.name || '-');
                    ipAddresses.push(net.ip || '-');
                });
            }
            if (networkNames.length === 0) {
                networkNames.push('-');
                ipAddresses.push('-');
            }

            // Format ports - separate container ports and mapped ports
            var containerPorts = [];
            var lanPorts = [];
            if (container.ports && container.ports.length > 0) {
                container.ports.forEach(function(p) {
                    // Format: "192.168.1.10:8080->80/tcp" or "80/tcp"
                    var parts = p.split('->');
                    if (parts.length === 2) {
                        lanPorts.push(parts[0]);
                        containerPorts.push(parts[1]);
                    } else {
                        containerPorts.push(p);
                    }
                });
            }
            if (containerPorts.length === 0) containerPorts.push('-');
            if (lanPorts.length === 0) lanPorts.push('-');

            // WebUI
            // WebUI — already resolved server-side by exec.php
            var webui = '';
            if (container.webUI) {
                webui = container.webUI;
                if (!isValidWebUIUrl(webui)) webui = '';
            }

            html += '<tr data-container="' + escapeAttr(containerName) + '" data-state="' + escapeAttr(state) + '" data-stackid="' + escapeAttr(stackId) + '">';

            // Container name column - matches Docker tab exactly
            html += '<td class="ct-name">';
            html += '<span class="outer ' + outerClass + '">';
            var containerShell = container.shell || '/bin/sh';
            html += '<span id="' + uniqueId + '" class="hand" data-name="' + escapeAttr(containerName) + '" data-state="' + escapeAttr(state) + '" data-webui="' + escapeAttr(webui) + '" data-stackid="' + escapeAttr(stackId) + '" data-shell="' + escapeAttr(containerShell) + '">';
            // Use actual image like Docker tab - either container icon or default question.png
            var iconSrc = (container.icon && (isValidWebUIUrl(container.icon) || container.icon.startsWith('data:image/'))) ?
                container.icon :
                '/plugins/dynamix.docker.manager/images/question.png';
            html += '<img src="' + escapeAttr(iconSrc) + '" class="img" onerror="this.src=\'/plugins/dynamix.docker.manager/images/question.png\'">';
            html += '</span>';
            html += '<span class="inner"><span class="appname">' + escapeHtml(shortName) + '</span><br>';
            html += '<i class="fa fa-' + shape + ' ' + statusText + ' ' + color + '"></i><span class="state">' + statusText + '</span>';
            html += '</span></span>';
            html += '</td>';

            // Update column - shows update status for this container (like Docker tab)
            html += '<td class="ct-updatecolumn">';
            var ctHasUpdate = container.hasUpdate || false;
            var ctUpdateStatus = container.updateStatus || '';
            var ctLocalSha = container.localSha || '';
            var ctRemoteSha = container.remoteSha || '';
            var ctIsPinned = container.isPinned || false;
            var ctPinnedDigest = container.pinnedDigest || '';

            if (ctIsPinned) {
                // Image is pinned with SHA256 digest - show pinned status
                html += '<span class="cyan-text" style="white-space:nowrap;"><i class="fa fa-thumb-tack fa-fw"></i> pinned</span>';
                if (ctPinnedDigest) {
                    html += '<div style="font-family:monospace;font-size:0.85em;color:#17a2b8;margin-top:2px;">' + escapeHtml(ctPinnedDigest) + '</div>';
                }
            } else if (ctHasUpdate) {
                // Update available - orange "update ready" style with SHA diff
                html += '<a class="exec" style="cursor:pointer;" onclick="showUpdateWarning(\'' + escapeAttr(project) + '\', \'' + escapeAttr(stackId) + '\');">';
                html += '<span class="orange-text" style="white-space:nowrap;"><i class="fa fa-flash fa-fw"></i> update ready</span>';
                html += '</a>';
                if (ctLocalSha && ctRemoteSha) {
                    // Always show SHA diff (not just in advanced view)
                    html += '<div style="font-family:monospace;font-size:0.85em;margin-top:2px;">';
                    html += '<span style="color:#f80;" title="' + escapeAttr(ctLocalSha) + '">' + escapeHtml(ctLocalSha.substring(0, 8)) + '</span>';
                    html += ' <i class="fa fa-arrow-right" style="margin:0 4px;color:#3c3;"></i> ';
                    html += '<span style="color:#3c3;" title="' + escapeAttr(ctRemoteSha) + '">' + escapeHtml(ctRemoteSha.substring(0, 8)) + '</span>';
                    html += '</div>';
                }
            } else if (ctUpdateStatus === 'up-to-date') {
                // No update - green "up-to-date" style
                html += '<span class="green-text" style="white-space:nowrap;"><i class="fa fa-check fa-fw"></i> up-to-date</span>';
                if (ctLocalSha) {
                    // Show SHA in advanced view only for up-to-date containers (15 chars)
                    html += '<div class="cm-advanced" style="font-family:monospace;font-size:0.85em;color:#666;" title="' + escapeAttr(ctLocalSha) + '">' + escapeHtml(ctLocalSha.substring(0, 15)) + '</div>';
                }
            } else {
                // Unknown/not checked
                html += '<span style="white-space:nowrap;color:#888;"><i class="fa fa-question-circle fa-fw"></i> not checked</span>';
            }
            html += '</td>';

            // Source (image name without tag)
            html += '<td><span class="docker_readmore" style="color:#606060;">' + escapeHtml(imageSource) + '</span></td>';

            // Tag (image tag) — truncated with ellipsis via CSS if too long
            html += '<td class="ct-col-tag-cell"><span class="ct-tag" title="' + escapeAttr(imageTag) + '">' + escapeHtml(imageTag) + '</span></td>';

            // Network
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + networkNames.map(escapeHtml).join('<br>') + '</span></td>';

            // Container IP
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + ipAddresses.map(escapeHtml).join('<br>') + '</span></td>';

            // Container Port
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + containerPorts.map(escapeHtml).join('<br>') + '</span></td>';

            // LAN IP:Port
            html += '<td style="white-space:nowrap;"><span class="docker_readmore">' + lanPorts.map(escapeHtml).join('<br>') + '</span></td>';

            html += '</tr>';
        });

        html += '</tbody></table>';

        $container.html(html);

        // Update the parent stack row shortly after rendering so counts and status
        // reflect the latest state. Use a short timeout to avoid racing with other
        // DOM updates (e.g. a full list reload) that may remove the row.
        try {
            setTimeout(function() {
                try {
                    updateParentStackFromContainers(stackId, project);
                } catch (e) {
                    composeClientDebug('[renderContainerDetails] update-parent-failed', {
                        err: e.toString(),
                        stackId: stackId,
                        project: project
                    }, 'daemon', 'error');
                }
                // Mark that we just rendered so immediate subsequent update-driven
                // refreshes don't re-trigger another load (breaks the render -> update -> render loop)
                try {
                    stackDetailsJustRendered[stackId] = true;
                } catch (ex) {
                    composeClientDebug('[renderContainerDetails] set-just-rendered-failed', {
                        err: ex.toString(),
                        stackId: stackId,
                        project: project
                    }, 'daemon', 'error');
                }
                composeClientDebug('[renderContainerDetails] just-rendered', {
                    stackId: stackId,
                    project: project
                }, 'daemon', 'info');
                // Clear the flag after a short window
                setTimeout(function() {
                    try {
                        stackDetailsJustRendered[stackId] = false;
                    } catch (ex) {}
                }, 1000);
                // Clear loading flag now that render finished
                try {
                    stackDetailsLoading[stackId] = false;
                } catch (ex) {}
            }, 120);
        } catch (e) {}

        // Apply readmore to container details — destroy first to avoid nesting wrappers
        $container.find('.docker_readmore').readmore('destroy');
        $container.find('.docker_readmore').readmore({
            maxHeight: 32,
            moreLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-down'></i></a>",
            lessLink: "<a href='#' style='text-align:center'><i class='fa fa-chevron-up'></i></a>"
        });

        // Attach context menus to each container icon (like Docker tab)
        containers.forEach(function(container, idx) {
            var uniqueId = 'ct-' + stackId + '-' + idx;
            addComposeContainerContext(uniqueId);
        });

        // If this stack was queued for a compose-list reload (due to containerAction),
        // process it now: remove from pending list and reload the compose list so
        // the parent stack row (status icon, counts) is refreshed.
        // NOTE: avoid scheduling a parent-list reload here. Reloads should only be
        // queued from actions that change container state (e.g. containerAction()).
        // Scheduling a reload from a pure render path can cause repeated cycles
        // when the parent row update triggers re-renders. Container actions will
        // queue the stack reload explicitly.
    }

    // Build a condensed stackInfo object from the stackContainersCache for a stack
    function buildStackInfoFromCache(stackId, project) {
        var containers = stackContainersCache[stackId] || [];
        var definedServices = stackDefinedServicesCache[stackId] || containers.length;
        return createStackInfo(project, containers, { totalServices: definedServices });
    }

    // Update only the parent stack row using cached container details
    function updateParentStackFromContainers(stackId, project) {
        try {
            var $stackRow = $('#compose_stacks tr.compose-sortable[data-project="' + project + '"]');
            if ($stackRow.length === 0) {
                // If the row isn't present, fall back to a full reload
                composeLoadlist();
                return;
            }

            // Detect if an update check is currently in progress (spinner visible)
            var $updateCell = $stackRow.find('td.compose-updatecolumn');
            var isChecking = $updateCell.find('.fa-refresh.fa-spin').length > 0;

            // Update the update-column using existing helper (expects stackInfo)
            var stackInfo = buildStackInfoFromCache(stackId, project);
            // Merge any previously saved update status so we don't lose 'checked' state
            mergeStackUpdateStatus(stackInfo, stackUpdateStatus[project] || {});

            // Cache the merged update status and apply UI update
            stackUpdateStatus[project] = stackInfo;
            // Skip updating the update column if a check is currently in progress
            if (!isChecking) {
                updateStackUpdateUI(project, stackInfo);
            }

            // Update the stack row status icon and state text based on container states
            var $stateEl = $stackRow.find('.state');
            var origText = $stateEl.data('orig-text') || $stateEl.text();
            // Determine aggregated state
            var runningCount = stackInfo.containers.filter(function(c) {
                return c.isRunning;
            }).length;
            // Use totalServices (defined services) if available, otherwise fall back to actual container count
            var totalCount = stackInfo.totalServices || stackInfo.containers.length;
            var anyRunning = runningCount > 0;
            var anyPaused = stackInfo.containers.some(function(c) {
                return !c.isRunning && (c.updateStatus === 'paused' || c.updateStatus === 'paused');
            });
            var newState;
            // If some containers are running but not all, show 'partial' and include counts
            if (anyRunning && runningCount < totalCount) {
                newState = 'partial';
                $stateEl.text('partial (' + runningCount + '/' + totalCount + ')');
            } else {
                newState = anyRunning ? 'started' : (anyPaused ? 'paused' : 'stopped');
                $stateEl.text(newState);
            }
            composeClientDebug('[updateParentStackFromContainers] state', {
                project: project,
                newState: newState,
                runningCount: runningCount,
                totalCount: totalCount
            }, 'daemon', 'debug');

            // Update the containers count cell (3rd column) to reflect cached values
            try {
                var $containersCell = $stackRow.find('td').eq(2);
                var containersClass = (runningCount == totalCount && runningCount > 0) ? 'green-text' : (runningCount > 0 ? 'orange-text' : 'grey-text');
                $containersCell.html('<span class="' + containersClass + '">' + runningCount + ' / ' + totalCount + '</span>');
            } catch (e) {}

            // Update the status icon to match the new state and color
            var $icon = $stackRow.find('.compose-status-icon');
            if ($icon.length) {
                var shape = newState === 'started' ? 'play' : (newState === 'paused' ? 'pause' : (newState === 'partial' ? 'exclamation-circle' : 'square'));
                var colorClass = newState === 'started' ? 'green-text' : (newState === 'paused' || newState === 'partial' ? 'orange-text' : 'grey-text');

                // Debug: record classes before we touch the icon
                composeClientDebug('[updateParentStackFromContainers] icon-before-classes', {
                    project: project,
                    classes: $icon.attr('class'),
                    origClass: $icon.data('orig-class')
                }, 'daemon', 'debug');

                // Remove spinner / temporary classes and any previous fa-<name> classes
                $icon.removeClass('fa-refresh fa-spin compose-status-spinner');
                // Use a regex that matches the full fa-<name> (including hyphens) to ensure
                // icons like fa-exclamation-circle are removed completely.
                $icon.removeClass(function(i, cls) {
                    return (cls.match(/fa-[^\s]+/g) || []).join(' ');
                });

                // Debug: record classes after removal
                composeClientDebug('[updateParentStackFromContainers] icon-after-removal', {
                    project: project,
                    classes: $icon.attr('class')
                }, 'daemon', 'debug');

                // Remove any previous color classes
                $icon.removeClass('green-text orange-text grey-text cyan-text');

                // Apply the new shape and color
                $icon.addClass('fa fa-' + shape + ' ' + colorClass + ' compose-status-icon');
                // Debug: report final classes for diagnostic purposes
                composeClientDebug('[updateParentStackFromContainers] icon-classes', {
                    project: project,
                    classes: $icon.attr('class')
                }, 'daemon', 'debug');
                // Clear any saved orig-class since we've now applied the new state
                if ($icon.data('orig-class')) {
                    $icon.removeData('orig-class');
                }
            }

            // Re-apply view mode (advanced/basic) to ensure column content visibility
            applyListView();
        } catch (e) {
            composeClientDebug('[updateParentStackFromContainers] error', {
                err: e.toString(),
                stackId: stackId,
                project: project
            }, 'daemon', 'error');
            // If anything goes wrong, fallback to full reload
            composeLoadlist();
        }
    }

    // Attach context menu to container icon (like Docker tab's addDockerContainerContext)
    function addComposeContainerContext(elementId) {
        var $el = $('#' + elementId);
        var containerName = $el.data('name');
        var state = $el.data('state');
        var webui = $el.data('webui');
        var stackId = $el.data('stackid');
        var shell = $el.data('shell') || '/bin/bash';
        var running = state === 'running';
        var paused = state === 'paused';

        var opts = [];
        context.settings({
            right: false,
            above: false
        });

        // WebUI (if running)
        if (running && webui) {
            opts.push({
                text: 'WebUI',
                icon: 'fa-globe',
                action: function(e) {
                    e.preventDefault();
                    window.open(webui, '_blank');
                }
            });
            opts.push({
                divider: true
            });
        }

        // Console (if running) — start writable ttyd, open in new window
        if (running) {
            opts.push({
                text: 'Console',
                icon: 'fa-terminal',
                action: function(e) {
                    e.preventDefault();
                    $.post(compURL, {
                        action: 'containerConsole',
                        container: containerName,
                        shell: shell
                    }, function(data) {
                        if (data) {
                            var height = Math.min(screen.availHeight, 800);
                            var width = Math.min(screen.availWidth, 1200);
                            window.open(data, 'Console_' + containerName.replace(/[^a-zA-Z0-9]/g, '_'),
                                'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
                        }
                    });
                }
            });
            opts.push({
                divider: true
            });
        }

        // Start/Stop/Pause/Resume
        if (running) {
            opts.push({
                text: 'Stop',
                icon: 'fa-stop',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'stop', stackId);
                }
            });
            opts.push({
                text: 'Pause',
                icon: 'fa-pause',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'pause', stackId);
                }
            });
            opts.push({
                text: 'Restart',
                icon: 'fa-refresh',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'restart', stackId);
                }
            });
        } else if (paused) {
            opts.push({
                text: 'Resume',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'unpause', stackId);
                }
            });
        } else {
            opts.push({
                text: 'Start',
                icon: 'fa-play',
                action: function(e) {
                    e.preventDefault();
                    containerAction(containerName, 'start', stackId);
                }
            });
        }

        opts.push({
            divider: true
        });

        // Logs — start ttyd via plugin, open in new window (same as stack logs)
        opts.push({
            text: 'Logs',
            icon: 'fa-navicon',
            action: function(e) {
                e.preventDefault();
                $.post(compURL, {
                    action: 'containerLogs',
                    container: containerName
                }, function(data) {
                    if (data) {
                        var height = Math.min(screen.availHeight, 800);
                        var width = Math.min(screen.availWidth, 1200);
                        window.open(data, 'Logs_' + containerName.replace(/[^a-zA-Z0-9]/g, '_'),
                            'height=' + height + ',width=' + width + ',resizable=yes,scrollbars=yes');
                    }
                });
            }
        });

        context.attach('#' + elementId, opts);
    }

    function containerAction(containerName, action, stackId) {
        // Show spinner by replacing the status icon (play/stop) in-place
        var $iconWrap = $('[data-name="' + containerName + '"]').first();
        // The status icon lives in the sibling '.inner' span next to the '.hand' wrapper
        var $statusIcon = $iconWrap.closest('td').find('.inner i').first();
        var statusOrigClass = null;
        var __spinnerInserted = false;
        // Also preserve/modify the state text (e.g. 'starting', 'stopping') while action runs
        var $stateTextEl = $iconWrap.closest('td').find('.inner .state').first();
        var stateOrigText = null;
        var actionStatusTextMap = {
            start: 'starting',
            stop: 'stopping',
            restart: 'restarting',
            pause: 'pausing',
            unpause: 'resuming'
        };
        var actionStatusText = actionStatusTextMap[action] || 'working';
        if ($statusIcon.length) {
            try {
                statusOrigClass = $statusIcon.attr('class') || '';
                $statusIcon.attr('data-orig-class', statusOrigClass);
                $statusIcon.removeClass().addClass('fa fa-refresh fa-spin compose-status-spinner');
                __spinnerInserted = true;
            } catch (e) {
                __spinnerInserted = false;
            }
        } else {
            // Fallback to previous behavior: if there's an <i> or <img> inside the hand, use that
            var $icon = $iconWrap.find('i,img').first();
            var originalClass = $icon.attr('class');
            if ($icon.is('i')) {
                $icon.removeClass().addClass('fa fa-refresh fa-spin');
                __spinnerInserted = true;
            } else if ($icon.is('img')) {
                // As a last resort, overlay a spinner on the image (should be rare now)
                try {
                    $iconWrap.css('position', 'relative');
                    $icon.css('opacity', 0.35);
                    $iconWrap.append('<i class="fa fa-refresh fa-spin compose-container-spinner" aria-hidden="true"></i>');
                    __spinnerInserted = true;
                } catch (e) {
                    __spinnerInserted = false;
                }
            }
        }

        // If we inserted a spinner, set temporary state text and save original so we can restore on failure
        if (__spinnerInserted && $stateTextEl.length) {
            try {
                stateOrigText = $stateTextEl.text();
                $stateTextEl.attr('data-orig-text', stateOrigText);
                $stateTextEl.text(actionStatusText);
                composeClientDebug('[containerAction] set-status', {
                    container: containerName,
                    action: action,
                    stackId: stackId,
                    statusText: actionStatusText
                }, 'daemon', 'info');
            } catch (e) {}
        }

        $.post(caURL, {
            action: 'containerAction',
            container: containerName,
            containerAction: action
        }, function(data) {
            if (data) {
                var response = JSON.parse(data);
                if (response.result === 'success') {
                    // Refresh the container details
                    // Also mark the parent stack for a compose-list reload so the stack-level
                    // status (play/stop icon, running count) is refreshed after the container action.
                    try {
                        var project = $('#stack-row-' + stackId).data('project');
                        if (project) {
                            if (pendingComposeReloadStacks.indexOf(project) === -1) pendingComposeReloadStacks.push(project);
                            composeClientDebug('[containerAction] queued-stack-reload', {
                                container: containerName,
                                action: action,
                                stack: project,
                                pending: pendingComposeReloadStacks.slice()
                            }, 'daemon', 'info');
                            // Show per-stack spinner immediately
                            try {
                                setStackActionInProgress(project, true);
                            } catch (e) {}
                        }
                    } catch (e) {}

                    // Refresh the container details after a short delay to let docker settle
                    setTimeout(function() {
                        var project = $('#stack-row-' + stackId).data('project');
                        loadStackContainerDetails(stackId, project);
                    }, 1000);
                    // Schedule a debounced parent-row update so multiple signals collapse
                    try {
                        schedulePendingComposeReloads(1200);
                    } catch (e) {}
                    // Also refresh Unraid's Docker containers widget
                    if (typeof window.loadlist === 'function') {
                        setTimeout(function() {
                            window.loadlist();
                        }, 1500);
                    }
                } else {
                    // Restore status icon or remove overlay spinner
                    if (__spinnerInserted) {
                        // Restore status icon if we replaced it
                        var $restStatus = $iconWrap.closest('td').find('.inner i').first();
                        if ($restStatus.length && $restStatus.attr('data-orig-class')) {
                            $restStatus.removeClass().addClass($restStatus.attr('data-orig-class'));
                            $restStatus.removeAttr('data-orig-class');
                        } else {
                            // Fallback: restore any overlay on the image
                            try {
                                $icon.css('opacity', 1);
                                $iconWrap.find('.compose-container-spinner').remove();
                            } catch (e) {}
                        }
                        // Restore state text if we changed it
                        try {
                            var $stateEl = $iconWrap.closest('td').find('.inner .state').first();
                            if ($stateEl.length && $stateEl.attr('data-orig-text')) {
                                $stateEl.text($stateEl.attr('data-orig-text'));
                                $stateEl.removeAttr('data-orig-text');
                            }
                        } catch (e) {}
                    }
                    swal({
                        title: 'Action Failed',
                        text: escapeHtml(response.message) || 'Failed to ' + action + ' container',
                        type: 'error'
                    });
                }
            }
        }).fail(function() {
            // Restore status icon or remove overlay spinner
            if (__spinnerInserted) {
                var $restStatus = $iconWrap.closest('td').find('.inner i').first();
                if ($restStatus.length && $restStatus.attr('data-orig-class')) {
                    $restStatus.removeClass().addClass($restStatus.attr('data-orig-class'));
                    $restStatus.removeAttr('data-orig-class');
                } else {
                    try {
                        $icon.css('opacity', 1);
                        $iconWrap.find('.compose-container-spinner').remove();
                    } catch (e) {}
                }
                // Restore state text if we changed it
                try {
                    var $stateEl = $iconWrap.closest('td').find('.inner .state').first();
                    if ($stateEl.length && $stateEl.attr('data-orig-text')) {
                        $stateEl.text($stateEl.attr('data-orig-text'));
                        $stateEl.removeAttr('data-orig-text');
                    }
                } catch (e) {}
            }
            swal({
                title: 'Action Failed',
                text: 'Failed to ' + action + ' container',
                type: 'error'
            });
        });
    }

    // Attach context menu to stack icon (like Docker tab's container context menu)
    function addComposeStackContext(elementId) {
        var $el = $('#' + elementId);
        var stackId = $el.data('stackid');
        var project = $el.data('project');
        var projectName = $el.data('projectname');
        var isUp = $el.data('isup') == "1";
        var running = parseInt($el.data('running') || 0);

        var $row = $('#stack-row-' + stackId);
        var path = $row.data('path');
        var profiles = $row.data('profiles') || [];
        var webuiUrl = $row.data('webui') || '';

        // Check if updates are available for this stack
        var hasUpdates = false;
        if (stackUpdateStatus[project] && stackUpdateStatus[project].hasUpdate) {
            hasUpdates = true;
        }

        var opts = [];
        context.settings({
            right: false,
            above: false
        });

        // WebUI link (if configured and stack is running)
        if (webuiUrl && isUp) {
            opts.push({
                text: 'WebUI',
                icon: 'fa-globe',
                action: function(e) {
                    e.preventDefault();
                    var url = processWebUIUrl(webuiUrl);
                    if (isValidWebUIUrl(url)) {
                        window.open(url, '_blank');
                    }
                }
            });
            opts.push({
                divider: true
            });
        }

        // Compose Up
        opts.push({
            text: isUp ? 'Compose Up (Recreate)' : 'Compose Up',
            icon: 'fa-play',
            action: function(e) {
                e.preventDefault();
                if (profiles.length > 0) {
                    showProfileSelector('up', path, profiles);
                } else {
                    ComposeUp(path);
                }
            }
        });

        // Compose Down (only if up)
        if (isUp) {
            opts.push({
                text: 'Compose Down',
                icon: 'fa-stop',
                action: function(e) {
                    e.preventDefault();
                    if (profiles.length > 0) {
                        showProfileSelector('down', path, profiles);
                    } else {
                        ComposeDown(path);
                    }
                }
            });
        }

        opts.push({
            divider: true
        });

        // Update Stack - disabled if no updates available
        var updateText = hasUpdates ? 'Update Stack' : 'Update Stack (no updates)';
        opts.push({
            text: updateText,
            icon: 'fa-cloud-download',
            disabled: !hasUpdates,
            action: function(e) {
                e.preventDefault();
                if (!hasUpdates) return;
                if (profiles.length > 0) {
                    showProfileSelector('update', path, profiles);
                } else {
                    UpdateStack(path);
                }
            }
        });

        opts.push({
            divider: true
        });

        // Edit Stack
        opts.push({
            text: 'Edit Stack',
            icon: 'fa-edit',
            action: function(e) {
                e.preventDefault();
                openEditorModalByProject(project, projectName);
            }
        });

        opts.push({
            divider: true
        });

        // View Logs
        opts.push({
            text: 'View Logs',
            icon: 'fa-navicon',
            action: function(e) {
                e.preventDefault();
                ComposeLogs(project);
            }
        });

        opts.push({
            divider: true
        });

        // Delete Stack (only if not running)
        if (!isUp) {
            opts.push({
                text: 'Delete Stack',
                icon: 'fa-trash',
                action: function(e) {
                    e.preventDefault();
                    deleteStackByProject(project, projectName);
                }
            });
        } else {
            opts.push({
                text: 'Delete Stack (Stop first)',
                icon: 'fa-trash',
                disabled: true
            });
        }

        context.attach('#' + elementId, opts);
    }

    // Event delegation for docker-style container actions
    $(document).on('click', '.docker-action[data-action]', function(e) {
        e.preventDefault();
        var $action = $(this);
        var $row = $action.closest('.docker-row');
        var containerName = $row.data('container');
        var action = $action.data('action');
        if (containerName && action) {
            containerAction(containerName, action);
        }
    });

    // Row click handler - expand/collapse stack details
    $(document).on('click', 'tr.compose-sortable[id^="stack-row-"]', function(e) {
        var $target = $(e.target);

        // Don't expand if clicking on interactive elements
        if ($target.closest('[data-stackid]').length || // Stack icon (context menu)
            $target.closest('.expand-icon').length || // Expand arrow
            $target.closest('.compose-updatecolumn a').length || // Update links
            $target.closest('.compose-updatecolumn .exec').length || // Update actions
            $target.closest('.auto_start').length || // Autostart toggle
            $target.closest('.switchButton').length || // Switch button wrapper
            $target.closest('a').length || // Any link
            $target.closest('button').length || // Any button
            $target.closest('input').length) { // Any input
            return;
        }

        var stackId = this.id.replace('stack-row-', '');
        if (stackId) {
            toggleStackDetails(stackId);
        }
    });

    // Close actions menu when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#stack-actions-modal, .stack-kebab-btn').length) {
            closeStackActionsMenu();
        }
    });

    // Close actions menu on escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closeStackActionsMenu();
        }
    });

    // Event delegation for container refresh button
    $(document).on('click', '.container-refresh-btn[data-stack-id]', function(e) {
        e.preventDefault();
        var stackId = $(this).data('stack-id');
        var project = $('#stack-row-' + stackId).data('project');
        if (stackId && project) {
            loadStackContainerDetails(stackId, project);
        }
    });

    // Keyboard support for expand toggle (Enter/Space)
    $(document).on('keydown', '.stack-expand-toggle', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).click();
        }
    });
</script>

<HTML>

<HEAD>
    <style type="text/css">
        .edit-stack-form .swal-footer {
            display: table;
            margin-left: auto;
            margin-right: auto;
        }

        .edit-stack-form .swal-footer .swal-button-container {
            display: table-row;
        }

        .edit-stack-form .swal-footer .swal-button-container .swal-button {
            width: 150px;
        }
    </style>
</HEAD>

<BODY>

    <span class='tipsterallowed' hidden></span>
    <div class="TableContainer">
        <table id="compose_stacks" class="tablesorter shift" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th class="col-stack">Stack</th>
                    <th class="col-update">Update</th>
                    <th class="col-containers">Containers</th>
                    <th class="col-uptime">Uptime</th>
                    <th class="cm-advanced col-description">Description</th>
                    <th class="cm-advanced col-path">Path</th>
                    <th class="nine col-autostart">Autostart</th>
                </tr>
            </thead>
            <tbody id="compose_list">
                <tr>
                    <td colspan='7'></td>
                </tr>
            </tbody>
        </table>
    </div>
    <span class='tipsterallowed' hidden>
        <input type='button' value='Add New Stack' onclick='addStack();'>
        <input type='button' value='Start All' onclick='startAllStacks();' id='startAllBtn'>
        <input type='button' value='Stop All' onclick='stopAllStacks();' id='stopAllBtn'>
        <input type='button' value='Check for Updates' onclick='checkAllUpdates();' id='checkUpdatesBtn'>
        <input type='button' value='Update All' onclick='updateAllStacks();' id='updateAllBtn' disabled>
        <label style='margin-left:10px;cursor:pointer;vertical-align:middle;' title='When enabled, only stacks with Autostart enabled will be affected'>
            <input type='checkbox' id='autostartOnlyToggle' style='vertical-align:middle;'>
            <span style='vertical-align:middle;'>Autostart only</span>
        </label>
        <a href='/Settings/compose.manager.settings' style='margin-left:20px;'><input type='button' value='Settings'></a>
    </span><br>

    <!-- Stack Actions Modal -->
    <div id="stack-actions-modal" class="stack-actions-modal" style="display:none;">
        <div class="stack-actions-modal-header">
            <span class="stack-actions-modal-title">Stack Actions</span>
            <button class="stack-actions-modal-close" onclick="closeStackActionsMenu();">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="stack-actions-modal-body">
            <button class="stack-action-item" onclick="executeStackAction('up');">
                <i class="fa fa-play"></i> Compose Up
            </button>
            <button class="stack-action-item" onclick="executeStackAction('down');">
                <i class="fa fa-stop"></i> Compose Down
            </button>
            <button class="stack-action-item" onclick="executeStackAction('update');">
                <i class="fa fa-refresh"></i> Update Stack
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('logs');">
                <i class="fa fa-file-text-o"></i> View Logs
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('editFiles');">
                <i class="fa fa-edit"></i> Edit Files
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editName');">
                <i class="fa fa-pencil"></i> Edit Name
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editDesc');">
                <i class="fa fa-align-left"></i> Edit Description
            </button>
            <button class="stack-action-item" onclick="executeStackAction('uiLabels');">
                <i class="fa fa-tags"></i> UI Labels
            </button>
            <button class="stack-action-item" onclick="executeStackAction('settings');">
                <i class="fa fa-cog"></i> Stack Settings
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item stack-action-danger" onclick="executeStackAction('delete');">
                <i class="fa fa-trash"></i> Delete Stack
            </button>
        </div>
    </div>
    <div id="stack-actions-overlay" class="stack-actions-overlay" onclick="closeStackActionsMenu();" style="display:none;"></div>

    <!-- Editor Modal -->
    <div id="editor-modal-overlay" class="editor-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editor-modal-title">
        <div class="editor-modal">
            <!-- Modal Header -->
            <div class="editor-modal-header">
                <h2 class="editor-modal-title" id="editor-modal-title">Edit Stack</h2>
                <button class="editor-modal-close" onclick="closeEditorModal()" aria-label="Close editor modal">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <!-- Unified Tab Bar -->
            <div class="editor-tabs" role="tablist">
                <button class="editor-tab active" id="editor-tab-compose" onclick="switchTab('compose')" role="tab" aria-selected="true" aria-controls="editor-panel-compose">
                    <i class="fa fa-file-code-o" aria-hidden="true"></i>
                    Compose
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-env" onclick="switchTab('env')" role="tab" aria-selected="false" aria-controls="editor-panel-env">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                    .env
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-labels" onclick="switchTab('labels')" role="tab" aria-selected="false" aria-controls="editor-panel-labels">
                    <i class="fa fa-tags" aria-hidden="true"></i>
                    WebUI Labels
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-settings" onclick="switchTab('settings')" role="tab" aria-selected="false" aria-controls="editor-panel-settings">
                    <i class="fa fa-sliders" aria-hidden="true"></i>
                    Settings
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
            </div>

            <!-- ========== COMPOSE EDITOR PANEL ========== -->
            <div class="editor-panel active" id="editor-panel-compose" role="tabpanel" aria-labelledby="editor-tab-compose">
                <div class="editor-modal-body">
                    <div class="editor-container active" id="editor-container-compose">
                        <div id="editor-compose" style="width: 100%; height: 100%;"></div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-compose">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== ENV EDITOR PANEL ========== -->
            <div class="editor-panel" id="editor-panel-env" role="tabpanel" aria-labelledby="editor-tab-env">
                <div class="editor-modal-body">
                    <div class="editor-container active" id="editor-container-env">
                        <div id="editor-env" style="width: 100%; height: 100%;"></div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-env">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== WEBUI LABELS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-labels" role="tabpanel" aria-labelledby="editor-tab-labels">
                <div class="labels-panel">
                    <div class="labels-panel-header">
                        <p>Configure icons, WebUI links, and shell commands for each service. These labels integrate your containers with the unRAID Docker UI.</p>
                    </div>
                    <div id="labels-services-container">
                        <div class="labels-empty-state">
                            <i class="fa fa-spinner fa-spin"></i>
                            Loading services...
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== SETTINGS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-settings" role="tabpanel" aria-labelledby="editor-tab-settings">
                <div class="settings-panel">
                    <!-- Stack Identity -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-info-circle"></i> Stack Identity</div>

                        <div class="settings-field">
                            <label for="settings-name">Stack Name</label>
                            <input type="text" id="settings-name" placeholder="Enter stack name">
                            <div class="settings-field-help">Display name shown in the UI. Does not affect the project folder name.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-description">Description</label>
                            <textarea id="settings-description" placeholder="Enter description for this stack"></textarea>
                            <div class="settings-field-help">Brief description of what this stack does.</div>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-picture-o"></i> Appearance</div>

                        <div class="settings-field">
                            <label for="settings-icon-url">Icon URL</label>
                            <input type="url" id="settings-icon-url" placeholder="https://example.com/icon.png">
                            <div class="settings-field-help">URL to a custom icon for this stack. Leave empty to use the default icon.</div>
                            <div class="settings-field-icon-preview" id="settings-icon-preview" style="display:none;">
                                <span>Preview:</span>
                                <img id="settings-icon-preview-img" src="" alt="Icon preview" onerror="this.parentElement.style.display='none';">
                            </div>
                        </div>
                    </div>

                    <!-- Stack WebUI -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-globe"></i> Stack WebUI</div>

                        <div class="settings-field">
                            <label for="settings-webui-url">WebUI URL</label>
                            <input type="url" id="settings-webui-url" placeholder="http://[IP]:[PORT]:8080">
                            <div class="settings-field-help">URL to the main WebUI for this stack. This adds a "WebUI" option to the stack's context menu. Supports [IP] and [PORT] placeholders for dynamic replacement.</div>
                        </div>
                    </div>

                    <!-- Advanced -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-sliders"></i> Advanced</div>

                        <div class="settings-field">
                            <label for="settings-external-compose-path">External Compose Path</label>
                            <input type="text" id="settings-external-compose-path" placeholder="Default (uses compose file in project folder)">
                            <div class="settings-field-help">Path to an external folder containing your compose file(s) (e.g., /mnt/user/appdata/myapp/). The folder must contain a file matching *compose*.yml. Leave empty to use the compose file stored in the project folder.</div>
                            <div id="settings-external-compose-info" style="margin-top:8px;display:none;">
                                <span style="color:#c80;font-size:0.9em;"><i class="fa fa-info-circle"></i> This stack uses an external compose file. The Compose editor tab will load the file from the external path.</span>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-env-path">External ENV File Path</label>
                            <input type="text" id="settings-env-path" placeholder="Default (uses .env in project folder)">
                            <div class="settings-field-help">Path to an external .env file (e.g., /mnt/user/appdata/myapp/.env). Leave empty to use the default .env file in the project folder.</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-default-profile">Default Profile(s)</label>
                            <input type="text" id="settings-default-profile" placeholder="Leave empty for all services">
                            <div class="settings-field-help">
                                Comma-separated list of profiles to use by default for Autostart and multi-stack operations (e.g., "production,monitoring").
                                <br>Leave empty to start all services. Available profiles are auto-detected from your compose file.
                            </div>
                            <div id="settings-available-profiles" style="margin-top:8px;display:none;">
                                <span style="color:#888;font-size:0.9em;">Available profiles: </span>
                                <span id="settings-profiles-list" style="font-family:monospace;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="editor-modal-footer">
                <div class="editor-footer-left">
                    <span class="editor-file-info" id="editor-file-info"></span>
                    <span class="editor-shortcuts">
                        <kbd>Ctrl+S</kbd> Save &nbsp; <kbd>Esc</kbd> Close
                    </span>
                </div>
                <div class="editor-footer-right">
                    <button class="editor-btn editor-btn-cancel" onclick="closeEditorModal()">Cancel</button>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-save-all" onclick="saveAllChanges()" disabled>Save All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize editor modal after DOM is fully loaded
        $(function() {
            try {
                updateModalOffset();
                $(window).on('resize', updateModalOffset);
                initEditorModal();
            } catch (e) {
                console.warn('Compose Manager: editor init error (non-fatal):', e);
            }
        });

        // Reorder Compose section above Docker Containers if configured
        // Runs in its own $(function) to avoid being blocked by editor init errors
        $(function() {
            (function reorderComposeAboveDocker() {
                if (!showComposeOnTop) return;
                // In tabbed mode the sections live in separate tabs; reordering is not applicable
                if ($('.tabs').length) return;

                var $content = $('div.content');
                if (!$content.length) return;

                // Locate the two title divs by their text content
                // Use find() with a filter instead of children() to handle any nesting edge-cases
                var $dockerTitle = null;
                var $composeTitle = null;
                $content.children('div.title').each(function() {
                    var txt = $(this).text().trim();
                    if (!$dockerTitle && /Docker\s*Containers/i.test(txt)) $dockerTitle = $(this);
                    if (!$composeTitle && /Compose/i.test(txt) && !/Docker/i.test(txt)) $composeTitle = $(this);
                });

                if (!$dockerTitle || !$composeTitle) {
                    console.warn('Compose Manager: reorder skipped — dockerTitle:', !!$dockerTitle, 'composeTitle:', !!$composeTitle);
                    return;
                }

                // Collect all nodes from the Compose title to the end of .content
                var composeNodes = [];
                var found = false;
                $content.contents().each(function() {
                    if (this === $composeTitle[0]) found = true;
                    if (found) composeNodes.push(this);
                });

                // Move them before the Docker title
                composeNodes.forEach(function(node) {
                    $content[0].insertBefore(node, $dockerTitle[0]);
                });
            })();
        });

        // Hide compose-managed containers from Docker Containers table if configured
        // Runs in its own $(function) to avoid being blocked by other init errors
        $(function() {
            (function hideComposeContainersFromDocker() {
                if (!hideComposeFromDocker) return;
                // In tabbed mode this doesn't make sense
                if ($('.tabs').length) return;

                function getComposeContainerNames() {
                    var names = {};
                    // Primary source: data-containers attribute on stack rows
                    // (populated by PHP at list-load time — always available)
                    $('#compose_stacks .compose-sortable[data-containers]').each(function() {
                        try {
                            var list = JSON.parse($(this).attr('data-containers') || '[]');
                            for (var i = 0; i < list.length; i++) {
                                if (list[i]) names[list[i].toLowerCase()] = true;
                            }
                        } catch (e) {}
                    });
                    // Fallback: stack detail rows (if any stacks have been expanded)
                    $('#compose_stacks .stack-details-row').each(function() {
                        $(this).find('tr[data-container]').each(function() {
                            var name = $(this).attr('data-container');
                            if (name) names[name.toLowerCase()] = true;
                        });
                    });
                    // Fallback: stackUpdateStatus (populated after update checks)
                    if (typeof stackUpdateStatus !== 'undefined') {
                        for (var stackName in stackUpdateStatus) {
                            var info = stackUpdateStatus[stackName];
                            if (info.containers) {
                                for (var i = 0; i < info.containers.length; i++) {
                                    var n = info.containers[i].name;
                                    if (n) names[n.toLowerCase()] = true;
                                }
                            }
                        }
                    }
                    return names;
                }

                function doHide() {
                    var $dockerTable = $('#docker_list');
                    if (!$dockerTable.length) return;

                    var composeNames = getComposeContainerNames();
                    if (Object.keys(composeNames).length === 0) return;

                    $dockerTable.find('tr.sortable').each(function() {
                        var $row = $(this);
                        // Use a broad selector — just find the appname span anywhere in the first cell
                        var rowName = $row.find('td:first span.appname').first().text().trim();
                        if (!rowName) rowName = $row.find('td:first').text().trim();

                        if (composeNames[rowName.toLowerCase()]) {
                            $row.hide();
                            // Also hide associated child/readmore rows
                            $row.nextUntil('tr.sortable').hide();
                        }
                    });
                }

                // Re-run whenever compose list reloads (event fired by composeLoadlist)
                $(document).on('compose-list-loaded', doHide);

                // Watch for Docker table changes (rows load asynchronously via AJAX)
                // Use polling until #docker_list exists, then attach MutationObserver
                function attachDockerObserver() {
                    var dockerTable = document.getElementById('docker_list');
                    if (dockerTable) {
                        var obs = new MutationObserver(function() {
                            setTimeout(doHide, 300);
                        });
                        obs.observe(dockerTable, {
                            childList: true,
                            subtree: true
                        });
                        // Initial run now that docker table exists
                        setTimeout(doHide, 500);
                    } else {
                        // docker_list not in DOM yet — retry
                        setTimeout(attachDockerObserver, 500);
                    }
                }
                attachDockerObserver();

                // Also run after a generous delay as final fallback
                setTimeout(doHide, 4000);
            })();
        });
    </script>

</BODY>

</HTML>