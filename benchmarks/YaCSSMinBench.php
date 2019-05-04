<?php

// $ ./vendor/bin/phpbench run benchmarks/YaCSSMinBench.php --report=default

include_once __DIR__ . '/../src/Minifier.php';

class YaCSSMinBench {
    /**
     * @Revs(200)
     * @Iterations(5)
     */
	public function benchYaCSSMin() {
		$css = file_get_contents(__DIR__ . '/biggish-file.css');
		\ThomasPeri\YaCSSMin\Minifier::minify($css);
	}
}
