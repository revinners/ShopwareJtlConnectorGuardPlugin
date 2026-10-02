<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The `customer_address` side of the same-person check. An address is guarded only when the same
 * write identified its customer as a different person (see SamePersonGuard); every other address
 * write of the connector — the same person, or an address-only write with no e-mail to judge by —
 * is applied untouched. Guarded means:
 *  - UpdateCommand / JsonUpdateCommand: every changed column (custom-field key) is written back
 *    in enforce, recorded in log_only;
 *  - InsertCommand / DeleteCommand: cannot be dropped from the write (the DAL exposes no way to
 *    remove a command), so they are recorded one row per column — or, under the reject_write
 *    policy in enforce, a constraint violation is added to the write context, which fails the
 *    whole connector write inside the DAL transaction. A delete of the account's default address
 *    is always rejected in enforce (the default ids are kept, so it would dangle).
 * Addresses of customers inserted or deleted in the same write belong to those customers and
 * are ignored here.
 */
final class AddressGuard
{
    private const REJECT_MESSAGE = 'JTL-Connector Guard: this write carries another customer\'s e-mail and would create or delete an address of the account; the write is rejected (addressCreateDeletePolicy=reject_write, or the address is the account\'s default address)';

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly CustomerAddressStateLoader $addressLoader,
        private readonly CustomerStateLoader $customerLoader,
        private readonly GuardLogger $guardLogger,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }

    /**
     * @param list<WriteCommand> $commands               the `customer_address` commands of one write event
     * @param list<string>       $insertedCustomerIdsHex customers created in the same write
     * @param list<string>       $deletedCustomerIdsHex  customers deleted in the same write
     * @param list<string>       $differentPersonIdsHex  customers this write carries a different e-mail for
     */
    public function guard(EntityWriteEvent $event, array $commands, array $insertedCustomerIdsHex, array $deletedCustomerIdsHex, ConnectorSource $connector, array $differentPersonIdsHex = []): void
    {
        $updates = [];
        $inserts = [];
        $deletes = [];
        foreach ($commands as $command) {
            if ($command instanceof DeleteCommand) {
                $deletes[] = $command;
            } elseif ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
            }
        }

        $addressIds = [];
        foreach ([...$updates, ...$deletes] as $command) {
            $addressIds[] = (string) $command->getPrimaryKey()['id'];
        }
        $addresses = $this->addressLoader->load(array_values(array_unique($addressIds)));

        $customerIds = [];
        foreach ($addresses as $address) {
            $customerHex = $address->getCustomerId();
            if ($customerHex !== null) {
                $customerIds[$customerHex] = Uuid::fromHexToBytes($customerHex);
            }
        }
        foreach ([...$inserts, ...$updates] as $command) {
            $customerHex = Values::hexOrNull($command->getPayload()['customer_id'] ?? null);
            if ($customerHex !== null && !\in_array($customerHex, $insertedCustomerIdsHex, true)) {
                $customerIds[$customerHex] = Uuid::fromHexToBytes($customerHex);
            }
        }
        $customers = $this->customerLoader->load(array_values($customerIds));

        foreach ($updates as $command) {
            $addressHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);
            $this->safely($addressHex, function () use ($command, $addressHex, $addresses, $customers, $connector, $differentPersonIdsHex): void {
                $address = $addresses[$addressHex] ?? null;
                if ($address === null) {
                    return; // vanished between extraction and event; nothing to protect
                }
                // Guarded when the address belongs to a different-person customer — or when the
                // write tries to move it to one (`customer_id` in the payload): otherwise a third
                // customer's address could be re-parented onto the account together with the
                // foreign data.
                $owner = $customers[$address->getCustomerId() ?? ''] ?? null;
                $newOwner = $command instanceof JsonUpdateCommand ? null : ($customers[Values::hexOrNull($command->getPayload()['customer_id'] ?? null) ?? ''] ?? null);
                foreach ([$owner, $newOwner] as $customer) {
                    $rules = $customer === null ? null : $this->rules($customer, $differentPersonIdsHex);
                    if ($rules !== null) {
                        $this->guardUpdate($command, $addressHex, $address, $customer, $rules, $connector);

                        return;
                    }
                }
            });
        }

        foreach ($inserts as $command) {
            $addressHex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null) ?? 'unknown';
            $this->safely($addressHex, function () use ($event, $command, $addressHex, $insertedCustomerIdsHex, $customers, $connector, $differentPersonIdsHex): void {
                $payload = $command->getPayload();
                $customerHex = Values::hexOrNull($payload['customer_id'] ?? null);
                if ($customerHex === null || \in_array($customerHex, $insertedCustomerIdsHex, true)) {
                    return; // new customer, new address: legitimately Wawi's
                }
                $customer = $customers[$customerHex] ?? null;
                if ($customer === null) {
                    return;
                }
                $rules = $this->rules($customer, $differentPersonIdsHex);
                if ($rules === null) {
                    return;
                }
                $this->recordCreateOrDelete($event, $command, $addressHex, $payload, $customer, $rules, $connector, GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE);
            });
        }

        foreach ($deletes as $command) {
            $addressHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);
            $this->safely($addressHex, function () use ($event, $command, $addressHex, $addresses, $customers, $deletedCustomerIdsHex, $connector, $differentPersonIdsHex): void {
                $address = $addresses[$addressHex] ?? null;
                $customerHex = $address?->getCustomerId();
                if ($address === null || $customerHex === null || \in_array($customerHex, $deletedCustomerIdsHex, true)) {
                    return; // already gone, or cascading from a customer delete
                }
                $customer = $customers[$customerHex] ?? null;
                if ($customer === null) {
                    return;
                }
                $rules = $this->rules($customer, $differentPersonIdsHex);
                if ($rules === null) {
                    return;
                }
                // Deleting the account's DEFAULT address while the default ids are kept (they are
                // customer columns) would leave the account pointing at an address that no longer
                // exists — there is no foreign key to stop it. The only outcome that corrupts
                // nothing is to reject that write, whatever the policy says.
                if ($rules['enforce'] && \in_array($addressHex, [
                    Values::hexOrNull($customer->get('default_billing_address_id')),
                    Values::hexOrNull($customer->get('default_shipping_address_id')),
                ], true)) {
                    $rules['reject'] = true;
                }
                $this->recordCreateOrDelete($event, $command, $addressHex, $address->columns(), $customer, $rules, $connector, GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE);
            });
        }
    }

    /**
     * @param list<string> $differentPersonIdsHex
     *
     * @return array{enforce: bool, mode: string, reject: bool}|null null = leave the address write untouched
     */
    private function rules(CustomerState $customer, array $differentPersonIdsHex): ?array
    {
        if (!\in_array($customer->id, $differentPersonIdsHex, true)) {
            return null; // same person, or an address-only write with no e-mail to judge by
        }
        $guardConfig = $this->configProvider->load($customer->getSalesChannelId());
        $config = $guardConfig->samePerson;
        if (!$guardConfig->enabled || !$config->enabled) {
            return null;
        }

        return [
            'enforce' => $config->enforce,
            'mode' => $config->mode(),
            'reject' => $config->rejectsAddressCreateDelete(),
        ];
    }

    /**
     * @param array{enforce: bool, mode: string, reject: bool} $rules
     */
    private function guardUpdate(UpdateCommand $command, string $addressHex, CustomerAddressState $address, CustomerState $customer, array $rules, ConnectorSource $connector): void
    {
        if ($command instanceof JsonUpdateCommand) {
            if ($command->getStorageName() !== Values::CUSTOM_FIELDS_COLUMN) {
                return;
            }
            $current = Values::decodeJson($address->get(Values::CUSTOM_FIELDS_COLUMN));
            foreach ($command->getPayload() as $key => $attempted) {
                $key = (string) $key;
                $currentValue = $current[$key] ?? null;
                if (Values::sameJson($attempted, $currentValue)) {
                    continue;
                }
                if ($rules['enforce']) {
                    $command->addPayload($key, $currentValue);
                }
                $field = Values::CUSTOM_FIELDS_COLUMN . '.' . $key;
                $this->guardLogger->log($this->entry(
                    $rules['enforce'] ? GuardLogEntry::ACTION_BLOCKED_MISMATCH : GuardLogEntry::ACTION_OBSERVED_MISMATCH,
                    $rules['mode'], $field, $addressHex, $customer, $connector,
                    Values::render($field, $currentValue), Values::render($field, $attempted),
                ));
            }

            return;
        }

        foreach ($command->getPayload() as $column => $attempted) {
            $column = (string) $column;
            if (Values::isBookkeeping($column)) {
                continue;
            }
            $currentValue = $address->get($column);
            if (Values::sameStorage($attempted, $currentValue)) {
                continue;
            }
            if ($rules['enforce']) {
                $command->addPayload($column, $currentValue);
            }
            $this->guardLogger->log($this->entry(
                $rules['enforce'] ? GuardLogEntry::ACTION_BLOCKED_MISMATCH : GuardLogEntry::ACTION_OBSERVED_MISMATCH,
                $rules['mode'], $column, $addressHex, $customer, $connector,
                Values::render($column, $currentValue), Values::render($column, $attempted),
            ));
        }
    }

    /**
     * @param array<string, mixed> $columns the inserted payload (create) or the current row (delete)
     * @param array{enforce: bool, mode: string, reject: bool} $rules
     */
    private function recordCreateOrDelete(EntityWriteEvent $event, WriteCommand $command, string $addressHex, array $columns, CustomerState $customer, array $rules, ConnectorSource $connector, string $action): void
    {
        if ($rules['reject']) {
            // DeleteCommand::getPath() is always '' in Shopware 6.6 (its constructor passes '' to
            // the parent), so a rejected delete surfaces with pointer '/'; inserts carry the real path.
            $violation = new ConstraintViolation(self::REJECT_MESSAGE, null, [], null, $command->getPath(), null);
            $event->getWriteContext()->getExceptions()->add(
                new WriteConstraintViolationException(new ConstraintViolationList([$violation]), $command->getPath())
            );
            $this->guardLogger->log($this->entry(GuardLogEntry::ACTION_REJECTED_WRITE, $rules['mode'], '*', $addressHex, $customer, $connector, null, null));

            return;
        }

        $isCreate = $action === GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE;
        foreach ($columns as $column => $value) {
            $column = (string) $column;
            if (Values::isBookkeeping($column) || $value === null) {
                continue;
            }
            $rendered = Values::render($column, $value);
            $this->guardLogger->log($this->entry(
                $action, $rules['mode'], $column, $addressHex, $customer, $connector,
                $isCreate ? null : $rendered,
                $isCreate ? $rendered : null,
            ));
        }
    }

    private function entry(string $action, string $mode, string $field, string $addressHex, CustomerState $customer, ConnectorSource $connector, ?string $current, ?string $attempted): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: $field,
            customerId: $customer->id,
            email: $customer->getEmail(),
            firstName: $customer->getFirstName(),
            lastName: $customer->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: null,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $customer->getSalesChannelId(),
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: $addressHex,
        );
    }

    /**
     * Per-command try/catch, mirroring the subscriber: one bad row must not stop the others.
     */
    private function safely(string $addressHex, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $message = sprintf('jtl_connector_guard: failed to guard address %s, left untouched: %s', $addressHex, $e->getMessage());
            try {
                $this->logger->error($message, ['exception' => $e, 'addressId' => $addressHex]);
            } catch (\Throwable) {
                try {
                    $this->fallbackLogger->error($message, ['exception' => $e, 'addressId' => $addressHex]);
                } catch (\Throwable) {
                    // both loggers broken; never let logging break the write
                }
            }
        }
    }
}
