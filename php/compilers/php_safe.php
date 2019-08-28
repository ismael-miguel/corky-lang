<?php

final class Corky_Exception_Compiler_PHP_Safe_Runtime extends Corky_Exception {
	public function __construct($message, $line = 0) {
		$this->message = $message;
		$this->line = +$line;
		parent::__construct($this);
	}
}

final class Corky_Compiler_PHP_Safe extends Corky_Compiler {
	const VERSION = 0.1;
	
	// stores the compiled PHP lambda
	private $fn = null;
	
	private $methods = null;

	function __construct(Corky_Lexer $lexer){
		parent::__construct($lexer);
		
		$this->lexer = $lexer;
		
		$this->methods = array(
			'const' => function(&$token, array &$context = null){
				static $value_only = array('echo');
				
				$value = $token['arg']['type'] === 'text'
					? '"' . str_replace('$', '\\$', $token['arg']['value']) . '"'
					: $token['arg']['value'];
				
				return isset($context) && in_array($context['token'], $value_only)
					? $value
					: 'array(\'value\' => ' . $value . ', \'type\' => \'' . $token['arg']['type'] .'\')';
			},
			'var' => function(&$token, array &$context = null){
				static $types = array(
					'~' => 'var',
					'&' => 'fn'
				);
				
				static $method = array(
					'echo' => 'value'
				);
				
				$method = $context && isset($method[$context['token']])
					? '_' . $method[$context['token']]
					: '';
				
				// to do: array index
				return 'Corky_Compiler_PHP_Runtime::get_' . $types[$token['type']] . $method . '($DATA, ' . $token['identifier'] . ', ' . (+!!$token['parent']) . ')';
			},
			'echo' => function(&$token){
				$values = array();
				
				foreach($token['args'] as $arg)
				{
					if(isset($this->methods[$arg['token']]))
					{
						$values[] = $this->methods[$arg['token']]($arg, $token['token']);
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
				return 'Corky_Compiler_PHP_Runtime::create_var($DATA, '
					. $token['arg']['identifier']
					. ', \'' . $token['type'] . '\''
				. ');'
				. (isset($token['store'])
					? PHP_EOL
					. 'Corky_Compiler_PHP_Runtime::set_var($DATA, '
						. $token['arg']['identifier']
						. ', '
							. $this->methods[$token['store']['token']]($token['store'])
					. ');'
					: ''
				);
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
		
		$this->code = <<<PHP
/*			 data boilerplate			 */

\$DATA = array(
	'vars' => array(
		-1 => array(
			'var' => &\$argv,
			'fn' => array()
		),
		0 => array(
			'var' => array(),
			'fn' => array()
		)
	),
	'const' => array(
		'true' => array('type' => 'static', 'value' => 1),
		'false' => array('type' => 'static', 'value' => 0),
		'default' => array('type' => 'static', 'value' => 0),
		'null' => array('type' => 'null', 'value' => null),
		'args' => &\$argv,
		'scope' => array(
			'depth' => array('type' => 'static', 'value' => 0),
			'maxdepth' => array('type' => 'static', 'value' => 10)
		),
		'compiler' => array(
			'system' => 'PHP',
			'file' => '',
			'version' => array('type' => 'dynamic', 'value' => Corky_Compiler_PHP::VERSION)
		)
	)
);

/* =========== data boilerplate =========== */
/*			 code to execute			  */

{$code}

/* =========== code to execute ============ */
PHP;
	}
	
	function execute(array $argv = array()){
		if(!$this->fn)
		{
			$this->fn = eval('return (function(array &$argv){ ' . $this->get_code() . ' });');
		}
		
		// $this->fn($argv)
		// will throw error (class::fn not found)
		$fn = &$this->fn;
		return $fn($argv);
	}
}

final class Corky_Compiler_PHP_Runtime {
	static function get_scope(&$DATA){
		return $DATA['const']['scope']['depth']['value'];
	}
	
	static function set_var(&$DATA, $index, array $value){
		$scope = self::get_scope($DATA);
		
		$var = self::get_var($DATA, $index);
		
		if(
			$value['type'] !== 'null'
			&& $var['type'] !== $value['type'] 
		)
		{
			throw new Corky_Exception_Compiler_PHP_Runtime('type mismatch, expected ' . $var['type'] . ', got ' . $value['type']);
		}
		
		$DATA['vars'][$scope]['var'][$index] = array(
			'type' => $value['type'],
			'value' => $value['value']
		);
	}
	
	static function get_var(&$DATA, $index, $parent = false){
		$scope = self::get_scope($DATA) - (!!$parent);
		
		if(!isset($DATA['vars'][$scope]['var'][$index]))
		{
			throw new Corky_Exception_Compiler_PHP_Runtime('undefined var ' . $index . ' on scope ' . $scope);
		}
		
		$var = &$DATA['vars'][$scope]['var'][$index];
		return $var;
	}
	
	static function get_var_value(&$DATA, $index, $parent = false){
		$var = self::get_var($DATA, $index, $parent);
		$value = &$var['value'];
		
		return $value;
	}
	
	static function create_var(&$DATA, $index, $type){
		$scope = self::get_scope($DATA);
		
		if($index != 0 && !isset($DATA['vars'][$scope]['var'][$index - 1]))
		{
			throw new Corky_Exception_Compiler_PHP_Runtime('undefined var ' . ($index - 1) . ' on scope ' . $scope);
		}
		
		$DATA['vars'][$scope]['var'][$index] = array(
			'type' => $type,
			'value' => null
		);
	}
}

Corky::define_lang('php_safe', 'Corky_Compiler_PHP_Safe');
