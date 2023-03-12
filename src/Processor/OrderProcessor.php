<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FriendsOfSylius\SyliusImportExportPlugin\Processor;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use FriendsOfSylius\SyliusImportExportPlugin\Exception\ImporterException;
use FriendsOfSylius\SyliusImportExportPlugin\Importer\Transformer\TransformerPoolInterface;
use FriendsOfSylius\SyliusImportExportPlugin\Repository\ProductImageRepositoryInterface;
use FriendsOfSylius\SyliusImportExportPlugin\Service\AttributeCodesProviderInterface;
use FriendsOfSylius\SyliusImportExportPlugin\Service\ImageTypesProvider;
use FriendsOfSylius\SyliusImportExportPlugin\Service\ImageTypesProviderInterface;



use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Bundle\CoreBundle\Fixture\OptionsResolver\LazyOption;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Core\Checker\OrderPaymentMethodSelectionRequirementCheckerInterface;
use Sylius\Component\Core\Checker\OrderShippingMethodSelectionRequirementCheckerInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Webmozart\Assert\Assert;

use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

use Sylius\Component\Order\OrderTransitions;


class OrderProcessor implements ResourceProcessorInterface
{
    /** @var OptionsResolver */
    private $optionsResolver;

    /** @var Generator */
    private $faker;
	private $headerKeys;
	private $manager;
	private $orderFactory;
	private $orderItemFactory;
	private $orderItemQuantityModifier;
	private $orderManager;
	private $channelRepository;
	private $customerRepository;
	private $productRepository;
	private $orderRepository;
	private $countryRepository;
	private $customerFactory;
	private $userFactory;
	
	private $paymentMethodRepository;
	private $shippingMethodRepository;
	private $addressFactory;
	private $stateMachineFactory;
	private $orderShippingMethodSelectionRequirementChecker;
	private $orderPaymentMethodSelectionRequirementChecker;
	private $datetime;
	
	
    public function __construct(
		FactoryInterface $userFactory,
        FactoryInterface $orderFactory,
        FactoryInterface $orderItemFactory,
        OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        ObjectManager $orderManager,
        RepositoryInterface $channelRepository,
        RepositoryInterface $customerRepository,
        FactoryInterface $customerFactory,
        
        ProductRepositoryInterface $productRepository,
        OrderRepositoryInterface $orderRepository,
        RepositoryInterface $countryRepository,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        ShippingMethodRepositoryInterface $shippingMethodRepository,
        FactoryInterface $addressFactory,
        StateMachineFactoryInterface $stateMachineFactory,
        OrderShippingMethodSelectionRequirementCheckerInterface $orderShippingMethodSelectionRequirementChecker,
        OrderPaymentMethodSelectionRequirementCheckerInterface $orderPaymentMethodSelectionRequirementChecker,
		EntityManagerInterface $manager,
        array $headerKeys,
    ) {
		$this->headerKeys = $headerKeys;
        $this->manager = $manager; 
		$this->faker = Factory::create();
		/* $this->optionsResolver = new OptionsResolver();
        
        $this->configureOptions($this->optionsResolver); */
		
	
        $this->orderFactory = $orderFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->orderItemQuantityModifier = $orderItemQuantityModifier;
        $this->orderManager = $orderManager;
        $this->channelRepository = $channelRepository;
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->userFactory = $userFactory;
        $this->productRepository = $productRepository;
        $this->orderRepository = $orderRepository;
        $this->countryRepository = $countryRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->addressFactory = $addressFactory;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->orderShippingMethodSelectionRequirementChecker = $orderShippingMethodSelectionRequirementChecker;
        $this->orderPaymentMethodSelectionRequirementChecker = $orderPaymentMethodSelectionRequirementChecker;
       
        
    }
	public function process(array $data): void
    {
		
		$this->setDatetime( new \DateTime('@'.strtotime($data['checkout_completed_at'])));
		//$this->setDatetime( \DateTime::createFromInterface($data['checkout_completed_at']));
		$channel = $this->channelRepository->findOneByCode($data['channel_code']);
		$currencyCode = $channel->getBaseCurrency()->getCode();
		$localeCode = $channel->getLocales()->toArray()[0]->getCode();
		
		/********* check customer or create customer with user login details ***/
		$customer = $this->createOrProvideCustomer($data);
		
		$order = $this->orderRepository->findOneBy(['number' => $data['number']]);
		
		
		if (null === $order) {
            $order = $this->orderFactory->createNew();
        } 
		//$order = $this->orderFactory->createNew();
		
		/********************/
		/******** Create Order ***/
		
		
		
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setCurrencyCode($currencyCode);
        $order->setLocaleCode($localeCode);
		$order->setNumber($data['number']);
		
		/****** add items to order *********/
		
		$this->generateItems($order,$data);
		/*************/
		
		/*** Address ********/
		$this->address($order, $customer,$data);
		/*********/
		
		
		
		
		$this->selectShipping($order, $this->datetime,$data);
        $this->selectPayment($order, $this->datetime,$data);
		
		
        $this->completeCheckout($order,$data);
		$this->setOrderCompletedDate($order, $this->datetime);
		if ($data['state']=='fulfilled') {
			
            $this->fulfillOrder($order);
        }
		
		$this->orderRepository->add($order);
		/*********Payemnt method ***/
		/* $paymentMethod = $this->paymentMethodRepository->findOneBy([
            'code' => 'wire-transfer',
        ]); 
		$shippingMethod = $this->shippingMethodRepository->findOneBy([
            'code' => 'fedex-po',
        ]);
		
		$this->proceedSelectingShippingAndPaymentMethod($order, $shippingMethod, $paymentMethod);
        $this->completeCheckout($order);
		$this->setOrderCompletedDate($order, $options['complete_date']);
        if ($data['State']) {
            $this->fulfillOrder($order);
        }
 */
		
		
		
		
		
		
		
		/* 
		$this->address($order,$customer,$data);
		
		$this->orderRepository->add($order);
        echo '<pre>';
		print_r($customer);
		exit; 
		exit;*/
		
    }
	
	
	
	protected function selectShipping(OrderInterface $order, \DateTimeInterface $createdAt,array $data): void
    {
		
		
		$order->setCheckoutState(OrderCheckoutStates::STATE_SHIPPING_SELECTED);
        if ($order->getCheckoutState() === OrderCheckoutStates::STATE_SHIPPING_SKIPPED) {
            return;
        }

        $channel = $order->getChannel();
       /*  $shippingMethods = $this->shippingMethodRepository->findEnabledForChannel($channel);

        if (count($shippingMethods) === 0) {
            throw new \InvalidArgumentException(sprintf(
                'You have no shipping method available for the channel with code "%s", but they are required to proceed an order',
                $channel->getCode(),
            ));
        } */

        //$shippingMethod = $this->faker->randomElement($shippingMethods);
		
		/* $paymentMethod = $this->paymentMethodRepository->findOneBy([
            'code' => 'wire-transfer',
        ]);*/ 
		$shippingMethod = $this->shippingMethodRepository->findOneBy([
            'code' => $data['shipping_method_code'],
        ]);
		
		
        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();
        Assert::notNull($shippingMethod, $this->generateInvalidSkipMessage('shipping', $channel->getCode()));

        foreach ($order->getShipments() as $shipment) {
            $shipment->setMethod($shippingMethod);
            $shipment->setCreatedAt($createdAt);
        }

        $this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        
    }

    protected function selectPayment(OrderInterface $order, \DateTimeInterface $createdAt,array $data): void
    {
		
		$order->setCheckoutState(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        if ($order->getCheckoutState() === OrderCheckoutStates::STATE_PAYMENT_SKIPPED) {
            return;
        }

       /*  $paymentMethod = $this
            ->faker
            ->randomElement($this->paymentMethodRepository->findEnabledForChannel($order->getChannel()))
        ;
		 */
		
		$paymentMethod = $this->paymentMethodRepository->findOneBy([
            'code' =>  $data['payment_method_code'],
        ]); 
		/* $shippingMethod = $this->shippingMethodRepository->findOneBy([
            'code' => $data['shipping_method_code'],
        ]);*/
		
		
        /** @var ChannelInterface $channel */
        $channel = $order->getChannel();
        Assert::notNull($paymentMethod, $this->generateInvalidSkipMessage('payment', $channel->getCode()));

        foreach ($order->getPayments() as $payment) {
            $payment->setMethod($paymentMethod);
            $payment->setCreatedAt($createdAt);
        }
		
		$this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
       
       
    }
	protected function generateInvalidSkipMessage(string $type, string $channelCode): string
    {
        return sprintf(
            "No enabled %s method was found for the channel '%s'. " .
            "Set 'skipping_%s_step_allowed' option to true for this channel if you want to skip %s method selection.",
            $type,
            $channelCode,
            $type,
            $type,
        );
    }
	
	protected function setDatetime(?\DateTimeInterface $datetime): void
    {
        $this->datetime = $datetime;
    }
	
	
	protected function setOrderCompletedDate(OrderInterface $order, \DateTimeInterface $date): void
    {
        if ($order->getCheckoutState() === OrderCheckoutStates::STATE_COMPLETED) {
            $order->setCheckoutCompletedAt($date);
        }
    }


	
	
	protected function completeCheckout(OrderInterface $order,array $data): void
    {
		
		
        if ($data['notes']) {
            $order->setNotes($data['notes']);
        }
		
        $this->applyCheckoutStateTransition($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
		$order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
    }

    protected function applyCheckoutStateTransition(OrderInterface $order, string $transition): void
    {
        $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH)->apply($transition);
    }
	
	/* private function proceedSelectingShippingAndPaymentMethod(OrderInterface $order, ShippingMethodInterface $shippingMethod, PaymentMethodInterface $paymentMethod)
    {
        foreach ($order->getShipments() as $shipment) {
            $shipment->setMethod($shippingMethod);
        }
        $this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        $this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        
		foreach ($order->getPayments() as $payment) {
            $payment->setMethod($paymentMethod);
        }
		

        $this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->applyTransitionOnOrderCheckout($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        
    } */
	
	private function applyTransitionOnOrderCheckout(OrderInterface $order, $transition)
    {
        $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH)->apply($transition);
    }
	
	private function generateItems(OrderInterface $order,array $data):void{
		$generatedItems = [];
		
		$product_list = $data['product_list'] ? $data['product_list'] : '';
		
		$product_list_array  = explode(',',$product_list);
		
		foreach($product_list_array as $k=>$v){
			
			if(!empty($v)){
				$product =  $this->productRepository->findOneByCode($v);
				
				if($product){
					//$product = $this->faker->randomElement($products);
					$variant = $this->faker->randomElement($product->getVariants()->toArray());
					/** @var OrderItemInterface $item */
					$item = $this->orderItemFactory->createNew();

					$item->setVariant($variant);
					$this->orderItemQuantityModifier->modify($item, 1);

					$generatedItems[$v] = $item;
					$order->addItem($item);
				}
			}
		}
	}
	
	private function createOrProvideCustomer(array $data): CustomerInterface
    {
        /** @var CustomerInterface|null $customer */
        $customer = $this->customerRepository->findOneBy(['email' => $data['email']]);
		
        return $customer ?? $this->createCustomer($data);
       
    }
	
	private function address(OrderInterface $order,CustomerInterface $customer, array $data):void{
		$address = $this->addressFactory->createNew();
        $address->setCountryCode($data['shipping_country']);
        $address->setCity($data['shipping_city']);
        //$address->setState($data['ShippingState']);
        $address->setFirstName($customer->getFirstName());
        $address->setLastName($customer->getLastName());
        $address->setStreet($data['shipping_address_line1']);
        $address->setPostcode($data['shipping_zip']);
		
		
		$baddress = $this->addressFactory->createNew();
        $baddress->setCountryCode($data['billing_country']);
        $baddress->setCity($data['billing_city']);
        //$address->setState($data['ShippingState']);
        $baddress->setFirstName($customer->getFirstName());
        $baddress->setLastName($customer->getLastName());
        $baddress->setStreet($data['billing_address_line1']);
        $baddress->setPostcode($data['billing_zip']);
		
		
		
		$order->setShippingAddress($address);
		$order->setBillingAddress($baddress);
	}
	private function createCustomer(array $data): CustomerInterface
    {
        /** @var CustomerInterface $customer */
		
		$user = $this->userFactory->createNew();



        $customer = $this->customerFactory->createNew();
        $customer->setEmail($data['email']);
        $customer->setFirstName($data['first_name']);
        $customer->setLastName($data['last_name']);
        $customer->setPhoneNumber($data['telephone']);
		
		
		$address = $this->addressFactory->createNew();
        $address->setCountryCode($data['shipping_country']);
        $address->setCity($data['shipping_city']);
        //$address->setState($data['ShippingState']);
        $address->setFirstName($customer->getFirstName());
        $address->setLastName($customer->getLastName());
        $address->setStreet($data['shipping_address_line1']);
        $address->setPostcode($data['shipping_zip']);
        $customer->setDefaultAddress($address);
		
		
		
		$user->setUsername($data['email']);
        $user->setPlainPassword($data['password']);
        $user->setEnabled(true);
		$user->setVerifiedAt(new \DateTime());
        

        $customer->setUser($user);
       // $customer->setLastName('Doe');
		$this->customerRepository->add($customer);
        return $customer;
    }

	
	/**************** Full fill order **********/
	
	
	protected function fulfillOrder(OrderInterface $order): void
    {
        $this->completePayments($order);
        $this->completeShipments($order);
    }

    protected function completePayments(OrderInterface $order): void
    {
        foreach ($order->getPayments() as $payment) {
            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
            }
        }
    }

    protected function completeShipments(OrderInterface $order): void
    {
        foreach ($order->getShipments() as $shipment) {
            $stateMachine = $this->stateMachineFactory->get($shipment, ShipmentTransitions::GRAPH);
            if ($stateMachine->can(ShipmentTransitions::TRANSITION_SHIP)) {
                $stateMachine->apply(ShipmentTransitions::TRANSITION_SHIP);
            }
        }
    }
	/*******************************/
	

}
