<?php

namespace Luma\Campaignmonitor\Controller\Adminhtml\Api;

use Luma\Campaignmonitor\Helper\Data;
use Luma\Campaignmonitor\Model\Api;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class CreateWebhook extends Action {

    /**
     * @var Api
     */
    private $api;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Data
     */
    private $helper;

    public function __construct(
        Action\Context $context,
        Api $api,
        StoreManagerInterface $storeManager,
        JsonFactory $jsonFactory,
        Data $helper
    )
    {
        $this->api = $api;
        $this->storeManager = $storeManager;
        $this->jsonFactory = $jsonFactory;
        $this->helper = $helper;
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $listIds = [];

        foreach ($this->storeManager->getStores() as $store){
            $listId = $this->helper->getListId($store->getId());
            if (!isset($listIds[$listId]))
                $listIds[$listId] = $store->getId();
        }

        $webhooksCreated = 0;

        foreach ($listIds as $listId => $storeId){

            $result = $this->api->getWebhooks($storeId);

            if ($result["success"] == false){
                $jsonData = ['status' => 'error', 'message' => sprintf("Failed to fetch current webhooks: %s", $result['data']['Message'])];
                return $this->jsonFactory->create()->setData($jsonData);
            }

            $webhooks = $result["data"];
            $webhookExists = false;

            if (sizeof($webhooks) > 0){

                $webhookUrl = $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB) . "campaignmonitor/webhook/subscribe";

                foreach ($webhooks as $webhook){

                    //$this->api->deleteWebhook($storeId, $webhook["WebhookID"]);

                    if ($webhook["Url"] == $webhookUrl) {
                        $webhookExists = true;
                        break;
                    }
                }
            }

            if (!$webhookExists) {

                $result = $this->api->createWebhook($storeId);

                if ($result['success'] === false) {
                    $jsonData = ['status' => 'error', 'message' => sprintf("Webhook creation failed: %s", $result['data']['Message'])];
                    return $this->jsonFactory->create()->setData($jsonData);
                }

                $webhooksCreated++;
            }
        }

        if ($webhooksCreated > 0) {
            $jsonData = ['status' => 'success', 'message' => $webhooksCreated . " webhooks successfully created!"];
        } else {
            $jsonData = ['status' => 'success', 'message' => "Webhooks already created."];
        }

        return $this->jsonFactory->create()->setData($jsonData);
    }
}
