<?php
namespace ThomasPeri\YaCSSMin;

class Minifier {
	static function minify($input) {
		$patterns = [
			// Whitespace becomes a single space.
			'@\s+@s' => ' ',
			
			// Comments get stripped.
			'@/\*.*?\*/@s' => false,
			
			// url(...) values should stay exactly as they are.
			// (Case-insensitive so as not to accidentally fix broken CSS.)
			'@url(.*?)@is' => true,
			
			// Quoted strings should be used as-is.
			// a quote,
			// any number of [character optionally preceded by a backslash],
			// and another of the same quote.
			'@([\'"])(\\\\?.)*?\1@s' => true,

			// Punctuation around which whitespace is always meaningless.
			// (Optimize a little by keeping this one last in the $patterns array.)
			'@[{};]\s*@' => function ($match) use (&$output) {
			
				// We need the match trimmed in advance of the prev logic.
				$match = trim($match);
				
				// Things happen at the ends of blocks.
				$close = ($match === '}');
				
				// Remove previous matches from the output until we get
				// a truthy one.
				do {
					if ( ($prev = array_pop($output)) ) {
						$prev = trim($prev);
						
						// Strip semicolons at the ends of blocks.
						if ($close && $prev === ';') {
							$prev = '';
						}
					}
				} while (!$prev);
				
				// If the previous match is an open brace and the
				// current on is a closing brace, this block has
				// nothing inside it. Remove both braces (the block),
				// along with whatever came before it, back to the most
				// recent brace (open or closed) or semicolon.
				if ($close && $prev === '{') {
					$match = '';
					do {
						$prev = array_pop($output);
					} while (
						$prev !== '{' &&
						$prev !== '}' &&
						$prev !== ';' &&
						$prev !== null
					);
				}

				// Put back the last thing that was popped, whatever it was.
				// (Either the unrelated thing preceding the now-removed
				// empty block, or the "real" previous thing.)
				if ($prev) {
					$output[] = $prev;
				}

				// Return the already-trimmed match.
				return $match;
			},
		];
		
		$output = [];

		$len = strlen($input);
		$offset = 0;
		
		do {
			$match = '';
			$start = $len;
			$action = null;
			
			// Match each pattern starting at the offset and use the earliest-occurring one.
			foreach ($patterns as $pattern => $val) {
				if (preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE, $offset)) {
					if ($matches[0][1] < $start) {
						$match = $matches[0][0];
						$start = $matches[0][1];
						$action = $val;
					}
					
					// No need to look further if it's as far back as it can be.
					if ($start === $offset) {
						break;
					}
				}
			}

			// Keep whatever came before the match (or at the end of
			// the input, if nothing matched).
			if ( ($unmatched = substr($input, $offset, $start - $offset)) ) {
				$output[] = $unmatched;
			}
			
			// Process whatever was matched and add it to the result.
			if ($action instanceof \Closure) {
				if ( ($result = $action($match))) {
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
}
