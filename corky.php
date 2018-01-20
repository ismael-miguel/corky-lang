<?php

class Corky_Exception extends Exception {
	public function __construct(Corky_Exception $class) {
		$this->message = get_class($class) . ': ' . $class->message;
	}

	public function __toString() {
		return $this->message;
	}
}

final class Corky_Exception_No_Code extends Corky_Exception {
	public function __construct() {
	    $this->message = 'No code given, empty or wrong type';
		parent::__construct($this);
	}
}

final class Corky_Exception_Type_Error extends Corky_Exception {
	public function __construct($message = 'Wrong type') {
	    $this->message = $message . '';
		parent::__construct($this);
	}
}
final class Corky_Exception_Invalid_State extends Corky_Exception {
	public function __construct($message = 'Invalid state') {
	    $this->message = $message . '';
		parent::__construct($this);
	}
}

// lexer-specific
final class Corky_Exception_Lexer_Syntax_Error extends Corky_Exception {
	public function __construct($message = 'Syntax Error', $line = 0) {
	    $this->message = $message . '';
	    $this->line = $line . '';
		parent::__construct($this);
	}
}

final class Corky_Parser {
	static private $regex = '%:
	(?P<skip>:)? # skips the code if there is ::<rule>
	(?:
		(?P<var> # parses things like ^&-1 or ~0
			(?P<parent>\^)?
			(?P<type>[~&]) # ~ is a variable | & is a function
			(?P<identifier>\d+|-1)
		)
		|
		(?P<fn>[a-z][a-z_]*)
		(?:@
			(?P<arg>
				(?P<fn_arg>[a-z_]+) # function name
				|
				(?P<static>\d+) # integer
				|
				(?P<dynamic>\d+\.\d*|\d*.\d+) # floating-point
				| # https://stackoverflow.com/a/481294
				(?P<text>"([^\\"]|\\\\|\\")*?") # double-quoted escaped string
			)
		)?
	)%Axis';
	
	static private $groups = array(
		'attribution' => array('define', 'store'),
		'data_type' => array('func', 'fn', 'static', 'dynamic', 'text'),
		'data_structure' => array('list', 'dict', 'obj'),
		'value' => array('var', 'const'),
		'output' => array('echo', 'format'),
		'scope' => array('scope', 'end'),
		'decision' => array('case'),
		'loop' => array('cycle', 'repeat'),
		'ignore' => array('')
	);
	static private $token_group = array();
	
	private static function tokenize_var(array $pieces) {
		return array(
			'token' => 'var',
			'type' => $pieces['type'],
			'identifier' => $pieces['identifier'],
			'parent' => isset($pieces['parent']) && $pieces['parent']
		);
	}
	
	private static function tokenize_fn(array $pieces) {
		$token = array(
			'token' => $pieces['fn']
		);
		
		if(isset($pieces['arg']) && $pieces['arg'])
		{
			foreach(array('fn' => 'fn_arg', 'static', 'dynamic', 'text') as $type => $value)
			{
				if(is_numeric($type))
				{
					$type = $value;
				}
				
				if(isset($pieces[$value]) && $pieces[$value])
				{
					$token['arg'] = array(
						'type' => $type,
						'value' => $pieces[$value]
					);
					break;
				}
			}
		}
		
		return $token;
	}
	
	static function token_get_group(array $token) {
		if(isset(self::$token_group[$token['token']]))
		{
			return self::$token_group[$token['token']];
		}
		
		foreach(self::$groups as $group => &$tokens)
		{
			if(in_array($token['token'], $tokens))
			{
				return self::$token_group[$token['token']] = $group;
			}
		}
		
		return self::$token_group[$token['token']] = 'unknown';
	}
	
	static function parse($data){
		// make sure it's a string
		$code = $data . '';
		
		if(!$code)
		{
			throw new Corky_Exception_No_Code();
		}
		
		$tokens = array();
		$line = 1;

		for($i = 0, $length = strlen($code); $i < $length; $i++)
		{
			$eof = $i + 1 >= $length;
			
			switch($code[$i])
			{
				case "\r":
					if(!$eof && $code[$i + 1] === "\n")
					{
						$i++;
					}
				case "\n":
					$line++;
					break;
				case ':':
					if($eof)
					{
						continue;
					}
					
					if(preg_match(self::$regex, $code, $pieces, 0, $i))
					{
						// reduces the code size, removing one token
						// this avoids accidental double-parsing, if there's a bug
						$code = substr($code, $i + strlen($pieces[0]));
						$i = -1; // leaving at 0 would skip a char
						$length = strlen($code);
						
						if(isset($pieces['skip']) && $pieces['skip'])
						{
							// lets leave before we do any more work
							break;
						}
						
						$token = isset($pieces['var']) && $pieces['var']
							? self::tokenize_var($pieces)
							: self::tokenize_fn($pieces);
						
						$token['line'] = $line;
						$token['group'] = self::token_get_group($token);
						
						$tokens[] = $token;
					}
					break;
			}
		}
		
		return $tokens;
	}
}

final class Corky_Lexer {
	private $tokens = array();
	private $code = '';
	private $code_raw = '';
	
	function getCode(){
		if(!$this->code_raw || $this->code)
		{
			return $this->code;
		}
		
		$code = '';
		$last_line = 0;
		
		foreach($this->tokens as $token)
		{
			$code .= ($last_line && $token['line'] > $last_line ? "\r\n": '') . ':';
			
			$last_line = $token['line'];
			
			if($token['token'] === 'var')
			{
				$code .= ($token['parent'] ? '^' : '') . $token['type'] . $token['value'];
			}
			else
			{
				$code .= $token['token'] . (
					isset($token['arg'])
						? '@' . $token['arg']['value']
						: ''
				);
			}
		}
		
		return $this->code = $code;
	}
	
	function getRawCode(){
		return $this->code_raw;
	}
	
	function get_tokens(){
		return $this->tokens;
	}
	
	private function parse(){
		$this->tokens = Corky_Parser::parse($this->code_raw);
	}
	
	private function validate(){
		$status = array(
			'level' => 0,
			'begin' => array()
		);
		
		$rules = array(
			'const' => function(&$token, &$iterator){
				return isset($token['arg'])
					&& in_array($token['arg']['type'], array('static', 'dynamic', 'text'))
					&& isset($token['arg']['value']);
			},
			'echo' => function(&$token, &$iterator){
				if(!$iterator->valid())
				{
					return false;
				}
				
				$current_key = $iterator->key();
				$iterator->next();
				$next = $iterator->current();
				$iterator->seek($current_key);
				
				return $next['group'] === 'value';
			}
		);
		
		$iterator = new ArrayIterator($this->tokens);
		while($iterator->valid())
		{
			$token = $iterator->current();
			if(
				isset($rules[$token['token']])
				&& !$rules[$token['token']]($token, $iterator)
			)
			{
				throw new Corky_Exception_Lexer_Syntax_Error('Unexpected or invalid token ' . $token['token'], $token['line']);
			}
			
			$iterator->next();
		}
	}

	function __construct($code){
		if($code)
		{
			$this->code_raw = $code;
			$this->parse();
			
			if(!$this->tokens)
			{
				throw new Corky_Exception_Invalid_State('Parser returned an invalid token list');
			}
			
			$this->validate();
		}
	}
}

abstract class Corky_Compiler {
	protected $lexer;
	
	function __construct(Corky_Lexer $lexer){
		$this->lexer = $lexer;
	}
	
	abstract protected function compile();
	abstract function get_code();
}

final class Corky_Compiler_PHP extends Corky_Compiler {
	private $fn = null;
	private $code = '';
	private $data = array(
		'vars' => array(),
		'const' => array(
			'true' => 1,
			'false' => 0
		),
		'dedup' => array()
	);
	
	protected $lexer = null;
	private $methods = null;

	function __construct(Corky_Lexer $lexer){
		parent::__construct($lexer);
		
		$this->lexer = $lexer;
		
		$this->methods = array(
			'echo' => function(&$token, &$iterator){
				if(!$iterator->valid())
				{
					return '';
				}
				
				$result = 'echo ';
				
				$iterator->next();
				$next = $iterator->current();
				$values = array();
				
				while($iterator->valid() && $next['group'] === 'value')
				{
					$values[] = $this->methods[$next['token']]($next, $iterator);
				
					$iterator->next();
					$next = $iterator->current();
				}
				
				return $result . implode(', ', $values) . ';';
			},
			'const' => function(&$token){
				$value = $token['arg']['type'] === 'text'
					? str_replace('$', '\\$', substr($token['arg']['value'], 1, -1))
					: $token['arg']['value'];
				
				$pos = array_search($value, $this->data['dedup']);
				
				if($pos !== false)
				{
					return '$DATA[\'dedup\'][' . $pos . ']';
				}
				
				$this->data['dedup'][] = $value;
				
				return '$DATA[\'dedup\'][' . (count($this->data['dedup']) - 1) . ']';
			}
		);
	}
	
	protected function compile(){
		$code = '';
		
		$methods = &$this->methods;
		$tokens = $this->lexer->get_tokens();
		
		$iterator = new ArrayIterator($tokens);
		while($iterator->valid())
		{
			$token = $iterator->current();
			if(isset($methods[$token['token']]))
			{
				$code .= $methods[$token['token']]($token, $iterator);
			}
			
			$iterator->next();
		}
		
		$this->code = '/* =========== data boilerplate =========== */' . PHP_EOL
			. '$DATA = ' . var_export($this->data, true) . ';' . PHP_EOL
			. '/* =========== data boilerplate =========== */' . PHP_EOL . PHP_EOL
			. '/* =========== code to execute */' . PHP_EOL
			. $code . PHP_EOL
			. '/* =========== code to execute ============ */';
	}
	
	function get_code(){
		if(!$this->code)
		{
			$this->compile();
		}
		
		return $this->code;
	}
	
	function execute(array $argv = array()){
		if(!$this->fn)
		{
			$this->fn = eval(
				'return (function(&\$argv){ '
					. $this->get_code()
				. ' });'
			);
		}
		
		// $this->fn($argv)
		// will throw error (class::fn not found)
		$fn = &$this->fn;
		return $fn($argv);
	}
}

final class Corky {
	private $code_raw = '';
	private static $languages = array(
		'php' => 'Corky_Compiler_PHP'
	);
	
	private $lexer = null;
	private $compilers = array();
	
	function __construct($data){
		$code = $data . '';
		
		if(!$code)
		{
			throw new Corky_Exception_No_Code();
		}
		
		$this->code_raw = $code;
		
		$this->lexer = new Corky_Lexer($this->code_raw);
	}
	
	function compile($lang){
		$lang = strtolower($lang);
		
		if(isset($this->compilers[$lang]))
		{
			return $this->compilers[$lang];
		}
		
		$class = self::$languages[$lang];
		
		if(!$class/* || !class_exists($class)*/)
		{
			throw new Corky_Exception_Invalid_Lang($lang);
		}
		
		$compiler = new $class($this->lexer);
		
		return $this->compilers[$lang] = $compiler;
	}
	
	function define_lang($lang, $class){
		self::$languages[$lang] = $class;
	}
	
	function defined_langs(){
		return array_keys(self::$languages);
	}
	
	function get_raw_code(){
		return $this->code_raw;
	}
	
	function get_tokens(){
		return $this->lexer->get_tokens();
	}
}
