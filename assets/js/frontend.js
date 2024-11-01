'use strict';

(function($) {
  $(document).on('click touch', '.wpcuv-edit', function() {
    var current_item_product;
    var cart_item_key = $(this).attr('id');

    current_item_product = $(this).closest('tr');
    current_item_product.fadeTo(300, 0);

    $.ajax({
      async: true,
      url: wpcuv_vars.ajax_url,
      type: 'POST',
      dataType: 'html',
      data: {
        action: 'load_variation',
        current_key: cart_item_key,
        nonce: wpcuv_vars.nonce,
      },

      success: function(result) {
        var form_present = $('tr.wpcuv-new-item-' + cart_item_key).length;

        if (form_present == 0) {
          current_item_product.after(result).hide();
          current_item_product.addClass('wpcuv-old-item-' + cart_item_key).
              fadeOut(300);

          $('tr.wpcuv-new-item-' + cart_item_key + ' .variations_form').
              wc_variation_form();

          $('tr.wpcuv-new-item-' + cart_item_key).fadeIn(300);

          $('.wpcuv-edit').each(function() {
            var other_cart_item_key = $(this).attr('id');

            if (other_cart_item_key !== cart_item_key) {
              $('tr.wpcuv-new-item-' + other_cart_item_key).remove();
              $('tr.wpcuv-old-item-' + other_cart_item_key).
                  fadeIn(150).
                  fadeTo(150, 1);
            }
          });
        }
      },
    });
  });

  $(document).on('click touch', '.wpcuv-update', function(e) {
    e.preventDefault();

    var $this = $(this);
    var $form = $this.closest('form');
    var old_key = $form.find('.old_key').val();

    $this.addClass('wpcuv-updating disabled');

    $.ajax({
      async: true,
      url: wpcuv_vars.ajax_url,
      type: 'POST',
      dataType: 'html',
      data: {
        action: 'update_variation',
        form_data: $form.serialize(),
        old_key: old_key,
        nonce: wpcuv_vars.nonce,
      },

      success: function(response) {
        $this.removeClass('wpcuv-updating disabled');

        var html = $.parseHTML(response);
        var new_form = $('table.shop_table.cart', html).
            closest('form');
        var new_totals = $('.cart_totals', html);

        $('table.shop_table.cart').
            closest('form').
            replaceWith(new_form);
        $('.cart_totals').replaceWith(new_totals);

        if ($('div.woocommerce-message').length == 0) {
          $('div.entry-content div.woocommerce').prepend(
              '<div class="woocommerce-message">' +
              wpcuv_vars.updated_text + '</div>',
          );
        }
      },
    });
  });

  $(document).on('click touch', '.wpcuv-cancel', function(e) {
    var key = $(this).attr('data-key');

    wpcuv_cancel(key);
  });

  $(document).on('found_variation', function(e, t) {
    var $editor = $(e['target']).closest('.wpcuv-editor');

    if ($editor.length) {
      if (t['image']['full_src'] && t['image']['full_src'] !== '') {
        $editor.find('.wpcuv-thumbnail-ori').hide();
        $editor.find('.wpcuv-thumbnail-new').
            html('<img src="' + t['image']['full_src'] + '"/>').show();
      } else {
        $editor.find('.wpcuv-thumbnail-new').html('').hide();
        $editor.find('.wpcuv-thumbnail-ori').show();
      }
    }
  });

  $(document).on('reset_data', function(e) {
    var $editor = $(e['target']).closest('.wpcuv-editor');

    if ($editor.length) {
      $editor.find('.wpcuv-thumbnail-new').html('').hide();
      $editor.find('.wpcuv-thumbnail-ori').show();
    }
  });

  function wpcuv_cancel(cart_item_key) {
    jQuery('tr.wpcuv-new-item-' + cart_item_key).remove();
    jQuery('tr.wpcuv-old-item-' + cart_item_key).fadeIn(150).fadeTo(150, 1);
  }
})(jQuery);