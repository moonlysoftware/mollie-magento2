<?php

namespace Mollie\Payment\Test\Integration\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mollie\Api\Endpoints\MethodEndpoint;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Payment\Helper\General;
use Mollie\Payment\Model\Client\Orders;
use Mollie\Payment\Model\Client\Payments;
use Mollie\Payment\Model\Mollie;
use Mollie\Payment\Test\Integration\IntegrationTestCase;

class MollieTest extends IntegrationTestCase
{
    public function processTransactionUsesTheCorrectApiProvider()
    {
        return [
            'orders' => ['ord_abcdefg', 'orders'],
            'payments' => ['tr_abcdefg', 'payments'],
        ];
    }

    /**
     * @dataProvider processTransactionUsesTheCorrectApiProvider
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/apikey_test test_dummyapikeywhichmustbe30characterslong
     * @magentoConfigFixture default_store payment/mollie_general/type test
     */
    public function testProcessTransactionUsesTheCorrectApi($orderId, $type)
    {
        $order = $this->loadOrder('100000001');
        $order->setMollieTransactionId($orderId);
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $mollieHelperMock = $this->createMock(General::class);
        $mollieHelperMock->method('getApiKey')->willReturn('test_TEST_API_KEY_THAT_IS_LONG_ENOUGH');

        $ordersApiMock = $this->createMock(Orders::class);
        $paymentsApiMock = $this->createMock(Payments::class);

        if ($type == 'orders') {
            $ordersApiMock->expects($this->once())->method('processTransaction');
        }

        if ($type == 'payments') {
            $paymentsApiMock->expects($this->once())->method('processTransaction');
        }

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class, [
            'ordersApi' => $ordersApiMock,
            'paymentsApi' => $paymentsApiMock,
            'mollieHelper' => $mollieHelperMock,
        ]);

        $instance->processTransaction($order->getEntityId());
    }

    public function testStartTransactionWithMethodOrder()
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setEntityId(1);

        $helperMock = $this->createMock(\Mollie\Payment\Helper\General::class);
        $helperMock->method('getApiKey')->willReturn('test_dummyapikeywhichmustbe30characterslong');
        $helperMock->method('getApiMethod')->willReturn('order');

        $ordersApiMock = $this->createMock(Orders::class);
        $ordersApiMock->method('startTransaction')->willReturn('order');

        $paymentsApiMock = $this->createMock(Payments::class);
        $paymentsApiMock->expects($this->never())->method('startTransaction');

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class, [
            'ordersApi' => $ordersApiMock,
            'paymentsApi' => $paymentsApiMock,
            'mollieHelper' => $helperMock,
        ]);

        $result = $instance->startTransaction($order);

        $this->assertEquals('order', $result);
    }

    public function testStartTransactionWithMethodPayment()
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setEntityId(1);

        $helperMock = $this->createMock(\Mollie\Payment\Helper\General::class);
        $helperMock->method('getApiKey')->willReturn('test_dummyapikeywhichmustbe30characterslong');
        $helperMock->method('getApiMethod')->willReturn('payment');

        $ordersApiMock = $this->createMock(Orders::class);
        $ordersApiMock->expects($this->never())->method('startTransaction');

        $paymentsApiMock = $this->createMock(Payments::class);
        $paymentsApiMock->method('startTransaction')->willReturn('payment');

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class, [
            'ordersApi' => $ordersApiMock,
            'paymentsApi' => $paymentsApiMock,
            'mollieHelper' => $helperMock,
        ]);

        $result = $instance->startTransaction($order);

        $this->assertEquals('payment', $result);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Mollie\Api\Exceptions\ApiException
     * @throws \ReflectionException
     */
    public function testRetriesOnACurlTimeout()
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setEntityId(1);

        $helperMock = $this->createMock(\Mollie\Payment\Helper\General::class);
        $helperMock->method('getApiKey')->willReturn('test_dummyapikeywhichmustbe30characterslong');
        $helperMock->method('getApiMethod')->willReturn('order');

        $ordersApiMock = $this->createMock(Orders::class);
        $ordersApiMock->expects($this->exactly(3))->method('startTransaction')->willThrowException(
            new ApiException(
                'cURL error 28: Connection timed out after 10074 milliseconds ' .
                '(see http://curl.haxx.se/libcurl/c/libcurl-errors.html)'
            )
        );

        $paymentsApiMock = $this->createMock(Payments::class);
        $paymentsApiMock->expects($this->once())->method('startTransaction')->willReturn('payment');

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class, [
            'ordersApi' => $ordersApiMock,
            'paymentsApi' => $paymentsApiMock,
            'mollieHelper' => $helperMock,
        ]);

        $result = $instance->startTransaction($order);

        $this->assertEquals('payment', $result);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testAssignsIssuerId()
    {
        $data = new DataObject;
        $data->setAdditionalData(['selected_issuer' => 'TESTBANK']);

        $order = $this->loadOrder('100000001');
        $payment = $order->getPayment();

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(\Mollie\Payment\Model\Methods\Ideal::class);
        $instance->setInfoInstance($payment);
        $instance->assignData($data);

        $this->assertEquals('TESTBANK', $payment->getAdditionalInformation()['selected_issuer']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testAssignsCardToken()
    {
        $data = new DataObject;
        $data->setAdditionalData(['card_token' => 'abc123']);

        $order = $this->loadOrder('100000001');
        $payment = $order->getPayment();

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(\Mollie\Payment\Model\Methods\Ideal::class);
        $instance->setInfoInstance($payment);
        $instance->assignData($data);

        $this->assertEquals('abc123', $payment->getAdditionalInformation()['card_token']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/apikey_test test_dummyapikeywhichmustbe30characterslong
     */
    public function testDoesNotFallbackOnPaymentsApiForSpecificMethods()
    {
        $this->expectException(LocalizedException::class);

        $encryptorMock = $this->createMock(EncryptorInterface::class);
        $encryptorMock->method('decrypt')->willReturn('test_dummyapikeywhichmustbe30characterslong');

        $mollieHelper = $this->objectManager->create(General::class, ['encryptor' => $encryptorMock]);

        $order = $this->loadOrder('100000001');
        $order->getPayment()->setMethod('mollie_methods_voucher');

        $ordersApi = $this->createMock(Orders::class);
        $ordersApi->expects($this->once())->method('startTransaction')->willThrowException(
            new \Exception('[test] Error while starting transaction')
        );

        $paymentsApi = $this->createMock(Payments::class);
        $paymentsApi->expects($this->never())->method('startTransaction');

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class, [
            'ordersApi' => $ordersApi,
            'paymentsApi' => $paymentsApi,
            'mollieHelper' => $mollieHelper,
        ]);

        $instance->startTransaction($order);
    }

    public function testGetIssuersHasAnSequentialIndex()
    {
        $response = new \stdClass();
        $response->issuers = [
            ['id' => 'ZZissuer', 'name' => 'ZZissuer'],
            ['id' => 'AAissuer', 'name' => 'AAissuer'],
        ];

        $methodEndpointMock = $this->createMock(MethodEndpoint::class);
        $methodEndpointMock->method('get')->willReturn($response);

        $mollieApi = new MollieApiClient;
        $mollieApi->methods = $methodEndpointMock;

        /** @var Mollie $instance */
        $instance = $this->objectManager->create(Mollie::class);

        $result = $instance->getIssuers($mollieApi, 'mollie_methods_ideal', 'radio');

        $this->assertSame(array_values($result), $result);
    }
}
