(function($,undefined) {
  worthy = {
    setup : false,
    elem : false,
    pelem : false,
    qualified : false,
    qualified_length : 1800,
    qualified_warn : 1600,
    
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
  
  $(document).bind ('wpcountwords', function (e, text) {
    worthy.counter (text);
  });
  
  $(document).ready (function () {
    $('#worthy-shop-goods input[type=radio]').change (function () {
      var total = 0;
      var total_tax = 0;
      
      $('#worthy-shop-goods input[type=radio]').each (function () {
        if (!this.checked || (this.value == 'none'))
          return;
        
        total += parseFloat ($(this).attr ('data-value'));
        total_tax += parseFloat ($(this).attr ('data-tax'));
      });
      $('#worthy-shop-price').html (total.toFixed (2).replace ('.', ','));
      $('#worthy-shop-tax').html (total_tax.toFixed (2).replace ('.', ','));
    });
    
    $('#worthy-shop-goods input[type=radio][checked]').change ();
    
    if ($('#worthy-giropay-bic').length > 0)
      $('#worthy-giropay-bic').giropay_widget({'return':'bic','kind':1});
    
    $('div.worthy-signup form').submit (function () {
      if (!$(this).find ('input#wp-worthy-accept-tac').prop ('checked')) {
        alert (wpWorthyLang.accept_tac);
        
        return false;
      }
    });
    $('#worthy-shop').submit (function () {
      var have_good = false;
      
      $('#worthy-shop-goods input[type=radio]').each (function () {
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
      
      if ($(this).find ('input#worthy-payment-giropay').prop ('checked') && !$(this).find ('input#worthy-giropay-bic').prop ('value').length) {
        alert (wpWorthyLang.empty_giropay_bic);
        
        return false;
      }
    });
    
    $('span.wp-worthy-inline-title').click (function () {
      var textbox = document.createElement ('input');
      textbox.setAttribute ('type', 'text');
      textbox.setAttribute ('name', this.getAttribute ('id'));
      textbox.setAttribute ('class', 'wp-worthy-inline-title');
      textbox.value = this.textContent.substr (0, this.textContent.lastIndexOf ("\n"));
      this.parentNode.replaceChild (textbox, this);
    });
    
    $('span.wp-worthy-inline-content').click (function () {
      var textbox = document.createElement ('textarea');
      textbox.setAttribute ('name', this.getAttribute ('id'));
      textbox.setAttribute ('class', 'wp-worthy-inline-content');
      textbox.value = this.textContent;
      textbox.style.height = this.clientHeight + 'px';
      this.parentNode.replaceChild (textbox, this);
    });
  });
}(jQuery));
