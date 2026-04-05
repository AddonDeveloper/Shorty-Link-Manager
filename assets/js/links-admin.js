(function () {
    const button = document.getElementById('wpsl-auto-run-button');
    const bar = document.getElementById('wpsl-progress-bar');
    const label = document.getElementById('wpsl-progress-label');
    const summary = document.getElementById('wpsl-progress-summary');
    const message = document.getElementById('wpsl-progress-message');
    const rateInfo = document.getElementById('wpsl-rate-limit-info');

    if (!button || typeof wpslLinksAdmin === 'undefined') {
        return;
    }

    let running = false;

    function updateUi(snapshot, statusMessage) {
        if (snapshot) {
            const percent = parseInt(snapshot.progress_percent || 0, 10);
            if (bar) {
                bar.style.width = percent + '%';
            }
            if (label) {
                label.textContent = percent + '%';
            }
            if (summary) {
                summary.textContent = 'Total: ' + (snapshot.total || 0) + ' | Shortened: ' + (snapshot.shortened || 0) + ' | Pending: ' + (snapshot.pending || 0) + ' | Errors: ' + (snapshot.error || 0);
            }
            if (rateInfo && wpslLinksAdmin.i18n.providerName) {
                const remaining = parseInt(snapshot.rate_limit_remaining || 0, 10);
                rateInfo.textContent = wpslLinksAdmin.i18n.rateInfo
                    .replace('%1$s', wpslLinksAdmin.i18n.providerName)
                    .replace('%2$d', remaining);
            }
        }

        if (message && statusMessage) {
            message.textContent = statusMessage;
        }
    }

    function setRunningState(state) {
        running = state;
        button.disabled = state;
        button.textContent = state ? wpslLinksAdmin.i18n.working : wpslLinksAdmin.i18n.buttonIdle;
    }

    async function callAjax(action) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', wpslLinksAdmin.nonce);

        const response = await fetch(wpslLinksAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        });

        return response.json();
    }

    function reloadPageSoon(delay, statusText) {
        updateUi(null, statusText);
        window.setTimeout(function () {
            window.location.reload();
        }, delay || 1200);
    }

    async function runLoop() {
        setRunningState(true);
        updateUi(null, wpslLinksAdmin.i18n.working);

        try {
            while (running) {
                const result = await callAjax('wpsl_process_next_batch');
                if (!result || !result.success) {
                    throw new Error((result && result.data && result.data.message) || wpslLinksAdmin.i18n.error);
                }

                const data = result.data || {};
                updateUi(data.snapshot || null, data.message || wpslLinksAdmin.i18n.working);

                if (data.done) {
                    setRunningState(false);
                    reloadPageSoon(1200, wpslLinksAdmin.i18n.reloading);
                    break;
                }

                if (data.rate_limited) {
                    setRunningState(false);
                    reloadPageSoon(1200, wpslLinksAdmin.i18n.waitingReload);
                    break;
                }

                await new Promise(resolve => window.setTimeout(resolve, 800));
            }
        } catch (error) {
            setRunningState(false);
            updateUi(null, error.message || wpslLinksAdmin.i18n.error);
        }
    }

    button.addEventListener('click', function () {
        if (running) {
            return;
        }
        runLoop();
    });
})();
