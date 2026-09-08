<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Subscriber;

use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\AddressGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\FieldGuard;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfigProvider;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\IdentityGuardConfig;
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
 * Makes Shopware the owner of a customer's number (feature 001) and identity — email and name
 * (feature 002) — against the JTL-Connector.
 *
 * Runs on EntityWriteEvent, which the DBAL EntityWriteGateway dispatches with the exact
 * WriteCommand instances it executes afterwards. For connector writes only:
 *  - UpdateCommand: a changed protected column is reverted to its current DB value
 *    (WriteCommand::addPayload overwrites the key — a key cannot be removed), every other
 *    field of the same write is left alone;
 *  - InsertCommand: the supplied customer_number is replaced by a value reserved from the
 *    shop's own `customer` number range for the customer's sales channel.
 *  - UpdateCommand, identity guard: an email swap (case-insensitive, trimmed inequality) and,
 *    per policy, a name change are reverted the same way; unprotected name-only changes are
 *    logged as observed and applied.
 *  - Feature 003 (FieldGuard / AddressGuard): every other column of the customer, every custom
 *    field key and every column of the customer's addresses is kept or recorded; see those
 *    services. This class only routes the commands and runs connector detection once per event.
 * In log_only mode nothing is changed, only logged. Any internal failure is caught: the
 * write must never be blocked by the guard itself.
 */
final class CustomerNumberWriteProtection implements EventSubscriberInterface
{
    private const FIELD_CUSTOMER_NUMBER = GuardConfigProvider::FIELD_CUSTOMER_NUMBER;

    private const FIELD_EMAIL = 'email';

    private const NAME_FIELDS = ['first_name', 'last_name'];

    public function __construct(
        private readonly GuardConfigProvider $configProvider,
        private readonly ConnectorSourceDetector $sourceDetector,
        private readonly CustomerStateLoader $stateLoader,
        private readonly NumberRangeValueGeneratorInterface $numberRangeGenerator,
        private readonly GuardLogger $guardLogger,
        private readonly FieldGuard $fieldGuard,
        private readonly AddressGuard $addressGuard,
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

        $globalConfig = $this->configProvider->load(null);
        if (!$globalConfig->enabled && !$globalConfig->identity->enabled && !$globalConfig->fieldGuard->enabled) {
            return;
        }

        $connector = $this->sourceDetector->resolve($context, $globalConfig);
        if ($connector === null) {
            $this->log(
                'debug',
                'jtl_connector_guard: admin-api integration write to customer not identified as the connector, left untouched',
                ['integrationId' => strtolower($source->getIntegrationId())]
            );

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

        $this->guardUpdates($updates, $jsonUpdates, $connector);
        $this->guardInserts($inserts, $connector, $context);

        if ($addressCommands !== [] && $globalConfig->fieldGuard->enabled) {
            $this->addressGuard->guard($event, $addressCommands, $insertedIds, $deletedIds, $connector);
        }
    }

    /**
     * @param list<UpdateCommand>     $updates
     * @param list<JsonUpdateCommand> $jsonUpdates custom_fields writes (feature 003)
     */
    private function guardUpdates(array $updates, array $jsonUpdates, ConnectorSource $connector): void
    {
        if ($updates === [] && $jsonUpdates === []) {
            return;
        }

        $ids = [];
        foreach ([...$updates, ...$jsonUpdates] as $command) {
            $ids[] = (string) $command->getPrimaryKey()['id'];
        }
        $states = $this->stateLoader->load(array_values(array_unique($ids)));

        // Per-command try/catch: one bad row (state loader race, a throwing log sink, ...)
        // must not stop the remaining commands in the same batch from being guarded.
        foreach ($updates as $command) {
            $idHex = Uuid::fromBytesToHex((string) $command->getPrimaryKey()['id']);

            try {
                $this->guardUpdate($command, $idHex, $states[$idHex] ?? null, $connector);
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
                if ($state === null) {
                    continue;
                }
                $config = $this->configProvider->load($state->getSalesChannelId());
                if ($config->fieldGuard->enabled) {
                    $this->fieldGuard->guardCustomerCustomFields($command, $idHex, $state, $config, $connector);
                }
            } catch (\Throwable $e) {
                $this->log(
                    'error',
                    sprintf('jtl_connector_guard: failed to guard custom fields of customer %s, left untouched: %s', $idHex, $e->getMessage()),
                    ['exception' => $e, 'customerId' => $idHex]
                );
            }
        }
    }

    private function guardUpdate(UpdateCommand $command, string $idHex, ?CustomerState $state, ConnectorSource $connector): void
    {
        if ($state === null) {
            return; // row vanished between extraction and event; nothing to protect
        }

        $config = $this->configProvider->load($state->getSalesChannelId());

        // Snapshot the payload exactly as the connector sent it, before guardProtectedFields()
        // below may revert a protected field via addPayload() (@internal — a key can only be
        // overwritten, never removed). The identity guard needs what was actually attempted,
        // not whatever 001 already wrote back into the command.
        $sent = $command->getPayload();

        // Fields the 001 block list already handled (whether or not they changed) are never
        // reverted or logged a second time by the identity guard — but an email 001 already
        // owns is still read for its swap signal, which drives the name policy regardless.
        $handled = [];
        if ($config->enabled) {
            $handled = $this->guardProtectedFields($command, $idHex, $state, $config, $connector);
        }

        if ($config->identity->enabled) {
            $this->guardIdentity($command, $idHex, $state, $config, $connector, $handled, $sent);
        }

        if ($config->fieldGuard->enabled) {
            $this->fieldGuard->guardCustomerColumns($command, $idHex, $state, $config, $connector, $handled, $sent);
        }
    }

    /**
     * Feature 001: the configurable block list (always containing customer_number).
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

    /**
     * Feature 002: email is the hard identity key; a name change is guarded only per policy.
     *
     * @param list<string>        $handled fields already processed by the 001 block list
     * @param array<string, mixed> $sent   the payload exactly as the connector sent it, captured
     *                                      before guardProtectedFields() could revert a field
     */
    private function guardIdentity(UpdateCommand $command, string $idHex, CustomerState $state, GuardConfig $config, ConnectorSource $connector, array $handled, array $sent): void
    {
        $identity = $config->identity;

        // The swap signal is taken from the payload as sent — never from $command->getPayload()
        // here, because by this point guardProtectedFields() may already have reverted a field
        // 001 also owns (e.g. email in protectedFields + enforce), which would make an attempted
        // swap read back as "unchanged". $handled still gates whether *this* step may act on the
        // field a second time, independent of what drove the swap signal itself.
        $emailSwapped = \array_key_exists(self::FIELD_EMAIL, $sent)
            && !$this->sameEmail($sent[self::FIELD_EMAIL], $state->getEmail());

        $changedNames = [];
        foreach (self::NAME_FIELDS as $field) {
            if (\array_key_exists($field, $sent) && !\in_array($field, $handled, true) && !Values::same($sent[$field], $state->get($field))) {
                $changedNames[] = $field;
            }
        }

        // $handled only gates whether the identity step itself may revert/log the email: when
        // 001's block list already processed it, this step must not double-revert or double-log.
        if ($emailSwapped && !\in_array(self::FIELD_EMAIL, $handled, true)) {
            $this->guardIdentityField($command, self::FIELD_EMAIL, true, $idHex, $state, $identity, $connector);
        }

        if ($identity->protectName === IdentityGuardConfig::PROTECT_NAME_OFF) {
            return;
        }
        $protectNames = $identity->protectName === IdentityGuardConfig::PROTECT_NAME_ALWAYS || $emailSwapped;
        foreach ($changedNames as $field) {
            $this->guardIdentityField($command, $field, $protectNames, $idHex, $state, $identity, $connector);
        }
    }

    private function guardIdentityField(UpdateCommand $command, string $field, bool $protect, string $idHex, CustomerState $state, IdentityGuardConfig $identity, ConnectorSource $connector): void
    {
        $attempted = $command->getPayload()[$field];
        $current = $state->get($field);

        $kept = $protect && $identity->enforce;
        if ($kept) {
            $command->addPayload($field, $current);
        }

        $this->guardLogger->log($this->entry(
            $kept ? GuardLogEntry::ACTION_BLOCKED_IDENTITY : GuardLogEntry::ACTION_OBSERVED_IDENTITY,
            $identity->mode(),
            $field,
            $idHex,
            $state,
            $connector,
            Values::render($field, $current),
            Values::render($field, $attempted),
        ));
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
     * Email identity comparison: case-insensitive and trimmed (spec 002, technical notes).
     */
    private function sameEmail(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
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
