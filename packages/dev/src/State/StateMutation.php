<?php

declare(strict_types=1);

namespace TheMattos\Leakless\Dev\State;

final readonly class StateMutation
{
    public function __construct(
        public string $className,
        public string $propertyName,
        public mixed $beforeValue,
        public mixed $afterValue,
    ) {}

    public function toFormattedString(): string
    {
        $beforeStr = $this->valueToString($this->beforeValue);
        $afterStr = $this->valueToString($this->afterValue);

        return sprintf(
            "- Property [%s::$%s] mutated:\n  Before: %s\n  After:  %s",
            $this->className,
            $this->propertyName,
            $beforeStr,
            $afterStr,
        );
    }

    private function valueToString(mixed $value): string
    {
        if ($value === '__UNINITIALIZED__') {
            return '<uninitialized>';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $formatted = (string) $value;
            if (is_string($value)) {
                $formatted = "'{$formatted}'";
            }

            return strlen($formatted) > 80 ? substr($formatted, 0, 77).'...' : $formatted;
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                return strlen($json) > 100 ? substr($json, 0, 97).'...' : $json;
            }

            return sprintf('array(%d items)', count($value));
        }

        if (is_object($value)) {
            return sprintf('object(%s#%d)', $value::class, spl_object_id($value));
        }

        if (is_resource($value)) {
            return sprintf('resource(%s)', get_resource_type($value));
        }

        return gettype($value);
    }
}
