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
	
	function special($name, &$exp, &$src) {
		$prefix = __DIR__ . '/test-data/_special/' . $name;
		$exp = file_get_contents($prefix . '/exp.css');
		$src = file_get_contents($prefix . '/src.css');
	}
	
	function comments($name, $filter) {
		$this->special($name, $exp, $src);
		$result = $this->minify($src, ['comments' => $filter]);
		$this->assertEquals($exp, $result);
	}
	
	function test_comments() {
		$this->comments('comments-none', function ($comment) {
			return false;
		});
		$this->comments('comments-all', function ($comment) {
			return $comment;
		});
		
		$keep = function ($comment) {
			if (false !== strpos($comment, '@keep')) {
				return $comment;
			}
		};
		$this->comments('comments', $keep);
		$this->comments('comments-indent', $keep);
		$this->comments('comments-weird', $keep);
		$this->comments('comments-empty-blocks', $keep);
		$this->comments('comments-empty-blocks-nested', $keep);
	}
	
	function test_filter() {
		$prefix = __DIR__ . '/test-data/_special/filter';
		$exp = file_get_contents($prefix . '/exp.css');
		$src = file_get_contents($prefix . '/src.css');
		$result = $this->minify($src, ['filter' => function ($css, &$strings) {
			$css = preg_replace('#\bb\b#', 'strong', $css);
			foreach ($strings as $i => $v) {
				$strings[$i] = strrev($strings[$i]);
			}
			return $css;
		}]);
		$this->assertEquals($exp, $result);
	}

	function test_unbalanced() {
		// Missing brace
		$this->assertFalse($this->minify(
			'@media (min-width: 300px) { .foo { color: red; }'
		));
		
		// Extra brace
		$this->assertFalse($this->minify(
			'@media (min-width: 300px) { .foo { color: red; }}}'
		));
		
		// Missing bracket
		$this->assertFalse($this->minify(
			'.foo [name=bar { color: red; }'
		));

		// Extra bracket
		$this->assertFalse($this->minify(
			'.foo [name=bar]] { color: red; }'
		));

		// Wrong delimiter opening
		$this->assertFalse($this->minify(
			'.foo { top: calc( 100px + [ 2em - 1rem ) ); }'
		));

		// Wrong delimiter closing
		$this->assertFalse($this->minify(
			'.foo { top: calc( 100px + ( 2em - 1rem ] ); }'
		));

		// Unclosed double quote
		$this->assertFalse($this->minify(
			'.foo:before { content: "foo; }'
		));

		// Unclosed single quote
		$this->assertFalse($this->minify(
			".foo:before { content: 'foo; }"
		));

		// Unclosed single quote because of escape
		$this->assertFalse($this->minify(
			'.foo:before { content: "foo\\"; }'
		));

		// Unclosed single quote because of escape
		$this->assertFalse($this->minify(
			".foo:before { content: 'bar\\'; }"
		));

		// Unescaped newline
		$this->assertFalse($this->minify(
			".foo:before { content: 'foo\nbar'; }"
		));
		// Contrast with escaped newline working
		$this->assertEquals(
			".foo:before{content:'foo\\\nb * ar'}",
			$this->minify(
				".foo:before { content: 'foo\\\nb * ar'; }"
			)
		);
	}

}

