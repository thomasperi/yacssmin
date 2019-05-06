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
	static function minify($css, $options = []) {
		$min = new self();
		$min->options = $options;

		$tokens = $min->tokenize($css); 
		$tokens = $min->blocks($tokens);
		$tokens = $min->spaces($tokens);

		// Return tokens if desired, but first do some extra processing.
		if ($min->option('return_tokens')) {
			return $min->meld($tokens);
		}
		
		// Otherwise, just return the string. The exact boundaries between
		// some tokens don't matter when we're just imploding it anyway.
		return implode('', $tokens);
	}
	
	// The instance's copy of the options array.
	private $options;
	
	// Retrieve an option.
	private function option($key) {
		if (isset($this->options[$key])) {
			return $this->options[$key];
		}
	}

	// Split a CSS text into proto-tokens.
	private function tokenize($css) {
		$boundaries = '#(?<=[^\w-])|(?=[^\w-])#s';
		$input = array_reverse(preg_split($boundaries, $css));
		
		// Prep the comments filter.
		$comments_filter = $this->option('comments_filter');
		$comment_pattern = '#^/\*.*?\*/$#s';
		if (!is_callable($comments_filter)) {
			$comments_filter = false;
		}

		$output = [];
		while ($input) {
			$token = array_pop($input);
			switch ($token) {
				// Reduce comments to a single token. Maybe empty, maybe not.
				case '/':
					if ('*' == end($input)) {
						array_pop($input);
						$comment = ['/*'];
						while ($input && (
							'*' !== ($comment[] = array_pop($input)) ||
							'/' !== ($comment[] = array_pop($input))
						));
						
						// If we're filtering comments, do so.
						$result = null;
						if ($comments_filter) {
							$comment = implode('', $comment);
							$result = $comments_filter($comment);
						}
						if ($result && preg_match($comment_pattern, $result)) {
							// If the filter returned a valid comment,
							// it'd be nice to put it all on lines by itself.
							// Strip any whitespace that came before the
							// original comment, and replace it with the
							// $recent_whitespace stashed the last time
							// whitespace was encountered.
							$this->strip($output);
							if ($output) {
								// Keep the whitespace that comes after the
								// last newline before the start of the comment.
								$output[] = "\n" . preg_replace(
									'#.*?([^\r\n]*)$#s',
									"\\1",
									implode('', $recent_whitespace)
								);
							}
							// Also add a newline to the end of the comment
							// and strip further whitespace from the input.
							$token = $result . "\n";
							while ('' === trim(end($input))) {
								array_pop($input);
							}
						} else {
							// Replace all other comments with empties.
							$token = '/**/';
						}
					}
					break;
				
				// Combine a quoted string into a single token.
				case '"':
				case "'":
					$quoted = [$token];
					while ($input) {
						switch ($quoted[] = array_pop($input)) {
							case '\\':
								$quoted[] = array_pop($input);
								break;
							case $token: // Match the opening quote
								break 2;
						}
					}
					$token = implode('', $quoted);
					break;
				
				// Reduce of multiple semicolons and whitespace
				// to a single semicolon token.
				case ';':
					while ($input) {
						$next = end($input);
						if (';' === $next || '' === trim($next)) {
							array_pop($input);
						} else {
							break;
						}
					}
					break;

				default: 
					// Replace runs of whitespace with single space.
					if ('' === trim($token)) {
						$recent_whitespace = [$token];
						while ($input && '' === trim(end($input))) {
							$recent_whitespace[] = array_pop($input);
						}
						$token = ' ';
					} else {
						$recent_whitespace = [];
					}
			}
			
			// Append the current token to the output.
			$output[] = $token;
		}
		return $output;
	}
	
	// Trace backwards through the input, looking for closing braces
	// in order to remove empty rulesets and trailing semicolons.
	private function blocks(&$input) {
		$output = [];
		while ($input) {
			$token = array_pop($input);
			if ('}' === $token) {
				$this->blocks_tail($input, $output);
			} else {
				$output[] = $token;
			}
		}
		return array_reverse($output);
	}
	
	// Recursive helper method for blocks().
	private function blocks_tail(&$input, &$output) {
		// When found, pop off semicolons, spaces, and empty comments
		// until we reach something else.
		while ($input) {
			$token = array_pop($input);
			switch ($token) {
				case ';':
				case ' ':
				case '/**/':
					// Skip empty comments. Non-empties are handled
					// in the default case.
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

				default:
					// If anything else was found, keep it.
					$input[] = $token;
				
					// If that something else was not a comment, we're done
					// optimizing the tail end of this block, so push the
					// closing brace (the one that caused blocks_tail() to be
					// called in the first place) onto the output.
					if ('/*' !== substr($token, 0, 2)) {
						$output[] = '}';
						return;
					}
			}
		}
	}
	
	// Decide where to remove white space and comments.
	private function spaces(&$input) {
		// Keep track of some contexts we might be inside of.
		$in_at = false;
		$inside = [];
		$CALC = 1;
		$MATCHES = 2;
		$NTH = 3;

		// A pattern for matching hyphen words.
		$word = '#^\w[\w-]*#';
		
		// Strip spaces and comments from the beginning and end,
		// and keep the array reversed so we can pop instead of shift.
		$this->strip($input);
		$input = array_reverse($input);
		$this->strip($input);
		
		$output = [];
		while ($input) {
			$token = array_pop($input);
			
			// First track what delimiters we're currently inside.
			switch ($token) {
				case '@':
					$in_at = true;
					break;
					
				case '(':
					// We never go looking for end($inside) to be
					// $MATCHES specifically, but we need it to be
					// something other than `nth` or `(` which we do
					// compare for.
					if (
						$CALC === end($inside) || 
						'calc' === ($end = strtolower(end($output)))
					) {
						$inside[] = $CALC;
					} else if (':matches' === $end) {
						$inside[] = $MATCHES;
					} else if (':nth' === substr($end, 0, 4)) {
						$inside[] = $NTH;
					} else {
						$inside[] = $token;
					}
					break;
				case '{':
					$in_at = false;
					// no break
				case '[':
					$inside[] = $token;
					break;
					
				case ']':
				case '}':
				case ')':
					array_pop($inside); // to-do: match the opener
			}

			// Then decide whether to strip any spaces on either side
			// of the current token.
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
				
				// Strip whitespace and comments around these.
				case ' ':
				case ';':
				case '{':
				case '}':
				case ',':
				case '>':
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
						'[' !== end($inside) || 
						']' === $this->nearest($input, ']=')
					) {
						$this->strip($input);
					}
					break;
				case '^':
				case '|':
				case '*':
				case '$':
					// Only strip whitespace to the left of these,
					// and only inside brackets. 
					if ('[' === end($inside)) {
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
						'[' !== end($inside) ||
						'[' === $this->nearest($output, '[~^|*$')
					) {
						$this->strip($output);
					}
					break;

				// Strip whitespace around `-` tokens only inside nth
				case '-':
					if ($NTH === end($inside)) {
						$this->strip($input);
						$this->strip($output);
					}
					break;

				// Strip whitespace around `+` tokens only outside calc
				case '+':
					if ($CALC !== end($inside)) {
						$this->strip($input);
						$this->strip($output);
					}
					break;
				
				// Colons are complicated...
				case ':':
					// Combine double-colon into a single token.
					if (':' === end($input)) {
						array_pop($input);
						$token = '::';
					}
					
					// We're in a selector if we're not in an @-rule
					// and there's an open brace before the next closing brace
					// or semicolon.
					$in_selector = (
						!$in_at &&
						'{' === $this->nearest($input, '{};')
					);

					// Combine the colon with the next token if we're
					// in a selector and the token is a hyphen-word.
					if ($in_selector && preg_match($word, end($input))) {
						$token .= array_pop($input);
					}

					// Strip whitespace around colons outside selectors
					// if the colon is inside standalone parentheses.
					if (!$in_selector || '(' === end($inside)) {
						$this->strip($input);
						$this->strip($output);
					}
					break;
				
				default:
					// Combine tokens inside nth-X(...) 
					if ($NTH === end($inside)) {
						do {
							$this->strip($input);
							if (')' === end($input)) {
								break;
							} else {
								$token .= array_pop($input);
							}
						} while ($input);
					}
			}

			$output[] = $token;
		}
		return $output;
	}
	
	// Prepare for returning tokens, by combining or further splitting 
	// some of the tokens where it didn't matter just for minification.
	private function meld(&$input) {
		$input = array_reverse($input);
		
		$output = [];
		$number_start = '#^[-+.\d]#';
		$number_chars = '#^[-+.\da-z]+$#i';
		$number = '#^[-+]?(\d*\.)?\d+[a-z]*$#i';
		while ($input) {
			$token = array_pop($input);
			
			// If a token starts like a number (with or without units),
			// determine whether it is one.
			if (preg_match($number_start, $token)) {
				$next = $possible = $token;
				$i = count($input);
				// Keep adding tokens on until the result neither...
				while ($i >= 0) {
					$possible = $next;
					$next .= $input[--$i];
					if (!preg_match($number, $next) &&    // ...is a number, nor...
						!preg_match($number_chars, $next) // ...has only number characters.
					) {
						// Back up the array pointer so that we don't splice
						// out the token that didn't match.
						$i++;
						break;
					}
				}
				$token = $possible;
				array_splice($input, $i);
			}
			
			switch ($token) {
				// Combine each dot, hash, or at, with whatever token follows it.
				case '.':
				case '#':
				case '@':
					$token .= array_pop($input);
					break;

				// Combine each equality operator into a single token.
				case '~':
				case '^':
				case '|':
				case '*':
				case '$':
					if ('=' === end($input)) {
						$token .= array_pop($input);
					}
					break;
			}
			$output[] = $token;
		}
		return $output;
	}

	// Strip whitespace and comments from the end of the input or output.
	private function strip(&$array) {
		// This is the most-called method in the class.
		// Don't be tempted to include non-empty comments in this somehow.
		// The speed of this method relies to being able to quickly check
		// which tokens to strip based on its membership in the $strip array.
		// Non-empty comments shouldn't be in weird places anyway.
		$strip = [' ', '/**/'];
		while (in_array(end($array), $strip)) {
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
