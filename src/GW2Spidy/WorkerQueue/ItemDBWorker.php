<?php

namespace GW2Spidy\WorkerQueue;

use \Criteria;

use GW2Spidy\DB\BuyListing;

use GW2Spidy\DB\SellListing;

use GW2Spidy\Util\Functions;

use GW2Spidy\Queue\WorkerQueueManager;
use GW2Spidy\Queue\WorkerQueueItem;

use GW2Spidy\DB\Item;
use GW2Spidy\DB\ItemQuery;
use GW2Spidy\TradingPostSpider;

use GW2Spidy\DB\ItemType;
use GW2Spidy\DB\ItemSubType;

class ItemDBWorker implements Worker {
    const ERROR_CODE_NO_LONGER_EXISTS = 444441;

    public function getRetries() {
        return 1;
    }

    public function work(WorkerQueueItem $item) {
        $data = $item->getData();

        $res = $this->buildItemDB($data['type'], $data['subtype'], $data['offset']);

        // we stop enqueueing the next slice when we stop getting results
        if ($data['full'] && $res) {
            $this->enqeueNextOffset($data['type'], $data['subtype'], $data['offset']);
        }
    }

    protected function buildItemDB($type, $subtype, $offset) {
        var_dump((string)$type, (string)$subtype, $offset) . "\n\n";

        $q = ItemQuery::create()
                ->filterByItemType($type)
                ->orderByRarity(Criteria::DESC)
                ->limit(10)
                ->offset($offset)
                ;

        $items = $q->find();
        $ok = false;

        foreach ($items as $item) {
            if ($itemData = TradingPostSpider::getInstance()->getItemById($item->getDataId())) {
                var_dump($item->getName(), (boolean)$itemData);

                $this->storeItemData($itemData, $type, null, $item);
                $ok = true;
            }
        }

        return $ok;
    }

    public function storeItemData($itemData, ItemType $type = null, ItemSubType $subtype = null, $item = null) {
        $now  = new \DateTime();
        $item = $item ?: ItemQuery::create()->findPK($itemData['data_id']);

        var_dump($itemData['name'], (string)$item, $itemData['min_sale_unit_price']) . "\n\n";
        if ($item) {
            if (($p = Functions::almostEqualCompare($itemData['name'], $item->getName())) > 50 || $item->getName() == "...") {
                $item->fromArray($itemData, \BasePeer::TYPE_FIELDNAME);
                $item->save();
            } else {
                throw new \Exception("Title for ID no longer matches! item [{$p}] [json::{$itemData['data_id']}::{$itemData['name']}] vs [db::{$item->getDataId()}::{$item->getName()}]", self::ERROR_CODE_NO_LONGER_EXISTS);
            }
        } else {
            $item = new Item();
            $item->fromArray($itemData, \BasePeer::TYPE_FIELDNAME);
            $item->setItemType($type);
            $item->setItemSubType($subtype);

            $item->save();
        }

        if ($itemData['min_sale_unit_price'] > 0) {
            $sellListing = new SellListing();
            $sellListing->setItem($item);
            $sellListing->setListingDate($now);
            $sellListing->setListingTime($now);
            $sellListing->setQuantity($itemData['sale_availability'] ?: 1);
            $sellListing->setUnitPrice($itemData['min_sale_unit_price']);
            $sellListing->setListings(1);

            $sellListing->save();
        }

        if ($itemData['max_offer_unit_price'] > 0) {
            $buyListing = new BuyListing();
            $buyListing->setItem($item);
            $buyListing->setListingDate($now);
            $buyListing->setListingTime($now);
            $buyListing->setQuantity($itemData['offer_availability'] ?: 1);
            $buyListing->setUnitPrice($itemData['max_offer_unit_price']);
            $buyListing->setListings(1);

            $buyListing->save();
        }
    }


    protected function enqeueNextOffset($type, $subtype, $offset) {
        return self::enqueueWorker($type, $subtype, $offset + 10, true);
    }

    public static function enqueueWorker($type, $subtype, $offset = 0, $full = true) {
        $queueItem = new WorkerQueueItem();
        $queueItem->setWorker("\\GW2Spidy\\WorkerQueue\\ItemDBWorker");
        // $queueItem->setPriority(WorkerQueueItem::PRIORITY_ITEMDB);
        $queueItem->setData(array(
            'type'    => $type,
            'subtype' => $subtype,
            'offset'  => $offset,
            'full'    => $full,
        ));

        WorkerQueueManager::getInstance()->enqueue($queueItem);

        return $queueItem;
    }
}

?>