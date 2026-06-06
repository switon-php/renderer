<?php

declare(strict_types=1);

namespace Switon\Rendering\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Engine\Sword\Compiler;

use function str_replace;

/**
 * Precompiles Sword templates to warm renderer cache.
 *
 * Use when preparing compiled `.sword` output ahead of requests or deployments.
 *
 * @see \Switon\Rendering\Engine\Sword\Compiler
 */
class SwordCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected Compiler $swordCompiler;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /**
     * Compiles discovered `.sword` templates.
     *
     * @param bool $replace Write .phtml next to source files when true
     */
    public function compileAction(bool $replace = false): void
    {
        $this->filesystem->rmdir('@runtime/sword');
        $this->console->writeLn('delete "@runtime/sword" directory success');

        $ext = 'sword';

        foreach ($this->filesystem->glob("@app/View/*.$ext") as $item) {
            $this->compile($item, $replace);
        }

        foreach ($this->filesystem->glob("@app/View/*/*.$ext") as $item) {
            $this->compile($item, $replace);
        }

        foreach ($this->filesystem->glob("@app/Areas/*/View/*/*.$ext") as $item) {
            $this->compile($item, $replace);
        }

        foreach ($this->filesystem->glob("@app/Areas/*/View/*.$ext") as $item) {
            $this->compile($item, $replace);
        }
    }

    /**
     * Compiles one template file to its target location.
     *
     * @param string $file Source `.sword` template file
     * @param bool $replace When true, output `.phtml` beside source
     */
    protected function compile(string $file, bool $replace): void
    {
        if ($replace) {
            $compiled = str_replace('.sword', '.phtml', $file);
        } else {
            $compiled = str_replace($this->pathAlias->resolve('@root'), $this->pathAlias->resolve('@runtime/sword'), $file);
        }

        $this->swordCompiler->compileFile($file, $compiled);

        $this->console->writeLn("compiled `$compiled` file generated");
    }
}
