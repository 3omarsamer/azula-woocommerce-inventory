jQuery(function($){
  function modal(){ var $m=$('#azsh-modal'); if(!$m.length){ $('body').append('<div id="azsh-modal" class="azsh-modal" hidden><div class="azsh-modal-card"><button type="button" class="azsh-close">×</button><h2>Stock History</h2><div class="azsh-modal-body"></div></div></div>'); $m=$('#azsh-modal'); } return $m; }
  $(document).on('click','.azsh-view-history',function(){ var id=$(this).data('variation'),$m=modal(); $m.removeAttr('hidden').find('.azsh-modal-body').html('Loading…'); $.post(AZSH.ajax,{action:'azsh_variation_history',nonce:AZSH.nonce,variation_id:id}).done(function(r){ $m.find('.azsh-modal-body').html(r.success?r.data.html:'Unable to load history.'); }).fail(function(){ $m.find('.azsh-modal-body').html('Unable to load history.'); }); });
  $(document).on('click','.azsh-close',function(){ $('#azsh-modal').attr('hidden',true); });
});

jQuery(function($){
 $(document).on('click','.azsh-update-stock',function(){
  var b=$(this), r=b.closest('tr'), i=r.find('.azsh-stock-input'), old=String(i.data('original')), neu=String(i.val()), sku=b.data('sku')||'SKU';
  if(neu===''||isNaN(Number(neu))){alert('Enter a valid stock quantity.');return;}
  if(old===neu){alert('No stock change.');return;}
  var d=Number(neu)-Number(old), ds=(d>0?'+':'')+d;
  if(!confirm('Change '+sku+' stock from '+old+' to '+neu+' ('+ds+')?')) return;
  b.prop('disabled',true).text('Updating…');
  $.post(AZSH.ajax,{action:'azsh_update_stock',nonce:AZSH.nonce,product_id:b.data('product'),new_stock:neu})
   .done(function(x){if(x&&x.success){i.val(x.data.stock).data('original',x.data.stock);alert(x.data.message);}else alert(x&&x.data&&x.data.message?x.data.message:'Stock update failed.');})
   .fail(function(){alert('Stock update failed.');})
   .always(function(){b.prop('disabled',false).text('Update');});
 });
});

jQuery(function($){
 $(document).on('click','.azsh-all-skus',function(){
   var parent=$(this).data('parent'), modal=$('#azsh-skus-modal'), body=modal.find('.azsh-skus-body');
   modal.find('.azsh-skus-title').text('All SKUs');
   body.html('<p>Loading…</p>'); modal.removeAttr('hidden');
   $.post(AZSH.ajax,{action:'azsh_product_skus',nonce:AZSH.nonce,parent_id:parent}).done(function(r){
     if(!r||!r.success){body.html('<p>Could not load SKUs.</p>');return;}
     modal.find('.azsh-skus-title').text(r.data.title+' — All SKUs');
     var h='<table class="widefat striped"><thead><tr><th>Photo</th><th>Variation / SKU</th><th>Current Stock</th></tr></thead><tbody>';
     $.each(r.data.items,function(_,x){h+='<tr><td class="azsh-image-col">'+x.image+'</td><td><strong>'+ $('<div>').text(x.name).html()+'</strong><br><code>'+ $('<div>').text(x.sku).html()+'</code></td><td><strong>'+x.stock+'</strong></td></tr>';});
     h+='</tbody></table>'; body.html(h);
   }).fail(function(){body.html('<p>Could not load SKUs.</p>');});
 });
 $(document).on('click','.azsh-skus-close',function(){$('#azsh-skus-modal').attr('hidden',true);});
 $(document).on('click','#azsh-skus-modal',function(e){if(e.target===this)$(this).attr('hidden',true);});
});

jQuery(function($){
 $(document).on('click','.azsh-toggle-value',function(){
   var cols=$('.azsh-total-value-col'), hidden=cols.first().prop('hidden');
   cols.prop('hidden',!hidden);
   $(this).attr('title',hidden?'Hide total value':'Show total value');
 });
});


jQuery(function($){
  function categoryChange(row, mode, term, termName, button){
    var product=row.data('product');
    if(!product||!term){alert('Choose a category first.');return;}
    var productName=row.find('td:first strong').text()||('Product '+product);
    var verb=mode==='add'?'Add':'Remove';
    var prep=mode==='add'?' to ':' from ';
    button.prop('disabled',true).text(verb==='Add'?'Adding…':'Removing…');
    $.post(AZSH.ajax,{action:'azsh_category_change',nonce:AZSH.nonce,product_id:product,term_id:term,mode:mode})
      .done(function(r){
        if(!r||!r.success){alert(r&&r.data&&r.data.message?r.data.message:'Category update failed.');return;}
        alert(r.data.message);
        window.location.reload();
      }).fail(function(){alert('Category update failed.');})
      .always(function(){button.prop('disabled',false).text(verb);});
  }
  $(document).on('click','.azsh-cat-action',function(){
    var b=$(this),row=b.closest('tr');
    categoryChange(row,b.data('mode'),b.data('term'),b.data('term-name'),b);
  });
  $(document).on('click','.azsh-cat-manual',function(){
    var b=$(this),row=b.closest('tr'),sel=row.find('.azsh-category-select'),term=sel.val(),name=sel.find('option:selected').text();
    categoryChange(row,b.data('mode'),term,name,b);
  });
});