/* RTOFLOW OS — Public/Client/Vendor JS */
(function() {
  'use strict';

  // Scroll message boxes to bottom
  document.querySelectorAll('.rto-messages, #msgBox').forEach(function(box) {
    box.scrollTop = box.scrollHeight;
  });

  // P3-JS-003 FIX: nav toggle with aria-expanded management
  var navToggle = document.querySelector('.rto-nav-toggle');
  var clientNav = document.querySelector('.rto-client-nav');
  if (navToggle && clientNav) {
    navToggle.addEventListener('click', function() {
      var isOpen = clientNav.classList.toggle('open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  // Auto-close success messages
  document.querySelectorAll('.rto-msg-success').forEach(function(msg) {
    setTimeout(function() {
      msg.style.opacity = '0';
      setTimeout(function() { msg.style.display = 'none'; }, 400);
    }, 5000);
  });

})();
