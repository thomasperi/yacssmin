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

	// Parse the CSS file into an array of tokens.
	private function tokenize($css) {
		// Split the CSS file along word boundaries.
		$boundaries = "#(?<=\W)|(?=\W)#s";
		$dashed_word = '#^[\w-]#';
		$split = preg_split($boundaries, $css);
		
		// Now it's split up into words and not-words,
		// but some of those tokens need to be re-combined.
		$tokens = [];
		$count = count($split);
		for ($i = 0; $i < $count; $i++) {
			$token = $split[$i];
			switch ($token) {
				// Follow comments to the next `*/`
				case '/':
					if ($i < $count - 1 && '*' === $split[$i + 1]) {
						$i++;
						while ($i < $count - 2 && (
							'*' !== $split[++$i] ||
							'/' !== $split[++$i]
						));
						$token = '/**/';
					}
					break;
				
				// Follow strings to the next matching quote,
				// ignoring the character after each backslash.
				case '"':
				case "'":
					$start = $i;
					$quote = $token;
					while ($i < $count - 1) {
						$i++;
						$tok = $split[$i];
						switch ($tok) {
							case '\\':
								$i++;
								break;
							case $quote:
								break 2;
						}
					}
					$token = implode('', array_slice($split, $start, $i + 1 - $start));
					break;
				
				// Replace runs of multiple semicolons and whitespace
				// with a single semicolon.
				case ';':
					while ($i < $count - 1 && (
						'' === trim($split[$i + 1]) ||
						';' === $split[$i + 1]
					)) {
						$i++;
					}
					break;
				
				// Combine a colon with a word immediately following it
				// if the word is followed immediately by an open parenthesis.
				case ':':
					$start = $i;
					while ($i < $count - 1 &&
						preg_match($dashed_word, $split[$i + 1])
					) {
						$i++;
					}
					if ($i < $count - 1 && '(' === $split[$i + 1]) {
						$token = implode('', array_slice($split, $start, $i + 1 - $start));
					} else {
						$i = $start;
					}
					break;
				
				// Replace runs of whitespace with a single space.
				default:
					if ('' === trim($token)) {
						$token = ' ';
						while ($i < $count && '' === trim($split[$i + 1])) {
							$i++;
						}
					}
			}
			
			// Whatever $token has been possibly overwritten with,
			// add it to the array of tokens.
			$tokens[] = $token;
		}
		
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
