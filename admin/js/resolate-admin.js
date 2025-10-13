(function($) {
	'use strict';
	/* All of the code for your admin-facing JavaScript source should reside in this file. */
	console.log('loading resolate-admin.js');

	// Media selector for Document Logo on settings page.
	function initLogoMediaSelector() {
		var $btnSelect = $('#resolate_doc_logo_select');
		if (!$btnSelect.length) return;
		var frame;
		$btnSelect.on('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title: $btnSelect.text(),
				button: { text: $btnSelect.text() },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				$('#resolate_doc_logo_id').val(attachment.id);
				var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				$('#resolate_doc_logo_preview').html('<img src="'+ url +'" style="max-width:220px;height:auto;display:block;"/>');
			});
			frame.open();
		});

		$('#resolate_doc_logo_remove').on('click', function(){
			$('#resolate_doc_logo_id').val('');
			$('#resolate_doc_logo_preview').empty();
		});

		// Right logo bindings
		var $btnSelectRight = $('#resolate_doc_logo_right_select');
		if ($btnSelectRight.length) {
			var frameRight;
			$btnSelectRight.on('click', function(e){
				e.preventDefault();
				if (frameRight) { frameRight.open(); return; }
				frameRight = wp.media({
					title: $btnSelectRight.text(),
					button: { text: $btnSelectRight.text() },
					library: { type: 'image' },
					multiple: false
				});
				frameRight.on('select', function(){
					var attachment = frameRight.state().get('selection').first().toJSON();
					$('#resolate_doc_logo_right_id').val(attachment.id);
					var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
					$('#resolate_doc_logo_right_preview').html('<img src="'+ url +'" style="max-width:220px;height:auto;display:block;"/>' );
				});
				frameRight.open();
			});

			$('#resolate_doc_logo_right_remove').on('click', function(){
				$('#resolate_doc_logo_right_id').val('');
				$('#resolate_doc_logo_right_preview').empty();
			});
		}
	}

	// Media selector for ODT template (restrict to .odt)
	function initOdtTemplateSelector() {
		var $btn = $('#resolate_odt_template_select');
		if (!$btn.length) return;
		var frame;
		$btn.on('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title: $btn.text(),
				button: { text: $btn.text() },
				library: { type: 'application/vnd.oasis.opendocument.text' },
				multiple: false
			});
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				$('#resolate_odt_template_id').val(attachment.id);
				$('#resolate_odt_template_preview').html('<a target="_blank" rel="noopener" href="'+ attachment.url +'">'+ (attachment.filename || 'plantilla.odt') +'</a>');
			});
			frame.open();
		});

		$('#resolate_odt_template_remove').on('click', function(){
			$('#resolate_odt_template_id').val('');
			$('#resolate_odt_template_preview').empty();
		});
	}

	function initDocxTemplateSelector() {
		var $btn = $('#resolate_docx_template_select');
		if (!$btn.length) return;
		var frame;
		$btn.on('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
				title: $btn.text(),
				button: { text: $btn.text() },
				library: { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
				multiple: false
			});
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				$('#resolate_docx_template_id').val(attachment.id);
				$('#resolate_docx_template_preview').html('<a target="_blank" rel="noopener" href="'+ attachment.url +'">'+ (attachment.filename || 'plantilla.docx') +'</a>');
			});
			frame.open();
		});

		$('#resolate_docx_template_remove').on('click', function(){
			$('#resolate_docx_template_id').val('');
			$('#resolate_docx_template_preview').empty();
		});
	}

	$(function(){
		initLogoMediaSelector();
		initOdtTemplateSelector();
		initDocxTemplateSelector();
	});

})(jQuery);
