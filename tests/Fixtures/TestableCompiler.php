<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Fixtures;

use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Engine\Sword\Compiler;

class TestableCompiler extends Compiler
{
    public function withFilesystem(FilesystemInterface $filesystem): static
    {
        $this->filesystem = $filesystem;
        return $this;
    }

    public function withPathAlias(PathAliasInterface $pathAlias): static
    {
        $this->pathAlias = $pathAlias;
        return $this;
    }

    public function withHashLength(int $hashLength): static
    {
        $this->hash_length = $hashLength;
        return $this;
    }

    public function withRawTags(array $tags): static
    {
        $this->rawTags = $tags;
        return $this;
    }

    public function withEscapedTags(array $tags): static
    {
        $this->escapedTags = $tags;
        return $this;
    }

    public function compileCommentsPublic(string $value): string
    {
        return $this->compileComments($value);
    }

    public function compileRawEchosPublic(string $value): string
    {
        return $this->compileRawEchos($value);
    }

    public function compileEscapedEchosPublic(string $value): string
    {
        return $this->compileEscapedEchos($value);
    }

    public function compileEchosPublic(string $value): string
    {
        return $this->compileEchos($value);
    }

    public function getEchoMethodsPublic(): array
    {
        return $this->getEchoMethods();
    }

    public function compileStatementsPublic(string $value): string
    {
        $foreachelseStack = [];
        $switchStack = [];
        return $this->compileStatements($value, $foreachelseStack, $switchStack);
    }

    public function compileForeachPublic(string $expression, array &$stack): string
    {
        return $this->compile_foreach($expression, $stack);
    }

    public function compileForeachElsePublic(?string $expression, array &$stack): string
    {
        return $this->compile_foreachElse($expression, $stack);
    }

    public function compileEndForeachPublic(?string $expression, array &$stack): string
    {
        return $this->compile_endForeach($expression, $stack);
    }

    public function compileSwitchPublic(?string $expression, array &$stack): string
    {
        return $this->compile_switch($expression, $stack);
    }

    public function compileCasePublic(?string $expression, array &$stack): string
    {
        return $this->compile_case($expression, $stack);
    }

    public function compileDefaultPublic(?string $expression, array &$stack): string
    {
        return $this->compile_default($expression, $stack);
    }

    public function compileEndSwitchPublic(?string $expression, array &$stack): string
    {
        return $this->compile_endswitch($expression, $stack);
    }

    public function compileAllowPublic(string $expression): string
    {
        return $this->compile_allow($expression);
    }

    public function compileCannotPublic(string $expression): string
    {
        return $this->compile_cannot($expression);
    }

    public function compileUnlessPublic(string $expression): string
    {
        return $this->compile_unless($expression);
    }

    public function compileIssetPublic(string $expression): string
    {
        return $this->compile_isset($expression);
    }

    public function compileEmptyPublic(string $expression): string
    {
        return $this->compile_empty($expression);
    }

    public function compilePartialPublic(string $expression): string
    {
        return $this->compile_partial($expression);
    }

    public function compileBlockPublic(string $expression): string
    {
        return $this->compile_block($expression);
    }

    public function compileYieldPublic(string $expression): string
    {
        return $this->compile_yield($expression);
    }

    public function compileSectionPublic(string $expression): string
    {
        return $this->compile_section($expression);
    }

    public function compileAppendPublic(): string
    {
        return $this->compile_append();
    }

    public function compileEndSectionPublic(): string
    {
        return $this->compile_endSection();
    }

    public function compileEndPhpPublic(): string
    {
        return $this->compile_endPhp();
    }

    public function compileEndOncePublic(): string
    {
        return $this->compile_endonce();
    }

    public function compileOncePublic(): string
    {
        return $this->compile_once();
    }

    public function compileWidgetPublic(string $expression): string
    {
        return $this->compile_widget($expression);
    }

    public function compileMaxAgePublic(string $expression): string
    {
        return $this->compile_maxAge($expression);
    }

    public function addFileHashPublic(string $value): string
    {
        return $this->addFileHash($value);
    }

    public function compileLayoutPublic(string $expression): string
    {
        return $this->compile_layout($expression);
    }

    public function compileInjectPublic(string $expression): string
    {
        return $this->compile_inject($expression);
    }

    public function compilePhpPublic(string $expression): string
    {
        return $this->compile_php($expression);
    }

    public function compileBreakPublic(?string $expression): string
    {
        return $this->compile_break($expression);
    }

    public function compileContinuePublic(?string $expression): string
    {
        return $this->compile_continue($expression);
    }

    public function compileContentPublic(): string
    {
        return $this->compile_content();
    }

    public function compileFlashPublic(): string
    {
        return $this->compile_flash();
    }

    public function compilePushPublic(string $expression): string
    {
        return $this->compile_push($expression);
    }

    public function compileStackPublic(string $expression): string
    {
        return $this->compile_stack($expression);
    }

    public function compileCanPublic(string $expression): string
    {
        return $this->compile_can($expression);
    }

    public function compileIfPublic(string $expression): string
    {
        return $this->compile_if($expression);
    }

    public function compileElseIfPublic(string $expression): string
    {
        return $this->compile_elseif($expression);
    }

    public function compileElsePublic(): string
    {
        return $this->compile_else();
    }

    public function compileForPublic(string $expression): string
    {
        return $this->compile_for($expression);
    }

    public function compileWhilePublic(string $expression): string
    {
        return $this->compile_while($expression);
    }

    public function compileEndWhilePublic(): string
    {
        return $this->compile_endWhile();
    }

    public function compileEndForPublic(): string
    {
        return $this->compile_endFor();
    }

    public function compileEndCanPublic(): string
    {
        return $this->compile_endCan();
    }

    public function compileEndCannotPublic(): string
    {
        return $this->compile_endCannot();
    }

    public function compileEndIfPublic(): string
    {
        return $this->compile_endIf();
    }

    public function compileEndUnlessPublic(): string
    {
        return $this->compile_endunless();
    }

    public function compileEndIssetPublic(): string
    {
        return $this->compile_endisset();
    }

    public function compileEndEmptyPublic(): string
    {
        return $this->compile_endempty();
    }

    public function compileEndPushPublic(): string
    {
        return $this->compile_endpush();
    }
}
