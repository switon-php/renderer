<?php

declare(strict_types=1);

namespace Switon\Rendering;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\PreconditionException;
use Switon\Core\PathAliasInterface;
use Switon\Core\Runtime;
use Switon\Rendering\Event\RendererRendered;
use Switon\Rendering\Event\RendererRendering;
use Switon\Rendering\Exception\ReservedVariableException;
use Switon\Sync\MutexInterface;
use Swoole\Coroutine;
use Throwable;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function dirname;
use function implode;
use function ob_get_clean;
use function ob_start;
use function realpath;
use function str_contains;
use function str_replace;
use function strtr;
use function trigger_error;

/**
 * Default implementation of `RendererInterface` for one complete render transaction.
 *
 * Use when template rendering should execute templates against one shared `Frames` transaction and return the completed frames.
 * Guidance: `Renderer` returns `Frames`, and the same object is also injected into templates as `$__frames`.
 *
 * Road-signs:
 * - render() for complete transactions
 * - renderFragment() for partials inside an active render
 * - theme-aware lookup in findTemplateFile()
 * - RendererRendering/RendererRendered events
 *
 * @see \Switon\Rendering\RendererInterface
 * @see \Switon\Rendering\Frames
 */
class Renderer implements RendererInterface
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected PathAliasInterface $pathAlias;
    #[Autowired] protected MutexInterface $mutex;

    /** @var array<string, EngineInterface> */
    #[Autowired(instances: true)] protected array $engines
        = ['.phtml' => 'Switon\Rendering\Engine\Php', '.sword' => 'Switon\Rendering\Engine\Sword'];

    /** @var array<string, array{0: string, 1: string}> */
    protected array $files = [];
    /** @var array<int, string> */
    protected array $templates = [];
    protected bool $captureActive = false;
    protected ?int $captureOwnerId = null;

    /**
     * Renders one full template transaction and returns completed frames.
     */
    public function render(string $template, array $vars = [], ?Frames $frames = null): Frames
    {
        $frames = $this->prepareFrames($frames, $vars);
        $frames->beginRender();

        try {
            $content = $this->capture(function () use ($frames, $template): void {
                $this->executeTemplate($frames, $template, $frames->all());
            });
        } catch (Throwable $e) {
            $frames->abortRender();
            throw $e;
        }

        $frames->endRender($content);
        return $frames;
    }

    /**
     * Checks whether a template can be resolved for any configured engine.
     */
    public function exists(string $template): bool
    {
        $template = $this->pathAlias->resolve($template);

        try {
            $this->findTemplateFile(Frames::of(), $template);
            return true;
        } catch (FileNotFoundException) {
            return false;
        }
    }

    /**
     * Renders a partial or nested template into the current output buffer segment.
     *
     * @param array<string, mixed> $vars
     */
    public function renderFragment(Frames $frames, string $template, array $vars = []): string
    {
        if (!$frames->isRendering()) {
            PreconditionException::raise('Cannot render partial "{template}": no active render transaction exists.', ['template' => $template]);
        }

        return $this->captureSegment(function () use ($frames, $template, $vars): void {
            $this->executeTemplate($frames, $template, $vars);
        });
    }

    /**
     * Executes one resolved template file through its matched engine.
     *
     * @param array<string, mixed> $vars
     */
    protected function executeTemplate(Frames $frames, string $template, array $vars): void
    {
        $template = $this->normalizeTemplatePath($template);
        [$file, $extension] = $this->findTemplateFile($frames, $template);
        $engine = $this->engines[$extension];
        $originalVars = $frames->all();

        $this->pushTemplate($template);
        try {
            $frames->setVars([...$originalVars, ...$vars]);
            $this->eventDispatcher->dispatch(new RendererRendering($this, $template, $file, $vars));
            $engine->render($file, $frames);
            $this->eventDispatcher->dispatch(new RendererRendered($this, $template, $file, $vars));
        } finally {
            $frames->setVars($originalVars);
            $this->popTemplate();
        }
    }

    /**
     * Normalizes template references for relative includes and platform path differences.
     */
    protected function normalizeTemplatePath(string $template): string
    {
        if (DIRECTORY_SEPARATOR === '\\' && str_contains($template, '\\')) {
            $template = str_replace('\\', '/', $template);
        }

        if (!str_contains($template, '/')) {
            $current = $this->currentTemplate();
            if ($current !== null) {
                $template = dirname($current) . '/' . $template;
            }
        }

        return $this->pathAlias->resolve($template);
    }

    /**
     * Resolves the actual template file and engine extension, including theme overrides.
     *
     * @return array{0: string, 1: string}
     */
    protected function findTemplateFile(Frames $frames, string $template): array
    {
        $cacheKey = $template . (($frames->getTheme() ?? '') !== '' ? '|theme:' . $frames->getTheme() : '');
        if (isset($this->files[$cacheKey])) {
            return $this->files[$cacheKey];
        }

        $file = null;
        $extension = null;
        $theme = $frames->getTheme();

        if ($theme && str_contains($template, '/View/')) {
            $themeTemplate = str_replace('/View/', '/themes/' . $theme . '/View/', $template);
            foreach ($this->engines as $ext => $_) {
                if (is_file($tmp = $themeTemplate . $ext)) {
                    if (PHP_EOL !== "\n") {
                        $realPath = strtr(realpath($tmp), '\\', '/');
                        if ($tmp !== $realPath) {
                            trigger_error("File name ($realPath) case mismatch for $tmp", E_USER_ERROR);
                        }
                    }
                    $file = $tmp;
                    $extension = $ext;
                    break;
                }
            }
        }

        if ($file === null) {
            foreach ($this->engines as $ext => $_) {
                if (is_file($tmp = $template . $ext)) {
                    if (PHP_EOL !== "\n") {
                        $realPath = strtr(realpath($tmp), '\\', '/');
                        if ($tmp !== $realPath) {
                            trigger_error("File name ($realPath) case mismatch for $tmp", E_USER_ERROR);
                        }
                    }
                    $file = $tmp;
                    $extension = $ext;
                    break;
                }
            }
        }

        if ($file === null || $extension === null) {
            $extensions = implode(', or ', array_keys($this->engines));
            FileNotFoundException::raise(
                'Template "{template}" with extensions "{extensions}" not found.',
                ['template' => $template, 'extensions' => $extensions]
            );
        }

        return $this->files[$cacheKey] = [$file, $extension];
    }

    /**
     * Prepares frames for one render call and binds renderer runtime state.
     *
     * @param array<string, mixed> $vars
     */
    protected function prepareFrames(?Frames $frames, array $vars): Frames
    {
        $prepared = $frames ?? Frames::of();
        $this->assertNoReservedVars($prepared->all());
        $this->assertNoReservedVars($vars);
        $prepared->attachRenderer($this);
        $prepared->merge($vars);

        return $prepared;
    }

    /**
     * Rejects reserved template variable names before template execution.
     *
     * @param array<string, mixed> $vars
     */
    protected function assertNoReservedVars(array $vars): void
    {
        if (array_key_exists('__frames', $vars)) {
            ReservedVariableException::raise('Cannot use reserved template variable name "$__frames".');
        }
    }

    /**
     * Captures the outer render output buffer with ownership protection.
     *
     * @param callable(): void $callback
     */
    protected function capture(callable $callback): string
    {
        if ($this->isOwnedByCurrentContext()) {
            PreconditionException::raise(
                'Cannot start nested render capture in the same execution context. Finish the current render before starting another one.'
            );
        }

        $this->mutex->lock();
        $this->captureActive = true;
        $this->captureOwnerId = $this->currentOwnerId();
        $throwable = null;

        ob_start();

        try {
            $callback();
        } catch (Throwable $e) {
            $throwable = $e;
        } finally {
            $output = (string)ob_get_clean();
            $this->templates = [];
            $this->captureActive = false;
            $this->captureOwnerId = null;
            $this->mutex->unlock();
        }

        if ($throwable !== null) {
            throw $throwable;
        }

        return $output;
    }

    /**
     * Captures a nested output segment inside an existing render transaction.
     *
     * @param callable(): void $callback
     */
    protected function captureSegment(callable $callback): string
    {
        ob_start();

        try {
            $callback();
        } catch (Throwable $e) {
            ob_get_clean();
            throw $e;
        }

        return (string)ob_get_clean();
    }

    protected function isOwnedByCurrentContext(): bool
    {
        if (!$this->captureActive) {
            return false;
        }

        $ownerId = $this->currentOwnerId();
        return $ownerId === null || $this->captureOwnerId === $ownerId;
    }

    protected function currentOwnerId(): ?int
    {
        if (!Runtime::isCoroutineEnabled() || !class_exists(Coroutine::class)) {
            return null;
        }

        $cid = Coroutine::getCid();
        return $cid >= 0 ? $cid : null;
    }

    protected function pushTemplate(string $template): void
    {
        $this->templates[] = $template;
    }

    protected function popTemplate(): void
    {
        array_pop($this->templates);
    }

    protected function currentTemplate(): ?string
    {
        return $this->templates === [] ? null : $this->templates[array_key_last($this->templates)];
    }

}
