<?php
namespace ThomasPeri\YaCSSMin;

/**
 * Yet Another CSS Minifier
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
		$tokens = $min->comments($tokens);
		$tokens = $min->spaces($tokens);
		$tokens = $min->expressions($tokens);

		return trim(implode('', $tokens));
	}
	
	private static
		$safe = ['{', '}', ';', ',', ' '],
		$patterns = [
			// Contiguous whitespace becomes a single space.
			'#\s+#s' => ' ',

			// The rules for whether a comment is considered nothing or
			// whitespace are complicated, so for the first pass, just
			// convert all comments to empty comments.
			'#(/\*.*?\*/)+#s' => '/**/',
	
			// Quoted strings stay as they are.
			'#([\'"])(\\\\?.)*?\1#s' => true,
	
			// Words including hyphens stay as they are.
			'#[\w-]+#' => true,

			// Isolate a few individual characters.
			'#[@:;(){}]#' => true,
		];
	
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
	
	private function blocks(&$input) {
		// Trace backwards through the input, looking for closing braces.
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
				// before proceeding to the next, 'cause it might be
				// empty too.
				case '}':
					$this->blocks_tail($input, $output);
					break;

				// If that something else is an opening brace,
				// this block is empty, so don't push any output.
				case '{':
					$this->blocks_empty($input, $output);
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
	
	private function blocks_empty(&$input, &$output) {
		// Pop things off until we find a semicolon or an open or
		// close brace. (Don't pop whatever is found.)
		while ($input) {
			switch (end($input)) {
				case ';':
				case '{':
				case '}':
					return;
				default:
					array_pop($input);
			}
		}
	}
	
	private function comments(&$input) {
		$output = [];
		$next = array_pop($input);
		while ($next !== null) {
			$prev = end($output);
			$token = $next;
			$next = array_pop($input);
			
			// Whenever there's a comment (emptied by the tokenizer),
			// decide whether to keep or discard it.
			if ($token === '/**/') {
				// If either adjacent token is a safe one, discard the comment.
				if (in_array($prev, self::$safe) || in_array($next, self::$safe)) {
					continue;
				}
			}
			
			// Keep tokens that aren't comments,
			// and comments that don't abut safe characters.
			$output[] = $token;
		}
		return array_reverse($output);
	}

	private function spaces(&$input) {
		$output = [];
		$maybe_decl = false;
		$next = array_pop($input);
		while ($next !== null) {
			$prev = end($output);
			$token = $next;
			$next = array_pop($input);
			
			// Whenever there's a comment (emptied by the tokenizer),
			// decide whether to keep or discard it.
			switch ($token) {
				case ' ':
					// Conditions under which to discard the space:
					if (
						// If either adjacent token is a safe one:
						in_array($prev, self::$safe) ||
						in_array($next, self::$safe) ||

						// At the beginning or end of a set of parens:
						// (Working tail-to-head, prev is to the right
						// and next is to the left.)
						$prev === ')' ||
						$next === '(' ||
						
						// If we might be in a property declaration
						// and the next or previous token is a colon:
						($maybe_decl && ($prev === ':' || $next === ':'))
					) {
						continue 2;
					}
					break;
				
				// If something is ending, it might be a property declaration.
				case ';':
				case '}':
					$maybe_decl = true;
					break;
				
				// If a block is beginning, what comes before it is
				// not a declaration.
				case '{':
					$maybe_decl = false;
					break;
			}
			
			// Keep tokens that aren't spaces,
			// and spaces that don't satisfy the conditions above.
			$output[] = $token;
		}
		return array_reverse($output);
	}
	
	private function expressions(&$input) {
		$output = [];
		
		while ($input) {
			// Find the next @-rule.
			$pos = array_search('@', $input);
			if ($pos === false) {
				break;
			}
			// Remove the head of the input and append it to the output.
			array_splice($output, count($output), 0, array_splice($input, 0, $pos));
			
			// Find the end of the @-rule.
			$count = count($input);
			$semi = array_search(';', $input);
			if ($semi === false) {
				$semi = $count;
			}
			$brace = array_search('{', $input);
			if ($brace === false) {
				$brace = $count;
			}
			$pos = min($semi, $brace);
			
			// Splice out the rule and search inside it.
			$at_rule = array_splice($input, 0, $pos);
			while ($at_rule) {
				// Each parenthesis with a space in front of it marks the
				// beginning of a potential media expression.
				$open = array_search('(', $at_rule);
				$close = array_search(')', $at_rule);
				$len = $close - $open;
				if ($len <= 0) {
					break;
				}
				
				// If what came before the paren wasn't a space,
				// move on to the next paren.
				if (end($output) !== ' ') {
					array_splice($output, count($output), 0, array_splice($at_rule, 0, $close));
					continue;
				}

				// Got a suitable paren, so extract the expression from
				// inside the @-rule.
				$expr = array_splice($at_rule, $open, $len);
				
				// Then, remove the head of the @-rule and append it to
				// the output.
				array_splice($output, count($output), 0, array_splice($at_rule, 0, $open));
				
				// Now that it's down to just the expression, we can
				// use a regex to strip spaces around any colons.
				$output[] = preg_replace('#\s*:\s*#s', ':', implode('', $expr));
			}
			
			// Append the rest of the @-rule onto the output.
			array_splice($output, count($output), 0, $at_rule);
		}

		// Append the rest of the input onto the output;
		array_splice($output, count($output), 0, $input);
		return $output;
	}
}
