<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber;

use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerRerouter;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\Values;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Guards customer writes of the JTL-Connector. Runs on EntityWriteEvent, which the DBAL
 * EntityWriteGateway dispatches with the exact WriteCommand instances it executes afterwards.
 * `enabled` is the master switch (per sales channel of the customer). For connector writes:
 *  - Customer number: on an update a changed protected column is reverted to its current DB
 *    value (WriteCommand::addPayload overwrites the key — a key cannot be removed); on an insert
 *    the supplied customer_number is replaced by one reserved from the shop's own number range.
 *  - Same-person check: an update carrying the account's own e-mail is applied in full; one
 *    carrying a different e-mail is another customer being pushed onto this account, so every
 *    column, custom field and address of that customer in the write is kept or recorded
 *    (SamePersonGuard / AddressGuard), and in enforce the write is handed to CustomerRerouter,
 *    which applies it after the request to the registered account with that e-mail.
 * In log_only mode nothing is changed, only logged. Any internal failure is caught: the
 * write must never be blocked by the guard itself.
 */
final class CustomerNumberWriteProtection implements EventSubscriberInterface
{
    private const FIELD_CUSTOMER_NUMBER = GuardConfigProvider::FIELD_CUSTOMER_NUMBER;

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly ConnectorSourceDetector $sourceDetector,
        private readonly CustomerStateLoader $stateLoader,
        private readonly NumberRangeValueGeneratorInterface $numberRangeGenerator,
        private readonly GuardLogger $guardLogger,
        private readonly AddressGuard $addressGuard,
        private readonly SamePersonGuard $samePersonGuard,
        private readonly CustomerRerouter $rerouter,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityWriteEvent::class => 'onEntityWrite'];
    }

    public function onEntityWrite(EntityWriteEvent $event): void
    {
        try {
            $this->guard($event);
        } catch (\Throwable $e) {
            $this->log(
                'error',
                'jtl_connector_guard failed, customer write left untouched: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Routes every diagnostic line through the channel logger first, falling back to
     * Shopware's main logger if the channel itself is broken. Never throws: a failure of
     * both loggers must not be able to escape into the DAL write.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->{$level}($message, $context);

            return;
        } catch (\Throwable) {
            // channel logger is broken, fall through to the fallback below
        }

        try {
            $this->fallbackLogger->{$level}($message, $context);
        } catch (\Throwable) {
            // both loggers are broken; swallow, never let logging break the customer write.
        }
    }

    private function guard(EntityWriteEvent $event): void
    {
        $customerCommands = $event->getCommandsForEntity(CustomerDefinition::ENTITY_NAME);
        $addressCommands = $event->getCommandsForEntity(CustomerAddressDefinition::ENTITY_NAME);
        if ($customerCommands === [] && $addressCommands === []) {
            return;
        }

        $context = $event->getContext();
        $source = $context->getSource();
        // Cheap pre-filter: only Admin API integration writes can be the connector.
        if (!$source instanceof AdminApiSource || $source->getIntegrationId() === null || $source->getUserId() !== null) {
            return;
        }

        // The integration is matched against every scope of the setting: picking it while a sales
        // channel is selected in the admin must not leave the plugin silently idle. Whether the
        // plugin is switched on is then decided per customer, from that customer's sales channel.
        $connector = $this->sourceDetector->resolve($context, $this->configProvider->connectorIntegrationIds());
        if ($connector === null) {
            return;
        }

        $updates = [];
        $jsonUpdates = [];
        $inserts = [];
        $insertedIds = [];
        $deletedIds = [];
        foreach ($customerCommands as $command) {
            // JsonUpdateCommand extends UpdateCommand: its payload keys are custom-field keys, not
            // columns, so it must be routed before the plain-update branch.
            if ($command instanceof JsonUpdateCommand) {
                $jsonUpdates[] = $command;
            } elseif ($command instanceof UpdateCommand) {
                $updates[] = $command;
            } elseif ($command instanceof InsertCommand) {
                $inserts[] = $command;
                $hex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null);
                if ($hex !== null) {
                    $insertedIds[] = $hex;
                }
            } elseif ($command instanceof DeleteCommand) {
                $hex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null);
                if ($hex !== null) {
                    $deletedIds[] = $hex;
                }
            }
        }

        $reroutes = [];
        $differentPersonIds = $this->guardUpdates($updates, $jsonUpdates, $connector, $reroutes);
        $this->guardInserts($inserts, $connector, $context);

        if ($addressCommands !== []) {
            $this->addressGuard->guard($event, $addressCommands, $insertedIds, $deletedIds, $connector, $differentPersonIds);
        }

        if ($differentPersonIds === []) {
            return;
        }

        // The event fires before the write is executed. Only a write that actually went through is
        // handed to the right account; a rolled-back one (a failing sync batch, reject_write, ...)
        // must leave nothing behind. Neither callback may throw: success() runs inside the
        // gateway's try block.
        $event->addSuccess(function () use ($reroutes): void {
            foreach ($reroutes as [$state, $sent, $config, $source]) {
                try {
                    $this->rerouter->queue($state, $sent, $config, $source);
                } catch (\Throwable $e) {
                    $this->log('error', 'jtl_connector_guard: could not queue the reroute for customer ' . $state->id . ': ' . $e->getMessage(), ['exception' => $e]);
                }
            }
        });
        $event->addError(function () use ($differentPersonIds): void {
            $this->log(
                'warning',
                'jtl_connector_guard: the connector write the guard intervened in FAILED and was rolled back; the audit rows just written for these customers describe an attempt, nothing was changed or rerouted',
                ['customerIds' => $differentPersonIds]
            );
        });
    }

    /**
     * @param list<UpdateCommand>     $updates
     * @param list<JsonUpdateCommand> $jsonUpdates custom_fields writes
     * @param list<array{0: CustomerState, 1: array<string, mixed>, 2: \Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig, 3: ConnectorSource}> $reroutes filled with the writes to hand to the right account once this write succeeded
     *
     * @return list<string> hex ids of the customers this write carries a different e-mail for
     */
    private function guardUpdates(array $updates, array $jsonUpdates, ConnectorSource $connector, array &$reroutes): array
    {
        if ($updates === [] && $jsonUpdates === []) {
            return [];
        }

        $ids = [];
        foreach ([...$updates, ...$jsonUpdates] as $command) {
            $ids[] = (string) $command->getPrimaryKey()['id'];
        }
        $states = $this->stateLoader->load(array_values(array_unique($ids)));

        // A sync batch may hold several commands for one customer. The person is judged per
        // customer, not per command: one command with a foreign e-mail flags them all, otherwise a
        // second command without an e-mail would slip through as "the same person".
        $byCustomer = [];
        foreach ($updates as $command) {
            $byCustomer[Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id'])][] = $command;
        }

        $differentPersonIds = [];

        // Per-customer try/catch: one bad row (state loader race, a throwing log sink, ...)
        // must not stop the remaining customers in the same batch from being guarded.
        foreach ($byCustomer as $idHex => $commands) {
            $idHex = (string) $idHex;

            try {
                $reroute = $this->guardCustomer($commands, $idHex, $states[$idHex] ?? null, $connector);
                if ($reroute === null) {
                    continue;
                }
                $differentPersonIds[] = $idHex;
                if ($reroute !== []) {
                    $reroutes[] = $reroute;
                }
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }

        foreach ($jsonUpdates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);

            try {
                $state = $states[$idHex] ?? null;
                if ($state === null || !\in_array($idHex, $differentPersonIds, true)) {
                    continue;
                }
                $config = $this->configProvider->load($state->getSalesChannelId());
                $this->samePersonGuard->guardCustomerCustomFields($command, $idHex, $state, $config->samePerson, $connector);
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard custom fields of customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }

        return $differentPersonIds;
    }

    /**
     * @param non-empty-list<UpdateCommand> $commands every plain update of this customer in the write
     *
     * @return array{0: CustomerState, 1: array<string, mixed>, 2: \Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig, 3: ConnectorSource}|array{}|null
     *                                                                                              null = same person; [] = different person, nothing to reroute; otherwise the reroute to queue
     */
    private function guardCustomer(array $commands, string $idHex, ?CustomerState $state, ConnectorSource $connector): ?array
    {
        if ($state === null) {
            return null; // row vanished between extraction and event; nothing to protect
        }

        $config = $this->configProvider->load($state->getSalesChannelId());
        if (!$config->enabled) {
            return null; // master switch
        }

        // Snapshot the payloads exactly as the connector sent them, before guardProtectedFields()
        // below may revert a protected field via addPayload() (@internal — a key can only be
        // overwritten, never removed). The same-person check needs what was actually attempted.
        $different = false;
        $merged = [];
        $foreignEmail = null;
        $sent = [];
        foreach ($commands as $index => $command) {
            $sent[$index] = $command->getPayload();
            $merged = array_merge($merged, $sent[$index]);
            if ($config->samePerson->enabled && SamePersonGuard::isDifferentPerson($sent[$index], $state)) {
                $different = true;
                $foreignEmail ??= $sent[$index]['email'];
            }
        }

        foreach ($commands as $index => $command) {
            $handled = $this->guardProtectedFields($command, $idHex, $state, $config, $connector);

            if ($different) {
                // Nothing of a different person's write may land. Columns the number guard already
                // wrote back are done; a column it merely observed (log_only) is still kept here.
                $reverted = $config->enforce ? $handled : [];
                $this->samePersonGuard->guardCustomerColumns($command, $idHex, $state, $config->samePerson, $connector, $reverted, $sent[$index]);
            }
        }

        if (!$different) {
            return null; // same person: Wawi may edit everything the number guard does not protect
        }

        // The data is right, only the account is wrong: hand it to the account it was meant for.
        if (!$config->samePerson->reroutes()) {
            return [];
        }
        $merged['email'] = $foreignEmail;

        return [$state, $merged, $config->samePerson, $connector];
    }

    /**
     * The configurable block list (always containing customer_number).
     *
     * @return list<string> the protected fields present in this write
     */
    private function guardProtectedFields(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector): array
    {
        $payload = $command->getPayload();
        $present = [];
        foreach ($config->protectedFields as $field) {
            if (!$command->hasField($field)) {
                continue;
            }
            $present[] = $field;

            $attempted = $payload[$field];
            $current = $state->get($field);
            if (Values::same($attempted, $current)) {
                continue;
            }

            if ($config->enforce) {
                $command->addPayload($field, $current);
            }

            $this->guardLogger->log($this->entry(
                GuardLogEntry::ACTION_BLOCKED_UPDATE,
                $config->mode(),
                $field,
                $idHex,
                $state,
                $connector,
                Values::render($field, $current),
                Values::render($field, $attempted),
            ));
        }

        return $present;
    }

    private function entry(string $action, string $mode, string $field, string $idHex, CustomerState $state, ConnectorSource $connector, ?string $current, ?string $attempted): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: $field,
            customerId: $idHex,
            email: $state->getEmail(),
            firstName: $state->getFirstName(),
            lastName: $state->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: null,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $state->getSalesChannelId(),
        );
    }

    /**
     * @param list<InsertCommand> $inserts
     */
    private function guardInserts(array $inserts, ConnectorSource $connector, Context $context): void
    {
        // Per-command try/catch, mirroring guardUpdates: e.g. the number range generator
        // failing for one new customer must not stop the others in the same batch.
        foreach ($inserts as $command) {
            $idHex = Values::hexOrNull($command->getPrimaryKey()['id'] ?? null);

            try {
                $this->guardInsert($command, $connector, $context);
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard new customer %s, left untouched: %s', $idHex ?? 'unknown', $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }
    }

    private function guardInsert(InsertCommand $command, ConnectorSource $connector, Context $context): void
    {
        $payload = $command->getPayload();
        $salesChannelId = Values::hexOrNull($payload['sales_channel_id'] ?? null);

        $config = $this->configProvider->load($salesChannelId);
        if (!$config->enabled) {
            return;
        }

        $attempted = Values::render(self::FIELD_CUSTOMER_NUMBER, $payload[self::FIELD_CUSTOMER_NUMBER] ?? null);
        $assigned = null;
        if ($config->enforce) {
            // Resolve the value first: only once it is known do we touch the payload, so a
            // failing number range generator leaves this insert exactly as the connector sent it.
            $assigned = $this->numberRangeGenerator->getValue(CustomerDefinition::ENTITY_NAME, $context, $salesChannelId);
            $command->addPayload(self::FIELD_CUSTOMER_NUMBER, $assigned);
        }

        $this->guardLogger->log(new GuardLogEntry(
            action: GuardLogEntry::ACTION_REMAPPED_CREATE,
            mode: $config->mode(),
            field: self::FIELD_CUSTOMER_NUMBER,
            customerId: Values::hexOrNull($command->getPrimaryKey()['id'] ?? null),
            email: Values::render('email', $payload['email'] ?? null),
            firstName: Values::render('first_name', $payload['first_name'] ?? null),
            lastName: Values::render('last_name', $payload['last_name'] ?? null),
            currentValue: null,
            attemptedValue: $attempted,
            assignedValue: $assigned,
            integrationId: $connector->integrationId,
            integrationLabel: $connector->label,
            salesChannelId: $salesChannelId,
        ));
    }
}
