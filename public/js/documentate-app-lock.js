/** Native WordPress Heartbeat post locks for the front-end editor. */
(function ($) {
	'use strict';

	var state = document.getElementById('dcta-edit-lock');
	if (!state || !state.dataset.lock) {
		return;
	}

	var form = document.querySelector('form.dcta-editor');
	var dialog = document.getElementById('dcta-lock-dialog');
	var lock = state.dataset.lock;
	var lost = false;
	var submitting = false;

	$(document).on('heartbeat-send.documentateLock', function (event, data) {
		if (!lost) {
			data['wp-refresh-post-lock'] = { post_id: Number(state.dataset.postId), lock: lock };
		}
	});
	$(document).on('heartbeat-tick.documentateLock', function (event, data) {
		var response = data['wp-refresh-post-lock'];
		if (!response || lost) {
			return;
		}
		if (response.lock_error) {
			lost = true;
			form.inert = true;
			document.getElementById('dcta-lock-owner').textContent = response.lock_error.name;
			dialog.showModal();
		} else if (response.new_lock) {
			lock = response.new_lock;
		}
	});
	dialog.addEventListener('cancel', function (event) {
		event.preventDefault();
	});
	form.addEventListener('submit', function (event) {
		if (lost) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true);
	// Like the classic editor, do not release on submission: the handler owns
	// the lock then. A release beacon could recreate a just-deleted lock.
	$(window).on('submit.documentateLock', function (event) {
		if (event.target === form) {
			submitting = !event.isDefaultPrevented();
		}
	});
	$(window).on('pagehide.documentateLock', function () {
		if (!lost && !submitting && navigator.sendBeacon) {
			var data = new FormData();
			data.append('action', 'wp-remove-post-lock');
			data.append('_wpnonce', state.dataset.releaseNonce);
			data.append('post_ID', state.dataset.postId);
			data.append('active_post_lock', lock);
			navigator.sendBeacon(documentateAppLock.ajaxUrl, data);
		}
	});
	// A restored page must check ownership again before exposing stale fields.
	$(window).on('pageshow.documentateLock', function (event) {
		if (event.originalEvent.persisted) {
			window.location.reload();
		}
	});
	wp.heartbeat.interval(15);
})(jQuery);
