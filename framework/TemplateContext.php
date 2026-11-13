<?php
declare(strict_types=1);

namespace PBreeze;

use LogicException;
use Throwable;

final class TemplateContext
{
    /** @var array<string, string> */
    private array $sections = [];
    /** @var list<array{layout: ?string, captures: list<array<string, mixed>>}> */
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
            throw new LogicException("Cyclische of te diep geneste view: $name");
        }
        $this->chain[] = $name;
        $this->frames[] = ['layout' => null, 'captures' => []];
        $level = ob_get_level();
        try {
            $output = $this->views->evaluate($name, $data, $this);
            $frame = $this->frames[array_key_last($this->frames)];
            if ($frame['captures'] !== []) {
                throw new LogicException("View $name bevat onafgesloten secties, componenten of slots.");
            }
            if ($frame['layout'] !== null) {
                if (trim($output) !== '') {
                    throw new LogicException("View $name: plaats inhoud binnen @section wanneer je @extends gebruikt.");
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
            throw new LogicException('Een template kan maar een layout uitbreiden.');
        }
        $this->frames[$index]['layout'] = $name;
    }

    public function startSection(string $name): void
    {
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
            throw new LogicException('Gebruik een geldige slotnaam; slot is gereserveerd voor de hoofdinhoud.');
        }
        $captures = $this->frames[array_key_last($this->frames)]['captures'];
        $parent = $captures[array_key_last($captures)] ?? null;
        if ($parent === null || $parent['kind'] !== 'component') {
            throw new LogicException('@slot moet rechtstreeks binnen een component staan.');
        }
        if (array_key_exists($name, $parent['slots'])) {
            throw new LogicException("Dubbele slotnaam: $name");
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
            throw new LogicException("Ongeldige afsluiting van $kind.");
        }
        $capture['content'] = (string) ob_get_clean();
        return $capture;
    }
}
