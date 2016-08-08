<?php
require('./callables.php');//FlexCall

abstract class Spawning
{
    /**
     * Spawning-Template:
     *  Base for other classes that can scan a tree-like template containing 
     *  hypothetical data (ex: XML document sample) then spawn a new instance
     *  out of it based on actual data (ex: ORM objects)
     **/
    const SPAWN='__SPAWN__';
    const SPAWNROOT='__SPAWN_ROOT__';
    const ITERATOR='__ITERATOR__';
    const SPAWNROOTSET = 'Spawn Root Set';
    protected $template;
    protected $mappings;
    protected $spawners;
    public function __construct($template, $mappings){
        $this->template = $template;
        $this->mappings = $mappings;
        $this->spawners = array();
    }
    
    abstract public function scan($element, $callable=null);
    abstract public function spawn($document);
}

class XMLSpawning extends Spawning
{
    /**
     * XML Spawning Template:
     *  scans an XML sample and uses it to spawn an XML document based on 
     *  an arbitrary set of ORM objects
     **/
    protected $domDocument;// XML sample document to use as template
    protected $spawnedRoot;//parent DOMNode to attach spawned XML element to.
    protected $spawnRoot;// top-most XML element to scan
    public function __construct($template, $mappings, DOMNode $spawnedRoot){
        /**
         * Scans XML $template and compiles $mappings list of callables into 
         *  a sequence of individual XML spawners to use during subsequent calls
         *  to ::spawn
         * $spawnedRoot is the 
         **/
        parent::__construct($template, $mappings);
        $this->domDocument = new DOMDocument('1.0','utf-8');
        $loadStatus = $this->domDocument->loadXML($this->template);
        if(!$loadStatus) throw new \Exception("Failed to load template. Invalid XML?");
        $templateRoot = simplexml_import_dom($this->domDocument->documentElement);
        if(get_class($spawnedRoot) == 'DOMDocument'){
            if (is_null($spawnedRoot->documentElement)){
                $root = $spawnedRoot->createElement($templateRoot->getName());
                $this->spawnedRoot = $spawnedRoot->appendChild($root);
            }else{
                $this->spawnedRoot = $spawnedRoot->documentElement;
            }
        }else{
            // assume a valid document root element
            $this->spawnedRoot = $spawnedRoot;
        }
        // set spawn root
        try{
            $this->scan($templateRoot, array($this,'setSpawnRoot')); 
        }catch(\Exception $ex){
            if ($ex->getMessage() !== self::SPAWNROOTSET) throw $ex;
        }
        $this->scan($start=null, array($this,'compile'));
    }
    
    public function setSpawnRoot(SimpleXMLElement $element){
        /**
         * Return whether XML element is the spawn root
         **/
        echo (string) $element;
        $domNode = dom_import_simplexml($element);
        $path = $domNode->getNodePath();
        if (array_key_exists($path, $this->mappings)){
            $elementMap = $this->mappings[$path];
            if(isset($elementMap[self::SPAWNROOT]) && $elementMap[self::SPAWNROOT]){
                $this->spawnRoot = $element;
                throw new \Exception(self::SPAWNROOTSET);
            }
        }
    }
    
    public function scan($element, $callable=null){
        /**
         * Descend XML sample depth first and invoke $callable on each
         * SimpleXMLElement encountered
         **/
        if(is_null($element)){
            $element = $this->spawnRoot;
        }
        if(!is_null($callable) && ($element !==$this->spawnRoot)){
            call_user_func_array($callable, array($element));
        }
        foreach($element->children() as $child){
            $this->scan($child, $callable);
        }
    }
    
    public function compile(SimpleXMLElement $element){
        /**
         * Define an XMLSpawner to spawn $element and its attributes then
         * map spawner using $element's node path
         **/
        $domNode = dom_import_simplexml($element);
        $path = $domNode->getNodePath();
        $parentNode = $domNode->parentNode;
        $parentPath = $parentNode->getNodePath();
        $parentSpawner = $this->spawners[$parentPath];
        // none-Spawn elements must be mapped to a callable
        if (!array_key_exists($path, $this->mappings)){
            throw new \Exception("Element at $path has no mapping");
        }else{
            //get the element's mappings
            $elementMap = $this->mappings[$path];
        }
        $spawner = new XMLSpawner($element, $elementMap, $parentSpawner);
        $this->spawners[$path] = $spawner;
    }
    
    public function spawn($args,
                          SimpleXMLElement $elToSpawn = NULL, 
                          DOMElement $spawnedParent = NULL){
        /**
         * initiates spawning of template then traverses template depth-first 
         * spawning elements as many times as determined by their individual spawners 
         **/
        
        if (is_null($elToSpawn)){
            //assume this is initial call
            //ignore $spawnedParent and set it to output dom's root
            //assume that $args is $data (an instance of an arbitrary model)
            //and turn it into a keyword-argument mapping
            $kwargs = array('_data'=>$args);
            $spawnedParent = $this->spawnedRoot;
            foreach($this->spawnRoot->children() as $child){
                $this->spawn($kwargs, $child, $spawnedParent);
            }
        }else{
            // assume that $args is already a keyword-argument mapping
            $domNode = dom_import_simplexml($elToSpawn);
            $kwargs = $args;
            // looks up spawner for elToSpawn
            // calls its iterate method with $args to obtain all kwargs 
            // required to invoke attribute getters
            // then iterates over it and calls spawner's spawn with new args
            $path = $domNode->getNodePath();
            if(array_key_exists($path, $this->spawners)){
                $spawner = $this->spawners[$path];
                $argIterator = $spawner->iterate($kwargs);
                foreach($argIterator as $newKwargs){
                    $spawned = $spawner->spawn($newKwargs, $elToSpawn, $spawnedParent);
                    foreach($elToSpawn->children() as $child){
                        $this->spawn($newKwargs, $child, $spawned);
                    }
                }
            }
        }
    }
}

class XMLSpawner implements OuterIterator{
    /**
     * Defines spawning behaviour on an XML element and all its attributes
     **/
    public $vars;// var name-getter pairs available to spawn element
    public $kwargs;// var name value pairs to pass on to child
    protected $makeIterator = null;
    protected $elToSpawn;// template element to be spawned
    protected $attrib_gets;// attribute getters for template element
    protected $parent;
    public function __construct(SimpleXMLElement $elToSpawn, Array $elMappings,
        XMLSpawner $parent=NULL){
        /**
         * Initializes spawning behaviour on $elToSpawn. Maintains a list of
         * attribute "getters" based on passed $elMappings.
         * Uses FlexCall to wrap original closures in order to facilitate
         *  future invokations.
         * $parent XMLSpawner is used to propogate arguments defined at parent's
         *  level to all of its child spawners
         **/
        $this->kwargs = array();
        $this->parent = $parent;
        $this->attrib_gets = array();
        if(is_null($parent)){
            $dataGetter = new FlexCall(function($_data){return $_data;});
            $this->vars= array('_data'=>$dataGetter);
        }else{
            $this->vars = array();
        }
        //TODO: get rid of spawnEL and this->spawnEL
        //      instead of looping attributes, loop on key=>values
        if(array_key_exists(Spawning::SPAWN, $elMappings)){
            $spawnSpecs = $elMappings[Spawning::SPAWN];
        }else{
            $spawnSpecs = array();
        }
        $this->elToSpawn = $elToSpawn;
        foreach($spawnSpecs as $var=>$call){
            if ($var === Spawning::ITERATOR){
                // if it's __ITERATOR__ we create iterator
                // else we add it to the list of required variables
                $this->makeIterator = new FlexCall($call);
            }else{
                $this->vars[$var] = new FlexCall($call);
            }
        }
        if (is_null($this->makeIterator)){
            // if an iterator is not defined, 
            // we return a 1-element array (i.e. one-time spawn)
            $this->makeIterator = function($kwargs){
                return new ArrayIterator(array($kwargs));
            };
        }
        
        // process attribute getter functions as flex calls
        // so that they can be parameterized and invoked more flexibly
        foreach($this->elToSpawn->attributes() as $attribute=>$value){
            if (array_key_exists($attribute, $elMappings)){
                $this->attrib_gets[$attribute] = new FlexCall($elMappings[$attribute]);       
            }else{// each attribute must have a mapping or we raise
                throw new \Exception("Attribute $attribute has no mapping");
            }
        }
    }
    
    public function iterate(Array $kwargs){
        /**
         * Prepare inner iterator by freezing all available arguments
         **/
        $this->iterator = call_user_func($this->makeIterator, $kwargs);
        // var_dump($this->iterator);
        foreach($this->vars as $var=>$getter){
            $getter->freeze($kwargs);
        }
        foreach($this->attrib_gets as $attrib=>$getter){
            $getter->freeze($kwargs);
        }
        return $this;
    }
    
    /**
     * OuterIterator stuff
     **/
    public function getInnerIterator(){return $this->iterator;}
    public function rewind(){$this->iterator->rewind();}
    public function valid(){return $this->iterator->valid();}
    public function next(){$this->iterator->next();}
    public function key(){return $this->iterator->key();}
    public function current(){
        $current = $this->iterator->current();
        foreach($this->vars as $var=>$getter){
            $kwval = $getter($current);
            $current[$var] = $kwval;
            $this->kwargs[$var] = $kwval;
        }
        return $current;
    }
    
    public function spawn(Array $kwargs, SimpleXMLElement $toSpawn, DOMElement $appendTo){
        /**
         * Spawns a new element using spawning template element 
         * and appends it to parent in spawning output
         * 
         * get domdoc and create a new element with name of tospawn
         * iterate attribute getters invoking them with kwargs
         * use getter returns to set new attributes for spawned element
         * append to appendTo
         * return newly created DOMElement
         **/
        $spawnedDoc = $appendTo->ownerDocument;
        $name = $toSpawn->getName();
        $spawnedNode = $spawnedDoc->createElement($name);
        foreach($toSpawn->attributes() as $attrib=>$val){
            $getter = $this->attrib_gets[$attrib];
            $spawnedValue = $getter($kwargs);
            // if null, assume optional and skip attribute
            if(!is_null($spawnedValue)){
                $spawnedNode->setAttribute($attrib, $spawnedValue);
            }
        }
        return $appendTo->appendChild($spawnedNode);
    }
}

/**
 * Demonstration:
 * Use an XML sample document of a hypothetical sale order to 
 *  spawn a compliant XML out of actual order model objects
 **/
class Items implements Iterator{
    protected $range;
    protected $item;
    protected $pos = 0;
    public function __construct(Array $range){
        $this->range = $range;
        $this->item = $this->range[0];
    }
    public function rewind(){
        $this->item = $this->range[0];
        $this->pos = 0;
    }
    public function current(){return array('current'=>$this->item);}
    public function key(){ return $this->pos;}
    public function next(){ $this->item++; $this->pos++;}
    public function valid(){
        return isset($this->range[$this->item]);
    }
}

$template= <<<'XML'
<ORDERS>
	<ORDER order_id="ABC123" order_type="SALE" order_date="2016-05-16T09:30:44" modified_date="2016-05-16T09:30:44" total_tax_amt="3.14159" total_disc_amt="3.14159" total_fees_amt="3.14159" sell_from="WEB">
		<BILLTO_CUSTOMER cust_id="987446321" email="jdoe@fakeemail.com" company_name="Test Company, Inc." title="Mr." first_name="John" last_name="Doe" address1="555 E. Meadow Road" address2="2B" city="New York" region="NY" postal="10016" phone="555-555-5555" country_name="USA"/>
		<SHIPTO_CUSTOMER cust_id="987446321" email="jdoe@fakeemail.com" company_name="Test Company, Inc." title="Mr." first_name="John" last_name="Doe" address1="555 E. Meadow Road" address2="2B" city="New York" region="NY" postal="10016" phone="555-555-5555" country_name="USA"/>
        <ORDER_FEES>
			<ORDER_FEE fee_no="1" fee_type="SHIPPING" fee_amt="15.00" fee_tax_amt="1.25625"/>
		</ORDER_FEES>
		<ORDER_PAYMENTS>
			<ORDER_PAYMENT payment_no="2" payment_amt="46.26" crd_name="AMEX" currency_name="USD"/>
		</ORDER_PAYMENTS>
		<ORDER_ITEMS>
			<ORDER_ITEM item_pos="1" sku="123-ABC" upc="123456789012" item_orig_price="34.99" item_price="24.99" item_tax_amt="2.09291">
				<ORDER_UNIT fill_from="NY1"/>
			</ORDER_ITEM>
        </ORDER_ITEMS>
    </ORDER>
</ORDERS>
XML;

$mappings = array(
    '/ORDERS'=>array(Spawning::SPAWNROOT=>true),
    '/ORDERS/ORDER'=>array(
        Spawning::SPAWN=>array(
            Spawning::ITERATOR=>function($_data){
                return new ArrayIterator(array(array('current'=>$_data)));},
            'order'=>function($current){return $current;},
            'cart'=>function($order){
                return $order->carts[0];},
            'billing'=>function($order){
                return $order->billing_info;},
            'shipping'=>function($order){
                return $order->shipping_info->address;}
        ),
        'order_id'=>function($order){return $order->ref_num;},
        'order_type'=>function($order){return 'SALE';},
        'order_date'=>function($order){return date('c', $order->date_created);},
        'modified_date'=>function($order){return date('c', $order->date_modified);},
        'total_tax_amt'=>function($cart){
            $taxes = array_filter($cart->totals, function($total){
                return ($total->class == 'ot_tax');
            });
            foreach($taxes as &$tax){$tax = $tax->amount;}
            return sprintf("%.2f", array_sum($taxes));},
        'total_disc_amt'=>function($cart){
            $discounts = array_filter($cart->totals, function($total){
                return ($total->class == 'ot_discount_total');
            });
            foreach($discounts as &$discount){$discount = $discount->amount;}
            return sprintf("%.2f", array_sum($discounts));},
        'total_fees_amt'=>function($cart){
            $fees = array_filter($cart->totals, function($total){
                return ($total->class == 'ot_shipping_total');
            });
            foreach($fees as &$fee){$fee = $fee->amount;}
            return sprintf("%.2f", array_sum($fees));},
        'sell_from'=>function($order){
            return 'STORE';},// always in store
    ),
    '/ORDERS/ORDER/BILLTO_CUSTOMER'=>array(
        'cust_id'=>function($billing) {
            return $billing->customer_id == -1 ? 'GUEST' : $billing->customer_id;},
        'email'=>function(){return;},//unavailable
        'company_name'=>function(){return;},//unavailable
        'title'=>function(){return;},//unavailable
        'first_name'=>function($billing){
            return empty($billing->firstname) ? NULL : $billing->firstname;},
        'last_name'=>function($billing){
            return empty($billing->lastname) ? NULL : $billing->lastname;},
        'address1'=>function($billing){return $billing->address;},
        'address2'=>function($billing){return $billing->suburb;},
        'city'=>function($billing){return $biling->city;},
        'region'=>function($billing){return $billing->state;},
        'postal'=>function($billing){return $billing->postcode;},
        'phone'=>function($billing){return $billing->phone;},
        'country_name'=>function($billing){return $billing->country;}
    ),
    '/ORDERS/ORDER/SHIPTO_CUSTOMER'=>array(
        'cust_id'=>function($shipping) {
            return ($shipping->customer_id === -1) ? 'GUEST' : $shipping->customer_id;},
        'email'=>function(){return;},//unavaiable
        'company_name'=>function(){return;},//unavailable
        'title'=>function(){return;},//unavailable,
        'first_name'=>function($shipping){
            return empty($shipping->firstname) ? NULL : $shipping->firstname;},
        'last_name'=>function($shipping){
            return empty($shipping->lastname) ? NULL : $shipping->lastname;},
        'address1'=>function($shipping){return $shipping->address;},
        'address2'=>function($shipping){return $shipping->suburb;},
        'city'=>function($shipping){return $shipping->city;},
        'region'=>function($shipping){return $shipping->state;},
        'postal'=>function($shipping){return $shipping->postal;},
        'phone'=>function($shipping){return $shipping->phone;},
        'country_name'=>function($shipping){return $shipping->country;}
    ),
    '/ORDERS/ORDER/ORDER_FEES'=>array(),
    '/ORDERS/ORDER/ORDER_FEES/ORDER_FEE'=>array(
        Spawning::SPAWN=>array(
            Spawning::ITERATOR=>function(){ return new Items(array(0));}
        ),
        'fee_no'=>function(){return;},
        'fee_type'=>function(){return "SHIPPING";},
        'fee_amt'=>function($cart){
            $fees = array_filter($cart->totals, function($total){
                return ($total->class == 'ot_shipping_total');
            });
            return sprintf("%.2f", array_sum($fees));},
        'fee_tax_amt'=>function(){return;}
    ),
    '/ORDERS/ORDER/ORDER_PAYMENTS'=>array(),
    '/ORDERS/ORDER/ORDER_PAYMENTS/ORDER_PAYMENT'=>array(
        Spawning::SPAWN=>array(
            Spawning::ITERATOR=>function($order){
                return new Items(range(0, count($order->payments) - 1));},
            'payment_ind'=>function($current){return $current;},
            'payment'=>function($order, $payment_ind){
                return $order->payments[$payment_ind];}
        ),
        'payment_no'=>function($payment_ind){return $payment_ind + 1;},
        'payment_amt'=>function($payment){return $payment->amount;},
        'crd_name'=>function($payment){return $payment->type;},
        'currency_name'=>function(){return 'USD';},//always USD
    ),
    '/ORDERS/ORDER/ORDER_ITEMS'=>array(),
    '/ORDERS/ORDER/ORDER_ITEMS/ORDER_ITEM'=>array(
        Spawning::SPAWN=>array(
            Spawning::ITERATOR=>function($cart){
                return new Items(range(0, count($cart->items) - 1));},
            'item_no'=>function($current){
                return $current;},
            'item'=>function($cart, $item_no){
                return $cart->items[$item_no];}
        ),
        'item_pos'=>function($item_no){
            return $item_no+1;},
        'sku'=>function($item) {
            return $item->product_id;},
        'upc'=>function($item) {
            return $item->variant_id;},
        'item_orig_price'=>function($item){return $item->attributes->price_override;},//TODO
        'item_price'=>function($item){return $item->attributes->price_override;},
        'item_tax_amt'=>function($item){return $item->attributes->tax_total;},
    ),
    '/ORDERS/ORDER/ORDER_ITEMS/ORDER_ITEM/ORDER_UNIT'=>array(
        'fill_from'=>function($item){
            return $item->attributes->fullfilment_store_id;},//TODO
    )
);

// actual order models
$serializedOrder2031 = 'O:8:"stdClass":17:{s:7:"ref_num";s:8:"TQ750882";s:11:"customer_id";s:2:"-1";s:6:"status";s:1:"3";s:5:"store";s:4:"1902";s:9:"source_id";s:1:"3";s:5:"carts";a:1:{i:0;O:8:"stdClass":15:{s:8:"store_id";s:4:"1902";s:11:"customer_id";s:2:"-1";s:5:"items";a:2:{i:0;O:8:"stdClass":11:{s:10:"product_id";s:6:"123902";s:19:"fulfilment_store_id";s:4:"1902";s:7:"cart_id";i:-1;s:8:"quantity";s:1:"1";s:10:"variant_id";s:6:"211806";s:7:"ref_num";s:0:"";s:10:"attributes";O:8:"stdClass":4:{s:20:"fulfillment_store_id";s:4:"1902";s:14:"price_override";s:8:"298.0000";s:12:"tax_override";s:1:"1";s:9:"tax_total";s:7:"26.4500";}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}i:1;O:8:"stdClass":11:{s:10:"product_id";s:6:"123134";s:19:"fulfilment_store_id";s:4:"1902";s:7:"cart_id";i:-1;s:8:"quantity";s:1:"1";s:10:"variant_id";s:6:"194451";s:7:"ref_num";s:0:"";s:10:"attributes";O:8:"stdClass":4:{s:20:"fulfillment_store_id";s:4:"1902";s:14:"price_override";s:8:"199.0000";s:12:"tax_override";s:1:"1";s:9:"tax_total";s:7:"17.6700";}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}}s:10:"attributes";a:0:{}s:6:"totals";a:5:{i:0;O:8:"stdClass":4:{s:6:"amount";s:8:"497.0000";s:11:"description";s:8:"Subtotal";s:5:"class";s:11:"ot_subtotal";s:4:"sort";s:3:"100";}i:1;O:8:"stdClass":4:{s:6:"amount";s:8:"541.1200";s:11:"description";s:5:"Total";s:5:"class";s:8:"ot_total";s:4:"sort";s:3:"999";}i:2;O:8:"stdClass":4:{s:6:"amount";s:7:"22.3700";s:11:"description";s:11:"NY CITY TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}i:3;O:8:"stdClass":4:{s:6:"amount";s:7:"19.8800";s:11:"description";s:12:"NY STATE TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}i:4;O:8:"stdClass":4:{s:6:"amount";s:6:"1.8700";s:11:"description";s:14:"NY SPECIAL TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}}s:6:"status";i:0;s:7:"ref_num";s:0:"";s:18:"shipping_method_id";N;s:19:"shipping_address_id";N;s:18:"billing_address_id";N;s:18:"available_shipping";a:0:{}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}}s:8:"payments";a:1:{i:0;O:8:"stdClass":15:{s:6:"module";s:5:"Cayan";s:6:"amount";s:8:"541.1200";s:5:"token";N;s:4:"card";s:0:"";s:15:"nickel_rounding";b:0;s:7:"ref_num";N;s:8:"pos_user";i:0;s:8:"terminal";i:0;s:10:"pos_method";i:0;s:6:"drawer";i:0;s:8:"tulip_id";s:3:"199";s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";s:579:"[{"TransactionType":"SALE","ResponseType":"SINGLE","ValidationKey":"389496be-e8e8-4c80-bb6c-e58d0f2f6dd8","AdditionalParameters":{"SignatureData":"10,10^110,110^0,65535^10,110^110,10^0,65535^~","AmountDetails":{"UserTip":"0.00","Cashback":"0.00","Donation":"0.00","Surcharge":"0.00","Discount":{"Total":"0.00"}}},"PaymentType":"MASTERCARD","AuthorizationCode":"MC0100","AccountNumber":"xxxxxxxxxxxx1234","Status":"APPROVED","AmountApproved":"1.00","ErrorMessage":"","EntryMode":"SWIPE","Cardholder":"Test Customer","Token":"100100100","TransactionDate":"7\/20\/2016 8:38:58 PM"}]";s:4:"type";s:10:"MASTERCARD";}}s:8:"currency";s:3:"USD";s:4:"type";s:0:"";s:9:"parent_id";N;s:12:"billing_info";O:8:"stdClass":17:{s:7:"address";s:0:"";s:4:"name";s:1:" ";s:4:"city";s:0:"";s:5:"state";s:0:"";s:7:"country";s:0:"";s:8:"postcode";s:0:"";s:7:"default";b:0;s:11:"customer_id";i:-1;s:6:"suburb";s:0:"";s:7:"ref_num";s:0:"";s:9:"firstname";s:0:"";s:8:"lastname";s:0:"";s:5:"phone";s:0:"";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:13:"shipping_info";O:8:"stdClass":7:{s:7:"address";O:8:"stdClass":17:{s:7:"address";s:0:"";s:4:"name";s:1:" ";s:4:"city";s:0:"";s:5:"state";s:0:"";s:7:"country";s:0:"";s:8:"postcode";s:0:"";s:7:"default";b:0;s:11:"customer_id";i:-1;s:6:"suburb";s:0:"";s:7:"ref_num";s:0:"";s:9:"firstname";s:0:"";s:8:"lastname";s:0:"";s:5:"phone";s:0:"";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:6:"method";s:0:"";s:6:"module";s:0:"";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:10:"attributes";a:0:{}s:13:"custom_fields";a:0:{}s:8:"tulip_id";s:4:"2031";s:12:"date_created";i:1469047080;s:13:"date_modified";i:1469047139;}';
$serializedOrder2032 = 'O:8:"stdClass":17:{s:7:"ref_num";s:8:"TQ717817";s:11:"customer_id";s:3:"708";s:6:"status";s:1:"1";s:5:"store";s:4:"1902";s:9:"source_id";s:1:"3";s:5:"carts";a:1:{i:0;O:8:"stdClass":15:{s:8:"store_id";s:4:"1902";s:11:"customer_id";s:3:"708";s:5:"items";a:2:{i:0;O:8:"stdClass":11:{s:10:"product_id";s:6:"123781";s:19:"fulfilment_store_id";s:4:"1902";s:7:"cart_id";i:-1;s:8:"quantity";s:1:"1";s:10:"variant_id";s:6:"208127";s:7:"ref_num";s:0:"";s:10:"attributes";O:8:"stdClass":4:{s:20:"fulfillment_store_id";s:1:"2";s:14:"price_override";s:8:"198.0000";s:12:"tax_override";s:1:"1";s:9:"tax_total";s:7:"17.5700";}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}i:1;O:8:"stdClass":11:{s:10:"product_id";s:6:"123841";s:19:"fulfilment_store_id";s:4:"1902";s:7:"cart_id";i:-1;s:8:"quantity";s:1:"1";s:10:"variant_id";s:6:"210079";s:7:"ref_num";s:0:"";s:10:"attributes";O:8:"stdClass":4:{s:20:"fulfillment_store_id";s:1:"2";s:14:"price_override";s:8:"228.0000";s:12:"tax_override";s:1:"1";s:9:"tax_total";s:7:"20.2400";}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}}s:10:"attributes";a:0:{}s:6:"totals";a:6:{i:0;O:8:"stdClass":4:{s:6:"amount";s:8:"426.0000";s:11:"description";s:8:"Subtotal";s:5:"class";s:11:"ot_subtotal";s:4:"sort";s:3:"100";}i:1;O:8:"stdClass":4:{s:6:"amount";s:8:"483.8100";s:11:"description";s:5:"Total";s:5:"class";s:8:"ot_total";s:4:"sort";s:3:"999";}i:2;O:8:"stdClass":4:{s:6:"amount";s:7:"17.0400";s:11:"description";s:12:"NY STATE TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}i:3;O:8:"stdClass":4:{s:6:"amount";s:7:"19.1700";s:11:"description";s:11:"NY CITY TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}i:4;O:8:"stdClass":4:{s:6:"amount";s:6:"1.6000";s:11:"description";s:14:"NY SPECIAL TAX";s:5:"class";s:6:"ot_tax";s:4:"sort";s:3:"300";}i:5;O:8:"stdClass":4:{s:6:"amount";s:7:"20.0000";s:11:"description";s:14:"Shipping Costs";s:5:"class";s:17:"ot_shipping_total";s:4:"sort";s:3:"290";}}s:6:"status";i:0;s:7:"ref_num";s:0:"";s:18:"shipping_method_id";N;s:19:"shipping_address_id";N;s:18:"billing_address_id";N;s:18:"available_shipping";a:0:{}s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}}s:8:"payments";a:2:{i:0;O:8:"stdClass":15:{s:6:"module";s:6:"Clutch";s:6:"amount";s:8:"440.0000";s:5:"token";N;s:4:"card";s:0:"";s:15:"nickel_rounding";b:0;s:7:"ref_num";N;s:8:"pos_user";i:0;s:8:"terminal";i:0;s:10:"pos_method";i:0;s:6:"drawer";i:0;s:8:"tulip_id";s:3:"200";s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";N;s:4:"type";s:9:"Gift Card";}i:1;O:8:"stdClass":15:{s:6:"module";s:5:"Cayan";s:6:"amount";s:7:"43.8100";s:5:"token";N;s:4:"card";s:0:"";s:15:"nickel_rounding";b:0;s:7:"ref_num";N;s:8:"pos_user";i:0;s:8:"terminal";i:0;s:10:"pos_method";i:0;s:6:"drawer";i:0;s:8:"tulip_id";s:3:"201";s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";s:579:"[{"TransactionType":"SALE","ResponseType":"SINGLE","ValidationKey":"7dcb0a9b-d725-4095-bf53-35267608df98","AdditionalParameters":{"SignatureData":"10,10^110,110^0,65535^10,110^110,10^0,65535^~","AmountDetails":{"UserTip":"0.00","Cashback":"0.00","Donation":"0.00","Surcharge":"0.00","Discount":{"Total":"0.00"}}},"PaymentType":"MASTERCARD","AuthorizationCode":"MC0100","AccountNumber":"xxxxxxxxxxxx1234","Status":"APPROVED","AmountApproved":"1.00","ErrorMessage":"","EntryMode":"SWIPE","Cardholder":"Test Customer","Token":"100100100","TransactionDate":"7\/20\/2016 8:59:33 PM"}]";s:4:"type";s:10:"MASTERCARD";}}s:8:"currency";s:3:"USD";s:4:"type";s:0:"";s:9:"parent_id";N;s:12:"billing_info";O:8:"stdClass":17:{s:7:"address";s:15:"3360 Octavia St";s:4:"name";s:13:"Julia Lehrman";s:4:"city";s:13:"San Francisco";s:5:"state";s:2:"CA";s:7:"country";s:13:"United States";s:8:"postcode";s:5:"94123";s:7:"default";b:0;s:11:"customer_id";i:1234;s:6:"suburb";s:6:"Apt 10";s:7:"ref_num";s:0:"";s:9:"firstname";s:5:"Julia";s:8:"lastname";s:7:"Lehrman";s:5:"phone";s:13:"(321) 123-456";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:13:"shipping_info";O:8:"stdClass":7:{s:7:"address";O:8:"stdClass":17:{s:7:"address";s:15:"3360 Octavia St";s:4:"name";s:13:"Julia Lehrman";s:4:"city";s:13:"San Francisco";s:5:"state";s:2:"CA";s:7:"country";s:13:"United States";s:8:"postcode";s:5:"94123";s:7:"default";b:0;s:11:"customer_id";i:1234;s:6:"suburb";s:6:"Apt 10";s:7:"ref_num";s:0:"";s:9:"firstname";s:5:"Julia";s:8:"lastname";s:7:"Lehrman";s:5:"phone";s:13:"(123) 123-456";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:6:"method";s:0:"";s:6:"module";s:0:"";s:8:"tulip_id";b:0;s:12:"date_created";b:0;s:13:"date_modified";b:0;s:13:"custom_fields";a:0:{}}s:10:"attributes";a:0:{}s:13:"custom_fields";a:0:{}s:8:"tulip_id";s:4:"2032";s:12:"date_created";i:1469048340;s:13:"date_modified";i:1469048373;}';
$orders = array(unserialize($serializedOrder2031),
    unserialize($serializedOrder2032));

// initialize XML output and pass to template
$spawnedXML = new DOMDocument('1.0','utf-8');
$spawnedXML->formatOutput = true;
$spawning = new XMLSpawning($template, $mappings, $spawnedXML);
foreach($orders as $order){
    // print_r($order);
    $spawning->spawn($order);
}
// print spawned XML
echo $spawnedXML->saveXML();
?>