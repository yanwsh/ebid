<?php
/**
 * Created by PhpStorm.
 * User: Elaine
 * Date: 11/23/14
 * Time: 5:49 PM
 */

namespace ebid\Controller;
use ebid\Entity\Product;
use ebid\Entity\Result;
use ebid\Entity\User;
use ebid\Entity\Bid;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends baseController {
    public function addAction(){
        $this->checkAuthentication();
        $MySQLParser = $this->container->get('MySQLParser');
        $data = json_decode($this->request->getContent());
        $product = new Product();
        if(!$product->isValid($data)){
            $result = new Result(Result::FAILURE, 'post data is not valid.');
            return new Response(json_encode($result));
        }
        $product->set($data);
        if($product->defaultImage == null || $product->defaultImage == ""){
            if($product->imageLists != null && count($product->imageLists)){
                $product->defaultImage = $product->imageLists[0];
            }
        }
        global $session;
        $securityContext = $session->get("security_context");
        $user = $securityContext->getToken()->getUser();
        $product->seller = $user->uid;
        if (preg_match ("/<body.*?>([\w\W]*?)<\/body>/", $product->description, $regs)) {
            $product->description = $regs[1];
        }
        $product->status = Product::INITIAL;
        $product->currentPrice = $product->startPrice;
        $MySQLParser->insert($product, array("pid"), array('startPrice', 'expectPrice','buyNowPrice','categoryId'));
        $result = new Result(Result::SUCCESS, "Product added successfully.");
        return new Response(json_encode($result));
    }

    public function itemAction($itemId){
        $itemId = intval($itemId);
        $MySQLParser = $this->container->get('MySQLParser');
        //get product result
        $product = new Product();
        $result = $MySQLParser->select($product, ' pid = ' .$itemId, NULL,array('pid','pname', 'description', 'buyNowPrice', 'currentPrice', 'startPrice','defaultImage', 'imageLists', 'endTime', 'categoryId', 'shippingType', 'shippingCost', 'auction', 'seller', '`condition`', 'status') );
        $result = $result[0];
        $result['imageLists'] = json_decode($result['imageLists']);
        $product->set($result);
        //get seller info
        $user = new User($product->seller, "seller");
        $res = $MySQLParser->select($user, 'uid = ' . $user->uid, NULL, array('uid', 'username','email', 'state'));
        $res = $res[0];
        $product->seller = $res;
        if (preg_match ("/<body.*?>([\w\W]*?)<\/body>/", $product->description, $regs)) {
            $product->description = $regs[1];
        }
        //filter null record
        $product = (object) array_filter((array) $product);
        //calc increment price and forceInc price;
        $forceInc = $inc = $this->getIncPrice($product->currentPrice);
        if($product->status == Product::INITIAL){
            $forceInc = 0;
        }else{
            $forceInc = $inc;
        }


        //set current price and bid number to firebase
        global $session;
        $ret = $session->get('bid/item/' . $itemId);
        if($ret == null) {
            //fetch current price.
            $firebase = $this->container->get('Firebase');
            $ret = $firebase->get('bid/item/' . $itemId);
            if ($ret == null) {
                $this->updateFirebase($itemId, $product->currentPrice);
            }
            $session->set('bid/item/' . $itemId, $ret);
        }
        //get userminPrice
        //check is login?
        global $session;
        $securityContext = $session->get("security_context");
        $user = $securityContext->getToken()->getUser();
        if($user == null || $user == "anon."){
            //not login
            $product->userMinPrice = $product->currentPrice + $forceInc;
        }else{
            $bid = new Bid();
            //get bid record
            $res = $MySQLParser->select($bid, ' uid = ' . $user->uid, 'bidPrice DESC');
            if(count($res) > 0){
                //has bid record
                $res = $res[0];
                $maxPrice = floatval($res['bidPrice']);
                $maxPrice = ($maxPrice > $product->currentPrice)? $maxPrice : $product->currentPrice;
                $minPrice = $maxPrice + $this->getIncPrice($maxPrice);
                $product->userMinPrice = $minPrice;
            }else{
                //no bid record
                $product->userMinPrice = $product->currentPrice + $forceInc;
            }
        }
        $result = new Result(Result::SUCCESS, "get product successfully.", $product);
        return new Response(json_encode($result));
    }

    public function bidAction($itemId, $price){
        $itemId = intval($itemId);
        $price = floatval($price);
        //check auth
        $this->checkAuthentication();
        $MySQLParser = $this->container->get('MySQLParser');
        $bid = new Bid();
        //get bid user
        global $session;
        $securityContext = $session->get("security_context");
        $user = $securityContext->getToken()->getUser();
        //get user bid record
        $res = $MySQLParser->select($bid, ' uid = ' . $user->uid, 'bidPrice DESC');
        //has bid record
        if(count($res) > 0){
            $res = $res[0];
            $maxPrice = floatval($res['bidPrice']);
            $minPrice = $maxPrice + $this->getIncPrice($maxPrice);
            if($price < $minPrice){
                $result = new Result(Result::FAILURE, 'You must post a price greater than ' . $minPrice .'.');
                return new Response(json_encode($result));
            }
        }
        //get product infomation
        $product = new Product();
        $result = $MySQLParser->select($product, ' pid = ' .$itemId);
        $result = $result[0];
        $product->set($result);
        //check price
        if($product->status == Product::INITIAL){
            $minPrice = $product->currentPrice;
        }else{
            $minPrice = $product->currentPrice  + $this->getIncPrice($product->currentPrice);
        }

        if($minPrice > $price){
            $result = new Result(Result::FAILURE, 'You can\'t post a price less than current price(' . $product->currentPrice .').');
            return new Response(json_encode($result));
        }else if($product->currentPrice == $price){
            if($product->status != Product::INITIAL){
                $result = new Result(Result::FAILURE, 'You can\'t post a price less than current price(' . $product->currentPrice .').');
                return new Response(json_encode($result));
            }
        }
        //update product price

        //get product bid record
        $res = $MySQLParser->select($bid, ' pid = ' . $itemId, 'bidPrice DESC');
        if(count($res) > 0) {
            $res = $res[0];
            $higherRecord = new Bid();
            $higherRecord->set($res);
        }

        $isWinner = true;
        if($higherRecord == null){
            //no bid record
            $product->currentPrice = $minPrice;
        }else{
            //highest price is the same user
            if($higherRecord->uid == $user->uid){
                //ignore
                //update higherRecord to not win
                $higherRecord->status = Bid::NOTWIN;
                $MySQLParser->updateSpecific($higherRecord, array('status'), array('status'), 'bid');
            }else{
                //if price is greater than $higherRecord
                if($price > $higherRecord->bidPrice){
                    $product->currentPrice = $higherRecord->bidPrice + $this->getIncPrice($higherRecord->bidPrice);
                    //update higherRecord to not win
                    $higherRecord->status = Bid::NOTWIN;
                    $MySQLParser->updateSpecific($higherRecord, array('status'), array('status'), 'bid');
                }else{
                    //price is lower or equal than higherRecord
                    if($price == $higherRecord->bidPrice){
                        $product->currentPrice = $price;
                    }else{
                        $product->currentPrice = $price + $this->getIncPrice($price);
                    }
                    $isWinner = false;
                }
            }
        }

        if($product->status == Product::INITIAL){
            $product->status = Product::BIDDING;
        }
        $MySQLParser->updateSpecific($product, array('currentPrice', 'status'), array('currentPrice', 'status'), 'pid');

        //insert to database
        $bid = new Bid();
        $bid->uid = $user->uid;
        $bid->status = $isWinner? Bid::WIN: Bid::NOTWIN;
        $bid->pid = $itemId;
        $bid->bidPrice = $price;
        $bid->bidTime = date('Y-m-d H:i:s', time());
        $MySQLParser->insert($bid, array("bid"), array('pid', 'bidPrice', 'status', 'uid'));


        //update firebase
        $this->updateFirebase($itemId, $product->currentPrice);

        $result = new Result(Result::SUCCESS, "bid post successfully.");
        return new Response(json_encode($result));
    }

    public function updateFirebase($itemId, $currentPrice){
        $MySQLParser = $this->container->get('MySQLParser');
        $sql = 'SELECT COUNT(*) FROM ' . _table('Bid') .' WHERE pid = '. $itemId;
        $res = $MySQLParser->query($sql);
        $bidNumber = intval($res[0]['COUNT(*)']);
        $priceList = array('currentPrice' => floatval($currentPrice), 'bidNumber' => $bidNumber);
        $firebase = $this->container->get('Firebase');
        $ret = $firebase->set('bid/item/' . $itemId, $priceList);
    }

    public function getIncPrice($price){
        if($price < 10){
            return 0.5;
        }else if($price < 50){
            return 1;
        }else if($price < 100){
            return 2;
        }else if($price < 300){
            return 5;
        }else if($price < 1000){
            return 10;
        }else if($price < 10000){
            return 50;
        }else if($price < 50000){
            return 500;
        }else{
            return 1000;
        }
    }
} 