<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

/**
 * @category  Shopware
 * @package   Shopware\Tests
 * @copyright Copyright (c) 2012, shopware AG (http://www.shopware.de)
 */
class Shopware_Tests_Controllers_Backend_CustomerTest extends Enlight_Components_Test_Controller_TestCase
{
    /** @var Shopware\Components\Model\ModelManager */
    private $manager = null;

    /**@var $model \Shopware\Models\Customer\Customer*/
    protected $repository = null;

    /**
     * Standard set up for every test - just disable auth
     */
    public function setUp()
    {
        parent::setUp();

        $this->manager    = Shopware()->Models();
        $this->repository = Shopware()->Models()->getRepository('Shopware\Models\Customer\Customer');

        // disable auth and acl
        Shopware()->Plugins()->Backend()->Auth()->setNoAuth();
        Shopware()->Plugins()->Backend()->Auth()->setNoAcl();
    }

    /**
     * Test saveAction controller action - change payment mean
     *
     * Get a random customer. Change payment method to debit
     */
    public function testChangeCustomerPaymentMean()
    {
        $dummyData = new \Shopware\Models\Customer\Customer();
        $dummyData->setEmail('test@phpunit.org');
        $this->manager->persist($dummyData);
        $this->manager->flush();
        $this->assertEquals(0, $dummyData->getPaymentId());

        $debit = $this->manager
            ->getRepository('Shopware\Models\Payment\Payment')
            ->findOneBy(array('name' => 'debit'));

        $params = array(
            'id' => $dummyData->getId(),
            'paymentId' => $debit->getId(),
        );
        $this->Request()->setMethod('POST')->setPost($params);
        $this->dispatch('/backend/Customer/save');
        $jsonBody = $this->View()->getAssign();

        $this->assertTrue($this->View()->success);
        $this->assertEquals($debit->getId(), $jsonBody['data']['paymentId']);

        $this->manager->refresh($dummyData);
        $this->assertEquals($debit->getId(), $dummyData->getPaymentId());

        $this->manager->remove($dummyData);
        $this->manager->flush();
    }

    /**
     * Test saveAction controller action - new customer with debit payment data
     */
    public function testAddCustomerPaymentDataWithDebit()
    {
        $debit = $this->manager
            ->getRepository('Shopware\Models\Payment\Payment')
            ->findOneBy(array('name' => 'debit'));

        $params = array(
            'paymentId' => $debit->getId(),
            'email' => 'test@shopware.de',
            'newPassword' => '222',
            'paymentData' => array(array(
                'accountHolder'  => 'Account Holder Name',
                'accountNumber'  => '1234567890',
                'bankCode'       => '2345678901',
                'bankName'       => 'Bank name',
                'bic'            => '',
                'iban'           => '',
                'useBillingData' => false
            ))
        );
        $this->Request()->setMethod('POST')->setPost($params);
        $this->dispatch('/backend/Customer/save');
        $jsonBody = $this->View()->getAssign();

        $this->assertTrue($this->View()->success);
        $this->assertEquals($debit->getId(), $jsonBody['data']['paymentId']);

        $dummyData = $this->repository->find($this->View()->data['id']);

        $this->assertEquals($debit->getId(), $dummyData->getPaymentId());
        $this->assertCount(1, $dummyData->getPaymentData()->toArray());

        /** @var \Shopware\Models\Customer\PaymentData $paymentData */
        $paymentData = array_shift($dummyData->getPaymentData()->toArray());
        $this->assertInstanceOf('\Shopware\Models\Customer\PaymentData', $paymentData);
        $this->assertEquals('Account Holder Name', $paymentData->getAccountHolder());
        $this->assertEquals('1234567890', $paymentData->getAccountNumber());
        $this->assertEquals('2345678901', $paymentData->getBankCode());
        $this->assertEquals('Bank name', $paymentData->getBankName());
        $this->assertEmpty($paymentData->getBic());
        $this->assertEmpty($paymentData->getIban());
        $this->assertFalse($paymentData->getUseBillingData());

        return $dummyData->getId();
    }

    /**
     * Test saveAction controller action - Update an existing customer
     *
     * @depends testAddCustomerPaymentDataWithDebit
     */
    public function testUpdateCustomerPaymentDataWithSepa($dummyDataId)
    {
        $dummyData = $this->repository->find($dummyDataId);
        $sepa = $this->manager
            ->getRepository('Shopware\Models\Payment\Payment')
            ->findOneBy(array('name' => 'sepa'));
        $debit = $this->manager
            ->getRepository('Shopware\Models\Payment\Payment')
            ->findOneBy(array('name' => 'debit'));

        $this->assertEquals($debit->getId(), $dummyData->getPaymentId());
        $this->assertCount(1, $dummyData->getPaymentData()->toArray());

        $params = array(
            'id' => $dummyData->getId(),
            'paymentId' => $sepa->getId(),
            'paymentData' => array(array(
                'accountHolder'  => '',
                'accountNumber'  => '',
                'bankCode'       => '',
                'bankName'       => 'European bank name',
                'bic'            => '123bic312',
                'iban'           => '456iban654',
                'useBillingData' => true
            ))
        );
        $this->Request()->setMethod('POST')->setPost($params);
        $this->dispatch('/backend/Customer/save');
        $jsonBody = $this->View()->getAssign();

        $this->assertTrue($this->View()->success);
        $this->assertEquals($sepa->getId(), $jsonBody['data']['paymentId']);

        $this->manager->refresh($dummyData);

        $this->assertEquals($sepa->getId(), $dummyData->getPaymentId());
        $paymentDataArray = $dummyData->getPaymentData()->toArray();
        $this->assertCount(2, $paymentDataArray);

        // Old debit payment data is still there, it's just not used currently
        /** @var \Shopware\Models\Customer\PaymentData $paymentData */
        $paymentData = array_shift($paymentDataArray);
        $this->assertInstanceOf('\Shopware\Models\Customer\PaymentData', $paymentData);
        $this->assertEquals('Account Holder Name', $paymentData->getAccountHolder());
        $this->assertEquals('1234567890', $paymentData->getAccountNumber());
        $this->assertEquals('2345678901', $paymentData->getBankCode());
        $this->assertEquals('Bank name', $paymentData->getBankName());
        $this->assertEmpty($paymentData->getBic());
        $this->assertEmpty($paymentData->getIban());
        $this->assertFalse($paymentData->getUseBillingData());

        // New SEPA data
        /** @var \Shopware\Models\Customer\PaymentData $paymentData */
        $paymentData = array_shift($paymentDataArray);
        $this->assertInstanceOf('\Shopware\Models\Customer\PaymentData', $paymentData);
        $this->assertEmpty($paymentData->getAccountHolder());
        $this->assertEmpty($paymentData->getAccountNumber());
        $this->assertEmpty($paymentData->getBankCode());
        $this->assertEquals('European bank name', $paymentData->getBankName());
        $this->assertEquals('123bic312', $paymentData->getBic());
        $this->assertEquals('456iban654', $paymentData->getIban());
        $this->assertTrue($paymentData->getUseBillingData());

        $this->manager->remove($dummyData);
        $this->manager->flush();
    }

    /**
     * Test that performOrderAction() sets the correct cookie settings
     */
    public function testPerformOrderAction()
    {
        //set the user id
        $params = array(
            'id' => 1
        );
        $this->Request()->setParams($params);

        /** @var Enlight_Controller_Response_ResponseTestCase $response */
        $response = $this->dispatch('backend/Customer/performOrder');

        $headerLocation = $response->getHeader("Location");
        $this->reset();
        $this->assertNotEmpty($headerLocation);
        $newLocation = explode('/backend/', $headerLocation);
        $response = $this->dispatch('backend/'.$newLocation[1]);

        $cookie = $response->getFullCookie('session-1');
        $this->assertTrue(strpos($headerLocation, $cookie['value']) !== false);
        $this->assertEquals(0, $cookie['expire']);
    }

    /**
     * SW-6667 Tests if the customer has an id to check if lazy loading was fetching the data
     */
    public function testCustomerId()
    {
        $customer = Shopware()->Models()->find('Shopware\Models\Customer\Customer', 1);

        $this->assertInstanceOf('\Shopware\Models\Customer\Customer', $customer);
        $this->assertEquals('1', $customer->getGroup()->getId());
    }
}
