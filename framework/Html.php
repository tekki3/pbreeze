<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class Html
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    private const BOOLEAN = [
        'allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'default', 'defer',
        'disabled', 'disablepictureinpicture', 'disableremoteplayback', 'formnovalidate', 'hidden', 'inert', 'ismap', 'itemscope', 'loop', 'multiple', 'muted',
        'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected',
    ];
    private const BLOCKED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link', 'svg', 'math',
        'xmp', 'plaintext', 'noembed', 'noframes', 'noscript',
    ];
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'longdesc', 'itemid', 'itemtype'];

    public static function escape(mixed $value): string
    {
        return View::escape($value);
    }

    public static function raw(string $html): HtmlString
    {
        return new HtmlString($html);
    }

    public static function tag(string $name, mixed $content = '', array $attributes = []): string
    {
        $name = strtolower($name);
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $name) || in_array($name, self::BLOCKED_TAGS, true)) {
            throw new InvalidArgumentException('Use a plain HTML element name; executable, raw-text, and foreign-content elements are not supported.');
        }
        $attributes = self::attributes($attributes);
        if (in_array($name, self::VOID, true)) {
            if ($content !== '' && $content !== null) {
                throw new InvalidArgumentException('HTML void elements cannot contain content.');
            }
            return '<' . $name . $attributes . '>';
        }
        $body = $content instanceof HtmlString && !in_array($name, ['textarea', 'title', 'option'], true)
            ? (string) $content : self::escape($content);
        if (in_array($name, ['textarea', 'pre', 'listing'], true)
            && (str_starts_with($body, "\n") || str_starts_with($body, "\r"))) {
            $body = "\n" . $body;
        }
        return '<' . $name . $attributes . '>' . $body . '</' . $name . '>';
    }

    public static function attributes(array $attributes): string
    {
        $output = '';
        $seen = [];
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name)) {
                throw new InvalidArgumentException('HTML attribute names must be plain ASCII names.');
            }
            $name = strtolower($name);
            if (isset($seen[$name]) || str_starts_with($name, 'on')
                || in_array($name, ['style', 'srcdoc', 'srcset', 'imagesrcset', 'ping', 'xmlns', 'is'], true)) {
                throw new InvalidArgumentException('Duplicate or executable HTML attributes are not supported.');
            }
            $seen[$name] = true;
            if ($value === null) {
                continue;
            }
            if (in_array($name, ['download', 'capture'], true) && is_bool($value)) {
                if ($value) {
                    $output .= ' ' . $name;
                }
                continue;
            }
            if (in_array($name, self::BOOLEAN, true)) {
                if (!is_bool($value)) {
                    throw new InvalidArgumentException("Boolean attribute $name requires true, false, or null.");
                }
                if ($value) {
                    $output .= ' ' . $name;
                }
                continue;
            }
            if ($value instanceof HtmlString) {
                throw new InvalidArgumentException('Raw HTML cannot be used as an attribute value.');
            }
            if ($name === 'class' && is_array($value)) {
                $value = self::classes($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (in_array($name, self::URL_ATTRIBUTES, true)) {
                $value = self::url(View::text($value), $name === 'href');
            }
            $output .= ' ' . $name . '="' . self::escape($value) . '"';
        }
        return $output;
    }

    public static function classes(string|array $classes): string
    {
        $tokens = [];
        self::classTokens($classes, $tokens, 0);
        return implode(' ', array_keys($tokens));
    }

    public static function a(string $url, mixed $content, array $attributes = []): string
    {
        return self::tag('a', $content, self::fixed(['href' => $url], $attributes));
    }

    public static function img(string $url, array $attributes = []): string
    {
        return self::tag('img', '', self::fixed(['src' => $url], $attributes));
    }

    public static function input(string $type, string $name, array $attributes = []): string
    {
        return self::tag('input', '', self::fixed(['type' => $type, 'name' => $name], $attributes));
    }

    public static function textarea(string $name, mixed $value = '', array $attributes = []): string
    {
        return self::tag('textarea', $value, self::fixed(['name' => $name], $attributes));
    }

    public static function select(string $name, array $options, string|int|array|null $selected = null, array $attributes = []): string
    {
        if (is_array($selected) && self::attributeValue($attributes, 'multiple') !== true) {
            throw new InvalidArgumentException('An array selection requires multiple => true.');
        }
        $selected = $selected === null ? [] : (is_array($selected) ? $selected : [$selected]);
        foreach ($selected as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('Selected values must be strings or integers.');
            }
        }
        $selected = array_map(static fn (string|int $value): string => (string) $value, $selected);
        $content = '';
        foreach ($options as $value => $label) {
            $content .= self::tag('option', $label, ['value' => $value, 'selected' => in_array((string) $value, $selected, true)]);
        }
        return self::tag('select', self::raw($content), self::fixed(['name' => $name], $attributes));
    }

    public static function checkbox(string $name, string|int $value = 1, bool $checked = false, array $attributes = []): string
    {
        return self::input('checkbox', $name, self::fixed(['value' => $value, 'checked' => $checked], $attributes));
    }

    public static function radio(string $name, string|int $value, bool $checked = false, array $attributes = []): string
    {
        return self::input('radio', $name, self::fixed(['value' => $value, 'checked' => $checked], $attributes));
    }

    public static function button(mixed $content, array $attributes = []): string
    {
        if (self::attributeValue($attributes, 'type') === null) {
            $attributes = self::fixed(['type' => 'button'], $attributes);
        }
        return self::tag('button', $content, $attributes);
    }

    public static function label(string $for, mixed $content, array $attributes = []): string
    {
        return self::tag('label', $content, self::fixed(['for' => $for], $attributes));
    }

    public static function csrf(Session $session): HtmlString
    {
        return self::raw(\csrf_field($session));
    }

    private static function fixed(array $fixed, array $attributes): array
    {
        foreach ($attributes as $name => $value) {
            if (is_string($name) && array_key_exists(strtolower($name), $fixed)) {
                throw new InvalidArgumentException('Do not override positional HTML helper arguments through attributes.');
            }
        }
        return $fixed + $attributes;
    }

    private static function attributeValue(array $attributes, string $name): mixed
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key) && strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    private static function classTokens(string|array $classes, array &$tokens, int $depth): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Class arrays cannot exceed 32 nested levels.');
        }
        if (is_string($classes)) {
            foreach (preg_split('/[\x09-\x0d ]+/', $classes, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                $tokens[$token] = true;
            }
            return;
        }
        foreach ($classes as $key => $value) {
            if (is_string($key)) {
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('Conditional CSS class values must be boolean.');
                }
                if ($value) {
                    self::classTokens($key, $tokens, $depth + 1);
                }
            } elseif (is_string($value) || is_array($value)) {
                self::classTokens($value, $tokens, $depth + 1);
            } elseif ($value !== null && $value !== false) {
                throw new InvalidArgumentException('CSS class lists accept strings, nested arrays, false, or null.');
            }
        }
    }

    private static function url(string $url, bool $link): string
    {
        if (preg_match('//u', $url) !== 1 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('HTML URLs cannot contain whitespace, controls, backslashes, or protocol-relative hosts.');
        }
        if (preg_match('/^([^\/?#]*):/', $url, $match)) {
            $scheme = strtolower($match[1]);
            if (!in_array($scheme, $link ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'], true)) {
                throw new InvalidArgumentException('Unsupported HTML URL scheme.');
            }
            if (in_array($scheme, ['http', 'https'], true)) {
                $parts = parse_url($url);
                if (!str_starts_with(strtolower($url), $scheme . '://') || $parts === false || empty($parts['host'])
                    || isset($parts['user']) || isset($parts['pass'])) {
                    throw new InvalidArgumentException('HTTP URLs require a host and cannot contain credentials.');
                }
            }
        }
        return $url;
    }
}
