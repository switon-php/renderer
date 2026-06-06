<?php

declare(strict_types=1);

namespace Switon\Rendering\Engine\Sword;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Exception\TemplateCompilationException;

use function count;
use function dirname;
use function in_array;
use function is_array;
use function str_starts_with;
use function strlen;

/**
 * Compiler for Sword templates.
 *
 * Use when converting `.sword` source into cached executable PHP.
 *
 * Supports escaped/raw echos, directives, comments, verbatim blocks, and
 * optional local static-file hash suffixing.
 *
 * @see \Switon\Rendering\Engine\Sword
 * @see \Switon\Rendering\Exception\TemplateCompilationException
 */
class Compiler
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected int $hash_length = 12;
    /** @var array<string, callable(string|null): string> */
    #[Autowired] protected array $directives = [];
    /** @var list<string> */
    #[Autowired] protected array $rawTags = ['{!!', '!!}'];
    /** @var list<string> */
    #[Autowired] protected array $escapedTags = ['{{', '}}'];
    #[Autowired] protected PathAliasInterface $pathAlias;

    /** @var list<string> */
    protected array $safe_functions
        = [
            'e',
            'url',
            'action',
            'asset',
            'csrf_token',
            'csrf_field',
            'date',
            'html',
            'attr_nv',
            'attr_inv',
            'partial',
            'Json::stringify'
        ];

    public function __construct(?string $safe_functions = null)
    {
        if ($safe_functions !== null) {
            /** @var list<string> $safeFunctions */
            $safeFunctions = preg_split('#[\s,]+#', $safe_functions, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $this->safe_functions = array_merge($this->safe_functions, $safeFunctions);
        }
    }

    protected function addFileHash(string $str): string
    {
        return preg_replace_callback(
            '#="(/[-\w/.]+\.\w+)"#',
            function ($match) {
                $url = $match[1];

                if (in_array(pathinfo($url, PATHINFO_EXTENSION), ['htm', 'html', 'php'], true)) {
                    return $match[0];
                }

                $path = '@public' . $url;
                $file = $this->pathAlias->resolve($path);
                if (!is_file($file)) {
                    return $match[0];
                }
                $hash = substr(md5_file($file), 0, $this->hash_length);

                return "=\"$url?v=$hash\"";
            },
            $str
        );
    }

    /**
     * Compile Sword template source text into PHP code.
     *
     * @param string $value Raw Sword template content
     *
     * @return string Compiled PHP code
     */
    public function compileString(string $value): string
    {
        // Process @verbatim blocks first - preserve their content without compilation
        $verbatimBlocks = [];
        $verbatimIndex = 0;

        $value = preg_replace_callback(
            '/@verbatim\s*(.*?)\s*@endverbatim/s',
            static function ($matches) use (&$verbatimBlocks, &$verbatimIndex) {
                $verbatimPlaceholder = '__VERBATIM_BLOCK_%d__';
                $placeholder = sprintf($verbatimPlaceholder, $verbatimIndex++);
                $verbatimBlocks[$placeholder] = $matches[1];
                return $placeholder;
            },
            $value
        );

        $result = '';
        // Local stacks for tracking block directives - created fresh for each compilation
        // These are not instance state, but local compilation context
        $foreachelseStack = [];
        $switchStack = [];
        $currentLine = 1;

        // Here we will loop through all the tokens returned by the Zend lexer and
        // parse each one into the corresponding valid PHP. We will then have this
        // template as the correctly rendered PHP that can be rendered natively.
        foreach (token_get_all($value) as $token) {
            if (is_array($token)) {
                [$id, $content, $line] = $token;

                // Add newlines to maintain line number correspondence
                if ($line > $currentLine) {
                    $result .= str_repeat("\n", $line - $currentLine);
                    $currentLine = $line;
                }

                if ($id === T_INLINE_HTML) {
                    $content = $this->compileStatements($content, $foreachelseStack, $switchStack);
                    $content = $this->compileComments($content);
                    $content = $this->compileEchos($content);
                }

                // Update current line based on content
                $newlines = substr_count($content, "\n");
                $currentLine += $newlines;
            } else {
                $content = $token;
                // Update current line for non-array tokens
                $newlines = substr_count($content, "\n");
                $currentLine += $newlines;
            }

            $result .= $content;
        }

        // Validate all block directives are properly closed
        if (!empty($foreachelseStack)) {
            TemplateCompilationException::raise(
                'Unclosed @foreach directive: {count} block(s) not terminated with @endforeach',
                ['count' => count($foreachelseStack)]
            );
        }

        if (!empty($switchStack)) {
            TemplateCompilationException::raise(
                'Unclosed @switch directive: {count} block(s) not terminated with @endswitch',
                ['count' => count($switchStack)]
            );
        }

        if ($this->hash_length) {
            $result = $this->addFileHash($result);
        }

        // Restore verbatim blocks - replace placeholders with original content
        foreach ($verbatimBlocks as $placeholder => $content) {
            $result = str_replace($placeholder, $content, $result);
        }

        return $result;
    }

    /**
     * Compile one source template file into one compiled PHP file.
     *
     * @param string $source Source template file path
     * @param string $compiled Target compiled file path
     *
     * @return static
     *
     * @throws \Switon\Core\Exception\CreateDirectoryFailedException
     * @throws TemplateCompilationException
     */
    public function compileFile(string $source, string $compiled): static
    {
        $source = $this->pathAlias->resolve($source);
        $compiled = $this->pathAlias->resolve($compiled);

        $this->filesystem->mkdir(dirname($compiled));

        try {
            $str = $this->filesystem->read($source);
        } catch (FileNotFoundException) {
            TemplateCompilationException::raise('Failed to read template: {source}', ['source' => $source]);
        }

        $result = $this->compileString($str);

        try {
            $this->filesystem->write($compiled, $result);
        } catch (RuntimeException) {
            TemplateCompilationException::raise('Failed to write template: {compiled}', ['compiled' => $compiled]);
        }

        return $this;
    }

    protected function compileComments(string $value): string
    {
        $pattern = sprintf('/%s--(.*?)--%s/s', $this->escapedTags[0], $this->escapedTags[1]);

        return preg_replace($pattern, '<?php /*$1*/ ?> ', $value);
    }

    protected function compileEchos(string $value): string
    {
        foreach ($this->getEchoMethods() as $method => $length) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * @return array{compileRawEchos: int, compileEscapedEchos: int}
     */
    protected function getEchoMethods(): array
    {
        $methods = [
            'compileRawEchos' => strlen(stripcslashes($this->rawTags[0])),
            'compileEscapedEchos' => strlen(stripcslashes($this->escapedTags[0])),
        ];

        uksort(
            $methods,
            static function ($method1, $method2) use ($methods) {
                // Ensure the longest tags are processed first
                if ($methods[$method1] > $methods[$method2]) {
                    return -1;
                }
                if ($methods[$method1] < $methods[$method2]) {
                    return 1;
                }

                // give preference to raw tags (assuming they've overridden)
                if ($method1 === 'compileRawEchos') {
                    return -1;
                }
                if ($method2 === 'compileRawEchos') {
                    return 1;
                }

                if ($method1 === 'compileEscapedEchos') {
                    return -1;
                }
                if ($method2 === 'compileEscapedEchos') {
                    return 1;
                }

                return 0;
            }
        );

        return $methods;
    }

    /**
     * Compile directive statements starting with `@`.
     *
     * @param string $value
     * @param array<int, bool> $foreachelseStack Local foreach tracking stack
     * @param array<int, bool> $switchStack Local switch tracking stack
     *
     * @return string
     */
    protected function compileStatements(string $value, array &$foreachelseStack, array &$switchStack): string
    {
        $callback = function ($match) use (&$foreachelseStack, &$switchStack) {
            $directive = $match[1];
            $methodName = 'compile_' . $directive;

            if (method_exists($this, $methodName)) {
                // Methods that need foreachelseStack for nested foreach support
                // Note: PHP method names are case-insensitive, so both 'endforeach' and 'endForeach' match compile_endForeach
                if (in_array($directive, ['foreach', 'foreachElse', 'endForeach', 'endforeach'], true)) {
                    $match[0] = $this->$methodName($match[3] ?? null, $foreachelseStack);
                } elseif (in_array($directive, ['switch', 'case', 'default', 'endswitch'], true)) {
                    $match[0] = $this->$methodName($match[3] ?? null, $switchStack);
                } else {
                    $match[0] = $this->$methodName($match[3] ?? null);
                }
            } elseif (isset($this->directives[$directive])) {
                $func = $this->directives[$directive];
                $match[0] = $func($match[3] ?? null);
            }

            return isset($match[3]) ? $match[0] : $match[0] . $match[2];
        };

        return preg_replace_callback(
            /** @lang text */
            '/\B@(\w+)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x',
            $callback,
            $value
        );
    }

    protected function compileRawEchos(string $value): string
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->rawTags[0], $this->rawTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3];

            return $matches[1]
                ? substr($matches[0], 1)
                : '<?= ' . $this->compileEchoDefaults($matches[2]) . '; ?>' . $whitespace;
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    protected function compileEscapedEchos(string $value): string
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->escapedTags[0], $this->escapedTags[1]);

        $callback = function ($matches) {
            if ($matches[1]) {
                return substr($matches[0], 1);
            }

            if (preg_match('#^[\w.\[\]"\']+$#', $matches[2]) || preg_match('#^\\$\w+\(#', $matches[2])) {
                return $matches[0];
            } elseif ($this->isSafeEchos($matches[2])) {
                return "<?= $matches[2] ?>" . (empty($matches[3]) ? '' : $matches[3]);
            } else {
                return '<?= e(' . $this->compileEchoDefaults($matches[2]) . '); ?>' . (empty($matches[3]) ? ''
                        : $matches[3]);
            }
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    protected function isSafeEchos(string $value): bool
    {
        return preg_match('#^([a-z\d_]+)\\(#', $value, $match) === 1
            && in_array($match[1], $this->safe_functions, true);
    }

    protected function compileEchoDefaults(string $value): string
    {
        /** @noinspection RegExpUnnecessaryNonCapturingGroup */
        return preg_replace('/^(?=\\$)(.+?)(?:\s+or\s+)(.+?)$/s', 'isset($1) ? $1 : $2', $value);
    }

    protected function compile_yield(string $expression): string
    {
        return "<?= \$__frames->section$expression; ?>";
    }

    protected function compile_section(string $expression): string
    {
        return "<?php \$__frames->startSection$expression; ?>";
    }

    protected function compile_append(): string
    {
        return '<?php $__frames->appendSection(); ?>';
    }

    protected function compile_endSection(): string
    {
        return '<?php $__frames->stopSection(); ?>';
    }

    protected function compile_else(): string
    {
        return '<?php else: ?>';
    }

    protected function compile_for(string $expression): string
    {
        return "<?php for$expression: ?>";
    }

    /**
     * @param array<int, bool> $foreachelseStack
     */
    protected function compile_foreach(string $expression, array &$foreachelseStack): string
    {
        // Push false to stack - will be set to true if foreachelse is used
        $foreachelseStack[] = false;

        // Extract collection and iteration part from expression: ($items as $key => $val) -> $items, $key => $val
        preg_match('/\(\s*(.+?)\s+as\s+(.*?)\s*\)$/s', $expression, $matches);
        $collection = $matches[1] ?? 'null';
        $iterationPart = $matches[2] ?? '$__item';

        // Use depth-based temp variable to avoid conflicts in nested foreach loops
        $depth = count($foreachelseStack);
        return "<?php \$__loopData$depth = $collection; \$loop = new \\Switon\\Rendering\\Engine\\Sword\\Loop(\$__loopData$depth, \$loop ?? null); "
            . "\$index = -1; foreach(\$__loopData$depth as $iterationPart): \$index++; \$loop->step(); ?>";
    }

    /**
     * @param array<int, bool> $foreachelseStack
     */
    protected function compile_foreachElse(?string $expression, array &$foreachelseStack): string
    {
        // Mark the current (top) foreach as having foreachelse
        if (!empty($foreachelseStack)) {
            $foreachelseStack[count($foreachelseStack) - 1] = true;
        }
        return '<?php endforeach; $loop = $loop->parent; ?> <?php if($index === -1): ?>';
    }

    protected function compile_can(string $expression): string
    {
        return "<?php if (\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can$expression): ?>";
    }

    protected function compile_allow(string $expression): string
    {
        $parts = explode(',', substr($expression, 1, -1), 2);
        $expr = $this->compileString($parts[1]);
        return "<?php if (\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can($parts[0])): ?>$expr<?php endif ?>";
    }

    protected function compile_cannot(string $expression): string
    {
        return "<?php if (!\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can$expression): ?>";
    }

    protected function compile_if(string $expression): string
    {
        return "<?php if$expression: ?>";
    }

    protected function compile_elseif(string $expression): string
    {
        return "<?php elseif$expression: ?>";
    }

    protected function compile_while(string $expression): string
    {
        return "<?php while$expression: ?>";
    }

    protected function compile_endWhile(): string
    {
        return '<?php endwhile; ?>';
    }

    protected function compile_endFor(): string
    {
        return '<?php endfor; ?>';
    }

    /**
     * @param array<int, bool> $foreachelseStack
     */
    protected function compile_endForeach(?string $expression, array &$foreachelseStack): string
    {
        // Pop from stack to get the foreachelse status for this foreach
        // Stack naturally handles nested foreach loops correctly
        $foreachelseUsed = !empty($foreachelseStack) && array_pop($foreachelseStack);
        return $foreachelseUsed ? '<?php endif; ?>' : '<?php endforeach; $loop = $loop->parent; ?>';
    }

    protected function compile_endCan(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_endCannot(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_endIf(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_unless(string $expression): string
    {
        return "<?php if (!$expression): ?>";
    }

    protected function compile_endunless(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_isset(string $expression): string
    {
        return "<?php if(isset$expression): ?>";
    }

    protected function compile_endisset(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_empty(string $expression): string
    {
        return "<?php if(empty$expression): ?>";
    }

    protected function compile_endempty(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_partial(string $expression): string
    {
        return "<?php \$__frames->partial$expression ?>";
    }

    protected function compile_block(string $expression): string
    {
        return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->block$expression ?>";
    }

    protected function compile_break(?string $expression): string
    {
        return $expression ? "<?php if$expression break; ?>" : '<?php break; ?>';
    }

    protected function compile_continue(?string $expression): string
    {
        return $expression ? "<?php if$expression continue; ?>" : '<?php continue; ?>';
    }

    protected function compile_maxAge(string $expression): string
    {
        return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->setMaxAge$expression; ?>";
    }

    protected function compile_layout(string $expression): string
    {
        if (str_contains($expression, '(false)')) {
            return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->disableLayout(); ?>";
        } else {
            return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->setLayout$expression; ?>";
        }
    }

    protected function compile_content(): string
    {
        return "<?= \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->getContent(); ?>";
    }

    protected function compile_php(string $expression): string
    {
        if (str_starts_with($expression, '(')) {
            $expression = substr($expression, 1, -1);
        }

        return $expression ? "<?php $expression; ?>" : '<?php ';
    }

    protected function compile_endPhp(): string
    {
        return ' ?>';
    }

    /**
     * @param array<int, bool> $switchStack
     */
    protected function compile_switch(?string $expression, array &$switchStack): string
    {
        // Push true = first case pending (PHP tag left open)
        $switchStack[] = true;
        return "<?php switch$expression:";
    }

    /**
     * @param array<int, bool> $switchStack
     */
    protected function compile_case(?string $expression, array &$switchStack): string
    {
        // First case shares the open PHP block from @switch (no <?php prefix)
        if (!empty($switchStack) && array_last($switchStack) === true) {
            $switchStack[count($switchStack) - 1] = false;
            return "case $expression: ?>";
        }
        return "<?php case $expression: ?>";
    }

    /**
     * @param array<int, bool> $switchStack
     */
    protected function compile_default(?string $expression, array &$switchStack): string
    {
        // First label after @switch shares the open PHP block (no <?php prefix)
        if (!empty($switchStack) && array_last($switchStack) === true) {
            $switchStack[count($switchStack) - 1] = false;
            return 'default: ?>';
        }
        return '<?php default: ?>';
    }

    /**
     * @param array<int, bool> $switchStack
     */
    protected function compile_endswitch(?string $expression, array &$switchStack): string
    {
        if (!empty($switchStack)) {
            array_pop($switchStack);
        }
        return '<?php endswitch; ?>';
    }

    protected function compile_once(): string
    {
        return '<?php if ($__frames->once(__FILE__ . \':\' . __LINE__)): ?>';
    }

    protected function compile_endonce(): string
    {
        return '<?php endif; ?>';
    }

    protected function compile_widget(string $expression): string
    {
        return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->widget$expression; ?>";
    }

    protected function compile_flash(): string
    {
        return "<?php \\Switon\\Core\\App::get('Switon\\Viewing\\FlashInterface')->output() ?>";
    }

    protected function compile_inject(string $expression): string
    {
        // Parse @inject('variableName', 'ServiceClass')
        // Expression format: ('variableName', 'ServiceClass')
        $parts = explode(',', substr($expression, 1, -1), 2);
        if (count($parts) !== 2) {
            // Fallback: treat as single argument
            $serviceClass = trim($parts[0], " '\"");
            $variableName = basename(str_replace('\\', '/', $serviceClass));
            return "<?php \$$variableName = \\Switon\\Core\\App::get('$serviceClass'); ?>";
        }

        $variableName = trim($parts[0], " '\"");
        $serviceClass = trim($parts[1], " '\"");

        return "<?php \$$variableName = \\Switon\\Core\\App::get('$serviceClass'); ?>";
    }

    protected function compile_push(string $expression): string
    {
        return "<?php \$__frames->startStack$expression; ?>";
    }

    protected function compile_endpush(): string
    {
        return '<?php $__frames->stopStack(); ?>';
    }

    protected function compile_stack(string $expression): string
    {
        return "<?= \$__frames->renderStack$expression; ?>";
    }

    /**
     * Register a custom directive handler.
     *
     * <code>
     * $compiler->directive('datetime', function ($expression) {
     *     return "<?php echo date('Y-m-d', $expression); ?>";
     * });
     * // Usage in template: @datetime($timestamp)
     * </code>
     *
     * @param string $name Directive name (used as <code>@name</code> in templates)
     * @param callable $handler Receives the expression string, returns compiled PHP
     */
    public function directive(string $name, callable $handler): static
    {
        $this->directives[$name] = $handler;

        return $this;
    }
}
