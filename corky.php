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
	public function __construct($message = 'Syntax Error', $line = 0, array $token = null) {
	    $this->message = ($token ? ':' . $token['token'] . ' - ' : '') . $message;
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
		'attribution' => array('define'),
		'data_type' => array('func', 'fn', 'static', 'dynamic', 'text'),
		'data_structure' => array('list', 'dict', 'obj'),
		'value' => array('var', 'const', 'true', 'false', 'null'),
		'output' => array('echo'),
		'scope' => array('scope', 'end'),
		'decision' => array('case'),
		'loop' => array('cycle', 'repeat'),
		'modifier' => array('format', 'to', 'from', 'through', 'into')
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
			'token' => strtolower($pieces['fn'])
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
						'value' => $type === 'text'
							? substr($pieces[$value], 1, -1) // remove quotes
							: $pieces[$value]
					);
					break;
				}
			}
		}
		
		return $token;
	}
	
	static function token_get_group(array $token, array $last = null) {
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
		$last = null;
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
						$token['group'] = self::token_get_group($token, $last);
						
						$tokens[] = $token;
						$last = $token;
					}
					break;
			}
		}
		
		return $tokens;
	}
}

final class Corky_Lexer {
	private $tokens = array();
	private $tree = array();
	
	function get_tokens(){
		return $this->tokens;
	}
	
	function get_tree(){
		return $this->tree;
	}
	
	private static function get_values(ArrayIterator &$iterator, $reset = false) {
		$old_index = $iterator->key();
		
		$next = $iterator->current();
		$tokens = array();
		
		while(
			$next['group'] === 'value'
			|| isset($next['arg'])
		)
		{
			$tokens[] = $next;
			
			if(!$iterator->valid())
			{
				break;
			}
			$iterator->next();
			$next = $iterator->current();
		}
		
		if($reset)
		{
			$iterator->seek($old_index);
		}
		else if($iterator->valid())
		{
		    $iterator->next();
		}
		
		return $tokens;
	}
	
	private function get_token_tree(array &$token, ArrayIterator &$iterator) {
		static $methods = null;
		if(!$methods)
		{
			$methods = array(
				'echo' => function(&$token, &$iterator){
					if(!$iterator->valid())
					{
						throw new Corky_Exception_Lexer_Syntax_Error('can\'t be the last token', $token['line'], $token);
					}
					
					$tree = array(
						'token' => $token
					);
					
					$iterator->next();
					$next = $iterator->current();
					
					if(
						$next['token'] === 'format'
						&& $next['group'] === 'modifier'
					)
					{
						if(
							!$next['arg']
							|| ($next['arg']['type'] !== 'text')
						)
						{
							throw new Corky_Exception_Lexer_Syntax_Error('argument of type text required', $next['line'], $next);
						}
						
						$tree['format'] = $next['arg']['value'];
						$iterator->next();
					}
					
					$tree['args'] = self::get_values($iterator);
					
					if(!$tree['args'])
					{
						throw new Corky_Exception_Lexer_Syntax_Error('at least 1 argument is required', $token['line'], $token);
					}
					
					return $tree;
				},
				'const' => function(&$token, &$iterator){
					if(!$token['arg'])
					{
						throw new Corky_Exception_Lexer_Syntax_Error('at least 1 argument is required', $token['line'], $token);
					}
					
					return array(
						'token' => $token,
						'value' => $token['arg']
					);
				},
				'define' => function(&$token, &$iterator){
					if(!$iterator->valid())
					{
						throw new Corky_Exception_Lexer_Syntax_Error('can\'t be the last token', $token['line'], $token);
					}
					
					$tree = array(
						'token' => $token
					);
					
					$iterator->next();
					$type = $iterator->current();
					
					if(
						$type['group'] !== 'data_type'
						&& $type['group'] !== 'data_structure'
					)
					{
						throw new Corky_Exception_Lexer_Syntax_Error('invalid token group', $type['line'], $type);
					}
					
					if(!$iterator->valid())
					{
						throw new Corky_Exception_Lexer_Syntax_Error('can\'t be the last token', $type['line'], $type);
					}
					
					$iterator->next();
					$var = $iterator->current();
					
					if($var['token'] !== 'var')
					{
						throw new Corky_Exception_Lexer_Syntax_Error('unexpected token ' . $var['token'] . ', expecting var', $var['line'], $token);
					}
					
					$tree = array(
						'token' => $token,
						'type' => $type['token'],
						'arg' => $var
					);
					
					// last token, no value stored
					if(!$iterator->valid())
					{
						return $tree;
					}
					
					$iterator->next();
					$store = $iterator->current();
					
					if($store['group'] !== 'modifier' && $store['token'] !== 'store')
					{
						$iterator->seek($iterator->key() - 1);
						return $tree;
					}
					
					if(!$iterator->valid())
					{
						throw new Corky_Exception_Lexer_Syntax_Error('value missing', $store['line'], $store);
					}
					
					$iterator->next();
					$value = $iterator->current();
					
					if($value['group'] !== 'value')
					{
						throw new Corky_Exception_Lexer_Syntax_Error('unexpected token group ' . $value['group'], $value['line'], $store);
					}
					
					$tree['store'] = $value;
					
					return $tree;
				}
			);
		}
		
		return isset($methods[$token['token']])
			? $methods[$token['token']]($token, $iterator)
			: $token;
	}
	
	private function build_tree(array $tokens) {
		$tree = array();
		$iterator = new ArrayIterator($tokens);
		
		while($iterator->valid())
		{
			$token = $iterator->current();
			$tree[] = self::get_token_tree($token, $iterator);
			$iterator->next();
		}
		
		$this->tree = $tree;
	}

	function __construct($code){
		if(!($code .= ''))
		{
			throw new Corky_Exception_No_Code();
		}
		
		$tokens = Corky_Parser::parse($code . '');
		
		if(!$tokens)
		{
			throw new Corky_Exception_Invalid_State('Parser returned an invalid token list');
		}
		
		$this->tokens = $tokens;
		
		$this->build_tree($tokens);
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
	const VERSION = '0.1';
	
	private $code = '';
	// prevents duplicated constants
	private $dedup = array();
	// stores the compiled PHP lambda
	private $fn = null;
	
	protected $lexer = null;
	private $methods = null;

	function __construct(Corky_Lexer $lexer){
		parent::__construct($lexer);
		
		$this->lexer = $lexer;
		
		$this->methods = array(
			'const' => function(&$token){
				$value = $token['arg']['type'] === 'text'
					? str_replace('$', '\\$', $token['arg']['value'])
					: $token['arg']['value'];
				
				$pos = array_search($value, $this->dedup);
				
				if($pos !== false)
				{
					return '$DATA[\'dedup\'][' . $pos . ']';
				}
				
				$this->dedup[] = $value;
				
				return '$DATA[\'dedup\'][' . (count($this->dedup) - 1) . ']';
			},
			'var' => function(&$token){
				return '$DATA[\'vars\'][$DATA[\'const\'][\'scope\'][\'depth\']' . ($token['parent'] ? '-1' : '') . '][\'' . ($token['type'] === '~' ? 'var' : 'fn') . '\'][' . $token['identifier'] . ']' . (isset($token['arg']) ? '[\'' . $token['arg']['value'] . '\']' : '' );
			},
			'echo' => function(&$token){
				$values = array();
				
				foreach($token['args'] as $arg)
				{
					if(isset($this->methods[$arg['token']]))
					{
						$values[] = $this->methods[$arg['token']]($arg);
					}
				}
				
				return sprintf(
					isset($token['format'])
						? 'printf("' . str_replace('%', '%%', $token['format']) . '", %s);'
						: 'echo %s;',
					implode(', ', $values)
				);
			},
			'define' => function(&$token){
				return $this->methods['var']($token['arg'])
					. ' = '
					. (
						isset($token['store'])
							? $this->methods[$token['store']['token']]($token['store'])
							: '$DATA[\'const\'][\'null\']'
					) . ';';
			}
		);
	}
	
	protected function compile(){
		$code = '';
		$methods = &$this->methods;
		
		foreach($this->lexer->get_tree() as $token)
		{
			if(isset($methods[$token['token']['token']]))
			{
				$code .= $methods[$token['token']['token']]($token) . PHP_EOL;
			}
		}
		
		$dedup = var_export($this->dedup, true);
		
		$this->code = <<<PHP
/*             data boilerplate             */

\$DATA = array(
	'vars' => array(
		0 => array(
			'var' => array(),
			'fn' => array()
		)
	),
	'const' => array(
		'true' => 1,
		'false' => 0,
		'default' => 0,
		'null' => null,
		'args' => \$argv,
		'scope' => array(
			'depth' => 0,
			'maxdepth' => 10
		),
		'compiler' => array(
			'system' => 'PHP',
			'file' => '',
			'version' => Corky_Compiler_PHP::VERSION
		)
	),
	'dedup' => {$dedup}
);

/* =========== data boilerplate =========== */
/*             code to execute              */

{$code}

/* =========== code to execute ============ */
PHP;
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
				'return (function(array &$argv){ '
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
/*
final class Corky_Compiler_Corky extends Corky_Compiler {
	private $code = '';
	
	protected $lexer = null;

	function __construct(Corky_Lexer $lexer){
		parent::__construct($lexer);
		
		$this->lexer = $lexer;
	}
	
	protected function compile(){
		$code = '';
		$last_line = 0;
		
		foreach($this->lexer->get_tokens() as $token)
		{
			$code .= ($last_line && $token['line'] > $last_line ? "\r\n" : '') . ':';
			
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
	
	function get_code(){
		if(!$this->code)
		{
			$this->compile();
		}
		
		return $this->code;
	}
}*/

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
	
	function get_tree(){
		return $this->lexer->get_tree();
	}
}
