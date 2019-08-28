<?php

final class Corky_Exception_Compiler_PHP_Runtime extends Corky_Exception {
	public function __construct($message, $line = 0) {
		$this->message = $message;
		$this->line = +$line;
		parent::__construct($this);
	}
}

final class Corky_Compiler_PHP extends Corky_Compiler {
	const VERSION = 0.1;
	
	// stores the compiled PHP lambda
	private $fn = null;
	
	private $methods = null;
	
	static private $types = array(
		'~' => 'var',
		'&' => 'fn'
	);

	function __construct(Corky_Lexer $lexer){
		parent::__construct($lexer);
		
		$this->lexer = $lexer;
		
		$this->methods = array(
			'const' => function(&$token, array &$context = null){
				static $value_only = array('echo', 'store');
				
				return self::render_value($token['arg'], isset($context) && in_array($context['token'], $value_only));
			},
			'echo' => function(&$token){
				$values = array();
				
				foreach($token['args'] as $arg)
				{
					$values[] = $arg['token'] === 'var'
						? self::render_var($arg)
						: self::render_value($arg);
				}
				
				return sprintf(
					isset($token['format'])
						? 'printf("' . str_replace('%', '%%', $token['format']) . '", %s);'
						: 'echo %s;',
					implode(', ', $values)
				);
			},
			'define' => function(&$token){
				$result = self::render_var($token['arg']) . ' = ';
				
				if(isset($token['store']))
				{
					if($token['store']['arg']['type'] !== $token['type'])
					{
						throw new Corky_Exception_Type_Error('Wrong type on line ' . $token['store']['line']);
					}
					
					$result .= self::render_value($token['store']['arg']) . ';';
				}
				else
				{
					$result .= 'null;';
				}
				
				return $result;
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
	
	
	
	private static function render_var(&$token)
	{
		if($token['token'] !== 'var')
		{
			return '';
		}
		
		return '$DATA[$DATA[\'const\'][\'scope\'][\'depth\'][\'value\']][\'' . self::$types[$token['type']] . '\'][\'' . $token['identifier'] . '\']';
	}
	
	private static function render_value(&$token, $value_only = true)
	{
		$value = $token['type'] === 'text'
			? '\'' . str_replace('\'', '\\\'', $token['value']) . '\''
			: $token['value'];
		
		return $value_only
			? $value
			: 'array(\'value\' => ' . $value . ', \'type\' => \'' . $token['type'] . '\')';
	}
}

Corky::define_lang('php', 'Corky_Compiler_PHP');
