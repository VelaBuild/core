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
  Settings → AI. Without one there is nothing to read your picture.
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

**3. Build it.** Choose how many rounds and press the button.

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

The build makes **its own page**, at `/design-preview`. Your site is not
touched while it works, and your existing homepage stays exactly as it is.

When it finishes you get:

- **Open it** — see the result as a visitor would.
- **Use this as my homepage** — put it in place. Your old homepage is kept,
  unlisted, so you can go back to it. The name the design gave the site becomes
  your site's name at this point, and not before.

If you do not like it, do nothing. Change the brief, or the picture, and build
again.

## What it is good at

- **Reading your picture.** Headlines, prices, opening hours, the words on
  buttons, the numbers in a statistics strip — these come out right.
- **The look.** It writes your site a theme of its own: your colours, your
  typeface, your header and footer, the shape of your cards.
- **Leaving you in charge.** Everything it puts on the page is an ordinary
  block. Open **Pages → your page → Edit** and change any of it — a headline, a
  price, a photo — the way you would change anything else. Nothing it makes is
  locked away in code.

## What it is not

- **Not a pixel-for-pixel copy.** It builds something that reads as the same
  design, not a tracing of it.
- **Not a photographer.** It can generate pictures, but the ones in your design
  are not yours to keep unless you own them. Add your own photos in the page
  editor.
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

**A section is in the wrong words.** Edit it. It is a block like any other, and
editing it takes ten seconds where rebuilding takes ten minutes.

## Where things are afterwards

- The design you uploaded stays in the design folder until you remove it.
- Each round's photograph and report are kept under **What it produced**, so
  you can see how the result changed.
- The theme it wrote lives with your site's other themes and can be switched
  away from and back to at any time.
