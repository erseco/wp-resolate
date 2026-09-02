/**
 * Documentate Workflow Manager
 *
 * Handles UI state management for the document workflow:
 * - Disables all fields when document is locked (published/archived/pending for non-admins)
 * - Shows appropriate notices based on user role
 * - Button handlers for the unified Document Management meta box
 *
 * @package Documentate
 */

(function ($) {
	'use strict';

	/**
	 * Workflow Manager class.
	 */
	var DocumentateWorkflow = {
		/**
		 * Configuration from PHP.
		 */
		config: {},

		/**
		 * Selectors for form elements to disable.
		 */
		editableSelectors: [
			'#title',
			'#documentate_title_textarea',
			'#titlewrap input',
			'#content',
			'#postdivrich',
			'.documentate-sections-container input',
			'.documentate-sections-container textarea',
			'.documentate-sections-container select',
			'.documentate-field-input',
			'.documentate-field-textarea',
			'#documentate_doc_type_selector input',
			'#documentate_doc_type_selector select',
			'[name^="documentate_field"]',
			'.tiptap-editor',
			'.ProseMirror',
			// Meta boxes.
			'#postcustom input',
			'#postcustom textarea',
			'#tagsdiv-documentate_doc_type input',
		],

		/**
		 * Initialize the workflow manager.
		 */
		init: function () {
			this.config = window.documentateWorkflow || {};

			if (!this.config.postId) {
				return;
			}

			this.bindEvents();
			this.applyWorkflowState();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function () {
			var self = this;

			// Re-apply state after DOM updates (e.g., meta box loading).
			$(document).on('ajaxComplete', function () {
				if (self.config.isLocked || self.config.isPublished || self.config.isArchived) {
					self.lockFields();
				}
			});

			// Lock TinyMCE editors when they are initialized (after page load).
			if (this.config.isLocked && typeof tinyMCE !== 'undefined') {
				tinyMCE.on('AddEditor', function (e) {
					if (e.editor && e.editor.on) {
						e.editor.on('init', function () {
							// Delay to ensure mode API is available.
							setTimeout(function () {
								if (e.editor.mode && typeof e.editor.mode.set === 'function') {
									e.editor.mode.set('readonly');
								}
							}, 100);
						});
					}
				});
			}

			// "Guardar borrador" button.
			$('#documentate-save-draft').on('click', function (e) {
				e.preventDefault();
				self.submitWithStatus('draft');
			});

			// "Enviar a gestión" / "Enviar a revisión" button: the target
			// status travels in data-estado (en_gestion or pending).
			$('#documentate-send-review').on('click', function (e) {
				e.preventDefault();
				var estado = $(this).data('estado') || 'pending';
				if (!self.config.isAdmin) {
					var strings = self.config.strings || {};
					var msg = estado === 'en_gestion'
						? (strings.confirmSendGestion || '¿Enviar el documento a gestión documental?')
						: (strings.confirmSendReview || '¿Enviar el documento a revisión?');
					if (!window.confirm(msg)) {
						return;
					}
				}
				self.submitWithStatus(estado);
			});

			// "Pasar a administración" button (from en_gestion to pending).
			$('#documentate-pass-admin').on('click', function (e) {
				e.preventDefault();
				var strings = self.config.strings || {};
				if (!window.confirm(strings.confirmPassAdmin || '¿Pasar el documento a administración?')) {
					return;
				}
				self.submitWithStatus('pending');
			});

			// "Guardar" button (save while keeping en_gestion status).
			$('#documentate-save-gestion').on('click', function (e) {
				e.preventDefault();
				self.submitWithStatus('en_gestion');
			});

			// "Aprobar y publicar" button.
			$('#documentate-approve-publish').on('click', function (e) {
				e.preventDefault();
				self.submitWithStatus('publish');
			});

			// "Devolver al área" button: needs a reason.
			$('#documentate-return-draft').on('click', function (e) {
				e.preventDefault();
				self.submitReturn('draft');
			});

			// "Devolver a gestión" button (from pending back to en_gestion): needs a reason.
			$('#documentate-return-gestion').on('click', function (e) {
				e.preventDefault();
				self.submitReturn('en_gestion');
			});

			// "Devolver a revisión" button (from published back to pending).
			$('#documentate-return-review').on('click', function (e) {
				e.preventDefault();
				self.submitWithStatus('pending');
			});

			// "Guardar" button (save while keeping pending status).
			$('#documentate-save-pending').on('click', function (e) {
				e.preventDefault();
				self.submitWithStatus('pending');
			});
		},

		/**
		 * Submit a return: reveal the reason box first, refuse to submit while empty.
		 *
		 * @param {string} newStatus The status the document goes back to.
		 */
		submitReturn: function (newStatus) {
			var $box = $('.documentate-mgmt-motivo');
			var $motivo = $('#documentate-return-draft-motivo');

			if ($box.length && !$box.is(':visible')) {
				$box.show();
				$motivo.trigger('focus');
				return;
			}

			if (!$motivo.length || $.trim($motivo.val()) === '') {
				var strings = this.config.strings || {};
				window.alert(strings.motivoRequired || 'Escribe el motivo de la devolución.');
				$motivo.trigger('focus');
				return;
			}

			this.submitWithStatus(newStatus);
		},

		/**
		 * Set the hidden post_status field and submit the form.
		 *
		 * @param {string} newStatus The target post status.
		 */
		submitWithStatus: function (newStatus) {
			$('#post_status').val(newStatus);
			$('#hidden_post_status').val(newStatus);
			$('#documentate_document_management .spinner').addClass('is-active');

			// Remove the beforeunload handler so the browser does not
			// show a "Leave site?" confirmation when we are intentionally saving.
			$(window).off('beforeunload.edit-post');

			$('#post').submit();
		},

		/**
		 * Apply the current workflow state to the UI.
		 */
		applyWorkflowState: function () {
			if (this.config.isLocked || this.config.isPublished || this.config.isArchived) {
				this.lockFields();
				this.showLockedNotice();
			}

			if (!this.config.hasDocType) {
				this.showDocTypeWarning();
			}
		},

		/**
		 * Lock all editable fields (read-only mode).
		 */
		lockFields: function () {
			var self = this;

			// Disable standard form elements.
			this.editableSelectors.forEach(function (selector) {
				$(selector).each(function () {
					var $el = $(this);

					// Handle different element types.
					if ($el.is('input, textarea, select')) {
						$el.prop('disabled', true).addClass(
							'documentate-locked'
						);
					} else if ($el.hasClass('ProseMirror')) {
						// TipTap/ProseMirror editor.
						$el.attr('contenteditable', 'false').addClass(
							'documentate-locked'
						);
					} else {
						// Container elements.
						$el.find('input, textarea, select').prop(
							'disabled',
							true
						);
						$el.addClass('documentate-locked');
					}
				});
			});

			// Lock TinyMCE if present (main content editor).
			if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
				tinyMCE.get('content').mode.set('readonly');
			}

			// Lock ALL TinyMCE editors (including those in array/repeater fields).
			if (typeof tinyMCE !== 'undefined' && tinyMCE.editors) {
				tinyMCE.editors.forEach(function (editor) {
					if (editor && editor.mode) {
						editor.mode.set('readonly');
					}
				});
			}

			// Add locked class to body for CSS targeting.
			$('body').addClass('documentate-document-locked');

			// Disable document type selector if it exists.
			$('#documentate_doc_type_selectorchecklist input').prop(
				'disabled',
				true
			);

			// Disable ALL TinyMCE toolbar buttons.
			$('.mce-btn').addClass('mce-disabled').attr('aria-disabled', 'true');

			// Disable ALL quicktags buttons.
			$('.quicktags-toolbar .ed_button').prop('disabled', true).addClass('documentate-locked');

			// Hide editor mode tabs (Visual/Code) to prevent switching.
			$('.wp-editor-tabs').hide();

			// Lock array/repeater field controls.
			this.lockArrayFields();

			// Add visual indicator overlay.
			this.addLockedOverlay();
		},

		/**
		 * Lock array/repeater field controls.
		 */
		lockArrayFields: function () {
			// Disable "Add element" buttons.
			$('.documentate-array-add').prop('disabled', true).addClass('documentate-locked');

			// Disable "Remove" buttons.
			$('.documentate-array-remove').prop('disabled', true).addClass('documentate-locked');

			// Disable drag handles.
			$('.documentate-array-handle')
				.addClass('documentate-locked')
				.css({
					cursor: 'not-allowed',
					opacity: '0.5',
					pointerEvents: 'none'
				})
				.attr('aria-disabled', 'true');

			// Make array items not draggable.
			$('.documentate-array-item').attr('draggable', 'false');

			// Disable all inputs inside array fields.
			$('.documentate-array-field input, .documentate-array-field textarea, .documentate-array-field select')
				.prop('disabled', true)
				.addClass('documentate-locked');

			// Disable TinyMCE toolbar buttons inside array fields.
			$('.documentate-array-field .mce-btn').addClass('mce-disabled');

			// Hide the wp-editor tabs (Visual/Code) to prevent switching.
			$('.documentate-array-field .wp-editor-tabs').hide();
		},

		/**
		 * Add a visual overlay to locked sections.
		 */
		addLockedOverlay: function () {
			var self = this;

			// Target the .inside container of the sections meta box.
			var $container = $('#documentate_sections .inside');

			// Fallback to sections container if meta box structure is different.
			if (!$container.length) {
				$container = $('.documentate-sections-container');
			}

			if ($container.length && !$container.find('.locked-overlay').length) {
				$container.css('position', 'relative');

				var message;
				var icon = 'dashicons-lock';

				if (self.config.isArchived) {
					icon = 'dashicons-archive';
					message = self.config.strings && self.config.strings.archivedMessage
						? self.config.strings.archivedMessage
						: 'Este documento está archivado y no se puede editar.';
				} else if (self.config.isPending && !self.config.isAdmin) {
					message = self.config.strings && self.config.strings.pendingMessage
						? self.config.strings.pendingMessage
						: 'Este documento está en revisión y no se puede editar.';
				} else if (self.config.isEnGestion && self.config.isLocked) {
					message = self.config.strings && self.config.strings.gestionMessage
						? self.config.strings.gestionMessage
						: 'Este documento está en gestión documental y no se puede editar.';
				} else {
					message = self.config.strings && self.config.strings.lockedMessage
						? self.config.strings.lockedMessage
						: 'Este documento está aprobado y no se puede editar.';
				}

				$container.append(
					'<div class="locked-overlay">' +
					'<div class="locked-message">' +
					'<span class="dashicons ' + icon + '"></span>' +
					'<span>' + message + '</span>' +
					'</div>' +
					'</div>'
				);
			}
		},

		/**
		 * Show notice when document is locked.
		 */
		showLockedNotice: function () {
			var message;
			var icon = 'dashicons-lock';

			if (this.config.isArchived) {
				icon = 'dashicons-archive';
				message = this.config.isAdmin
					? this.config.strings.adminUnarchive
					: this.config.strings.archivedMessage;
			} else if (this.config.isPending && !this.config.isAdmin) {
				message = this.config.strings.pendingMessage;
			} else if (this.config.isEnGestion && this.config.isLocked) {
				message = this.config.strings.gestionMessage;
			} else {
				message = this.config.isAdmin
					? this.config.strings.adminUnlock
					: this.config.strings.lockedMessage;
			}

			var noticeClass = this.config.isAdmin
				? 'notice-info'
				: 'notice-warning';

			var $notice = $(
				'<div class="notice ' +
					noticeClass +
					' documentate-workflow-notice">' +
					'<p><span class="dashicons ' + icon + '"></span> ' +
					'<strong>' +
					this.config.strings.lockedTitle +
					'</strong> - ' +
					message +
					'</p>' +
					'</div>'
			);

			// Insert after title if not already present.
			if (!$('.documentate-workflow-notice').length) {
				$('#poststuff').before($notice);
			}
		},

		/**
		 * Show warning when no document type is selected.
		 */
		showDocTypeWarning: function () {
			if (
				this.config.postStatus === 'auto-draft' ||
				this.config.hasDocType
			) {
				return;
			}

			var $warning = $(
				'<div class="notice notice-warning documentate-doctype-warning">' +
					'<p><span class="dashicons dashicons-warning"></span> ' +
					this.config.strings.needsDocType +
					'</p>' +
					'</div>'
			);

			if (!$('.documentate-doctype-warning').length) {
				$('#poststuff').before($warning);
			}
		},
	};

	// Initialize when DOM is ready.
	$(document).ready(function () {
		DocumentateWorkflow.init();
	});
})(jQuery);
