<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class TemplateCompiler
{
    public const VERSION = '1';

    public function compile(string $source, string $name): string
    {
        if (str_contains($source, '<?')) {
            throw new RuntimeException("Template $name: use directives instead of PHP tags.");
        }
        $output = '<?php declare(strict_types=1); ?>';
        $stack = [];
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            if (substr($source, $offset, 4) === '{{--') {
                $end = strpos($source, '--}}', $offset + 4);
                if ($end === false) {
                    throw new RuntimeException("Template $name: unclosed comment.");
                }
                $offset = $end + 4;
                continue;
            }
            if (substr($source, $offset, 2) === '@@') {
                $output .= '@';
                $offset += 2;
                continue;
            }
            $raw = substr($source, $offset, 3) === '{!!';
            if ($raw || substr($source, $offset, 2) === '{{') {
                $offset += $raw ? 3 : 2;
                $expression = $this->expression($source, $offset, $raw ? '!!}' : '}}', $name);
                $output .= '<?php echo \PBreeze\View::' . ($raw ? 'text' : 'escape') . '(' . $expression . '); ?>';
                continue;
            }
            if ($source[$offset] !== '@' || !preg_match('/\G@([a-zA-Z]+)/', $source, $match, 0, $offset)) {
                $output .= $source[$offset++];
                continue;
            }
            $directive = $match[1];
            if ($offset > 0 && preg_match('/[a-zA-Z0-9_.]/', $source[$offset - 1])
                && !in_array($directive, [
                    'if', 'elseif', 'else', 'endif', 'foreach', 'endforeach', 'for', 'endfor',
                    'extends', 'section', 'endsection', 'yield', 'include', 'component', 'endcomponent', 'slot', 'endslot', 'csrf',
                ], true)) {
                $output .= $source[$offset++];
                continue;
            }
            $offset += strlen($match[0]);
            $noArguments = ['else', 'endif', 'endforeach', 'endfor', 'endsection', 'endcomponent', 'endslot'];
            $arguments = '';
            if (!in_array($directive, $noArguments, true)) {
                $start = $offset;
                while ($offset < $length && ctype_space($source[$offset])) {
                    $offset++;
                }
                if (($source[$offset] ?? '') !== '(') {
                    throw new RuntimeException("Template $name: @$directive requires arguments (position $start).");
                }
                $offset++;
                $arguments = $this->expression($source, $offset, ')', $name);
            }
            if (in_array($directive, ['if', 'foreach', 'for', 'section', 'component', 'slot'], true)) {
                $stack[] = ['kind' => $directive, 'else' => false];
            } elseif ($directive === 'else' || $directive === 'elseif') {
                $top = array_key_last($stack);
                if ($top === null || $stack[$top]['kind'] !== 'if' || $stack[$top]['else']) {
                    throw new RuntimeException("Template $name: unexpected @$directive.");
                }
                $stack[$top]['else'] = $directive === 'else';
            } elseif (str_starts_with($directive, 'end')) {
                $open = array_pop($stack);
                if ($open === null || $open['kind'] !== substr($directive, 3)) {
                    throw new RuntimeException("Template $name: unexpected @$directive.");
                }
            }
            $output .= match ($directive) {
                'if' => "<?php if ($arguments): ?>",
                'elseif' => "<?php elseif ($arguments): ?>",
                'else' => '<?php else: ?>',
                'endif' => '<?php endif; ?>',
                'foreach' => "<?php foreach ($arguments): ?>",
                'endforeach' => '<?php endforeach; ?>',
                'for' => "<?php for ($arguments): ?>",
                'endfor' => '<?php endfor; ?>',
                'extends' => '<?php $__engine->extend(' . $arguments . '); ?>',
                'section' => '<?php $__engine->startSection(' . $arguments . '); ?>',
                'endsection' => '<?php $__engine->endSection(); ?>',
                'yield' => '<?php echo $__engine->yieldSection(' . $arguments . '); ?>',
                'include' => '<?php echo $__engine->includeView(get_defined_vars(), ' . $arguments . '); ?>',
                'component' => '<?php $__engine->startComponent(' . $arguments . '); ?>',
                'endcomponent' => '<?php echo $__engine->endComponent(); ?>',
                'slot' => '<?php $__engine->startSlot(' . $arguments . '); ?>',
                'endslot' => '<?php $__engine->endSlot(); ?>',
                'csrf' => '<?php echo csrf_field(' . $arguments . '); ?>',
                default => throw new RuntimeException("Template $name: unknown directive @$directive. Use @@ for a literal @."),
            };
        }
        if ($stack !== []) {
            throw new RuntimeException("Template $name: unclosed @" . $stack[array_key_last($stack)]['kind'] . '.');
        }
        try {
            token_get_all($output, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new RuntimeException("Template $name: invalid expression: " . $error->getMessage(), 0, $error);
        }
        return $output;
    }

    private function expression(string $source, int &$offset, string $closing, string $name): string
    {
        $start = $offset;
        $stack = [];
        $quote = null;
        $length = strlen($source);
        while ($offset < $length) {
            $character = $source[$offset];
            if ($quote !== null) {
                if ($character === '\\') {
                    $offset += 2;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                $offset++;
                continue;
            }
            if ($stack === [] && substr($source, $offset, strlen($closing)) === $closing) {
                $expression = trim(substr($source, $start, $offset - $start));
                $offset += strlen($closing);
                if ($expression === '') {
                    throw new RuntimeException("Template $name: empty expression.");
                }
                return $expression;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (substr($source, $offset, 2) === '/*') {
                $end = strpos($source, '*/', $offset + 2);
                if ($end === false) {
                    break;
                }
                $offset = $end + 2;
                continue;
            } elseif (substr($source, $offset, 2) === '//' || $character === '#') {
                $end = strpos($source, "\n", $offset);
                if ($end === false) {
                    break;
                }
                $offset = $end + 1;
                continue;
            } elseif ($character === '`' || substr($source, $offset, 3) === '<<<') {
                throw new RuntimeException("Template $name: backticks and heredoc are not supported.");
            } elseif (str_contains('([{', $character)) {
                $stack[] = $character;
            } elseif (str_contains(')]}', $character)) {
                $open = array_pop($stack);
                if ($open !== match ($character) { ')' => '(', ']' => '[', '}' => '{' }) {
                    throw new RuntimeException("Template $name: unbalanced expression.");
                }
            }
            $offset++;
        }
        throw new RuntimeException("Template $name: unclosed expression.");
    }
}
