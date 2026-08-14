/**
 * Custom dropdown (select enhancement).
 *
 * Progressive enhancement: the native <select> stays in the DOM and keeps its
 * name/value/required, so server-side code and browser validation are
 * untouched. Only the UI is replaced.
 *
 * Usage
 *   <select name="event_type" data-cs> … </select>
 *   <select name="group" data-cs data-cs-search="auto"> … </select>
 *   <select name="tags" multiple data-cs> … </select>
 *
 * Attributes (on the <select>)
 *   data-cs              opt in
 *   data-cs-skip         opt out
 *   data-cs-search       "auto" (default, shows above 7 options) | "always" | "never"
 *   data-cs-placeholder  trigger text when nothing is chosen
 *   data-cs-icon         Font Awesome classes for the trigger icon
 *   data-cs-size         "sm" for the compact toolbar variant
 *
 * Attributes (on each <option>)
 *   data-icon            Font Awesome classes shown before the label
 *   data-hint            secondary line under the label
 *
 * An empty-valued first option is treated as the placeholder.
 * The panel is portalled to <body> because form sections use overflow:hidden.
 */
(function () {
    'use strict';

    var openSelect = null;
    var uid = 0;

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

    function normalize(str) {
        return (str || '').toLowerCase().trim();
    }

    /* ── control ── */

    function CustomSelect(select) {
        this.select = select;
        this.multiple = select.multiple;
        this.id = 'cs-' + (++uid);
        this.placeholder = select.dataset.csPlaceholder || this.derivePlaceholder();
        this.searchMode = select.dataset.csSearch || 'auto';
        this.activeIndex = -1;

        this.build();
        this.syncFromSelect();

        var self = this;
        select.addEventListener('change', function () {
            if (!self.suppressSync) self.syncFromSelect();
        });
        select.addEventListener('invalid', function () {
            self.wrap.classList.add('is-invalid');
        });
        select.form && select.form.addEventListener('reset', function () {
            window.setTimeout(function () { self.syncFromSelect(); }, 0);
        });
    }

    CustomSelect.prototype.derivePlaceholder = function () {
        var first = this.select.options[0];
        if (first && first.value === '') return first.textContent.trim();
        return this.multiple ? 'Select options' : 'Select an option';
    };

    CustomSelect.prototype.build = function () {
        var select = this.select;
        var wrap = el('div', 'cs');

        if (select.dataset.csSize === 'sm') wrap.classList.add('cs-sm');
        if (select.classList.contains('profile-input--error')) wrap.classList.add('is-invalid');

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('cs-native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        var trigger = button('cs-trigger');
        trigger.id = this.id + '-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.disabled = select.disabled;

        if (select.dataset.csIcon) {
            var icon = el('i', 'cs-trigger-icon ' + select.dataset.csIcon);
            trigger.appendChild(icon);
        }

        this.triggerText = el('span', 'cs-trigger-text');
        trigger.appendChild(this.triggerText);

        if (this.multiple) {
            this.countEl = el('span', 'cs-count');
            this.countEl.style.display = 'none';
            trigger.appendChild(this.countEl);
        }

        trigger.appendChild(el('i', 'cs-trigger-caret fa-solid fa-chevron-down'));
        wrap.appendChild(trigger);

        var label = select.id && document.querySelector('label[for="' + select.id + '"]');
        if (label) {
            if (!label.id) label.id = this.id + '-label';
            trigger.setAttribute('aria-labelledby', label.id + ' ' + trigger.id);
            label.addEventListener('click', function (e) {
                e.preventDefault();
                trigger.focus();
            });
        } else if (select.getAttribute('aria-label')) {
            trigger.setAttribute('aria-label', select.getAttribute('aria-label'));
        }

        this.wrap = wrap;
        this.trigger = trigger;

        var self = this;
        trigger.addEventListener('click', function () {
            self.isOpen ? self.close() : self.open();
        });
        trigger.addEventListener('keydown', function (e) { self.onTriggerKeydown(e); });
    };

    /** Read <option>/<optgroup> structure into a flat model. */
    CustomSelect.prototype.readOptions = function () {
        var items = [];
        var children = this.select.children;

        for (var i = 0; i < children.length; i++) {
            var node = children[i];
            if (node.tagName === 'OPTGROUP') {
                items.push({ group: true, label: node.label });
                for (var j = 0; j < node.children.length; j++) {
                    this.pushOption(items, node.children[j], node.disabled);
                }
            } else if (node.tagName === 'OPTION') {
                this.pushOption(items, node, false);
            }
        }
        return items;
    };

    CustomSelect.prototype.pushOption = function (items, option, groupDisabled) {
        // An empty-valued first option supplies the trigger's placeholder text, but
        // stays in the list so it can be re-selected (e.g. "All groups" on a filter).
        var isPlaceholder = option.value === '' && !this.multiple &&
            items.filter(function (i) { return !i.group; }).length === 0;

        items.push({
            group: false,
            placeholder: isPlaceholder,
            value: option.value,
            label: option.textContent.trim(),
            hint: option.dataset.hint || '',
            icon: option.dataset.icon || '',
            disabled: option.disabled || groupDisabled,
            option: option
        });
    };

    CustomSelect.prototype.syncFromSelect = function () {
        this.items = this.readOptions();
        this.renderTrigger();
        if (this.isOpen) this.renderList();
    };

    CustomSelect.prototype.selectedItems = function () {
        return this.items.filter(function (i) { return !i.group && i.option.selected; });
    };

    CustomSelect.prototype.renderTrigger = function () {
        var chosen = this.selectedItems().filter(function (i) { return !i.placeholder; });

        if (!chosen.length) {
            this.triggerText.textContent = this.placeholder;
            this.triggerText.classList.add('is-placeholder');
        } else {
            this.triggerText.textContent = chosen.map(function (i) { return i.label; }).join(', ');
            this.triggerText.classList.remove('is-placeholder');
        }

        if (this.countEl) {
            this.countEl.textContent = chosen.length;
            this.countEl.style.display = chosen.length > 1 ? '' : 'none';
        }
    };

    /* ── panel ── */

    CustomSelect.prototype.buildPanel = function () {
        var self = this;
        var panel = el('div', 'cs-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', this.placeholder);

        var sheetHead = el('div', 'cs-sheet-head');
        sheetHead.appendChild(el('span', 'cs-sheet-title', this.placeholder));
        var sheetClose = button('cs-sheet-close', '<i class="fa-solid fa-xmark"></i>');
        sheetClose.setAttribute('aria-label', 'Close');
        sheetClose.addEventListener('click', function () { self.close(true); });
        sheetHead.appendChild(sheetClose);
        panel.appendChild(sheetHead);

        this.searchWrap = el('div', 'cs-search-wrap');
        this.searchWrap.appendChild(el('i', 'cs-search-icon fa-solid fa-magnifying-glass'));
        this.searchInput = el('input', 'cs-search');
        this.searchInput.type = 'text';
        this.searchInput.placeholder = 'Search…';
        this.searchInput.autocomplete = 'off';
        this.searchWrap.appendChild(this.searchInput);
        panel.appendChild(this.searchWrap);

        this.list = el('div', 'cs-list');
        this.list.setAttribute('role', 'listbox');
        if (this.multiple) this.list.setAttribute('aria-multiselectable', 'true');
        panel.appendChild(this.list);

        if (this.multiple) {
            var foot = el('div', 'cs-foot');
            var clearBtn = button('cs-btn cs-btn-ghost', 'Clear');
            var doneBtn = button('cs-btn cs-btn-primary', 'Done');
            foot.appendChild(clearBtn);
            foot.appendChild(el('div', 'cs-foot-spacer'));
            foot.appendChild(doneBtn);
            panel.appendChild(foot);

            clearBtn.addEventListener('click', function () {
                self.items.forEach(function (i) { if (!i.group) i.option.selected = false; });
                self.commit();
                self.renderList();
            });
            doneBtn.addEventListener('click', function () { self.close(true); });
        }

        this.searchInput.addEventListener('input', function () {
            self.query = this.value;
            self.activeIndex = -1;
            self.renderList();
        });
        this.searchInput.addEventListener('keydown', function (e) { self.onPanelKeydown(e); });
        panel.addEventListener('keydown', function (e) { self.onPanelKeydown(e); });
        panel.addEventListener('mousedown', function (e) { e.stopPropagation(); });

        document.body.appendChild(panel);
        this.panel = panel;
    };

    CustomSelect.prototype.showSearch = function () {
        if (this.searchMode === 'always') return true;
        if (this.searchMode === 'never') return false;
        return this.items.filter(function (i) { return !i.group; }).length > 7;
    };

    CustomSelect.prototype.renderList = function () {
        var self = this;
        var q = normalize(this.query);
        this.list.innerHTML = '';
        this.optionEls = [];

        var pendingGroup = null;
        var shown = 0;

        this.items.forEach(function (item) {
            if (item.group) {
                pendingGroup = item.label;
                return;
            }
            if (q && normalize(item.label).indexOf(q) === -1 && normalize(item.hint).indexOf(q) === -1) {
                return;
            }
            if (pendingGroup) {
                self.list.appendChild(el('div', 'cs-group-label', pendingGroup));
                pendingGroup = null;
            }

            var b = button('cs-option');
            b.setAttribute('role', 'option');
            b.dataset.value = item.value;
            b.disabled = item.disabled;

            if (self.multiple) {
                var box = el('span', 'cs-option-box');
                box.innerHTML = '<i class="fa-solid fa-check"></i>';
                b.appendChild(box);
            } else if (item.icon) {
                b.appendChild(el('i', 'cs-option-icon ' + item.icon));
            }

            var body = el('div', 'cs-option-body');
            body.appendChild(el('span', 'cs-option-label', item.label));
            if (item.hint) body.appendChild(el('span', 'cs-option-hint', item.hint));
            b.appendChild(body);

            if (!self.multiple) {
                var mark = el('i', 'cs-option-mark fa-solid fa-check');
                b.appendChild(mark);
            }

            if (item.option.selected) {
                b.classList.add('is-selected');
                b.setAttribute('aria-selected', 'true');
            } else {
                b.setAttribute('aria-selected', 'false');
            }

            b.addEventListener('click', function () { self.choose(item); });
            b.addEventListener('mousemove', function () {
                self.setActive(self.optionEls.indexOf(b));
            });

            self.list.appendChild(b);
            self.optionEls.push(b);
            shown++;
        });

        if (!shown) {
            this.list.appendChild(el('div', 'cs-empty', q ? 'No matches for “' + this.query + '”' : 'No options available'));
        }

        this.syncActive();
    };

    CustomSelect.prototype.choose = function (item) {
        if (this.multiple) {
            item.option.selected = !item.option.selected;
            this.commit();
            this.renderList();
            return;
        }
        this.items.forEach(function (i) { if (!i.group) i.option.selected = false; });
        item.option.selected = true;
        this.commit();
        this.close(true);
    };

    CustomSelect.prototype.commit = function () {
        this.suppressSync = true;
        this.select.dispatchEvent(new Event('input', { bubbles: true }));
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
        this.suppressSync = false;

        this.wrap.classList.remove('is-invalid');
        this.select.classList.remove('profile-input--error');
        this.renderTrigger();
    };

    /* ── keyboard ── */

    CustomSelect.prototype.onTriggerKeydown = function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.open();
            return;
        }
        // Type-ahead straight from the closed trigger, like a native select.
        if (!this.multiple && e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) {
            var match = this.items.filter(function (i) {
                return !i.group && !i.disabled && normalize(i.label).indexOf(normalize(e.key)) === 0;
            })[0];
            if (match) {
                e.preventDefault();
                this.choose(match);
            }
        }
    };

    CustomSelect.prototype.onPanelKeydown = function (e) {
        switch (e.key) {
            case 'Escape':
                e.preventDefault();
                this.close(true);
                return;
            case 'ArrowDown':
                e.preventDefault();
                this.move(1);
                return;
            case 'ArrowUp':
                e.preventDefault();
                this.move(-1);
                return;
            case 'Home':
                if (e.target === this.searchInput) return;
                e.preventDefault();
                this.setActive(this.firstEnabled(0, 1));
                return;
            case 'End':
                if (e.target === this.searchInput) return;
                e.preventDefault();
                this.setActive(this.firstEnabled(this.optionEls.length - 1, -1));
                return;
            case 'Enter':
                e.preventDefault();
                if (this.activeIndex >= 0 && this.optionEls[this.activeIndex]) {
                    this.optionEls[this.activeIndex].click();
                } else if (this.multiple) {
                    this.close(true);
                }
                return;
            case 'Tab':
                this.close();
                return;
            default:
                return;
        }
    };

    CustomSelect.prototype.firstEnabled = function (from, dir) {
        for (var i = from; i >= 0 && i < this.optionEls.length; i += dir) {
            if (!this.optionEls[i].disabled) return i;
        }
        return -1;
    };

    CustomSelect.prototype.move = function (dir) {
        if (!this.optionEls.length) return;
        var start = this.activeIndex < 0 ? (dir > 0 ? -1 : this.optionEls.length) : this.activeIndex;
        var next = this.firstEnabled(start + dir, dir);
        if (next === -1) next = this.firstEnabled(dir > 0 ? 0 : this.optionEls.length - 1, dir);
        this.setActive(next);
    };

    CustomSelect.prototype.setActive = function (index) {
        this.activeIndex = index;
        this.syncActive();
    };

    CustomSelect.prototype.syncActive = function () {
        var self = this;
        this.optionEls.forEach(function (b, i) {
            b.classList.toggle('is-active', i === self.activeIndex);
        });
        var active = this.optionEls[this.activeIndex];
        if (active) {
            active.scrollIntoView({ block: 'nearest' });
            if (active.id === '') active.id = this.id + '-opt-' + this.activeIndex;
            this.list.setAttribute('aria-activedescendant', active.id);
        } else {
            this.list.removeAttribute('aria-activedescendant');
        }
    };

    /* ── open / close / position ── */

    CustomSelect.prototype.open = function () {
        if (this.isOpen || this.trigger.disabled) return;
        if (openSelect && openSelect !== this) openSelect.close();

        this.syncFromSelect();
        if (!this.panel) this.buildPanel();

        this.query = '';
        this.searchInput.value = '';
        this.searchWrap.style.display = this.showSearch() ? '' : 'none';

        // Start on the current selection so arrow keys continue from there.
        this.renderList();
        var selectedIdx = this.optionEls.findIndex(function (b) { return b.classList.contains('is-selected'); });
        this.setActive(this.multiple ? -1 : selectedIdx);

        this.isOpen = true;
        openSelect = this;
        this.wrap.classList.add('is-open');
        this.trigger.setAttribute('aria-expanded', 'true');
        this.panel.classList.add('is-open');

        this.isSheet = window.matchMedia('(max-width: 640px)').matches;
        this.panel.classList.toggle('cs-sheet', this.isSheet);

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
            if (self.showSearch() && !self.isSheet) {
                self.searchInput.focus({ preventScroll: true });
            } else {
                var target = self.optionEls[self.activeIndex] || self.optionEls[0];
                if (target) target.focus({ preventScroll: true });
            }
            self.syncActive();
        });
    };

    CustomSelect.prototype.showBackdrop = function () {
        var self = this;
        if (!this.backdrop) {
            this.backdrop = el('div', 'cs-backdrop');
            this.backdrop.addEventListener('click', function () { self.close(true); });
            document.body.appendChild(this.backdrop);
        }
        this.backdrop.classList.add('is-open');
        window.requestAnimationFrame(function () {
            if (self.isOpen) self.backdrop.classList.add('is-visible');
        });
    };

    CustomSelect.prototype.position = function () {
        var rect = this.trigger.getBoundingClientRect();
        var panel = this.panel;

        panel.style.width = Math.round(rect.width) + 'px';
        panel.style.visibility = 'hidden';
        panel.style.top = '0px';
        panel.style.left = '0px';
        var ph = panel.offsetHeight;
        var pw = panel.offsetWidth;
        panel.style.visibility = '';

        var gap = 6;
        var above = rect.bottom + gap + ph > window.innerHeight && rect.top - gap - ph > 0;
        var top = above ? rect.top - gap - ph : rect.bottom + gap;
        var left = Math.min(Math.max(8, rect.left), window.innerWidth - pw - 8);

        panel.classList.toggle('is-above', above);
        panel.style.top = Math.round(top) + 'px';
        panel.style.left = Math.round(left) + 'px';
    };

    CustomSelect.prototype.bindReposition = function () {
        var self = this;
        this.reposition = function () { self.position(); };
        window.addEventListener('scroll', this.reposition, true);
        window.addEventListener('resize', this.reposition);
    };

    CustomSelect.prototype.close = function (focusTrigger) {
        if (!this.isOpen) return;
        this.isOpen = false;
        if (openSelect === this) openSelect = null;

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

    /* ── boot ── */

    function attach(select) {
        if (!select || select.csInstance || select.tagName !== 'SELECT') return null;
        select.csInstance = new CustomSelect(select);
        return select.csInstance;
    }

    function refresh(root) {
        (root || document).querySelectorAll('select[data-cs]:not([data-cs-skip])').forEach(attach);
    }

    document.addEventListener('mousedown', function (e) {
        if (!openSelect) return;
        if (openSelect.wrap.contains(e.target)) return;
        openSelect.close();
    });

    document.addEventListener('DOMContentLoaded', function () { refresh(); });
    if (document.readyState !== 'loading') refresh();

    window.CustomSelect = { attach: attach, refresh: refresh };
})();
