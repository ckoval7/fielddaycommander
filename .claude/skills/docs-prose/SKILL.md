---
name: docs-prose
description: Use this skill when writing or editing any user-facing prose for FD Commander, including the website and docs in the github.io branch (docs/ folder, fielddaycommander-pages worktree), how-to guides, the FAQ, feature copy, README text, and release notes. It sets the voice, punctuation rules (no em dashes), and HTML conventions so new text reads like the rest of the site and like a person wrote it.
---

# FD Commander docs prose

The site is written by a ham for hams. It sounds like a club member who has run Field Day a few times and is showing you around the software. Plain, friendly, specific, a little dry humor now and then. Never salesy, never breathless.

## Core principles

Write like an experienced, approachable engineer helping another person get something done. Your job is to make the reader successful, not to make the software sound impressive.

- Start with what the reader wants to accomplish, then tell them what they need to know to do it.
- Use plain, natural English.
- Explain why a step matters when that context helps. Don't explain things an experienced reader already knows.
- Prefer concrete examples and specific instructions over abstract descriptions.
- Vary sentence length and paragraph structure naturally. Don't force every section into the same template.
- Use headings when they help readers find information, not to break up every few sentences.
- Avoid generic introductions, closing summaries, repetitive transitions, and marketing language.
- Don't say the same thing in the introduction, the body, and the conclusion.
- Don't add filler to make a page feel complete. Cover what the reader actually needs, and cover it well.
- Assume the reader is intelligent but may be new to FD Commander.

### Accuracy

- Never invent commands, configuration options, prerequisites, menu paths, labels, or behavior. Check them in the app source on `main` first.
- Check the reasons you give, not just the steps. A step with a made-up justification is still wrong. For example, contacts are scoped to their event in the code, so "delete the practice event so test contacts don't mix with the real ones" gives a false reason.
- If the source doesn't establish a fact, don't guess. Leave it out, or flag it to the user as unverified when you report back.
- The same goes for ham radio facts. Don't claim which modes or bands are busiest, or what most clubs do, without a source. Check operating advice against the event rules too. All digital modes count as one mode at Field Day, so a club can only have one digital signal on a band at a time, and a guide can't suggest RTTY and FT8 sharing 20m.
- Winter Field Day is run by the Winter Field Day Association, not the ARRL. Never write "ARRL Winter Field Day." When a third-party program uses that name, such as fldigi's "ARRL Winter FD" contest entry, quote the label and say who actually runs the event.

### Edit before you hand it back

Read your draft as a human technical editor would. Cut sentences that don't earn their place, replace vague explanations with concrete ones, and rewrite anything that sounds like generic corporate documentation. The page should hold only the finished content, with no notes about how it was written.

## Where the docs live

- Branch `github.io`, served by GitHub Pages from `/docs`. Check it out in a worktree at `../fielddaycommander-pages`.
- `docs/fd-commander-docs.html` is the full reference. `docs/fd-commander-features.html` and `docs/index.html` are marketing pages. `docs/fd-commander-faq.html` answers skeptics. `docs/how-to/` has step-by-step guides, indexed in `docs/how-to/index.html`.
- `docs/sitemap.xml` lists every page. Add new pages there and to `how-to/index.html`.

## Punctuation

- **No em dashes.** Not `—`, not `&mdash;`, not ` -- `, not a spaced hyphen standing in for one. Rewrite the sentence instead:
  - Split it into two sentences. "Operators won't see this field. Their gear is assigned to them automatically."
  - Use a comma for a light aside. "The deploy script, which handles everything, takes about five minutes."
  - Use a colon to introduce a list or an explanation. "Two settings matter here: capacity and confirmation."
  - Use parentheses for a true side note. "(This only matters if you run WSJT-X.)"
- En dashes are fine in ranges only: `Sat 14:00–16:00`, `160m–23cm`.
- No semicolons in casual prose. Use a period.
- Use the serial comma.
- One exclamation point per page at most, and usually none.

## Voice

- Talk to the reader as "you." The club is "your club." Use "we" only on marketing pages, and sparingly.
- Short, plain sentences. Vary the length so it doesn't read like a list of facts. Mix in an occasional longer sentence that explains why something matters.
- Say what a thing does and why someone would care, in that order. Lead with the task, not the feature name. "To see who's covering the overnight hours, open the shift dashboard."
- Use concrete Field Day details: 2 AM operator chairs, the GOTA station, the safety officer walking the site, a tent full of laptops on a generator. These beat generic adjectives.
- Contractions are welcome: you'll, it's, don't, won't.
- Keep the dry humor light and rare. The FAQ intro ("doing Field Day just fine without this, thank you very much") is the ceiling.
- Open a page or section with what it helps you do, not with a scene that sets up the stakes. Skip dramatic lead-ins like "Your Field Day log lives on one server..." followed by a list of things that can go wrong. Readers already know why they opened a backup guide.
- Don't use "one" for emphasis where normal English says "a": "a server", not "one server". Only say "one" when the count matters ("only one replica is supported").
- Don't compress sentences by stripping helper verbs. People say "scripts that will save the database and can put it back on a new server," not "scripts that save the database and put it back on a new server." Use "will," "can," "lets you," and similar words where a person would say them, especially when a sentence chains two verbs together.
- Use as few words as the point needs. Don't stack qualifiers and clauses into one sentence ("can only log on its own station and open a few read-only pages, whoever's callsign is typed in, so nobody can..."). State the fact, then stop: "Station laptops can only log contacts and view a few pages. Admin settings are off limits, even for admins." If a sentence needs a second reading, split it or cut it.
- Say things plainly. Don't write clever-sounding phrases the reader has to decode, like "Most of this guide is for the days you need it." Write what you mean: "The rest of this guide covers restoring and moving servers, so you can come back to it when you need it."
- Leave out internal details the reader doesn't need to act on. On the FAQ and marketing pages, "the app picks the right scoring rules for the event" beats listing rule versions and when they lock. Save that kind of detail for the reference docs, and only include it there if someone needs it to make a choice.
- Don't tack a clause onto a sentence to rule out a problem the reader wasn't worried about ("…ready for the next operator, with no chance of logging under whoever sat there last"). End the sentence where the useful information ends. If a risk really matters, give it its own sentence or a callout.
- Don't reassure the reader that the software is looking after them: "FD Commander picks the right one for you", "has everything ready for you", "takes care of it", "you'll be glad you did". State the fact plainly ("It depends on your operating class:") and move on.
- Prefer the spoken form "doesn't have a" over the clipped "has no": "Winter Field Day doesn't have a safety bonus", not "Winter Field Day has no safety bonus".
- Field Day is an operating event, not a contest. Don't frame it as something clubs win, lose, or beat each other at ("where scores are won", "won or lost in the weeks before"). Talk about earning points, a bigger score, or getting more out of the event.
- Avoid personifying data or software with verbs like "lives", "sits", "breathes", or "speaks". Say where it's stored or what it does.

## Words to avoid

These read as machine-written or as marketing copy. Don't use them:

seamless, seamlessly, robust, powerful, leverage, utilize, effortless, streamline, empower, elevate, unlock (except for features that email literally unlocks), comprehensive, cutting-edge, game-changer, delve, navigate the complexities, in today's world, whether you're X or Y, it's worth noting, it's important to note, simply, just (when it minimizes effort), "not only X but also Y".

Also avoid:

- Triplets used for rhythm ("fast, simple, and reliable") when one precise word would do.
- Starting a paragraph with a question you answer yourself ("Need to export? FD Commander has you covered.").
- Ending a section with a summary sentence that repeats it.
- Bold or italics for emphasis inside a sentence. Bold is for UI labels only.

## Reference conventions (fd-commander-docs.html)

- UI navigation in bold with `&gt;` between steps: `<strong>Administration &gt; Manage Schedule</strong>`.
- Button and field names in bold, matching the app's exact label: `<strong>Add Shift</strong>`.
- Example values in quotes: "Antenna Setup Crew".
- State who can do something when permissions matter: "Admins and event managers can..." "Operators won't see this button."
- Sections are `<h2>` with an `id`, subsections `<h3>`. Add new sections to the table of contents in the sidebar, matching the existing markup.
- Paragraphs carry the content. Use a `<ul>` only for real lists of parallel items, and give each item a bold lead-in when it's a setting or option.
- Before describing a UI, read the Blade view or Livewire component on `main` so labels, menu paths, and permission names are exact. Don't guess at labels.

## How-to guides (docs/how-to/)

How-to guides sound like an experienced club member sitting next to you at the bench, walking you through it for the first time. Friendly, patient, and instructive, like a mentor.

- Explain the why behind a step when it isn't obvious. "Give the Pi a static IP now, so the address you hand out to operators doesn't change halfway through Saturday."
- Tell the reader what they should see after a step, so they know it worked. "You should see a green Listening badge."
- Point out the mistakes people actually make before they make them. "If the packet counter stays at zero, it's almost always the firewall."
- Reassure where it helps. "Don't worry if this takes a minute the first time."
- Assume a ham who is comfortable with radios but maybe not with Linux. Define a term the first time you use it, then move on.
- Use numbered steps for anything done in order, and keep each step to one action.
- Still be direct. A mentor doesn't pad or cheerlead. No "Great job!" and no "Let's dive in."
- Tie a new task to something the reader already does on the air. "Tuning around on digital is a lot like tuning around on SSB, except you do most of it with the mouse" lands better than a description of how the waterfall maps audio to RF.
- Describe what they'll see or hear in the terms a ham would use. "A RTTY signal looks like a pair of thin lines side by side" is better than a frequency calculation.
- Use small, real details from the table: the band change at 3 AM, the operator who left for the food tent without changing the operator callsign. A few of these make a guide sound lived-in. Don't stack them.
- Include on-air courtesy where it applies. Tell a dupe they're a dupe instead of moving on, and keep exchanges short because the other operator wants their next contact too.
- Research to get the facts right, then leave the research out. No rule citations, surveys of options, "the rules say X, but by convention Y," or caveats that only show the homework was done. For a research-heavy guide, draft one section and check the voice with the user before writing the rest. A guide with every fact right can still read like an AI-written paper, and the user will scrap it.

## Screenshots

- `docs/screenshots/` is a library of app screenshots in light and dark pairs, named `<screen>-dark.png` and `<screen>-light.png`. Use one only where it helps the reader with the step they're on. Don't force a fit.
- The site has a dark theme, so use the `-dark` version. Some screenshots exist only in dark (for example `station-card-dark.png`).
- Small, cropped screenshots (a single card or dialog) get `class="screenshot compact"` so they show at their natural size instead of being stretched to full width. Set `width` and `height` to the image's real pixel size.
- Markup: `<figure class="screenshot"><a href="..."><img src="..." alt="..." loading="lazy" width="1920" height="1080"></a><figcaption>...</figcaption></figure>`. Styles live in `fd-commander.css`.
- Clicking a screenshot opens it full size in a modal, handled by `lightbox.js`. Any page with screenshots needs `<script src="../lightbox.js"></script>` (no `../` at the root) after `footer.js`/`toc.js`. The `<a>` must be a direct child of the figure, and the modal reuses the figcaption unless the caption has its own links.
- The alt text describes what's on screen. The caption tells the reader what to notice, and makes no claims the image doesn't show.

## Marketing conventions (index.html, fd-commander-features.html)

- FD Commander has rule sets for both ARRL Field Day and Winter Field Day. On the homepage, don't single out either event: no card about one event, and no ARRL-only details like "the 100-point Safety Officer bonus" or W1AW. Describe features in terms that hold for both ("the bulletin", "your event's rules"). The reference docs and how-to guides can still be specific about each event.
- FD Commander works with whatever computers a club has: club-owned equipment, members' own laptops and tablets (BYOD), or a mix. Never make club equipment sound required, and don't say "club laptop" when you mean "a computer". Station mode is for any computer that stays at a station while operators take turns on it ("bind a shared computer to its station"), whoever owns it. Signing in with a personal account on your own device is equally supported. GOTA and visitors are just more cases of shared computers.
- Lead with the answer a reader is hoping for when it's true. "Do operators need accounts?" opens with "Not to log," then explains station mode, then what accounts are still good for.
- Headlines are short, concrete outcomes: "Know where every piece of gear is."
- Feature checklists use `✓` items, each a short fragment with no ending period.
- Mock UI panels on the features page use realistic Field Day data: real-looking callsigns (KD2ABC), band and mode pairs, Saturday and Sunday times.

## Before you finish

1. Search your changes for `—`, `&mdash;`, ` -- `, and the banned words. Rewrite anything you find.
2. Read each new paragraph aloud in your head. If it sounds like a press release, cut it down.
3. Check that every menu path and label still matches the app on `main`.
