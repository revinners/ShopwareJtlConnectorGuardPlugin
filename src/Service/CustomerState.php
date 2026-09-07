<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The current `customer` row (storage column names, raw DB values) of a customer that is about to be updated.
 */
final readonly class CustomerState
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(
        public string $id,
        private array $row,
    ) {
    }

    public function get(string $column): mixed
    {
        return $this->row[$column] ?? null;
    }

    public function getCustomerNumber(): ?string
    {
        return $this->string('customer_number');
    }

    public function getEmail(): ?string
    {
        return $this->string('email');
    }

    public function getFirstName(): ?string
    {
        return $this->string('first_name');
    }

    public function getLastName(): ?string
    {
        return $this->string('last_name');
    }

    public function getSalesChannelId(): ?string
    {
        $bytes = $this->row['sales_channel_id'] ?? null;

        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    private function string(string $column): ?string
    {
        $value = $this->row[$column] ?? null;

        return $value === null ? null : (string) $value;
    }
}
