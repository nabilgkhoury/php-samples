<?php

class FlexCall
{
    /** 
     * class encapsulating a callable with support for partial 
     * application and random argument ordering during invocation
     * inspired by Python functool partial
     **/
    protected $func;
    protected $params;
    protected $params_lookup;
    protected $frozen;
    public function __construct($def, $kwargs=array()){
        // is def embedded in a string or just a callable?
        if(is_callable($def)){
            $this->func = $def;
            // use reflection to get list of params
            $this->params = array();
            $this->frozen = array();
            $reflectFunc = new ReflectionFunction($this->func);
            $params = $reflectFunc->getParameters();
            foreach ($params as $param){
                $this->params[] = $param->name;
            }
        }elseif(gettype($def) === 'string'){
            echo $def . '\n';
            eval('$this->func = '."$def;");
        }elseif(gettype($def) === 'array'){
            $body = 'return '.$def['body'].';';
            $this->params = $def['params'];
            $params = array(); // for create_function
            foreach ($this->params as $param){
                $params[]= "$$param";
            }
            $params = implode(',',$params);
            $this->func = create_function($params, $body);
        }else{// not supported
            throw new \Exception("FlexCall: unsupported definition type");
        }
        $this->freeze($kwargs);
        // flip $this->params to accelarate lookup operations
        $this->params_lookup = array_flip($this->params);
    }
    
    public function __get($param){
        if(array_key_exists($param, $this->params_lookup)){
            if(array_key_exists($param, $this->frozen)){
                return $this->frozen[$param];
            }else{
                return NULL;
            }    
        }else{
            // param is invalid
            $parameters = implode(',',$this->params);
            throw new \Exception(
                "FlexCall: Invalid parameter $param. ($parameters) available.");
        }
    }
    public function __set($param, $value){
        // freeze another parameter value
        if (array_key_exists($param, $this->params_lookup)){
            $this->frozen[$param] = $value;
        }
    }
    
    // $kwargs could be key=>value (ex: one:1, two:2) or indexed (ex:0:1, 1:2)
    public function freeze($kwargs){
        // freeze more params if recognized, otherwise ignore
        foreach ($kwargs as $kw=>$arg){
            if (array_key_exists($kw, $this->params_lookup)){
                $this->frozen[$kw] = $arg;
            }
        }
    }
    
    public function __invoke($kwargs=array()){
        //var_dump($kwargs);
        $args = array();
        foreach ($this->params as $param){
            // get the param value from kwargs or frozen args
            // give preference to kwargs because it's fresher.
            if (array_key_exists($param, $kwargs)){
                $args[] = $kwargs[$param];
            }elseif(array_key_exists($param, $this->frozen)){
                $args[] = $this->frozen[$param];
            }else{
                // won't invoke if some args are missing
                throw new \Exception(
                sprintf(__CLASS__." requires %s",implode(',',$this->params)));
            }
        }
        //printf ("invocation args will be: %s\n", implode(',', $args));
        return call_user_func_array($this->func, $args);
    }
}

/**
 * Demonstration:
 * define a FlexCall out of an closure and invoke it in a variety of ways
 **/
function demonstrate(){
    $flexCall = new FlexCall(function($first, $second){
        echo "First= $first, second= $second\n";
    });
    
    try{
        //should raise because $first is undefined
        $flexCall(array($second=>2));
    }catch (\Exception $ex){
        echo $ex->getMessage()."\n";
    }
    
    $flexCall->first = 1;// freeze first all by itself
    $flexCall(array('second'=>2));// should work since first was frozen
    try{
        // should raise because $second hasn't been frozen
        $flexCall();
    }catch(\Exception $ex){
        echo $ex->getMessage()."\n";
    }
    
    // freeze both first and second using new values:
    $flexCall->freeze(array('first'=>'one', 'second'=>'two'));
    // can now call with no arguments
    $flexCall();// should work since first and second are frozen;
    
    // call normally with new arguments
    $flexCall(array('first'=>"I",'second'=>"II"));
}
//demonstrate();
?>