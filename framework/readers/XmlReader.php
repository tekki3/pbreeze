<?php
declare(strict_types=1);

namespace PBreeze\Readers;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

final class XmlReader extends Reader
{
    public function read(string $path, array $options = []): array
    {
        $options = $this->options($options);
        $contents = $this->utf8($this->contents($path, $options['max_bytes']));
        if (!class_exists(DOMDocument::class, false)) {
            throw new RuntimeException('XML reading requires the native DOM extension.');
        }
        if (trim($contents) === '') {
            throw new RuntimeException('Invalid XML: the document is empty.');
        }
        if (str_contains($contents, "\0")
            || (preg_match('/^<\?xml\s[^?]*\bencoding\s*=\s*(["\'])(.*?)\1/i', $contents, $encoding)
                && !in_array(strtoupper($encoding[2]), ['UTF-8', 'UTF8', 'US-ASCII'], true))) {
            throw new RuntimeException('XML must use UTF-8 rather than an alternate declared or detected encoding.');
        }
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new RuntimeException('XML DTDs and entity declarations are not allowed.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
                $error = libxml_get_last_error();
                throw new RuntimeException('Invalid XML' . ($error === false ? '.' : " at line {$error->line}, column {$error->column}."));
            }
            if ($document->doctype !== null || $document->documentElement === null) {
                throw new RuntimeException('XML requires one root element and no DTD.');
            }
            $count = 0;
            return [$this->name($document->documentElement) => $this->element($document->documentElement, 1, $options['max_depth'], $count)];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function name(DOMNode $node): string
    {
        return $node->namespaceURI !== null && $node->namespaceURI !== ''
            ? '{' . $node->namespaceURI . '}' . $node->localName : $node->nodeName;
    }

    private function element(DOMElement $element, int $depth, int $limit, int &$count): array
    {
        if ($depth > $limit || ++$count > 100000) {
            throw new RuntimeException('XML exceeds max_depth or the 100000-element limit.');
        }
        $result = ['@attributes' => [], '@text' => '', '@children' => []];
        foreach ($element->attributes as $attribute) {
            $result['@attributes'][$this->name($attribute)] = $attribute->value;
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $result['@children'][$this->name($child)][] = $this->element($child, $depth + 1, $limit, $count);
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $result['@text'] .= $child->nodeValue;
            } elseif ($child->nodeType === XML_ENTITY_REF_NODE) {
                throw new RuntimeException('XML entity references are not allowed.');
            }
        }
        return $result;
    }
}
