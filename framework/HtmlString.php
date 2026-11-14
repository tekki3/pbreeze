<?php
declare(strict_types=1);

namespace PBreeze;

use Stringable;

final class HtmlString implements Stringable
{
    public function __construct(private readonly string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }
}
