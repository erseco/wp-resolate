/* global jQuery, wp, resolateDocTypes */
(function($){
  function parseSchemaData(val){
    if (!val){ return []; }
    if (Array.isArray(val)){ return val; }
    if (typeof val === 'string'){
      try {
        var parsed = JSON.parse(val);
        return Array.isArray(parsed) ? parsed : [];
      } catch (e){ return []; }
    }
    return [];
  }

  function schemaToSlugList(schema){
    return (schema || []).map(function(row){
      if (!row){ return ''; }
      if (typeof row === 'string'){ return row; }
      return (row.slug || '').toString();
    }).filter(function(slug){ return !!slug; });
  }

  function renderSchema($container, fields, diff){
    $container.empty();
    if (!fields || !fields.length){
      $container.append('<div class="resolate-schema-empty">'+ _.escape(resolateDocTypes.i18n.noFields) +'</div>');
      return;
    }
    var $list = $('<ul />');
    fields.forEach(function(field){
      $list.append('<li>'+ _.escape(field) +'</li>');
    });
    $container.append($list);
    if (diff){
      if (diff.added && diff.added.length){
        var $added = $('<p style="margin-top:8px;color:#008a20;" />');
        $added.text(resolateDocTypes.i18n.diffAdded + ': ' + diff.added.join(', '));
        $container.append($added);
      }
      if (diff.removed && diff.removed.length){
        var $removed = $('<p style="margin-top:4px;color:#cc1818;" />');
        $removed.text(resolateDocTypes.i18n.diffRemoved + ': ' + diff.removed.join(', '));
        $container.append($removed);
      }
    }
  }

  function computeDiff(newFields){
    var previous = Array.isArray(resolateDocTypes.schema) ? resolateDocTypes.schema : [];
    var added = [];
    var removed = [];
    var newSet = {};
    newFields.forEach(function(f){ newSet[f] = true; });
    newFields.forEach(function(f){ if (previous.indexOf(f) === -1){ added.push(f); } });
    previous.forEach(function(f){ if (!newSet[f]){ removed.push(f); } });
    return { added: added, removed: removed };
  }

  function updateTemplateTypeLabel($scope, type){
    var $label = $scope.find('.resolate-template-type');
    if (!$label.length){ return; }
    if (!type){
      var fallback = $label.data('default') || '';
      $label.text(fallback);
      return;
    }
    if (type === 'docx'){
      $label.text(resolateDocTypes.i18n.typeDocx);
    } else if (type === 'odt'){
      $label.text(resolateDocTypes.i18n.typeOdt);
    } else {
      $label.text(resolateDocTypes.i18n.typeUnknown);
    }
  }

  function fetchTemplateFields(attachmentId, $scope){
    var $schemaBox = $('#resolate_type_schema_preview');
    $schemaBox.addClass('is-loading');
    $.post(resolateDocTypes.ajax.url, {
      action: 'resolate_doc_type_template_fields',
      nonce: resolateDocTypes.ajax.nonce,
      attachment_id: attachmentId
    }).done(function(resp){
      $schemaBox.removeClass('is-loading');
      if (!resp || !resp.success){
        window.alert(resp && resp.data && resp.data.message ? resp.data.message : 'Error');
        return;
      }
      var fields = Array.isArray(resp.data.fields) ? resp.data.fields : [];
      var diff = computeDiff(fields);
      renderSchema($schemaBox, fields, diff);
      updateTemplateTypeLabel($scope, resp.data.type || '');
    }).fail(function(){
      $schemaBox.removeClass('is-loading');
      window.alert('Error al analizar la plantilla seleccionada.');
    });
  }

  $(function(){
    $('.resolate-color-field').each(function(){
      if (typeof $(this).wpColorPicker === 'function'){
        $(this).wpColorPicker();
      }
    });

    var $schemaBox = $('#resolate_type_schema_preview');
    var initialSchema = schemaToSlugList(parseSchemaData($schemaBox.data('schema')));
    if (initialSchema.length){
      renderSchema($schemaBox, initialSchema);
    } else {
      renderSchema($schemaBox, []);
    }

    var initialType = $('.resolate-template-type').data('current') || resolateDocTypes.templateExt || '';
    updateTemplateTypeLabel($(document), initialType);

    $(document).on('click', '.resolate-template-select', function(e){
      e.preventDefault();
      var $btn = $(this);
      var allowed = ($btn.data('allowed') || '').split(',').filter(function(v){ return !!v; });
      var frame = wp.media({
        title: resolateDocTypes.i18n.select,
        multiple: false,
        library: allowed.length ? { type: allowed } : undefined
      });
      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        $('#resolate_type_template_id').val(att.id);
        $('#resolate_type_template_preview').text(att.filename || att.title || att.url || (''+att.id));
        fetchTemplateFields(att.id, $btn.closest('form, table'));
      });
      frame.open();
    });
  });
})(jQuery);
