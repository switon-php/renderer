<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Fixtures;

use Switon\Core\AppInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Engine\Sword;
use Switon\Rendering\Engine\Sword\Compiler;

class TestableSword extends Sword
{
    public int $compiledFileLookups = 0;
    protected ?string $compiledFileOverride = null;

    public function withApp(AppInterface $app): static
    {
        $this->app = $app;
        return $this;
    }

    public function withCompiler(Compiler $compiler): static
    {
        $this->swordCompiler = $compiler;
        return $this;
    }

    public function withPathAlias(PathAliasInterface $pathAlias): static
    {
        $this->pathAlias = $pathAlias;
        return $this;
    }

    public function withCompiledFileOverride(?string $compiledFile): static
    {
        $this->compiledFileOverride = $compiledFile;
        return $this;
    }

    public function getCompiledFile(string $source): string
    {
        $this->compiledFileLookups++;

        return $this->compiledFileOverride ?? parent::getCompiledFile($source);
    }
}
