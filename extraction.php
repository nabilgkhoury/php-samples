<?php
require('./callables.php');//FlexCall

class Extractor
{
	/**
	 * class encapsulating extraction operations whose specifics are defined by 
	 * an instance of ExtractorSpecs
	 **/
	protected $specs;// instance of ExtractorSpecs 
	protected $extractors;// array of individual extractors
	protected $overrides_checker;// checks whether override columns are available
	protected $overrides_validators;// flex-calls to validate override columns
	protected $specs_root;// SimpleXMLElement pointing to root of $specs DOM
	protected $default_getter;// returns field values of a given record
	protected $constants_prefix;// helps resolve consts attached to $specs
	const NAME='name',CONSTANT='constant',COLUMNS='columns',OVERRIDES='overrides',GETTER='getter';
	public function __construct(ExtractorSpecs $specs){
		$this->specs = $specs;
		$this->constants_prefix = get_class($specs);
		$this->extractors = array();
		$this->overrides_validators= array();
		$this->default_getter = function($record, $columns){
			return $record[$columns[0]];
		};
		$this->overrides_checker = function ($record, $overrides){
			// if any of the overrides columns is defined return true
			foreach($overrides as $override){
				if (isset($record[$override]) && !empty($record[$override])){
					return true;
				}
			}
			return false;
		};
        $domSpecs = new DOMDocument('1.0','utf-8');
		$loadStatus = $domSpecs->loadXML($this->specs->template);
		if(!$loadStatus) throw new \Exception("Failed to load template. Invalid XML?");
		$this->specs_root = simplexml_import_dom($domSpecs->documentElement);
		$this->scan($start=null, array($this,'compile'));
	}

	public function scan($element, $callable=null){
		/**
		 * Descend Specs tree depth first 
		 *	and calls $callable on each element encountered
		 **/
		if(is_null($element)){
			$element = $this->specs_root;
		}
		if(!is_null($callable)){
			call_user_func_array($callable, array($element));
		}
		foreach($element->children() as $child){
			$this->scan($child, $callable);
		}
	}

	public function compile(\SimpleXMLElement $element){
		/**
		 * validates Specs element and maps its extractor and override
		 **/
		//get the xpath to facilitate mapping
		//loop on attributes to define corresponding getter functions
		$domNode = dom_import_simplexml($element);
		$path = $domNode->getNodePath();
		//TODO iterate list of specification attributes adding an extraction getter for each
		$name=NULL; $constant=NULL;$columns=NULL; $overrides=NULL; $getter=NULL;
		$countDefined = 0;
		// validate and resolve specification attributes
		foreach($element->attributes() as $attr=>$val){
			$countDefined++;
			if (empty($val)){
				throw new \Exception(__FUNCTION__.
					" error: Empty attribute at $path");
			}else{
				$values = explode(',', $val);
			}
			switch ($attr){
				case self::NAME:
					if (count($values)>1) {
						throw new \Exception(__FUNCTION__ . ' error : ' .
							"Only one name allowed in name attribute at $path");
					}else {
						$name=$values[0];
					}
					if (array_key_exists($path, $this->extractors)){
						throw new \Exception(__FUNCTION__.' error : '.
							"Duplicate specification attribute: $path");
					}
					break 1;
				case self::CONSTANT:
				    $constant = create_function('',"return $val;");
				    break 1;
				case self::COLUMNS:
					//TODO getter getColumnIndices
					try{
						$columns = $this->getColumnIndices($values);
					}catch(\Exception $ex){
						throw new \Exception(__FUNCTION__.' error : '.
							"Invalid column identifier at $path");
					}
					break 1;
				case self::OVERRIDES:
					try{
						$overrides = $this->getColumnIndices($values);
					}catch(\Exception $ex){
						throw new \Exception(__FUNCTION__.' error : '.
							"Invalid overrides identifier at $path");
					}
					break 1;
				case self::GETTER:
					if (count($values)>1) {
						throw new \Exception(__FUNCTION__ . ' error : ' .
							"Only one getter allowed getter attribute at $path");
					}else{
						$getter=$values[0];
						if(array_key_exists($getter, $this->specs->getters)){
							$getter = $this->specs->getters[$getter];
						}else{
							// must map to one of the getters
							throw new \Exception(__FUNCTION__. ' error : ' .
								"Function $getter at $path is not mapped to any known getter");
						}
					}
					break 1;
				default:throw new \Exception(__FUNCTION__.' error : '.
					"Unsupported attribute $attr at $path"); break 1;
			}
		}
		//must define name and column at least
		if($countDefined > 0){
		    if (is_null($name)){
		        throw new \Exception(__FUNCTION__." error: ".
					"Attribute (name) missing from specification element at $path");  
		    }elseif (is_null($constant) && is_null($columns)){
				throw new \Exception(__FUNCTION__." error: ".
					"Attributes (constant) and (columns) missing from specification element at $path");
		    }elseif (!is_null($constant)){
		        // when constant is defined, ignore columns and overrides
		        // add element path to list of extractors and map to flex call
				$this->extractors[$path] = new FlexCall($constant);
			}else{
				// add attribute path to list of extractors and map to flex call
				// Specification Elements without overrides attribute
				// get a single flexcall of the form: function($record, columns)
				// While those with overrides attribute get a second flexgetter
				// of the form: `boolean function($record, overrides)` to determine
				// whether first flexcall will use `columns` or `overrides`
				// if `getter` attribute is there, use it to init flexcall
				// 	otherwise use default_getter instead
				if (is_null($getter)){
					$getter = $this->default_getter;
				}
				$extractor = new FlexCall($getter);
				//ensure getter defined at least record and column
				$extractor->columns = $columns;// freeze columns arg
				$this->extractors[$path] = $extractor;
				if (!is_null($overrides)){
					$overrides_validator = new FlexCall($this->overrides_checker);
					$overrides_validator->overrides = $overrides;
					$this->overrides_validators[$path] = $overrides_validator;
				}
			}
		}
	}

	private function getColumnIndices($column_names){
		/**
		 * translates array of column names into array of field indices 
		 *	based on constants in $specs
		 **/
		$indices=array();
		foreach($column_names as $column_name){
			$constInd = constant($this->constants_prefix.'::'.$column_name);
			if(is_null($constInd)){
				// not a constant index, is it a column index?
				if(is_int($column_name)){
					$indices[] = $column_name;
				}else{
					throw new \Exception();
				}
			}else{
				$indices[]=$constInd;
			}
		}
		return $indices;
	}
	
	public function extract($record, $xpath){
		/**
		 * extracts fields of $record corresponding to elements matching $xpath
		 **/
	    $result = $this->specs_root->xpath($xpath);
	    echo "$xpath \n";
	    $extracted = array();
	    foreach($result as $index=>$node){
	        $dN = dom_import_simplexml($node);
	        $path = $dN->getNodePath();
	        if(array_key_exists($path, $this->extractors)){
	        	$name = (string) $node['name'];
	        	$extractor = $this->extractors[$path];
		        // are there overrides?
		        if(array_key_exists($path, $this->overrides_validators)){
		        	// validate overrides
		        	$overrides_validator = $this->overrides_validators[$path];
		        	if ($overrides_validator(array('record'=>$record))){
		        		// overrides are available. Freeze overrides columns
		        		//  in extractor instead of regular columns
		        		$extractor->columns = $overrides_validator->overrides;
		        	}
		        }
	            $extracted[$name] = $extractor(array('record'=>$record));
	        }
	    }
	    return $extracted;
	}
}

abstract class ExtractorSpecs{
	/**
	 * Base for extractor specification classes
	 *	encapsulates the xml $template and corresponding $getters
	 **/
    public $template;
    public $getters;
    abstract public function __construct();
}

/**
 * Demonstration: extract products/variants from a simulated csv file
 *	Uses ProductExtraction, GroupIterator and a simulated csv input
 *	to demonstrate Extractor and ExtractorSpecs
 **/
class ProductExtraction extends ExtractorSpecs{
	/**
	 * Extraction specs for hypothetical tabulated Product/Variant data
	 **/
    const RP_SKU = 0, RP_NAME=1, ALU=2, COST=3, DCS_CODE=4, RP_COLOR=5,
    	RP_SIZE=6, RP_DESCRIPTION=7, RP_STATUS=8,TAX_CODE=9, PRODUCTID=10,
    	UPC=11, MODELNAME=12, OS_DESCRIPTION=13, OS_DESCRIPTION2=14, DEFAULTIMAGEURL=15,
    	STOCKLEVEL=16, COLORNAME=17, SIZENAME=18, COLORIMAGEURL=19, EXTRAMEDIAIMAGE1=20,
    	EXTRAMEDIAIMAGE2=21,EXTRAMEDIAIMAGE3=22,EXTRAMEDIAIMAGE4=23,EXTRAMEDIAIMAGE5=24, EXTRAMEDIAIMAGE6=25,
    	ACTIVE=26, COLORORDER=27, SIZEORDER=28, PRODUCTSUGGESTIONS=29, SWATCHIMAGEURL=30,
    	COLORFAMILY=31, ATTRIBUTES=32, CATEGORIES=33;
   
    public function __construct(){
        $this->template= <<<'XML'
<PRODUCT>
	<FIELDS>
		<FIELD name="SKU" columns="RP_SKU"/>
		<FIELD name="UPC" columns="UPC"/>
		<FIELD name="name" columns="RP_NAME" overrides="MODELNAME"/>
		<FIELD name="categories" columns="CATEGORIES"/>
		<FIELD name="description" columns="RP_DESCRIPTION" overrides="OS_DESCRIPTION,OS_DESCRIPTION2" getter="getDescription"/>
		<FIELD name="price" constant="(float) 0.0"/>
		<FIELD name="status" constant="0" overrides="ACTIVE"/>
		<FIELD name="images" constant="array()"/>
		<FIELD name="related" columns="PRODUCTSUGGESTIONS" getter="getRelatedProducts"/>
		<FIELD name="quantity_text" columns="STOCKLEVEL"/>
	</FIELDS>
	<ATTRIBUTES>
		<ATTRIBUTE name="source" columns="PRODUCTID" getter="getSource"/>
		<ATTRIBUTE name="onestop_id" columns="PRODUCTID"/>
		<ATTRIBUTE name="os_attributes" columns="ATTRIBUTES" getter="getOSAttributes"/>
		<ATTRIBUTE name="color_family" columns="COLORFAMILY"/>
	</ATTRIBUTES>
	<VARIANTS>
		<VARIANT>
			<FIELDS>
				<FIELD name="name" columns="RP_NAME" overrides="MODELNAME"/>
				<FIELD name="ref_num" columns="UPC" getter="getVariantRefNum"/>
				<FIELD name="upc" columns="UPC" getter="getVariantRefNum"/>
				<FIELD name="status" columns="RP_STATUS"/>
				<FIELD name="images" columns="COLORIMAGEURL,EXTRAMEDIAIMAGE1,EXTRAMEDIAIMAGE2,EXTRAMEDIAIMAGE3,EXTRAMEDIAIMAGE4,EXTRAMEDIAIMAGE5,EXTRAMEDIAIMAGE6" getter="getImages"/>
				<FIELD name="sort_order" columns="COLORORDER" getter="getSortOrder"/>
			</FIELDS>
			<OPTIONS>
				<OPTION name="color" columns="COLORFAMILY,COLORFAMILY,COLORFAMILY" overrides="COLORNAME,COLORNAME,SWATCHIMAGEURL" getter="getOption"/>
				<OPTION name="size" columns="RP_SIZE,RP_SIZE,RP_SIZE" overrides="SIZENAME,SIZENAME,SIZENAME" getter="getOption"/>
			</OPTIONS>
			<ATTRIBUTES>
				<ATTRIBUTE name="retailpro_sku" columns="RP_SKU"/>
				<ATTRIBUTE name="retailpro_color" columns="RP_COLOR"/>
				<ATTRIBUTE name="source" columns="PRODUCTID" getter="getSource"/>
				<ATTRIBUTE name="alu" columns="ALU"/>
				<ATTRIBUTE name="cost" columns="COST"/>
				<ATTRIBUTE name="dcs_code" columns="DCS_CODE"/>
				<ATTRIBUTE name="os_attributes" columns="ATTRIBUTES" getter="getOSAttributes"/>
				<ATTRIBUTE name="onestop_id" columns="PRODUCTID"/>
			</ATTRIBUTES>
		</VARIANT>
	</VARIANTS>
</PRODUCT>
XML;
        $this->getters= array(
        	"getDescription"=>function($record, $columns){
        		$descriptions = array();
        		foreach ($columns as $column){
        			$description = trim($record[$column]);
        			if($description != '') $descriptions[] = $description;
        		}
        		return implode(FRYE_DESC_SEP, $descriptions);
        	},"getRelatedProducts"=>function($record, $columns){
        	    return $record[$columns[0]];
        	},"getSource"=>function($record, $columns){
        		if ($record[$columns[0]] != ''){
        			return 'RetailPro';
        		}
        		else{
        			return 'OneStop';
        		}
        	},"getInteger"=>function($record, $columns){
        		return (int) $record[$columns[0]];
        	},"getOSAttributes"=>function($record, $columns){
        	    $json = $record[$columns[0]];
        	    $attributes = json_decode(stripslashes($json), true);
        	    $named_attributes = array();
        	    foreach($attributes as $attribute){
        	        foreach($attribute as $name=>$details){
            	        list($value, $data) = $details;
            	        $named_attributes[$name] = $value;
            	        if($name == 'Tax Code')
            	            $named_attributes[$name] = $data;
            	        break 1;
        	        }
        	    }
        	    return $named_attributes;
        	},
            "getVariantRefNum"=>function($record, $columns){
        		return ltrim($record[$columns[0]], ' 0');
        	},"getImages"=>function($record, $columns){
        		$images = array();
        		foreach ($columns as $index){
        			// convert url to "canonical form"
        			$img = strtolower(trim($record[$index]));
        			if (strlen($img) > 0){
        				$images[] = $img;
        			}
        		}
        		// return distinct images with normalized indices
        		return array_values(array_unique($images));
        	},"getSortOrder"=>function($record, $columns){
        		$sort_order = $record[$columns[0]];
        		if($sort_order != '') return $sort_order;
        		else return 10;
        	},"getOption"=>function($record, $columns){
        		if (count($columns) !== 3){
        			throw new \Exception("getOption requires an option name, presentation and value fields");
        		}else{
        			list($n, $p, $v) = $columns;
        			return array(trim($record[$n]), trim($record[$p]),trim($record[$v]));
        		}
        	}
        );
    }
}

class GroupIterator implements iterator{
	/**
	 * Iterator implementation that returns a group of related csv 
	 * records for caller to iterate on. 
	 * This is a naive simulation for demonstration purposes. 
	 * Not suitable for production!!
	 **/
    public $records;// csv records/array of a field arrays
    public $separator;// csv field separator
    public $group_start;// index of first record in group
    public $group_stop; // index of last record in group
    public $group_id;// index of field containing group identifier
    public $current_group;// prepared array of records belonging to current group 
    public function __construct ($sortedContent,$groupId,$separator){
    	/**
    	 * $sortedContent csv content. Assumed pre-sorted on 
    	 * field containing group id.
    	 * $groupId index of field containing group identifier
    	 * $separator character used to delimit csv fields
    	 **/
        $this->group_id = $groupId;
        $this->separator = $separator;
        $this->records = explode("\n", $sortedContent);
        foreach ($this->records as &$record){
            $record = str_getcsv($record, $this->separator);
        }
    }
    public function current(){
        return $this->current_group;
    }
    public function key(){
        return $this->group_id;
    }
    public function next(){
        $this->current_group = array();
        while (isset($this->records[$this->group_stop])){
            $group_first = $this->records[$this->group_start];
            $group_last = $this->records[$this->group_stop];
            if ($group_first[$this->group_id] === $group_last[$this->group_id]){
                $this->current_group[] = $group_last;
                $this->group_stop++;
            }else{
                break;
            }
        }
        $this->group_start = $this->group_stop;// reset range for next iteration
    }
    public function rewind(){
        $this->group_stop = $this->group_start = 0;
        $this->current_group = array();
        $this->next();
    }
    public function valid(){
        return count($this->current_group) > 0;
    }
}

//simulated CSV input
$products_csv = <<< CSV
"70007"|"VERA BUCKLE"|"3470007-BLK"|"86.25"|"FRWBOTWRK"|"BLK"|"5.5"|""|"1"|"6"|"70007"|"888542292691"|"Vera Buckle"|""|""|"http://www.thefryecompany.com/store/productimages/regular/70007_black_l.jpg"|"0"|"Black"|"5.5"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_f.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_t.jpg"|"0"|"1"|"11"|"73458"|"http://www.thefryecompany.com/store/ProductImages/colors/70007_black_l_sw.jpg"|"Black"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Mid\",\"\"]},{\"Shaft Height\":[\"Mid Shaft\",\"\"]},{\"Style\":[\"Work\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
"70007"|"VERA BUCKLE"|"3470007-BLK"|"86.25"|"FRWBOTWRK"|"BLK"|"6"|""|"1"|"6"|"70007"|"888542293506"|"Vera Buckle"|""|""|"http://www.thefryecompany.com/store/productimages/regular/70007_black_l.jpg"|"0"|"Black"|"6"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_f.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_t.jpg"|"0"|"1"|"12"|"73458"|"http://www.thefryecompany.com/store/ProductImages/colors/70007_black_l_sw.jpg"|"Black"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Mid\",\"\"]},{\"Shaft Height\":[\"Mid Shaft\",\"\"]},{\"Style\":[\"Work\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
"70007"|"VERA BUCKLE"|"3470007-BLK"|"86.25"|"FRWBOTWRK"|"BLK"|"6.5"|""|"1"|"6"|"70007"|"888542293513"|"Vera Buckle"|""|""|"http://www.thefryecompany.com/store/productimages/regular/70007_black_l.jpg"|"0"|"Black"|"6.5"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_l.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_f.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/70007_black_t.jpg"|"0"|"1"|"13"|"73458"|"http://www.thefryecompany.com/store/ProductImages/colors/70007_black_l_sw.jpg"|"Black"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Mid\",\"\"]},{\"Shaft Height\":[\"Mid Shaft\",\"\"]},{\"Style\":[\"Work\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
"73458"|"GABBY GHILLIE"|"3470014-SMK"|"561.79"|"FRWBOTWRK"|"SMK"|"5.5"|""|"1"|"6"|"73458"|"888542292123"|"GABBY GHILLIE"|""|""|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"0"|"Smoke"|"5.5"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_f.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_t.jpg"|"0"|"1"|"11"|"70007"|"http://www.thefryecompany.com/store/ProductImages/colors/73458_smoke_l_sw.jpg"|"Smoke"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Tall\",\"\"]},{\"Shaft Height\":[\"Tall Shaft\",\"\"]},{\"Style\":[\"Leisure\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
"73458"|"GABBY GHILLIE"|"3470014-SMK"|"561.79"|"FRWBOTWRK"|"SMK"|"6"|""|"1"|"6"|"73458"|"888542293234"|"GABBY GHILLIE"|""|""|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"0"|"Smoke"|"6"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_f.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_t.jpg"|"0"|"1"|"12"|"70007"|"http://www.thefryecompany.com/store/ProductImages/colors/73458_smoke_l_sw.jpg"|"Smoke"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Tall\",\"\"]},{\"Shaft Height\":[\"Tall Shaft\",\"\"]},{\"Style\":[\"Leisure\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
"73458"|"GABBY GHILLIE"|"3470014-SMK"|"561.79"|"FRWBOTWRK"|"SMK"|"6.5"|""|"1"|"6"|"73458"|"888542293345"|"GABBY GHILLIE"|""|""|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"0"|"Smoke"|"6.5"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_l.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_f.jpg"|"http://s002.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_c.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_b.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_s.jpg"|"http://s001.osstatic.net/s/FRYE/store/productimages/master/73458_smoke_t.jpg"|"0"|"1"|"13"|"70007"|"http://www.thefryecompany.com/store/ProductImages/colors/73458_smoke_l_sw.jpg"|"Smoke"|"[{\"Classification\":[\"Shoes\",\"\"]},{\"Gender\":[\"Women\",\"\"]},{\"Heel Height\":[\"Tall\",\"\"]},{\"Shaft Height\":[\"Tall Shaft\",\"\"]},{\"Style\":[\"Leisure\",\"\"]},{\"Tax Code\":[\"General Adult Apparel\",\"PC040100\"]}]"|"101,112,143,1002"
CSV;

// initialize product extractor
$productExtractor = new Extractor(new ProductExtraction());
$productsIterator = new GroupIterator($products_csv, 0, '|');
foreach ($productsIterator as $product){
    $first = true;
    foreach ($product as $variant){
        if($first){
            $first = false;
            $prod_fields = $productExtractor->extract($variant,'/PRODUCT/FIELDS/*');
            print_r($prod_fields);
            $prod_attributes = $productExtractor->extract($variant,'/PRODUCT/ATTRIBUTES/*');
            print_r($prod_attributes);
        }
        $variant_fields = $productExtractor->extract($variant,'/PRODUCT/VARIANTS/VARIANT/FIELDS/*');
        print_r($variant_fields);
        $variant_options = $productExtractor->extract($variant,'/PRODUCT/VARIANTS/VARIANT/OPTIONS/*');
        print_r($variant_options);
        $variant_attributes = $productExtractor->extract($variant,'/PRODUCT/VARIANTS/VARIANT/ATTRIBUTES/*');
        print_r($variant_attributes);
    }
}
?>