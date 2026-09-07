<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Loads the current DB state of customers by primary key (binary ids), one query per write event.
 */
final class CustomerStateLoader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $idsBytes 16-byte binary ids as found in WriteCommand::getPrimaryKey()['id']
     *
     * @return array<string, CustomerState> keyed by lowercase hex id
     */
    public function load(array $idsBytes): array
    {
        if ($idsBytes === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `customer` WHERE `id` IN (:ids)',
            ['ids' => array_values($idsBytes)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $states = [];
        foreach ($rows as $row) {
            $hex = Uuid::fromBytesToHex((string) $row['id']);
            $states[$hex] = new CustomerState($hex, $row);
        }

        return $states;
    }
}
