<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class QrCode
{
    private const ECC_LENGTHS = [7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30];
    private const BLOCK_COUNTS = [1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25];

    /** @var list<list<bool>> */
    private array $modules;
    /** @var list<list<bool>> */
    private array $reserved;
    private readonly int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $this->modules = $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    public static function svg(#[\SensitiveParameter] string $text): string
    {
        $length = strlen($text);
        if ($length === 0 || $length > 2953 || preg_match('/^[\x20-\x7e]+$/D', $text) !== 1) {
            throw new InvalidArgumentException('QR codes require 1-2953 printable ASCII bytes.');
        }
        for ($version = 1; $version <= 40; $version++) {
            $capacity = self::dataLength($version);
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + $length * 8 <= $capacity * 8) {
                break;
            }
        }
        $bits = '0100' . str_pad(decbin($length), $countBits, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0');
        $data = array_map(bindec(...), str_split($bits, 8));
        for ($pad = 0; count($data) < $capacity; $pad++) {
            $data[] = $pad % 2 === 0 ? 0xec : 0x11;
        }
        $qr = new self($version);
        $qr->drawPatterns();
        $qr->drawData(self::addErrorCorrection($data, $version));
        $unmasked = $qr->modules;
        $best = $unmasked;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->modules = $unmasked;
            $qr->applyMask($mask);
            $qr->drawFormat($mask);
            $score = $qr->penalty();
            if ($score < $bestScore) {
                $best = $qr->modules;
                $bestScore = $score;
            }
        }
        $path = '';
        foreach ($best as $y => $row) {
            for ($x = 0; $x < $qr->size; $x++) {
                if (!$row[$x]) {
                    continue;
                }
                $start = $x;
                while ($x + 1 < $qr->size && $row[$x + 1]) {
                    $x++;
                }
                $width = $x - $start + 1;
                $path .= 'M' . ($start + 4) . ',' . ($y + 4) . "h{$width}v1h-{$width}z";
            }
        }
        $edge = $qr->size + 8;
        $pixels = $edge * 4;
        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="QR code"'
            . " viewBox=\"0 0 $edge $edge\" width=\"$pixels\" height=\"$pixels\" shape-rendering=\"crispEdges\">"
            . "<rect width=\"$edge\" height=\"$edge\" fill=\"#fff\"/><path fill=\"#000\" d=\"$path\"/></svg>";
    }

    private static function rawLength(int $version): int
    {
        $modules = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignments = intdiv($version, 7) + 2;
            $modules -= (25 * $alignments - 10) * $alignments - 55;
            if ($version >= 7) {
                $modules -= 36;
            }
        }
        return intdiv($modules, 8);
    }

    private static function dataLength(int $version): int
    {
        return self::rawLength($version) - self::ECC_LENGTHS[$version - 1] * self::BLOCK_COUNTS[$version - 1];
    }

    /** @param list<int> $data
     *  @return list<int>
     */
    private static function addErrorCorrection(#[\SensitiveParameter] array $data, int $version): array
    {
        $count = self::BLOCK_COUNTS[$version - 1];
        $degree = self::ECC_LENGTHS[$version - 1];
        $shortBlocks = $count - self::rawLength($version) % $count;
        $shortData = intdiv(self::rawLength($version), $count) - $degree;
        $generator = [1];
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, $root);
            }
            $generator = $next;
            $root = self::multiply($root, 2);
        }
        $blocks = $parity = [];
        $offset = 0;
        for ($block = 0; $block < $count; $block++) {
            $length = $shortData + ($block < $shortBlocks ? 0 : 1);
            $blocks[$block] = array_slice($data, $offset, $length);
            $offset += $length;
            $remainder = array_fill(0, $degree, 0);
            foreach ($blocks[$block] as $byte) {
                $factor = $byte ^ array_shift($remainder);
                $remainder[] = 0;
                for ($i = 0; $i < $degree; $i++) {
                    $remainder[$i] ^= self::multiply($generator[$i + 1], $factor);
                }
            }
            $parity[] = $remainder;
        }
        $result = [];
        for ($i = 0; $i <= $shortData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $degree; $i++) {
            foreach ($parity as $block) {
                $result[] = $block[$i];
            }
        }
        return $result;
    }

    private static function multiply(int $left, int $right): int
    {
        $result = 0;
        for ($bit = 7; $bit >= 0; $bit--) {
            $result = ($result << 1) ^ (($result & 0x80) !== 0 ? 0x11d : 0);
            if ((($right >> $bit) & 1) !== 0) {
                $result ^= $left;
            }
        }
        return $result;
    }

    private function mark(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }

    private function drawPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->mark(6, $i, $i % 2 === 0);
            $this->mark($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$x, $y]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    if ($x + $dx >= 0 && $x + $dx < $this->size && $y + $dy >= 0 && $y + $dy < $this->size) {
                        $distance = max(abs($dx), abs($dy));
                        $this->mark($x + $dx, $y + $dy, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        if ($this->version >= 2) {
            $count = intdiv($this->version, 7) + 2;
            $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;
            $positions = [6];
            for ($position = $this->size - 7; count($positions) < $count; $position -= $step) {
                array_splice($positions, 1, 0, [$position]);
            }
            foreach ($positions as $i => $y) {
                foreach ($positions as $j => $x) {
                    if (($i === 0 && ($j === 0 || $j === $count - 1)) || ($i === $count - 1 && $j === 0)) {
                        continue;
                    }
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $this->mark($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
                        }
                    }
                }
            }
        }
        $this->drawFormat(0);
        if ($this->version >= 7) {
            $remainder = $this->version << 12;
            for ($i = 17; $i >= 12; $i--) {
                if ((($remainder >> $i) & 1) !== 0) {
                    $remainder ^= 0x1f25 << ($i - 12);
                }
            }
            $bits = ($this->version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->mark($a, $b, (($bits >> $i) & 1) !== 0);
                $this->mark($b, $a, (($bits >> $i) & 1) !== 0);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = 8 | $mask;
        $remainder = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ((($remainder >> $i) & 1) !== 0) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        for ($i = 0; $i <= 5; $i++) {
            $this->mark(8, $i, (($bits >> $i) & 1) !== 0);
        }
        $this->mark(8, 7, (($bits >> 6) & 1) !== 0);
        $this->mark(8, 8, (($bits >> 7) & 1) !== 0);
        $this->mark(7, 8, (($bits >> 8) & 1) !== 0);
        for ($i = 9; $i < 15; $i++) {
            $this->mark(14 - $i, 8, (($bits >> $i) & 1) !== 0);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->mark($this->size - 1 - $i, 8, (($bits >> $i) & 1) !== 0);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->mark(8, $this->size - 15 + $i, (($bits >> $i) & 1) !== 0);
        }
        $this->mark(8, $this->size - 8, true);
    }

    /** @param list<int> $data */
    private function drawData(#[\SensitiveParameter] array $data): void
    {
        $bit = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $up = (($right + 1) & 2) === 0;
            for ($row = 0; $row < $this->size; $row++) {
                $y = $up ? $this->size - 1 - $row : $row;
                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;
                    if (!$this->reserved[$y][$x]) {
                        $this->modules[$y][$x] = $bit < count($data) * 8
                            && (($data[intdiv($bit, 8)] >> (7 - $bit % 8)) & 1) !== 0;
                        $bit++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if (!$this->reserved[$y][$x] && $invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $score = $dark = 0;
        for ($y = 0; $y < $this->size; $y++) {
            $row = $column = '';
            for ($x = 0; $x < $this->size; $x++) {
                $value = $this->modules[$y][$x];
                $dark += (int) $value;
                $row .= $value ? '1' : '0';
                $column .= $this->modules[$x][$y] ? '1' : '0';
                if ($x > 0 && $y > 0 && $value === $this->modules[$y][$x - 1]
                    && $value === $this->modules[$y - 1][$x] && $value === $this->modules[$y - 1][$x - 1]) {
                    $score += 3;
                }
            }
            foreach ([$row, $column] as $line) {
                preg_match_all('/0{5,}|1{5,}/', $line, $runs);
                foreach ($runs[0] as $run) {
                    $score += strlen($run) - 2;
                }
                $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        return $score + intdiv(abs($dark * 20 - $this->size * $this->size * 10), $this->size * $this->size) * 10;
    }
}
