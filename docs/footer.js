(function () {
  var footer = document.querySelector('footer');
  if (!footer) return;

  var path = window.location.pathname;
  var inSubdir = path.indexOf('/how-to/') !== -1;
  var root = inSubdir ? '../' : '';

  fetch(root + 'footer.html')
    .then(function (r) { return r.text(); })
    .then(function (html) {
      html = html.replace(/\{\{root\}\}/g, root);
      footer.innerHTML = html;
    });
})();
