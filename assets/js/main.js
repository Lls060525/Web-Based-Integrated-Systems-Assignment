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
});
