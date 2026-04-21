/*
 * Shopwalk UCP — admin dashboard JS.
 *
 * Wires up the "Run self-test", "Refresh payment status", and per-row
 * "Probe" buttons on the admin dashboard. AJAX endpoints are carried on
 * `window.SHOPWALK_UCP` so the controller owns URL + token assembly.
 */

(function () {
    'use strict';

    if (typeof window.SHOPWALK_UCP !== 'object') {
        return;
    }

    function getJson(url, then) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            try {
                then(JSON.parse(xhr.responseText || '{}'), xhr.status);
            } catch (e) {
                then({ error: 'parse_error', message: 'Invalid JSON response' }, xhr.status);
            }
        };
        xhr.send();
    }

    var btnSelfTest = document.getElementById('sw-run-self-test');
    var btnPayments = document.getElementById('sw-refresh-payments');
    var probes      = document.querySelectorAll('.sw-probe');
    var probeOut    = document.getElementById('sw-probe-out');
    var selfTestEl  = document.getElementById('sw-self-test-partial');
    var paymentsBody = document.getElementById('sw-payments-body');

    if (btnSelfTest) {
        btnSelfTest.addEventListener('click', function () {
            btnSelfTest.disabled = true;
            btnSelfTest.textContent = 'Running…';
            getJson(window.SHOPWALK_UCP.selfTestUrl, function (payload) {
                if (selfTestEl && payload && payload.partial) {
                    selfTestEl.innerHTML = payload.partial;
                }
                btnSelfTest.disabled = false;
                btnSelfTest.textContent = 'Run self-test';
            });
        });
    }

    if (btnPayments) {
        btnPayments.addEventListener('click', function () {
            btnPayments.disabled = true;
            btnPayments.textContent = 'Refreshing…';
            getJson(window.SHOPWALK_UCP.paymentsStatusUrl, function (payload) {
                if (payload && payload.adapters && paymentsBody) {
                    var rows = payload.adapters.map(function (a) {
                        var statusCell = a.ready
                            ? '<span class="sw-status sw-status-ok">&#9989; ' + a.status_label + '</span>'
                            : '<span class="sw-status sw-status-off">&#9744; ' + a.status_label + '</span>';
                        var cfgCell = a.configure_url
                            ? '<a href="' + a.configure_url + '">Open settings</a>'
                            : '&mdash;';
                        return '<tr><td><strong>' + a.id + '</strong></td>'
                            + '<td>' + statusCell + '</td>'
                            + '<td>' + cfgCell + '</td></tr>';
                    }).join('');
                    paymentsBody.innerHTML = rows || '<tr><td colspan="3">No adapters registered.</td></tr>';
                }
                btnPayments.disabled = false;
                btnPayments.textContent = 'Refresh payment status';
            });
        });
    }

    if (probes && probeOut) {
        for (var i = 0; i < probes.length; i++) {
            probes[i].addEventListener('click', function (e) {
                var target = e.currentTarget.getAttribute('data-target') || 'discovery';
                probeOut.textContent = 'Probing ' + target + '…';
                getJson(window.SHOPWALK_UCP.probeUrl + '&target=' + encodeURIComponent(target), function (payload) {
                    probeOut.textContent = 'GET ' + payload.url
                        + '\nHTTP ' + (payload.status || '?')
                        + '\n\n' + (payload.body || '');
                });
            });
        }
    }
})();
