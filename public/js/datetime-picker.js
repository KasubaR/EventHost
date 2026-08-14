/**
 * Custom date / time / datetime picker.
 *
 * Progressive enhancement: the native <input type="date|time|datetime-local">
 * stays in the DOM and keeps its name/value/required, so server-side code and
 * browser validation are untouched. Only the UI is replaced.
 *
 * Usage
 *   <input type="date" name="event_date" data-dtp>
 *   <input type="time" name="event_time" data-dtp data-minute-step="15">
 *   <input type="datetime-local" name="rsvp_deadline" data-dtp data-min="today">
 *
 * Attributes
 *   data-dtp             opt in (or call DateTimePicker.attach(el) manually)
 *   data-dtp-skip        opt out when auto-attaching by type
 *   data-minute-step     minute granularity, default 5
 *   data-hour-format     "12" (default) or "24"
 *   data-week-start      0 = Sunday (default) … 1 = Monday
 *   data-placeholder     trigger text when empty
 *   min / max            native attrs are respected ("today" also accepted)
 *
 * The panel is portalled to <body> because form sections use overflow:hidden.
 */
(function () {
    'use strict';

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    var TIME_PRESETS = [
        { label: 'Morning', h: 9, m: 0 },
        { label: 'Noon', h: 12, m: 0 },
        { label: 'Afternoon', h: 14, m: 0 },
        { label: 'Evening', h: 18, m: 0 },
        { label: 'Night', h: 20, m: 0 }
    ];

    var openPicker = null;
    var uid = 0;

    /* ── helpers ── */

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function button(className, html) {
        var b = el('button', className);
        b.type = 'button';
        if (html) b.innerHTML = html;
        return b;
    }

    function daysInMonth(y, m) {
        return new Date(y, m + 1, 0).getDate();
    }

    /** Serial number for a calendar day — safe to compare/sort. */
    function daySerial(y, m, d) {
        return y * 10000 + m * 100 + d;
    }

    function minuteSerial(y, m, d, h, min) {
        return daySerial(y, m, d) * 10000 + h * 100 + min;
    }

    /** Parse "YYYY-MM-DD", "HH:MM" or "YYYY-MM-DDTHH:MM" into parts. */
    function parseValue(raw, hasDate, hasTime) {
        if (!raw) return null;
        var datePart = raw;
        var timePart = '';

        if (hasDate && hasTime) {
            var split = raw.split('T');
            datePart = split[0];
            timePart = split[1] || '';
        } else if (hasTime) {
            datePart = '';
            timePart = raw;
        }

        var out = { y: null, m: null, d: null, h: null, min: null };

        if (hasDate) {
            var dm = /^(\d{4})-(\d{2})-(\d{2})$/.exec(datePart);
            if (!dm) return null;
            out.y = +dm[1];
            out.m = +dm[2] - 1;
            out.d = +dm[3];
            if (out.m < 0 || out.m > 11 || out.d < 1 || out.d > daysInMonth(out.y, out.m)) return null;
        }

        if (hasTime) {
            var tm = /^(\d{1,2}):(\d{2})/.exec(timePart);
            if (!tm) return hasDate ? out : null;
            out.h = +tm[1];
            out.min = +tm[2];
            if (out.h > 23 || out.min > 59) return null;
        }

        return out;
    }

    function formatValue(v, hasDate, hasTime) {
        if (!v) return '';
        var date = hasDate ? v.y + '-' + pad(v.m + 1) + '-' + pad(v.d) : '';
        var time = hasTime ? pad(v.h || 0) + ':' + pad(v.min || 0) : '';
        if (hasDate && hasTime) return date + 'T' + time;
        return hasDate ? date : time;
    }

    function formatDisplayDate(v) {
        var js = new Date(v.y, v.m, v.d);
        return WEEKDAYS[js.getDay()] + ', ' + v.d + ' ' + MONTHS_SHORT[v.m] + ' ' + v.y;
    }

    function formatDisplayTime(h, min, use24) {
        if (use24) return pad(h) + ':' + pad(min);
        var mer = h >= 12 ? 'PM' : 'AM';
        var hr = h % 12;
        if (hr === 0) hr = 12;
        return hr + ':' + pad(min) + ' ' + mer;
    }

    /* ── picker ── */

    function Picker(input) {
        this.input = input;
        this.type = input.getAttribute('type');
        this.hasDate = this.type !== 'time';
        this.hasTime = this.type !== 'date';
        this.use24 = input.dataset.hourFormat === '24';
        this.weekStart = Math.min(6, Math.max(0, parseInt(input.dataset.weekStart, 10) || 0));
        this.minuteStep = Math.min(30, Math.max(1, parseInt(input.dataset.minuteStep, 10) || 5));
        this.placeholder = input.dataset.placeholder || this.defaultPlaceholder();
        this.mode = 'days';
        this.id = 'dtp-' + (++uid);

        this.readBounds();
        this.buildTrigger();
        this.syncFromInput();

        var self = this;
        input.addEventListener('change', function () {
            if (!self.suppressSync) self.syncFromInput();
        });
        input.addEventListener('invalid', function () {
            self.wrap.classList.add('is-invalid');
        });
        input.form && input.form.addEventListener('reset', function () {
            window.setTimeout(function () { self.syncFromInput(); }, 0);
        });
    }

    Picker.prototype.defaultPlaceholder = function () {
        if (!this.hasDate) return 'Select a time';
        if (!this.hasTime) return 'Select a date';
        return 'Select date & time';
    };

    /** min/max from native attrs; "today" is accepted as a keyword. */
    Picker.prototype.readBounds = function () {
        this.min = this.parseBound(this.input.getAttribute('min'));
        this.max = this.parseBound(this.input.getAttribute('max'));
    };

    Picker.prototype.parseBound = function (raw) {
        if (!raw) return null;
        if (raw === 'today' || raw === 'now') {
            var n = new Date();
            return {
                y: n.getFullYear(), m: n.getMonth(), d: n.getDate(),
                h: raw === 'now' ? n.getHours() : 0,
                min: raw === 'now' ? n.getMinutes() : 0
            };
        }
        var parsed = parseValue(raw, this.hasDate, this.hasTime);
        if (!parsed) parsed = parseValue(raw, this.hasDate, false);
        return parsed;
    };

    Picker.prototype.buildTrigger = function () {
        var input = this.input;
        var wrap = el('div', 'dtp');

        // Carry validation-state class from the server-rendered input.
        if (input.classList.contains('profile-input--error')) wrap.classList.add('is-invalid');

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('dtp-native');
        input.setAttribute('tabindex', '-1');
        input.setAttribute('aria-hidden', 'true');

        var trigger = button('dtp-trigger');
        trigger.id = this.id + '-trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.disabled = input.disabled;

        var icon = el('i', 'dtp-trigger-icon');
        icon.className = 'dtp-trigger-icon fa-solid ' + (this.hasDate ? 'fa-calendar-day' : 'fa-clock');
        var text = el('span', 'dtp-trigger-text');
        var caret = el('i', 'dtp-trigger-caret fa-solid fa-chevron-down');

        trigger.appendChild(icon);
        trigger.appendChild(text);
        trigger.appendChild(caret);
        wrap.appendChild(trigger);

        // Label the trigger with the field's own <label>.
        var label = input.id && document.querySelector('label[for="' + input.id + '"]');
        if (label) {
            if (!label.id) label.id = this.id + '-label';
            trigger.setAttribute('aria-labelledby', label.id + ' ' + trigger.id);
            label.addEventListener('click', function (e) {
                e.preventDefault();
                trigger.focus();
            });
        }

        this.wrap = wrap;
        this.trigger = trigger;
        this.triggerText = text;

        var self = this;
        trigger.addEventListener('click', function () {
            self.isOpen ? self.close() : self.open();
        });
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                self.open();
            }
        });
    };

    Picker.prototype.syncFromInput = function () {
        var parsed = parseValue(this.input.value, this.hasDate, this.hasTime);
        this.value = parsed;

        var now = new Date();
        this.draft = {
            y: parsed && parsed.y != null ? parsed.y : now.getFullYear(),
            m: parsed && parsed.m != null ? parsed.m : now.getMonth(),
            d: parsed && parsed.d != null ? parsed.d : now.getDate(),
            h: parsed && parsed.h != null ? parsed.h : null,
            min: parsed && parsed.min != null ? parsed.min : null
        };
        this.hasDateSet = !!(parsed && parsed.y != null);
        this.hasTimeSet = !!(parsed && parsed.h != null);
        this.view = { y: this.draft.y, m: this.draft.m };
        this.renderTrigger();
    };

    Picker.prototype.renderTrigger = function () {
        var v = this.value;
        var parts = [];

        if (v && this.hasDate && v.y != null) parts.push(formatDisplayDate(v));
        if (v && this.hasTime && v.h != null) {
            parts.push((this.hasDate ? 'at ' : '') + formatDisplayTime(v.h, v.min, this.use24));
        }

        if (parts.length) {
            this.triggerText.textContent = parts.join(' ');
            this.triggerText.classList.remove('is-placeholder');
        } else {
            this.triggerText.textContent = this.placeholder;
            this.triggerText.classList.add('is-placeholder');
        }
    };

    /* ── bounds checks ── */

    Picker.prototype.dayDisabled = function (y, m, d) {
        var s = daySerial(y, m, d);
        if (this.min && this.min.y != null && s < daySerial(this.min.y, this.min.m, this.min.d)) return true;
        if (this.max && this.max.y != null && s > daySerial(this.max.y, this.max.m, this.max.d)) return true;
        return false;
    };

    Picker.prototype.monthDisabled = function (y, m) {
        var last = daysInMonth(y, m);
        return this.dayDisabled(y, m, 1) && this.dayDisabled(y, m, last);
    };

    Picker.prototype.yearDisabled = function (y) {
        return this.monthDisabled(y, 0) && this.monthDisabled(y, 11);
    };

    /** Time bounds only bite on the boundary day (or always, for type=time). */
    Picker.prototype.timeDisabled = function (h, min) {
        var d = this.draft;
        if (!this.hasDate) {
            if (this.min && this.min.h != null && h * 100 + min < this.min.h * 100 + this.min.min) return true;
            if (this.max && this.max.h != null && h * 100 + min > this.max.h * 100 + this.max.min) return true;
            return false;
        }
        if (!this.hasDateSet && d.y == null) return false;
        var s = minuteSerial(d.y, d.m, d.d, h, min);
        if (this.min && this.min.y != null && this.min.h != null &&
            s < minuteSerial(this.min.y, this.min.m, this.min.d, this.min.h, this.min.min)) return true;
        if (this.max && this.max.y != null && this.max.h != null &&
            s > minuteSerial(this.max.y, this.max.m, this.max.d, this.max.h, this.max.min)) return true;
        return false;
    };

    /** An hour is only selectable if at least one of its minutes is. */
    Picker.prototype.hourDisabled = function (h) {
        for (var m = 0; m < 60; m += this.minuteStep) {
            if (!this.timeDisabled(h, m)) return false;
        }
        return this.timeDisabled(h, 0);
    };

    /* ── panel ── */

    Picker.prototype.buildPanel = function () {
        var self = this;
        var panel = el('div', 'dtp-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-label', this.placeholder);

        var sheetHead = el('div', 'dtp-sheet-head');
        sheetHead.appendChild(el('span', 'dtp-sheet-title', this.placeholder));
        var sheetClose = button('dtp-sheet-close', '<i class="fa-solid fa-xmark"></i>');
        sheetClose.setAttribute('aria-label', 'Close');
        sheetClose.addEventListener('click', function () { self.close(true); });
        sheetHead.appendChild(sheetClose);
        panel.appendChild(sheetHead);

        var body = el('div', 'dtp-body');
        panel.appendChild(body);

        if (this.hasDate) {
            var datePane = el('div', 'dtp-pane dtp-pane-date');

            var head = el('div', 'dtp-head');
            this.prevBtn = button('dtp-nav', '<i class="fa-solid fa-chevron-left"></i>');
            this.prevBtn.setAttribute('aria-label', 'Previous');
            this.titleBtn = button('dtp-title');
            this.nextBtn = button('dtp-nav', '<i class="fa-solid fa-chevron-right"></i>');
            this.nextBtn.setAttribute('aria-label', 'Next');
            head.appendChild(this.prevBtn);
            head.appendChild(this.titleBtn);
            head.appendChild(this.nextBtn);
            datePane.appendChild(head);

            this.weekdayRow = el('div', 'dtp-weekdays');
            datePane.appendChild(this.weekdayRow);

            this.daysGrid = el('div', 'dtp-days');
            this.daysGrid.setAttribute('role', 'grid');
            datePane.appendChild(this.daysGrid);

            this.altGrid = el('div', 'dtp-grid');
            this.altGrid.style.display = 'none';
            datePane.appendChild(this.altGrid);

            body.appendChild(datePane);

            this.prevBtn.addEventListener('click', function () { self.step(-1); });
            this.nextBtn.addEventListener('click', function () { self.step(1); });
            this.titleBtn.addEventListener('click', function () {
                self.mode = self.mode === 'days' ? 'months' : (self.mode === 'months' ? 'years' : 'days');
                self.renderDatePane();
            });
        }

        if (this.hasTime) {
            var timePane = el('div', 'dtp-pane dtp-pane-time');

            var readout = el('div', 'dtp-time-readout');
            this.timeValueEl = el('span', 'dtp-time-value', '--:--');
            readout.appendChild(this.timeValueEl);

            if (!this.use24) {
                var mer = el('div', 'dtp-meridiem');
                this.merButtons = {};
                ['AM', 'PM'].forEach(function (label) {
                    var b = button('dtp-meridiem-btn', label);
                    b.addEventListener('click', function () { self.setMeridiem(label); });
                    self.merButtons[label] = b;
                    mer.appendChild(b);
                });
                readout.appendChild(mer);
            }
            timePane.appendChild(readout);

            timePane.appendChild(el('p', 'dtp-time-label', 'Hour'));
            this.hoursGrid = el('div', 'dtp-time-grid ' + (this.use24 ? 'dtp-time-grid-hours-24' : 'dtp-time-grid-hours'));
            timePane.appendChild(this.hoursGrid);

            timePane.appendChild(el('p', 'dtp-time-label', 'Minute'));
            this.minutesGrid = el('div', 'dtp-time-grid dtp-time-grid-minutes');
            timePane.appendChild(this.minutesGrid);

            var presets = el('div', 'dtp-presets');
            this.presetButtons = [];
            TIME_PRESETS.forEach(function (p) {
                var b = button('dtp-preset', p.label);
                b.title = formatDisplayTime(p.h, p.m, self.use24);
                b.addEventListener('click', function () {
                    self.draft.h = p.h;
                    self.draft.min = p.m;
                    self.hasTimeSet = true;
                    self.renderTimePane();
                });
                b.dataset.h = p.h;
                b.dataset.m = p.m;
                self.presetButtons.push(b);
                presets.appendChild(b);
            });
            timePane.appendChild(presets);

            body.appendChild(timePane);
        }

        var foot = el('div', 'dtp-foot');
        var clearBtn = button('dtp-btn dtp-btn-ghost', 'Clear');
        var nowBtn = button('dtp-btn dtp-btn-quiet', this.hasDate ? (this.hasTime ? 'Now' : 'Today') : 'Now');
        var doneBtn = button('dtp-btn dtp-btn-primary', 'Done');

        foot.appendChild(clearBtn);
        foot.appendChild(el('div', 'dtp-foot-spacer'));
        foot.appendChild(nowBtn);
        foot.appendChild(doneBtn);
        panel.appendChild(foot);

        if (this.input.required) clearBtn.style.display = 'none';

        clearBtn.addEventListener('click', function () {
            self.commit('');
            self.close(true);
        });
        nowBtn.addEventListener('click', function () { self.selectNow(); });
        doneBtn.addEventListener('click', function () { self.confirm(); });

        panel.addEventListener('keydown', function (e) { self.onPanelKeydown(e); });
        panel.addEventListener('mousedown', function (e) { e.stopPropagation(); });

        document.body.appendChild(panel);
        this.panel = panel;
    };

    Picker.prototype.step = function (dir) {
        if (this.mode === 'days') {
            var m = this.view.m + dir;
            var y = this.view.y;
            if (m < 0) { m = 11; y -= 1; }
            if (m > 11) { m = 0; y += 1; }
            this.view.y = y;
            this.view.m = m;
        } else if (this.mode === 'months') {
            this.view.y += dir;
        } else {
            this.view.y += dir * 12;
        }
        this.renderDatePane();
    };

    Picker.prototype.renderDatePane = function () {
        if (!this.hasDate) return;
        var self = this;

        if (this.mode === 'days') {
            this.titleBtn.innerHTML = '';
            this.titleBtn.appendChild(document.createTextNode(MONTHS[this.view.m] + ' ' + this.view.y));
            this.titleBtn.appendChild(el('i', 'fa-solid fa-chevron-down'));
            this.weekdayRow.style.display = '';
            this.daysGrid.style.display = '';
            this.altGrid.style.display = 'none';
            this.renderWeekdays();
            this.renderDays();
        } else {
            this.weekdayRow.style.display = 'none';
            this.daysGrid.style.display = 'none';
            this.altGrid.style.display = '';
            this.altGrid.innerHTML = '';

            if (this.mode === 'months') {
                this.titleBtn.textContent = this.view.y;
                this.altGrid.classList.remove('dtp-grid-years');
                var today = new Date();
                MONTHS_SHORT.forEach(function (name, idx) {
                    var b = button('dtp-cell', name);
                    if (self.hasDateSet && self.draft.y === self.view.y && self.draft.m === idx) b.classList.add('is-selected');
                    if (today.getFullYear() === self.view.y && today.getMonth() === idx) b.classList.add('is-current');
                    b.disabled = self.monthDisabled(self.view.y, idx);
                    b.addEventListener('click', function () {
                        self.view.m = idx;
                        self.mode = 'days';
                        self.renderDatePane();
                    });
                    self.altGrid.appendChild(b);
                });
            } else {
                var start = this.view.y - 6;
                this.titleBtn.textContent = start + ' – ' + (start + 11);
                this.altGrid.classList.add('dtp-grid-years');
                var nowY = new Date().getFullYear();
                for (var i = 0; i < 12; i++) {
                    (function (year) {
                        var b = button('dtp-cell', year);
                        if (self.hasDateSet && self.draft.y === year) b.classList.add('is-selected');
                        if (year === nowY) b.classList.add('is-current');
                        b.disabled = self.yearDisabled(year);
                        b.addEventListener('click', function () {
                            self.view.y = year;
                            self.mode = 'months';
                            self.renderDatePane();
                        });
                        self.altGrid.appendChild(b);
                    })(start + i);
                }
            }
        }

        this.updateNavState();
    };

    Picker.prototype.updateNavState = function () {
        if (this.mode === 'days') {
            var pm = this.view.m - 1, py = this.view.y;
            if (pm < 0) { pm = 11; py -= 1; }
            var nm = this.view.m + 1, ny = this.view.y;
            if (nm > 11) { nm = 0; ny += 1; }
            this.prevBtn.disabled = this.monthDisabled(py, pm);
            this.nextBtn.disabled = this.monthDisabled(ny, nm);
        } else if (this.mode === 'months') {
            this.prevBtn.disabled = this.yearDisabled(this.view.y - 1);
            this.nextBtn.disabled = this.yearDisabled(this.view.y + 1);
        } else {
            this.prevBtn.disabled = false;
            this.nextBtn.disabled = false;
        }
    };

    Picker.prototype.renderWeekdays = function () {
        if (this.weekdayRow.childNodes.length) return;
        for (var i = 0; i < 7; i++) {
            var name = WEEKDAYS[(i + this.weekStart) % 7];
            this.weekdayRow.appendChild(el('span', 'dtp-weekday', name.charAt(0) + name.charAt(1)));
        }
    };

    Picker.prototype.renderDays = function () {
        var self = this;
        var y = this.view.y;
        var m = this.view.m;
        this.daysGrid.innerHTML = '';

        var firstDow = new Date(y, m, 1).getDay();
        var lead = (firstDow - this.weekStart + 7) % 7;
        var total = daysInMonth(y, m);
        var prevTotal = daysInMonth(m === 0 ? y - 1 : y, m === 0 ? 11 : m - 1);
        var cells = Math.ceil((lead + total) / 7) * 7;

        var today = new Date();
        var todaySerial = daySerial(today.getFullYear(), today.getMonth(), today.getDate());

        for (var i = 0; i < cells; i++) {
            var dayNum, cy = y, cm = m, outside = false;

            if (i < lead) {
                dayNum = prevTotal - lead + 1 + i;
                cm = m - 1;
                outside = true;
            } else if (i >= lead + total) {
                dayNum = i - lead - total + 1;
                cm = m + 1;
                outside = true;
            } else {
                dayNum = i - lead + 1;
            }
            if (cm < 0) { cm = 11; cy -= 1; }
            if (cm > 11) { cm = 0; cy += 1; }

            var b = button('dtp-day', String(dayNum));
            b.dataset.y = cy;
            b.dataset.m = cm;
            b.dataset.d = dayNum;

            var dow = (i % 7 + this.weekStart) % 7;
            if (dow === 0 || dow === 6) b.classList.add('is-weekend');
            if (outside) b.classList.add('is-outside');
            if (daySerial(cy, cm, dayNum) === todaySerial) b.classList.add('is-today');
            if (this.hasDateSet && this.draft.y === cy && this.draft.m === cm && this.draft.d === dayNum) {
                b.classList.add('is-selected');
                b.setAttribute('aria-selected', 'true');
            }
            b.disabled = this.dayDisabled(cy, cm, dayNum);
            b.tabIndex = b.classList.contains('is-selected') ? 0 : -1;

            b.addEventListener('click', function () {
                self.pickDay(+this.dataset.y, +this.dataset.m, +this.dataset.d);
            });

            this.daysGrid.appendChild(b);
        }

        if (!this.daysGrid.querySelector('.dtp-day[tabindex="0"]')) {
            // Nothing chosen yet — start on today when it is in view, else the first open day.
            var fallback = this.daysGrid.querySelector('.dtp-day.is-today:not(.is-outside):not(:disabled)') ||
                this.daysGrid.querySelector('.dtp-day:not(.is-outside):not(:disabled)');
            if (fallback) fallback.tabIndex = 0;
        }
    };

    Picker.prototype.pickDay = function (y, m, d) {
        this.draft.y = y;
        this.draft.m = m;
        this.draft.d = d;
        this.hasDateSet = true;
        this.view.y = y;
        this.view.m = m;
        this.renderDatePane();

        if (this.hasTime) {
            // Date bounds can change which times are legal.
            this.renderTimePane();
        } else {
            this.confirm();
        }
    };

    Picker.prototype.renderTimePane = function () {
        if (!this.hasTime) return;
        var self = this;
        var h = this.hasTimeSet ? this.draft.h : null;
        var min = this.hasTimeSet ? this.draft.min : null;

        this.timeValueEl.textContent = this.hasTimeSet
            ? (this.use24 ? pad(h) + ':' + pad(min) : (function () {
                var hr = h % 12; if (hr === 0) hr = 12;
                return hr + ':' + pad(min);
            })())
            : '--:--';

        if (this.merButtons) {
            var active = this.hasTimeSet ? (h >= 12 ? 'PM' : 'AM') : null;
            this.merButtons.AM.classList.toggle('is-active', active === 'AM');
            this.merButtons.PM.classList.toggle('is-active', active === 'PM');
        }

        // Hours
        this.hoursGrid.innerHTML = '';
        var hourList = [];
        if (this.use24) {
            for (var i = 0; i < 24; i++) hourList.push({ label: pad(i), h: i });
        } else {
            var isPM = this.hasTimeSet ? h >= 12 : false;
            for (var k = 1; k <= 12; k++) {
                var real = k % 12 + (isPM ? 12 : 0);
                hourList.push({ label: String(k), h: real });
            }
        }
        hourList.forEach(function (item) {
            var b = button('dtp-time-btn', item.label);
            if (self.hasTimeSet && item.h === h) b.classList.add('is-selected');
            b.disabled = self.hourDisabled(item.h);
            b.addEventListener('click', function () {
                self.draft.h = item.h;
                if (!self.hasTimeSet || self.draft.min == null) self.draft.min = self.firstLegalMinute(item.h);
                self.hasTimeSet = true;
                self.renderTimePane();
            });
            self.hoursGrid.appendChild(b);
        });

        // Minutes — on-step values, plus the current off-step value if any.
        this.minutesGrid.innerHTML = '';
        var minuteList = [];
        for (var mm = 0; mm < 60; mm += this.minuteStep) minuteList.push(mm);
        if (this.hasTimeSet && min != null && minuteList.indexOf(min) === -1) {
            minuteList.push(min);
            minuteList.sort(function (a, b) { return a - b; });
        }
        minuteList.forEach(function (mv) {
            var b = button('dtp-time-btn', pad(mv));
            if (self.hasTimeSet && mv === min) b.classList.add('is-selected');
            b.disabled = !self.hasTimeSet ? false : self.timeDisabled(h, mv);
            b.addEventListener('click', function () {
                if (!self.hasTimeSet) {
                    self.draft.h = self.draft.h != null ? self.draft.h : 12;
                    self.hasTimeSet = true;
                }
                self.draft.min = mv;
                self.renderTimePane();
            });
            self.minutesGrid.appendChild(b);
        });

        this.presetButtons.forEach(function (b) {
            var ph = +b.dataset.h, pm = +b.dataset.m;
            b.disabled = self.timeDisabled(ph, pm);
            b.classList.toggle('is-selected', self.hasTimeSet && h === ph && min === pm);
        });
    };

    Picker.prototype.firstLegalMinute = function (hour) {
        for (var m = 0; m < 60; m += this.minuteStep) {
            if (!this.timeDisabled(hour, m)) return m;
        }
        return 0;
    };

    Picker.prototype.setMeridiem = function (mer) {
        if (!this.hasTimeSet) {
            this.draft.h = mer === 'PM' ? 12 : 9;
            this.draft.min = 0;
            this.hasTimeSet = true;
        } else {
            var h = this.draft.h % 12;
            this.draft.h = mer === 'PM' ? h + 12 : h;
        }
        this.renderTimePane();
    };

    Picker.prototype.selectNow = function () {
        var n = new Date();
        if (this.hasDate) {
            this.draft.y = n.getFullYear();
            this.draft.m = n.getMonth();
            this.draft.d = n.getDate();
            this.hasDateSet = true;
            this.view.y = this.draft.y;
            this.view.m = this.draft.m;
            this.mode = 'days';
            this.renderDatePane();
        }
        if (this.hasTime) {
            var step = this.minuteStep;
            this.draft.h = n.getHours();
            this.draft.min = Math.min(55, Math.round(n.getMinutes() / step) * step % 60);
            this.hasTimeSet = true;
            this.renderTimePane();
        }
        if (!this.hasTime) this.confirm();
    };

    Picker.prototype.confirm = function () {
        if (this.hasDate && !this.hasDateSet) return;
        if (this.hasTime && !this.hasTimeSet) {
            if (this.hasDate) {
                // Date-only selection on a datetime field: default to 09:00.
                this.draft.h = 9;
                this.draft.min = 0;
                this.hasTimeSet = true;
            } else {
                return;
            }
        }
        this.commit(formatValue(this.draft, this.hasDate, this.hasTime));
        this.close(true);
    };

    Picker.prototype.commit = function (raw) {
        this.suppressSync = true;
        this.input.value = raw;
        this.suppressSync = false;

        this.value = parseValue(raw, this.hasDate, this.hasTime);
        this.hasDateSet = !!(this.value && this.value.y != null);
        this.hasTimeSet = !!(this.value && this.value.h != null);
        this.wrap.classList.remove('is-invalid');
        this.input.classList.remove('profile-input--error');
        this.renderTrigger();

        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    /* ── open / close / position ── */

    Picker.prototype.open = function () {
        if (this.isOpen || this.trigger.disabled || this.input.readOnly) return;
        if (openPicker && openPicker !== this) openPicker.close();

        this.readBounds();
        this.syncFromInput();
        if (!this.panel) this.buildPanel();

        this.mode = 'days';
        this.renderDatePane();
        this.renderTimePane();

        this.isOpen = true;
        openPicker = this;
        this.wrap.classList.add('is-open');
        this.trigger.setAttribute('aria-expanded', 'true');
        this.panel.classList.add('is-open');

        this.isSheet = window.matchMedia('(max-width: 640px)').matches;
        this.panel.classList.toggle('dtp-sheet', this.isSheet);

        if (this.isSheet) {
            this.showBackdrop();
        } else {
            this.position();
            this.bindReposition();
        }

        var self = this;
        window.requestAnimationFrame(function () {
            if (!self.isOpen) return;
            self.panel.classList.add('is-visible');
            var focusTarget = self.panel.querySelector('.dtp-day[tabindex="0"]') ||
                self.panel.querySelector('.dtp-time-btn.is-selected') ||
                self.panel.querySelector('.dtp-btn-primary');
            if (focusTarget) focusTarget.focus({ preventScroll: true });
        });
    };

    Picker.prototype.showBackdrop = function () {
        var self = this;
        if (!this.backdrop) {
            this.backdrop = el('div', 'dtp-backdrop');
            this.backdrop.addEventListener('click', function () { self.close(true); });
            document.body.appendChild(this.backdrop);
        }
        this.backdrop.classList.add('is-open');
        window.requestAnimationFrame(function () {
            if (self.isOpen) self.backdrop.classList.add('is-visible');
        });
    };

    Picker.prototype.position = function () {
        var rect = this.trigger.getBoundingClientRect();
        var panel = this.panel;
        panel.style.visibility = 'hidden';
        panel.style.top = '0px';
        panel.style.left = '0px';
        var pw = panel.offsetWidth;
        var ph = panel.offsetHeight;
        panel.style.visibility = '';

        var gap = 6;
        var vh = window.innerHeight;
        var vw = window.innerWidth;

        var above = rect.bottom + gap + ph > vh && rect.top - gap - ph > 0;
        var top = above ? rect.top - gap - ph : rect.bottom + gap;
        var left = Math.min(Math.max(8, rect.left), vw - pw - 8);

        panel.classList.toggle('is-above', above);
        panel.style.top = Math.round(top) + 'px';
        panel.style.left = Math.round(left) + 'px';
    };

    Picker.prototype.bindReposition = function () {
        var self = this;
        this.reposition = function () { self.position(); };
        window.addEventListener('scroll', this.reposition, true);
        window.addEventListener('resize', this.reposition);
    };

    Picker.prototype.close = function (focusTrigger) {
        if (!this.isOpen) return;
        this.isOpen = false;
        if (openPicker === this) openPicker = null;

        this.wrap.classList.remove('is-open');
        this.trigger.setAttribute('aria-expanded', 'false');
        this.panel.classList.remove('is-visible');
        if (this.backdrop) this.backdrop.classList.remove('is-visible');

        if (this.reposition) {
            window.removeEventListener('scroll', this.reposition, true);
            window.removeEventListener('resize', this.reposition);
            this.reposition = null;
        }

        var self = this;
        window.setTimeout(function () {
            if (self.isOpen) return;
            self.panel.classList.remove('is-open');
            if (self.backdrop) self.backdrop.classList.remove('is-open');
        }, 200);

        if (focusTrigger) this.trigger.focus();
    };

    Picker.prototype.onPanelKeydown = function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            this.close(true);
            return;
        }
        if (e.key === 'Tab') {
            this.trapTab(e);
            return;
        }

        var day = e.target.closest && e.target.closest('.dtp-day');
        if (!day || this.mode !== 'days') return;

        var delta = 0;
        switch (e.key) {
            case 'ArrowLeft': delta = -1; break;
            case 'ArrowRight': delta = 1; break;
            case 'ArrowUp': delta = -7; break;
            case 'ArrowDown': delta = 7; break;
            case 'Home': delta = -((+day.dataset.d - 1) % 7); break;
            case 'End': delta = 6; break;
            case 'PageUp': e.preventDefault(); this.step(-1); this.focusDay(); return;
            case 'PageDown': e.preventDefault(); this.step(1); this.focusDay(); return;
            default: return;
        }

        e.preventDefault();
        var base = new Date(+day.dataset.y, +day.dataset.m, +day.dataset.d);
        base.setDate(base.getDate() + delta);

        if (base.getFullYear() !== this.view.y || base.getMonth() !== this.view.m) {
            this.view.y = base.getFullYear();
            this.view.m = base.getMonth();
            this.renderDatePane();
        }
        this.focusDay(base.getFullYear(), base.getMonth(), base.getDate());
    };

    Picker.prototype.focusDay = function (y, m, d) {
        var target;
        if (y == null) {
            target = this.daysGrid.querySelector('.dtp-day.is-selected') ||
                this.daysGrid.querySelector('.dtp-day:not(.is-outside)');
        } else {
            target = this.daysGrid.querySelector('.dtp-day[data-y="' + y + '"][data-m="' + m + '"][data-d="' + d + '"]');
        }
        if (!target) return;
        this.daysGrid.querySelectorAll('.dtp-day').forEach(function (b) { b.tabIndex = -1; });
        target.tabIndex = 0;
        target.focus({ preventScroll: true });
    };

    Picker.prototype.trapTab = function (e) {
        var focusables = this.panel.querySelectorAll(
            'button:not(:disabled):not([tabindex="-1"]), [tabindex="0"]'
        );
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };

    /* ── boot ── */

    function attach(input) {
        if (!input || input.dtpInstance) return null;
        var type = input.getAttribute('type');
        if (['date', 'time', 'datetime-local'].indexOf(type) === -1) return null;
        input.dtpInstance = new Picker(input);
        return input.dtpInstance;
    }

    function refresh(root) {
        var scope = root || document;
        scope.querySelectorAll('input[data-dtp]:not([data-dtp-skip])').forEach(attach);
    }

    document.addEventListener('mousedown', function (e) {
        if (!openPicker) return;
        if (openPicker.wrap.contains(e.target)) return;
        openPicker.close();
    });

    document.addEventListener('DOMContentLoaded', function () { refresh(); });
    if (document.readyState !== 'loading') refresh();

    window.DateTimePicker = { attach: attach, refresh: refresh };
})();
