<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

/**
 * The current `customer_address` row (storage column names, raw DB values) of an address the
 * connector is about to update or delete.
 */
final readonly class CustomerAddressState
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

    public function getCustomerId(): ?string
    {
        return Values::hexOrNull($this->row['customer_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return $this->row;
    }
}
