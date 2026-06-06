<?php

declare(strict_types=1);

namespace Switon\Rendering;

use Switon\Core\Exception\PreconditionException;
use Switon\Core\Exception\RuntimeException;

use function array_key_exists;
use function array_pop;
use function implode;
use function ob_get_clean;
use function ob_start;

/**
 * Default mutable render transaction object.
 *
 * Use when render input, shared state, template helpers, and final output should travel through one object.
 * Guidance: `Frames` is both the render result and the injected `$__frames` runtime inside templates.
 *
 * Road-signs:
 * - vars/content/theme for shared render state
 * - partial() for nested template rendering
 * - section()/startSection()/stopSection()
 * - stack()/startStack()/stopStack()
 * - once() for one-time template blocks
 *
 * @see \Switon\Rendering\RendererInterface
 */
class Frames
{
    /** @var array<string, mixed> */
    protected array $vars = [];
    protected string $content = '';
    /** @var array<string, string> */
    protected array $sections = [];
    /** @var array<string, array<int, string>> */
    protected array $stacks = [];
    /** @var array<int, array{type: string, name: string}> */
    protected array $captures = [];
    /** @var array<string, true> */
    protected array $once = [];
    protected bool $rendering = false;
    protected ?string $theme = null;
    protected ?RendererInterface $renderer = null;

    /**
     * Creates a frames transaction with initial variables.
     *
     * @param array<string, mixed> $vars
     */
    public static function of(array $vars = []): static
    {
        $frames = new static();
        $frames->vars = $vars;
        return $frames;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->vars;
    }

    /**
     * Merges variables into the current frames transaction.
     *
     * @param array<string, mixed> $vars
     */
    public function merge(array $vars): static
    {
        $this->vars = [...$this->vars, ...$vars];
        return $this;
    }

    /**
     * Replaces the current variable set.
     *
     * @param array<string, mixed> $vars
     */
    public function setVars(array $vars): static
    {
        $this->vars = $vars;
        return $this;
    }

    /** @internal Renderer runtime only. */
    public function attachRenderer(RendererInterface $renderer): static
    {
        $this->renderer = $renderer;
        return $this;
    }

    /** @internal Renderer runtime only. */
    public function beginRender(): void
    {
        if ($this->rendering) {
            PreconditionException::raise('Cannot begin render: this Frames transaction is already active.');
        }

        $this->rendering = true;
        $this->captures = [];
    }

    /** @internal Renderer runtime only. */
    public function endRender(string $content): void
    {
        $this->content = $content;
        $this->rendering = false;
        $this->captures = [];
    }

    /** @internal Renderer runtime only. */
    public function abortRender(): void
    {
        $this->rendering = false;
        $this->captures = [];
    }

    /** @internal Renderer runtime only. */
    public function isRendering(): bool
    {
        return $this->rendering;
    }

    /**
     * Sets the active theme used during template lookup.
     */
    public function setTheme(?string $theme): static
    {
        $this->theme = $theme;
        return $this;
    }

    /**
     * Returns the active theme for template lookup.
     */
    public function getTheme(): ?string
    {
        return $this->theme;
    }

    /**
     * Returns the final rendered content.
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Overrides the stored final content.
     */
    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Returns one named section, or the provided default when missing.
     */
    public function section(string $section, string $default = ''): string
    {
        return $this->sections[$section] ?? $default;
    }

    /**
     * Checks whether a named section has been captured.
     */
    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->sections);
    }

    /**
     * Returns all captured fragments for one stack.
     *
     * @return array<int, string>
     */
    public function stack(string $name): array
    {
        return $this->stacks[$name] ?? [];
    }

    /**
     * Renders one captured stack with optional glue.
     */
    public function renderStack(string $name, string $glue = ''): string
    {
        return implode($glue, $this->stack($name));
    }

    /** @param array<string, mixed> $vars */
    /**
     * Renders a partial within the current transaction.
     *
     * @param array<string, mixed> $vars
     */
    public function partial(string $path, array $vars = []): void
    {
        if ($this->renderer === null) {
            RuntimeException::raise('Frames renderer runtime is not attached.');
        }

        echo $this->renderer->renderFragment($this, $path, $vars);
    }

    /**
     * Starts capturing a section, or seeds its default content.
     */
    public function startSection(string $section, ?string $default = null): void
    {
        if ($default !== null) {
            $this->sections[$section] = $default;
            return;
        }

        $this->ensureRenderRuntime('start section');
        ob_start();
        $this->captures[] = ['type' => 'section', 'name' => $section];
    }

    /**
     * Stops the current section capture and stores its content.
     */
    public function stopSection(bool $overwrite = false): void
    {
        $capture = $this->popCapture('section', 'stop section');
        $content = (string)ob_get_clean();

        if ($overwrite || !isset($this->sections[$capture['name']])) {
            $this->sections[$capture['name']] = $content;
        } else {
            $this->sections[$capture['name']] .= $content;
        }
    }

    /**
     * Stops the current section capture and appends to existing section content.
     */
    public function appendSection(): void
    {
        $capture = $this->popCapture('section', 'append section');
        $content = (string)ob_get_clean();
        $this->sections[$capture['name']] = ($this->sections[$capture['name']] ?? '') . $content;
    }

    /**
     * Starts capturing one stack fragment.
     */
    public function startStack(string $name): void
    {
        $this->ensureRenderRuntime('start stack');
        ob_start();
        $this->captures[] = ['type' => 'stack', 'name' => $name];
    }

    /**
     * Stops the current stack capture and appends its content.
     */
    public function stopStack(): void
    {
        $capture = $this->popCapture('stack', 'stop stack');
        $content = (string)ob_get_clean();
        $this->stacks[$capture['name']][] = $content;
    }

    /**
     * Marks a one-time template block and returns whether it should render now.
     */
    public function once(string $id): bool
    {
        if (isset($this->once[$id])) {
            return false;
        }

        $this->once[$id] = true;
        return true;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    protected function ensureRenderRuntime(string $action): void
    {
        if (!$this->rendering) {
            PreconditionException::raise('Cannot {action}: no active render transaction exists.', ['action' => $action]);
        }
    }

    /** @return array{type: string, name: string} */
    protected function popCapture(string $type, string $action): array
    {
        $this->ensureRenderRuntime($action);
        $capture = array_pop($this->captures);
        if (!is_array($capture) || ($capture['type'] ?? null) !== $type) {
            PreconditionException::raise('Cannot {action}: no active {type} frame exists.', ['action' => $action, 'type' => $type]);
        }

        return $capture;
    }
}
