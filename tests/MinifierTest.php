<?php
namespace ThomasPeri\YaCSSMin\Test;

class MinifierTest extends \PHPUnit\Framework\TestCase {

	function minify($css) {
		return \ThomasPeri\YaCSSMin\Minifier::minify($css);
	}
	
	function test_a() {
		$this->assertEquals(
			'.hello{z-index:0}',
			$this->minify("/* hello */\n\n.hello {\n\tz-index: 0;\n}\n\n")
		);
	}
	
	function test_minify() {
		$this->assertEquals(
			'.hello{font-size:20px}',
			$this->minify("/* hello */\n\n.hello {\n\tfont-size: 20px;\n}\n\n")
		);
	}

	function test_minify_empty_block() {
		$this->assertEquals(
			'',
			$this->minify("/* hello */\n\n.hello {}\n\n")
		);
	}

	function test_minify_empty_block_comment() {
		$this->assertEquals(
			'',
			$this->minify("/* hello */\n\n.hello {/* nothing here */}\n\n")
		);
	}

	function test_minify_empty_block_whitespace() {
		$this->assertEquals(
			'',
			$this->minify("/* hello */\n\n.hello {   }\n\n")
		);
	}

	function test_minify_empty_block_whitespace_comment() {
		$this->assertEquals(
			'',
			$this->minify("/* hello */\n\n.hello { /* nothing here */}\n\n")
		);
	}

	function test_minify_empty_block_comment_whitespace_comment() {
		$this->assertEquals(
			'',
			$this->minify("/* hello */\n\n.hello {/* nothing here */ }\n\n")
		);
	}

	function test_minify_empty_block_after_brace() {
		$this->assertEquals(
			'.a{text-align:center}.c{line-height:100%}',
			$this->minify(".a { text-align  :    center; } .b { } .c {line-height: 100%}")
		);
	}

	function test_minify_url() {
		$this->assertEquals(
			'.a{background-image:url(/one/two/three.png)}',
			$this->minify(".a { background-image  :url(/one/two/three.png); }")
		);
	}

	function test_minify_url_quotes() {
		$this->assertEquals(
			".a{background-image:url('/one/two/three.png')}",
			$this->minify(".a { background-image: url('/one/two/three.png'); }")
		);
		$this->assertEquals(
			'.a{background-image:url("/one/two/three.png")}',
			$this->minify('.a { background-image: url("/one/two/three.png"); }')
		);
	}
	
	function test_colons_in_selector() {
		$this->assertEquals(
			".a :first-child{z-index:1}",
			$this->minify(".a :first-child { z-index: 1; }")
		);

		// Don't accidentally fix broken CSS
		$this->assertEquals(
			".a : first-child{z-index:1}",
			$this->minify(".a : first-child { z-index: 1; }")
		);
		$this->assertEquals(
			".a: first-child{z-index:1}",
			$this->minify(".a: first-child { z-index: 1; }")
		);
	}

	function test_colons_in_media_expression() {
		$expected = "@media only screen and (max-width:600px){.p :first-child{z-index:0}}";
		
		$this->assertEquals($expected, $this->minify(
			"@media only screen and (max-width: 600px) { .p :first-child { z-index: 0; } }"
		));
		$this->assertEquals($expected, $this->minify(
			"@media only screen and ( max-width : 600px ) { .p :first-child { z-index : 0; } }  "
		));
	}

	function test_nested_empties() {
		$expected = "p{z-index:0}";
		$this->assertEquals($expected, $this->minify(
			"p {z-index : 0} .a :first-child { @media (max-width: 600px) { /* foo */ } }"
		));
		$this->assertEquals($expected, $this->minify(
			".a :first-child { @media (max-width: 600px) { /* foo */ } } p {z-index : 0} "
		));
	}
}

