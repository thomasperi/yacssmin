# YaCSSMin
Yet Another CSS Minifier

## Why?
Why write another CSS Minifier? Read [PHILOSOPHY.md](PHILOSOPHY.md).

## Installation

**Composer**  
In your project directory:

```
composer require thomasperi/yacssmin
```
    
**Manually**  
Download [Minifier.php](https://raw.githubusercontent.com/thomasperi/yacssmin/master/src/Minifier.php) and `require` it in your PHP app:

```php
require '/path/to/Minifier.php';
```

## Usage

```php
use \ThomasPeri\YaCSSMin\Minifier;
$minified_css = Minifier::minify($css);
```

### Return Value

The `minify` method returns a minified string when it successfully minifies a stylesheet. If it returns `false`, it means that the CSS code contained an un-balanced brace, bracket, parenthesis, comment, or string; or an unescaped newline inside a string. To distinguish this from the empty string, use strict comparison:

```php
$minified_css = Minifier::minify($css);
if (false === $minified_css) {
    // Error
} else {
    // Success
}
```

### Preserving Comments

The `minify` method also accepts an optional second argument which should be an array of options.

You can selectively preserve comments by writing a callback function for filtering each comment, and passing it in as the `comments` option. This callback should accept a full comment (beginning with `/*` and ending with `*/`) and return a full comment. To preserve a comment as-is, you can just return the string that was passed in:

```php
// Preserve any comments that contain the string `@license`:
$minified_css = Minifier::minify($css, [
    'comments' => function ($comment) {
        if (false !== strpos($comment, '@license')) {
            return $comment;
        }
    }
]);
```

If it returns `null` or anything other than a valid CSS comment, the comment will be stripped from the output.
    
### Tokenoids

The other option available is to return the minified CSS as an array of "tokenoids" instead of a string:

```php
$tokenoids = Minifier::minify($css, ['tokenoids' => true]);
```

Some of them are real CSS tokens, and some of them are only partial tokens. This can be useful if you want to extend the functionality of YaCSSMin.

## Pronunciation
/ YAX min /
