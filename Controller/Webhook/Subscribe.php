<?php

namespace Luma\Campaignmonitor\Controller\Webhook;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Zhik\GeoIPRedirect\Helper\Data;

class Subscribe extends Action implements CsrfAwareActionInterface {

    /**
     * @var SubscriberFactory
     */
    private $subscriberFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var Data
     */
    private $geoIpHelper;

    /**
     * @var \Luma\Campaignmonitor\Helper\Data
     */
    private $cmHelper;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        SubscriberFactory $subscriberFactory,
        CustomerRepositoryInterface $customerRepository,
        Data $geoIpHelper,
        \Luma\Campaignmonitor\Helper\Data $cmHelper
    )
    {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->subscriberFactory = $subscriberFactory;
        $this->customerRepository = $customerRepository;
        $this->geoIpHelper = $geoIpHelper;
        $this->cmHelper = $cmHelper;

        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $this->cmHelper->log("webhook received!");

        if (empty($this->getRequest()->getContent())){
            $this->cmHelper->log("Webhook error: data empty");
            return;
        }

        $data = json_decode($this->getRequest()->getContent(), true);

        if ($data !== false){

            if (!isset($data["ListID"]) || !isset($data["Events"])){
                $this->cmHelper->log("Webhook error: wrong schema!");
                return;
            }

            // Authorise webhook by matching CM list ID
            $verified = false;

            foreach ($this->storeManager->getStores() as $store){
                $listId = $this->scopeConfig->getValue("createsend_general/api/list_id", "store", $store->getCode());

                if ($listId == $data["ListID"]){
                    $verified = true;
                    break;
                }
            }

            if (!$verified){
                $this->cmHelper->log("Webhook error: authorisation failed!");
                return;
            }

            /* @var Subscriber $subscriber */
            $subscriber = $this->subscriberFactory->create();

            foreach ($data["Events"] as $event){

                switch ($event["Type"]){

                    case "Subscribe":

                        try {
                            $customer = $this->customerRepository->get($event["EmailAddress"]);
                        } catch (\Exception $e){
                            $customer = null;
                        }

                        $subscriber->load($event["EmailAddress"], "subscriber_email");
                        $curStatus = $subscriber->getStatus();
                        $subscriber->setEmail($event["EmailAddress"])
                                    ->setStatus(Subscriber::STATUS_SUBSCRIBED)
                                    ->setCmListid($data["ListID"])
                                    ->setIsWebhookUpdate(true);

                        if ($curStatus !== Subscriber::STATUS_SUBSCRIBED)
                            $subscriber->setStatusChanged(true);

                        if ($customer){
                            $subscriber->setCustomerId($customer->getId());
                            $subscriber->setStoreId($customer->getStoreId());
                        } else {
                            $storeId = $this->getStoreIdByIpAddress((isset($event["SignupIPAddress"]) ? $event["SignupIPAddress"] : ""));
                            $this->storeManager->setCurrentStore($storeId);

                            $subscriber->setStoreId($storeId);
                        }

                        try {
                            $subscriber->save();
                            $this->cmHelper->log("Webhook: Successfully added subscriber: ".$event["EmailAddress"]);
                        } catch (\Exception $e){
                            $this->cmHelper->log("Webhook: Failed to save subscriber: ".$e->getMessage());
                        }

                        break;

                    case "Update":

                        $subscriber->load($event["OldEmailAddress"], "subscriber_email");

                        if (!$subscriber->getId()) {
                            $this->cmHelper->log("Webhook: Subscriber does not exist in Magento for update");
                            break;
                        }

                        $subscriber->setEmail($event["EmailAddress"]);

                        $status = Subscriber::STATUS_NOT_ACTIVE;

                        if ($event["State"] == "Active"){
                            $status = Subscriber::STATUS_SUBSCRIBED;
                        } else if ($event["State"] == "Unsubscribed"){
                            $status = Subscriber::STATUS_UNSUBSCRIBED;
                        }

                        $subscriber->setStatus($status)
                            ->setStatusChanged(true)
                            ->setIsWebhookUpdate(true);

                        try {
                            $subscriber->save();
                            $this->cmHelper->log("Webhook: Successfully updated subscriber: ".$event["OldEmailAddress"]." -> ".$event["EmailAddress"]);
                        } catch (\Exception $e){
                            $this->cmHelper->log("Webhook: Failed to save subscriber: ".$e->getMessage());
                        }

                        break;

                    case "Deactivate":

                        $subscriber->load($event["EmailAddress"], "subscriber_email");

                        if (!$subscriber->getId()) {
                            $this->cmHelper->log("Webhook: Subscriber does not exist in Magento for unsubscribe");
                            break;
                        }

                        try {
                            $subscriber->setIsWebhookUpdate(true);
                            $subscriber->unsubscribe();
                            $this->cmHelper->log("Webhook: Successfully unsubscribed subscriber: ".$event["EmailAddress"]);
                        } catch (\Exception $e){
                            $this->cmHelper->log("Webhook: Failed to save subscriber: ".$e->getMessage());
                        }

                        break;
                }
            }
        } else {
            $this->cmHelper->log("Webhook: JSON decode failed - ".json_last_error_msg());
        }
    }

    private function getStoreIdByIpAddress($ipAddress){

        $storeCode = $this->geoIpHelper->getStoreCodeByIpAddress($ipAddress);

        return $this->storeManager->getStore($storeCode)->getId();
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
