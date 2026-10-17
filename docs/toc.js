(function () {
  var guide = document.querySelector('.guide-layout');
  var faq = document.querySelector('.faq-section');
  var content = guide || faq;
  if (!content) return;

  function slugify(text) {
    return text.toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 60);
  }

  function ensureId(el) {
    if (!el.id) {
      var base = slugify(el.textContent) || 'section';
      var id = base;
      var n = 2;
      while (document.getElementById(id)) { id = base + '-' + n++; }
      el.id = id;
    }
    return el.id;
  }

  // Each group: { title, el, items: [{ title, el }] }
  var groups = [];
  if (guide) {
    guide.querySelectorAll('h2').forEach(function (h) {
      groups.push({ title: h.textContent.trim(), el: h, items: [] });
    });
  } else {
    faq.querySelectorAll('.faq-category').forEach(function (cat) {
      var titleEl = cat.querySelector('.faq-category-title');
      var items = [];
      cat.querySelectorAll('.faq-question').forEach(function (q) {
        items.push({ title: q.textContent.trim(), el: q });
      });
      groups.push({ title: titleEl ? titleEl.textContent.trim() : '', el: titleEl || cat, items: items });
    });
  }

  var count = groups.reduce(function (n, g) { return n + 1 + g.items.length; }, 0);
  if (groups.length < 3 && count < 5) return;

  var targets = [];

  function buildList() {
    var ul = document.createElement('ul');
    groups.forEach(function (g) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = '#' + ensureId(g.el);
      a.textContent = g.title;
      a.className = g.items.length ? 'toc-group' : '';
      li.appendChild(a);
      if (g.items.length) {
        var sub = document.createElement('ul');
        g.items.forEach(function (item) {
          var sli = document.createElement('li');
          var sa = document.createElement('a');
          sa.href = '#' + ensureId(item.el);
          sa.textContent = item.title;
          sli.appendChild(sa);
          sub.appendChild(sli);
        });
        li.appendChild(sub);
      }
      ul.appendChild(li);
    });
    return ul;
  }

  groups.forEach(function (g) {
    targets.push(g.el);
    g.items.forEach(function (i) { targets.push(i.el); });
  });

  // Floating panel for wide screens
  var aside = document.createElement('aside');
  aside.className = 'page-toc';
  aside.setAttribute('aria-label', 'On this page');
  var heading = document.createElement('div');
  heading.className = 'page-toc-title';
  heading.textContent = 'On this page';
  aside.appendChild(heading);
  aside.appendChild(buildList());
  aside.style.setProperty('--toc-offset', (content.offsetWidth / 2 + 48) + 'px');
  document.body.appendChild(aside);

  // Collapsible block at the top of the content for narrow screens
  var details = document.createElement('details');
  details.className = 'page-toc-inline';
  var summary = document.createElement('summary');
  summary.textContent = 'On this page';
  details.appendChild(summary);
  details.appendChild(buildList());
  var breadcrumb = content.querySelector('.guide-breadcrumb');
  if (breadcrumb) {
    breadcrumb.insertAdjacentElement('afterend', details);
  } else {
    content.insertBefore(details, content.firstChild);
  }
  details.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') { details.open = false; }
  });

  // Highlight the section currently in view
  var links = aside.querySelectorAll('a');
  var ticking = false;
  function update() {
    ticking = false;
    var line = window.innerHeight * 0.3;
    var current = null;
    for (var i = 0; i < targets.length; i++) {
      if (targets[i].getBoundingClientRect().top <= line) { current = targets[i].id; } else { break; }
    }
    links.forEach(function (a) {
      var active = current && a.getAttribute('href') === '#' + current;
      a.classList.toggle('active', !!active);
      if (active && aside.scrollHeight > aside.clientHeight) {
        var top = a.offsetTop - aside.clientHeight / 2;
        if (Math.abs(aside.scrollTop - top) > aside.clientHeight / 3) { aside.scrollTop = top; }
      }
    });
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
  }, { passive: true });
  // Headings get their ids from this script, so honor a deep link once they exist
  if (window.location.hash) {
    var target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
    if (target) { target.scrollIntoView(); }
  }

  window.addEventListener('resize', function () {
    aside.style.setProperty('--toc-offset', (content.offsetWidth / 2 + 48) + 'px');
    update();
  });
  update();
})();
