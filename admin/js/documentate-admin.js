(function($) {
	'use strict';

	/**
	 * Extract the visible text of an HTML fragment.
	 *
	 * Parsed with DOMParser rather than assigned to innerHTML. The parsed
	 * document has no browsing context, so scripting is disabled: neither
	 * scripts nor inline event handlers run. Assigning to innerHTML on a
	 * detached node gives no such guarantee — the node still belongs to the
	 * active document, so <img src=x onerror=...> stored in an editor executed
	 * its handler here. The parsed tree is never inserted anywhere; only
	 * body.textContent is read.
	 *
	 * Real parsing is required, not a tag-stripping regex: callers compare the
	 * result against '' to detect an empty field, and only a parser decodes
	 * entities (&#160;) and understands '>' inside an attribute value.
	 *
	 * @param {string} html Raw field value, possibly containing markup.
	 * @return {string} Trimmed visible text.
	 */
	function extractPlainText(html) {
		var parsed = new DOMParser().parseFromString(String(html), 'text/html');
		return (parsed.body.textContent || '').trim();
	}

	// Expose the extraction helper to the unit tests. WordPress serves this
	// file as a plain script, where `module` is undefined, so the browser
	// never takes this branch and the handler below is always registered.
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { extractPlainText: extractPlainText };
		return;
	}

	/**
	 * Get the text content of a TinyMCE or textarea rich editor.
	 *
	 * @param {HTMLElement} el The textarea or container element.
	 * @return {string} Trimmed text content.
	 */
	function getRichEditorText(el) {
		var textarea = el.matches('textarea') ? el : el.querySelector('textarea');
		if (!textarea) {
			return '';
		}

		// Sync TinyMCE content to the textarea before reading.
		if (window.tinyMCE) {
			var editor = window.tinyMCE.get(textarea.id);
			if (editor) {
				editor.save();
			}
		}

		return extractPlainText(textarea.value);
	}

	/**
	 * Show a validation error notice and scroll to the first invalid element.
	 *
	 * @param {jQuery}  $firstInvalid First invalid element to scroll to.
	 * @param {string}  message       Error message to display.
	 */
	function showValidationError($firstInvalid, message) {
		$('html, body').animate({
			scrollTop: $firstInvalid.offset().top - 50
		}, 300);

		var $notice = $('#documentate-required-notice');
		if ($notice.length === 0) {
			$notice = $(
				'<div id="documentate-required-notice" class="notice notice-error is-dismissible">' +
				'<p></p>' +
				'</div>'
			);
			$('.wrap h1, #wpbody-content .wrap > h1').first().after($notice);
		}
		$notice.find('p').text(message);
		$notice.show();
	}

	/**
	 * Validate all required fields (native + rich editors) on form submit.
	 *
	 * Native HTML5 validation only fires on real button clicks, not on
	 * programmatic .submit() calls (used by workflow buttons). This handler
	 * covers both scenarios by running reportValidity() for native inputs
	 * and custom checks for rich text editors.
	 */
	$(document).on('submit', '#post', function(e) {
		var form = this;

		// Sync all TinyMCE editors before validation.
		if (window.tinyMCE) {
			window.tinyMCE.triggerSave();
		}

		// --- 1. Native required / pattern / min / max validation ---
		// reportValidity() shows the browser's built-in error UI and returns false
		// when any constraint fails. It covers all HTML5 attributes including
		// required on text, number, date, email, url, select, textarea, etc.
		if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
			e.preventDefault();
			return;
		}

		// --- 2. Rich editor required validation ---
		var invalid = [];

		// Remove previous error highlights.
		$('.documentate-rich-required-error').removeClass('documentate-rich-required-error');

		// Check wp_editor containers marked with data-required.
		$('.documentate-rich-editor-wrap[data-required="true"]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		// Check collaborative textareas marked with data-required.
		$('textarea.documentate-collab-textarea[data-required="true"]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		// Check array-field rich textareas with required attribute.
		$('textarea.documentate-array-rich[required]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		if (invalid.length > 0) {
			e.preventDefault();

			invalid.forEach(function(el) {
				$(el).addClass('documentate-rich-required-error');
			});

			var message = 'Rellena todos los campos obligatorios.';
			showValidationError($(invalid[0]), message);
		}
	});

// jQuery is always present in the admin, where WordPress enqueues it as a
// dependency. The guard is for the unit tests, which require this file
// directly to reach extractPlainText() and never touch the jQuery handlers.
})(typeof jQuery !== 'undefined' ? jQuery : undefined);
