(function () {
    'use strict';

    var POLL_MS = 8000;

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.getElementById('egalGrid');
        if (!grid) {
            return;
        }

        var feedUrl = grid.getAttribute('data-feed-url');
        if (!feedUrl) {
            return;
        }

        var emptyState = document.getElementById('egalEmptyState');

        function highestId() {
            var max = 0;
            grid.querySelectorAll('[data-photo-id]').forEach(function (el) {
                var id = parseInt(el.getAttribute('data-photo-id'), 10) || 0;
                if (id > max) {
                    max = id;
                }
            });
            return max;
        }

        function renderPhoto(photo) {
            var fig = document.createElement('figure');
            fig.className = 'egal-item egal-item--new';
            fig.setAttribute('data-photo-id', String(photo.id));

            var img = document.createElement('img');
            img.src = photo.thumbnail_url;
            img.loading = 'lazy';
            img.alt = 'Photo from ' + (photo.uploader_name || 'a guest');
            fig.appendChild(img);

            if (photo.uploader_name) {
                var caption = document.createElement('figcaption');
                caption.textContent = photo.uploader_name;
                fig.appendChild(caption);
            }

            grid.insertBefore(fig, grid.firstChild);
        }

        function poll() {
            fetch(feedUrl + '?after_id=' + highestId(), { headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    var photos = data.photos || [];
                    if (photos.length && emptyState) {
                        emptyState.hidden = true;
                    }
                    photos.forEach(renderPhoto);
                })
                .catch(function () {
                    // Silent — next poll tries again.
                })
                .finally(function () {
                    window.setTimeout(poll, POLL_MS);
                });
        }

        window.setTimeout(poll, POLL_MS);
    });
})();
