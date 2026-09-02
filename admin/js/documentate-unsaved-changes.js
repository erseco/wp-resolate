/**
 * Documentate Unsaved Changes Guard
 *
 * Document generation (preview, PDF, sign, DOCX, ODT) runs server-side from the
 * saved post meta, so firing an action while the form is dirty silently produces
 * a file built from the previous version. This module tracks the dirty state of
 * the classic-editor form, gates every [data-documentate-action] click while
 * changes are pending, and offers to save first and replay the action after the
 * reload.
 *
 * The gate listens on the capture phase and is registered before
 * documentate-actions and documentate-autofirma, so a single confirmation covers
 * every action. That ordering matters for "Sign and Download": its own capture
 * handler calls stopImmediatePropagation(), which would otherwise hide the click
 * from any later listener.
 *
 * Dirty tracking is event driven and deliberately biased towards "dirty". A false
 * positive costs one dialog that already offers "use saved version"; a false
 * negative is the stale-document bug this module exists to prevent. Comparing a
 * serialized snapshot of the form was rejected because core's volatile hidden
 * inputs report changes the user never made.
 *
 * @package Documentate
 */
(function ($) {
	'use strict';

	var config = window.documentateUnsavedChangesConfig || {};
	var strings = config.strings || {};

	var STORAGE_PREFIX = 'documentate_pending_action_';

	/**
	 * How long a stored action stays replayable, in milliseconds.
	 *
	 * Bounds the window in which a leftover entry could fire on its own: the
	 * reload after saving takes a couple of seconds, so anything older belongs to
	 * an abandoned attempt and is discarded.
	 */
	var RESUME_TTL = 120000;

	var isDirty = false;
	var bypassNextClick = false;
	var subscribers = [];
	var $modal = null;
	var triggerButton = null;

	/**
	 * Read a localized string, falling back to the Spanish default.
	 *
	 * @param {string} key      Key in the localized strings object.
	 * @param {string} fallback Text to use when the key is missing.
	 * @return {string} The resolved string.
	 */
	function text(key, fallback) {
		return strings[key] || fallback;
	}

	/**
	 * Announce a message to assistive technology when wp-a11y is available.
	 *
	 * @param {string} message Message to announce.
	 */
	function speak(message) {
		if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
			wp.a11y.speak(message);
		}
	}

	/* -------------------------------------------------------------------------
	 * Dirty state
	 * ---------------------------------------------------------------------- */

	/**
	 * Notify every subscriber of the current dirty state.
	 */
	function notify() {
		subscribers.forEach(function (callback) {
			try {
				callback(isDirty);
			} catch (error) {
				window.console.error('Documentate unsaved-changes subscriber failed:', error);
			}
		});
	}

	/**
	 * Flag the document as having unsaved changes.
	 */
	function markDirty() {
		if (isDirty) {
			return;
		}
		isDirty = true;
		notify();
	}

	/**
	 * Flag the document as saved.
	 */
	function markClean() {
		if (!isDirty) {
			return;
		}
		isDirty = false;
		notify();
	}

	/**
	 * Selector of the form being watched.
	 *
	 * wp-admin edits documents in #post; the front-end application posts its
	 * own form and passes its selector in the config.
	 *
	 * @return {string} CSS selector.
	 */
	function formSelector() {
		return config.formSelector || '#post';
	}

	/**
	 * Bind the change sources that make the document dirty.
	 *
	 * Everything is delegated on the form so repeater rows added after load are
	 * covered without rebinding.
	 */
	function bindDirtySources() {
		var $form = $(formSelector());
		if (!$form.length) {
			return;
		}

		$form.on(
			'input.documentateUnsaved change.documentateUnsaved',
			'input, textarea, select',
			markDirty
		);

		// TipTap/ProseMirror surfaces are contenteditable, which emits input natively.
		$form.on('input.documentateUnsaved', '.ProseMirror', markDirty);

		// Repeater rows: added, removed or reordered via native drag and drop.
		$form.on(
			'click.documentateUnsaved',
			'.documentate-array-add, .documentate-array-remove',
			markDirty
		);
		$form.on('drop.documentateUnsaved dragend.documentateUnsaved', '.documentate-array-items', markDirty);

		// Attachments are added, removed and reordered programmatically, so the
		// metabox script announces the result by triggering change on its hidden
		// field. Nothing extra is needed here.

		bindTinyMce();
	}

	/**
	 * Observe one TinyMCE editor.
	 *
	 * Only the Dirty and change events are used. SetContent also fires while an
	 * editor loads its initial content, which would mark an untouched document as
	 * dirty the moment the page finished rendering.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 */
	function attachEditor(editor) {
		if (!editor || typeof editor.on !== 'function' || editor.documentateDirtyBound) {
			return;
		}
		editor.documentateDirtyBound = true;

		if (editor.initialized) {
			editor.on('Dirty change', markDirty);
			return;
		}

		editor.on('init', function () {
			editor.on('Dirty change', markDirty);
		});
	}

	/**
	 * Track TinyMCE editors, both those already on the page and those created
	 * later for repeater rows.
	 */
	function bindTinyMce() {
		if (typeof window.tinyMCE === 'undefined') {
			return;
		}

		if (typeof window.tinyMCE.on === 'function') {
			window.tinyMCE.on('AddEditor', function (event) {
				attachEditor(event.editor);
			});
		}

		if (Array.isArray(window.tinyMCE.editors)) {
			window.tinyMCE.editors.forEach(attachEditor);
		}
	}

	/* -------------------------------------------------------------------------
	 * Pending action storage
	 * ---------------------------------------------------------------------- */

	/**
	 * Resolve the post ID of the document being edited.
	 *
	 * @return {number} Post ID, or 0 when it cannot be determined.
	 */
	function getPostId() {
		if (config.postId) {
			return parseInt(config.postId, 10) || 0;
		}
		return parseInt($('#post_ID').val(), 10) || 0;
	}

	/**
	 * Build the sessionStorage key for this document.
	 *
	 * @return {string} Storage key, or an empty string without a post ID.
	 */
	function storageKey() {
		var postId = getPostId();
		return postId ? STORAGE_PREFIX + postId : '';
	}

	/**
	 * Remember the action to replay once the page reloads.
	 *
	 * @param {string} action Action name.
	 * @param {string} format Document format, when the action has one.
	 */
	function rememberPendingAction(action, format) {
		var key = storageKey();
		if (!key) {
			return;
		}

		try {
			window.sessionStorage.setItem(
				key,
				JSON.stringify({ action: action, format: format || '', ts: Date.now() })
			);
		} catch (error) {
			// Private browsing modes reject writes; saving still works, the action
			// simply is not replayed.
		}
	}

	/**
	 * Read and remove the stored action, discarding stale entries.
	 *
	 * @return {Object|null} The pending action, or null when there is none.
	 */
	function takePendingAction() {
		var key = storageKey();
		if (!key) {
			return null;
		}

		var raw;
		try {
			raw = window.sessionStorage.getItem(key);
			window.sessionStorage.removeItem(key);
		} catch (error) {
			return null;
		}

		if (!raw) {
			return null;
		}

		var pending;
		try {
			pending = JSON.parse(raw);
		} catch (error) {
			return null;
		}

		if (!pending || !pending.action || Date.now() - pending.ts > RESUME_TTL) {
			return null;
		}

		return pending;
	}

	/* -------------------------------------------------------------------------
	 * Modal
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the modal markup once and cache the jQuery object.
	 */
	function buildModal() {
		if ($modal) {
			return;
		}

		var html =
			'<div class="documentate-unsaved-modal" id="documentate-unsaved-modal" role="dialog" aria-modal="true" aria-labelledby="documentate-unsaved-modal-title">' +
			'<div class="documentate-unsaved-modal__content">' +
			'<h2 class="documentate-unsaved-modal__title" id="documentate-unsaved-modal-title"></h2>' +
			'<p class="documentate-unsaved-modal__message"></p>' +
			'<div class="documentate-unsaved-modal__actions">' +
			'<button type="button" class="button button-primary documentate-unsaved-modal__primary"></button>' +
			'<button type="button" class="button documentate-unsaved-modal__secondary"></button>' +
			'<button type="button" class="button-link documentate-unsaved-modal__cancel"></button>' +
			'</div>' +
			'</div>' +
			'</div>';

		$modal = $(html);
		$('body').append($modal);

		$modal.on('click', '.documentate-unsaved-modal__cancel', closeModal);
		$modal.on('keydown', onModalKeydown);

		// Clicking the backdrop, but not the panel, dismisses the dialog.
		$modal.on('click', function (event) {
			if (event.target === $modal[0]) {
				closeModal();
			}
		});
	}

	/**
	 * Keep keyboard focus inside the dialog and close it on Escape.
	 *
	 * @param {Object} event jQuery keydown event.
	 */
	function onModalKeydown(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			closeModal();
			return;
		}

		if (event.key !== 'Tab') {
			return;
		}

		var focusable = $modal.find('button:visible').toArray();
		if (!focusable.length) {
			return;
		}

		var first = focusable[0];
		var last = focusable[focusable.length - 1];

		if (event.shiftKey && event.target === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && event.target === last) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Choose the primary button label for an action.
	 *
	 * @param {string} action Action name.
	 * @return {string} Button label.
	 */
	function saveLabel(action) {
		if (action === 'preview') {
			return text('saveAndPreview', 'Guardar y previsualizar');
		}
		if (action === 'sign') {
			return text('saveAndSign', 'Guardar y firmar');
		}
		return text('saveAndDownload', 'Guardar y descargar');
	}

	/**
	 * Close the dialog and return focus to the button that opened it.
	 */
	function closeModal() {
		if ($modal) {
			$modal.removeClass('is-visible');
		}
		if (triggerButton && typeof triggerButton.focus === 'function') {
			triggerButton.focus();
		}
	}

	/**
	 * Show the dialog with the given content and buttons.
	 *
	 * @param {Object} options Title, message and button descriptors.
	 */
	function showModal(options) {
		buildModal();

		$modal.find('.documentate-unsaved-modal__title').text(options.title);
		$modal.find('.documentate-unsaved-modal__message').text(options.message);

		var $primary = $modal.find('.documentate-unsaved-modal__primary');
		var $secondary = $modal.find('.documentate-unsaved-modal__secondary');
		var $cancel = $modal.find('.documentate-unsaved-modal__cancel');

		$primary.off('click').text(options.primaryLabel).on('click', options.onPrimary);

		if (options.secondaryLabel) {
			$secondary.off('click').text(options.secondaryLabel).on('click', options.onSecondary).show();
		} else {
			$secondary.off('click').hide();
		}

		$cancel.text(text('cancel', 'Cancelar'));

		// Restore the button row in case a previous save left it hidden.
		$modal.find('.documentate-unsaved-modal__actions').show();

		$modal.addClass('is-visible');
		speak(options.title + '. ' + options.message);

		window.setTimeout(function () {
			$primary.trigger('focus');
		}, 0);
	}

	/**
	 * Replace the dialog body with a non-dismissable saving state.
	 */
	function showSavingState() {
		if (!$modal) {
			return;
		}
		$modal.find('.documentate-unsaved-modal__title').text(text('saving', 'Guardando cambios…'));
		$modal.find('.documentate-unsaved-modal__message').text(text('savingWait', 'La acción continuará automáticamente.'));
		$modal.find('.documentate-unsaved-modal__actions').hide();
	}

	/* -------------------------------------------------------------------------
	 * Gate
	 * ---------------------------------------------------------------------- */

	/**
	 * Fire the action for real, bypassing this gate once.
	 *
	 * A synthetic MouseEvent is dispatched rather than calling button.click()
	 * because documentate-autofirma replaces HTMLAnchorElement.prototype.click.
	 *
	 * @param {Element} button Action button to activate.
	 */
	function replayAction(button) {
		bypassNextClick = true;
		button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
	}

	/**
	 * Save the form and record the action so it resumes after the reload.
	 *
	 * The real form is submitted with the status untouched, so the existing save
	 * path (meta saver, nonces, revisions, workflow) runs exactly as it does for a
	 * manual save. TinyMCE is flushed explicitly because jQuery's submit trigger
	 * calls the native form.submit(), which skips TinyMCE's own submit listener.
	 *
	 * @param {string} action Action to replay.
	 * @param {string} format Document format, when the action has one.
	 */
	function saveAndResume(action, format) {
		var $form = $(formSelector());
		if (!$form.length) {
			closeModal();
			return;
		}

		rememberPendingAction(action, format);

		if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
			window.tinyMCE.triggerSave();
		}

		$(window).off('beforeunload.edit-post');
		markClean();
		showSavingState();
		$form.trigger('submit');
	}

	/**
	 * Intercept action clicks while the document has unsaved changes.
	 *
	 * @param {MouseEvent} event Native click event, capture phase.
	 */
	function onCaptureClick(event) {
		var target = event.target;
		if (!target || typeof target.closest !== 'function') {
			return;
		}

		var button = target.closest('[data-documentate-action]');
		if (!button) {
			return;
		}

		if (bypassNextClick) {
			bypassNextClick = false;
			return;
		}

		if (!isDirty) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		triggerButton = button;
		var action = button.getAttribute('data-documentate-action') || '';
		var format = button.getAttribute('data-documentate-format') || '';

		showModal({
			title: text('title', 'Hay cambios sin guardar'),
			message: text(
				'message',
				'El documento se genera a partir de la última versión guardada. Si continúas sin guardar, no verás tus cambios.'
			),
			primaryLabel: saveLabel(action),
			onPrimary: function () {
				saveAndResume(action, format);
			},
			secondaryLabel: text('useSaved', 'Usar versión guardada'),
			onSecondary: function () {
				closeModal();
				replayAction(button);
			},
		});
	}

	/* -------------------------------------------------------------------------
	 * Resume
	 * ---------------------------------------------------------------------- */

	/**
	 * Replay the action stored before the last save, if there is one.
	 *
	 * Called by documentate-actions once it has delegated its click handler, not
	 * from this module's own ready callback. The replay is a synthetic click, and
	 * this module loads first precisely because the other one depends on it, so
	 * its ready callback runs while nothing is listening for the action yet: the
	 * click would land on nothing and the stored entry would be consumed for
	 * nothing.
	 */
	function resumePendingAction() {
		var pending = takePendingAction();
		if (!pending) {
			return;
		}

		var selector = '[data-documentate-action="' + pending.action + '"]';
		if (pending.format) {
			selector += '[data-documentate-format="' + pending.format + '"]';
		}

		var button = document.querySelector(selector);
		if (!button) {
			return;
		}

		// AutoFirma needs a user gesture to launch, so signing always resumes
		// behind a button instead of firing on load.
		if (pending.action === 'sign') {
			triggerButton = button;
			showModal({
				title: text('savedTitle', 'Cambios guardados'),
				message: text('resumeMessage', 'Tus cambios se han guardado. Continúa para generar el documento.'),
				primaryLabel: text('signNow', 'Firmar y descargar'),
				onPrimary: function () {
					closeModal();
					replayAction(button);
				},
			});
			return;
		}

		replayAction(button);
	}

	/* -------------------------------------------------------------------------
	 * Public API and bootstrap
	 * ---------------------------------------------------------------------- */

	window.documentateUnsavedChanges = {
		/**
		 * Whether the document has changes that are not saved yet.
		 *
		 * @return {boolean} Dirty state.
		 */
		isDirty: function () {
			return isDirty;
		},
		markDirty: markDirty,
		markClean: markClean,
		resumePendingAction: resumePendingAction,

		/**
		 * Observe dirty-state transitions.
		 *
		 * @param {Function} callback Receives the new dirty state.
		 */
		subscribe: function (callback) {
			if (typeof callback === 'function') {
				subscribers.push(callback);
				callback(isDirty);
			}
		},
	};

	// The gate must be registered before documentate-actions and
	// documentate-autofirma bind their own handlers, so it runs at file scope
	// rather than on DOM ready.
	document.addEventListener('click', onCaptureClick, true);

	$(function () {
		bindDirtySources();

		// Passive indicator inside the actions meta box.
		var $indicator = $('.documentate-unsaved-indicator');
		if ($indicator.length) {
			window.documentateUnsavedChanges.subscribe(function (dirty) {
				$indicator.prop('hidden', !dirty);
			});
		}
	});
})(jQuery);
