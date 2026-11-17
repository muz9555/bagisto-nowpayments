<?php

namespace Webkul\NowPayments\Services;

class IpnSignature
{
    /**
     * Verify the recursively sorted notification using the merchant's IPN secret.
     */
    public function verify(string $payload, ?string $signature, string $secret): bool
    {
        if (
            trim($secret) === ''
            || ! preg_match('/^[a-f0-9]{128}$/iD', $signature ?? '')
        ) {
            return false;
        }

        try {
            $data = json_decode($payload, false, 64, JSON_THROW_ON_ERROR);

            if (! $data instanceof \stdClass) {
                return false;
            }

            $canonical = json_encode($this->sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return hash_equals(hash_hmac('sha512', $canonical, trim($secret)), strtolower($signature));
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Sort object keys recursively while preserving JSON arrays and empty objects.
     */
    protected function sort(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map(fn ($child) => $this->sort($child), $properties);
        }

        return is_array($value) ? array_map(fn ($child) => $this->sort($child), $value) : $value;
    }
}
