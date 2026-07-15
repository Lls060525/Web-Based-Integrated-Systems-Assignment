$(function(){
  // simple search UX: focus
  $('.search input').on('focus', function(){ $(this).closest('.search').addClass('focused'); });
  $('.search input').on('blur', function(){ $(this).closest('.search').removeClass('focused'); });

  // --- 购物车动态交互 (AJAX Integration) ---
  $(document).on('click', '.add-to-cart', function(e){
    e.preventDefault();
    var productId = $(this).data('id');
    var $btn = $(this);
    
    // UI 反馈：防止用户连续狂点
    var originalText = $btn.text();
    $btn.text('Adding...').prop('disabled', true);

    $.ajax({
        url: '/api/cart_action.php',
        type: 'POST',
        data: { action: 'add', product_id: productId },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                $('.cart-count').text(res.cart_count); // 瞬间更新右上角购物车数字
                showJSToast(res.message, 'success');
            } else {
                showJSToast(res.message, 'error');
                // 如果后端说没登录，1.5秒后自动踢去登录页
                if(res.message.includes('log in')) {
                    setTimeout(function(){ window.location.href = '/auth/login.php'; }, 1500);
                }
            }
        },
        error: function() {
            showJSToast('Server error. Please try again.', 'error');
        },
        complete: function() {
            $btn.text(originalText).prop('disabled', false); // 恢复按钮状态
        }
    });
  });

  // 让 JS 也能呼叫我们之前做好的 Toast 弹窗动画
  function showJSToast(message, type) {
      $('.js-toast').remove(); 
      var cssClass = type === 'success' ? 'toast-success' : 'toast-error';
      var $toast = $('<div class="toast-message js-toast ' + cssClass + '">' + message + '</div>');
      $('body').append($toast);
      $toast.fadeIn(300).delay(3000).fadeOut(300, function(){ $(this).remove(); });
  }

  // --- Profile Sidebar Tabs Transition ---
  $('.profile-nav a[href^="#"]').on('click', function(e) {
    e.preventDefault(); // 阻止浏览器默认的生硬锚点跳转

    // 1. 切换左侧菜单的橘色 Active 高亮状态
    $('.profile-nav a').removeClass('active');
    $(this).addClass('active');

    // 2. 获取用户点击的目标卡片 ID (例如: #profile-password)
    var targetCard = $(this).attr('href');

    // 3. 丝滑过渡：先隐藏当前所有卡片，然后用 300ms 淡入目标卡片
    $('.profile-content .card').hide();
    $(targetCard).fadeIn(300);
  });

  // --- Global Toast Notification Logic ---
  // 如果页面中存在 toast 消息，则平滑淡入，显示 3 秒后自动淡出
  if ($('.toast-message').length > 0) {
      $('.toast-message').fadeIn(400).delay(3000).fadeOut(400);
  }

  $('#photoInput').on('change', function(e) {
    var file = e.target.files[0];
    if (file) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#avatarPreview').attr('src', e.target.result);
            $('#uploadBtn').fadeIn(); // 这里的 fadeIn 就是让它淡入显示
        }
        reader.readAsDataURL(file);
    }
  });

  // --- Smart Save Button (Dirty Checking) ---
  var $profileName = $('#profileName');
  var $profileEmail = $('#profileEmail');
  var $saveProfileBtn = $('#saveProfileBtn');

  function checkProfileChanges() {
      // 获取 HTML 中存入的初始原始值
      var originalName = $profileName.data('original');
      var originalEmail = $profileEmail.data('original');
      
      // 获取用户当前输入框里实时的值 (去除首尾多余空格)
      var currentName = $profileName.val().trim();
      var currentEmail = $profileEmail.val().trim();

      // 判断：只要有任何一个值和原来不一样，就显示按钮
      if (currentName !== originalName || currentEmail !== originalEmail) {
          if (!$saveProfileBtn.is(':visible')) {
              $saveProfileBtn.fadeIn(200); // 200ms 丝滑淡入
          }
      } else {
          // 如果用户又把值改回去了，完全匹配原始数据，则隐藏按钮
          if ($saveProfileBtn.is(':visible')) {
              $saveProfileBtn.fadeOut(200);
          }
      }
  }

  // 监听 'input' 事件：用户无论是敲击键盘、复制粘贴还是撤销，都会瞬间触发
  $profileName.on('input', checkProfileChanges);
  $profileEmail.on('input', checkProfileChanges);
  
  // --- Admin Dynamic Search (AJAX Integration) ---
  var searchTimer; // 用于防抖 (Debounce) 的计时器
  
  // 阻止搜索表单按回车时导致页面跳转刷新
  $('.admin-search-form').on('submit', function(e) {
      e.preventDefault();
  });

  // 监听搜索框的实时输入事件
  $('.admin-search-input').on('input', function() {
      var query = $(this).val();
      var $tbody = $('.admin-table tbody');

      // 清除上一次的计时器（用户如果连续打字，就不会发请求）
      clearTimeout(searchTimer);
      
      // 等用户停止打字 300 毫秒后，再向服务器发送 AJAX 请求
      searchTimer = setTimeout(function() {
          // 可选：在等待数据时显示 Loading 状态
          $tbody.html('<tr><td colspan="6" class="text-center" style="padding: 20px; color: var(--text-muted);">Searching...</td></tr>');

          $.ajax({
              url: '/admin/members.php',
              type: 'GET',
              data: { q: query }, // 发送搜索关键词
              success: function(response) {
                  // 将服务器吐出的纯 <tr> 标签直接塞进表格主体
                  $tbody.html(response);
                  
                  // 高级 UX 细节：利用 HTML5 History API 悄悄修改网址栏，方便用户刷新或分享，但不触发页面重载
                  var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + (query ? '?q=' + encodeURIComponent(query) : '');
                  window.history.pushState({path: newUrl}, '', newUrl);
              },
              error: function() {
                  $tbody.html('<tr><td colspan="6" class="text-center" style="color: red;">Error fetching data.</td></tr>');
              }
          });
      }, 300); // 300ms 防抖时间
  });
});

window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Force a hard reload from the server
        window.location.reload();
    }
});
