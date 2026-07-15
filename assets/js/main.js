$(function(){
  // simple search UX: focus
  $('.search input').on('focus', function(){ $(this).closest('.search').addClass('focused'); });
  $('.search input').on('blur', function(){ $(this).closest('.search').removeClass('focused'); });

  // cart count placeholder (could be updated via AJAX)
  function updateCartCount(n){ $('.cart-count').text(n); }
  // demo: read from localStorage
  var count = parseInt(localStorage.getItem('cartCount')||'0',10);
  updateCartCount(count);

  // example: add-to-cart buttons should trigger this event
  $(document).on('click', '.add-to-cart', function(e){
    e.preventDefault();
    count = (count || 0) + 1;
    localStorage.setItem('cartCount', count);
    updateCartCount(count);
  });

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
  
});
