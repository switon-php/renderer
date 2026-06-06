# Switon Renderer Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/renderer/ci.yml?branch=main&label=CI)](https://github.com/switon-php/renderer/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Template rendering with shared `Frames`, `.phtml` and `.sword` templates, and theme-aware lookup for Switon Framework.

## Highlights

- **Shared frames:** compose layout and partial data with `Frames` before rendering.
- **Multiple engines:** render `.phtml` and `.sword` templates through one `RendererInterface`.
- **Theme-aware lookup:** resolve views with theme and alias boundaries.

## Installation

```bash
composer require switon/renderer
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Rendering\Frames;
use Switon\Rendering\RendererInterface;

class WelcomePage
{
    #[Autowired] protected RendererInterface $renderer;

    public function render(array $user): string
    {
        $frames = Frames::of(['title' => 'Welcome', 'user' => $user]);

        return $this->renderer->render('@app/View/home', [], $frames)->content();
    }
}
```

Docs: https://docs.switon.dev/latest/renderer

## License

MIT.
