<?php

namespace craft\cloud;

class StaticCacheTag implements \Stringable, \JsonSerializable
{
    private const INVALID_CHARS = '/[^\x21-\x2B\x2D-\x7E]/';

    public readonly string $originalValue;
    private bool $minify = false;

    public function __construct(
        private string $value,
    ) {
        $this->originalValue = $value;
    }

    public static function create(string $value): self
    {
        return new self($value);
    }

    public function jsonSerialize(): array
    {
        return [
            'value' => $this->getValue(),
            'originalValue' => $this->originalValue,
        ];
    }

    public function __toString(): string
    {
        return $this->getValue();
    }

    public function getValue(): string
    {
        if ($this->value === '') {
            return '';
        }

        if ($this->minify) {
            return self::create($this->value)
                ->hash()
                ->withPrefix(Module::getInstance()->getConfig()->getShortEnvironmentId() ?? '')
                ->value;
        }

        return $this->normalizedValue();
    }

    public function withPrefix(string $prefix): self
    {
        $this->value = $prefix . $this->value;

        return $this;
    }

    public function minify(bool $minify): self
    {
        $this->minify = $minify;

        return $this;
    }

    private function normalizedValue(): string
    {
        // Cache tags need printable ASCII with no spaces or commas.
        // @see https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        return preg_replace_callback(
            self::INVALID_CHARS,
            fn(array $match) => rawurlencode($match[0]),
            $this->value,
        ) ?? $this->value;
    }

    private function hash(): self
    {
        $this->value = sprintf('%x', crc32($this->value));

        return $this;
    }
}
