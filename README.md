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
    
### Content Filtering

The other option available is to pass in a custom filter to alter the CSS beyond what YaCSSMin does on its own.

YaCSSMin only removes whitespace and comments. It doesn't change any of the values in your CSS, like shortening color codes or rewriting URLs. (For reasons why, see [PHILOSOPHY.md](PHILOSOPHY.md).) So to do things like that, you need the `filter` option:

```php
// Preserve any comments that contain the string `@license`:
$minified_css = Minifier::minify($css, [
    'filter' => function ($css, &$strings, &$comments) {
        // ... do stuff that modifies $css, then return the modified copy ...
        return $css;
    }
]);
```

Here are the arguments that function should accept:

| Argument | Description |
| - | - |
| `$css` | A string containing the minified stylesheet, with every string represented by the placeholder `"_"`, and preserved comments represented by `/*_*/`. |
| `&$strings` | An array of strings (including quotes) corresponding, in order, to the `"_"` placeholders. |
| `&$comments` | An array of comments corresponding, in order, to the `/*_*/` placeholders. |

If you remove a string or a comment from `$css`, be sure to also `unset` or `array_splice` it out of `&$strings` or `&$comments`, to keep them in sync.

There might also be comments (`/**/`) that were only emptied rather than stripped, because of weird placement. These have no counterpart in the `&$comments` array.

#### Example

Here a stub of what rewriting URLs could look like, minus the actual rewriting part.

```php
$minified_css = Minifier::minify($css, [
    'filter' => function ($css, &$strings) {
        $pattern = '#"_"|url\((.+?)\)#i';
        $i = 0;
        $rewrite = function ($url) {
            // ... make your changes to $url here ...
            return $url;
        };
        return preg_replace_callback(
            $pattern,
            function ($matches) use (&$i, &$strings, $rewrite) {
                $match = $matches[0];
                switch (strtolower($match)) {
                    // Skip over strings that aren't URLs, but count them.
                    case '"_"':
                        $i++;
                        break;
                
                    // Found a string that contains a URL. Rewrite the string
                    // in the array, but leave the placeholder unchanged.
                    case 'url("_")':
                        // Separate the URL from the quotes, rewrite the URL,
                        // and put the quotes back on.
                        $string = $strings[$i];
                        $quote = substr($string, 0, 1);
                        $url = substr($string, 1, strlen($string) - 2);
                        $strings[$i] = $quote . $rewrite($url) . $quote;
                        $i++;
                        break;
                    
                    // Unquoted URLs aren't stashed as strings, so do the
                    // rewrite on what was found inside url(...).
                    default:
                        $match = 'url(' . $rewrite($matches[1]) . ')';
                }
                return $match;
            },
            $css
        );
    }
]);
```

## Pronunciation
/ YAX min /
