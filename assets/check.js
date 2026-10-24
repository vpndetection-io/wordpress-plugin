// Checks a page a cache served without asking WordPress: the site answers whether this visitor may
// see it. A visitor let through is not asked again for half an hour in this tab.
(function () {
    'use strict';

    var config = window.vpndetectionCheck;
    if (!config || !window.fetch) {
        return;
    }
    var key = 'vpndetection-check';
    try {
        var allowedUntil = Number(window.sessionStorage.getItem(key) || 0);
        if (allowedUntil > Date.now()) {
            return;
        }
    } catch (e) {
        // Storage refused, as in a private window: ask every time.
    }

    window.fetch(config.url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: window.location.pathname + window.location.search }),
    })
        .then(function (response) {
            return response.ok ? response.json() : { blocked: false };
        })
        .then(function (verdict) {
            if (!verdict || !verdict.blocked) {
                try {
                    window.sessionStorage.setItem(key, String(Date.now() + 30 * 60 * 1000));
                } catch (e) {
                    // As above.
                }
                return;
            }
            if (verdict.redirect) {
                window.location.replace(verdict.redirect);
                return;
            }
            refuse(verdict.message || '');
        })
        .catch(function () {
            // The site could not be asked; the page stays, as a failed lookup lets a visitor through.
        });

    function refuse(message) {
        document.title = config.title;
        var body = document.createElement('body');
        var main = document.createElement('main');
        main.setAttribute('style', 'max-width:40rem;margin:4rem auto;padding:0 1rem;font:16px/1.5 system-ui,sans-serif;');
        var heading = document.createElement('h1');
        heading.textContent = config.title;
        var text = document.createElement('p');
        text.textContent = message;
        main.appendChild(heading);
        main.appendChild(text);
        body.appendChild(main);
        document.documentElement.replaceChild(body, document.body);
    }
})();
