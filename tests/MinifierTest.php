<?php
namespace ThomasPeri\YaCSSMin\Test;

class MinifierTest extends \PHPUnit\Framework\TestCase {

	function minify($css) {
		return \ThomasPeri\YaCSSMin\Minifier::minify($css);
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

// 						echo "\n=== file: $subdir$source: ";
// 						ob_flush();

						$original = file_get_contents($subdir_full . $source);
						
// 						$start = microtime(true);

						$actual = $this->minify($original);

// 						$duration = microtime(true) - $start;
// 						echo "$duration seconds ===";
// 						ob_flush();
						
						if ($expected === $actual) {
							echo '-';
							$total++;
							$success++;
						} else {
							echo 'X';
							$errors[] = [
								'file' => $subdir . $source,
								'expected' => $expected,
								'actual' => $actual,
								'original' => $original,
							];
							$total++;
							$failure++;
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
				$msg[] = '(' . ++$i . ') ' .  $error['file'];
				$msg[] = "----- Expected: -----";
				$msg[] = $error['expected'];
				$msg[] = "----- Actual: -------";
				$msg[] = $error['actual'];
				$msg[] = "----- Original: -------";
				$msg[] = $error['original'];
				$msg[] = "\n";
			}
			$this->assertTrue(false, implode("\n", $msg));

		} else {
			$this->assertTrue(true); // Make the test un-risky.
		}
	}
}

