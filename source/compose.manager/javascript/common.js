// Cached async config getter for common use across the module.
// Use `getConfig().then(cfg => { ... })` to access configuration.
var _configCache = null;
var _configPromise = null;
var caURL = "/plugins/compose.manager/php/exec.php";

function getConfig() {
    if (_configCache) return Promise.resolve(_configCache);
    if (_configPromise) return _configPromise;

    _configPromise = new Promise(function (resolve, reject) {
        $.post(caURL, { action: 'getConfig' }, function (response) {
            var resp = response;
            if (typeof resp === 'string') {
                try {
                    resp = JSON.parse(resp);
                } catch (e) {
                    _configPromise = null;
                    composeClientDebug('getConfig returned non-JSON response; using empty config', { response: response, error: e }, 'daemon', 'error');
                    resolve({});
                    return;
                }
            }

            if (resp && resp.result === 'success') {
                _configCache = resp.config;
                _configPromise = null;
                resolve(_configCache);
            } else {
                _configPromise = null;
                composeClientDebug('getConfig returned non-success response; using empty config', resp, 'daemon', 'error');
                resolve({});
            }
        }).fail(function () {
            _configPromise = null;
            composeClientDebug('Network error while fetching config; using empty config', null, 'daemon', 'error');
            resolve({});
        });
    });

    return _configPromise;
}

// Client-side debug helper: logs to console and posts short messages to server syslog
function composeClientDebug(msg, obj, type, lvl) {
    
    // Send lightweight debug message to server for persistence (non-blocking)
    try {
        var payload = {
            action: 'clientDebug',
            msg: msg,
            type: type || 'daemon',
            lvl: lvl || 'info'
        };
        if (obj !== undefined) payload.data = JSON.stringify(obj);
        // Fire-and-forget; no UI impact
        $.post(caURL, payload).fail(function () { });
    } catch (e) { }

    // Use cached async getter to fetch config and decide console logging
    getConfig().then(function (cfg) {
        try {
            switch (lvl) {
                case 'debug':
                    if (cfg && (cfg.DEBUG_TO_LOG === 'false' || cfg.DEBUG_TO_LOG === false)) {
                        return; // Skip debug logs if disabled in config
                    } else {
                        // When config fetch fails (cfg === null), default to showing debug logs.
                        msg = '[DEBUG] ' + msg;
                    }
                    break;
                case 'err':
                case 'error':
                    msg = '[ERROR] ' + msg;
                    break;
                case 'warn':
                case 'warning':
                    msg = '[WARN] ' + msg;
                    break;
                case 'info':
                default:
                    msg = '[INFO] ' + msg;
            }
            if (obj !== undefined && obj !== null && obj !== '' && obj !== 'null') {
                console.log('compose.manager: ' + msg, obj);
            } else {
                console.log('compose.manager: ' + msg);
            }
        } catch (e) { }
    });
}