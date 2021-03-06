<?php

/**
 * using Silex micro framework
 *  this file contains all routing and the 'controllers' using lambda functions
 */

use GW2Spidy\GW2SessionManager;

use \DateTime;

use GW2Spidy\DB\GW2Session;
use GW2Spidy\DB\GoldToGemRateQuery;
use GW2Spidy\DB\GemToGoldRateQuery;
use GW2Spidy\DB\ItemQuery;
use GW2Spidy\DB\ItemTypeQuery;
use GW2Spidy\DB\SellListingQuery;
use GW2Spidy\DB\WorkerQueueItemQuery;
use GW2Spidy\DB\ItemPeer;
use GW2Spidy\DB\BuyListingPeer;
use GW2Spidy\DB\SellListingPeer;
use GW2Spidy\DB\BuyListingQuery;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;

use GW2Spidy\Application;
use GW2Spidy\ItemHistory;

use GW2Spidy\Twig\VersionedAssetsRoutingExtension;
use GW2Spidy\Twig\ItemListRoutingExtension;
use GW2Spidy\Twig\GW2MoneyExtension;

use GW2Spidy\Queue\RequestSlotManager;
use GW2Spidy\Queue\WorkerQueueManager;

require dirname(__FILE__) . '/../config/config.inc.php';
require dirname(__FILE__) . '/../autoload.php';

@session_start();

// initiate the application, check config to enable debug / sql logging when needed
$app = Application::getInstance();
$app->isSQLLogMode() && $app->enableSQLLogging();
$app->isDevMode()    && $app['debug'] = true;

/*
 * register providers
 */
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path'    => dirname(__FILE__) . '/../templates',
    'twig.options' => array(
        'cache' => dirname(__FILE__) . '/../tmp/twig-cache',
    ),
));

/*
 * register custom extensions
 */
$app['twig']->addExtension(new VersionedAssetsRoutingExtension());
$app['twig']->addExtension(new GW2MoneyExtension());
$app['twig']->addExtension(new ItemListRoutingExtension($app['url_generator']));

/**
 * lambda used to convert URL arguments
 *
 * @param  mixed     $val
 * @return int
 */
$toInt = function($val) {
    return (int) $val;
};


/**
 * generic function used for /search and /type
 *
 * @param  Application   $app
 * @param  Request       $request
 * @param  ItemQuery     $q
 * @param  int           $page
 * @param  int           $itemsperpage
 * @param  array         $tplVars
 */
function item_list(Application $app, Request $request, ItemQuery $q, $page, $itemsperpage, array $tplVars = array()) {
    $sortByOptions = array('name', 'rarity', 'restriction_level', 'min_sale_unit_price', 'max_offer_unit_price');

    foreach ($sortByOptions as $sortByOption) {
        if ($request->get("sort_{$sortByOption}", null)) {
            $sortOrder = $request->get("sort_{$sortByOption}", 'asc');
            $sortBy    = $sortByOption;
        }
    }

    $sortBy    = isset($sortBy)    && in_array($sortBy, $sortByOptions)          ? $sortBy    : 'name';
    $sortOrder = isset($sortOrder) && in_array($sortOrder, array('asc', 'desc')) ? $sortOrder : 'asc';

    $count = $q->count();

    if ($count > 0) {
        $lastpage = ceil($count / $itemsperpage);
        if ($page > $lastpage) {
            $page = $lastpage;
        }
    } else {
        $page     = 1;
        $lastpage = 1;
    }

    $q->offset($itemsperpage * ($page-1))
    ->limit($itemsperpage);

    if ($sortOrder == 'asc') {
        $q->addAscendingOrderByColumn($sortBy);
    } else if ($sortOrder == 'desc') {
        $q->addDescendingOrderByColumn($sortBy);
    }

    $items = $q->find();

    return $app['twig']->render('item_list.html.twig', $tplVars + array(
            'page'     => $page,
            'lastpage' => $lastpage,
            'items'    => $items,

            'current_sort'       => $sortBy,
            'current_sort_order' => $sortOrder,
    ));
};

/**
 * ----------------------
 *  route /
 * ----------------------
 */
$app->get("/", function() use($app) {
    // workaround for now to set active menu item
    $app->setHomeActive();

    // get copper ore as featured item
    $featured = ItemQuery::create()->findPk(19697);

    return $app['twig']->render('index.html.twig', array(
        'featured' => $featured,
    ));
})
->bind('homepage');

/**
 * ----------------------
 *  route /gem
 * ----------------------
 */
$app->get("/gem", function() use($app) {
    // workaround for now to set active menu item
    $app->setGemActive();


    $lastSell = GemToGoldRateQuery::create()
                ->addDescendingOrderByColumn("rate_datetime")
                ->offset(-1)
                ->limit(1)
                ->findOne();

    $lastBuy = GoldToGemRateQuery::create()
                ->addDescendingOrderByColumn("rate_datetime")
                ->offset(-1)
                ->limit(1)
                ->findOne();

    if (!$lastSell || !$lastBuy) {
        return $app['twig']->render('gem.html.twig', array());
    }

    $gemtogold = $lastSell->getRate();
    $goldtogem = $lastBuy->getRate();

    $usdtogem    = 10  / 800 * 100;
    $poundstogem = 8.5 / 800 * 100;
    $eurostogem  = 10  / 800 * 100;

    $usdtogold   = (10000 / $gemtogold) * $usdtogem;

    return $app['twig']->render('gem.html.twig', array(
        'gemtogold' => $gemtogold,
        'goldtogem' => $goldtogem,
        'usdtogem'  => $usdtogem,
        'usdtogold' => $usdtogold,
    ));
})
->bind('gem');

/**
 * ----------------------
 *  route /gem
 * ----------------------
 */
$app->get("/gem_chart", function() use($app) {
    $chart = array();

    /*---------------------
     *  BUY GEMS WITH GOLD
    *----------------------*/
    $chart[] = array(
        'data'   => GoldToGemRateQuery::getChartDatasetData(),
        'name'   => "Gold to Gems",
    );

    /*---------------------
     *  SELL GEMS FOR GOLD
    *----------------------*/
    $chart[] = array(
        'data'   => GemToGoldRateQuery::getChartDatasetData(),
        'name'   => "Gems to Gold",
    );

    $content = json_encode($chart);

    return $content;
})
->bind('gem_chart');

/**
 * ----------------------
 *  route /types
 * ----------------------
 */
$app->get("/types", function() use($app) {
    $types = ItemTypeQuery::getAllTypes();

    return $app['twig']->render('types.html.twig', array(
        'types' => $types,
    ));
})
->bind('types');

/**
 * ----------------------
 *  route /type
 * ----------------------
 */
$app->get("/type/{type}/{subtype}/{page}", function(Request $request, $type, $subtype, $page) use($app) {
    $page = $page > 0 ? $page : 1;

    $q = ItemQuery::create();

    if (!is_null($type)) {
        $q->filterByItemTypeId($type);
    }
    if (!is_null($subtype)) {
        $q->filterByItemSubTypeId($subtype);
    }

    // use generic function to render
    return item_list($app, $request, $q, $page, 50, array('type' => $type, 'subtype' => $subtype));
})
->assert('type',     '\d+')
->assert('subtype',  '\d+')
->assert('page',     '-?\d+')
->convert('type',    $toInt)
->convert('subtype', $toInt)
->convert('page',    $toInt)
->value('type',      null)
->value('subtype',   null)
->value('page',      1)
->bind('type');

/**
 * ----------------------
 *  route /item
 * ----------------------
 */
$app->get("/item/{dataId}", function($dataId) use ($app) {
    $item = ItemQuery::create()->findPK($dataId);

    if (!$item) {
        return $app->abort(404, "Page does not exist.");
    }

    // add item to the history stored in session
    ItemHistory::getInstance()->addItem($item);

    return $app['twig']->render('item.html.twig', array(
        'item'        => $item,
    ));
})
->assert('dataId',  '\d+')
->convert('dataId', $toInt)
->bind('item');

/**
 * ----------------------
 *  route /chart
 * ----------------------
 */
$app->get("/chart/{dataId}", function($dataId) use ($app) {
    $item = ItemQuery::create()->findPK($dataId);

    if (!$item) {
        return $app->abort(404, "Page does not exist.");
    }

    $chart = array();

    /*----------------
     *  SELL LISTINGS
     *----------------*/
    $chart[] = array(
        'data'   => SellListingQuery::getChartDatasetDataForItem($item),
        'name'  => "Sell Listings",
    );

    /*---------------
     *  BUY LISTINGS
     *---------------*/
    $chart[] = array(
        'data'   => BuyListingQuery::getChartDatasetDataForItem($item),
        'name'   => "Buy Listings",
    );

    $content = json_encode($chart);

    return $content;
})
->assert('dataId',  '\d+')
->convert('dataId', $toInt)
->bind('chart');

/**
 * ----------------------
 *  route /status
 * ----------------------
 */
$app->get("/status", function() use($app) {
    ob_start();

    echo "there are [[ " . RequestSlotManager::getInstance()->getLength() . " ]] available slots right now \n";
    echo "there are still [[ " . WorkerQueueManager::getInstance()->getLength() . " ]] items in the queue \n";

    $content = ob_get_clean();

    return $app['twig']->render('status.html.twig', array(
        'dump' => $content,
    ));
})
->bind('status');

/**
 * ----------------------
 *  route /search POST
 * ----------------------
 */
$app->post("/search", function (Request $request) use ($app) {
    // redirect to the GET with the search in the URL
    return $app->redirect($app['url_generator']->generate('search', array('search' => $request->get('search'))));
})
->bind('searchpost');

/**
 * ----------------------
 *  route /search GET
 * ----------------------
 */
$app->get("/search/{search}/{page}", function(Request $request, $search, $page) use($app) {
    if (!$search) {
        return $app->handle(Request::create("/searchform", 'GET'), HttpKernelInterface::SUB_REQUEST);
    }

    $page = $page > 0 ? $page : 1;

    $q = ItemQuery::create();
    $q->filterByName("%{$search}%");

    // use generic function to render
    return item_list($app, $request, $q, $page, 25, array('search' => $search));
})
->assert('search',   '[^/]*')
->assert('page',     '-?\d+')
->convert('page',    $toInt)
->convert('search',  function($search) { return urldecode($search); })
->value('search',    null)
->value('page',      1)
->bind('search');

/**
 * ----------------------
 *  route /searchform
 * ----------------------
 */
$app->get("/searchform", function() use($app) {
    return $app['twig']->render('search.html.twig', array());
})
->bind('searchform');

/**
 * ----------------------
 *  route /api
 * ----------------------
 */
$app->get("/api/{format}/{secret}", function($format, $secret) use($app) {
    // check if the secret is in the configured allowed api_secrets
    if (!(isset($GLOBALS['api_secrets']) && in_array($secret, $GLOBALS['api_secrets'])) && !$app['debug']) {
        return $app->redirect("/");
    }

    Propel::disableInstancePooling();
    $items = ItemQuery::create()->find();

    if ($format == 'csv') {
        header('Content-type: text/csv');
        header('Content-disposition: attachment; filename=item_data_' . date('Ymd-His') . '.csv');

        ob_start();

        echo implode(",", ItemPeer::getFieldNames(BasePeer::TYPE_FIELDNAME)) . "\n";

        foreach ($items as $item) {
            echo implode(",", $item->toArray(BasePeer::TYPE_FIELDNAME)) . "\n";
        }

        return ob_get_clean();
    } else if ($format == 'json') {
        header('Content-type: application/json');
        header('Content-disposition: attachment; filename=item_data_' . date('Ymd-His') . '.json');

        $json = array();

        foreach ($items as $item) {
            $json[$item->getDataId()] = $item->toArray(BasePeer::TYPE_FIELDNAME);
        }

        return json_encode($json);
    }
})
->assert('format', 'csv|json')
->bind('api');

/**
 * ----------------------
 *  route /api/item
 * ----------------------
 */
$app->get("/api/listings/{dataId}/{type}/{format}/{secret}", function($dataId, $type, $format, $secret) use($app) {
    // check if the secret is in the configured allowed api_secrets
    if (!(isset($GLOBALS['api_secrets']) && in_array($secret, $GLOBALS['api_secrets'])) && !$app['debug']) {
        return $app->redirect("/");
    }

    $item = ItemQuery::create()->findPK($dataId);

    if (!$item) {
        return $app->abort(404, "Page does not exist.");
    }

    $fields   = array();
    $listings = array();
    if ($type == 'sell') {
        $fields   = SellListingPeer::getFieldNames(BasePeer::TYPE_FIELDNAME);
        $listings = SellListingQuery::create()->findByItemId($item->getDataId());
    } else {
        $fields   = BuyListingPeer::getFieldNames(BasePeer::TYPE_FIELDNAME);
        $listings = BuyListingQuery::create()->findByItemId($item->getDataId());
    }

    if ($format == 'csv') {
        header('Content-type: text/csv');
        header('Content-disposition: attachment; filename=listings_data_' . $item->getDataId() . '_' . $type . '_' . date('Ymd-His') . '.csv');

        ob_start();

        echo implode(",", $fields) . "\n";

        foreach ($listings as $listing) {
        	$data = $listing->toArray(BasePeer::TYPE_FIELDNAME);

            $date = new DateTime("{$listing->getListingDate()} {$listing->getListingTime()}");
            $date->setTimezone(new DateTimeZone('UTC'));

            $data[$listing->getId()]['listing_date'] = $date->format("Y-m-d");
            $data[$listing->getId()]['listing_time'] = $date->format("H:i:s");

            echo implode(",", $data) . "\n";
        }

        return ob_get_clean();
    } else if ($format == 'json') {
        header('Content-type: application/json');
        header('Content-disposition: attachment; filename=listings_data_' . $item->getDataId() . '_' . $type . '_' . date('Ymd-His') . '.json');

        $json = array();

        foreach ($listings as $listing) {
            $json[$listing->getId()] = $listing->toArray(BasePeer::TYPE_FIELDNAME);

            $date = new DateTime("{$listing->getListingDate()} {$listing->getListingTime()}");
            $date->setTimezone(new DateTimeZone('UTC'));

            $json[$listing->getId()]['listing_date'] = $date->format("Y-m-d");
            $json[$listing->getId()]['listing_time'] = $date->format("H:i:s");
        }

        return json_encode($json);
    }
})
->assert('dataId',  '\d+')
->assert('format', 'csv|json')
->assert('type',   'sell|buy')
->convert('dataId', $toInt)
->bind('api_item');

/**
 * ----------------------
 *  route /admin/session
 * ----------------------
 */
$app->get("/admin/session", function(Request $request) use($app) {
    // workaround for now to set active menu item
    $app->setHomeActive();

    return $app['twig']->render('admin_session.html.twig', array(
        'flash'    => $request->get('flash'),
    ));
})
->bind('admin_session');

/**
 * ----------------------
 *  route /admin/session POST
 * ----------------------
 */
$app->post("/admin/session", function(Request $request) use($app) {
    $secret = $request->get('admin_secret');
    if (!$secret || !defined('ADMIN_SECRET') || $secret !== ADMIN_SECRET) {
        return '';
    }

    $session_key  = $request->get('session_key');
    $game_session = (boolean)$request->get('game_session');

    $gw2session = new GW2Session();
    $gw2session->setSessionKey($session_key);
    $gw2session->setGameSession($game_session);
    $gw2session->setCreated(new DateTime());

    try {
        try {
            $ok = GW2SessionManager::getInstance()->checkSessionAlive($gw2session);
        } catch (Exception $e) {
            $gw2session->save();
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "tpdown")));
        }

        if ($ok) {
            $gw2session->save();
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "ok")));
        } else {
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "dead")));
        }
    } catch (PropelException $e) {
        if (strstr($e->getMessage(), "Duplicate")) {
            return $app->redirect($app['url_generator']->generate('admin_session', array('flash' => "duplicate")));
        } else {
            throw $e;
        }
    }
})
->bind('admin_session_post');

/**
 * ----------------------
 *  route /profit
 * ----------------------
 */
$app->get("/profit", function(Request $request) use($app) {
    $where = "";

    if ($minlevel = $request->get('minlevel')) {
        $where .= " AND restriction_level >= {$minlevel}";
    }

    $margin = $request->get('margin') ?: 500;

    if ($minprice = $request->get('minprice')) {
        $where .= " AND min_sale_unit_price >= {$minprice}";
    }

    if ($maxprice = $request->get('maxprice')) {
        $where .= " AND min_sale_unit_price <= {$maxprice}";
    }

    if ($type = $request->get('type')) {
        $where .= " AND item_type_id = {$type}";
    }

    $stmt = Propel::getConnection()->prepare("
    SELECT
        data_id,
        name,
        min_sale_unit_price,
        max_offer_unit_price,
        sale_availability,
        offer_availability,
        ((min_sale_unit_price - max_offer_unit_price) / max_offer_unit_price) * 100 as margin
    FROM item
    WHERE offer_availability > 1
    AND   sale_availability > 5
    AND   ((min_sale_unit_price - max_offer_unit_price) / max_offer_unit_price) * 100 < {$margin}
    {$where}
    ORDER BY margin DESC
    LIMIT 50");

    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $app['twig']->render('quick_table.html.twig', array(
        'headers' => array_keys(reset($data)),
        'data'    => $data,
    ));
})
->assert('minlevel', '\d*');

// bootstrap the app
$app->run();

?>
