(function (window, document) {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function dataValue(key, fallback) {
        var store = window.PTSB_DATA || {};
        if (Object.prototype.hasOwnProperty.call(store, key)) {
            return store[key];
        }
        return fallback;
    }

    function syncCheckboxChips(container) {
        if (!container) {
            return;
        }
        var checkboxes = container.querySelectorAll('.ptsb-chip input[type="checkbox"]');
        checkboxes.forEach(function (checkbox) {
            var chip = checkbox.closest('.ptsb-chip');
            if (!chip) {
                return;
            }
            var update = function () {
                chip.classList.toggle('active', checkbox.checked);
            };
            checkbox.addEventListener('change', update);
            update();
        });
    }

    function initTimeBuilder(quantityId, targetId) {
        var qty = document.getElementById(quantityId);
        var box = document.getElementById(targetId);
        if (!qty || !box) {
            return;
        }
        var rebuild = function () {
            var n = Math.max(1, Math.min(12, parseInt(qty.value, 10) || 1));
            var oldValues = Array.from(box.querySelectorAll('input[type="time"]')).map(function (input) {
                return input.value;
            });
            box.innerHTML = '';
            for (var i = 0; i < n; i++) {
                var input = document.createElement('input');
                input.type = 'time';
                input.step = 60;
                input.name = 'times[]';
                input.style.width = '100%';
                if (oldValues[i]) {
                    input.value = oldValues[i];
                }
                box.appendChild(input);
            }
            var sel = box.closest('form');
            if (sel) {
                var modeSel = sel.querySelector('select[name="mode"]');
                if (modeSel) {
                    modeSel.dispatchEvent(new Event('change'));
                }
            }
        };
        qty.addEventListener('input', rebuild);
        rebuild();
    }

    ready(function () {
        setupPerForm();
        setupFileDetails();
        setupBackupPager();
        setupManualChips();
        setupManualKeepToggle();
        setupProgressPoll();
        setupRenameButtons();
        setupCycleAddLetters();
        setupCycleKeepToggle();
        setupModeSections();
        initTimeBuilder('new-daily-qty', 'new-daily-times');
        initTimeBuilder('new-weekly-qty', 'new-weekly-times');
        initTimeBuilder('new-everyn-qty', 'new-everyn-times');
        setupWeeklyChips();
        setupWindowToggles();
        setupCycleValidation();
        setupNextForms();
        setupNextPager();
        setupLastForms();
        setupLastPager();
        setupLogPolling();
    });

    function setupPerForm() {
        var form = document.getElementById('ptsb-per-form');
        if (!form) {
            return;
        }
        var input = form.querySelector('input[name="per"]');
        if (!input) {
            return;
        }
        input.addEventListener('change', function () {
            form.submit();
        });
    }

    function setupFileDetails() {
        var ajaxUrl = dataValue('ajaxUrl', window.ajaxurl || '');
        var nonce = dataValue('nonce', '');
        if (!ajaxUrl || !nonce) {
            return;
        }
        var rows = document.querySelectorAll('table.widefat tbody tr[data-file]');
        if (!rows.length) {
            return;
        }

        function collectFiles() {
            return Array.from(rows).map(function (tr) {
                return tr.getAttribute('data-file');
            }).filter(Boolean);
        }

        function letterIcon(letter) {
            var map = {
                'D': 'dashicons-database',
                'P': 'dashicons-admin-plugins',
                'T': 'dashicons-admin-appearance',
                'W': 'dashicons-wordpress-alt',
                'S': 'dashicons-editor-code',
                'M': 'dashicons-admin-media',
                'O': 'dashicons-image-filter'
            };
            var cls = map[letter] || 'dashicons-marker';
            return '<span class="ptsb-mini" title="' + letter + '"><span class="dashicons ' + cls + '"></span></span>';
        }

        function renderRetentionCell(tr, keepDays) {
            var kept = tr.getAttribute('data-kept') === '1';
            var td = tr.querySelector('.ptsb-col-ret');
            if (!td) {
                return;
            }
            if (kept) {
                td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                return;
            }
            if (keepDays === null) {
                td.textContent = '—';
                return;
            }
            if (keepDays === 0) {
                td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                return;
            }
            var iso = tr.getAttribute('data-time');
            var created = iso ? new Date(iso) : null;
            if (!created || Number.isNaN(created.getTime())) {
                td.textContent = '—';
                return;
            }
            var now = new Date();
            var elapsedDays = Math.max(0, Math.floor((now - created) / 86400000));
            var currentDay = Math.min(keepDays, elapsedDays + 1);
            var expired = currentDay >= keepDays;
            td.innerHTML = '<span class="ptsb-ret" title="Dia ' + currentDay + ' de ' + keepDays + '">' + currentDay + '/' + keepDays + '</span>';
            if (expired && !kept) {
                tr.classList.add('ptsb-expired');
                var nameCell = tr.querySelector('.ptsb-filename');
                if (nameCell && !(nameCell.nextElementSibling && nameCell.nextElementSibling.classList && nameCell.nextElementSibling.classList.contains('ptsb-tag'))) {
                    var tag = document.createElement('span');
                    tag.className = 'ptsb-tag vencido';
                    tag.textContent = 'vencido';
                    nameCell.insertAdjacentElement('afterend', tag);
                }
            }
        }

        function hydrate() {
            var files = collectFiles();
            if (!files.length) {
                return;
            }
            var body = new URLSearchParams();
            body.set('action', 'ptsb_details_batch');
            body.set('nonce', nonce);
            files.forEach(function (file) {
                body.append('files[]', file);
            });
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (!res || !res.success || !res.data) {
                    return;
                }
                files.forEach(function (file) {
                    var tr = document.querySelector('tr[data-file="' + CSS.escape(file) + '"]');
                    if (!tr) {
                        return;
                    }
                    var data = res.data[file] || {};
                    var routineCell = tr.querySelector('.ptsb-col-rotina');
                    if (routineCell) {
                        routineCell.textContent = data.routine_label || '—';
                    }
                    var lettersCell = tr.querySelector('.ptsb-col-letters');
                    if (lettersCell) {
                        var letters = (data.parts_letters && data.parts_letters.length) ? data.parts_letters : ['D', 'P', 'T', 'W', 'S', 'M', 'O'];
                        lettersCell.innerHTML = letters.map(letterIcon).join('');
                    }
                    var keepDays = data.keep_days;
                    renderRetentionCell(tr, keepDays === null ? null : parseInt(keepDays, 10));
                });
            }).catch(function () {
                // ignore errors silently
            });
        }

        hydrate();
    }

    function setupBackupPager() {
        var input = document.getElementById('ptsb-pager-input');
        var template = dataValue('backupPagerUrl', '');
        if (!input || !template) {
            return;
        }
        var go = function () {
            var min = parseInt(input.min, 10) || 1;
            var max = parseInt(input.max, 10) || min;
            var value = Math.max(min, Math.min(max, parseInt(input.value, 10) || min));
            window.location.href = template.replace('__PAGE__', String(value));
        };
        input.addEventListener('change', go);
        input.addEventListener('keyup', function (event) {
            if (event.key === 'Enter') {
                go();
            }
        });
    }

    function setupManualChips() {
        var form = document.getElementById('ptsb-now-form');
        var chipsBox = document.getElementById('ptsb-chips');
        if (!form || !chipsBox) {
            return;
        }

        syncCheckboxChips(chipsBox);

        form.addEventListener('submit', function () {
            var sentinel = document.getElementById('ptsb-parts-hidden-sentinel');
            if (sentinel && sentinel.parentNode) {
                sentinel.parentNode.removeChild(sentinel);
            }
            form.querySelectorAll('input[name="parts_sel[]"]').forEach(function (input) {
                input.remove();
            });
            var letters = [];
            chipsBox.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(function (checkbox) {
                letters.push(String(checkbox.dataset.letter || '').toUpperCase());
            });
            var finalLetters = letters.length ? letters : ['D', 'P', 'T', 'W', 'S', 'M', 'O'];
            finalLetters.forEach(function (letter) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'parts_sel[]';
                hidden.value = letter;
                form.appendChild(hidden);
            });
        });
    }

    function setupManualKeepToggle() {
        var checkbox = document.getElementById('ptsb-man-keep-forever');
        var days = document.querySelector('#ptsb-now-form input[name="manual_keep_days"]');
        if (!checkbox || !days) {
            return;
        }
        var sync = function () {
            days.disabled = checkbox.checked;
            days.style.opacity = checkbox.checked ? 0.5 : 1;
        };
        checkbox.addEventListener('change', sync);
        sync();
    }

    function setupProgressPoll() {
        var ajaxUrl = dataValue('ajaxUrl', window.ajaxurl || '');
        var nonce = dataValue('nonce', '');
        if (!ajaxUrl || !nonce) {
            return;
        }
        var barBox = document.getElementById('ptsb-progress');
        var bar = document.getElementById('ptsb-progress-bar');
        var text = document.getElementById('ptsb-progress-text');
        if (!barBox || !bar || !text) {
            return;
        }
        var wasRunning = false;
        var didReload = false;
        var poll = function () {
            var body = new URLSearchParams({ action: 'ptsb_status', nonce: nonce }).toString();
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (!res || !res.success) {
                    return;
                }
                var status = res.data || {};
                if (status.running) {
                    wasRunning = true;
                    barBox.style.display = 'block';
                    var pct = Math.max(5, Math.min(100, status.percent | 0));
                    bar.style.width = pct + '%';
                    text.textContent = pct < 100 ? (pct + '% - ' + (status.stage || 'executando…')) : '100%';
                } else {
                    if (wasRunning && (status.percent | 0) >= 100 && !didReload) {
                        didReload = true;
                        bar.style.width = '100%';
                        text.textContent = '100% - concluído';
                        setTimeout(function () {
                            window.location.reload();
                        }, 1200);
                    } else {
                        barBox.style.display = 'none';
                    }
                    wasRunning = false;
                }
            }).catch(function () {
                // ignore
            });
        };
        poll();
        window.setInterval(poll, 2000);
    }

    function setupRenameButtons() {
        var prefix = String(dataValue('renamePrefix', ''));
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('.ptsb-rename-btn');
            if (!btn) {
                return;
            }
            var form = btn.closest('form.ptsb-rename-form');
            if (!form) {
                return;
            }
            var oldFull = btn.getAttribute('data-old') || '';
            var currentNick = oldFull.replace(new RegExp('^' + prefix), '').replace(/\.tar\.gz$/i, '');
            var nick = window.prompt('Novo apelido (apenas a parte entre "' + prefix + '" e ".tar.gz"):', currentNick);
            if (nick === null) {
                return;
            }
            nick = (nick || '').trim().replace(/\.tar\.gz$/i, '').replace(new RegExp('^' + prefix), '').replace(/[^A-Za-z0-9._-]+/g, '-');
            if (!nick) {
                window.alert('Apelido inválido.');
                return;
            }
            var newFull = prefix + nick + '.tar.gz';
            if (newFull === oldFull) {
                window.alert('O nome não foi alterado.');
                return;
            }
            if (!/^[A-Za-z0-9._-]+\.tar\.gz$/.test(newFull)) {
                window.alert('Use apenas letras, números, ponto, hífen e sublinhado. A extensão deve ser .tar.gz.');
                return;
            }
            var targetInput = form.querySelector('input[name="new_file"]');
            if (targetInput) {
                targetInput.value = newFull;
            }
            form.submit();
        });
    }

    function setupCycleAddLetters() {
        var form = document.getElementById('ptsb-add-cycle-form');
        if (!form) {
            return;
        }
        var wrap = form.querySelector('#ptsb-add-letters');
        if (!wrap) {
            return;
        }
        syncCheckboxChips(wrap);
        form.addEventListener('submit', function () {
            form.querySelectorAll('input[name="letters[]"]').forEach(function (node) {
                node.remove();
            });
            wrap.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(function (checkbox) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'letters[]';
                hidden.value = String(checkbox.dataset.letter || '').toUpperCase();
                form.appendChild(hidden);
            });
        });
    }

    function setupCycleKeepToggle() {
        var form = document.getElementById('ptsb-add-cycle-form');
        if (!form) {
            return;
        }
        var checkbox = form.querySelector('input[name="keep_forever"]');
        var days = form.querySelector('input[name="keep_days"]');
        if (!checkbox || !days) {
            return;
        }
        var sync = function () {
            days.disabled = checkbox.checked;
            days.style.opacity = checkbox.checked ? 0.5 : 1;
        };
        checkbox.addEventListener('change', sync);
        sync();
    }

    function setupModeSections() {
        document.querySelectorAll('form').forEach(function (form) {
            var selector = form.querySelector('select[name="mode"]');
            if (!selector) {
                return;
            }
            var toggleSections = function () {
                var value = selector.value;
                form.querySelectorAll('[data-new],[data-sec]').forEach(function (box) {
                    var match = box.getAttribute('data-new') === value || box.getAttribute('data-sec') === value;
                    box.style.display = match ? '' : 'none';
                    box.querySelectorAll('input, select, textarea').forEach(function (input) {
                        input.disabled = !match;
                    });
                });
            };
            selector.addEventListener('change', toggleSections);
            toggleSections();
        });
    }

    function setupWeeklyChips() {
        var wrap = document.getElementById('wk_new');
        if (!wrap) {
            return;
        }
        var form = wrap.closest('form');
        if (!form) {
            return;
        }
        var sync = function () {
            form.querySelectorAll('input[name="wk_days[]"]').forEach(function (input) {
                input.remove();
            });
            wrap.querySelectorAll('.ptsb-chip.active').forEach(function (chip) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'wk_days[]';
                hidden.value = String(chip.dataset.day || '');
                form.appendChild(hidden);
            });
        };
        wrap.addEventListener('click', function (event) {
            var chip = event.target.closest('.ptsb-chip');
            if (!chip) {
                return;
            }
            chip.classList.toggle('active');
            sync();
        });
        sync();
    }

    function setupWindowToggles() {
        document.querySelectorAll('[data-new="interval"], [data-sec="interval"]').forEach(function (section) {
            var disable = section.querySelector('input[name="win_disable"]');
            var start = section.querySelector('input[name="win_start"]');
            var end = section.querySelector('input[name="win_end"]');
            if (!disable) {
                return;
            }
            var sync = function () {
                var on = disable.checked;
                [start, end].forEach(function (input) {
                    if (!input) {
                        return;
                    }
                    input.disabled = on;
                    input.style.opacity = on ? 0.5 : 1;
                });
            };
            disable.addEventListener('change', sync);
            sync();
        });
    }

    function setupCycleValidation() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches('form') || !form.querySelector('input[name="action"][value="ptsb_cycles"]')) {
                return;
            }
            var modeSel = form.querySelector('select[name="mode"]');
            if (!modeSel) {
                return;
            }
            var mode = modeSel.value;
            var section = form.querySelector('[data-new="' + mode + '"],[data-sec="' + mode + '"]') || form;
            var times = section.querySelectorAll('input[type="time"]:not([disabled])');
            for (var i = 0; i < times.length; i++) {
                var input = times[i];
                input.required = true;
                if (!input.value) {
                    event.preventDefault();
                    input.reportValidity();
                    return;
                }
            }
            if (mode === 'weekly') {
                var guard = form.querySelector('input[name="wk_days_guard"]');
                var hasDay = !!section.querySelector('.ptsb-chips [data-day].active');
                if (guard) {
                    if (!hasDay) {
                        guard.value = '';
                        guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
                        event.preventDefault();
                        guard.reportValidity();
                        return;
                    }
                    guard.value = 'ok';
                    guard.setCustomValidity('');
                }
            }
        }, true);
    }

    function setupNextForms() {
        var dateForm = document.getElementById('ptsb-next-date-form');
        if (dateForm) {
            var dateInput = dateForm.querySelector('input[name="next_date"]');
            if (dateInput) {
                dateInput.addEventListener('change', function () {
                    dateForm.submit();
                });
            }
        }
        var perForm = document.getElementById('ptsb-next-per-form');
        if (perForm) {
            var perInput = perForm.querySelector('input[name="per_next"]');
            if (perInput) {
                perInput.addEventListener('change', function () {
                    perForm.submit();
                });
            }
        }
    }

    function setupNextPager() {
        var input = document.getElementById('ptsb-next-pager-input');
        var template = dataValue('nextPagerUrl', '');
        if (!input || !template) {
            return;
        }
        var go = function () {
            var value = Math.max(1, parseInt(input.value, 10) || 1);
            window.location.href = template.replace('__PAGE__', String(value));
        };
        input.addEventListener('change', go);
        input.addEventListener('keyup', function (event) {
            if (event.key === 'Enter') {
                go();
            }
        });
    }

    function setupLastForms() {
        var filterForm = document.getElementById('ptsb-last-filter-form');
        if (filterForm) {
            filterForm.addEventListener('change', function () {
                filterForm.submit();
            });
        }
        var perForm = document.getElementById('ptsb-last-per-form');
        if (perForm) {
            var input = perForm.querySelector('input[name="per_last"]');
            if (input) {
                input.addEventListener('change', function () {
                    perForm.submit();
                });
            }
        }
    }

    function setupLastPager() {
        var input = document.getElementById('ptsb-last-pager-input');
        var template = dataValue('lastPagerUrl', '');
        if (!input || !template) {
            return;
        }
        var go = function () {
            var min = parseInt(input.min, 10) || 1;
            var max = parseInt(input.max, 10) || min;
            var value = Math.max(min, Math.min(max, parseInt(input.value, 10) || min));
            window.location.href = template.replace('__PAGE__', String(value));
        };
        input.addEventListener('change', go);
        input.addEventListener('keyup', function (event) {
            if (event.key === 'Enter') {
                go();
            }
        });
    }

    function setupLogPolling() {
        var ajaxUrl = dataValue('ajaxUrl', window.ajaxurl || '');
        var nonce = dataValue('nonce', '');
        if (!ajaxUrl || !nonce) {
            return;
        }
        var logEl = document.getElementById('ptsb-log');
        if (!logEl) {
            return;
        }
        var lastLog = logEl.textContent || '';
        var autoStick = true;
        logEl.addEventListener('scroll', function () {
            var nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
            autoStick = nearBottom;
        });
        var render = function (text) {
            if (text === lastLog) {
                return;
            }
            var shouldStick = autoStick;
            logEl.textContent = text;
            if (shouldStick) {
                requestAnimationFrame(function () {
                    logEl.scrollTop = logEl.scrollHeight;
                });
            }
            lastLog = text;
        };
        var poll = function () {
            var body = new URLSearchParams({ action: 'ptsb_status', nonce: nonce }).toString();
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (res) {
                if (!res || !res.success) {
                    return;
                }
                var status = res.data || {};
                var text = (status.log && String(status.log).trim()) ? status.log : '(sem linhas)';
                render(text);
            }).catch(function () {
                // ignore
            });
        };
        poll();
        window.setInterval(poll, 2000);
    }
})(window, document);
