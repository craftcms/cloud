<?php

namespace craft\cloud;

class StaticCacheTag implements \Stringable, \JsonSerializable
{
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

    public function jsonSerialize(): false|string
    {
        return json_encode([
            'value' => $this->getValue(),
            'originalValue' => $this->originalValue,
        ]);
    }

    public function __toString(): string
    {
        return $this->getValue();
    }

    public function getValue(): string
    {
        if (!$this->value) {
            return '';
        }

        if ($this->minify) {
            return self::create($this->value)
                ->hash()
                ->withPrefix(Module::getInstance()->getConfig()->getShortEnvironmentId())
                ->value;
        }

        return $this->isValidCacheTag() ? $this->value : '';
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

    private function isValidCacheTag(): bool
    {
        // Cloudflare accepts printable ASCII, except spaces. A comma separates tags.
        // @see https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/#a-few-things-to-remember
        return preg_match('/^[\x21-\x2B\x2D-\x7E]+$/', $this->value) === 1;
    }

    private function hash(): self
    {
        $this->value = sprintf('%x', crc32($this->value));

        return $this;
    }
}
