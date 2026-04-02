(function () {
  var nav = document.querySelector('nav');
  if (!nav) return;

  // Figure out if we're in a subdirectory
  var path = window.location.pathname;
  var depth = (path.match(/\//g) || []).length - 1;
  // On GitHub Pages, root pages are at /file.html (depth 0)
  // Subdirectory pages are at /how-to/file.html (depth 1)
  // Adjust for trailing slash: /how-to/ counts as depth 1
  var inSubdir = path.indexOf('/how-to/') !== -1;
  var root = inSubdir ? '../' : '';

  fetch(root + 'nav.html')
    .then(function (r) { return r.text(); })
    .then(function (html) {
      // Replace path placeholders
      html = html.replace(/\{\{root\}\}/g, root);

      nav.innerHTML = html;

      // Set active state based on current page
      var page = path.split('/').pop() || 'index.html';
      var links = nav.querySelectorAll('.nav-links a');

      links.forEach(function (link) {
        var href = link.getAttribute('href');
        var linkPage = href.split('/').pop();

        if (linkPage === page) {
          link.classList.add('active');
        }
      });

      // Set How-To dropdown label active if we're in a how-to page
      if (inSubdir) {
        var label = nav.querySelector('.nav-dropdown-label');
        if (label) label.classList.add('active');
      }
    });
})();
