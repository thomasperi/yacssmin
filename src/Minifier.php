<?php
namespace ThomasPeri\YaCSSMin;

/**
 * Yet Another CSS Minifier
 * @author Thomas Peri <tjperi@gmail.com>
 * @version 0.7.0
 * @license MIT
 */
class Minifier {
	/**
	 * Minify some CSS code.
	 * @param string $css The CSS code to minify.
	 * @returns string
	 */
	static function minify($css, $options = []) {
		$min = new self();
		$options = array_merge([
			'filter' => false,
			'comments' => false,
		], $options);

		// If the comments option has a value that's not callable, flag it so.
		$comment_filter = $options['comments'];
		if (!is_callable($comment_filter)) {
			$comment_filter = false;
		}

		// Add spaces at the beginning and end so we don't have to check for 
		// the beginning and end of the file.
		$css = ' ' . $css . ' ';
		
		// Stash strings and comments to be repopulated later, and do some
		// basic validation.
		$css = $min->stash_strings_and_comments($css, $comment_filter);
		if (false === $css || !$min->balance_delimiters($css)) {
			return false;
		}

		// Do all the minification steps.
		$css = $min->strip_easy_spaces($css);
		$css = $min->remove_empty_blocks($css);
		$css = $min->collapse_semicolons($css);
		$css = $min->do_contextual_replacements($css);
		
		// Pass through the user filter if callable.
		$filter = $options['filter'];
		if (is_callable($filter)) {
			$css = $filter($css, $min->strings, $min->$comments);
		}
		
		// Re-populate the stashed strings and comments.
		$css = $min->unstash_strings_and_comments($css);

		// Remove any leading or trailing spaces.
		$css = trim($css);
		
		return $css;
	}
	
	// All the comments and strings found in the CSS code.
	private $comments = [], $strings = [], $c = 0, $s = 0;
	
	/*
	 * Stash comments and strings for later.
	 */
	private function stash_strings_and_comments($css, $filter) {
		// Convert Windows linebreaks so that we don't have to deal with the
		// possibility of a backslash that escapes the next two characters
		// instead of just one.
		$css = preg_replace('#\r\n#s', "\n", $css);
		
		// Replace strings and comments with placeholders.
		
		// A string is a single or double quote, then any number, non-greedily,
		// of any character optionally preceded by a backslash, followed by
		// either (a) the same quote we started with, (b) a newline, or (c)
		// the end of the file.
		$string = '([\'"])(?:\\\\?.)*?(\\1|\n|$)';
		
		// A comment is a slash and a star, followed by any number of any
		// character non-greedily, followed by a star and a slash.
		$comment = '/\*.*?\*/';
		
		// Build a pattern to match either string or comment.
		$either = '#' . $string . '|' . $comment . '#s';
		
		// Build a pattern to test whether something's a comment.
		$pattern = '#^' . $comment . '$#s';
		
		// Allow $this access inside anonymous function.
		$me = $this;
		$success = true;
		$css = preg_replace_callback($either, function ($matches) use ($me, $filter, $pattern, &$success) {
			$match = $matches[0];
			switch (substr($match, 0, 1)) {
				// Strings
				case '"':
				case "'":
					// If the string was terminated by anything other than the
					// same quote that opened it (newline or end of file), 
					// that's a problem.
					if ($matches[1] !== $matches[2]) {
						$success = false;
					}
					// Regardless, for now, stash it like a valid string and
					// temporarily replace it with an underscored string.
					$me->strings[] = $match;
					return '"_"';
				
				// Comments
				case '/':
					// If there's a callable comment filter...
					if ($filter &&
						// ...and it returns something truthy...
						($comment = $filter($match)) &&
						// ...and that thing is a string...
						is_string($comment) &&
						// ...and it matches the pattern for a comment...
						preg_match($pattern, $comment)
					) {
						// ...then stash it and mark it as stashed with an underscore.
						$me->comments[] = $comment;
						return '/*_*/';
					}
					// Otherwise, just replace it with an empty comment.
					return '/**/';
			}
		}, $css);
		
		if (!$success) {
			return false;
		}
		return $css;
	}
	
	/*
	 * Ensure that nested delimiters are balanced.
	 */
	private function balance_delimiters($css) {
		$inside = [];
		$success = true;
		preg_replace_callback('#[{}()[\]]#', function ($matches) use (&$inside, &$success) {
			$char = $matches[0];
			switch ($char) {
				case '{':
					$inside[] = '}';
					break;
				case '(':
					$inside[] = ')';
					break;
				case '[':
					$inside[] = ']';
					break;
				default:
					if ($char !== array_pop($inside)) {
						$success = false;
					}
			}
			return $char;
		}, $css);

		// It's only valid if they were nested properly,
		// AND there's nothing left over waiting to be balanced.
		return $success && !$inside;
	}

	/*
	 * Strip the spaces from spots where they never matter.
	 */
	private function strip_easy_spaces($css) {
		// Strip whitespace and empty comments around other whitespace,
		// commas, braces, and semicolons.
		$css = $this->strip($css, '[\s,;{}]', true, true);
		
		// Strip whitespace immediately inside parentheses and brackets.
		$css = $this->strip($css, '[(\[]', false, true);
		$css = $this->strip($css, '[)\]]', true, false);
		
		// Replace any remaining runs of whitespace with a single space.
		$css = preg_replace('#\s+#s', ' ', $css);
		
		return $css;
	}
	
	/*
	 * Remove empty blocks.
	 */
	private function remove_empty_blocks($css) {
		// To account for nested empty blocks, remove empty blocks until the
		// removal results in no change.
		$short = $css;
		do {
			$css = $short;
			// Match an empty block along with whatever came before it that's
			// not the end of something else or the beginning of an outer block.
			$short = preg_replace_callback('#[^;{}]+\{\}#s', function ($matches) {
				// Don't remove blocks that have preserved comments in the rule
				// before the block.
				$chunk = $matches[0];
				if (preg_match('#/\*_\*/#', $chunk)) {
					return $chunk;
				}
				return '';
			}, $css);
		} while ($short !== $css);
		return $css;
	}
	
	/*
	 * Remove unnecessary semicolons.
	 */
	private function collapse_semicolons($css) {
		// Replace any runs of semicolons with a single semicolon.
		$css = preg_replace('#;+#', ';', $css);
		
		// Strip semicolons before closing braces.
		$css = preg_replace('#;\}#', '}', $css);

		return $css;
	}
	
	/*
	 * Strip spaces and do other replacements that can only happen in certain
	 * contexts.
	 */
	private function do_contextual_replacements($css) {
		$me = $this;

		// In declarations and @-rules, strip spaces around colons, stars, and slashes.
		$half = '[^:;{}]+';
		$declaration = '(?:(?<=[;{}])' . $half . ':' . $half . '(?=[;}]))';
		$at_rule = '@.+?(?=\{)';
		$either = '#' . $declaration . '|' . $at_rule . '#s';
		$css = preg_replace_callback($either, function ($matches) use ($me) {
			return $me->strip($matches[0], '[:*/]', true, true);
		}, $css);
		
		// Stash attribute selectors
		$attributes = [];
		$css = preg_replace_callback('#\[.+?\]#s', function ($matches) use ($me, &$attributes) {
			$att = $matches[0];
			// Also strip spaces from around operators: ~= ^= |= *= $=
			// but leave space inside malformed ones untouched: ~ =
			$att = $me->strip($att, '[~^|*$]\s*=', true, true);
			
			// Strip spaces from both sides of equals signs that are preceded
			// by a word character.
			$att = preg_replace('#(?<=\w)\s*=\s*#s', '=', $att);
			
			$attributes[] = $att;
			return '[_]';

			// Note: Identifiers get unquoted during unstashing.
		}, $css);

		// Inside selectors, strip spaces around <+~
		// Match selectors by looking back to the last end of something -- a
		// semicolon, open, or closing brace -- and finding everything that
		// isn't an @, non-greedy, until the next character is a brace.
		$css = preg_replace_callback('#(?<=[;{}]|^)[^@]+?(?=\{)#s', function ($matches) use ($me) {
			return $me->strip($matches[0], '[>+~]', true, true);
		}, $css);
		
		// Un-stash attribute selectors
		$i = 0;
		$css = preg_replace_callback('#\[_\]#', function ($matches) use (&$attributes, &$i) {
			return $attributes[$i++];
		}, $css);
		
		// Remove spaces around + and - inside nth-X()
		$css = preg_replace_callback('#(:nth-[\w-]+\(-?)(.+?)(\)|of)#s', function ($matches) use ($me) {
			return $matches[1] . $me->strip($matches[2], '[+-]', true, true) . $matches[3];
		}, $css);
		
		return $css;
	}
	
	/*
	 * Replace colors with shorter values for the same color.
	 */
	private function shorten_colors($name, $value) {
		return preg_replace_callback('/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', function ($matches) {
			return '#' . strtolower($matches[1] . $matches[2] . $matches[3]);
		}, $value);
	}
	
	/*
	 * Repopulate strings and preserved comments.
	 */
	private function unstash_strings_and_comments($css) {
		// Optionally match an equals before the string,
		// for unquoting identifiers.
		$string = '=?"_"';
		
		// Safe characters before and/or after a preserved comment mean
		// it can have line breaks.
		$safe = '[ ,;{}]?';
		$comment = '(' . $safe . ')(/\*_\*/)(' . $safe . ')';

		// Build a pattern that matches comments, strings, and declarations.
		$either = '#' . $comment . '|' . $string . '#s';
		
		$me = $this;
		$css = preg_replace_callback($either, function ($matches) use ($me, $comment, $filter) {
			$full = $matches[0];
			// Strip everything except the index number.
			switch (substr($full, 0, 1)) {
				// Unquote identifiers after equals.
				case '=':
					$str = $me->strings[$me->s++];
					$content = substr($str, 1, strlen($str) - 2);
					if (preg_match('#^[_a-z][-0-9_a-z]*$#i', $content)) {
						$str = $content;
					}
					return '=' . $str;
				
				// Other strings get returned as they are.
				case '"':
					return $me->strings[$me->s++];
					
				// Comments 
				default:
					// Do some minimal formatting. Strip whitespace from the
					// front of each line, then put back a single space on lines
					// that begin with a star.
					$restore = $me->comments[$me->c++];
					$restore = preg_replace('#[\r\n]\s*#s', "\n", $restore);
					$restore = preg_replace('#\n\*#s', "\n *", $restore);

					// Insert line breaks around comments that adjoin
					// whitespace-safe characters.
					$before = $matches[1];
					$after = $matches[3];
					if ($before || $after) {
						$before = trim($before) . "\n";
						$after = "\n" . trim($after);
					}
					return $before . $restore . $after;
			}
		}, $css);
		
		return $css;
	}

	/*
	 * Strip empty comments and spaces adjoining the provided character class,
	 * to the left, the right, or both.
	 */
	private function strip($css, $chars, $left, $right) {
		$strip = '(?:\s|/\*\*/)';
		$strip_one = $strip . '+';
		$strip_both = $strip . '*';
		if ($left && $right) {
			$css = preg_replace('#' . $strip_both . '(' . $chars . ')' . $strip_both . '#s', '\\1', $css);
		} else if ($left) {
			$css = preg_replace('#' . $strip_one . '(' . $chars . ')#s', '\\1', $css);
		} else if ($right) {
			$css = preg_replace('#(' . $chars . ')' . $strip_one . '#s', '\\1', $css);
		}
		return $css;
	}
	
}
