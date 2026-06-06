<?php

declare(strict_types=1);

namespace Switon\Rendering\Engine;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Engine\Sword\Compiler;
use Switon\Rendering\EngineInterface;
use Switon\Rendering\Frames;

use function extract;
use function file_exists;
use function filemtime;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Compiled renderer for `.sword` templates.
 *
 * Use when templates should be compiled to PHP and cached under `@runtime/sword`.
 *
 * Road-signs:
 * - getCompiledFile() for source => compiled path resolution
 * - render() for compiled template execution
 */
class Sword implements EngineInterface
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected Compiler $swordCompiler;
    #[Autowired] protected PathAliasInterface $pathAlias;

    protected string $doc_root;
    /** @var array<string, string> */
    protected array $compiled = [];

    public function __construct(?string $doc_root = null)
    {
        $this->doc_root = $doc_root ?? $_SERVER['DOCUMENT_ROOT'];
    }

    /**
     * Resolves and refreshes the compiled PHP file for one `.sword` source template.
     */
    public function getCompiledFile(string $source): string
    {
        $root = $this->pathAlias->resolve('@root');
        if (str_starts_with($source, $root)) {
            $compiled = '@runtime/sword' . substr($source, strlen($root));
        } elseif ($this->doc_root !== '' && str_starts_with($source, $this->doc_root)) {
            $compiled = '@runtime/sword/' . substr($source, strlen($this->doc_root));
        } else {
            $compiled = "@runtime/sword/$source";
            if (DIRECTORY_SEPARATOR === '\\') {
                $compiled = str_replace(':', '_', $compiled);
            }
        }

        $compiled = $this->pathAlias->resolve($compiled);

        if ($this->app->isDebug() || !file_exists($compiled) || filemtime($source) > filemtime($compiled)) {
            $this->swordCompiler->compileFile($source, $compiled);
        }

        return $compiled;
    }

    /**
     * Executes the compiled PHP template for one `.sword` source file.
     */
    public function render(string $file, Frames $frames): void
    {
        $__frames = $frames;
        extract($frames->all(), EXTR_SKIP);

        $this->compiled[$file] ??= $this->getCompiledFile($file);

        require $this->compiled[$file];
    }
}
