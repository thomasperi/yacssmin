# YaCSSMin
Yet Another CSS Minifier

## Usage

    $minified_css = \ThomasPeri\YaCSSMin\Minifier::minify($css);

## Goals
Why write another CSS Minifier? I had a few goals in mind:

* No library dependencies.
* Small codebase. (~300 lines, 8 KB)
* Readable and maintainable. Regular expressions used sparingly.
* Don't break valid CSS even on weird corner cases.
* Don't accidentally fix broken CSS. It's a source of confusion.
* Strike a balance between handling the corner cases and keeping things simple.

## Two Big Challenges
CSS comments and white space are weird.

### #1: Comments

Comments in CSS are "ignored," sure. But exactly what that means depends on the context. In some parts of a stylesheet they're equivalent to nothing-at-all. These two selectors are equivalent:

    div.bar
    div/* foo */.bar
    
In other contexts, a comment is equivalent to whitespace. These two media queries are equivalent:

    @media screen and (min-width: 500px)
    @media screen/* foo */and (min-width: 500px)

How do we go about writing rules to match the behavior?

Well, the vast majority of comments you'll actually write aren't going to be inside selectors or media queries, so the minifier doesn't need to optimize for those. And when a comment is where you'd normally put one -- before a selector, or before a declaration -- its context is easy to test for.

It will be adjacent to a space (the beginning or end of the file counts as a space) and/or any of these characters:

    ,;{}

So that's all YaCSSMin tests for. Comments that meet those criteria get stripped. Comments in weird places get replaced with empty comments, so as far as a web browser is concerned, it's still a comment. The minifier doesn't have to worry about whether it's supposed to be a space or a nothing.

    div/**/.bar
    @media screen/**/and (min-width: 500px)

### #2: Whitespace

Whitespace in CSS suffers a similar ambiguity. In some contexts it really is ignored, and in others it has meaning. Where should the minifier strip whitespace and where should it not?

Whitespace matters in selectors, like these three lines:

    div .foo
    div.foo
    div. foo
    
The first two are both valid, but the difference in whitespace causes them to have different results. The third isn't valid and doesn't select anything, but removing the whitespace would accidentally make non-functional CSS functional.

Whitespace also matters around calc() operators:

    calc(500px - (300px + 100px)) /* this works as expected */
    calc(500px - (300px +100px))  /* this doesn't           */
    calc(500px - (300px+100px))   /* neither does this      */

Another example:

    @media screen and (min-width: 1000px)
    @media screen and ( min-width:1000px )
    @media screen and( min-width:1000px )
    
The first two work, the last one doesn't.

Here's where the presence or absence of whitespace is always irrelevant:

* When it abuts another space, beginning or end of file, or any of the four characters listed above for comments.
* When it abuts the colon in a name:value pair inside a media expression or a declaration. Not all colons.
* At the beginning and end of the *contents* of parentheses.

And so that's where YaCSSMin strips whitespace. Everywhere else -- places where the whitespace could be meaningful, like in most parts of a selector -- it just converts contiguous runs of whitespace into single spaces.
