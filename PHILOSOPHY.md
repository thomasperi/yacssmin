# YaCSSMin Philosophy
The ideas that went into YaCSSMin

## Why?
Why write another CSS Minifier? I had a few goals in mind:

* **No library dependencies.**
* **No change in behavior** of minified CSS files. Valid CSS shouldn't break even if it's crazy, and *invalid* CSS shouldn't accidentally start working after it's minified.
* **No bloat.** Just minify CSS code, with no peripheral features like concatenating files and replacing path names. Those things can be done separately.
* **Readable and maintainable** source code. Use regular expressions for tokenizing, not decision-making.

## The Big Two
White space and comments are the two biggest parts of the CSS file that should be removed during minification. And in CSS, white space and comments are complicated.

### #1: Whitespace

In some contexts whitespace is ignored, and in others it has meaning. Here's an example in which all of the whitespace is completely meaningless and can be stripped away:

```css
div {
    color: red;
}
```
    
Without any of the whitespace it means exactly the same thing:

```css
div{color:red;}
```

But of course that's not the case with all whitespace. These three selectors differ only in whitespace, but select different elements.

```css
div .foo    /* An element of class "foo", inside a div */
div.foo     /* A div of class "foo" */
div. foo    /* Malformed; doesn't select anything */
```
    
It's easy to see why the space in the first example shouldn't be stripped. But even the malformed selector should be left how it is, so that the minified stylesheet continues to behave exactly as it did before it was minified. Inadvertently fixing malformed code would allow problems to go unnoticed.

There are other contexts where whitespace matters. For example:

```css
calc( 100rem - 10px ) /* This works. */
calc(100rem - 10px)   /* This works. */
calc(100rem-10px)     /* This doesn't work. */
calc (100rem - 10px)  /* This doesn't work. */

@media screen and (max-width: 300px)  /* This works. */
@media screen and ( max-width:300px ) /* This works. */
@media screen and(max-width: 300px)   /* This doesn't work. */
```

Therefore, YaCSSMin strips whitespace where it doesn't change the meaning of the code:

* On both sides of `:` except in selectors,
* To the right of `([`,
* To the left of  `)]`,
* On both sides of `;{},>`,
* On both sides of `+` except inside `calc()`,
* Inside `:nth-___()` expressions,
* To the left and/or right of `~^|*$=`, except when stripping whitespace would accidentally fix a malformed operator in an attribute selector.

For example, `[class * = foo]` becoming `[class*=foo]` would accidentally fix the broken `*=` operator. Therefore YaCSSMin only reduces it to `[class* =foo]` which continues to not work.)

Everywhere else, YaCSSMin replaces each contiguous run of whitespace with a single space character.

### #2: Comments

Here's what the [W3C Recommendation](https://www.w3.org/TR/CSS21/syndata.html#comments) says about CSS comments:

> Comments begin with the characters "/\*" and end with the characters "\*/". They may occur anywhere outside other tokens, and their contents have no influence on the rendering. Comments may not be nested.

Sounds simple enough, right? But the reality is more complicated. Comments in CSS suffer from an even worse ambiguity than whitespace. The precise **way** in which they "have no influence" depends on the context.

Fortunately the vast majority of comments in real-world stylesheets are adjacent to whitespace (tabs and linebreaks), like this:

```css
/* 
 * This comment has the beginning of the file before it
 * and a linebreak after it.
 */
div {
    /* This comment has a tab before it and a linebreak after it */
    color: red;
}
```
    
Even most weirdly-placed comments are usually at least adjacent to the whitespace-safe characters listed above and can safely be stripped in those contexts:
    
```css
a {color: green/* This comment has a brace after it. */}
```

A super-weird example:

```css
div+/* This comment is next to a + outside calc. */p { color: red; }
```

But what do we do with corner cases where the behavior of comments is unpredictable? Here are three contrasting examples:

**1.** Comments inside calc() expressions seem to be **equivalent to nothing**. Replacing the comment with a space accidentally fixes broken CSS code:

```css
calc(100rem/* foo */- 10px)  /* Broken */
calc(100rem - 10px)          /* Accidental Fix */
```

**2.** Comments between words in @-rules seem to be **equivalent to spaces**. Replacing the comment with nothing breaks working code:

```css
@media screen/* foo */and (max-width: 300px)  /* This works. */
@media screenand (max-width: 300px)           /* This breaks it. */
```

**3.** Comments between words inside a selector are even more nefarious. They **don't act as spaces**, but they **still separate tokens** the way spaces would:

```css
section/* foo */div     /* Comment:  Broken         */
section div             /* Space:    Accidental Fix */
sectiondiv              /* No-space: Stays broken   */

sect/* foo */ion div    /* Comment:  Broken         */
sect ion div            /* Space:    Stays broken   */
section p               /* No-space: Accidental Fix */
```

That means that in order to know whether to strip the comment or replace it with a space inside selectors, we would need to compare the words adjoining a comment with all known HTML tags.

But no. YaCSSMin instead "chooses not to decide" (*a la* Rush) and leaves those comments in place. It just converts them to **empty** comments.

```css
section/* foo */div     /* Comment:  Broken         */
section/**/div          /* Empty:    Stays broken   */

sect/* foo */ion div    /* Comment:  Broken         */
sect/**/ion div         /* Empty:    Stays broken   */
```

Simple, effective, and since no human being would ever actually write CSS like this, we don't need to worry about the three or four extra bytes we could have saved by deciding whether to replace it with a space or to strip it.

## Other Features (and Non-Features)

Here's some other stuff YaCSSMin does:

* Removes empty blocks (and whatever rule or selector the block applies to)
* Removes unnecessary semicolons
* Converts six-digit hex color codes to three-digit where appropriate

Stuff it might do in the future:

* Convert rgb() and hsl() colors to hex codes

And stuff it will *never* do:

* Bring images inline as data urls
* Concatenate CSS files
* Remove units from zeroes

Inlining images and concatenating stylesheets can be done separately using whatever mechanism you like. These are separate tasks from minifying, so there's no reason for YaCSSMin to do them.

The reason YaCSSMin will never remove units from zeroes is that there's a high risk of accidentally fixing bad CSS that has units where they shouldn't be. Even though there is a finite number of places where units are *necessary* on zeroes (`calc()` expressions), there are also many places where units should *never* be used, such as `z-index`, `opacity`, and a lot of `flex` properties. Once you consider shorthand values, the logic for removing units only in the right contexts gets long and complicated.
