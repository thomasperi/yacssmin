<?php

// $ ./vendor/bin/phpbench run benchmarks/YaCSSMinBench.php --report=default

include_once __DIR__ . '/../src/Minifier.php';

class YaCSSMinBench {
	private $css;
	
	public function __construct() {
		$this->css = file_get_contents(__DIR__ . '/biggish-file.css');
	}
	
    /**
     * @Revs(100)
     * @Iterations(5)
     */
	public function benchYaCSSMin() {
		\ThomasPeri\YaCSSMin\Minifier::minify($this->css);
	}

    /**
     * @Revs(100)
     * @Iterations(5)
     */
	public function benchYaCSSMinComments() {
		\ThomasPeri\YaCSSMin\Minifier::minify($this->css, ['comments' => true]);
	}
}
