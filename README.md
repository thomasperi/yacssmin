# YaCSSMin
Yet Another CSS Minifier

## Usage

    $minified_css = \ThomasPeri\YaCSSMin\Minifier::minify($css);

## Why?
Why write another CSS Minifier? Looking through the known issues with other minifiers, I had a few goals in mind:

* No dependencies on other libraries.
* Readable and maintainable, using regular expressions only for tokenizing, not for decision-making.
* Don't break any CSS that works, even if there's crazy stuff in there.
* Don't "accidentally fix" any broken CSS.
* Handle corner cases in the simplest way possible, even if it means the output is a few bytes longer than it could be.

## The Two Big Challenges
CSS comments and white space are complicated. Half the source code of this minifier deals with how and when to convert them.

### #1: Comments

Comments in CSS are "ignored," sure. But exactly what that means depends on the context. In some parts of a stylesheet they're equivalent to nothing-at-all. These two selectors are equivalent:

    div.bar
    div/* foo */.bar
    
In other contexts, a comment is equivalent to whitespace. These two media queries are equivalent:

    @media screen and (min-width: 500px)
    @media screen/* foo */and (min-width: 500px)

How do we go about writing rules to match the behavior?

Fortunately, the vast majority of comments you'll actually write aren't going to be inside selectors or media queries, so the minifier doesn't need to optimize for those. It just needs to make sure they keep working the way they do.

Furthermore, when a comment is where you'd normally put one -- before a selector, or before a declaration -- its context is easy to test for: It will be adjacent to a space (the beginning or end of the file counts as a space) and/or any of these characters:

    ,;{}

So that's all YaCSSMin tests for. Comments that meet those criteria get stripped. Comments in other contexts get replaced with *empty* comments, so that as far as a web browser is concerned, it's still a comment. The minifier doesn't have to worry about whether to remove it or replace it with whitespace.

    div/* foo */.bar
    --- becomes ---
    div/**/.bar

    @media screen/* foo */and (min-width: 500px)
    --- becomes ---
    @media screen/**/and (min-width:500px)

### #2: Whitespace

Whitespace in CSS suffers a similar ambiguity. In some contexts it really is ignored, and in others it has meaning. Where should the minifier strip whitespace and where should it not?

An example in which all of the whitespace can be stripped:

    .foo {
        color: red;
    }
    
The above has the exact same meaning as the below:
    
    .foo{color:red;}

It *does* matter in selectors, like these three lines:

    div :hover
    div:hover
    div: hover
    
The first two are both valid, but the difference in whitespace is important. Changing the whitespace changes the behavior. The third is a typo and doesn't do anything, but removing the whitespace would accidentally make it do something.

Whitespace also matters around `calc()` operators like `+` and `-`:

    calc(500px - (300px + 100px)) /* this works as expected */
    calc(500px - (300px +100px))  /* this doesn't           */
    calc(500px - (300px+100px))   /* neither does this      */

Another example, with a media query:

    @media screen and (min-width: 1000px)  /* works        */
    @media screen and ( min-width:1000px ) /* works        */
    @media screen and( min-width:1000px )  /* doesn't work */
    @media screen and(min-width: 1000px)   /* doesn't work */
    
So here's where YaCSSMin strips whitespace:

* When it abuts another space, the beginning or end of file, or any of the four characters listed above for comments.
* When it abuts a colon in a name:value pair. (But not in selectors, where space around colons is meaningful.)
* After an opening parenthesis or before a closing one.

Everywhere else -- places where the whitespace might be meaningful -- it just converts contiguous runs of whitespace into single spaces.

## Other Features (and Non-Features)

Here's some other stuff it removes:

* Empty blocks (and whatever rule the block applies to)
* Unnecessary semicolons

Stuff it *doesn't* do yet:

* Shorten color names and hex codes

And stuff it will probably never do:

* Remove units from zeroes

I'm reluctant to remove units from zeroes. Even though there is a finite number of places where units are necessary on zeroes (`calc()` expressions), there are also many places where units should never be used, such as `z-index`, `opacity`, and a lot of `flex` properties. Removing units from zeroes means accidentally fixing bad CSS that has units where they shouldn't be. Once you work in shorthand values, the list of places not to remove units from gets long and complicated.