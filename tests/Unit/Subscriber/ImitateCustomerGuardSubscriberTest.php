<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Subscriber\ImitateCustomerGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Symfony\Component\Validator\Validation;

#[CoversClass(ImitateCustomerGuardSubscriber::class)]
class ImitateCustomerGuardSubscriberTest extends TestCase
{
    private const CUSTOMER_DE = '0190ffffffffffffffffffffffffff01';
    private const CUSTOMER_EN = '0190ffffffffffffffffffffffffff02';
    private const CUSTOMER_EN_BOUND_DE = '0190ffffffffffffffffffffffffff03';

    public function testOwnCustomersInOwnChannels(): void
    {
        static::assertSame([], $this->violations(self::CUSTOMER_DE, Ids::DE));
        static::assertSame([], $this->violations(self::CUSTOMER_EN_BOUND_DE, Ids::DE));
    }

    public function testOtherChannelsAndTheirCustomersAreRefused(): void
    {
        static::assertSame(['customerId'], $this->violations(self::CUSTOMER_EN, Ids::DE));
        static::assertSame(['salesChannelId'], $this->violations(self::CUSTOMER_DE, Ids::EN));
        static::assertSame(['customerId'], $this->violations('0190ffffffffffffffffffffffffff99', Ids::DE));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertSame([], $this->violations(self::CUSTOMER_EN, Ids::EN, Ids::context(Ids::FREE_USER)));
    }

    /**
     * @return list<string> fields with violations
     */
    private function violations(string $customerId, string $salesChannelId, ?Context $context = null): array
    {
        $definition = new DataValidationDefinition('impersonation.generate-token');
        $data = new DataBag(['customerId' => $customerId, 'salesChannelId' => $salesChannelId]);
        $event = new BuildValidationEvent($definition, $data, $context ?? Ids::context());
        $lookup = new StaticScopeLookup(customers: [
            self::CUSTOMER_DE => ['salesChannelId' => Ids::DE, 'boundSalesChannelId' => null],
            self::CUSTOMER_EN => ['salesChannelId' => Ids::EN, 'boundSalesChannelId' => null],
            self::CUSTOMER_EN_BOUND_DE => ['salesChannelId' => Ids::EN, 'boundSalesChannelId' => Ids::DE],
        ]);
        (new ImitateCustomerGuardSubscriber(Ids::scope(), $lookup))->onBuildValidation($event);

        $fields = [];
        $validator = Validation::createValidator();
        foreach ($definition->getProperties() as $field => $constraints) {
            if (\count($validator->validate($data->get($field), $constraints)) > 0) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
