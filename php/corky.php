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
		$this->line = +$line;
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
				# https://stackoverflow.com/a/481294
				(?P<text>"([^\\\\"]|\\\\\\\\|\\\\")*?")# double-quoted escaped string
				|
				(?P<fn_arg>[a-z_]+) # function name
				|
				~(?P<index>\d+) # index
				|
				(?P<dynamic>\d+\.\d*|\d*.\d+) # floating-point
				|
				(?P<static>\d+) # integer
				|
				(?P<unknown>[^:\s]*)# no type
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
		
		if(isset($pieces['arg']) && $pieces['arg'] !== '')
		{
			foreach(array('fn' => 'fn_arg', 'static', 'dynamic', 'text', 'index', 'unknown') as $type => $value)
			{
				if(is_numeric($type))
				{
					$type = $value;
				}
				
				if(isset($pieces[$value]) && $pieces[$value] !== '')
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
	
	private static function assert(array $token, array $rules, ArrayIterator &$iterator, $throw = true){
		static $methods = null;
		
		if(!$methods)
		{
			$methods = array(
				'last' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					if($iterator->valid() !== !$value)
					{
						return self::assert_throw((!!$value ? 'must' : 'can\'t') . ' be the last token', $token['line'], $token, $throw);
					}
					
					$key = $iterator->key();
					$iterator->next();
					$valid = $iterator->valid();
					$iterator->seek($key);
					
					if($valid !== !$value)
					{
						return self::assert_throw((!!$value ? 'must' : 'can\'t') . ' be the last token', $token['line'], $token, $throw);
					}
					
					return true;
				},
				'first' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					if(
						($value && $iterator->key() > 0)
						|| (!$value && $iterator->key() === 0)
					)
					{
						return self::assert_throw(($value ? 'must' : 'can\'t') . ' be the first token', $token['line'], $token, $throw);
					}
					return true;
				},
				'prev' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					return $methods['after']($value, $token, $iterator, $throw);
				},
				'after' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					$first = false;
					if(!$methods['first']($first, $token, $iterator, $throw))
					{
						return false;
					}
					
					$key = $iterator->key();
					$iterator->seek($key - 1);
					$prev = $iterator->current();
					$iterator->next();
					
					if(is_string($value))
					{
						return $value !== $prev['token']
							? self::assert_throw('must be the after :' . $value . ' token', $token['line'], $token, $throw)
							: true;
					}
					elseif(is_array($value))
					{
						if(count(array_filter(array_keys($value), 'is_numeric')))
						{
							return !in_array($prev['token'], $value)
								? self::assert_throw('must be after ' . $value . ' token', $token['line'], $token, $throw)
								: true;
						}
						else
						{
							foreach($value as $value_name => &$value_value)
							{
								return $value_value !== $prev[$value_name]
									? self::assert_throw(
										'previous ' . $value_name . ' must be ' . $value_value . ', ' . $value_name . ' ' . $prev[$value_name] . ' given',
										$token['line'], $token, $throw
									)
									: true;
							}
						}
					}
					return true;
				},
				'next' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					return $methods['before']($value, $token, $iterator, $throw);
				},
				'before' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					$last = false;
					if(!$methods['last']($last, $token, $iterator, $throw))
					{
						return false;
					}
					
					$key = $iterator->key();
					$iterator->next();
					$next = $iterator->current();
					$iterator->seek($key);
					
					if(is_string($value))
					{
						return $value !== $next['token']
							? self::assert_throw('must be the before :' . $value . ' token', $token['line'], $token, $throw)
							: true;
					}
					elseif(is_array($value))
					{
						if(count(array_filter(array_keys($value), 'is_numeric')))
						{
							return !in_array($next['token'], $value)
								? self::assert_throw('must be before ' . $value . ' token', $token['line'], $token, $throw)
								: true;
						}
						else
						{
							foreach($value as $value_name => &$value_value)
							{
								return $value_value !== $next[$value_name]
									? self::assert_throw(
										'next ' . $value_name . ' must be ' . $value_value . ', ' . $value_name . ' ' . $next[$value_name] . ' given',
										$token['line'], $token, $throw
									)
									: true;
							}
						}
					}
					return true;
				},
				'arg' => function(&$value, &$token, &$iterator, $throw)use(&$methods){
					if(is_bool($value))
					{
						if($value && (!isset($token['arg']) || !$token['arg']))
						{
							return self::assert_throw('argument required', $token['line'], $token, $throw);
						}
						elseif(!$value && (isset($token['arg']) || $token['arg']))
						{
							return self::assert_throw('argument not allowed', $token['line'], $token, $throw);
						}
					}
					elseif(is_array($value))
					{
						if(!isset($token['arg']) || !$token['arg'])
						{
							return self::assert_throw('argument required', $token['line'], $token, $throw);
						}
						
						foreach($value as $rule_name => &$rule_value)
						{
							if(!is_array($rule_value) && $rule_value !== $token['arg'][$rule_name])
							{
								return self::assert_throw(
									'argument with ' . $rule_name . ' of ' . $rule_value . ' expected, ' . $rule_name . ' ' . $token['arg'][$rule_name] . ' given',
									$token['line'], $token, $throw
								);
							}
							elseif(is_array($rule_value) && !in_array($token['arg'][$rule_name], $rule_value))
							{
								return self::assert_throw(
									'argument with ' . $rule_name . ' in (' . implode(', ', $rule_value) . ') expected; ' . $rule_name . ' ' . $token['arg'][$rule_name] . ' given',
									$token['line'], $token, $throw
								);
							}
						}
					}
					return true;
				}
			);
		}
		
		foreach($rules as $rule_name => &$rule_value)
		{
			if(isset($methods[$rule_name]))
			{
				if(!$methods[$rule_name]($rule_value, $token, $iterator, $throw))
				{
					return false;
				}
			}
			elseif(is_bool($rule_value))
			{
				if($rule_value && (!isset($token[$rule_name]) || !$token[$rule_name]))
				{
					return self::assert_throw('token ' . $rule_name . ' required, none given', $token['line'], $token, $throw);
				}
				elseif(!$rule_value && (isset($token[$rule_name]) || $token[$rule_name]))
				{
					return self::assert_throw('token ' . $rule_name . ' not allowed', $token['line'], $token, $throw);
				}
			}
			elseif(is_array($rule_value))
			{
				if(!in_array($token[$rule_name], $rule_value))
				{
					return self::assert_throw(
						'expected token ' . $rule_name . ' in (' . implode(', ', $rule_value) . '); ' . $rule_name . ' ' . $token[$rule_name] . ' given',
						$token['line'], $token, $throw
					);
				}
			}
			elseif($rule_value != $token[$rule_name])
			{
				return self::assert_throw(
					'token ' . $rule_name . ' required to be ' . $rule_value . ', ' . $rule_name . ' ' . $token[$rule_name] . ' given',
					$token['line'], $token, $throw
				);
			}
		}
		return true;
	}
	
	private static function assert_throw($message, $line, &$token, $throw) {
		if($throw)
		{
			throw new Corky_Exception_Lexer_Syntax_Error($message, $token['line'], $token);
		}
		return false;
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
					self::assert($token, array('last' => false), $iterator);
					
					$iterator->next();
					
					$tree = array(
						'token' => $token
					);
					
					if(
						isset($token['arg'])
						&& self::assert($token, array('arg' => array('type' => 'text')), $iterator, false)
					)
					{
						$tree['format'] = $token['arg']['value'];
						
						// do not allow a :format after!
						self::assert($iterator->current(), array('group' => 'value'), $iterator);
					}
					else
					{
						$next = $iterator->current();
						
						if(self::assert($next, array('token' => 'format', 'group' => 'modifier'), $iterator, false))
						{
							self::assert($next, array('arg' => array('type' => 'text')), $iterator);
							
							$tree['format'] = $next['arg']['value'];
							$iterator->next();
						}
					}
					
					$tree['args'] = self::get_values($iterator);
					
					if(!$tree['args'])
					{
						throw new Corky_Exception_Lexer_Syntax_Error('at least 1 argument is required', $token['line'], $token);
					}
					
					return $tree;
				},
				'format' => function(&$token, &$iterator){
					self::assert($token, array('arg' => 'text', 'after' => 'echo'), $iterator);
					
					return array(
						'token' => $token,
						'value' => $token['arg']
					);
				},
				'store' => function(&$token, &$iterator){
					self::assert($token, array('arg' => 'text', 'after' => 'var'), $iterator);
					
					return array(
						'token' => $token,
						'value' => $token['arg']
					);
				},
				'const' => function(&$token, &$iterator){
					self::assert($token, array('arg' => array('type' => array('text', 'static', 'dynamic'))), $iterator);
					
					return array(
						'token' => $token,
						'value' => $token['arg']
					);
				},
				'define' => function(&$token, &$iterator){
					self::assert($token, array('last' => false), $iterator);
					
					$tree = array(
						'token' => $token
					);
					
					$iterator->next();
					$type = $iterator->current();
					
					self::assert($type,
						array(
							'last' => false,
							'group' => array('data_type', 'data_structure'),
							'next' => 'var'
						),
						$iterator
					);
					
					$iterator->next();
					$var = $iterator->current();
					
					$tree = array('token' => $token, 'type' => $type['token'], 'arg' => $var);
					
					// last token, no value stored
					if(!self::assert($var, array('next' => 'store'), $iterator, false))
					{
						return $tree;
					}
					
					$iterator->next();
					$store = $iterator->current();
					
					self::assert($store, array('next' => array('group' => 'value')), $iterator);
					
					$iterator->next();
					$tree['store'] = $iterator->current();
					self::assert($tree['store'], array('arg' => array('type' => array('text', 'static', 'dynamic'))), $iterator);
					
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
	protected $lexer = null;
	protected $code = '';
	
	function __construct(Corky_Lexer $lexer){
		$this->lexer = $lexer;
	}
	
	abstract protected function compile();
	
	function get_code(){
		if(!$this->code)
		{
			$this->compile();
		}
		
		return $this->code;
	}
}

final class Corky {
	private $code_raw = '';
	private static $languages = array();
	
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
	
	function get_raw_code(){
		return $this->code_raw;
	}
	
	function get_tokens(){
		return $this->lexer->get_tokens();
	}
	
	function get_tree(){
		return $this->lexer->get_tree();
	}
	
	static function define_lang($lang, $class){
		self::$languages[$lang] = $class;
	}
	
	static function defined_langs(){
		return array_keys(self::$languages);
	}
}
