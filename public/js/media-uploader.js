/**
 * Uploads images the moment they are picked, instead of when the form is saved.
 *
 * Opt in by adding data-upload-slot + data-upload-url to any file input. The native
 * input keeps its name and stays in the DOM, so when this script does not run the
 * form still posts binaries and the controller still accepts them — the same
 * progressive-enhancement contract event-edit-save.js uses.
 *
 * data-upload-commit="1" persists the file on the server immediately (admin hero)
 * instead of staging an id for a later save. Success is HTTP 201 + { url }.
 * data-upload-queue on an ancestor mounts the tile list there instead of next
 * to the input (so the input can sit under a pick button).
 *
 * Two things here are load-bearing:
 *
 *   1. XMLHttpRequest, not fetch. fetch() reports no upload progress, and a real
 *      percentage is the entire point of moving the transfer earlier.
 *   2. input.value is cleared as soon as the files are read. Leaving them there
 *      would submit the binary alongside the staged id and store the image twice.
 */
(function () {
    'use strict';

    var SINGLE_SLOTS = ['hero_portrait', 'cover', 'audio'];

    var inFlight = 0;
    var failed = 0;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function isSingle(slot) {
        return SINGLE_SLOTS.indexOf(slot) !== -1 || /^speaker:[0-3]$/.test(slot);
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function accepts(input, file) {
        var accept = (input.getAttribute('accept') || '').trim();
        if (!accept) return true;

        return accept.split(',').some(function (rule) {
            rule = rule.trim().toLowerCase();
            if (!rule) return false;
            if (rule.slice(-2) === '/*') {
                return file.type.toLowerCase().indexOf(rule.slice(0, -1)) === 0;
            }
            if (rule.charAt(0) === '.') {
                return file.name.toLowerCase().slice(-rule.length) === rule;
            }
            return file.type.toLowerCase() === rule;
        });
    }

    function Uploader(input) {
        this.input = input;
        this.slot = input.dataset.uploadSlot;
        this.url = input.dataset.uploadUrl;
        this.maxBytes = parseInt(input.dataset.uploadMaxBytes || '0', 10);
        this.single = isSingle(this.slot);
        this.form = input.form || input.closest('form');
        this.commit = input.dataset.uploadCommit === '1';
        this.items = [];

        this.queue = document.createElement('ul');
        this.queue.className = 'mup-queue';
        this.queue.setAttribute('aria-live', 'polite');
        var host = input.closest('[data-upload-queue]');
        if (host) {
            host.appendChild(this.queue);
        } else {
            input.parentNode.insertBefore(this.queue, input.nextSibling);
        }

        this.bind();
    }

    Uploader.prototype.bind = function () {
        var self = this;

        this.input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(self.input.files || []);

            // Cleared out of band so other change listeners on the same input —
            // the cover preview in events-form.js, for one — still see the files
            // regardless of which handler was registered first.
            setTimeout(function () {
                self.input.value = '';
            }, 0);

            files.forEach(function (file) {
                self.add(file);
            });
        });

        // Dropping onto the field itself is the gesture people try first.
        var zone = this.input.closest('.profile-field') || this.input.parentNode;
        ['dragenter', 'dragover'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.add('mup-drop-active');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            zone.addEventListener(evt, function (e) {
                e.preventDefault();
                zone.classList.remove('mup-drop-active');
            });
        });
        zone.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files) return;
            Array.prototype.slice.call(e.dataTransfer.files).forEach(function (file) {
                self.add(file);
            });
        });
    };

    Uploader.prototype.add = function (file) {
        if (!accepts(this.input, file)) {
            this.addRejected(file, 'Unsupported file type');
            return;
        }
        if (this.maxBytes > 0 && file.size > this.maxBytes) {
            // Rejected before a single byte leaves the device.
            this.addRejected(file, 'Too large — max ' + formatBytes(this.maxBytes));
            return;
        }

        if (this.single) {
            this.clearAll();
        }

        var item = this.buildTile(file);
        this.items.push(item);
        this.upload(item);
    };

    Uploader.prototype.addRejected = function (file, reason) {
        var item = this.buildTile(file);
        this.items.push(item);
        this.setState(item, 'error', reason, false);
    };

    Uploader.prototype.buildTile = function (file) {
        var li = document.createElement('li');
        li.className = 'mup-tile';

        var thumb = document.createElement('div');
        thumb.className = 'mup-thumb';
        if (/^image\//.test(file.type)) {
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = '';
            img.addEventListener('load', function () {
                URL.revokeObjectURL(img.src);
            });
            thumb.appendChild(img);
        } else {
            thumb.innerHTML = '<i class="fa-solid fa-music" aria-hidden="true"></i>';
        }
        li.appendChild(thumb);

        var body = document.createElement('div');
        body.className = 'mup-body';

        var name = document.createElement('span');
        name.className = 'mup-name';
        name.textContent = file.name;
        body.appendChild(name);

        var track = document.createElement('div');
        track.className = 'mup-track';
        var bar = document.createElement('div');
        bar.className = 'mup-bar';
        bar.style.width = '0%';
        track.appendChild(bar);
        body.appendChild(track);

        var status = document.createElement('span');
        status.className = 'mup-status';
        status.textContent = 'Waiting…';
        body.appendChild(status);

        li.appendChild(body);

        var actions = document.createElement('div');
        actions.className = 'mup-actions';

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'mup-btn mup-retry';
        retry.innerHTML = '<i class="fa-solid fa-rotate-right" aria-hidden="true"></i>';
        retry.title = 'Try this upload again';
        retry.hidden = true;
        actions.appendChild(retry);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'mup-btn mup-remove';
        remove.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        remove.title = 'Remove';
        actions.appendChild(remove);

        li.appendChild(actions);
        this.queue.appendChild(li);

        var item = {
            file: file,
            el: li,
            bar: bar,
            status: status,
            retry: retry,
            removeBtn: remove,
            hidden: null,
            xhr: null,
            stagedId: null,
            state: 'idle',
        };

        var self = this;
        retry.addEventListener('click', function () {
            retry.hidden = true;
            self.upload(item);
        });
        remove.addEventListener('click', function () {
            self.discard(item);
        });

        return item;
    };

    Uploader.prototype.setState = function (item, state, message, showRetry) {
        if (item.state === 'uploading' && state !== 'uploading') {
            inFlight--;
        }
        if (item.state === 'error' && state !== 'error') {
            failed--;
        }
        if (state === 'uploading' && item.state !== 'uploading') {
            inFlight++;
        }
        if (state === 'error' && item.state !== 'error') {
            failed++;
        }

        item.state = state;
        item.el.className = 'mup-tile mup-tile--' + state;
        item.status.textContent = message;
        item.retry.hidden = !showRetry;
    };

    Uploader.prototype.upload = function (item) {
        var self = this;
        var data = new FormData();
        data.append('slot', this.slot);
        data.append('file', item.file);

        var xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', this.url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.withCredentials = true;

        // The only source of a real percentage — fetch() cannot do this.
        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round((e.loaded / e.total) * 100);
            item.bar.style.width = pct + '%';
            self.setState(item, 'uploading', pct < 100 ? 'Uploading… ' + pct + '%' : 'Finishing…', false);
        });

        xhr.addEventListener('load', function () {
            item.xhr = null;
            var payload = {};
            try {
                payload = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
                payload = {};
            }

            var committed = self.commit && xhr.status === 201 && payload.url;
            var staged = !self.commit && xhr.status === 201 && payload.id;

            if (committed || staged) {
                item.bar.style.width = '100%';
                if (staged) {
                    item.stagedId = payload.id;
                    self.attachHidden(item, payload.id);
                }
                self.setState(item, 'done', self.commit ? 'Saved' : 'Ready', false);
                if (committed) {
                    item.removeBtn.hidden = true;
                    self.input.dispatchEvent(new CustomEvent('mediauploader:complete', {
                        bubbles: true,
                        detail: payload,
                    }));
                }
                return;
            }

            var message = 'Upload failed';
            if (xhr.status === 422 && payload.errors && payload.errors.file) {
                message = payload.errors.file[0];
            } else if (xhr.status === 429) {
                message = 'Too many uploads — wait a moment';
            } else if (payload.message) {
                message = payload.message;
            }

            item.bar.style.width = '0%';
            self.setState(item, 'error', message, true);
        });

        xhr.addEventListener('error', function () {
            item.xhr = null;
            item.bar.style.width = '0%';
            self.setState(item, 'error', 'Connection lost', true);
        });

        xhr.addEventListener('abort', function () {
            item.xhr = null;
        });

        item.bar.style.width = '0%';
        this.setState(item, 'uploading', 'Uploading…', false);
        xhr.send(data);
    };

    Uploader.prototype.attachHidden = function (item, id) {
        if (!this.form) return;
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'staged_media[]';
        hidden.value = String(id);
        this.form.appendChild(hidden);
        item.hidden = hidden;
    };

    Uploader.prototype.detach = function (item) {
        if (item.hidden && item.hidden.parentNode) {
            item.hidden.parentNode.removeChild(item.hidden);
        }
        item.hidden = null;
    };

    /** Remove the tile and tell the server to drop the file it is holding. */
    Uploader.prototype.discard = function (item) {
        if (item.xhr) {
            item.xhr.abort();
        }
        if (item.state === 'uploading') {
            inFlight--;
        }
        if (item.state === 'error') {
            failed--;
        }
        item.state = 'discarded';

        this.detach(item);

        if (item.stagedId) {
            var xhr = new XMLHttpRequest();
            xhr.open('DELETE', this.url + '/' + item.stagedId, true);
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.withCredentials = true;
            xhr.send();
        }

        if (item.el.parentNode) {
            item.el.parentNode.removeChild(item.el);
        }
        this.items = this.items.filter(function (i) {
            return i !== item;
        });
    };

    Uploader.prototype.clearAll = function () {
        this.items.slice().forEach(this.discard, this);
    };

    function init(root) {
        (root || document).querySelectorAll('[data-upload-slot]').forEach(function (input) {
            if (input.dataset.mupReady === '1') return;
            if (!input.dataset.uploadUrl) return;
            input.dataset.mupReady = '1';
            new Uploader(input);
        });
    }

    window.MediaUploader = {
        refresh: init,
        /** In-flight uploads — event-edit-save.js waits on this before saving. */
        pending: function () {
            return inFlight;
        },
        /** Uploads that failed and were never retried. */
        failed: function () {
            return failed;
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})();
