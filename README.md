# YaCSSMin
Yet Another CSS Minifier

## Installation

**Composer**  
In your project directory:

    composer require thomasperi/yacssmin
    
**Manually**  
Download [Minifier.php](https://raw.githubusercontent.com/thomasperi/yacssmin/master/src/Minifier.php) and `require` it in your PHP app:

	require '/path/to/Minifier.php';

## Usage

    use \ThomasPeri\YaCSSMin\Minifier;
    $minified_css = Minifier::minify($css);

### Preserving Comments

The `minify` method also accepts an optional second argument which should be an array of options.

You can selectively preserve comments by writing a callback function for filtering each comment, and passing it in as the `comments` option. This callback should accept a full comment (beginning with `/*` and `*/`) and return a full comment. To preserve a comment as-is, you can just return the string that was passed in:

    // Preserve any comments that contain the string `@license`:
    $minified_css = Minifier::minify($css, [
        'comments' => function ($comment) {
            if (false !== strpos($comment, '@license')) {
                return $comment;
            }
        }
    ]);

If it returns `null` or anything other than a valid CSS comment, the comment will be stripped from the output.
    
### Tokenoids

The other option available is to return the minified CSS as an array of "tokenoids" instead of a string:

    $tokenoids = Minifier::minify($css, ['tokenoids' => true]);

Some of them are real CSS tokens, and some of them are only partial tokens. This can be useful if you want to extend the functionality of YaCSSMin.

## Why?
Why write another CSS Minifier? I had a few goals in mind:

* Don't depend on other libraries.
* Keep the logic readable and maintainable, with minimal regex usage.
* Don't break any valid CSS, even if there's crazy stuff in there.
* Don't "accidentally fix" any broken CSS.
* Do nothing besides minification. No peripheral features like concatenating files and replacing path names. 

For more information, read [PHILOSOPHY.md](https://github.com/thomasperi/yacssmin/blob/master/PHILOSOPHY.md).