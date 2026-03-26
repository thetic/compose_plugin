<?php
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");

// Allow callers to override the socket name via query parameter
// (used by per-container console/logs). Sanitise to alphanumeric + _ and -.
$active_socket = $socket_name;
if (!empty($_GET['socket'])) {
    $active_socket = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['socket']);
}
$showDone = !empty($_GET['done']);

$url = "/logterminal/$active_socket/";

$version = parse_ini_file("/etc/unraid-version");
if (version_compare($version['version'], "6.10.0", "<")) {
    $url = "/dockerterminal/$socket_name/";
}
?>
<!DOCTYPE html>
<html style="height:100%;margin:0;padding:0;background:var(--background-color, var(--dynamix-sb-body-bg-color))">

<head>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background: var(--background-color, var(--dynamix-sb-body-bg-color));
            box-sizing: border-box
        }

        body {
            display: flex;
            flex-direction: column
        }

        #ttyd-frame {
            flex: 1;
            border: none;
            width: 100%;
            display: block
        }

        p.centered {
            text-align: center;
            padding: 10px 0;
            margin: 0;
            background: var(--background-color, var(--dynamix-sb-body-bg-color));
            flex-shrink: 0
        }

        p.centered button {
            margin: 0
        }

        input[type=button],
        input[type=reset],
        input[type=submit],
        button,
        button[type=button],
        a.button {
            font-family: clear-sans;
            font-size: 1.1rem;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 9px 18px;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            outline: none;
            border-radius: 4px;
            border: 0;
            color: var(--dynamix-jquery-ui-button-text-color);
            background: linear-gradient(90deg, var(--dynamix-jquery-ui-button-background-start) 0, var(--dynamix-jquery-ui-button-background-end));
            background-size: 100% 2px, 100% 2px, 2px 100%, 2px 100%
        }

        input:hover[type=button],
        input:hover[type=reset],
        input:hover[type=submit],
        button:hover,
        button:hover[type=button],
        a.button:hover {
            color: var(--dynamix-sb-message-text-color);
            background: linear-gradient(90deg, var(--dynamix-jquery-ui-button-background-start) 0, var(--dynamix-jquery-ui-button-background-end));
        }
    </style>
</head>

<body style="background:var(--dynamix-sb-body-bg-color)">
    <iframe id="ttyd-frame" src="<?= $url ?>"></iframe>
    <?php if ($showDone): ?>
        <p class="centered"><button class="logLine" type="button" id="done-btn">Done</button></p>
    <?php endif; ?>
    <script>
        <?php if ($showDone): ?>
            // Done button: close Shadowbox (if inside one) or close the window
            document.getElementById('done-btn').addEventListener('click', function() {
                try {
                    top.Shadowbox.close();
                } catch (e) {}
                try {
                    window.close();
                } catch (e) {}
            });
        <?php endif; ?>

        // Aggressively suppress "Leave Site?" prompt from ttyd's beforeunload handler.
        // ttyd sets window.onbeforeunload after its JS loads, so we must continuously
        // override it using an interval + Object.defineProperty on the iframe window.
        function suppressBeforeUnload(win) {
            try {
                // Prevent ttyd from setting onbeforeunload by making it a no-op property
                Object.defineProperty(win, 'onbeforeunload', {
                    get: function() {
                        return null;
                    },
                    set: function() {
                        /* swallow ttyd's assignment */ },
                    configurable: true
                });
                // Also catch addEventListener-based handlers
                var origAdd = win.addEventListener.bind(win);
                win.addEventListener = function(type, fn, opts) {
                    if (type === 'beforeunload') return;
                    return origAdd(type, fn, opts);
                };
            } catch (e) {}
        }

        // Apply to parent window
        suppressBeforeUnload(window);

        // Darken ALL Shadowbox container elements so no white peeks through.
        // Uses CSS injection with !important to override Shadowbox's own styles.
        try {
            var doc = top.document;
            if (!doc.getElementById('compose-sb-dark')) {
                var s = doc.createElement('style');
                s.id = 'compose-sb-dark';
                s.textContent = '#sb-body,#sb-body-inner,#sb-player,#sb-info,#sb-info-inner,#sb-loading,#sb-wrapper-inner{background:var(--dynamix-sb-body-bg-color) !important;border-color:var(--dynamix-box-inner-div-border-color) !important}';
                doc.head.appendChild(s);
            }
        } catch (e) {}

        // Apply inside iframe once loaded (same-origin)
        var frame = document.getElementById('ttyd-frame');
        frame.addEventListener('load', function() {
            try {
                suppressBeforeUnload(frame.contentWindow);
                // Inject scrollbar styling
                var fdoc = frame.contentDocument || frame.contentWindow.document;
                var s = fdoc.createElement('style');
                s.textContent = '::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:var(--dynamix-box-inner-div-border-color);border-radius:4px}::-webkit-scrollbar-thumb{background:var(--dynamix-box-text-color);border-radius:4px}::-webkit-scrollbar-thumb:hover{background:var(--dynamix-sb-title-text-color)}';
                fdoc.head.appendChild(s);
                // Force ttyd to recalculate terminal dimensions (fixes initial right margin)
                setTimeout(function() {
                    frame.contentWindow.dispatchEvent(new Event('resize'));
                }, 200);
            } catch (e) {}
        });
    </script>
</body>

</html>
