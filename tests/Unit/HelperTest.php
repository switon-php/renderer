<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit;

use Switon\Rendering\Tests\TestCase;

use function e;

class HelperTest extends TestCase
{
    public function testEHelperEscapesHtml(): void
    {
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', e('<b>hi</b>'));
    }

    public function testEHelperKeepsNullAsStringNull(): void
    {
        $this->assertSame('null', e(null));
    }

    public function testEHelperWithDoubleEncodeFalseLeavesExistingEntitiesUnescapedAgain(): void
    {
        $alreadyEncoded = '&lt;span&gt;x&lt;/span&gt;';

        $this->assertSame($alreadyEncoded, e($alreadyEncoded, false));
    }

    public function testEHelperEscapesQuotesWithDoubleEncodeDefault(): void
    {
        $this->assertSame('say &quot;hi&quot;', e('say "hi"'));
    }

    public function testEHelperEscapesSingleQuote(): void
    {
        $this->assertSame('it&#039;s fine', e("it's fine"));
    }
}
