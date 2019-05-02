# YaCSSMin
Yet Another CSS Minifier

## Warning

I haven't created many test cases yet. Use at your own risk, and please report any issues you have with it.

## Installation

**Composer**  
In your project directory:

    composer require thomasperi/yacssmin
    
**Manually**  
Download [Minifier.php](https://raw.githubusercontent.com/thomasperi/yacssmin/master/src/Minifier.php) and `require` it in your PHP app:

	require '/path/to/Minifier.php';

## Usage

    $minified_css = \ThomasPeri\YaCSSMin\Minifier::minify($css);

That's it. The rest of this readme is philosophical.

## Why?
Why write another CSS Minifier? I had a few goals in mind:

* No dependencies on other libraries.
* Readable and maintainable, using regular expressions only for tokenizing, not for decision-making.
* Don't break any CSS that works, even if there's crazy stuff in there.
* Don't "accidentally fix" any broken CSS.
* Handle corner cases in the simplest way possible, even if it means the output is a few bytes longer than it could be.

## The Two Big Challenges
In CSS, white space and comments are complicated. Half the source code of this minifier deals with how and when to convert them.

### #1: Whitespace

In some contexts whitespace is ignored, and in others it has meaning. Here's an example in which all of the whitespace is completely meaningless and can be stripped away:

    div {
        color: red;
    }
    
Without any of the whitespace it means exactly the same thing:
    
    div{color:red;}

But of course that's not the case with all whitespace. These three selectors differ only in whitespace, but select different elements.

    div .foo    /* An element of class "foo", inside a div */
    div.foo     /* A div of class "foo" */
    div. foo    /* Malformed; doesn't select anything */
    
It's easy to see why the space in the first example shouldn't be stripped. But even the malformed selector should be left how it is, so that the minified stylesheet continues to behave exactly as it did before it was minified. Inadvertently fixing malformed code would allow problems to go unnoticed.

There are other contexts where whitespace matters. For example:

    calc( 100rem - 10px ) /* This works. */
    calc(100rem - 10px)   /* This works. */
    calc(100rem-10px)     /* This doesn't work. */
    calc (100rem - 10px)  /* This doesn't work. */

    @media screen and (max-width: 300px)  /* This works. */
    @media screen and ( max-width:300px ) /* This works. */
    @media screen and(max-width: 300px)   /* This doesn't work. */

Therefore, YaCSSMin only strips whitespace that:

* adjoins any of these characters: `,;{}`,
* adjoins a colon in name:value pairs (but not in selectors),
* follows an opening parenthesis,
* precedes a closing parenthesis, or
* adjoins the beginning or end of the file.

Everywhere else, whitespace is meaningful, and so YaCSSMin only replaces each contiguous run of whitespace with a single space character.

### #2: Comments

Here's what the [W3C Recommendation](https://www.w3.org/TR/CSS21/syndata.html#comments) says about CSS comments:

> Comments begin with the characters "/\*" and end with the characters "\*/". They may occur anywhere outside other tokens, and their contents have no influence on the rendering. Comments may not be nested.

Sounds simple enough, right? But the reality is more complicated. Comments in CSS suffer from a similar ambiguity as whitespace. The precise way in which they "have no influence" depends on the context.

Fortunately the vast majority of comments in real-world stylesheets are adjacent to whitespace (tabs and linebreaks), like this:

    /* 
     * This comment has the beginning of the file before it
     * and a linebreak after it.
     */
    div {
        /* This comment has a tab before it and a linebreak after it */
        color: red;
        
        position: relative;
    }
    
Even most weirdly-placed comments are usually at least adjacent to the whitespace-safe characters `,;{}` and can safely be stripped:
    
    a {color: green/* This comment has a brace after it. */}

But what do we do with corner cases where the behavior of comments is unpredictable? Here are three contrasting examples:

**1.** Comments in calc() expressions seem to be **equivalent to nothing**. Replacing the comment with a space accidentally fixes broken CSS code:

    calc(100rem/* foo */- 10px)  /* Broken */
    calc(100rem - 10px)          /* Accidental Fix */

**2.** Comments between words in @-rules seem to be **equivalent to spaces**. Replacing the comment with nothing breaks working code:

    @media screen/* foo */and (max-width: 300px)  /* This works. */
    @media screenand (max-width: 300px)           /* This breaks it. */

**3.** Comments between words inside a selector are even more nefarious. They **don't act as spaces**, but they **still separate tokens** the way spaces would:

	section/* foo */div     /* Comment:  Broken         */
	section div             /* Space:    Accidental Fix */
	sectiondiv              /* No-space: Stays broken   */
	
	sect/* foo */ion div    /* Comment:  Broken         */
	sect ion div            /* Space:    Stays broken   */
	section p               /* No-space: Accidental Fix */

That means that in order to know whether to strip the comment or replace it with a space inside selectors, we would need to compare the words adjoining a comment with all known HTML tags.

But no. YaCSSMin instead "chooses not to decide" (*a la* Rush) and leaves those comments in place. It just empties them first.

	section/* foo */div     /* Comment:  Broken         */
	section/**/div          /* Empty:    Stays broken   */

	sect/* foo */ion div    /* Comment:  Broken         */
	sect/**/ion div         /* Empty:    Stays broken   */

Simple, effective, and since no human being would ever actually write CSS like this, we don't need to worry about the three or four extra bytes we could have saved by deciding whether to replace it with a space or to strip it.

## Other Features (and Non-Features)

Here's some other stuff it removes:

* Empty blocks (and whatever rule the block applies to)
* Unnecessary semicolons

Stuff it *doesn't* do yet:

* Shorten color names and hex codes
* 

And stuff it will probably never do:

* Remove units from zeroes

I'm reluctant to remove units from zeroes. Even though there is a finite number of places where units are necessary on zeroes (`calc()` expressions), there are also many places where units should never be used, such as `z-index`, `opacity`, and a lot of `flex` properties. Removing units from zeroes means accidentally fixing bad CSS that has units where they shouldn't be. Once you work in shorthand values, the list of places not to remove units from gets long and complicated.