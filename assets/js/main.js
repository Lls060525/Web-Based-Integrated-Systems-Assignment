$(function(){
  // simple search UX: focus
  $('.search input').on('focus', function(){ $(this).closest('.search').addClass('focused'); });
  $('.search input').on('blur', function(){ $(this).closest('.search').removeClass('focused'); });

  // (AJAX Integration) ---
  $(document).on('click', '.add-to-cart', function(e){
    e.preventDefault();
    var productId = $(this).data('id');
    var $btn = $(this);
    
    
    var originalText = $btn.text();
    $btn.text('Adding...').prop('disabled', true);

    $.ajax({
        url: '/api/cart_action.php',
        type: 'POST',
        data: { action: 'add', product_id: productId },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                $('.cart-count').text(res.cart_count);
                showJSToast(res.message, 'success');
            } else {
                showJSToast(res.message, 'error');
     
                if(res.message.includes('log in')) {
                    setTimeout(function(){ window.location.href = '/auth/login.php'; }, 1500);
                }
            }
        },
        error: function() {
            showJSToast('Server error. Please try again.', 'error');
        },
        complete: function() {
            $btn.text(originalText).prop('disabled', false); 
        }
    });
  });

  
  function showJSToast(message, type) {
      $('.js-toast').remove(); 
      var cssClass = type === 'success' ? 'toast-success' : 'toast-error';
      var $toast = $('<div class="toast-message js-toast ' + cssClass + '">' + message + '</div>');
      $('body').append($toast);
      $toast.fadeIn(300).delay(3000).fadeOut(300, function(){ $(this).remove(); });
  }

  // --- Profile Sidebar Tabs Transition ---
  $('.profile-nav a[href^="#"]').on('click', function(e) {
    e.preventDefault(); 


    $('.profile-nav a').removeClass('active');
    $(this).addClass('active');

    
    var targetCard = $(this).attr('href');


    $('.profile-content .card').hide();
    $(targetCard).fadeIn(300);
  });

  // --- Global Toast Notification Logic ---

  if ($('.toast-message').length > 0) {
      $('.toast-message').fadeIn(400).delay(3000).fadeOut(400);
  }

  $('#photoInput').on('change', function(e) {
    var file = e.target.files[0];
    if (file) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#avatarPreview').attr('src', e.target.result);
            $('#uploadBtn').fadeIn(); 
        }
        reader.readAsDataURL(file);
    }
  });

  // --- Smart Save Button (Dirty Checking) ---
  var $profileName = $('#profileName');
  var $profileEmail = $('#profileEmail');
  var $saveProfileBtn = $('#saveProfileBtn');

  function checkProfileChanges() {
      
      var originalName = $profileName.data('original');
      var originalEmail = $profileEmail.data('original');
      
    
      var currentName = $profileName.val().trim();
      var currentEmail = $profileEmail.val().trim();

      
      if (currentName !== originalName || currentEmail !== originalEmail) {
          if (!$saveProfileBtn.is(':visible')) {
              $saveProfileBtn.fadeIn(200); // 200ms 丝滑淡入
          }
      } else {
         
          if ($saveProfileBtn.is(':visible')) {
              $saveProfileBtn.fadeOut(200);
          }
      }
  }

 
  $profileName.on('input', checkProfileChanges);
  $profileEmail.on('input', checkProfileChanges);
  
 
  var searchTimer; 
  
  
  $('.admin-search-form').on('submit', function(e) {
      e.preventDefault();
  });

  
  $('.admin-search-input').on('input', function() {
      var query = $(this).val();
      var $tbody = $('.admin-table tbody');

      
      clearTimeout(searchTimer);
      
      
      searchTimer = setTimeout(function() {
          
          $tbody.html('<tr><td colspan="6" class="text-center" style="padding: 20px; color: var(--text-muted);">Searching...</td></tr>');

          $.ajax({
              url: window.location.pathname,
              type: 'GET',
              data: { q: query }, 
              success: function(response) {
                  
                  $tbody.html(response);
                  
                  
                  var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + (query ? '?q=' + encodeURIComponent(query) : '');
                  window.history.pushState({path: newUrl}, '', newUrl);
              },
              error: function() {
                  $tbody.html('<tr><td colspan="6" class="text-center" style="color: red;">Error fetching data.</td></tr>');
              }
          });
      }, 300); 
  });
});

window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Force a hard reload from the server
        window.location.reload();
    }
});
