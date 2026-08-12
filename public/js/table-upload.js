(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('tbupForm');
        if (!form) {
            return;
        }

        var dropZone = document.getElementById('tbupDrop');
        var fileInput = document.getElementById('tbupPhoto');
        var queueList = document.getElementById('tbupQueue');
        var nameInput = document.getElementById('tbupName');
        var status = document.getElementById('tbupStatus');
        var submitBtn = document.getElementById('tbupSubmit');
        var tokenInput = form.querySelector('input[name="_token"]');

        var queue = [];

        function addFiles(fileList) {
            Array.prototype.forEach.call(fileList, function (file) {
                if (!/^image\//.test(file.type)) {
                    return;
                }

                var li = document.createElement('li');
                li.className = 'tbup-queue-item';

                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = '';
                li.appendChild(img);

                var badge = document.createElement('span');
                badge.className = 'tbup-queue-badge';
                badge.textContent = 'Ready';
                li.appendChild(badge);

                queueList.appendChild(li);
                queue.push({ file: file, badge: badge, state: 'ready' });
            });
        }

        fileInput.addEventListener('change', function () {
            addFiles(fileInput.files);
            fileInput.value = '';
        });

        if (dropZone) {
            ['dragenter', 'dragover'].forEach(function (evt) {
                dropZone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    dropZone.classList.add('tbup-drop--active');
                });
            });

            ['dragleave', 'drop'].forEach(function (evt) {
                dropZone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    dropZone.classList.remove('tbup-drop--active');
                });
            });

            dropZone.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files) {
                    addFiles(e.dataTransfer.files);
                }
            });
        }

        function uploadOne(item) {
            item.state = 'uploading';
            item.badge.textContent = 'Uploading…';
            item.badge.className = 'tbup-queue-badge tbup-queue-badge--busy';

            var fd = new FormData();
            fd.append('_token', tokenInput ? tokenInput.value : '');
            fd.append('photo', item.file);
            if (nameInput && nameInput.value) {
                fd.append('uploader_name', nameInput.value);
            }

            return fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: { Accept: 'application/json' },
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (payload) {
                    if (!payload.ok) {
                        item.state = 'error';
                        item.badge.textContent = 'Failed';
                        item.badge.className = 'tbup-queue-badge tbup-queue-badge--error';
                        return;
                    }

                    item.state = 'done';
                    item.badge.textContent = 'Added';
                    item.badge.className = 'tbup-queue-badge tbup-queue-badge--done';
                })
                .catch(function () {
                    item.state = 'error';
                    item.badge.textContent = 'Failed';
                    item.badge.className = 'tbup-queue-badge tbup-queue-badge--error';
                });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var pending = queue.filter(function (item) {
                return item.state === 'ready' || item.state === 'error';
            });

            if (!pending.length) {
                status.className = 'tbup-status tbup-status--error';
                status.textContent = 'Choose at least one photo first.';
                return;
            }

            submitBtn.disabled = true;
            status.className = 'tbup-status tbup-status--busy';
            status.textContent = 'Uploading ' + pending.length + ' photo' + (pending.length > 1 ? 's' : '') + '…';

            var chain = Promise.resolve();
            pending.forEach(function (item) {
                chain = chain.then(function () {
                    return uploadOne(item);
                });
            });

            chain.then(function () {
                var failed = queue.filter(function (item) {
                    return item.state === 'error';
                }).length;
                var done = queue.filter(function (item) {
                    return item.state === 'done';
                }).length;

                submitBtn.disabled = false;

                if (failed === 0) {
                    status.className = 'tbup-status tbup-status--success';
                    status.textContent = done + ' photo' + (done !== 1 ? 's' : '') + " added — thank you! Add more whenever you'd like.";
                } else if (done > 0) {
                    status.className = 'tbup-status tbup-status--error';
                    status.textContent = done + ' added, ' + failed + " couldn't upload — try those again.";
                } else {
                    status.className = 'tbup-status tbup-status--error';
                    status.textContent = "Upload failed — try again.";
                }
            });
        });
    });
})();
