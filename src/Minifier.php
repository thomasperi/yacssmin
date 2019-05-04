<?php
namespace ThomasPeri\YaCSSMin;

/**
 * Yet Another CSS Minifier
 * @author Thomas Peri <tjperi@gmail.com>
 * @license MIT
 */
class Minifier {
	/**
	 * Minify some CSS code.
	 * @param string $css The CSS code to minify.
	 * @returns string
	 */
	static function minify($css) {
		$min = new self();
		
		// Wrap it in spaces so we don't have to check for the
		// beginning and end of the file.
		$tokens = $min->tokenize(' ' . $css . ' '); 
		$tokens = $min->blocks($tokens);
		$tokens = $min->spaces($tokens);
		
		return trim(implode('', $tokens));
	}
	
	private static $partials = [
		// Contiguous whitespace becomes a single space.
		'\s+',
	
		// The rules for whether a comment is considered nothing or
		// whitespace are complicated, so for the first pass, just
		// convert all comments to empty comments.
		'(/\*.*?\*/)+',
		
		// Quoted strings stay as they are.
		'\'(\\\\?.)*?\'',
		'\"(\\\\?.)*?\"',

		// Multiple semicolons collapse to a single semicolon.
		'(;\s*)+',
		
		// Operators in attribute selectors like [class^="foo""]
		'[~^|*$]?=',
		
		// Any word that precedes an open parentheses,
		// optionally with a colon to match pseudo-classes.
		':?[\w-]+(?=\()',

		// Isolate a few other characters individually. Keep this one
		// last so that it doesn't supercede other patterns that begin
		// with these characters.
		'[~^|*$>+\-,:(){}[\]]',
	];
	
	// Parse the CSS file into an array of tokens.
	private function tokenize($css) {
		$all = '#(' . implode(')|(', self::$partials) . ')#s';
		
		$tokens = [];
		$len = strlen($css);
		$offset = 0;
		$start = 0;

		do {
			$found = preg_match($all, $css, $matches, PREG_OFFSET_CAPTURE, $offset);
			if ($found) {
				$match = $matches[0][0];
				$start = $matches[0][1];
			} else {
				$start = $len;
			}
			
			// Add unmatched stuff.
			if ($start > $offset) {
				$tokens[] = substr($css, $offset, $start - $offset);
			}
			
			// Add matched stuff.
			if ($found) {
				$char = substr($match, 0, 1);
				$token = $match;
				switch ($char) {
					case ';':
						$token = ';';
						break;
					case '/':
						if (substr($match, 1, 1) === '*') {
							$token = '/**/';
						}
						break;
					default:
						if (preg_match('#\s#', $char)) {
							$token = ' ';
						}
				}
				$tokens[] = $token;
			}
			
			// Move the offset to the end of the match.
			$offset = $start + strlen($match);
			
		} while ($found);
		
		return $tokens;
	}
	
	// Trace backwards through the input, looking for closing braces
	// in order to remove empty rulesets and trailing semicolons.
	private function blocks(&$input) {
		$output = [];
		while ($input) {
			$token = array_pop($input);
			if ($token === '}') {
				$this->blocks_tail($input, $output);
			} else {
				$output[] = $token;
			}
		}
		return array_reverse($output);
	}
	
	// Recursive helper method for blocks().
	private function blocks_tail(&$input, &$output) {
		// When found, pop off semicolons, spaces, and comments
		// until we reach something else.
		while ($input) {
			$token = array_pop($input);
			switch ($token) {
				case ';':
				case ' ':
				case '/**/':
					break;

				// If that something else is a closing brace,
				// it's a nested block. process that one recursively
				// before continuing with this one, 'cause it might be
				// empty too.
				case '}':
					$this->blocks_tail($input, $output);
					break;

				// If that something else is an opening brace,
				// this block is empty, so don't push any output.
				case '{':
					// Pop things off until we find a semicolon or an open or
					// close brace. (Don't pop whatever is found.)
					while ($input) {
						switch (end($input)) {
							case ';':
							case '{':
							case '}':
								break 2;
							default:
								array_pop($input);
						}
					}
					// And since the block was empty, we're done
					// optimizing the tail end of it, so return.
					return;

				// If anything else is found, we're done optimizing the
				// tail end of this block. Push the closing brace, then
				// put back whatever token was popped off the input.
				default:
					$output[] = '}';
					$input[] = $token;
					return;
			}
		}
	}
	
	// Decide where to strip spaces.
	private function spaces(&$input) {
		$input = array_reverse($input);
		$output = [];
		
		// Keep track of some contexts we might be inside of.
		$inside = [];
		
		while ($input) {
			$token = array_pop($input);
			
			// First track what delimiters we're currently inside.
			switch ($token) {
				case '(':
					$expr = strtolower(end($output));
					// We never go looking for end($inside) to be
					// `matches` specifically, but we need it to be
					// something other than `nth` or `(` which we do
					// compare for.
					if ($expr === ':matches') {
						$inside[] = 'matches';
					} else if (substr($expr, 0, 5) === ':nth-') {
						$inside[] = 'nth';
					} else {
						$inside[] = '(';
					}
					break;
				case '[':
				case '{':
					$inside[] = $token;
					break;
				
				case '}':
				case ']':
				case ')':
					array_pop($inside); // Don't bother matching the opener.
			}
			
			// Then decide what to do around the current character.
			switch ($token) {
				// Strip whitespace to the right of these.
				case '[':
				case '(':
					$this->strip($input);
					break;
				
				// Strip whitespace to the left of these.
				case ']':
				case ')':
					$this->strip($output);
					break;
				
				// Strip whitespace around these
				case ' ':
				case ';';
				case '{';
				case '}';
				case ',';
				case '>':
				case '~=':
				case '^=':
				case '|=':
				case '*=':
				case '$=':
					$this->strip($input);
					$this->strip($output);
					break;

				// Don't fix malformed equality operators inside brackets.
				case '~':
					// Always strip spaces from the left of a tilde,
					// but only strip them from the right if it's
					// outside of brackets or the next closing bracket
					// is closer than the next equals.
					$this->strip($output);
					if (
						end($inside) !== '[' || 
						$this->nearest($input, ']=') === ']'
					) {
						$this->strip($input);
					}
					break;
				case '^':
				case '|':
				case '*':
				case '$':
					if (end($inside) === '[') {
						$this->strip($output);
					}
					break;
				case '=':
					// Always strip spaces from the right of an equals,
					// but only strip them from the left if it's
					// outside of brackets or the previous opening
					// bracket is closer than the previous character
					// that might be part of a malformed operator.
					$this->strip($input);
					if (
						end($inside) !== '[' ||
						$this->nearest($output, '[~^|*$') === '['
					) {
						$this->strip($output);
					}
					break;

				// Strip whitespace around `+` tokens but only inside
				// selectors.
				case '+':
					if ($this->nearest($input, '{};') === '{') {
						$this->strip($input);
						$this->strip($output);
					}
					break;
				
				// Strip whitespace around `-` tokens only inside
				// :nth-X() pseudo-classes
				case '-':
					if (end($inside) === 'nth') {
						$this->strip($input);
						$this->strip($output);
					}
					break;
				
				// Strip whitespace around colons if inside parentheses
				// -- i.e. a media expression, not including :matches
				// which gets registered as 'matches' instead of `(` --
				// or if we're not inside a selector, which we test for
				// by checking whether an open brace is coming sooner
				// than a closing brace or semicolon.
				case ':':
					if (
						end($inside) === '(' ||
						$this->nearest($input, '{};') !== '{'
					) {
						$this->strip($input);
						$this->strip($output);
					}
					break;
			}
			$output[] = $token;
		}
		return $output;
	}
	
	// Strip whitespace and comments from the end of the input or output.
	private function strip(&$array) {
		$tokens = [' ', '/**/'];
		while (in_array(end($array), $tokens)) {
			array_pop($array);
		}
	}
	
	// The character from $needles that appears closest to the end of $haystack.
	private function nearest(&$haystack, $needles) {
		$needles = str_split($needles);
		for ($i = count($haystack) - 1; $i >= 0; $i--) {
			// This looks backwards because in_array's arguments are
			// ($needle, $haystack), but we're looking for a specific
			// *member* of $haystack in the *array* of $needles to find
			// which of those needles appears first in $haystack.
			if (in_array($haystack[$i], $needles)) {
				return $haystack[$i];
			}
		}
		return false;
	}
}
