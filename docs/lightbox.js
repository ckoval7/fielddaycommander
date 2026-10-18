// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

(function () {
  var links = document.querySelectorAll('.screenshot > a[href$=".png"], .screenshot > a[href$=".jpg"], .screenshot > a[href$=".webp"]');
  if (!links.length || typeof HTMLDialogElement !== 'function') return;

  var dialog = document.createElement('dialog');
  dialog.className = 'lightbox';
  dialog.setAttribute('aria-label', 'Screenshot');
  dialog.innerHTML =
    '<button type="button" class="lightbox-close" aria-label="Close">&times;</button>' +
    '<img alt="">' +
    '<p class="lightbox-caption"></p>';
  document.body.appendChild(dialog);

  var img = dialog.querySelector('img');
  var caption = dialog.querySelector('.lightbox-caption');

  links.forEach(function (link) {
    link.addEventListener('click', function (e) {
      // Let ctrl/cmd/shift/middle clicks open the image in a new tab as usual
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      var thumb = link.querySelector('img');
      var figcaption = link.closest('figure') && link.closest('figure').querySelector('figcaption');
      // A caption with its own links would be dead text in the viewer, so leave it out
      if (figcaption && figcaption.querySelector('a')) figcaption = null;
      img.src = link.href;
      img.alt = thumb ? thumb.alt : '';
      caption.textContent = figcaption ? figcaption.textContent : '';
      caption.hidden = !figcaption;
      dialog.showModal();
    });
  });

  // Any click closes it: the image, the backdrop, or the close button
  dialog.addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('close', function () { img.removeAttribute('src'); });
})();
