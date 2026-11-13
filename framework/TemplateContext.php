<?php
declare(strict_types=1);

namespace PBreeze;

use LogicException;

final class TemplateContext
{
    /** @var array<string, string> */
    private array $sections = [];
    /** @var list<array{layout: ?string, sections: list<string>, captures: list<array<string, mixed>>}> */
    private array $frames = [];
    /** @var list<string> */
    private array $chain;

    /** @param list<string> $chain */
    public function __construct(private readonly View $views, array $chain = [])
    {
        $this->chain = $chain;
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        if (count($this->chain) >= 32 || in_array($name, $this->chain, true)) {
            throw new LogicException("Cyclic or excessively nested view: $name");
        }
        $this->chain[] = $name;
        $this->frames[] = ['layout' => null, 'sections' => [], 'captures' => []];
        $level = ob_get_level();
        try {
            $output = $this->views->evaluate($name, $data, $this);
            $frame = $this->frames[array_key_last($this->frames)];
            if ($frame['captures'] !== []) {
                throw new LogicException("View $name contains unclosed sections, components, or slots.");
            }
            if ($frame['layout'] !== null) {
                if (trim($output) !== '') {
                    throw new LogicException("View $name: put content inside @section when using @extends.");
                }
                return $this->render($frame['layout'], $data);
            }
            return $output;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            array_pop($this->frames);
            array_pop($this->chain);
        }
    }

    public function extend(string $name): void
    {
        $index = array_key_last($this->frames);
        if ($this->frames[$index]['layout'] !== null) {
            throw new LogicException('A template can extend only one layout.');
        }
        $this->frames[$index]['layout'] = $name;
    }

    public function startSection(string $name): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $name)) {
            throw new LogicException('Invalid section name.');
        }
        $frame = array_key_last($this->frames);
        if (in_array($name, $this->frames[$frame]['sections'], true)) {
            throw new LogicException("Duplicate section in the same template: $name");
        }
        $this->frames[$frame]['sections'][] = $name;
        $this->start('section', ['name' => $name]);
    }

    public function endSection(): void
    {
        $capture = $this->finish('section');
        $this->sections[$capture['name']] ??= $capture['content'];
    }

    public function yieldSection(string $name, mixed $default = ''): string
    {
        return $this->sections[$name] ?? View::escape($default);
    }

    /** @param array<string, mixed> $scope @param array<string, mixed> $data */
    public function includeView(array $scope, string $name, array $data = []): string
    {
        return (new self($this->views, $this->chain))->render($name, array_replace($scope, $data));
    }

    /** @param array<string, mixed> $data */
    public function startComponent(string $name, array $data = []): void
    {
        $this->start('component', ['name' => $name, 'data' => $data, 'slots' => []]);
    }

    public function endComponent(): string
    {
        $capture = $this->finish('component');
        return (new self($this->views, $this->chain))->render(
            $capture['name'],
            array_replace($capture['data'], $capture['slots'], ['slot' => $capture['content']]),
        );
    }

    public function startSlot(string $name): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name) || $name === 'slot') {
            throw new LogicException('Use a valid slot name; slot is reserved for the main content.');
        }
        $captures = $this->frames[array_key_last($this->frames)]['captures'];
        $parentIndex = array_key_last($captures);
        $parent = $parentIndex === null ? null : $captures[$parentIndex];
        if ($parent === null || $parent['kind'] !== 'component') {
            throw new LogicException('@slot must be directly inside a component.');
        }
        if (array_key_exists($name, $parent['slots'])) {
            throw new LogicException("Duplicate slot name: $name");
        }
        $this->start('slot', ['name' => $name]);
    }

    public function endSlot(): void
    {
        $capture = $this->finish('slot');
        $frame = array_key_last($this->frames);
        $parent = array_key_last($this->frames[$frame]['captures']);
        $this->frames[$frame]['captures'][$parent]['slots'][$capture['name']] = $capture['content'];
    }

    /** @param array<string, mixed> $data */
    private function start(string $kind, array $data): void
    {
        $this->frames[array_key_last($this->frames)]['captures'][] = $data + ['kind' => $kind, 'level' => ob_get_level()];
        ob_start();
    }

    /** @return array<string, mixed> */
    private function finish(string $kind): array
    {
        $index = array_key_last($this->frames);
        $capture = array_pop($this->frames[$index]['captures']);
        if ($capture === null || $capture['kind'] !== $kind || ob_get_level() !== $capture['level'] + 1) {
            throw new LogicException("Invalid closing directive for $kind.");
        }
        $capture['content'] = (string) ob_get_clean();
        return $capture;
    }
}
