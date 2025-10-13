/* global jQuery */
(function($){
  'use strict';

  function renumberAnnexes($list){
    $list.find('.resolate-annex-item').each(function(i){
      var $item = $(this);
      $item.attr('data-index', i);
      // Header label
      $item.find('strong').first().text('Anexo ' + (i + 1));
      // Update field names
      $item.find('input[name*="resolate_annexes"], textarea[name*="resolate_annexes"]').each(function(){
        var isTitle = $(this).is('input');
        var name = 'resolate_annexes['+ i +']['+ (isTitle ? 'title' : 'text') +']';
        $(this).attr('name', name);
      });
      // Update textarea id; do not auto-init TinyMCE here to avoid losing content.
      var $ta = $item.find('textarea');
      if ($ta.length) {
        var newId = 'resolate_annex_text_' + i;
        $ta.attr('id', newId);
      }
    });
  }

  function addAnnex($list){
    var i = $list.find('.resolate-annex-item').length;
    var tpl = $('#resolate-annex-template').html();
    if (!tpl) return;
    tpl = tpl.replace(/__i__/g, i).replace(/__n__/g, (i + 1));
    var $node = $(tpl);
    $list.append($node);
    renumberAnnexes($list);
  }

  function initAnnexes(){
    var $wrap = $('#resolate-annexes');
    if (!$wrap.length) return;
    var $list = $('#resolate-annex-list');
    $('#resolate-add-annex').on('click', function(){ addAnnex($list); });
    $wrap.on('click', '.resolate-annex-remove', function(){
      $(this).closest('.resolate-annex-item').remove();
      renumberAnnexes($list);
    });

    // Ensure TinyMCE updates underlying textareas before submit
    $('#post').on('submit', function(){
      if (window.tinymce && typeof tinymce.triggerSave === 'function') {
        try { tinymce.triggerSave(); } catch(e) {}
      }
    });
  }

  $(initAnnexes);
})(jQuery);
