// The settings screen: Connect with VPNDetection, the confirmations, and showing each option only
// where it applies.
(function () {
    'use strict';

    var config = window.vpndetectionAdmin || {};
    var text = config.text || {};

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // An area's own options only matter once it is on, its own signals only once asked for.
    function sync() {
        document.querySelectorAll('.vpnd-area').forEach(function (row) {
            var action = row.querySelector('.vpnd-action');
            var own = row.querySelector('.vpnd-own');
            row.querySelector('.vpnd-when-on').hidden = action.value === 'off';
            row.querySelector('.vpnd-own-signals').hidden = !own.checked;
        });
        var scope = document.querySelector('input[name$="[pages_scope]"]:checked');
        var selected = document.querySelector('.vpnd-selected');
        if (selected) {
            selected.hidden = !scope || scope.value !== 'selected';
        }
        var proxy = document.getElementById('vpnd-proxy');
        var custom = document.querySelector('.vpnd-custom-proxy');
        if (proxy && custom) {
            custom.hidden = proxy.value !== 'custom';
        }
    }
    document.addEventListener('change', sync);
    sync();

    var button = document.getElementById('vpnd-connect');
    var status = document.getElementById('vpnd-connect-status');
    if (!button || !status) {
        return;
    }

    function post(action) {
        var body = new URLSearchParams({ action: action, _ajax_nonce: config.nonce });
        return window.fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) {
                return response.json();
            });
    }

    function say(message) {
        status.textContent = message;
    }

    button.addEventListener('click', function () {
        // Opened inside the click, or the browser treats it as a pop-up.
        var tab = window.open('about:blank', '_blank');
        button.disabled = true;
        say('');
        post('vpndetection_connect_start').then(function (reply) {
            if (!reply || !reply.success) {
                throw new Error(reply && reply.data && reply.data.message ? reply.data.message : text.failed);
            }
            var device = reply.data;
            if (tab) {
                tab.opener = null;
                tab.location.href = device.url;
            } else {
                status.textContent = text.popup + ' ';
                var link = document.createElement('a');
                link.href = device.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = device.url;
                status.appendChild(link);
            }
            if (tab) {
                say(text.waiting + ' ' + device.code + '.');
            }
            var interval = device.interval * 1000;
            var deadline = Date.now() + device.expires * 1000;
            function poll() {
                if (Date.now() > deadline) {
                    say(text.expired);
                    button.disabled = false;
                    return;
                }
                post('vpndetection_connect_poll').then(function (answer) {
                    if (!answer || !answer.success) {
                        throw new Error(answer && answer.data && answer.data.message ? answer.data.message : text.failed);
                    }
                    var state = answer.data.status;
                    if (state === 'connected') {
                        window.location.reload();
                        return;
                    }
                    if (state === 'pending') {
                        if (answer.data.slower) {
                            interval += 5000;
                        }
                        window.setTimeout(poll, interval);
                        return;
                    }
                    say(text[state] || text.failed);
                    button.disabled = false;
                }).catch(function (error) {
                    say(error.message || text.failed);
                    button.disabled = false;
                });
            }
            window.setTimeout(poll, interval);
        }).catch(function (error) {
            if (tab) {
                tab.close();
            }
            say(error.message || text.failed);
            button.disabled = false;
        });
    });
})();
