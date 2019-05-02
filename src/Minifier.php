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

		// Isolate a few other characters individually.
		'#[@,:(){}]#' => true,
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
		
		// In order to strip spaces around colons, we need to know
		// whether it's part of a selector, a declaration, or a media
		// expression. We can detect media expressions as we move
		// forward, without looking ahead. We need to also detect
		// @-rules, because that's where parentheses mean media
		// expressions.
		$in_at = false;
		$in_expr = false;
		
		while ($input) {
			$token = array_pop($input);
			switch ($token) {
				case '@':
					$in_at = true;
					break;
				
				case '(':
					$this->strip($input);
					if ($in_at) {
						$in_expr = true;
					}
					break;
				
				case ')':
					$this->strip($output);
					$in_expr = false;
					break;
				
				case ';';
				case '{';
				case '}';
					$in_at = false;
					$in_expr = false;
					// fall-through
				
				case ',';
				case ' ':
					$this->strip($input);
					$this->strip($output);
					break;

				// Strip whitespace around colons that are part of
				// media expressions or style declarations, but not
				// selectors.
				case ':':
					// Media expressions are taken care of already.
					if ($in_expr) {
						$strip = true;
					
					// If we're not in a media expression but we are in
					// an @-rule, finding a colon is weird, so don't
					// strip spaces.
					} else if ($in_at) {
						$strip = false;
						
					// If we're not in a media expression or an @-rule,
					// check if the next big thing is a block.
					} else {
						// If it's NOT a block, the colon is part of a 
						// declaration, so we CAN strip whitespace.
						// If it IS a block, the colon is part of a
						// selector, so DON'T strip whitespace.
						$strip = !$this->block_approaching($input);
					}
					
					if ($strip) {
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
	
	// If the next open brace comes before the next closing brace or
	// semicolon, then the next thing after the thing we're in is a block.
	private function block_approaching(&$input) {
		$end = ['{', '}', ';'];
		for ($i = count($input) - 1; $i >= 0; $i--) {
			if (in_array($input[$i], $end)) {
				return $input[$i] === '{';
			}
		}
	}
}
