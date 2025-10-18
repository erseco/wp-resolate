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

  function normalizeText(value, fallback){
    if (value === undefined || value === null){
      return fallback || '';
    }
    if (typeof value === 'string'){
      return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean'){
      return String(value);
    }
    if (Array.isArray(value)){
      for (var i = 0; i < value.length; i++){
        if (typeof value[i] === 'string' && value[i]){
          return value[i];
        }
      }
      return fallback || '';
    }
    if (typeof value === 'object'){
      if (typeof value.rendered === 'string' && value.rendered){
        return value.rendered;
      }
      var keys = Object.keys(value);
      for (var j = 0; j < keys.length; j++){
        var key = keys[j];
        if (typeof value[key] === 'string' && value[key]){
          return value[key];
        }
      }
    }
    return fallback || '';
  }

  function normalizeField(field){
    if (!field){ return null; }
    if (typeof field === 'string'){ return {
      slug: field,
      label: field,
      placeholder: field,
      data_type: 'text',
      group: ''
    }; }
    var slug = normalizeText(field.slug || field.name, '');
    slug = slug ? slug.toString().trim() : '';
    var label = normalizeText(field.title || field.label || field.name, '').trim();
    if (!label){ label = normalizeText(field.placeholder, '').trim(); }
    var placeholder = normalizeText(field.placeholder, slug).trim();
    var type = normalizeText(field.type || field.data_type, 'text').trim() || 'text';
    var group = normalizeText(field.group, '').trim();
    return {
      slug: slug,
      label: label,
      placeholder: placeholder,
      data_type: type,
      group: group
    };
  }

  function fieldComparisonKey(field){
    var normalized = normalizeField(field);
    if (!normalized){ return ''; }
    var label = normalized.label || '';
    var fallback = normalized.placeholder || normalized.slug || '';
    var key = label || fallback;
    return key ? key.toString().trim().toLowerCase() : '';
  }

  function fieldDisplayName(field){
    var normalized = normalizeField(field);
    if (!normalized){ return ''; }
    var name = normalized.label || normalized.placeholder || normalized.slug || '';
    return name ? name.toString().trim() : '';
  }

  function renderSchema($container, fields, diff, summary){
    $container.empty();
    if (!fields || !fields.length){
      $container.append('<div class="resolate-schema-empty">'+ _.escape(resolateDocTypes.i18n.noFields) +'</div>');
      return;
    }
    var $list = $('<ul />');
    fields.forEach(function(field){
      var normalized = normalizeField(field);
      if (!normalized){ return; }
      var label = normalized.label || normalized.placeholder || normalized.slug;
      var pieces = [];
      if (normalized.group){
        pieces.push('<strong>'+ _.escape(normalized.group) +'</strong>: ');
      }
      pieces.push(_.escape(label));
      if (normalized.placeholder && normalized.placeholder !== normalized.slug){
        pieces.push(' <code>['+ _.escape(normalized.placeholder) +']</code>');
      }
      var typeKey = normalized.data_type || 'text';
      if (typeKey && typeKey !== 'text'){
        var typeLabel = (resolateDocTypes.fieldTypes && resolateDocTypes.fieldTypes[typeKey]) ? resolateDocTypes.fieldTypes[typeKey] : typeKey;
        pieces.push(' <span class="resolate-field-type">('+ _.escape(typeLabel) +')</span>');
      }
      $list.append('<li>'+ pieces.join('') +'</li>');
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
    if (summary && typeof summary === 'object'){
      var metaParts = [];
      if (typeof summary.field_count !== 'undefined'){
        metaParts.push(resolateDocTypes.i18n.fieldCount.replace('%d', summary.field_count));
      }
      if (summary.repeaters && summary.repeaters.length){
        metaParts.push(resolateDocTypes.i18n.repeaterList.replace('%s', summary.repeaters.join(', ')));
      }
      if (summary.parsed_at){
        metaParts.push(resolateDocTypes.i18n.parsedAt.replace('%s', summary.parsed_at));
      }
      if (metaParts.length){
        var $meta = $('<p class="resolate-schema-meta" style="margin-top:8px;color:#555;" />');
        $meta.text(metaParts.join(' • '));
        $container.append($meta);
      }
    }
  }

  function computeDiff(newFields){
    var previous = Array.isArray(resolateDocTypes.schema) ? resolateDocTypes.schema : [];
    var added = [];
    var removed = [];
    var normalizedNew = newFields.map(normalizeField).filter(function(field){ return !!field; });
    var previousLookup = {};
    previous.forEach(function(field){
      var key = fieldComparisonKey(field);
      if (key){ previousLookup[key] = true; }
    });
    var newLookup = {};
    normalizedNew.forEach(function(field){
      var key = fieldComparisonKey(field);
      if (!key){ return; }
      newLookup[key] = fieldDisplayName(field);
      if (!previousLookup[key]){
        added.push(fieldDisplayName(field));
      }
    });
    previous.forEach(function(field){
      var key = fieldComparisonKey(field);
      if (!key){ return; }
      if (!Object.prototype.hasOwnProperty.call(newLookup, key)){
        removed.push(fieldDisplayName(field));
      }
    });
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

  function flattenSchema(schema){
    var result = [];
    if (!schema || typeof schema !== 'object'){ return result; }
    if (Array.isArray(schema.fields)){
      schema.fields.forEach(function(field){
        if (!field){ return; }
        var entry = Object.assign({}, field);
        entry.group = '';
        result.push(entry);
      });
    }
    if (Array.isArray(schema.repeaters)){
      schema.repeaters.forEach(function(repeater){
        if (!repeater || !Array.isArray(repeater.fields)){ return; }
        var groupName = normalizeText(repeater.title || repeater.name, '');
        repeater.fields.forEach(function(field){
          if (!field){ return; }
          var entry = Object.assign({}, field);
          entry.group = groupName;
          result.push(entry);
        });
      });
    }
    return result;
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
      var schema = resp.data.schema || {};
      var summary = resp.data.summary || {};
      var flattened = flattenSchema(schema).map(normalizeField).filter(function(field){ return !!field; });
      var diff = computeDiff(flattened);
      renderSchema($schemaBox, flattened, diff, summary);
      resolateDocTypes.schemaV2 = schema;
      resolateDocTypes.schemaSummary = summary;
      resolateDocTypes.schema = flattened;
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

    resolateDocTypes.schemaV2 = resolateDocTypes.schemaV2 || {};
    resolateDocTypes.schemaSummary = resolateDocTypes.schemaSummary || {};
    var $schemaBox = $('#resolate_type_schema_preview');
    if ($schemaBox.length){
      var initialSchema = flattenSchema(resolateDocTypes.schemaV2);
      var normalizedInitial = initialSchema.map(normalizeField).filter(function(field){ return !!field; });
      resolateDocTypes.schema = normalizedInitial;
      renderSchema($schemaBox, normalizedInitial, null, resolateDocTypes.schemaSummary);
    } else {
      resolateDocTypes.schema = flattenSchema(resolateDocTypes.schemaV2).map(normalizeField).filter(function(field){ return !!field; });
    }

    var $schemaBox = $('#resolate_type_schema_preview');
    var initialSchemaRaw = parseSchemaData($schemaBox.data('schema'));
    var initialNormalized = initialSchemaRaw.map(normalizeField).filter(function(field){ return !!field; });
    if (initialNormalized.length){
      renderSchema($schemaBox, initialNormalized);
    } else {
      renderSchema($schemaBox, []);
    }
    resolateDocTypes.schema = initialNormalized;

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
