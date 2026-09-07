<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Core\Content\GuardLog;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class GuardLogEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId = null;

    protected ?string $email = null;

    protected ?string $firstName = null;

    protected ?string $lastName = null;

    protected string $field;

    protected ?string $currentValue = null;

    protected ?string $attemptedValue = null;

    protected ?string $assignedValue = null;

    protected string $action;

    protected string $mode;

    protected ?string $integrationId = null;

    protected ?string $integrationLabel = null;

    protected ?string $salesChannelId = null;

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function getAttemptedValue(): ?string
    {
        return $this->attemptedValue;
    }

    public function getAssignedValue(): ?string
    {
        return $this->assignedValue;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getIntegrationId(): ?string
    {
        return $this->integrationId;
    }

    public function getIntegrationLabel(): ?string
    {
        return $this->integrationLabel;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
