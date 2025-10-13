/* global jQuery, wp */
(function($){
  function ensureArray(val){ if (!val) return []; if (Array.isArray(val)) return val; try{ var a = JSON.parse(val); return Array.isArray(a)?a:[]; }catch(e){ return []; } }

  function updateFieldsJSON($container){
    var rows = [];
    $container.find('.resolate-field-row').each(function(){
      var $r = $(this);
      var slug = ($r.find('.fld-slug').val()||'').trim();
      var label = ($r.find('.fld-label').val()||'').trim();
      var type = ($r.find('.fld-type').val()||'textarea').trim();
      if (slug && label){ rows.push({slug: slug.replace(/[^a-z0-9_\-]/gi,'').toLowerCase(), label: label, type: type}); }
    });
    $('#resolate_type_fields_json').val(JSON.stringify(rows));
  }

  function renderFieldRow(data){
    data = data || {slug:'', label:'', type:'textarea'};
    var html = '<div class="resolate-field-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
      + '<input type="text" class="regular-text fld-slug" placeholder="slug" value="'+ _.escape(data.slug||'') +'" style="width:140px;" />'
      + '<input type="text" class="regular-text fld-label" placeholder="Etiqueta" value="'+ _.escape(data.label||'') +'" />'
      + '<select class="fld-type"><option value="single">'+resolateDocTypes.i18n.single+'</option><option value="textarea">'+resolateDocTypes.i18n.textarea+'</option><option value="rich">'+resolateDocTypes.i18n.rich+'</option></select>'
      + '<button type="button" class="button link-delete fld-remove">'+resolateDocTypes.i18n.remove+'</button>'
      + '</div>';
    var $row = $(html);
    $row.find('.fld-type').val(data.type||'textarea');
    return $row;
  }

  function refreshLogosCSV(){
    var ids = [];
    $('#resolate_type_logos_list .logo-item').each(function(){ ids.push($(this).data('id')); });
    $('#resolate_type_logos').val(ids.join(','));
  }

  $(document).on('click','.resolate-media-select', function(e){
    e.preventDefault();
    var target = $(this).data('target');
    var type = $(this).data('type') || '';
    var frame = wp.media({ title: resolateDocTypes.i18n.select, multiple:false, library: { type: type ? [type] : undefined } });
    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      $('#'+target).val(att.id);
      var name = att.filename || att.title || att.url;
      $('#'+target+'_preview').text(name);
    });
    frame.open();
  });

  $(document).on('click','.resolate-add-logo', function(e){
    e.preventDefault();
    var frame = wp.media({ title: resolateDocTypes.i18n.logo, multiple:false, library: { type: ['image'] } });
    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      var $it = $('<div class="logo-item" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;" />');
      $it.attr('data-id', att.id);
      $it.append('<img src="'+ (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) +'" style="width:48px;height:48px;object-fit:contain;" />');
      $it.append('<span>'+ _.escape(att.filename || att.title) +'</span>');
      $it.append('<button type="button" class="button link-delete rm-logo">'+resolateDocTypes.i18n.remove+'</button>');
      $('#resolate_type_logos_list').append($it);
      refreshLogosCSV();
    });
    frame.open();
  });

  $(document).on('click','.rm-logo', function(){ $(this).closest('.logo-item').remove(); refreshLogosCSV(); });

  $(function(){
    // Populate logos from data-initial (edit screen)
    var $logos = $('#resolate_type_logos_list');
    var initialLogos = ensureArray($logos.data('initial'));
    if (initialLogos.length){
      // We can't resolve URLs server-side here; keep placeholders with ID.
      initialLogos.forEach(function(id){
        var $it = $('<div class="logo-item" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;" />');
        $it.attr('data-id', id);
        $it.append('<span>#'+ id +'</span>');
        $it.append('<button type="button" class="button link-delete rm-logo">'+resolateDocTypes.i18n.remove+'</button>');
        $logos.append($it);
      });
    }

    // Fields builder
    var $fields = $('#resolate_type_fields');
    var initial = ensureArray($fields.data('initial'));
    initial.forEach(function(row){ $fields.append(renderFieldRow(row)); });
    $(document).on('click','.resolate-add-field', function(e){ e.preventDefault(); $fields.append(renderFieldRow()); updateFieldsJSON($fields); });
    $(document).on('change keyup','.fld-slug,.fld-label,.fld-type', function(){ updateFieldsJSON($fields); });
    $(document).on('click','.fld-remove', function(){ $(this).closest('.resolate-field-row').remove(); updateFieldsJSON($fields); });

    // Prepare i18n object if missing
  });
})(jQuery);

