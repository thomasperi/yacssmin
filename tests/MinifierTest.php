<?php
namespace ThomasPeri\YaCSSMin\Test;

class MinifierTest extends \PHPUnit\Framework\TestCase {

	function minify(...$args) {
		return call_user_func_array('\ThomasPeri\YaCSSMin\Minifier::minify', $args);
	}
	
	function test_() {
		$errors = [];
		$success = 0;
		$failure = 0;
		$total = 0;
		$wrap = 72;
		$dir = __DIR__ . '/test-data/';
		foreach (scandir($dir) as $subdir) {
			if ('.' === substr($subdir, 0, 1)) {
				continue;
			}
			$subdir = $subdir . '/';
			$subdir_full = $dir . $subdir;

			if (is_dir($subdir_full)) {
				$tests = scandir($subdir_full);
				$index = array_search('_expected.css', $tests);
				
				if ($index !== false) {
					unset($tests[$index]);
					$expected = file_get_contents($subdir_full . '_expected.css');
					foreach ($tests as $source) {
						if (
							'.' === substr($source, 0, 1) ||
							'.css' !== substr($source, -4)
						) {
							continue;
						}

						$original = file_get_contents($subdir_full . $source);
						$actual = $this->minify($original);
						
						// Sanity-check the minified against the tokens array.
						$tokens = $this->minify($original, ['return_tokens' => true]);
						$imploded = implode('', $tokens);
						
						$total++;
						if ($expected !== $actual) {
							echo 'X';
							$errors[] = [
								'ERROR' => 'Minified string did not equal expected.',
								'FILE' => $subdir . $source,
								'EXPECTED' => $expected,
								'ACTUAL' => $actual,
								'ORIGINAL' => $original,
							];
							$failure++;

						} else if ($actual !== $imploded) {
							echo 'X';
							$errors[] = [
								'ERROR' => 'Imploded tokens did not equal minified string.',
								'FILE' => $subdir . $source,
								'MINIFIED' => $actual,
								'IMPLODED' => $imploded,
								'ORIGINAL' => $original,
							];
							$failure++;
							
						} else {
							echo '-';
							$success++;
						}
						
						if ($total % $wrap === 0) {
							echo "\n";
						}
						ob_flush();
					}
				}
			}
		}
		echo "\ntest_() tested $total stylesheets. $success succeeded. $failure failed.\n";
		ob_flush();
		
		if ($errors) {
			$msg = ["Failures in test_():\n"];
			$i = 0;
			foreach ($errors as $error) {
				$msg[] = '(' . ++$i . ')';
				foreach ($error as $key => $val) {
				$msg[] = "----- $key: -----";
				$msg[] = $val;
				}
			}
			$this->assertTrue(false, implode("\n", $msg));

		} else {
			$this->assertTrue(true); // Make the test un-risky.
		}
	}
}

