<?php

namespace MailCampaigns\Connector\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use MailCampaigns\Connector\Helper\Data as DataHelper;
use MailCampaigns\Connector\Helper\MailCampaigns_API;
use Psr\Log\LoggerInterface as Logger;

class SynchronizeProduct implements ObserverInterface
{
    protected $logger;
    protected $helper;
    protected $storemanager;
    protected $objectmanager;
    protected $productrepository;
    protected $taxhelper;
    protected $mcapi;

    public function __construct(
        DataHelper $dataHelper,
        MailCampaigns_API $mcapi,
        StoreManagerInterface $storeManager,
        ObjectManagerInterface $objectManager,
        ProductRepositoryInterface $productRepository,
        Data $taxHelper,
        Logger $logger
    ) {
        $this->logger               = $logger;
        $this->helper               = $dataHelper;
        $this->mcapi                = $mcapi;
        $this->storemanager         = $storeManager;
        $this->productrepository    = $productRepository;
        $this->taxhelper            = $taxHelper;
        $this->objectmanager        = $objectManager;
    }

    public function execute(EventObserver $observer)
    {
        // set vars
        $this->mcapi->APIWebsiteID      = $observer->getWebsite();
        $this->mcapi->APIStoreID        = $observer->getStore();
        $this->mcapi->APIKey            = $this->helper->getConfig('mailcampaignsapi/general/api_key', $this->mcapi->APIStoreID);
        $this->mcapi->APIToken          = $this->helper->getConfig('mailcampaignsapi/general/api_token', $this->mcapi->APIStoreID);
        $this->mcapi->ImportProducts    = $this->helper->getConfig('mailcampaignsrealtimesync/general/import_products',$this->mcapi->APIStoreID);

        if ($this->mcapi->ImportProducts == 1)
        {
            try
            {
                // Retrieve the product being updated from the event observer
                $i = 0;
                $product_data = array();

                $product = $observer->getEvent()->getProduct();

                $attributes = $product->getAttributes();
                foreach ($attributes as $attribute)
                {
                    $data = $product->getData($attribute->getAttributeCode());
                    if (!is_array($data)) $product_data[$i][$attribute->getAttributeCode()] = $data;
                }

                // Get Price Incl Tax
                $product_data[$i]["price"] = $this->taxhelper->getTaxPrice($product, $product_data[$i]["price"], true, NULL, NULL, NULL, $this->mcapi->APIStoreID, NULL, true);

                // Get Special Price Incl Tax
                $product_data[$i]["special_price"] = $this->taxhelper->getTaxPrice($product, $product_data[$i]["special_price"], true, NULL, NULL, NULL, $this->mcapi->APIStoreID, NULL, true);

                // get lowest tier price / staffel
                $lowestTierPrice = $product->getResource()->getAttribute('tier_price')->getValue($product);
                $product_data[$i]["lowest_tier_price"] = $lowestTierPrice;

                // images
                if(!empty($product->getData('image')))
                {
                    $image_id                              = 1;
                    $product_data[$i]["mc:image_url_main"] = $product->getMediaConfig()->getMediaUrl($product->getData('image'));
                    $product_images                        = $product->getMediaGalleryImages();
                    if(!empty($product_images) && sizeof($product_images) > 0 && is_array($product_images))
                    {
                        foreach($product_images as $image)
                        {
                            $product_data[$i]["mc:image_url_" . $image_id++ . ""] = $image->getUrl();
                        }
                    }
                }

                // link
                $product_data[$i]["mc:product_url"] = $product->getProductUrl();

                // store id
                $product_data[$i]["store_id"] = $product->getStoreID();

                // product parent id
                if($product->getId() != "")
                {
                    $objectMan =  \Magento\Framework\App\ObjectManager::getInstance();
                    $parent_product = $objectMan->create('Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable')->getParentIdsByChild($product->getId());
                    if(isset($parent_product[0]))
                    {
                        $product_data[$i]["parent_id"] = $parent_product[0];
                    }
                }

                // get related products
                $related_products = array();
                $related_product_collection = $product->getRelatedProductIds();
                $related_products[$product->getId()]["store_id"] = $product_data[$i]["store_id"];
                if (!empty($related_product_collection) && sizeof($related_product_collection) > 0 && is_array($related_product_collection))
                {
                    foreach($related_product_collection as $pdtid)
                    {
                        $related_products[$product->getId()]["products"][] = $pdtid;
                    }
                }

                // Categories
                $category_data = array();
                $categories = array();
                $objectMan =  \Magento\Framework\App\ObjectManager::getInstance();
                foreach ($product->getCategoryIds() as $category_id)
                {
                    $categories[] = $category_id;
                    $cat = $objectMan->create('Magento\Catalog\Model\Category')->load($category_id);
                    $category_data[$category_id] = $cat->getName();
                }
                $product_data[$i]["categories"] = json_encode(array_unique($categories));

                // Post data
                if (sizeof($category_data) > 0)
                    $this->mcapi->QueueAPICall("update_magento_categories", $category_data);

                if (sizeof($product_data) > 0)
                    $this->mcapi->QueueAPICall("update_magento_products", $product_data);

                if (sizeof($related_product_collection) > 0)
                    $this->mcapi->QueueAPICall("update_magento_related_products", $related_products);
            }
            catch (\Magento\Framework\Exception\NoSuchEntityException $e)
            {
                $this->mcapi->DebugCall($e->getMessage());
            }
            catch (Exception $e)
            {
                $this->mcapi->DebugCall($e->getMessage());
            }
        }
    }
}
