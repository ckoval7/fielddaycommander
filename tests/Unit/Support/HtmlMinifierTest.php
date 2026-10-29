<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\HtmlMinifier;

it('collapses indentation to single line breaks', function () {
    expect(HtmlMinifier::minify("<div>\n        <span>a</span>   \n\n    <span>b</span>\n</div>"))
        ->toBe("<div>\n<span>a</span>\n<span>b</span>\n</div>");
});

it('leaves whitespace on a single line alone', function () {
    expect(HtmlMinifier::minify('<span>a</span>   <span>b</span>'))->toBe('<span>a</span>   <span>b</span>');
});

it('drops comments but keeps Livewire morph markers', function () {
    $html = '<div><!-- AVATAR --><!--[if BLOCK]><![endif]--><p>x</p><!--[if ENDBLOCK]><![endif]--></div>';

    expect(HtmlMinifier::minify($html))->toBe('<div><!--[if BLOCK]><![endif]--><p>x</p><!--[if ENDBLOCK]><![endif]--></div>');
});

it('keeps whitespace that renders', function (string $html) {
    expect(HtmlMinifier::minify("<div>\n    $html\n</div>"))->toBe("<div>\n$html\n</div>");
})->with([
    'pre' => "<pre>line one\n    indented</pre>",
    'textarea' => "<textarea name=\"notes\">first\n    second</textarea>",
    'script' => "<script>\n    // comment\n    let a = 1;\n</script>",
    'style' => "<style>\n    p { color: red; }\n</style>",
    'pre-wrap class' => "<p class=\"text-sm whitespace-pre-wrap\">Notes\n    kept</p>",
    'pre-line style' => "<div style=\"white-space: pre-line\">a\n    b</div>",
    'comment-like text in script' => "<script>\n    const s = '<!-- not a comment -->';\n</script>",
]);

it('reads tags whose attributes contain >', function () {
    $html = "<pre x-data=\"{ ok: a => a > 1 }\">keep\n    this</pre>";

    expect(HtmlMinifier::minify($html))->toBe($html);
});

it('keeps line comments inside Alpine expressions working', function () {
    expect(HtmlMinifier::minify("<div x-data=\"{\n    // the count\n    n: 0\n}\"></div>"))
        ->toBe("<div x-data=\"{\n// the count\nn: 0\n}\"></div>");
});

it('gives the same result when run twice', function () {
    $html = "<div>\n  <span>a</span>  <!-- note -->\n   <b>b</b>\n  <pre> x\n  y</pre>\n</div>";
    $once = HtmlMinifier::minify($html);

    expect(HtmlMinifier::minify($once))->toBe($once);
});

it('drops indentation that follows a morph marker at the start of a line', function () {
    expect(HtmlMinifier::minify("<div>\n    <!--[if BLOCK]><![endif]-->        <a>x</a>\n    <!--[if ENDBLOCK]><![endif]-->    </div>"))
        ->toBe("<div>\n<!--[if BLOCK]><![endif]--><a>x</a>\n<!--[if ENDBLOCK]><![endif]--></div>");
});
