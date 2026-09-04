# Building a site from a picture

Vela can take a picture of a website — a screenshot, a mockup from a designer,
a photo of a sketch on paper — and build your site to look like it. You do not
need to write anything, install anything, or open a terminal.

This page explains what it does, what it does not do, and what to do when the
result is not what you hoped for.

## Before you start

Go to **Settings → Build from a design**. The page lists what it needs before
the button will work:

- **An AI provider.** A key from OpenAI, Anthropic or Gemini, added under
  Settings → AI. Without one there is nothing to read your picture. The line
  names the provider and the model a build will run on, because that is the
  largest single difference between a build that comes out well and one that
  comes out thin — if yours disappoints, read this line before building again.
  It is set by `AI_CHAT_DESIGN_ANTHROPIC_MODEL` (and its OpenAI and Gemini
  counterparts) in `.env`, and falls back to the site's everyday chat model
  when those are empty.
- **A browser.** The builder photographs your site to check its own work. If
  the machine has no browser it can use, it downloads one the first time you
  build — around 350 MB, and nothing outside your site's own storage changes.
- **Background builds.** Most servers allow this. If yours does not, the page
  says so and tells you the command to run instead.

Each of these shows a tick when it is ready.

## The three steps

**1. Show us the design.** Drop a picture onto the box, or click it to choose
one. Each file says what it will be taken for: *the design*, *a logo*, or *the
brief*.

Uploading adds to what is there — it does not replace it. If you upload a
second design, both are used and the result tries to satisfy both. The page
tells you how many pictures will be used; remove any you did not mean to
include.

**2. Say what it is for.** A sentence or two: what the site is, who it is for,
anything the picture does not show. "A wood-fired restaurant on the Wellington
harbour" is enough. This matters more than it looks — it is how the builder
knows what the words in your picture mean.

**3. Say where it goes.** A design is either your **homepage** or **a page**,
and the two are genuinely different jobs.

- **My homepage** — the whole site. The build writes a theme, sets the
  navigation and builds the front page. This is the right answer for the first
  design you give a site.
- **A page I already have** / **A new page** — content only. The build writes
  the sections of your picture into the theme you already have, and does not
  touch your theme, your navigation or your site's name. Use this for an About
  page, a pricing page, a landing page for a campaign.

The reason a page build cannot write a theme is that a theme belongs to the
whole site. A mockup of one inside page, allowed to write one, would redress
every other page on the site — which is the last thing anybody asking for an
About page wants. So those tools are not given to it at all, and its header and
footer come from the site rather than from the picture. A design's own header
being different from yours is not a fault the build will chase.

**4. Build it.** Choose how many rounds and press the button.

| Rounds | Roughly | Good for |
|---|---|---|
| 1 | a few minutes | seeing what you get |
| 3 | ten minutes or so | the usual choice |
| 5 | longer | when the design is complicated |

A round is one pass: the builder photographs what it has made, compares it with
your picture, and corrects the differences. More rounds is not always better —
the first round is often already close.

You can close the page. The build keeps going, and the page picks it back up
when you return.

## What you get

The build makes **its own page**, at `/design-preview`, whichever destination
you chose. Your site is not touched while it works, so visitors go on seeing it
exactly as it is; a homepage build's theme and navigation belong to that one
page until you say otherwise.

When it finishes you get:

- **Open it** — see the result as a visitor would.
- **Use this as…** — put it in place, at the destination you chose. Whatever
  was standing there is kept, unlisted, so you can go back to it. For a
  homepage this is also the moment the design's theme and navigation become
  your site's, and the name the design gave the site becomes your site's name.
  For a page, only the page moves: an existing page keeps its own title, so
  every link and menu item pointing at it still reads right.
- **Put back the site I had** — undoes all of that, and keeps the design so you
  can go forward again.

If you do not like it, do nothing. Change the brief, or the picture, and build
again.

## What it is good at

- **Reading your picture.** Headlines, prices, opening hours, the words on
  buttons, the numbers in a statistics strip — these come out right.
- **The look.** It writes your site a theme of its own — your colours, your
  typeface, your header and footer — and then writes each section of the page
  to match what your picture shows: the arrangement, the proportions, the
  spacing. Sections are not fitted into ready-made shapes, which is why the
  result resembles your design rather than resembling this CMS.
- **Leaving you in charge.** Open **Pages → your page → Edit** and you get a
  plain form: every headline, sentence, button label, picture and link on the
  page, ready to change. Nothing it makes is locked away in code.

## What it is not

- **Not a pixel-for-pixel copy.** It comes close on arrangement, colour and
  type. It cannot match a typeface it has no access to, and the photographs in
  your design are not in it — put your own in from the page editor.
- **Not a section you can rearrange by dragging.** The words, pictures and
  links in a written section are editable as a form; moving its parts around
  means editing HTML, or asking for that section to be built again.
- **Not a photographer.** It makes at most three pictures, for the places where
  a photograph or an illustration IS the content. It does not draw icons — those
  are shapes, and it writes them into the section — and it will not draw a logo
  at all: a strip of company logos in a design shows where your own customers
  go, and a drawn one is somebody else's trademark, approximated, saying they
  work with you. Untick **Make pictures for it** if you have your own; the slots
  are left with a plain grey frame in them, at the right size, ready for you to
  drop the real picture in from the page editor.
- **Not consistent between runs.** The same picture built twice gives two
  results of similar quality but different detail. If a run comes out thin,
  build again.

## When something goes wrong

**The build says it failed.** The reason is on the page. The most common one is
an AI key with no credit left.

**The site stopped working.** It will not have. If a build ever leaves your
site unable to load, it puts the theme back the way it was and tells you the
design was not applied.

**The result is nothing like the design.** Check that only one picture is
listed as *the design*, and that your brief says what the site is. A build
given two designs, or no explanation, has to guess.

**A section is in the wrong words.** Edit it. **Pages → your page → Edit** puts
every piece of wording in the section in front of you as a form, and changing
one takes ten seconds where rebuilding takes ten minutes.

## Where things are afterwards

- The design you uploaded stays in the design folder until you remove it.
- Each round's photograph and report are kept under **What it produced**, so
  you can see how the result changed.
- The theme it wrote lives with your site's other themes and can be switched
  away from and back to at any time.
