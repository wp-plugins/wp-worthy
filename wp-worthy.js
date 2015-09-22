(function($,undefined) {
  worthy = {
    setup : false,
    elem : false,
    pelem : false,
    qualified : false,
    qualified_length : 1800,
    qualified_warn : 1600,
    auto_assign : false,
    
    counter : function (text) {
      if (!this.setup) {
        if (!(e = document.getElementById ('wp-word-count')))
          return;
        
        e.appendChild (document.createElement ('br'));
        e.appendChild (document.createTextNode (wpWorthyLang.counter + ': '));
        this.elem = document.createElement ('spam');
        this.elem.setAttribute ('class', 'character-count');
        this.elem.innerHTML = '0';
        this.pelem = document.createElement ('div');
        e.appendChild (this.elem);
        
        this.setup = true;
      }
      
      if (text != false)
        this.pelem.innerHTML = text.replace(/(<([^>]+)>)/ig, '');
      
      if (this.pelem.childNodes.length == 0)
        len = 0;
      else
        len = this.pelem.childNodes [0].nodeValue.length;
      
      this.qualified = (((len >= this.qualified_length) || $('#worthy_lyric').prop ('checked')) && !$('#worthy_ignore').prop ('checked'));
      
      this.elem.innerHTML = len;
      $(this.elem).css ({
        'font-weight' : (this.qualified ? 'bold' : 'normal'),
        'font-style' : ((len > this.qualified_warn) && (len < this.qualified_length) ? 'italic' : 'normal'),
        'text-decoration' : ((len > this.qualified_length - ((this.qualified_length - this.qualified_warn) / 2)) && (len < this.qualified_length) ? 'blink' : 'none'),
        'animation' : ((len > this.qualified_length - ((this.qualified_length - this.qualified_warn) / 2)) && (len < this.qualified_length) ? 'blink 2s step-end infinite' : 'none')
      });
      
      if ($('#worthy_embed').prop ('disabled') == this.qualified) {
        if (!this.qualified) {
          $('#worthy_embed').prop ('ochecked', $('#worthy_embed').prop ('checked'));
          $('#worthy_embed').prop ('checked', false);
        } else
          $('#worthy_embed').prop ('checked', $('#worthy_embed').prop ('ochecked'));
          
        $('#worthy_embed').prop ('disabled', !this.qualified);
      }
      
      if (this.qualified && this.auto_assign) {
        $('#worthy_embed').prop ('checked', true);
        this.auto_assign = false;
      }
      
      $('#worthy_embed_label').css ('font-weight', this.qualified && !$('#worthy_embed').prop ('checked') ? 'bold' : 'normal');
    },
    
    postNotice : function (message, classes) {
      if (!(p = document.getElementById ('worthy-notices')))
        return;
      
      if (message.indexOf ('<p>') < 0)
        message = '<p><strong>Worthy:</strong> ' + message + '</p>';
      
      c = document.createElement ('div');
      c.className = 'notice fade ' + classes;
      c.innerHTML = message;
      
      p.appendChild (c);
    }
  }
  
  //$(document).bind ('wpcountwords', function (e, text) {
  //   worthy.counter (text);
  //});
  
  $(document).ready (function () {
    worthy.auto_assign = ($('#worthy_embed').attr ('data-wp-worthy-auto') == 1);
    
    $('#wp-worthy-shop-goods input[type=radio]').change (function () {
      var total = 0;
      var total_tax = 0;
      
      $('#wp-worthy-shop-goods input[type=radio]').each (function () {
        if (!this.checked || (this.value == 'none'))
          return;
        
        total += parseFloat ($(this).attr ('data-value'));
        total_tax += parseFloat ($(this).attr ('data-tax'));
      });
      $('#wp-worthy-shop-price').html (total.toFixed (2).replace ('.', ','));
      $('#wp-worthy-shop-tax').html (total_tax.toFixed (2).replace ('.', ','));
    });
    
    $('#wp-worthy-shop-goods input[type=radio][checked]').change ();
    
    if ($('#wp-worthy-payment-giropay-bic').length > 0)
      $('#wp-worthy-payment-giropay-bic').giropay_widget({'return':'bic','kind':1});
    
    $('div.worthy-signup form').submit (function () {
      if (!$(this).find ('input#wp-worthy-accept-tac').prop ('checked')) {
        alert (wpWorthyLang.accept_tac);
        
        return false;
      }
    });
    
    $('#wp-worthy-shop').submit (function () {
      var have_good = false;
      
      $('#wp-worthy-shop-goods input[type=radio]').each (function () {
        if (this.checked && (this.value != 'none'))
          have_good = true;
      });
      
      if (!have_good) {
        alert (wpWorthyLang.no_goods);
        
        return false;
      }
      
      if (!$(this).find ('input#wp-worthy-accept-tac').prop ('checked')) {
        alert (wpWorthyLang.accept_tac);

        return false;
      }
      
      if ($(this).find ('input#wp-worthy-payment-giropay').prop ('checked') && !$(this).find ('input#wp-worthy-payment-giropay-bic').prop ('value').length) {
        alert (wpWorthyLang.empty_giropay_bic);
        
        return false;
      }
    });
    
    $('span.wp-worthy-inline-title').click (function () {
      var box = document.createElement ('div');
      box.setAttribute ('class', 'wp-worthy-inline-title');
      
      var textbox = document.createElement ('input');
      textbox.setAttribute ('type', 'text');
      textbox.setAttribute ('name', this.getAttribute ('id'));
      textbox.setAttribute ('class', 'wp-worthy-inline-title');
      textbox.value = this.textContent.substr (0, this.textContent.lastIndexOf ("\n"));
      box.appendChild (textbox);
      
      var label = document.createElement ('span');
      label.setAttribute ('class', 'wp-worthy-inline-counter');
      box.appendChild (label);
      
      textbox.onchange = function () {
        label.textContent = '(' + this.value.length + ' ' + wpWorthyLang.characters + ')';
      };
      textbox.oninput = textbox.onchange;
      
      this.parentNode.replaceChild (box, this);
      textbox.onchange ();
    });
    
    $('span.wp-worthy-inline-content').click (function () {
      var textbox = document.createElement ('textarea');
      textbox.setAttribute ('name', this.getAttribute ('id'));
      textbox.setAttribute ('class', 'wp-worthy-inline-content');
      textbox.value = this.textContent;
      textbox.style.height = this.clientHeight + 'px';
      this.parentNode.replaceChild (textbox, this);
    });
    
    $('select#wp-worthy-account-sharing').change (function () {
      $('form.worthy-form p.wp-worthy-no-sharing').css ('display', (this.options [this.selectedIndex].value == 0 ? 'block' : 'none'));
    }).change ();
    
    if ($('th#cb input[type=checkbox]').prop ('checked'))
      $('th.check-column input[type=checkbox]').prop ('checked', true);
    
    $('#content').on ('input keyup', function () { worthy.counter (this.value); });
    $(document).on ('tinymce-editor-init', function (ev, ed) { if (ed.id=='content') ed.on ('nodechange keyup', function () { tinyMCE.triggerSave (); worthy.counter ($('#content').val ()); }); });
  });
}(jQuery));
