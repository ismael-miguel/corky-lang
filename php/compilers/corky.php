<?php

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
