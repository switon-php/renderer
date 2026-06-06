<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Engine\Sword;

use PHPUnit\Framework\TestCase;
use Switon\Rendering\Engine\Sword\Loop;

class LoopTest extends TestCase
{
    public function testStepUpdatesAllProperties(): void
    {
        // Arrange
        $loop = new Loop([1, 2, 3]);

        // Act - first iteration
        $loop->step();

        // Assert
        $this->assertSame(0, $loop->index, 'First iteration index should be 0');
        $this->assertSame(1, $loop->iteration, 'First iteration count should be 1');
        $this->assertTrue($loop->first, 'First iteration should be first');
        $this->assertFalse($loop->last, 'First of 3 should not be last');
        $this->assertTrue($loop->odd, 'Iteration 1 should be odd');
        $this->assertFalse($loop->even, 'Iteration 1 should not be even');
        $this->assertSame(2, $loop->remaining, 'Should have 2 remaining');
        $this->assertSame(3, $loop->count, 'Count should be 3');
        $this->assertSame(1, $loop->depth, 'Top-level depth should be 1');
        $this->assertNull($loop->parent, 'Top-level parent should be null');
    }

    public function testStepLastIteration(): void
    {
        // Arrange
        $loop = new Loop([1, 2]);
        $loop->step(); // first

        // Act
        $loop->step(); // second (last)

        // Assert
        $this->assertSame(1, $loop->index, 'Second iteration index should be 1');
        $this->assertSame(2, $loop->iteration, 'Second iteration count should be 2');
        $this->assertFalse($loop->first, 'Second iteration should not be first');
        $this->assertTrue($loop->last, 'Last iteration should be last');
        $this->assertTrue($loop->even, 'Iteration 2 should be even');
        $this->assertSame(0, $loop->remaining, 'Should have 0 remaining');
    }

    public function testNestedLoopDepthAndParent(): void
    {
        // Arrange
        $outer = new Loop([1, 2, 3]);
        $inner = new Loop(['a', 'b'], $outer);

        // Assert
        $this->assertSame(1, $outer->depth, 'Outer loop depth should be 1');
        $this->assertSame(2, $inner->depth, 'Inner loop depth should be 2');
        $this->assertSame($outer, $inner->parent, 'Inner loop parent should be outer loop');
    }

    public function testUncountableItems(): void
    {
        // Arrange - generator is not countable
        $generator = (function () {
            yield 1;
            yield 2;
        })();
        $loop = new Loop($generator);

        // Act
        $loop->step();

        // Assert
        $this->assertNull($loop->count, 'Uncountable items should have null count');
        $this->assertNull($loop->remaining, 'Uncountable items should have null remaining');
        $this->assertFalse($loop->last, 'Cannot determine last for uncountable items');
    }

    public function testSingleItemLoop(): void
    {
        // Arrange
        $loop = new Loop(['only']);

        // Act
        $loop->step();

        // Assert
        $this->assertTrue($loop->first, 'Single item should be first');
        $this->assertTrue($loop->last, 'Single item should be last');
        $this->assertSame(0, $loop->remaining, 'Single item should have 0 remaining');
    }
}
