<?php

declare(strict_types=1);

namespace Switon\Rendering;

/**
 * Contract for complete template rendering transactions.
 *
 * Use when one render call should fully execute a template and return a `Frames` result.
 * Guidance: Pass per-call vars separately from the optional shared `Frames` transaction; partials, sections, stacks, and theme belong to `$__frames`.
 *
 * Road-signs:
 * - render() for full template transactions
 * - exists() for view lookup checks
 * - Frames for shared render runtime
 *
 * @see \Switon\Rendering\Renderer
 * @see \Switon\Rendering\Frames
 */
interface RendererInterface
{
    /**
     * Renders one template and returns the completed frames transaction.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = [], ?Frames $frames = null): Frames;

    /**
     * Checks whether a template can be resolved by the configured engines.
     */
    public function exists(string $template): bool;
}
