<?php

/**
 * @covers ConverterRule
 */
class ConverterRuleTest extends MediaWikiTestCase {

	public function setUp() : void {
		parent::setUp();
		$this->setMwGlobals( 'wgUser', new User );
	}

	public function testParseEmpty() {
		$converter = new EnConverter( new Language() );
		$rule = new ConverterRule( '', $converter );
		$rule->parse();

		$this->assertSame( false, $rule->getTitle(), 'title' );
		$this->assertSame( [], $rule->getConvTable(), 'conversion table' );
		$this->assertSame( 'none', $rule->getRulesAction(), 'rules action' );
	}

}
