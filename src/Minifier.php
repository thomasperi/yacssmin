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

		// If the comments option has a value that's not callable, flag it so.
		$comment_filter = $options['comments'];
		if (!is_callable($comment_filter)) {
			$comment_filter = false;
		}

		// Tokenize and minify.
		$tokens = $min->tokenize($css, $comment_filter);
		if (false === $tokens) {
			return false;
		}
		$tokens = $min->semicolons($tokens);
		$tokens = $min->blocks($tokens);
		$tokens = $min->spaces($tokens);
		
		// Restore comments if there's a filter.
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
		
		// Convert Windows linebreaks so that the tokenizer doesn't have to
		// deal with the possibility of a backslash that escapes the next
		// two characters instead of just one.
		$css = preg_replace('#\r\n#s', "\n", $css);
		
		// Split into proto-tokens.
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
						$token .= $next = array_pop($input);
						switch ($next) {
							case '\\':
								$token .= array_pop($input);
								break;
							case "\n":
								return false; // Unescaped line break.
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
							
						// Keep but skip over all comments, for now.
						// Their time will come.
						case '/**/':
						case '/*_*/':
							$output[] = $token;
							break;
						
						// Anything else and we stop looking near this
						// particular semicolon.
						default:
							break 2;
					}
					// We haven't found our 'anything else' yet,
					// so pop the next token.
					$token = array_pop($input);
				}
			} // `break 2` leads here
			$output[] = $token;
		} while ($input);
		return $output;
	}

	// Trace backwards through the input, looking for closing braces
	// in order to remove empty blocks and trailing semicolons.
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
	
	// Deal with the contents of a block, recursively.
	private function blocks_recursive(&$input, &$output) {
		$found_comments = 0;
		while ($input) {
			switch ($token = array_pop($input)) {
				// Strip these and keep going.
				case ';':
				case ' ':
				case '/**/':
					break;
				
				// Preserved comments get stripped too, but keep count.
				case '/*_*/':
					$found_comments++;
					break;
				
				// Handle nested blocks recursively.
				case '}':
					// But first, output all the comments so far.
					if ($found_comments > 0) {
						$this->flush_comments($output, $found_comments);
					}
					// Output the brace.
					$output[] = $token;
					// Then, deal with the nested block's contents.
					$this->blocks_recursive($input, $output);
					break;
				
				// Beginning of the block, probably empty,
				// but maybe there were preserved comments inside it.
				case '{':
					// If there were preserved comments in the block,
					// the block isn't empty after all.
					if ($found_comments > 0) {
						// Output the number of comments found.
						$this->flush_comments($output, $found_comments);
						// Output the brace.
						$output[] = $token;
					} else {
						// But if it really is empty, deal with that.
						// Output the brace.
						$output[] = $token;
						// Check the block's rule for comments before
						// removing the block and the rule.
						$this->blocks_empty($input, $output);
					}
					// We're done with this block either way.
					return;
				
				// If we find anything else, there's nothing more to optimize
				// in this block, so put whatever we found back on the input
				// and output any comments we might have passed over.
				default:
					$input[] = $token;
					if ($found_comments > 0) {
						$this->flush_comments($output, $found_comments);
					}
					return;
			}
		}
	}
	
	// It's an empty block. Check whether the rule for the
	// block has comments that are being preserved.
	private function blocks_empty(&$input, &$output) {
		$found_comments = 0;
		$contains_comment = false;
		$buffer = [];
		while ($input) {
			switch ($buffer[] = array_pop($input)) {
				case ';':
				case '{':
				case '}':
					// This character isn't part of the rule for
					// the block, so remove it from the buffer and
					// put it back on the input.
					$input[] = array_pop($buffer);
					break 2;
				case '/*_*/':
					// Pass over preserved comments, but keep a count.
					$found_comments++;
					break;
				default:
					// If we encounter something else that's not a
					// comment, and at least one comment has been
					// encountered, that means the comments are
					// "inside" the buffer and the buffer can't be
					// tossed out.
					if ($found_comments > 0) {
						$contains_comment = true;
					}
			}
		}
		// If it's got preserved comments that happened INSIDE the
		// buffer, put back the buffer and keep the whole block.
		if ($contains_comment) {
			array_splice($output, count($output), 0, $buffer);
		
		// Otherwise, get rid of the braces and discard the buffer.
		} else {
			array_pop($output);
			array_pop($output);
			// If it's got preserved comments that were OUTSIDE
			// the buffer, output just those. All we care about
			// right now is how many there are, 'cause they're all
			// the same until they get repopulated.
			$this->flush_comments($output, $found_comments);
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

		// A pattern for matching six-hex colors that could be three-hex.
		$color = '#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3#';
	
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

				case '#':
					// This one isn't about spaces, but it's piggybacking 
					// here because it needs to know where we are.
					// If we're not in a selector (or an @-rule incidentally),
					// this is a color.
					if ('{' !== $this->nearest($input, '{};')) {
						// Reduce it from six hex digits to three if possible.
						if (preg_match($color, strtolower(end($input)), $matches)) {
							array_pop($input);
							$token .= $matches[1] . $matches[2] . $matches[3];
						}
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
		// Keep track of how many preserved comments are encountered,
		// then add them back at the end.
		$found_comments = 0;
		while ($array) {
			switch ($token = array_pop($array)) {
				case ' ':
				case '/**/':
					break;
				case '/*_*/':
					$found_comments++;
					break;
				default:
					$array[] = $token;
					break 2;
			}
		}
		if ($found_comments > 0) {
			$this->flush_comments($array, $found_comments);
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

	// Re-insert the number of preserved comments that were counted.
	private function flush_comments(&$array, &$n) {
		while (0 < $n--) {
			$array[] = '/*_*/';
		}
	}
	
	// Replace preserved comment placeholders with real comments.
	// This is unlike other methods in that it operates directly on the $input
	// array instead of returning a new $output array.
	private function comments(&$input) {
		// Tokens that are always safe to add whitespace next to.
		$tokens_safe = [' ', ',', ';', '{', '}'];
		$preserved = '/*_*/';
	
		// An array to put the replacement comments in before really replacing
		// them, so that the loop can still look back at the previous token.
		$replace = [];

		$n = 0;
		$last = count($input) - 1;
		for ($i = 0; $i <= $last; $i++) {
			$token = $input[$i];

			// On each preserved comment token we find...
			if ($preserved === $token) {
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
						if ($preserved !== $input[$next]) {
							$pad_tail = "\n";
						}
						break;
					}
					// If the previous and next tokens aren't comments
					// (and we know they aren't safe characters either)
					// stop looking.
					if ($preserved !== $input[$prev] &&
						$preserved !== $input[$next]
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
