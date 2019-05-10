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
		$options = array_merge([
			'comments' => false,
			'tokenoids' => false,
		], $options);

		$comment_filter = $options['comments'];
		if (!is_callable($comment_filter)) {
			$comment_filter = false;
		}


		$tokens = $min->tokenize($css, $comment_filter);
		if (false === $tokens) {
			return false;
		}
		$tokens = $min->semicolons($tokens);
		$tokens = $min->blocks($tokens);
		$tokens = $min->spaces($tokens);
		if ($comment_filter) {
			$min->comments($tokens);
		}

		// Return tokenoids if asked.
		if ($options['tokenoids']) {
			return $tokens;
		}
		
		// Otherwise, just return the minified string.
		return implode('', $tokens);
	}
	
	// All the comments found in the CSS code.
	private $comments = [];
	
	// Split a CSS text into tokenoids.
	// (Not real CSS tokens, but good enough for minification purposes.)
	private function tokenize($css, $comment_filter) {
		$boundaries = '#(?<=[^\w-])|(?=[^\w-])#s';
		$comment_pattern = '#^/\*.*?\*/$#s';
		$input = array_reverse(preg_split($boundaries, $css));
		
		$output = [];
		$inside = [];
		while ($input) {
			$token = array_pop($input);
			switch ($token) {
				// If braces and such don't balance, other parts of YaCSSMin
				// might make different assumptions than a web browser would,
				// possibly leading either to breakage or to an accidental fix.
				// To mitigate that risk, refuse to minify such a stylesheet.
				case '{':
					$inside[] = '}';
					break;
				case '(':
					$inside[] = ')';
					break;
				case '[':
					$inside[] = ']';
					break;
				case ']':
				case ')':
				case '}':
					if ($token !== array_pop($inside)) {
						return false;
					}
					break;
					
				// Combine a quoted string into a single token.
				case '"':
				case "'":
					$quote = $token;
					do {
						if (!$input) {
							return false; // Couldn't find the ending quote.
						}
						$token .= $next = array_pop($input);;
						if ('\\' === $next) {
							$token .= array_pop($input);
						}
					} while ($next !== $quote);
					break;
				
				// Reduce comments to a single token.
				case '/':
					if ('*' == end($input)) {
						$token = '/**/';
						array_pop($input);

						// Build the comment by popping things off until the
						// sequence `*/` has happened.
						$comment = ['/*'];
						while ($input && (
							'*' !== ($comment[] = array_pop($input)) ||
							'/' !== ($comment[] = array_pop($input))
						));
						
						// If there's a filter and the comment passes it,
						// replace it with a placeholder token for later
						// reinstatement.
						if ($comment_filter &&
							($comment = $comment_filter(implode('', $comment))) &&
							is_string($comment) &&
							preg_match($comment_pattern, $comment)
						) {
							$this->comments[] = $comment;
							$token = '/*_*/';
						}
					}
					break;
				
				default: 
					// Replace runs of whitespace with single space.
					if ('' === trim($token)) {
						while ($input && '' === trim(end($input))) {
							array_pop($input);
						}
						$token = ' ';
					}
			}
			
			// Append the current token to the output.
			$output[] = $token;
		}
		
		// Bail if there's anything left over that we're looking to balance.
		if ($inside) {
			return false;
		}

		return $output;
	}
	
	// Remove unnecessary semicolons.
	private function semicolons(&$input) {
		$input = array_reverse($input);
		$safe = ['{', '}', ';', false];
		$output = [];
		// Loop through the tokens, determining whether to strip things
		// by looking back at the most recent thing on the output.
		do {
			$token = array_pop($input);
			// The first time through this loop, end($output) will be false,
			// so false is one of the $safe tokens after which it's okay to
			// strip semicolons and spaces.
			if (in_array(end($output), $safe, true)) {
				// Once we've found a token after which semicolons can be
				// stripped, strip them until something else is encountered.
				while ($input) {
					switch ($token) {
						// Strip spaces and semicolons.
						case ';':
						case ' ':
							break;
							
						// Keep but skip over comments for now.
						case '/**/':
						case '/*_*/':
							$output[] = $token;
							break;
						
						// Anything else and we stop looking near this
						// particular semicolon.
						default:
							break 2;
					}
					$token = array_pop($input);
				}
			} // `break 2` leads here
			$output[] = $token;
		} while ($input);
		return $output;
	}

	// Trace backwards through the input, looking for closing braces
	// in order to remove empty rulesets and trailing semicolons.
	private function blocks(&$input) {
		$output = [];
		while ($input) {
			$output[] = $token = array_pop($input);
			if ('}' === $token) {
				$this->blocks_recursive($input, $output);
			}
		}
		return array_reverse($output);
	}
	
	// Recursive helper method for blocks().
	private function blocks_recursive(&$input, &$output) {
		while ($input) {
			$output[] = $token = array_pop($input);
			switch ($token) {
				// Strip these and keep going.
				case ';':
				case ' ':
				case '/**/': // Comments that didn't pass the filter.
					array_pop($output);
					break;
				case '}':
					$this->blocks_recursive($input, $output);
					break;
				case '{':
					// It's an empty block.
					// Check whether the rule for the block has comments.
					
					$has_comment = false;
					$buffer = [];
					while ($input) {
						$buffer[] = $tok = array_pop($input);
						switch ($tok) {
							case ';':
							case '{':
							case '}':
								$input[] = array_pop($buffer);
								break 2;
							case '/*_*/':
								$has_comment = true;
						}
					}
					if ($has_comment) {
						array_splice($output, count($output), 0, $buffer);
					} else {
						array_pop($output);
						array_pop($output);
					}
					return;
				default:
					return;
			}
		}
	}
	
	// Decide where to remove white space and comments.
	private function spaces(&$input) {
		// Keep track of some contexts we might be inside of.
		$in_at = false;
		$inside = [];
		$PAREN = 0;
		$CALC  = 1;
		$NTH   = 2;

		// A pattern for matching possibly-hyphenated words.
		$word = '#^\w[\w-]*#';
	
		// Strip whitespace from the beginning and end,
		// and keep the array reversed so we can pop instead of shift.
		$this->strip($input);
		$input = array_reverse($input);
		$this->strip($input);
	
		$output = [];
		while ($input) {
			$token = array_pop($input);
		
			// First track what context we're currently inside.
			switch ($token) {
				case '@':
					$in_at = true;
					break;
				
				case '{':
					$in_at = false;
					// no break
				case '[':
					$inside[] = $token;
					break;

				case '(':
					$end = strtolower(end($output));
					$prev = prev($output);
					if ('calc' === $end || $CALC === end($inside)) {
						$inside[] = $CALC;
					} else if (':' === $prev && 'nth-' === substr($end, 0, 4)) {
						$inside[] = $NTH;
					} else {
						// We'll never check against this, but we need to push 
						// *something* for its closing paren to pop off.
						$inside[] = $PAREN;
					}
					break;
				
				// tokenize() has already ensured that the thing that's open
				// is the thing being closed here, so we don't need to check.
				case ')':
				case ']':
				case '}':
					array_pop($inside);
			}

			// Then decide whether to strip any spaces on either side
			// of the current token.
			switch ($token) {
				// Strip whitespace around colons outside selectors.
				case ':':
					// If it's in an @-rule, or if the next open brace comes
					// after the next closing brace or semicolon, then it's 
					// not in a selector, so strip the whitespace.
					if ($in_at || '{' !== $this->nearest($input, '{};')) {
						$this->strip($input);
						$this->strip($output);
					}
					break;
			
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
			
				// Strip whitespace on both sides of these.
				case ' ':
				case ';':
				case '{':
				case '}':
				case ',':
				case '>':
					$this->strip($input);
					$this->strip($output);
					break;

				// Strip whitespace around `-` tokens only inside :nth-X()
// Commented out because it's handled in the default case,
// but could still be useful to see.
// 				case '-':
// 					if ($NTH === end($inside)) {
// 						$this->strip($input);
// 						$this->strip($output);
// 					}
// 					break;

				// Strip whitespace around `+` tokens only outside calc()
				case '+':
					if ($CALC !== end($inside)) {
						$this->strip($input);
						$this->strip($output);
					}
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

				default:
					// Strip whitespace inside :nth-X(...) 
					if ($NTH === end($inside)) {
						$this->strip($input);
					}
			}

			$output[] = $token;
		}
		return $output;
	}
	
	// Strip whitespace from the end of the input or output,
	// preserving comments with empty strings.
	private function strip(&$array) {
		while ($array) {
			switch (end($array)) {
				case ' ':
				case '/**/':
					array_pop($array);
					break;
				default:
					break 2;
			}
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

	// Replace comment placeholders with real comments.
	private function comments(&$input) {
		// Tokens that are always safe to add whitespace next to.
		$tokens_safe = [' ', ',', ';', '{', '}'];
		$tokens_comment = ['/*_*/'];
	
		// An array to put the replacement comments in before really replacing
		// them, so that the loop can still look back at the previous token.
		$replace = [];

		$n = 0;
		$last = count($input) - 1;
		for ($i = 0; $i <= $last; $i++) {
			$token = $input[$i];

			// On each comment token we find...
			if (in_array($token, $tokens_comment)) {
				// Get the next stashed real comment.
				$comment = $this->comments[$n++];

				// Do some minimal formatting. Strip whitespace from the front
				// of each line, then put back a single space on lines that
				// begin with a star.
				$comment = preg_replace('#[\r\n]\s*#s', "\n", $comment);
				$comment = preg_replace('#\n\*#s', "\n *", $comment);

				// Pad the comment.
				$pad_head = '';
				$pad_tail = '';
				$offset = 0;
				while (1) {
					$offset++;
					$prev = $i - $offset;
					$next = $i + $offset;
					// If the comment is at the front, only pad the end.
					if ($prev < 0) {
						$pad_tail = "\n";
						break;
					}
					// If the comment is at the end, only pad the front.
					if ($next > $last) {
						$pat_head = "\n";
						break;
					}
					// If the previous or next token is safe, pad the front.
					if (in_array($input[$prev], $tokens_safe)
						|| in_array($input[$next], $tokens_safe)
					) {
						$pad_head = "\n";
						// If the next token is not a comment,
						// also pad the end.
						if (!in_array($input[$next], $tokens_comment)) {
							$pad_tail = "\n";
						}
						break;
					}
					// If the previous and next tokens aren't comments
					// (and we know they aren't safe characters either)
					// stop looking.
					if (!in_array($input[$prev], $tokens_comment)
						&& !in_array($input[$next], $tokens_comment)
					) {
						break;
					}
				}
				$replace[$i] = $pad_head . $comment . $pad_tail;
			}
		}
		foreach ($replace as $i => $comment) {
			$input[$i] = $replace[$i];
		}
	}
}
