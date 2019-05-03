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
	
	// Some regex patterns for tokenizing.
	// Strings represent replacements, true means use the match.
	private static $patterns = [
		// Contiguous whitespace becomes a single space.
		'#\s+#s' => ' ',
	
		// The rules for whether a comment is considered nothing or
		// whitespace are complicated, so for the first pass, just
		// convert all comments to empty comments.
		'#(/\*.*?\*/)+#s' => '/**/',
		
		// Quoted strings stay as they are.
		'#([\'"])(\\\\?.)*?\1#s' => true,

		// Multiple semicolons collapse to a single semicolon.
		'#(;\s*)+#s' => ';',
		
		// Operators in attribute selectors like [class^="foo""]
		'#[~^|*$]?=#' => true,
		
		// Any word that precedes an open parentheses,
		// optionally with a colon to match pseudo-classes.
		'#:?[\w-]+(?=\()#' => true,

		// Isolate a few other characters individually. Keep this one
		// last so that it doesn't supercede other patterns that begin
		// with these characters.
		'#[~^|*$>+\-,:(){}[\]]#' => true,
	];
	
	// Parse the CSS file into an array of tokens.
	private function tokenize($css) {
		$tokens = [];

		$len = strlen($css);
		$offset = 0;
		do {
			// Set some initial values before each `do` iteration.
			$match = '';
			$start = $len;
			$action = null;
			
			// Match each pattern starting at the offset and use the earliest-occurring one.
			foreach (self::$patterns as $pattern => $val) {
				if (
					preg_match($pattern, $css, $matches, PREG_OFFSET_CAPTURE, $offset) &&
					$matches[0][1] < $start
				) {
					// Update those values when we find a better match.
					$match = $matches[0][0];
					$start = $matches[0][1];
					$action = $val;
					
					// No need to keep looking if it's as far back as it can be.
					if ($start === $offset) {
						break;
					}
				}
			}

			// Keep whatever came before the match (or at the end of
			// the css, if nothing matched).
			$unmatched = substr($css, $offset, $start - $offset);
			if ($unmatched !== '') {
				$tokens[] = $unmatched;
			}
			
			// Process whatever was matched and add it to the result.
			if (is_string($action)) {
				$tokens[] = $action;

			} else if ($action === true) {
				$tokens[] = $match;
			}
			
			// Move the offset to the end of the match.
			$offset = $start + strlen($match);
			
			// Keep going as long as something was matched.
		} while ($match !== '');
		
		return $tokens;
	}
	
	// Trace backwards through the input, looking for closing braces
	// in order to remove empty rulesets and trailing semicolons.
	private function blocks(&$input) {
		$output = [];
		while ($input) {
			$token = array_pop($input);
			if ($token === '}') {
				$empty = $this->blocks_tail($input, $output);
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
					if ($expr === 'calc') {
						$inside[] = 'calc';
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
				// (i.e. a media expression) or if we're not inside 
				// a selector, which we test for by checking whether a 
				// block is coming soon.
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
	
	//
	private function nearest(&$input, $haystack) {
		$haystack = str_split($haystack);
		for ($i = count($input) - 1; $i >= 0; $i--) {
			if (in_array($input[$i], $haystack)) {
				return $input[$i];
			}
		}
		return false;
	}
}
