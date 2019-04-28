<?php
namespace ThomasPeri\YaCSSMin;

/**
 * Yet Another CSS Minifier
 */
class Minifier {

	private static $patterns = [
		// Whitespace becomes a single space.
		'#\s+#s' => ' ',
	
		// Comments get stripped.
		'#/\*.*?\*/#s' => false,
	
		// url(...) values should stay exactly as they are.
		// (Case-insensitive so as not to accidentally fix broken CSS.)
		'#url\(.*?\)#is' => true,
	
		// Quoted strings should be used as-is.
		'#([\'"])(\\\\?.)*?\1#s' => true,
		
		// A colon.
		'#:\s*#s' => ['colon'],
		
		// An @-rule
		'#@\w+#' => ['at_rule'],

		// One or more semicolons, each optionally followed by whitespace.
		'#(;\s*)+#s'  => ['semicolon'],

		// Other delimiters
		'#\(\s*#s' => ['open_paren'],
		'#\)#'     => ['close_paren'], // Might need its whitespace so don't trap it here.
		'#\{\s*#s' => ['open_brace'],
		'#\}\s*#s' => ['close_brace'],
	];
	
	private
		$input,
		$output = [],
		$context = [];
	
	/**
	 * Minify some CSS code.
	 * @param string $css The CSS code to minify.
	 * @returns string
	 */
	static function minify($css) {
		return (new self($css))->minify_instance();
	}

	private function __construct($input) {
		$this->input = $input;
	}

	private function truthy($value) {
		return $value || $value === '0';
	}
	
	private function minify_instance() {
		$input = &$this->input;
		$output = &$this->output;
		
		$len = strlen($input);
		$offset = 0;
		
		do {
			// Set some initial values before each `do` iteration.
			$match = '';
			$start = $len;
			$action = null;
			
			// Match each pattern starting at the offset and use the earliest-occurring one.
			foreach (self::$patterns as $pattern => $val) {
				if (
					preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE, $offset) &&
					$matches[0][1] < $start
				) {
					// Update those values when we find a better match.
					$match = $matches[0][0];
					$start = $matches[0][1];
					$action = $val;
					
					// No need to look further if it's as far back as it can be.
					if ($start === $offset) {
						break;
					}
				}
			}

			// Keep whatever came before the match (or at the end of
			// the input, if nothing matched).
			$unmatched = substr($input, $offset, $start - $offset);
			if ($this->truthy($unmatched)) {
				$output[] = $unmatched;
			}
			
			// Process whatever was matched and add it to the result.
			if (is_array($action)) {
				$method = $action[0];
				$result = $this->$method($match);
				if ($result) {
					$output[] = $result;
				}

			} else if (is_string($action)) {
				$output[] = $action;

			} else if (true === $action) {
				$output[] = $match;
			}
			
			// Move the offset to the end of the match.
			$offset = $start + strlen($match);
			
			// Keep going as long as something was matched.
		} while ($match);
		
		return trim(implode('', $output));
	}
	
	private function whitespace($match) {
		// Remove previous matches from the end of the output until we
		// get a non-whitespace one or we reach the beginning.
		while (!$this->truthy($prev) && $this->output) {
			$prev = trim(array_pop($this->output));
		}

		// If the last thing that was popped wasn't whitespace,
		// put it back.
		if ($this->truthy($prev)) {
			$this->output[] = $prev;
		}
		
		// Trim the match itself.
		return trim($match);
	}
	
	private function at_rule($match) {
		$this->context[] = '@';
		return $match;
	}
	
	private function colon($match) {
		// Directly inside a media expression, just remove whitespace
		// around the colon and be done with it.
		if (end($this->context) === '(') {
			return $this->whitespace($match);
		}
		
		// In other contexts, enter a new context indicating that this 
		// might be a name-value pair. A close brace or a semicolon exit
		// this colon context.
		$this->context[] = ':';

		// If it has whitespace, trim it and append a single space.
		if (strlen($match) > 1) {
			return trim($match) . ' ';
		}
		
		// Otherwise, return it as-is.
		return $match;
	}
	
	private function colon_pair() {
		// If we encounter a close brace in colon context, this must be
		// a name-value pair, and so the colon should be de-whitespaced.
		if (end($this->context) === ':') {
			// Not in colon context anymore.
			array_pop($this->context);
			
			// Stash a reference.
			$output = &$this->output;
			
			// Find the most recent colon.
			for ($i = count($output) - 1; $i >= 0; $i--) {
				switch ($output[$i]) {
					case ':':
					case ': ':
						break 2;
				}
			}
			
			// Remove the colon and everything after it.
			$tail = array_splice($output, $i);
			
			// Remove whitespace before the colon.
			$this->whitespace('');
			
			// Add the colon back on.
			$output[] = ':';
			
			// Remove the colon from the tail.
			array_shift($tail);
			
			// Re-attach the remainder of the tail.
			array_splice($output, count($output), 0, $tail);
		}
	}

	private function open_paren($match) {
		// If this open parenthesis is inside an @-rule...
		if (end($this->context) === '@') {
			// ...now we're in a media expression inside the @-rule.
			$this->context[] = '(';
			// Remove white space after the paren but not before it.
			$match = trim($match);
		}
		return $match;
	}

	private function close_paren($match) {
		// Pop back to the most recent open paren.
		do {
			$open = array_pop($this->context);
		} while ($open && $open !== '(');

		// If this parenthesis is inside an @-rule, this is a media
		// expression, so remove white space around the paren.
		if (end($this->context) === '@') {
			$match = $this->whitespace($match);
		}
		
		return $match;
	}
	
	private function open_brace($match) {
		// If we were in an @-rule, we're not anymore.
		if (end($this->context) === '@') {
			array_pop($this->context);
		}
		
		// Now we're in a block.
		$this->context[] = '{';

		// Remove whitespace around the brace.
		return $this->whitespace($match);
	}

	private function close_brace($match) {
		// If we're in a colon context, deal with possible name-value pair.
		$this->colon_pair();
	
		// Pop back to the most recent open brace.
		do {
			$open = array_pop($this->context);
		} while ($open && $open !== '{');

		// Remove whitespace around the brace.
		$match = $this->whitespace($match);
		
		// Grab a reference to the output array for conciseness.
		$output = &$this->output;

		// Remove possible semicolon before the brace.
		if (end($output) === ';') {
			array_pop($output);
		}

		// If the previous match is an open brace, this block has
		// nothing inside it. Remove both braces (the block), along
		// with whatever came before it, back to the most recent brace
		// (open or closed) or semicolon.
		if (end($output) === '{') {
			// Remove both braces (the match and the end of the output)
			$match = '';
			array_pop($output);
			
			// Trace it back to the nearest brace (open or closed) or semicolon.
			while ($output) {
				switch (end($output)) {
					case '{':
					case '}':
					case ';';
						// When one is found, stop.
						break 2;
				}
				// Remove whatever it was that wasn't one of those characters.
				array_pop($output);
			}
		}
		
		return $match;
	}

	private function semicolon($match) {
		// If we're in a colon context, deal with possible name-value pair.
		$this->colon_pair();

		// If we were in an @-rule, we're not anymore.
		if (end($this->context) === '@') {
			array_pop($this->context);
		}
		
		// Remove whitespace around the brace.
		$this->whitespace($match);
		
		// Return a single semicolon no matter how many there are.
		return ';';
	}

}
