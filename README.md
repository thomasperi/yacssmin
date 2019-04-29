# YaCSSMin
Yet Another CSS Minifier

## Goals
Why write another CSS Minifier? I had a few goals in mind:

* No library dependencies.
* Small codebase. (~300 lines, 8 KB)
* Don't accidentally fix broken CSS. It's a source of confusion.
* Don't break valid CSS even on weird corner cases.
* Strike a balance between handling the weird corner cases and keeping things simple.

## Two Big Questions
CSS comments and white space are weird.

### #1: Comments

Comments in CSS are ignored, sure. But in some places in a stylesheet they're treated like a space, and in other places they're treated as literally nothing. So how do we go about writing rules to match the behavior.

A comment is treated like true nothingness when it abuts a space or one of these characters:

    :;{}
			
And here's where a comment *sometimes* acts like whitespace and other times like nothing:

* Inside parentheses (inconsistent between browsers)
* Inside selectors (depending on *where* inside the selector)
* Maybe other places. Who knows?

The good news is that the places where comments *don't* act like whitespace is where you'd normally put comments. So YaCSSMin strips those, and replaces comments in the weird places with empty comments, like this:

    /**/

That way we don't have to worry about whether a comment should be stripped or replaced with a space. They just stay comments where it's ambiguous, and that only happens in places where you shouldn't be putting comments anyway.

### #2: Whitespace

Some whitespace in CSS is really ignored, and other whitespace has meaning. So, we're in a similar dilemma. Where do we strip them and where do we not?

Whitespace matters in selectors, like these three lines:

    div .foo
    div.foo
    div. foo
    
The first two are both valid, but the difference in whitespace causes them to have different results. If we stripped the whitespace from the first, it would become the second and not be what the author intended.

The third isn't valid and doesn't select anything. But remember the goals? The minifier shouldn't "accidentally fix" any broken CSS, and if it removed whitespace in this example it would be doing just that.

Whitespace also matters around calc() operators:

    calc(500px - (300px + 100px)) /* this works as expected */
    calc(500px - (300px +100px))  /* this doesn't           */
    calc(500px - (300px+100px))   /* neither does this      */

Another example:

    @media screen and (min-width: 1000px)
    @media screen and ( min-width:1000px )
    @media screen and( min-width:1000px )
    
The first two work, the last one doesn't.

Here's where the presence or absence of whitespace is irrelevant:

* When it abuts a space or any of the four characters listed for comments.
* When it abuts a colon in a declaration's (or media expression's) name:value pair.
* At the beginning and end of what's inside parentheses.

And so that's where YaCSSMin strips whitespace. Everywhere else -- where the whitespace could be meaningful, like in most parts of a selector -- it just converts contiguous runs of white into single spaces.