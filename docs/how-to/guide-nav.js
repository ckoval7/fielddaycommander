// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

(function () {
  var nav = document.querySelector('.guide-nav');
  if (!nav) return;

  // Reading order: server setup, planning, logging, wrap-up, optional extras.
  // Add or reorder guides here to update every article's previous/next links.
  var guides = [
    { file: "pi-setup.html", title: "Prepare a Raspberry Pi" },
    { file: "ssh-basics.html", title: "SSH & Command-Line Basics" },
    { file: "install.html", title: "Install Field Day Commander" },
    { file: "lan-setup.html", title: "Set Up a LAN for Field Operations" },
    { file: "first-event.html", title: "First Event Setup" },
    { file: "bonuses-objectives.html", title: "Plan Your Bonuses and Objectives" },
    { file: "safety-officer-checklist.html", title: "Work Through the Safety Checklist" },
    { file: "multi-station-logging.html", title: "Set Up Multi-Station Logging" },
    { file: "station-mode.html", title: "Set Up Station Mode" },
    { file: "gota-station.html", title: "Run the GOTA Station" },
    { file: "external-loggers.html", title: "Connect External Loggers" },
    { file: "fldigi-digital-modes.html", title: "Digital Modes with fldigi" },
    { file: "submit-field-day-entry.html", title: "Submit Your Field Day Entry" },
    { file: "backup-restore.html", title: "Back Up and Restore" },
    { file: "email-setup.html", title: "Email Setup" },
    { file: "dns-tls.html", title: "DNS & TLS Setup" }
  ];

  var page = window.location.pathname.split('/').pop();
  var index = guides.findIndex(function (guide) { return guide.file === page; });
  if (index === -1) return;

  var allGuides = { file: 'index.html', title: 'All Guides' };
  var previous = guides[index - 1] || allGuides;
  var next = guides[index + 1] || allGuides;

  function makeLink(guide, isPrevious) {
    var link = document.createElement('a');
    link.href = guide.file;
    link.textContent = isPrevious ? '\u2190 ' + guide.title : guide.title + ' \u2192';
    if (guide !== allGuides) link.rel = isPrevious ? 'prev' : 'next';
    return link;
  }

  nav.replaceChildren(makeLink(previous, true), makeLink(next, false));
})();
