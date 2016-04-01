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

class sAdminTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var sAdmin
     */
    private $module;

    /**
     * @var sBasket
     */
    private $basketModule;

    /**
     * @var sSystem
     */
    private $systemModule;

    /**
     * @var Shopware_Components_Config
     */
    private $config;

    /**
     * @var Enlight_Components_Session_Namespace The session data
     */
    private $session;

    /**
     * @var Shopware_Components_Snippet_Manager Snippet manager
     */
    private $snippetManager;

    /**
     * @var Enlight_Controller_Front
     */
    private $front;

    public function setUp()
    {
        parent::setUp();

        Shopware()->Front()->setRequest(new Enlight_Controller_Request_RequestHttp());

        $this->module = Shopware()->Modules()->Admin();
        $this->config = Shopware()->Config();
        $this->session = Shopware()->Session();
        $this->front = Shopware()->Front();
        $this->snippetManager = Shopware()->Snippets();
        $this->basketModule = Shopware()->Modules()->Basket();
        $this->systemModule = Shopware()->System();
        $this->systemModule->sCurrency = Shopware()->Db()->fetchRow('SELECT * FROM s_core_currencies WHERE currency LIKE "EUR"');
        $this->systemModule->sSESSION_ID = null;
        $this->session->offsetSet('sessionId', null);
    }

    /**
     * @covers sAdmin::sGetPaymentMeanById
     */
    public function testsGetPaymentMeanById()
    {
        // Fetching non-existing payment means returns null
        $this->assertEmpty($this->module->sGetPaymentMeanById(0));

        // Fetching existing inactive payment means returns the data array
        $sepaData = $this->module->sGetPaymentMeanById(6);
        $this->assertInternalType('array', $sepaData);
        $this->assertArrayHasKey('id', $sepaData);
        $this->assertArrayHasKey('name', $sepaData);
        $this->assertArrayHasKey('description', $sepaData);
        $this->assertArrayHasKey('debit_percent', $sepaData);
        $this->assertArrayHasKey('surcharge', $sepaData);
        $this->assertArrayHasKey('surchargestring', $sepaData);
        $this->assertArrayHasKey('active', $sepaData);
        $this->assertArrayHasKey('esdactive', $sepaData);

        // Fetching existing active payment means returns the data array
        $debitData = $this->module->sGetPaymentMeanById(2);
        $this->assertInternalType('array', $debitData);
        $this->assertArrayHasKey('id', $debitData);
        $this->assertArrayHasKey('name', $debitData);
        $this->assertArrayHasKey('description', $debitData);
        $this->assertArrayHasKey('debit_percent', $debitData);
        $this->assertArrayHasKey('surcharge', $debitData);
        $this->assertArrayHasKey('surchargestring', $debitData);
        $this->assertArrayHasKey('active', $debitData);
        $this->assertArrayHasKey('esdactive', $debitData);

        $customer = $this->createDummyCustomer();
        $userData = array(
            "additional" => array(
                "user" => array(
                    "id" => $customer->getId(),
                    "paymentpreset" => 0
                )
            )
        );

        $this->assertEquals(0, $customer->getPaymentId());

        // Fetching active payment mean doesn't update user
        $this->module->sGetPaymentMeanById(2, $userData);
        $customerPaymentId = Shopware()->Db()->fetchOne(
            "SELECT paymentID FROM s_user WHERE id = ?",
            array($customer->getPaymentId())
        );
        $this->assertEquals(0, $customerPaymentId);

        // Test that payment method can be reset
        $this->module->sGetPaymentMeanById(6, $userData);
        $customerPaymentId = Shopware()->Db()->fetchOne(
            "SELECT paymentID FROM s_user WHERE id = ?",
            array($customer->getId())
        );
        $this->assertEquals(5, $customerPaymentId);

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sGetPaymentMeans
     */
    public function testsGetPaymentMeans()
    {
        $result = $this->module->sGetPaymentMeans();
        foreach ($result as $paymentMean) {
            $this->assertArrayHasKey('id', $paymentMean);
            $this->assertArrayHasKey('name', $paymentMean);
            $this->assertArrayHasKey('description', $paymentMean);
            $this->assertArrayHasKey('debit_percent', $paymentMean);
            $this->assertArrayHasKey('surcharge', $paymentMean);
            $this->assertArrayHasKey('surchargestring', $paymentMean);
            $this->assertArrayHasKey('active', $paymentMean);
            $this->assertArrayHasKey('esdactive', $paymentMean);
            $this->assertContains($paymentMean['id'], array(3, 5, 6));
        }
    }

    /**
     * @covers sAdmin::sInitiatePaymentClass
     */
    public function testsInitiatePaymentClass()
    {
        $payments = Shopware()->Models()->getRepository('Shopware\Models\Payment\Payment')->findAll();

        foreach ($payments as $payment) {
            $paymentClass = $this->module->sInitiatePaymentClass($this->module->sGetPaymentMeanById($payment->getId()));
            if (is_bool($paymentClass)) {
                $this->assertFalse($paymentClass);
            } else {
                $this->assertInstanceOf('ShopwarePlugin\PaymentMethods\Components\BasePaymentMethod', $paymentClass);
                Shopware()->Front()->setRequest(new Enlight_Controller_Request_RequestHttp());

                $requestData = Shopware()->Front()->Request()->getParams();
                $validationResult = $paymentClass->validate($requestData);
                $this->assertTrue(is_array($validationResult));
                if (count($validationResult)) {
                    $this->assertArrayHasKey('sErrorFlag', $validationResult);
                    $this->assertArrayHasKey('sErrorMessages', $validationResult);
                }
            }
        }
    }

    /**
     * @covers sAdmin::sValidateStep3
     * @expectedException Enlight_Exception
     * @expectedExceptionMessage sValidateStep3 #00: No payment id
     */
    public function testExceptionInsValidateStep3()
    {
        $this->module->sValidateStep3();
    }

    /**
     * @covers sAdmin::sValidateStep3
     */
    public function testsValidateStep3()
    {
        $this->front->Request()->setPost('sPayment', 2);

        $result = $this->module->sValidateStep3();
        $this->assertArrayHasKey('checkPayment', $result);
        $this->assertArrayHasKey('paymentData', $result);
        $this->assertArrayHasKey('sProcessed', $result);
        $this->assertArrayHasKey('sPaymentObject', $result);

        $this->assertInternalType('array', $result['checkPayment']);
        $this->assertCount(2, $result['checkPayment']);
        $this->assertInternalType('array', $result['paymentData']);
        $this->assertCount(20, $result['paymentData']);
        $this->assertInternalType('boolean', $result['sProcessed']);
        $this->assertTrue($result['sProcessed']);
        $this->assertInternalType('object', $result['sPaymentObject']);
        $this->assertInstanceOf('ShopwarePlugin\PaymentMethods\Components\BasePaymentMethod', $result['sPaymentObject']);
    }

    /**
     * @covers sAdmin::sUpdateBilling
     */
    public function testsUpdateBilling()
    {
        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        $this->front->Request()->setPost(array(
            'company' => 'TestCompany',
            'department' => 'TestDepartment',
            'salutation' => 'TestSalutation',
            'firstname' => 'TestFirstName',
            'lastname' => 'TestLastName',
            'street' => 'TestStreet',
            'zipcode' => 'TestZip',
            'city' => 'TestCity',
            'phone' => 'TestPhone',
            'country' => '2',
            'stateID' => '1',
            'ustid' => 'TestUstId',
            'birthday' => '21',
            'birthmonth' => '10',
            'birthyear' => '1998',
            'text1' => 'TestText1',
            'text2' => 'TestText2',
            'text3' => 'TestText3',
            'text4' => 'TestText4',
            'text5' => 'TestText5',
            'text6' => 'TestText6'
        ));

        $this->assertTrue($this->module->sUpdateBilling());
        $result = Shopware()->Db()->fetchRow('
            SELECT *

            FROM s_user_billingaddress
            LEFT JOIN s_user_billingaddress_attributes
            ON s_user_billingaddress.id = s_user_billingaddress_attributes.billingID

            WHERE s_user_billingaddress.userID = ?
        ', array($customer->getId()));

        $this->front->Request()->setPost(array(
            'countryID' => $this->front->Request()->getPost('country'),
            'birthday' => '1998-10-21',
            'country' => '',
            'birthmonth' => '',
            'birthyear' => ''
        ));

        $this->assertArrayHasKey('id', $result);
        foreach ($this->front->Request()->getPost() as $key => $value) {
            $this->assertEquals($value, $result[$key]);
        }

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sUpdateNewsletter
     */
    public function testsUpdateNewsletter()
    {
        $email = uniqid() . 'test@foobar.com';

        // Test insertion
        $this->assertTrue($this->module->sUpdateNewsletter(true, $email));
        $newsletterSubscription = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertNotNull($newsletterSubscription);
        $this->assertEquals(0, $newsletterSubscription['customer']);
        $this->assertEquals(1, $newsletterSubscription['groupID']);

        // Test removal
        $this->assertTrue($this->module->sUpdateNewsletter(false, $email));
        $newsletterSubscription = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertFalse($newsletterSubscription);


        // Retest insertion for customers
        $this->assertTrue($this->module->sUpdateNewsletter(true, $email, true));
        $newsletterSubscription = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertNotNull($newsletterSubscription);
        $this->assertEquals(1, $newsletterSubscription['customer']);
        $this->assertEquals(0, $newsletterSubscription['groupID']);

        // Test removal
        $this->assertTrue($this->module->sUpdateNewsletter(false, $email));
        $newsletterSubscription = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertFalse($newsletterSubscription);
    }

    /**
     * @covers sAdmin::sGetPreviousAddresses
     */
    public function testsGetPreviousAddresses()
    {
        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', null);

        // Test no user id
        $this->assertFalse($this->module->sGetPreviousAddresses('shipping'));

        $this->session->offsetSet('sUserId', $customer->getId());

        // Test empty argument scenario
        $this->assertFalse($this->module->sGetPreviousAddresses(''));

        // Test fetching for new customer with no order (should return empty)
        $this->assertCount(0, $this->module->sGetPreviousAddresses('shipping'));
        $this->assertCount(0, $this->module->sGetPreviousAddresses('billing'));

        $this->deleteDummyCustomer($customer);

        // Test with existing demo customer data
        $this->session->offsetSet('sUserId', 1);

        $shippingData = $this->module->sGetPreviousAddresses('shipping');
        $billingData = $this->module->sGetPreviousAddresses('billing');
        $this->assertGreaterThan(0, count($shippingData));
        $this->assertGreaterThan(0, count($billingData));

        $shippingDetails = end($shippingData);
        $billingDetails = end($billingData);

        $this->assertArrayHasKey('hash', $shippingDetails);
        $this->assertArrayHasKey('hash', $billingDetails);

        $shippingDetailsWithHash = $this->module->sGetPreviousAddresses('shipping', $shippingDetails['hash']);
        $billingDetailsWithHash = $this->module->sGetPreviousAddresses('billing', $billingDetails['hash']);

        foreach (array($shippingDetails, $billingDetails, $billingDetailsWithHash, $shippingDetailsWithHash) as $details) {
            $this->assertInternalType('array', $details);
            $this->assertArrayHasKey('company', $details);
            $this->assertArrayHasKey('department', $details);
            $this->assertArrayHasKey('salutation', $details);
            $this->assertArrayHasKey('firstname', $details);
            $this->assertArrayHasKey('lastname', $details);
            $this->assertArrayHasKey('street', $details);
            $this->assertArrayHasKey('zipcode', $details);
            $this->assertArrayHasKey('city', $details);
            $this->assertArrayHasKey('country', $details);
            $this->assertArrayHasKey('countryID', $details);
            $this->assertArrayHasKey('countryname', $details);

            $this->assertNotEmpty($details['hash']);
            $this->assertEquals('shopware AG', $details['company']);
            $this->assertEquals('', $details['department']);
            $this->assertEquals('mr', $details['salutation']);
            $this->assertEquals('Max', $details['firstname']);
            $this->assertEquals('Mustermann', $details['lastname']);
            $this->assertEquals('Mustermannstraße 92', $details['street']);
            $this->assertEquals('48624', $details['zipcode']);
            $this->assertEquals('Schöppingen', $details['city']);
            $this->assertEquals('2', $details['country']);
            $this->assertEquals('2', $details['countryID']);
            $this->assertEquals('Deutschland', $details['countryname']);
        }
    }

    /**
     * @covers sAdmin::sUpdateShipping
     */
    public function testsUpdateShipping()
    {
        // Test no user id
        $this->assertFalse($this->module->sUpdateShipping());

        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        // With user id but with no data, operation is successful
        $this->assertTrue($this->module->sUpdateShipping());

        // Setup dummy test data and test with it
        $postData = array(
            'company' => 'Testcompany',
            'department' => 'Testdepartment',
            'salutation' => 'Testsalutation',
            'firstname' => 'Testfirstname',
            'lastname' => 'Testlastname',
            'street' => 'Teststreet',
            'zipcode' => 'Testzipcode',
            'city' => 'Testcity',
            'country' => '2',
            'stateID' => '4',
            'text1' => 'TestText1',
            'text2' => 'TestText2',
            'text3' => 'TestText3',
            'text4' => 'TestText4',
            'text5' => 'TestText5',
            'text6' => 'TestText6'
        );
        $this->front->Request()->setPost($postData);
        $this->assertTrue($this->module->sUpdateShipping());

        $result = Shopware()->Db()->fetchRow('
            SELECT *

            FROM s_user_shippingaddress
            LEFT JOIN s_user_shippingaddress_attributes
            ON s_user_shippingaddress.id = s_user_shippingaddress_attributes.shippingID

            WHERE s_user_shippingaddress.userID = ?
        ', array($customer->getId()));

        // Prepare testData for comparison
        $postData['countryID'] = $postData['country'];
        unset($postData['country']);

        $this->assertArrayHasKey('id', $result);
        foreach ($postData as $key => $value) {
            $this->assertEquals($value, $result[$key]);
        }

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sUpdatePayment
     */
    public function testsUpdatePayment()
    {
        // Test no user id
        $this->assertFalse($this->module->sUpdatePayment());

        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        // Test that operation succeeds even without payment id
        $this->assertTrue($this->module->sUpdatePayment());
        $this->assertEquals(
            0,
            Shopware()->Db()->fetchOne('SELECT paymentID FROM s_user WHERE id = ?', array($customer->getId()))
        );

        // Setup dummy test data and test with it
        $this->front->Request()->setPost(array(
            'sPayment' => 2
        ));
        $this->assertTrue($this->module->sUpdatePayment());
        $this->assertEquals(
            2,
            Shopware()->Db()->fetchOne('SELECT paymentID FROM s_user WHERE id = ?', array($customer->getId()))
        );

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sUpdateAccount
     */
    public function testsUpdateAccount()
    {
        // Test no user id
        $this->assertTrue($this->module->sUpdateAccount());

        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());
        $this->front->Request()->setPost('email', uniqid() . 'test@foobar.com');

        $this->assertTrue($this->module->sUpdateAccount());

        // Test that email was updated
        $this->assertNotEquals(
            $customer->getEmail(),
            Shopware()->Db()->fetchOne('SELECT email FROM s_user WHERE id = ?', array($customer->getId()))
        );

        $this->front->Request()->setPost('password', uniqid() . 'password');
        $this->front->Request()->setPost('passwordConfirmation', $this->front->Request()->getPost('password'));

        $this->assertTrue($this->module->sUpdateAccount());

        // Test that password was updated
        $this->assertNotEquals(
            $customer->getPassword(),
            Shopware()->Db()->fetchOne('SELECT password FROM s_user WHERE id = ?', array($customer->getId()))
        );

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sValidateStep2
     */
    public function testsValidateStep2()
    {
        // Test with no rules, should always validate
        $result = $this->module->sValidateStep2(array());
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(0, $result['sErrorFlag']);
        $this->assertCount(0, $result['sErrorMessages']);

        $testRuleSet = array(
            'testField1' => array('required' => 1),
            'testField2' => array('required' => 0),
            'testField3' => array('required' => 1),
        );

        // Test failing validation, should have 2 failing fields
        $result = $this->module->sValidateStep2($testRuleSet);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('testField1', $result['sErrorFlag']);
        $this->assertArrayHasKey('testField3', $result['sErrorFlag']);
        $this->assertCount(1, $result['sErrorMessages']);


        // Setup dummy test data and test with it, see it passes
        $this->front->Request()->setPost(array(
            'testField1' => 'testValue',
            'testField2' => 'testValue',
            'testField3' => 'testValue'
        ));
        $result = $this->module->sValidateStep2($testRuleSet);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(0, $result['sErrorFlag']);
        $this->assertCount(0, $result['sErrorMessages']);
    }

    /**
     * @covers sAdmin::sValidateStep2ShippingAddress
     */
    public function testsValidateStep2ShippingAddress()
    {
        // Test with no rules, should always validate
        $result = $this->module->sValidateStep2ShippingAddress(array());
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertNull($result['sErrorMessages']);

        $testRuleSet = array(
            'testField1' => array('required' => 1),
            'testField2' => array('required' => 0),
            'testField3' => array('required' => 1),
        );

        // Test failing validation, should have 2 failing fields
        $result = $this->module->sValidateStep2ShippingAddress($testRuleSet);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('testField1', $result['sErrorFlag']);
        $this->assertArrayHasKey('testField3', $result['sErrorFlag']);
        $this->assertCount(1, $result['sErrorMessages']);


        // Setup dummy test data and test with it, see it passes
        $this->front->Request()->setPost(array(
            'testField1' => 'testValue',
            'testField2' => 'testValue',
            'testField3' => 'testValue'
        ));
        $result = $this->module->sValidateStep2ShippingAddress($testRuleSet);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertNull($result['sErrorMessages']);
    }

    /**
     * @covers sAdmin::sValidateStep1
     */
    public function testsValidateStep1WithoutEdit()
    {
        // Test with no data, should fail on password field
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            'Bitte w&auml;hlen Sie ein Passwort, welches aus mindestens {config name="MinPassword"} Zeichen besteht.',
            $result['sErrorMessages']
        );
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('password', $result['sErrorFlag']);
        $this->assertArrayHasKey('passwordConfirmation', $result['sErrorFlag']);

        // Test with diverging password, should fail
        $this->front->Request()->setPost(array(
            'password' => 'password',
            'passwordConfirmation' => 'passwordConfirmation',
        ));
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            'Die Passwörter stimmen nicht überein.',
            $result['sErrorMessages']
        );
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('password', $result['sErrorFlag']);
        $this->assertArrayHasKey('passwordConfirmation', $result['sErrorFlag']);

        // Test with matching passwords, should succeed
        $this->front->Request()->setPost(array(
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ));
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorMessages']);
        $this->assertNull($result['sErrorFlag']);

        // Test with invalid email, should fail
        $this->front->Request()->setPost('email', 'failmail.com');
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get('MailFailure', 'Please enter a valid mail address'),
            $result['sErrorMessages']
        );
        $this->assertCount(1, $result['sErrorFlag']);
        $this->assertArrayHasKey('email', $result['sErrorFlag']);

        // Test with valid email, should succeed
        $this->front->Request()->setPost('email', 'foo@failmail.com');
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorMessages']);
        $this->assertNull($result['sErrorFlag']);

        // Test with diverging emailConfirmation and email, should fail
        $this->front->Request()->setPost('emailConfirmation', 'bar@failmail.com');
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get('MailFailureNotEqual', 'The mail addresses entered are not equal'),
            $result['sErrorMessages']
        );
        $this->assertCount(1, $result['sErrorFlag']);
        $this->assertArrayHasKey('emailConfirmation', $result['sErrorFlag']);

        // Test with valid email, should succeed
        $this->front->Request()->setPost('emailConfirmation', 'foo@failmail.com');
        $result = $this->module->sValidateStep1();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorMessages']);
        $this->assertNull($result['sErrorFlag']);

        // Test that session data is correctly set
        $sessionRegister = $this->session->offsetGet('sRegister');
        $this->assertEquals(0, $sessionRegister['auth']['accountmode']);
        $this->assertNull($sessionRegister['auth']['receiveNewsletter']);
        $this->assertEquals('foo@failmail.com', $sessionRegister['auth']['email']);
        $this->assertEquals('bcrypt', $sessionRegister['auth']['encoderName']);
        $this->assertArrayHasKey('password', $sessionRegister['auth']);

        // Test with skipLogin
        $this->front->Request()->setPost('skipLogin', true);
        $this->module->sValidateStep1();
        $sessionRegister = $this->session->offsetGet('sRegister');
        $this->assertEquals(1, $sessionRegister['auth']['accountmode']);
        $this->assertNull($sessionRegister['auth']['receiveNewsletter']);
        $this->assertEquals('foo@failmail.com', $sessionRegister['auth']['email']);
        $this->assertEquals('md5', $sessionRegister['auth']['encoderName']);
        $this->assertArrayHasKey('password', $sessionRegister['auth']);
    }

    /**
     * @covers sAdmin::sValidateStep1
     */
    public function testsValidateStep1WithEdit()
    {
        $customer = $this->createDummyCustomer();

        // Test with no data
        $result = $this->module->sValidateStep1(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            'Das aktuelle Passwort stimmt nicht!',
            $result['sErrorMessages']
        );
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('email', $result['sErrorFlag']);
        $this->assertArrayHasKey('currentPassword', $result['sErrorFlag']);

        // Test with correct basic data, wrong password
        // First, hack session data with correct data
        $this->session->offsetSet('sUserMail', $customer->getEmail());
        $this->session->offsetSet(
            "sUserPassword",
            Shopware()->PasswordEncoder()->encodePassword("fooobar", 'bcrypt')
        );
        // Then set post with wrong data
        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'currentPassword' => 'password',
        ));
        $result = $this->module->sValidateStep1(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            'Das aktuelle Passwort stimmt nicht!',
            $result['sErrorMessages']
        );
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('email', $result['sErrorFlag']);
        $this->assertArrayHasKey('currentPassword', $result['sErrorFlag']);

        // Now use correct data to test correct behavior
        $this->front->Request()->setPost('currentPassword', 'fooobar');
        $result = $this->module->sValidateStep1(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorMessages']);
        $this->assertNull($result['sErrorFlag']);

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sLogin
     */
    public function testsLogin()
    {
        // Test with no data, get error
        $result = $this->module->sLogin();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get('LoginFailure', 'Wrong email or password'),
            $result['sErrorMessages']
        );
        $this->assertCount(2, $result['sErrorFlag']);
        $this->assertArrayHasKey('email', $result['sErrorFlag']);
        $this->assertArrayHasKey('password', $result['sErrorFlag']);

        // Test with wrong data, get error
        $this->front->Request()->setPost(array(
            'email' => uniqid() . 'test',
            'password' => uniqid() . 'test',
        ));
        $result = $this->module->sLogin();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get('LoginFailure', 'Wrong email or password'),
            $result['sErrorMessages']
        );
        $this->assertNull($result['sErrorFlag']);

        $customer = $this->createDummyCustomer();

        // Test successful login
        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'password' => 'fooobar',
        ));
        $result = $this->module->sLogin();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertNull($result['sErrorMessages']);

        // Test wrong pre-hashed password. Need a user with md5 encoded password
        Shopware()->Db()->update(
            's_user',
            array(
                'password' => md5('fooobar'),
                'encoder' => 'md5'
            ),
            'id = '.$customer->getId()
        );

        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'passwordMD5' => uniqid(),
        ));
        $result = $this->module->sLogin(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get('LoginFailure', 'Wrong email or password'),
            $result['sErrorMessages']
        );

        // Test correct pre-hashed password
        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'passwordMD5' => md5('fooobar'),
        ));
        $result = $this->module->sLogin(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertNull($result['sErrorMessages']);

        $modifiedMd5User = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_user WHERE id = ?',
            array($customer->getId())
        );

        // Test that it's the same user, but with different last login
        $this->assertEquals($modifiedMd5User['email'], $customer->getEmail());
        $this->assertEquals($modifiedMd5User['password'], md5('fooobar'));
        $this->assertNotEquals($modifiedMd5User['lastlogin'], $customer->getLastLogin()->format('Y-m-d H:i:s'));

        // Test inactive account
        Shopware()->Db()->update('s_user', array('active' => 0), 'id = '.$customer->getId());
        $result = $this->module->sLogin(true);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get(
                    'LoginFailureActive',
                    'Your account is disabled. Please contact us.'
                ),
            $result['sErrorMessages']
        );

        // Test brute force lockout
        Shopware()->Db()->update('s_user', array('active' => 1), 'id = '.$customer->getId());
        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'password' => 'asasasasas',
        ));
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $this->module->sLogin();
        $result = $this->module->sLogin();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertCount(1, $result['sErrorMessages']);
        $this->assertContains(
            $this->snippetManager->getNamespace('frontend/account/internalMessages')
                ->get(
                    'LoginFailureLocked',
                    'Too many failed logins. Your account was temporary deactivated.'
                ),
            $result['sErrorMessages']
        );

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sCheckUser
     */
    public function testsCheckUser()
    {
        $customer = $this->createDummyCustomer();

        // Basic failing case
        $this->assertFalse($this->module->sCheckUser());

        // Test successful login
        $this->front->Request()->setPost(array(
            'email' => $customer->getEmail(),
            'password' => 'fooobar',
        ));
        $result = $this->module->sLogin();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('sErrorFlag', $result);
        $this->assertArrayHasKey('sErrorMessages', $result);
        $this->assertNull($result['sErrorFlag']);
        $this->assertNull($result['sErrorMessages']);

        // Test that user is correctly logged in
        $this->assertTrue($this->module->sCheckUser());

        // Force timeout
        Shopware()->Db()->update('s_user', array('lastlogin' => '2000-01-01 00:00:00'), 'id = '.$customer->getId());
        $this->assertFalse($this->module->sCheckUser());

        $this->assertEquals($customer->getGroup()->getKey(), $this->session->offsetGet('sUserGroup'));
        $this->assertInternalType('array', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('groupkey', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('description', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('tax', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('taxinput', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('mode', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('discount', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('minimumorder', $this->session->offsetGet('sUserGroupData'));
        $this->assertArrayHasKey('minimumordersurcharge', $this->session->offsetGet('sUserGroupData'));

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sGetCountryTranslation
     */
    public function testsGetCountryTranslation()
    {
        // Backup existing data and inject demo data
        $existingData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_countries' AND objectlanguage = 2
        ");

        $demoData = array(
            'objectkey' => 1,
            'objectlanguage' => 2,
            'objecttype' => 'config_countries',
            'objectdata' => serialize(
                array(
                    2 => array(
                        'active' => '1',
                        'countryname' => 'Germany',
                    ),
                    5 => array(
                        'active' => '1',
                        'countryname' => 'Belgium',
                    )
                )
            )
        );

        if ($existingData) {
            Shopware()->Db()->update('s_core_translations', $demoData, 'id = '.$existingData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoData);
        }

        // Test loading all data, should return the test data
        $shopId = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->getId();
        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId(2);

        $result = $this->module->sGetCountryTranslation();
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertArrayHasKey('active', $result[2]);
        $this->assertArrayHasKey('countryname', $result[2]);
        $this->assertEquals(1, $result[2]['active']);
        $this->assertEquals('Germany', $result[2]['countryname']);
        $this->assertArrayHasKey('active', $result[5]);
        $this->assertArrayHasKey('countryname', $result[5]);
        $this->assertEquals(1, $result[5]['active']);
        $this->assertEquals('Belgium', $result[5]['countryname']);

        // Test with just one country
        $result = $this->module->sGetCountryTranslation(array('id' => 2, 'randomField' => 'randomValue'));
        $this->assertCount(4, $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('countryname', $result);
        $this->assertArrayHasKey('randomField', $result);
        $this->assertEquals(2, $result['id']);
        $this->assertEquals(1, $result['active']);
        $this->assertEquals('Germany', $result['countryname']);
        $this->assertEquals('randomValue', $result['randomField']);

        // If backup data exists, restore it
        if ($existingData) {
            $existingDataId = $existingData['id'];
            unset($existingData['id']);
            Shopware()->Db()->update('s_core_translations', $existingData, 'id = '.$existingDataId);
        }

        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId($shopId);
    }

    /**
     * @covers sAdmin::sGetDispatchTranslation
     */
    public function testsGetDispatchTranslation()
    {
        // Backup existing data and inject demo data
        $existingData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_dispatch' AND objectlanguage = 2
        ");

        $demoData = array(
            'objectkey' => 1,
            'objectlanguage' => 2,
            'objecttype' => 'config_dispatch',
            'objectdata' => serialize(
                array(
                    9 => array(
                        'dispatch_name' => 'Standard shipping',
                        'dispatch_description' => 'Standard shipping description',
                        'dispatch_status_link' => 'http://www.dhl.com',
                    ),
                    10 => array(
                        'dispatch_name' => 'Shipping by weight',
                        'dispatch_description' => 'Shipping by weight description',
                        'dispatch_status_link' => 'url',
                    ),
                )
            )
        );

        if ($existingData) {
            Shopware()->Db()->update('s_core_translations', $demoData, 'id = '.$existingData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoData);
        }

        // Test loading all data, should return the test data
        $shopId = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->getId();
        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId(2);

        $result = $this->module->sGetDispatchTranslation();
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(9, $result);
        $this->assertArrayHasKey(10, $result);
        $this->assertArrayHasKey('dispatch_name', $result[9]);
        $this->assertArrayHasKey('dispatch_description', $result[9]);
        $this->assertArrayHasKey('dispatch_status_link', $result[9]);
        $this->assertArrayHasKey('dispatch_name', $result[10]);
        $this->assertArrayHasKey('dispatch_description', $result[10]);
        $this->assertArrayHasKey('dispatch_status_link', $result[10]);
        $this->assertEquals('Standard shipping', $result[9]['dispatch_name']);
        $this->assertEquals('Standard shipping description', $result[9]['dispatch_description']);
        $this->assertEquals('http://www.dhl.com', $result[9]['dispatch_status_link']);
        $this->assertEquals('Shipping by weight', $result[10]['dispatch_name']);
        $this->assertEquals('Shipping by weight description', $result[10]['dispatch_description']);
        $this->assertEquals('url', $result[10]['dispatch_status_link']);

        // Test with just one shipping method
        $result = $this->module->sGetDispatchTranslation(array('id' => 9, 'randomField' => 'randomValue'));
        $this->assertCount(5, $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('status_link', $result);
        $this->assertArrayHasKey('randomField', $result);
        $this->assertEquals(9, $result['id']);
        $this->assertEquals('Standard shipping', $result['name']);
        $this->assertEquals('Standard shipping description', $result['description']);
        $this->assertEquals('http://www.dhl.com', $result['status_link']);
        $this->assertEquals('randomValue', $result['randomField']);

        // If backup data exists, restore it
        if ($existingData) {
            $existingDataId = $existingData['id'];
            unset($existingData['id']);
            Shopware()->Db()->update('s_core_translations', $existingData, 'id = '.$existingDataId);
        }

        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId($shopId);
    }

    /**
     * @covers sAdmin::sGetPaymentTranslation
     */
    public function testsGetPaymentTranslation()
    {
        // Backup existing data and inject demo data
        $existingData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_payment' AND objectlanguage = 2
        ");

        $demoData = array(
            'objectkey' => 1,
            'objectlanguage' => 2,
            'objecttype' => 'config_payment',
            'objectdata' => serialize(
                array(
                    4 => array(
                        'description' => 'Invoice',
                        'additionalDescription' => 'Payment by invoice. Shopware provides automatic invoicing for all customers on orders after the first, in order to avoid defaults on payment.',
                    ),
                    2 => array(
                        'description' => 'Debit',
                        'additionalDescription' => 'Additional text',
                    ),
                    3 => array(
                        'description' => 'Cash on delivery',
                        'additionalDescription' => '(including 2.00 Euro VAT)',
                    ),
                    5 => array(
                        'description' => 'Paid in advance',
                        'additionalDescription' => 'The goods are delivered directly upon receipt of payment.',
                    ),
                    6 => array(
                        'additionalDescription' => 'SEPA direct debit',
                    ),
                )
            )
        );

        if ($existingData) {
            Shopware()->Db()->update('s_core_translations', $demoData, 'id = '.$existingData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoData);
        }

        // Test loading all data, should return the test data
        $shopId = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->getId();
        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId(2);

        $result = $this->module->sGetPaymentTranslation();
        $this->assertCount(5, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(3, $result);
        $this->assertArrayHasKey(4, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertArrayHasKey(6, $result);
        $this->assertArrayHasKey('description', $result[2]);
        $this->assertArrayHasKey('additionalDescription', $result[2]);
        $this->assertArrayHasKey('description', $result[3]);
        $this->assertArrayHasKey('additionalDescription', $result[3]);
        $this->assertArrayHasKey('description', $result[5]);
        $this->assertArrayHasKey('additionalDescription', $result[5]);
        $this->assertEquals('Debit', $result[2]['description']);
        $this->assertEquals('Additional text', $result[2]['additionalDescription']);
        $this->assertEquals('Cash on delivery', $result[3]['description']);
        $this->assertEquals('(including 2.00 Euro VAT)', $result[3]['additionalDescription']);
        $this->assertEquals('Paid in advance', $result[5]['description']);
        $this->assertEquals('The goods are delivered directly upon receipt of payment.', $result[5]['additionalDescription']);

        // Test with just one payment mean
        $result = $this->module->sGetPaymentTranslation(array('id' => 2, 'randomField' => 'randomValue'));
        $this->assertCount(4, $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('additionaldescription', $result);
        $this->assertArrayHasKey('randomField', $result);
        $this->assertEquals(2, $result['id']);
        $this->assertEquals('Debit', $result['description']);
        $this->assertEquals('Additional text', $result['additionaldescription']);
        $this->assertEquals('randomValue', $result['randomField']);

        // If backup data exists, restore it
        if ($existingData) {
            $existingDataId = $existingData['id'];
            unset($existingData['id']);
            Shopware()->Db()->update('s_core_translations', $existingData, 'id = '.$existingDataId);
        }

        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setId($shopId);
    }

    /**
     * @covers sAdmin::sGetCountryStateTranslation
     */
    public function testsGetCountryStateTranslation()
    {
        // Backup existing data and inject demo data
        $existingData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_country_states' AND objectlanguage = 1
        ");

        $demoData = array(
            'objectkey' => 1,
            'objectlanguage' => 1,
            'objecttype' => 'config_country_states',
            'objectdata' => serialize(
                array(
                    24 => array(
                        'name' => 'California',
                    ),
                    23 => array(
                        'name' => 'Arkansas (english)',
                    ),
                )
            )
        );

        if ($existingData) {
            Shopware()->Db()->update('s_core_translations', $demoData, 'id = '.$existingData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoData);
        }

        // Test with default shop, return empty array
        $this->assertCount(0, $this->module->sGetCountryStateTranslation());

        // Hack the current system shop, so we can properly test this
        Shopware()->Shop()->setDefault(false);

        $result = $this->module->sGetCountryStateTranslation();
        $this->assertCount(2, $result);
        $this->assertArrayHasKey(23, $result);
        $this->assertArrayHasKey(24, $result);
        $this->assertArrayHasKey('name', $result[23]);
        $this->assertArrayHasKey('name', $result[24]);
        $this->assertEquals('Arkansas (english)', $result[23]['name']);
        $this->assertEquals('California', $result[24]['name']);

        // Create a stub of a Shop for fallback.
        $shopFallbackId = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->getFallbackId();
        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setFallbackId(10000);

        Shopware()->Db()->insert('s_core_translations', array(
            'objectkey' => 1,
            'objectlanguage' => 10000,
            'objecttype' => 'config_country_states',
            'objectdata' => serialize(
                array(
                    2 => array(
                        'name' => 'asdfasfdasdfa',
                    ),
                )
            )
        ));

        // Test with fallback
        $result = $this->module->sGetCountryStateTranslation();
        $this->assertCount(3, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(23, $result);
        $this->assertArrayHasKey(24, $result);
        $this->assertArrayHasKey('name', $result[2]);
        $this->assertArrayHasKey('name', $result[23]);
        $this->assertArrayHasKey('name', $result[24]);
        $this->assertEquals('asdfasfdasdfa', $result[2]['name']);
        $this->assertEquals('Arkansas (english)', $result[23]['name']);
        $this->assertEquals('California', $result[24]['name']);

        // If backup data exists, restore it
        if ($existingData) {
            $existingDataId = $existingData['id'];
            unset($existingData['id']);
            Shopware()->Db()->update('s_core_translations', $existingData, 'id = '.$existingDataId);
        }
        Shopware()->Db()->delete('s_core_translations', 'objectlanguage = 10000');

        Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext()->getShop()->setFallbackId($shopFallbackId);
    }

    /**
     * @covers sAdmin::sGetCountryList
     */
    public function testsGetCountryList()
    {
        // Test with default country data
        $result = $this->module->sGetCountryList();
        foreach ($result as $country) {
            $this->assertArrayHasKey('id', $country);
            $this->assertArrayHasKey('countryname', $country);
            $this->assertArrayHasKey('countryiso', $country);
            $this->assertArrayHasKey('areaID', $country);
            $this->assertArrayHasKey('countryen', $country);
            $this->assertArrayHasKey('shippingfree', $country);
            $this->assertArrayHasKey('taxfree', $country);
            $this->assertArrayHasKey('display_state_in_registration', $country);
            $this->assertArrayHasKey('force_state_in_registration', $country);
            $this->assertArrayHasKey('states', $country);
            $this->assertArrayHasKey('flag', $country);
        }

        // Add translations
        $existingCountryData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_countries' AND objectlanguage = 1
        ");
        $existingStateData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_translations
            WHERE objecttype = 'config_country_states' AND objectlanguage = 1
        ");

        $demoCountryData = array(
            'objectkey' => 1,
            'objectlanguage' => 1,
            'objecttype' => 'config_countries',
            'objectdata' => serialize(
                array(
                    2 => array(
                        'active' => '1',
                        'countryname' => 'Germany',
                    ),
                )
            )
        );
        $demoStateData = array(
            'objectkey' => 1,
            'objectlanguage' => 1,
            'objecttype' => 'config_country_states',
            'objectdata' => serialize(
                array(
                    2 => array(
                        'name' => '111',
                    ),
                    3 => array(
                        'name' => '222',
                    ),
                )
            )
        );

        if ($existingCountryData) {
            Shopware()->Db()->update('s_core_translations', $demoCountryData, 'id = '.$existingCountryData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoCountryData);
        }
        if ($existingStateData) {
            Shopware()->Db()->update('s_core_translations', $demoStateData, 'id = '.$existingStateData['id']);
        } else {
            Shopware()->Db()->insert('s_core_translations', $demoStateData);
        }

        // Test with translations but display_states = false
        $result = $this->module->sGetCountryList();
        $country = $result[0]; // Germany
        $this->assertArrayHasKey('id', $country);
        $this->assertArrayHasKey('countryname', $country);
        $this->assertArrayHasKey('countryiso', $country);
        $this->assertArrayHasKey('areaID', $country);
        $this->assertArrayHasKey('countryen', $country);
        $this->assertArrayHasKey('shippingfree', $country);
        $this->assertArrayHasKey('taxfree', $country);
        $this->assertArrayHasKey('display_state_in_registration', $country);
        $this->assertArrayHasKey('force_state_in_registration', $country);
        $this->assertArrayHasKey('states', $country);
        $this->assertArrayHasKey('flag', $country);
        $this->assertCount(0, $country['states']);
        $this->assertEquals('Germany', $country['countryname']);


        // Hack the current system shop, so we can properly test this
        Shopware()->Shop()->setDefault(false);

        // Make Germany display states, so we can test it
        $existingGermanyData = Shopware()->Db()->fetchRow("
            SELECT * FROM s_core_countries
            WHERE countryiso = 'DE'
        ");
        Shopware()->Db()->update(
            's_core_countries',
            array('display_state_in_registration' => 1),
            'id = '.$existingGermanyData['id']
        );

        // Test with translations and states
        $result = $this->module->sGetCountryList();
        $country = $result[0]; // Germany
        $this->assertArrayHasKey('id', $country);
        $this->assertArrayHasKey('countryname', $country);
        $this->assertArrayHasKey('countryiso', $country);
        $this->assertArrayHasKey('areaID', $country);
        $this->assertArrayHasKey('countryen', $country);
        $this->assertArrayHasKey('shippingfree', $country);
        $this->assertArrayHasKey('taxfree', $country);
        $this->assertArrayHasKey('display_state_in_registration', $country);
        $this->assertArrayHasKey('force_state_in_registration', $country);
        $this->assertArrayHasKey('states', $country);
        $this->assertArrayHasKey('flag', $country);
        $this->assertCount(16, $country['states']);
        $this->assertEquals('Germany', $country['countryname']);
        foreach ($country['states'] as $state) {
            $this->assertArrayHasKey('id', $state);
            $this->assertArrayHasKey('countryID', $state);
            $this->assertArrayHasKey('name', $state);
            $this->assertArrayHasKey('shortcode', $state);
            $this->assertArrayHasKey('active', $state);
        }
        $this->assertContains('111', array_column($country['states'], 'name'));

        // If backup data exists, restore it
        if ($existingCountryData) {
            $existingCountryDataId = $existingCountryData['id'];
            unset($existingCountryData['id']);
            Shopware()->Db()->update('s_core_translations', $existingCountryData, 'id = '.$existingCountryDataId);
        }
        if ($existingStateData) {
            $existingStateDataId = $existingStateData['id'];
            unset($existingStateData['id']);
            Shopware()->Db()->update('s_core_translations', $existingStateData, 'id = '.$existingStateDataId);
        }
        if ($existingGermanyData) {
            $existingGermanyDataId = $existingGermanyData['id'];
            unset($existingGermanyData['id']);
            Shopware()->Db()->update('s_core_countries', $existingGermanyData, 'id = '.$existingGermanyDataId);
        }

        // Remove shop hack
        Shopware()->Shop()->setDefault(true);
    }

    /**
     * @covers sAdmin::sSaveRegisterMainData
     * @expectedException Zend_Db_Statement_Exception
     */
    public function testsSaveRegisterMainDataWithEmptyData()
    {
        $this->module->sSaveRegisterMainData(array());
    }

    /**
     * @covers sAdmin::sSaveRegisterMainData
     */
    public function testsSaveRegisterMainData()
    {
        $password = Shopware()->PasswordEncoder()->encodePassword(
            'foo',
            'bcrypt'
        );
        $testData = array(
            'auth' => array(
                'password' => $password,
                'email' => uniqid() . 'test@foobar.com',
                'accountmode' => 1,
                'encoderName' => 'bcrypt'
            ),
            'payment' => array(
                'object' => array(
                    'id' => 2
                )
            )
        );
        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);


        $result = $this->module->sSaveRegisterMainData($testData);

        /** @var $customer Shopware\Models\Customer\Customer */

        $customer = Shopware()->Db()->fetchRow(
            'SELECT * FROM s_user WHERE id = ?',
            array($result)
        );

        $this->assertEquals($testData['auth']['email'], $customer['email']);
        $this->assertEquals($customer['password'], $password);
        $this->assertEquals($testData['auth']['accountmode'], $customer['accountmode']);
        $this->assertEquals(2, $customer['paymentID']);

        return $result;
    }

    /**
     * @covers sAdmin::sSaveRegisterBilling
     * @depends testsSaveRegisterMainData
     * @expectedException Zend_Db_Statement_Exception
     */
    public function testsSaveRegisterBillingWithEmptyData($userId)
    {
        $this->module->sSaveRegisterBilling($userId, array());
    }

    /**
     * @covers sAdmin::sSaveRegisterBilling
     * @depends testsSaveRegisterMainData
     */
    public function testsSaveRegisterBilling($userId)
    {
        $testData = array(
            'company' => 'Testcompany',
            'department' => 'Testdepartment',
            'salutation' => 'Testsalutation',
            'firstname' => 'Testfirstname',
            'lastname' => 'Testlastname',
            'street' => 'Teststreet',
            'zipcode' => 'Testzipcode',
            'city' => 'Testcity',
            'phone' => 'Testphone',
            'country' => '2',
            'stateID' => '3',
            'ustid' => 'Testustid',

            'birthyear' => '1956',
            'birthmonth' => '2',
            'birthday' => '21',

            'text1' => 'text1',
            'text2' => 'text2',
            'text3' => 'text3',
            'text4' => 'text4',
            'text5' => 'text5',
            'text6' => 'text6'
        );

        $result = $this->module->sSaveRegisterBilling($userId, array('billing' => $testData, 'shipping' => $testData));
        $this->assertGreaterThan(0, $result);

        $savedData = Shopware()->Db()->fetchRow('
            SELECT *

            FROM s_user_billingaddress
            LEFT JOIN s_user_billingaddress_attributes
            ON s_user_billingaddress.id = s_user_billingaddress_attributes.billingID

            WHERE s_user_billingaddress.id = ?
        ', array($result));

        // Prepare demo data for comparison
        $testData['countryID'] = $testData['country'];
        unset($testData['country']);
        $testData['birthday'] = mktime(
            0, 0, 0,
            (int) $testData['birthmonth'],
            (int) $testData['birthday'],
            (int) $testData['birthyear']
        );
        $testData['birthday'] = '1956-02-21';
        unset($testData['birthmonth']);
        unset($testData['birthyear']);

        foreach ($testData as $name => $value) {
            $this->assertEquals($savedData[$name], $value);
        }

        Shopware()->Db()->delete('s_user_billingaddress_attributes', 'billingID = '.$result);
        Shopware()->Db()->delete('s_user_billingaddress', 'userID = '.$userId);
    }

    /**
     * @covers sAdmin::sSaveRegisterShipping
     * @depends testsSaveRegisterMainData
     * @expectedException Zend_Db_Statement_Exception
     */
    public function testsSaveRegisterShippingWithEmptyData($userId)
    {
        $this->module->sSaveRegisterShipping($userId, array());
    }

    /**
     * @covers sAdmin::sSaveRegisterShipping
     * @depends testsSaveRegisterMainData
     */
    public function testsSaveRegisterShipping($userId)
    {
        $testData = array(
            'company' => 'Testcompany',
            'department' => 'Testdepartment',
            'salutation' => 'Testsalutation',
            'firstname' => 'Testfirstname',
            'lastname' => 'Testlastname',
            'street' => 'Teststreet',
            'zipcode' => 'Testzipcode',
            'city' => 'Testcity',
            'country' => '2',
            'stateID' => '3',

            'text1' => 'text1',
            'text2' => 'text2',
            'text3' => 'text3',
            'text4' => 'text4',
            'text5' => 'text5',
            'text6' => 'text6'
        );

        $result = $this->module->sSaveRegisterShipping($userId, array('shipping' => $testData, 'billing' => $testData));
        $this->assertGreaterThan(0, $result);

        $savedData = Shopware()->Db()->fetchRow('
            SELECT *

            FROM s_user_shippingaddress
            LEFT JOIN s_user_shippingaddress_attributes
            ON s_user_shippingaddress.id = s_user_shippingaddress_attributes.shippingID

            WHERE s_user_shippingaddress.id = ?
        ', array($result));

        // Prepare demo data for comparison
        $testData['countryID'] = $testData['country'];
        unset($testData['country']);

        foreach ($testData as $name => $value) {
            $this->assertEquals($savedData[$name], $value);
        }

        Shopware()->Db()->delete('s_user_shippingaddress_attributes', 'shippingID = '.$result);
        Shopware()->Db()->delete('s_user_shippingaddress', 'userID = '.$userId);
        Shopware()->Db()->delete('s_user_attributes', 'userID = '.$userId);
        Shopware()->Db()->delete('s_user', 'id = '.$userId);
    }

    /**
     * @covers sAdmin::sSaveRegisterNewsletter
     */
    public function testsSaveRegisterNewsletter()
    {
        // Test basic scenario
        $email = uniqid() . 'test@foobar.com';
        $result = Shopware()->Db()->fetchOne(
            'SELECT id FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertFalse($result);

        $testData = array(
            'auth' => array(
                'email' =>  $email,
            ),
        );
        $this->module->sSaveRegisterNewsletter($testData);
        $result = Shopware()->Db()->fetchOne(
            'SELECT id FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertGreaterThan(0, $result);

        // Test that duplicates are not changed
        $this->module->sSaveRegisterNewsletter($testData);
        $result = Shopware()->Db()->fetchOne(
            'SELECT id FROM s_campaigns_mailaddresses WHERE email = ?',
            array($email)
        );
        $this->assertGreaterThan(0, $result);
    }

    /**
     * @covers sAdmin::sSaveRegister
     */
    public function testsSaveRegisterWithRegistrationFinished()
    {
        $customer = $this->createDummyCustomer();

        $this->session->offsetSet('sRegisterFinished', true);
        $this->session->offsetSet('sUserMail', $customer->getEmail());
        $this->session->offsetSet('sUserPassword', $customer->getPassword());
        $this->session->offsetSet('sOneTimeAccount', true);
        $this->assertTrue($this->module->sSaveRegister());
        $this->assertNotEmpty($this->session->offsetGet('sUserId'));

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sSaveRegister
     * @expectedException Enlight_Exception
     */
    public function testsSaveRegisterWithNoData()
    {
        $this->assertTrue($this->module->sSaveRegister());
        $this->assertNotEmpty($this->session->offsetGet('sUserId'));
    }

    /**
     * @covers sAdmin::sSaveRegister
     */
    public function testsSaveRegister()
    {
        // Prepare all needed test structures for login
        $testData = array(
            'auth' => array(
                'email' => uniqid() . 'test@foobar.com',
                'password' => 'fooobar',
                'accountmode' => 1,
                'encoderName' => 'bcrypt'
            ),
            'billing' => array(
                'salutation' => 'testsalutation',
                'firstname' => 'testfirstname',
                'lastname' => 'testlastname',
                'street' => 'teststreet',
                'zipcode' => 'testzipcode',
                'city' => 'testcity',
                'country' => '2'
            ),
            'payment' => array(
                'object' => array(
                    'id' => 2
                )
            )
        );
        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);

        $this->session->offsetSet('sRegister', $testData);

        // Test that login was successful
        $this->assertEmpty($this->session->offsetGet('sUserId'));
        $this->assertFalse($this->module->sCheckUser());
        $this->assertTrue($this->module->sSaveRegister());
        $userId = $this->session->offsetGet('sUserId');
        $this->assertEquals(
            $userId,
            Shopware()->Db()->fetchOne('SELECT id FROM s_user WHERE id = ?', array($userId))
        );
        $this->assertNotEmpty($this->session->offsetGet('sUserId'));
        $this->assertTrue($this->module->sCheckUser());

        // Logout and delete data
        Shopware()->Session()->unsetAll();

        Shopware()->Db()->delete('s_user_attributes', 'userID = '.$userId);
        Shopware()->Db()->delete('s_user', 'id = '.$userId);
    }

    /**
     * @covers sAdmin::sGetDownloads
     */
    public function testsGetDownloads()
    {
        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        // New customers don't have available downloads
        $downloads = $this->module->sGetDownloads();
        $this->assertCount(0, $downloads['orderData']);

        // Inject demo data
        $orderData = array(
            'ordernumber' => uniqid(),
            'userID' => $customer->getId(),
            'invoice_amount' => '37.99',
            'invoice_amount_net' => '31.92',
            'invoice_shipping' => '0',
            'invoice_shipping_net' => '0',
            'ordertime' => '2014-03-14 10:26:20',
            'status' => '0',
            'cleared' => '17',
            'paymentID' => '4',
            'transactionID' => '',
            'comment' => '',
            'customercomment' => '',
            'internalcomment' => '',
            'net' => '0',
            'taxfree' => '0',
            'partnerID' => '',
            'temporaryID' => '',
            'referer' => '',
            'cleareddate' => null,
            'trackingcode' => '',
            'language' => '2',
            'dispatchID' => '9',
            'currency' => 'EUR',
            'currencyFactor' => '1',
            'subshopID' => '1',
            'remote_addr' => '127.0.0.1'
        );

        Shopware()->Db()->insert('s_order', $orderData);
        $orderId = Shopware()->Db()->lastInsertId();

        $orderDetailsData = array(
            'orderID' => $orderId,
            'ordernumber' => '20003',
            'articleID' => '98765',
            'articleordernumber' => 'SW10196',
            'price' => '34.99',
            'quantity' => '1',
            'name' => 'ESD download article',
            'status' => '0',
            'shipped' => '0',
            'shippedgroup' => '0',
            'releasedate' => '0000-00-00',
            'modus' => '0',
            'esdarticle' => '1',
            'taxID' => '1',
            'tax_rate' => '19',
            'config' => ''
        );

        Shopware()->Db()->insert('s_order_details', $orderDetailsData);
        $orderDetailId = Shopware()->Db()->lastInsertId();

        $orderEsdData = array(
            'serialID' => '8',
            'esdID' => '2',
            'userID' => $customer->getId(),
            'orderID' => $orderId,
            'orderdetailsID' => $orderDetailId,
            'datum' => '2014-03-14 10:26:20'
        );

        Shopware()->Db()->insert('s_order_esd', $orderEsdData);

        // Mock a login
        $orderEsdId = Shopware()->Db()->lastInsertId();

        // Calling the method should now return the expected data
        $downloads = $this->module->sGetDownloads();
        $result = $downloads['orderData'];

        $this->assertCount(1, $result);
        $esd = end($result);
        $this->assertArrayHasKey('id', $esd);
        $this->assertArrayHasKey('ordernumber', $esd);
        $this->assertArrayHasKey('invoice_amount', $esd);
        $this->assertArrayHasKey('invoice_amount_net', $esd);
        $this->assertArrayHasKey('invoice_shipping', $esd);
        $this->assertArrayHasKey('invoice_shipping_net', $esd);
        $this->assertArrayHasKey('datum', $esd);
        $this->assertArrayHasKey('status', $esd);
        $this->assertArrayHasKey('cleared', $esd);
        $this->assertArrayHasKey('comment', $esd);
        $this->assertArrayHasKey('details', $esd);
        $this->assertEquals($orderData['ordernumber'], $esd['ordernumber']);
        $this->assertEquals('37,99', $esd['invoice_amount']);
        $this->assertEquals($orderData['invoice_amount_net'], $esd['invoice_amount_net']);
        $this->assertEquals($orderData['invoice_shipping'], $esd['invoice_shipping']);
        $this->assertEquals($orderData['invoice_shipping_net'], $esd['invoice_shipping_net']);
        $this->assertEquals('14.03.2014 10:26', $esd['datum']);
        $this->assertEquals($orderData['status'], $esd['status']);
        $this->assertEquals($orderData['cleared'], $esd['cleared']);
        $this->assertEquals($orderData['comment'], $esd['comment']);
        $this->assertCount(1, $esd['details']);
        $esdDetail = end($esd['details']);

        $this->assertArrayHasKey('id', $esdDetail);
        $this->assertArrayHasKey('orderID', $esdDetail);
        $this->assertArrayHasKey('ordernumber', $esdDetail);
        $this->assertArrayHasKey('articleID', $esdDetail);
        $this->assertArrayHasKey('articleordernumber', $esdDetail);
        $this->assertArrayHasKey('serial', $esdDetail);
        $this->assertArrayHasKey('esdLink', $esdDetail);
        $this->assertNotNull($esdDetail['esdLink']);

        return array(
            'customer' => $customer,
            'orderEsdId' => $orderEsdId,
            'orderDetailId' => $orderDetailId,
            'orderId' => $orderId,
            'orderData' => $orderData
        );
    }

    /**
     * @covers sAdmin::sGetOpenOrderData
     * @depends testsGetDownloads
     * @ticket SW-5653
     */
    public function testsGetOpenOrderData($demoData)
    {
        // Inherit data from previous test
        $customer = $demoData['customer'];
        $oldOrderId = $demoData['orderId'];
        $orderEsdId = $demoData['orderEsdId'];
        $orderNumber = uniqid();

        // Add another order to the customer
        $orderData = array(
            'ordernumber' => $orderNumber,
            'userID' => $customer->getId(),
            'invoice_amount' => '16.89',
            'invoice_amount_net' => '14.2',
            'invoice_shipping' => '3.9',
            'invoice_shipping_net' => '3.28',
            'ordertime' => '2013-04-08 17:39:30',
            'status' => '0',
            'cleared' => '17',
            'paymentID' => '5',
            'transactionID' => '',
            'comment' => '',
            'customercomment' => '',
            'internalcomment' => '',
            'net' => '0',
            'taxfree' => '0',
            'partnerID' => '',
            'temporaryID' => '',
            'referer' => '',
            'cleareddate' => null,
            'trackingcode' => '',
            'language' => '2',
            'dispatchID' => '9',
            'currency' => 'EUR',
            'currencyFactor' => '1',
            'subshopID' => '1',
            'remote_addr' => '172.16.10.71'
        );

        Shopware()->Db()->insert('s_order', $orderData);
        $orderId = Shopware()->Db()->lastInsertId();

        Shopware()->Db()->query("
            INSERT IGNORE INTO `s_order_details` (`orderID`, `ordernumber`, `articleID`, `articleordernumber`, `price`, `quantity`, `name`, `status`, `shipped`, `shippedgroup`, `releasedate`, `modus`, `esdarticle`, `taxID`, `tax_rate`, `config`) VALUES
            (?, ?, 12, 'SW10012', 9.99, 1, 'Kobra Vodka 37,5%', 0, 0, 0, '0000-00-00', 0, 0, 1, 19, ''),
            (?, ?, 0, 'SHIPPINGDISCOUNT', -2, 1, 'Warenkorbrabatt', 0, 0, 0, '0000-00-00', 4, 0, 0, 19, ''),
            (?, ?, 0, 'sw-surcharge', 5, 1, 'Mindermengenzuschlag', 0, 0, 0, '0000-00-00', 4, 0, 0, 19, '');
        ", array(
            $orderId, $orderNumber,
            $orderId, $orderNumber,
            $orderId, $orderNumber
        ));


        // At this point, the user is not logged in so we should have no data
        $data = $this->module->sGetOpenOrderData();
        $this->assertCount(0, $data['orderData']);

        // Mock a login
        $this->session->offsetSet('sUserId', $customer->getId());

        // Calling the method should now return the expected data
        $result = $this->module->sGetOpenOrderData();
        $result = $result['orderData'];

        $this->assertCount(2, $result);
        foreach ($result as $order) {
            $this->assertArrayHasKey('id', $order);
            $this->assertArrayHasKey('ordernumber', $order);
            $this->assertArrayHasKey('invoice_amount', $order);
            $this->assertArrayHasKey('invoice_amount_net', $order);
            $this->assertArrayHasKey('invoice_shipping', $order);
            $this->assertArrayHasKey('invoice_shipping_net', $order);
            $this->assertArrayHasKey('datum', $order);
            $this->assertArrayHasKey('status', $order);
            $this->assertArrayHasKey('cleared', $order);
            $this->assertArrayHasKey('comment', $order);
            $this->assertArrayHasKey('details', $order);
            foreach ($order['details'] as $detail) {
                $this->assertArrayHasKey('id', $detail);
                $this->assertArrayHasKey('orderID', $detail);
                $this->assertArrayHasKey('ordernumber', $detail);
                $this->assertArrayHasKey('articleID', $detail);
                $this->assertArrayHasKey('articleordernumber', $detail);
            }

            // This tests SW-5653
            if ($order['id'] == $orderId) {
                $this->assertNotEmpty($order);
                $this->assertEquals($orderNumber, $order["ordernumber"]);
                $this->assertEquals($customer->getId(), $order["userID"]);
                break;
            }
        }

        Shopware()->Db()->delete('s_order_esd', 'id = '.$orderEsdId);
        Shopware()->Db()->delete('s_order_details', 'orderID = '.$orderId);
        Shopware()->Db()->delete('s_order_details', 'orderID = '.$oldOrderId);
        Shopware()->Db()->delete('s_order', 'id = '.$orderId);
        Shopware()->Db()->delete('s_order', 'id = '.$oldOrderId);
        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sGetUserMailById
     * @covers sAdmin::sGetUserByMail
     * @covers sAdmin::sGetUserNameById
     */
    public function testGetEmailAndUser()
    {
        $customer = $this->createDummyCustomer();

        // Test sGetUserMailById with null and expected cases
        $this->assertNull($this->module->sGetUserMailById());
        $this->session->offsetSet('sUserId', $customer->getId());
        $this->assertEquals($customer->getEmail(), $this->module->sGetUserMailById());

        // Test sGetUserByMail with null and expected cases
        $this->assertNull($this->module->sGetUserByMail(uniqid()));
        $this->assertEquals($customer->getId(), $this->module->sGetUserByMail($customer->getEmail()));

        // Test sGetUserNameById with null and expected cases
        $this->assertEmpty($this->module->sGetUserNameById(uniqid()));
        $this->assertEquals(
            array('firstname' => 'Max', 'lastname' => 'Mustermann'),
            $this->module->sGetUserNameById($customer->getId())
        );

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sGetUserData
     */
    public function testsGetUserDataWithoutLogin()
    {
        $this->assertEquals(
            array('additional' =>
                array(
                    'country' => array(),
                    'countryShipping' => array(),
                    'stateShipping' => array('id' => 0)
                )
            ),
            $this->module->sGetUserData()
        );

        $this->session->offsetSet('sCountry', 20);

        $this->assertEquals(
            array('additional' =>
                array(
                    'country' => array(
                        'id' => '20',
                        'countryname' => 'Namibia',
                        'countryiso' => 'NA',
                        'areaID' => '2',
                        'countryen' => 'NAMIBIA',
                        'position' => '10',
                        'notice' => '',
                        'shippingfree' => '0',
                        'taxfree' => '0',
                        'taxfree_ustid' => '0',
                        'taxfree_ustid_checked' => '0',
                        'active' => '0',
                        'iso3' => 'NAM',
                        'display_state_in_registration' => '0',
                        'force_state_in_registration' => '0',
                        'countryarea' => 'welt'
                    ),
                    'countryShipping' => array(
                        'id' => '20',
                        'countryname' => 'Namibia',
                        'countryiso' => 'NA',
                        'areaID' => '2',
                        'countryen' => 'NAMIBIA',
                        'position' => '10',
                        'notice' => '',
                        'shippingfree' => '0',
                        'taxfree' => '0',
                        'taxfree_ustid' => '0',
                        'taxfree_ustid_checked' => '0',
                        'active' => '0',
                        'iso3' => 'NAM',
                        'display_state_in_registration' => '0',
                        'force_state_in_registration' => '0',
                        'countryarea' => 'welt'
                    ),
                    'stateShipping' => array('id' => 0),
                )
            ),
            $this->module->sGetUserData()
        );
    }

    /**
     * @covers sAdmin::sGetUserData
     */
    public function testsGetUserDataWithLogin()
    {
        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        $result = $this->module->sGetUserData();

        $expectedData = array(
            'billingaddress' => array(
                'customerBillingId' => $customer->getBilling()->getId(),
                'text1' => 'Freitext1',
                'text2' => 'Freitext2',
                'text3' => null,
                'text4' => null,
                'text5' => null,
                'text6' => null,
                'id' => $customer->getBilling()->getId(),
                'userID' => $customer->getId(),
                'company' => '',
                'department' => '',
                'salutation' => '',
                'customernumber' => $customer->getBilling()->getNumber(),
                'firstname' => 'Max',
                'lastname' => 'Mustermann',
                'street' => 'Kraftweg, 22',
                'zipcode' => '12345',
                'city' => '',
                'phone' => '',
                'countryID' => '2',
                'stateID' => null,
                'ustid' => '',
                'birthday' => '1986-12-20',
                'additional_address_line1' => 'IT-Department',
                'additional_address_line2' => 'Second Floor'
            ),
            'additional' => array(
                'country' => array(
                    'id' => '2',
                    'countryname' => 'Germany',
                    'countryiso' => 'DE',
                    'areaID' => '1',
                    'countryen' => 'GERMANY',
                    'position' => '1',
                    'notice' => '',
                    'shippingfree' => '0',
                    'taxfree' => '0',
                    'taxfree_ustid' => '0',
                    'taxfree_ustid_checked' => '0',
                    'active' => '1',
                    'iso3' => 'DEU',
                    'display_state_in_registration' => '0',
                    'force_state_in_registration' => '0',
                    'countryarea' => 'deutschland'
                ),
                'state' => array(),
                'user' => array(
                    'id' => (int) $customer->getId(),
                    'password' => $customer->getPassword(),
                    'encoder' => 'bcrypt',
                    'email' => $customer->getEmail(),
                    'active' => '1',
                    'accountmode' => '0',
                    'confirmationkey' => '',
                    'paymentID' => '0',
                    'firstlogin' => $customer->getFirstLogin()->format('Y-m-d'),
                    'lastlogin' => $customer->getLastLogin()->format('Y-m-d H:i:s'),
                    'sessionID' => '',
                    'newsletter' => 0,
                    'validation' => '',
                    'affiliate' => '0',
                    'customergroup' => 'EK',
                    'paymentpreset' => '0',
                    'language' => '1',
                    'subshopID' => '1',
                    'referer' => '',
                    'pricegroupID' => null,
                    'internalcomment' => '',
                    'failedlogins' => '0',
                    'lockeduntil' => null,
                    'default_billing_address_id' => $customer->getDefaultBillingAddress() ? $customer->getDefaultBillingAddress()->getId() : null,
                    'default_shipping_address_id' => $customer->getDefaultShippingAddress() ? $customer->getDefaultShippingAddress()->getId() : null
                ),
                'countryShipping' => array(
                    'id' => '4',
                    'countryname' => 'Australien',
                    'countryiso' => 'AU',
                    'areaID' => '2',
                    'countryen' => 'AUSTRALIA',
                    'position' => '10',
                    'notice' => '',
                    'shippingfree' => '0',
                    'taxfree' => '0',
                    'taxfree_ustid' => '0',
                    'taxfree_ustid_checked' => '0',
                    'active' => '1',
                    'iso3' => 'AUS',
                    'display_state_in_registration' => '0',
                    'force_state_in_registration' => '0',
                    'countryarea' => 'welt'
                ),
                'stateShipping' => array(),
                'payment' => array(
                    'id' => '5',
                    'name' => 'prepayment',
                    'description' => 'Vorkasse',
                    'template' => 'prepayment.tpl',
                    'class' => 'prepayment.php',
                    'table' => '',
                    'hide' => '0',
                    'additionaldescription' => 'Sie zahlen einfach vorab und erhalten die Ware bequem und günstig bei Zahlungseingang nach Hause geliefert.',
                    'debit_percent' => '0',
                    'surcharge' => '0',
                    'surchargestring' => '',
                    'position' => '1',
                    'active' => '1',
                    'esdactive' => '0',
                    'mobile_inactive' => '0',
                    'embediframe' => '',
                    'hideprospect' => '0',
                    'action' => null,
                    'pluginID' => null,
                    'source' => null,
                ),
            ),
            'shippingaddress' => array(
                'customerShippingId' => $customer->getShipping()->getId(),
                'text1' => 'Freitext1',
                'text2' => 'Freitext2',
                'text3' => null,
                'text4' => null,
                'text5' => null,
                'text6' => null,
                'id' => $customer->getShipping()->getId(),
                'userID' => $customer->getId(),
                'company' => 'Widgets Inc.',
                'department' => '',
                'salutation' => 'Mr',
                'firstname' => 'Max',
                'lastname' => 'Mustermann',
                'street' => 'Merkel Strasse, 10',
                'zipcode' => '98765',
                'city' => '',
                'countryID' => '4',
                'stateID' => null,
                'additional_address_line1' => 'Sales-Department',
                'additional_address_line2' => 'Third Floor'
            ),
        );

        $this->assertEquals($expectedData, $result);

        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sManageRisks
     * @covers sAdmin::sRiskORDERVALUELESS
     * @covers sAdmin::sRiskORDERVALUEMORE
     * @covers sAdmin::sRiskCUSTOMERGROUPIS
     * @covers sAdmin::sRiskCUSTOMERGROUPISNOT
     * @covers sAdmin::sRiskZIPCODE
     * @covers sAdmin::sRiskBILLINGZIPCODE
     * @covers sAdmin::sRiskZONEIS
     * @covers sAdmin::sRiskBILLINGZONEIS
     * @covers sAdmin::sRiskZONEISNOT
     * @covers sAdmin::sRiskBILLINGZONEISNOT
     * @covers sAdmin::sRiskLANDIS
     * @covers sAdmin::sRiskBILLINGLANDIS
     * @covers sAdmin::sRiskLANDISNOT
     * @covers sAdmin::sRiskBILLINGLANDISNOT
     * @covers sAdmin::sRiskNEWCUSTOMER
     * @covers sAdmin::sRiskORDERPOSITIONSMORE
     * @covers sAdmin::sRiskATTRIS
     * @covers sAdmin::sRiskATTRISNOT
     * @covers sAdmin::sRiskDUNNINGLEVELONE
     * @covers sAdmin::sRiskDUNNINGLEVELTWO
     * @covers sAdmin::sRiskDUNNINGLEVELTHREE
     * @covers sAdmin::sRiskINKASSO
     * @covers sAdmin::sRiskLASTORDERLESS
     * @covers sAdmin::sRiskARTICLESFROM
     * @covers sAdmin::sRiskLASTORDERSLESS
     * @covers sAdmin::sRiskPREGSTREET
     * @covers sAdmin::sRiskDIFFER
     * @covers sAdmin::sRiskCUSTOMERNR
     * @covers sAdmin::sRiskLASTNAME
     * @covers sAdmin::sRiskSUBSHOP
     * @covers sAdmin::sRiskSUBSHOPNOT
     * @covers sAdmin::sRiskCURRENCIESISOIS
     * @covers sAdmin::sRiskCURRENCIESISOISNOT
     */
    public function testsManageRisks()
    {
        $customer = $this->createDummyCustomer();
        $this->session->offsetSet('sUserId', $customer->getId());

        $basket = array(
            'content' => 1,
            'AmountNumeric' => 10
        );
        $user = $this->module->sGetUserData();

        $date = new DateTime();

        // Inject demo data
        $orderData = array(
            'ordernumber' => uniqid(),
            'userID' => $customer->getId(),
            'invoice_amount' => '37.99',
            'invoice_amount_net' => '31.92',
            'invoice_shipping' => '0',
            'invoice_shipping_net' => '0',
            'ordertime' => $date->format("Y-m-d H:i:s"),
            'status' => '0',
            'cleared' => '17',
            'paymentID' => '4',
            'transactionID' => '',
            'comment' => '',
            'customercomment' => '',
            'internalcomment' => '',
            'net' => '0',
            'taxfree' => '0',
            'partnerID' => '',
            'temporaryID' => '',
            'referer' => '',
            'cleareddate' => null,
            'cleared' => 16,
            'trackingcode' => '',
            'language' => '2',
            'dispatchID' => '9',
            'currency' => 'EUR',
            'currencyFactor' => '1',
            'subshopID' => '1',
            'remote_addr' => '127.0.0.1'
        );

        Shopware()->Db()->insert('s_order', $orderData);
        $orderId = Shopware()->Db()->lastInsertId();

        // No rules, returns false
        $this->assertFalse($this->module->sManageRisks(2, $basket, $user));

        // Test all rules

        // sRiskORDERVALUELESS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ORDERVALUELESS',
                'value1' => 20
            )
        );
        $firstTestRuleId = Shopware()->Db()->lastInsertId();
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));

        // sRiskORDERVALUEMORE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ORDERVALUEMORE',
                'value1' => 20
            )
        );
        // Test 'OR' logic between different rules (only one needs to be true)
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));

        // Deleting the first rule, only a false one is left
        Shopware()->Db()->delete('s_core_rulesets', 'id = '.$firstTestRuleId);
        $this->assertFalse($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCUSTOMERGROUPIS
        // sRiskCUSTOMERGROUPISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CUSTOMERGROUPIS',
                'value1' => 'EK',
                'rule2' => 'CUSTOMERGROUPISNOT',
                'value2' => 'EK'
            )
        );

        // Test 'AND' logic between the two parts of the same rule (both need to be true)
        $this->assertFalse($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskZIPCODE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ZIPCODE',
                'value1' => '98765'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskBILLINGZIPCODE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'BILLINGZIPCODE',
                'value1' => '12345'
            )
        );

        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskZONEIS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ZONEIS',
                'value1' => '12345'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskZONEISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ZONEISNOT',
                'value1' => '12345'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLANDIS
        // sRiskLANDISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LANDIS',
                'value1' => 'AU',
                'rule2' => 'LANDISNOT',
                'value2' => 'UK'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskBILLINGLANDIS
        // sRiskBILLINGLANDISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'BILLINGLANDIS',
                'value1' => 'DE',
                'rule2' => 'BILLINGLANDISNOT',
                'value2' => 'UK'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskNEWCUSTOMER
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'NEWCUSTOMER',
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskORDERPOSITIONSMORE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ORDERPOSITIONSMORE',
                'value1' => '2'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $basket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);
        $this->basketModule->sAddArticle('SW10118.8');

        // sRiskATTRIS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ATTRIS',
                'value1' => '1|0'
            )
        );

        $fullBasket = $this->basketModule->sGetBasket();
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        $this->basketModule->sAddArticle('SW10118.8');
        // sRiskATTRISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ATTRISNOT',
                'value1' => '17|null'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskDUNNINGLEVELONE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'DUNNINGLEVELONE'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskDUNNINGLEVELTWO
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'DUNNINGLEVELTWO'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskDUNNINGLEVELTHREE
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'DUNNINGLEVELTHREE'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskINKASSO
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'INKASSO'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLASTORDERLESS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LASTORDERLESS',
                'value1' => '1'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskARTICLESFROM
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ARTICLESFROM',
                'value1' => '1'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskARTICLESFROM
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'ARTICLESFROM',
                'value1' => '9'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLASTORDERSLESS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LASTORDERSLESS',
                'value1' => '9'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLASTORDERSLESS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LASTORDERSLESS',
                'value1' => '0'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskPREGSTREET
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'PREGSTREET',
                'value1' => 'Merkel'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskPREGSTREET
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'PREGSTREET',
                'value1' => 'Google'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskPREGBILLINGSTREET
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'PREGBILLINGSTREET',
                'value1' => 'Google'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskDIFFER
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'DIFFER'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCUSTOMERNR
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CUSTOMERNR',
                'value1' => $customer->getBilling()->getNumber()
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCUSTOMERNR
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CUSTOMERNR',
                'value1' => 'ThisIsNeverGoingToBeACustomerNumber'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLASTNAME
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LASTNAME',
                'value1' => 'Mustermann'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskLASTNAME
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'LASTNAME',
                'value1' => 'NotMustermann'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskSUBSHOP
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'SUBSHOP',
                'value1' => '1'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskSUBSHOP
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'SUBSHOP',
                'value1' => '2'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskSUBSHOPNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'SUBSHOPNOT',
                'value1' => '2'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskSUBSHOPNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'SUBSHOPNOT',
                'value1' => '1'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCURRENCIESISOIS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CURRENCIESISOIS',
                'value1' => 'eur'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCURRENCIESISOIS
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CURRENCIESISOIS',
                'value1' => 'yen'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCURRENCIESISOISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CURRENCIESISOISNOT',
                'value1' => 'eur'
            )
        );
        $this->assertFalse($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        // sRiskCURRENCIESISOISNOT
        Shopware()->Db()->insert(
            's_core_rulesets',
            array(
                'paymentID' => 2,
                'rule1' => 'CURRENCIESISOISNOT',
                'value1' => 'yen'
            )
        );
        $this->assertTrue($this->module->sManageRisks(2, $fullBasket, $user));
        Shopware()->Db()->delete('s_core_rulesets', 'id >= '.$firstTestRuleId);

        Shopware()->Db()->delete('s_order', 'id = '.$orderId);
        $this->deleteDummyCustomer($customer);
    }

    /**
     * @covers sAdmin::sNewsletterSubscription
     */
    public function testsNewsletterSubscriptionWithPostData()
    {
        // Test subscribe with empty post field and empty address, fail validation
        $this->front->Request()->setPost('newsletter', '');
        $result = $this->module->sNewsletterSubscription('');
        $this->assertEquals(
            array(
                'code' => 5,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('ErrorFillIn', 'Please fill in all red fields'),
                'sErrorFlag' => array('newsletter' => true)
            ),
            $result
        );
    }

    /**
     * @covers sAdmin::sNewsletterSubscription
     */
    public function testsNewsletterSubscription()
    {
        $validAddress = uniqid().'@shopware.com';

        // Test unsubscribe with non existing email, fail
        $result = $this->module->sNewsletterSubscription(uniqid().'@shopware.com', true);
        $this->assertEquals(
            array(
                'code' => 4,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureNotFound', 'This mail address could not be found')
            ),
            $result
        );

        // Test unsubscribe with empty post field, fail validation
        $result = $this->module->sNewsletterSubscription('', true);
        $this->assertEquals(
            array(
                'code' => 6,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureMail', 'Enter eMail address')
            ),
            $result
        );

        // Test with empty field, fail validation
        $result = $this->module->sNewsletterSubscription('');
        $this->assertEquals(
            array(
                'code' => 6,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureMail', 'Enter eMail address')
            ),
            $result
        );

        // Test with malformed email, fail validation
        $result = $this->module->sNewsletterSubscription('thisIsNotAValidEmailAddress');
        $this->assertEquals(
            array(
                'code' => 1,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureInvalid', 'Enter valid eMail address')
            ),
            $result
        );

        // Check that test email does not exist
        $this->assertFalse(
            Shopware()->Db()->fetchRow(
                'SELECT email, groupID FROM s_campaigns_mailaddresses WHERE email LIKE ?',
                array($validAddress)
            )
        );

        // Test with correct unique email, all ok
        $result = $this->module->sNewsletterSubscription($validAddress);
        $this->assertEquals(
            array(
                'code' => 3,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterSuccess', 'Thank you for receiving our newsletter')
            ),
            $result
        );

        // Check that test email was inserted
        $this->assertEquals(
            array(
                'email' => $validAddress,
                'groupID' => $this->config->get('sNEWSLETTERDEFAULTGROUP')
            ),
            Shopware()->Db()->fetchRow(
                'SELECT email, groupID FROM s_campaigns_mailaddresses WHERE email LIKE ?',
                array($validAddress)
            )
        );
        $this->assertEquals(
            array(
                array(
                    'email' => $validAddress,
                    'groupID' => $this->config->get('sNEWSLETTERDEFAULTGROUP')
                )
            ),
            Shopware()->Db()->fetchAll(
                'SELECT email, groupID FROM s_campaigns_maildata WHERE email LIKE ?',
                array($validAddress)
            )
        );

        // Test with same email, fail
        $result = $this->module->sNewsletterSubscription($validAddress);
        $this->assertEquals(
            array(
                'code' => 2,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureAlreadyRegistered', 'You already receive our newsletter')
            ),
            $result
        );

        // Test with same email in a different list, fail
        $groupId = rand(1, 9999);
        $result = $this->module->sNewsletterSubscription($validAddress, false, $groupId);
        $this->assertEquals(
            array(
                'code' => 2,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterFailureAlreadyRegistered', 'You already receive our newsletter')
            ),
            $result
        );

        // Check that test email address is still there, but now in two groups
        $this->assertEquals(
            array(
                array(
                    'email' => $validAddress,
                    'groupID' => $this->config->get('sNEWSLETTERDEFAULTGROUP')
                )
            ),
            Shopware()->Db()->fetchAll(
                'SELECT email, groupID FROM s_campaigns_mailaddresses WHERE email LIKE ?',
                array($validAddress)
            )
        );
        $this->assertEquals(
            array(
                array(
                    'email' => $validAddress,
                    'groupID' => $this->config->get('sNEWSLETTERDEFAULTGROUP')
                ),
                array(
                    'email' => $validAddress,
                    'groupID' => $groupId
                )
            ),
            Shopware()->Db()->fetchAll(
                'SELECT email, groupID FROM s_campaigns_maildata WHERE email LIKE ?',
                array($validAddress)
            )
        );

        // Test unsubscribe the same email, all ok
        $result = $this->module->sNewsletterSubscription($validAddress, true);
        $this->assertEquals(
            array(
                'code' => 5,
                'message' => $this->snippetManager->getNamespace('frontend/account/internalMessages')
                        ->get('NewsletterMailDeleted', 'Your mail address was deleted')
            ),
            $result
        );

        // Check that test email address was removed
        $this->assertFalse(
            Shopware()->Db()->fetchRow(
                'SELECT email, groupID FROM s_campaigns_mailaddresses WHERE email LIKE ?',
                array($validAddress)
            )
        );

        // But not completely from maildata
        $this->assertEquals(
            array(
                array(
                    'email' => $validAddress,
                    'groupID' => $groupId
                )
            ),
            Shopware()->Db()->fetchAll(
                'SELECT email, groupID FROM s_campaigns_maildata WHERE email LIKE ?',
                array($validAddress)
            )
        );

        Shopware()->Db()->delete(
            's_campaigns_maildata',
            'email LIKE "'.$validAddress.'"'
        );
    }

    /**
     * @covers sAdmin::sGetCountry
     */
    public function testsGetCountry()
    {
        // Empty argument, return false
        $this->assertFalse($this->module->sGetCountry(''));

        // No matching country, return empty array
        $this->assertEquals(array(), $this->module->sGetCountry(-1));

        // Valid country returns valid data
        $result = $this->module->sGetCountry('de');
        $this->assertEquals(
            array(
                'id' => '2',
                'countryID' => '2',
                'countryname' => 'Deutschland',
                'countryiso' => 'DE',
                'countryarea' => 'deutschland',
                'countryen' => 'GERMANY',
                'position' => '1',
                'notice' => '',
                'shippingfree' => '0',
            ),
            $result
        );

        // Fetching for id or iso code gives the same result
        $this->assertEquals(
            $this->module->sGetCountry($result['id']),
            $result
        );
    }

    /**
     * @covers sAdmin::sGetPaymentMean
     */
    public function testsGetPaymentmean()
    {
        // Empty argument, return false
        $this->assertFalse($this->module->sGetPaymentMean(''));

        // No matching payment mean, return empty array
        $this->assertEquals(array('country_surcharge' => array()), $this->module->sGetPaymentMean(-1));

        // Valid country returns valid data
        $result = $this->module->sGetPaymentMean(
            Shopware()->Db()->fetchOne('SELECT id FROM s_core_paymentmeans WHERE name = "prepayment"')
        );

        $this->assertEquals(
            array(
                'id' => '5',
                'name' => 'prepayment',
                'description' => 'Vorkasse',
                'template' => 'prepayment.tpl',
                'class' => 'prepayment.php',
                'table' => '',
                'hide' => '0',
                'additionaldescription' => 'Sie zahlen einfach vorab und erhalten die Ware bequem und günstig bei Zahlungseingang nach Hause geliefert.',
                'debit_percent' => '0',
                'surcharge' => '0',
                'surchargestring' => '',
                'position' => '1',
                'active' => '1',
                'esdactive' => '0',
                'mobile_inactive' => '0',
                'embediframe' => '',
                'hideprospect' => '0',
                'action' => null,
                'pluginID' => null,
                'source' => null,
                'country_surcharge' =>
                    array(
                    ),
            ),
            $result
        );

        // Fetching for id or iso code gives the same result
        $this->assertEquals(
            $this->module->sGetPaymentMean($result['name']),
            $result
        );
    }

    /**
     * @covers sAdmin::sGetDispatchBasket
     */
    public function testsGetDispatchBasket()
    {
        // No basket, return false
        $this->assertFalse($this->module->sGetDispatchBasket());

        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);
        $this->basketModule->sAddArticle('SW10118.8');

        // With the correct data, return properly formatted array
        // This is a big query function
        $result = $this->module->sGetDispatchBasket();
        $this->assertArrayHasKey('instock', $result);
        $this->assertArrayHasKey('stockmin', $result);
        $this->assertArrayHasKey('laststock', $result);
        $this->assertArrayHasKey('weight', $result);
        $this->assertArrayHasKey('count_article', $result);
        $this->assertArrayHasKey('shippingfree', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('amount_net', $result);
        $this->assertArrayHasKey('amount_display', $result);
        $this->assertArrayHasKey('length', $result);
        $this->assertArrayHasKey('height', $result);
        $this->assertArrayHasKey('width', $result);
        $this->assertArrayHasKey('userID', $result);
        $this->assertArrayHasKey('has_topseller', $result);
        $this->assertArrayHasKey('has_comment', $result);
        $this->assertArrayHasKey('has_esd', $result);
        $this->assertArrayHasKey('max_tax', $result);
        $this->assertArrayHasKey('basketStateId', $result);
        $this->assertArrayHasKey('countryID', $result);
        $this->assertArrayHasKey('paymentID', $result);
        $this->assertArrayHasKey('customergroupID', $result);
        $this->assertArrayHasKey('multishopID', $result);
        $this->assertArrayHasKey('sessionID', $result);
    }

    /**
     * @covers sAdmin::sGetPremiumDispatches
     */
    public function testsGetPremiumDispatches()
    {
        // No basket, return empty array,
        $this->assertEquals(array(), $this->module->sGetPremiumDispatches());

        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);
        $this->basketModule->sAddArticle('SW10118.8');

        $result = $this->module->sGetPremiumDispatches();

        $this->assertGreaterThan(0, count($result));
        foreach ($result as $dispatch) {
            $this->assertArrayHasKey('id', $dispatch);
            $this->assertArrayHasKey('name', $dispatch);
            $this->assertArrayHasKey('description', $dispatch);
            $this->assertArrayHasKey('calculation', $dispatch);
            $this->assertArrayHasKey('status_link', $dispatch);
        }
    }

    /**
     * @covers sAdmin::sGetPremiumDispatchSurcharge
     */
    public function testsGetPremiumDispatchSurcharge()
    {
        // No basket, return false,
        $this->assertFalse($this->module->sGetPremiumDispatchSurcharge(null));

        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);
        $this->basketModule->sAddArticle('SW10010');
        $fullBasket = $this->module->sGetDispatchBasket();

        $result = $this->module->sGetPremiumDispatchSurcharge($fullBasket);
        $this->assertEquals(0, $result);
    }

    /**
     * @covers sAdmin::sGetPremiumShippingcosts
     */
    public function testsGetPremiumShippingcosts()
    {
        // No basket, return false,
        $this->assertFalse($this->module->sGetPremiumShippingcosts());

        $countries = $this->module->sGetCountryList();
        foreach ($countries as $country) {
            if ($country['countryiso']) {
                $germany = $country;
                break;
            }
        }

        $this->module->sSYSTEM->sSESSION_ID = uniqid();
        $this->session->offsetSet('sessionId', $this->module->sSYSTEM->sSESSION_ID);
        $this->basketModule->sAddArticle('SW10010');

        // With country data, no dispatch method
        $this->assertEquals(
            array('brutto' => 0, 'netto' => 0),
            $this->module->sGetPremiumShippingcosts($germany)
        );

        // With dispatch method
        $this->session->offsetSet('sDispatch', 9);
        $result = $this->module->sGetPremiumShippingcosts($germany);
        $this->assertArrayHasKey('brutto', $result);
        $this->assertArrayHasKey('netto', $result);
        $this->assertArrayHasKey('value', $result);
        $this->assertArrayHasKey('factor', $result);
        $this->assertArrayHasKey('surcharge', $result);
        $this->assertArrayHasKey('tax', $result);
    }

    /**
     * Create dummy customer entity
     *
     * @return \Shopware\Models\Customer\Customer
     */
    private function createDummyCustomer()
    {
        $date = new DateTime();
        $date->modify('-8 days');
        $lastLogin = $date->format(DateTime::ISO8601);

        $birthday = DateTime::createFromFormat('Y-m-d', '1986-12-20')->format(DateTime::ISO8601);

        $testData = array(
            "password" => "fooobar",
            "email"    => uniqid() . 'test@foobar.com',

            "lastlogin"  => $lastLogin,

            "billing" => array(
                "firstName" => "Max",
                "lastName"  => "Mustermann",
                "birthday"  => $birthday,
                "attribute" => array(
                    'text1' => 'Freitext1',
                    'text2' => 'Freitext2',
                ),
                "zipcode"   => '12345',
                "street"    => 'Kraftweg, 22',
                "countryId" => '2',
                "additionalAddressLine1" => 'IT-Department',
                "additionalAddressLine2" => 'Second Floor',
            ),

            "shipping" => array(
                "salutation" => "Mr",
                "company"    => "Widgets Inc.",
                "firstName"  => "Max",
                "lastName"   => "Mustermann",
                "zipcode"     => "98765",
                "street"     => "Merkel Strasse, 10",
                "countryId"  => '4',
                "attribute"  => array(
                    'text1'  => 'Freitext1',
                    'text2'  => 'Freitext2',
                ),
                "additionalAddressLine1" => 'Sales-Department',
                "additionalAddressLine2" => 'Third Floor',
            ),

            "debit" => array(
                "account"       => "Fake Account",
                "bankCode"      => "55555555",
                "bankName"      => "Fake Bank",
                "accountHolder" => "Max Mustermann",
            ),
        );

        $customerResource = new \Shopware\Components\Api\Resource\Customer();
        $customerResource->setManager(Shopware()->Models());

        return $customerResource->create($testData);
    }

    /**
     * Deletes all dummy customer entity
     */
    private function deleteDummyCustomer(\Shopware\Models\Customer\Customer $customer)
    {
        $billingId = Shopware()->Db()->fetchOne('SELECT id FROM s_user_billingaddress WHERE userID = ?', array($customer->getId()));
        $shippingId = Shopware()->Db()->fetchOne('SELECT id FROM s_user_shippingaddress WHERE userID = ?', array($customer->getId()));

        if ($billingId) {
            Shopware()->Db()->delete('s_user_billingaddress_attributes', 'billingID = '.$billingId);
            Shopware()->Db()->delete('s_user_billingaddress', 'id = '.$billingId);
        }
        if ($shippingId) {
            Shopware()->Db()->delete('s_user_shippingaddress_attributes', 'shippingID = '.$shippingId);
            Shopware()->Db()->delete('s_user_shippingaddress', 'id = '.$shippingId);
        }
        Shopware()->Db()->delete('s_core_payment_data', 'user_id = '.$customer->getId());
        Shopware()->Db()->delete('s_user_attributes', 'userID = '.$customer->getId());
        Shopware()->Db()->delete('s_user', 'id = '.$customer->getId());
    }
}
